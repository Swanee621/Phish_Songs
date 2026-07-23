import { useHttp } from '@inertiajs/svelte';
import { liveStatus } from '@/actions/App/Http/Controllers/PhishNetExamplesController';

type LiveStatus = {
    version: string | null;
    year: number | null;
    showdate: string | null;
    /** The show a page should still present as the current one, if any. */
    highlightShowdate: string | null;
    /** ISO 8601 instant at which that stops being true. */
    highlightUntil: string | null;
    inShowWindow: boolean;
    pollInterval: number;
};

type LivePollOptions = {
    /** Seconds between polls while no show is underway. */
    idleInterval: number;
    /** Seconds between polls while a show is underway. */
    activeInterval: number;
    /**
     * Called with the live snapshot whenever the version hash moves, meaning new
     * setlist data has landed upstream and the page should refetch. The year is
     * guaranteed non-null here; `showdate` names the show being played, if any.
     */
    onStale: (status: LiveStatus) => void;
};

/**
 * Polls the lightweight `/data/live` endpoint on the same idle/active cadence
 * the server uses, exposing a per-second countdown to the next poll and whether
 * a show is currently underway. It never touches the phish.net API — only our
 * own cached snapshot.
 *
 * Call `start()` once mounted and `stop()` on cleanup. Read `secondsRemaining`
 * and `inShowWindow` reactively in the component.
 */
export function createLivePoll(options: LivePollOptions) {
    const http = useHttp<Record<string, never>, { data: LiveStatus }>({});

    let version: string | null = null;
    let pollTimer: ReturnType<typeof setTimeout> | null = null;
    let countdownTimer: ReturnType<typeof setInterval> | null = null;
    let nextPollAt = 0;
    let stopped = false;

    let inShowWindow = $state(false);
    let activeShowdate = $state<string | null>(null);
    let secondsRemaining = $state(0);
    let highlightShowdate = $state<string | null>(null);
    let highlightUntilMs = $state<number | null>(null);

    /*
     * The highlight can outlive the poll that announced it — idle polls are an
     * hour apart — so its deadline is checked against a clock that ticks on its
     * own rather than only being re-evaluated when a response lands.
     */
    let nowMs = $state(Date.now());

    function schedule(seconds: number) {
        if (stopped) {
            return;
        }

        nextPollAt = Date.now() + seconds * 1000;
        secondsRemaining = seconds;

        if (pollTimer !== null) {
            clearTimeout(pollTimer);
        }

        pollTimer = setTimeout(poll, Math.max(seconds, 1) * 1000);
    }

    function poll() {
        http.get(liveStatus.url(), {
            onSuccess: (response) => {
                const status = response.data;
                inShowWindow = status.inShowWindow;
                activeShowdate = status.showdate;
                highlightShowdate = status.highlightShowdate;
                highlightUntilMs =
                    status.highlightUntil === null
                        ? null
                        : Date.parse(status.highlightUntil);
                nowMs = Date.now();

                // Skip the first observation (baseline) and any change we can't
                // act on, then hand the moved version off to the caller.
                if (
                    status.version !== null &&
                    version !== null &&
                    status.version !== version &&
                    status.year !== null
                ) {
                    options.onStale(status);
                }

                version = status.version;

                schedule(
                    status.inShowWindow
                        ? options.activeInterval
                        : options.idleInterval,
                );
            },
            // Keep the loop alive across a failed poll, backing off to idle.
            onError: () => schedule(options.idleInterval),
            onNetworkError: () => schedule(options.idleInterval),
        });
    }

    return {
        start() {
            stopped = false;

            countdownTimer = setInterval(() => {
                nowMs = Date.now();
                secondsRemaining = Math.max(
                    0,
                    Math.ceil((nextPollAt - nowMs) / 1000),
                );
            }, 1000);

            poll();
        },
        stop() {
            stopped = true;

            if (pollTimer !== null) {
                clearTimeout(pollTimer);
            }

            if (countdownTimer !== null) {
                clearInterval(countdownTimer);
            }
        },
        get inShowWindow() {
            return inShowWindow;
        },
        get activeShowdate() {
            return activeShowdate;
        },
        /**
         * The show a page should still be treating as the current one — the one
         * being played, and then last night's until the server's cutoff passes.
         * Null once it has, without waiting for another poll to say so.
         */
        get highlightShowdate() {
            if (highlightUntilMs === null || nowMs >= highlightUntilMs) {
                return null;
            }

            return highlightShowdate;
        },
        get secondsRemaining() {
            return secondsRemaining;
        },
    };
}

/**
 * Format a countdown in whole seconds as a compact clock: `45s` under a minute,
 * `12:05` at or above one.
 */
export function formatCountdown(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;

    return `${minutes}:${rest.toString().padStart(2, '0')}`;
}
