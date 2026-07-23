/**
 * Open/closed state for the app sidebar.
 *
 * A module-level singleton rather than a context, because the app renders
 * exactly one sidebar and both the sidebar itself and the header trigger need
 * to read it. The chosen state is mirrored into a cookie so the server can
 * render the next page at the right width instead of flashing open first.
 */
const COOKIE_NAME = 'sidebar_state';
const COOKIE_MAX_AGE = 60 * 60 * 24 * 7;
const MOBILE_QUERY = '(max-width: 768px)';
const KEYBOARD_SHORTCUT = 'b';

let open = $state(true);
let openMobile = $state(false);
let isMobile = $state(false);

function persistOpen(value: boolean): void {
    open = value;
    document.cookie = `${COOKIE_NAME}=${value}; path=/; max-age=${COOKIE_MAX_AGE}`;
}

export const sidebar = {
    get isMobile(): boolean {
        return isMobile;
    },

    get openMobile(): boolean {
        return openMobile;
    },

    get collapsed(): boolean {
        return !open;
    },

    get state(): 'expanded' | 'collapsed' {
        return open ? 'expanded' : 'collapsed';
    },

    closeMobile(): void {
        openMobile = false;
    },

    /**
     * On mobile the sidebar is a drawer that opens over the page; on desktop it
     * collapses to an icon rail, so the two widths track separate flags.
     */
    toggle(): void {
        if (isMobile) {
            openMobile = !openMobile;

            return;
        }

        persistOpen(!open);
    },

    /**
     * Adopt the width the visitor last chose, falling back to the server's
     * opinion when they have never chosen one.
     *
     * Runs while the layout initialises rather than on mount, so the very first
     * paint is already the right width instead of flashing open and snapping
     * shut.
     */
    restore(defaultOpen: boolean): void {
        open = document.cookie.includes(`${COOKIE_NAME}=false`)
            ? false
            : defaultOpen;

        isMobile = window.matchMedia(MOBILE_QUERY).matches;
    },

    /**
     * Keep the sidebar in step with the viewport and the ctrl/cmd-B shortcut.
     * Returns the teardown so a caller can hand it straight back from
     * `onMount`.
     */
    listen(): () => void {
        const media = window.matchMedia(MOBILE_QUERY);
        const syncIsMobile = () => (isMobile = media.matches);

        const handleShortcut = (event: KeyboardEvent) => {
            if (
                event.key === KEYBOARD_SHORTCUT &&
                (event.metaKey || event.ctrlKey)
            ) {
                event.preventDefault();
                sidebar.toggle();
            }
        };

        media.addEventListener('change', syncIsMobile);
        window.addEventListener('keydown', handleShortcut);

        return () => {
            media.removeEventListener('change', syncIsMobile);
            window.removeEventListener('keydown', handleShortcut);
        };
    },
};
