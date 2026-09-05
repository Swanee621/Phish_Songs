import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';

const PREFS_COOKIE_NAME = 'guest-appearances';

type StoredPrefs = {
    shown: boolean;
};

const stored = readPrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME);

/**
 * Hidden by default: the listings are about Phish's own shows, and a guest
 * appearance is opted into rather than filtered out. The checkbox names how
 * many are being held back, so what is missing is never a silent gap.
 */
let shown = $state(typeof stored?.shown === 'boolean' ? stored.shown : false);

/**
 * Whether shows Phish did not headline (`artistid !== 1`) belong on screen.
 *
 * One preference across every page that can list them, persisted in a cookie —
 * a visitor who turned them off on the browser means it on the recent setlists
 * too, and means it on their next visit.
 */
export const guestAppearances = {
    get shown(): boolean {
        return shown;
    },
    set shown(value: boolean) {
        shown = value;

        writePrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME, { shown: value });
    },
};
