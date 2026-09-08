import {
    recentSetlists,
    setlistBrowser,
} from '@/actions/App/Http/Controllers/AppController';
import { home } from '@/routes';

const STORAGE_KEY = 'last-visit';

/** Older than this and the visitor has moved on; start them at the top. */
const MAX_AGE_MS = 1000 * 60 * 60 * 24 * 7;

/**
 * How long to keep waiting for the document to grow tall enough to hold the
 * offset. Generous, because a page can need several fetches before it reaches
 * full height, and settling early leaves the visitor stranded near the top. It
 * costs nothing to wait: the moment they touch the page we stand down entirely.
 */
const RESTORE_BUDGET_MS = 3000;

type StoredVisit = {
    /** The page last used, so a cold load of `/` can return to it. */
    path: string;
    /** Vertical offset per path. */
    scroll: Record<string, number>;
    at: number;
};

/**
 * The pathname for a URL, mirroring the derivation the sidebar uses for its
 * active link. Everything sits inside the `try` because this is also reached
 * while rendering on the server, where there is no `window` to ask.
 */
export function toPath(url?: string | null): string {
    try {
        if (typeof url === 'string' && url !== '') {
            return new URL(url, window.location.origin).pathname;
        }

        return window.location.pathname;
    } catch {
        return typeof url === 'string' && url !== '' ? url : '/';
    }
}

/**
 * `localStorage` rather than a prefs cookie: a per-path scroll offset is of no
 * use to the server, and a cookie would ride along on every request. Note that
 * access *throws* in a private window or with site data blocked rather than
 * returning nothing, so every read and write is guarded — losing the memory is
 * acceptable, breaking the page is not.
 */
function read(): StoredVisit | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        const visit = parsed as Partial<StoredVisit>;

        // A stale offset is worse than no offset: the page it described has most
        // likely changed height, or grown rows at the top.
        if (
            typeof visit.at !== 'number' ||
            Date.now() - visit.at > MAX_AGE_MS
        ) {
            return null;
        }

        return {
            path: typeof visit.path === 'string' ? visit.path : '/',
            scroll:
                typeof visit.scroll === 'object' && visit.scroll !== null
                    ? (visit.scroll as Record<string, number>)
                    : {},
            at: visit.at,
        };
    } catch {
        return null;
    }
}

function write(visit: Omit<StoredVisit, 'at'>): void {
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({ ...visit, at: Date.now() }),
        );
    } catch {
        // Private window, or the quota is spent. Nothing here is worth an error.
    }
}

/**
 * Only the app's own pages are ever navigated to from storage — a stale or
 * hand-edited value must not be able to send anyone somewhere else.
 */
function allowedPaths(): string[] {
    return [home().url, setlistBrowser().url, recentSetlists().url].map(toPath);
}

/**
 * Where a cold load of `/` should really go, or `null` to stay put.
 *
 * Resolved once at module load, before Inertia mounts. That is what makes in-app
 * navigation exempt for free: this runs once per full page load, so clicking
 * Song Checker in the sidebar later can never trigger a bounce. A query string
 * opts out, which doubles as an escape hatch (`/?stay`). A crawler has no stored
 * visit and so always gets `/` exactly as served.
 */
function resolveBounce(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (window.location.pathname !== '/' || window.location.search) {
        return null;
    }

    const stored = read();

    if (!stored || stored.path === '/') {
        return null;
    }

    return allowedPaths().includes(stored.path) ? stored.path : null;
}

let pendingBounce = resolveBounce();

export const lastVisit = {
    /** The page to bounce this cold load to, or `null` to stay. */
    get bounceTarget(): string | null {
        return pendingBounce;
    },

    /**
     * Whether this load is on its way somewhere else. Pages at `/` use this to
     * skip work they are about to throw away.
     */
    get bouncePending(): boolean {
        return pendingBounce !== null;
    },

    /**
     * Called once the bounce has been handed to the router, and only then.
     *
     * Everything downstream has to behave normally from this point: the
     * destination needs a live scroll memory of its own, and so does `/` if the
     * visitor navigates back to it later in the same page load.
     */
    consumeBounce(): void {
        pendingBounce = null;
    },
};

export type ScrollMemory = {
    restore: () => void;
    track: () => () => void;
};

/**
 * Remembers a page and how far down it the visitor had read, and puts them back
 * there on their next visit.
 *
 * Restoring cannot happen at mount: every page fetches its rows over XHR after
 * mounting, so the document is only a heading tall at that point and a scroll
 * would go nowhere. Call `restore()` from an effect keyed on whatever the page
 * already uses to mean "the rows are on screen".
 */
export function createScrollMemory(path: string): ScrollMemory {
    const noop: ScrollMemory = { restore: () => {}, track: () => () => {} };

    if (typeof window === 'undefined') {
        return noop;
    }

    /*
     * The page being bounced away from must not record itself as the last one
     * used, or it would overwrite the very path it is bouncing to. Only that
     * page is held back: the destination is matched by the second clause, and
     * in any case mounts after the bounce has been consumed.
     */
    if (pendingBounce !== null && path !== pendingBounce) {
        return noop;
    }

    const target = read()?.scroll[path] ?? 0;

    let restored = false;
    let userMoved = false;

    /**
     * Whether the visitor has taken the page over themselves, in which case
     * dropping them somewhere else would be rude.
     *
     * The position is the reliable half of this: it catches scrolling by any
     * means, including a scrollbar drag, which fires none of the events below.
     * A `scroll` listener could not do the same job, being indistinguishable
     * from the one our own restore provokes. The events add the case of someone
     * who scrolled and came back to the top while the rows were still landing.
     */
    const hasTakenOver = (): boolean => userMoved || window.scrollY > 0;

    const intentEvents = ['wheel', 'touchmove', 'keydown'] as const;

    function detachIntent(): void {
        for (const event of intentEvents) {
            window.removeEventListener(event, onIntent);
        }
    }

    function onIntent(): void {
        userMoved = true;
        detachIntent();
    }

    for (const event of intentEvents) {
        window.addEventListener(event, onIntent, { passive: true });
    }

    return {
        restore(): void {
            if (restored || hasTakenOver() || target <= 0) {
                return;
            }

            // Claimed up front: the calling effect may re-run.
            restored = true;

            const deadline = Date.now() + RESTORE_BUDGET_MS;

            const attempt = () => {
                if (hasTakenOver()) {
                    return;
                }

                const furthest = Math.max(
                    document.documentElement.scrollHeight - window.innerHeight,
                    0,
                );

                // Rows and images can still be settling. Keep giving the
                // document a frame to grow tall enough to hold the offset.
                if (furthest < target && Date.now() < deadline) {
                    requestAnimationFrame(attempt);

                    return;
                }

                window.scrollTo({
                    top: Math.min(target, furthest),
                    behavior: 'instant',
                });

                detachIntent();
            };

            requestAnimationFrame(attempt);
        },

        /** Call from `onMount`; returns the teardown. */
        track(): () => void {
            let frame = 0;

            const save = (): void => {
                frame = 0;

                write({
                    path,
                    scroll: { ...read()?.scroll, [path]: window.scrollY },
                });
            };

            // Coalesced to one write per frame; `scroll` fires far too often.
            const onScroll = (): void => {
                if (frame) {
                    return;
                }

                frame = requestAnimationFrame(save);
            };

            const onVisibilityChange = (): void => {
                if (document.visibilityState === 'hidden') {
                    save();
                }
            };

            // Record the page immediately, so it is remembered even by someone
            // who never scrolls it.
            save();

            window.addEventListener('scroll', onScroll, { passive: true });

            // `pagehide` and a backgrounded tab are the only dependable signals
            // that a page is going away — mobile Safari frequently never fires
            // `beforeunload`, and closing the page is the whole point here.
            window.addEventListener('pagehide', save);
            document.addEventListener('visibilitychange', onVisibilityChange);

            return () => {
                if (frame) {
                    cancelAnimationFrame(frame);
                }

                save();
                detachIntent();
                window.removeEventListener('scroll', onScroll);
                window.removeEventListener('pagehide', save);
                document.removeEventListener(
                    'visibilitychange',
                    onVisibilityChange,
                );
            };
        },
    };
}
