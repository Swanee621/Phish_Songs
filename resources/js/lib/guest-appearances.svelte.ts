import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';

type StoredPrefs = {
    shown: boolean;
};

export type GuestAppearancesSetting = {
    shown: boolean;
};

/**
 * Whether shows Phish did not headline (`artistid !== 1`) belong on screen,
 * persisted in a cookie of its own.
 *
 * Hidden by default: the listings are about Phish's own shows, and a guest
 * appearance is opted into rather than filtered out.
 */
function createGuestAppearances(cookieName: string): GuestAppearancesSetting {
    const stored = readPrefsCookie<StoredPrefs>(cookieName);

    let shown = $state(
        typeof stored?.shown === 'boolean' ? stored.shown : false,
    );

    return {
        get shown(): boolean {
            return shown;
        },
        set shown(value: boolean) {
            shown = value;

            writePrefsCookie<StoredPrefs>(cookieName, { shown: value });
        },
    };
}

/**
 * One setting per page, so guest appearances can be on in one listing and
 * off in another.
 */
export const guestAppearances = {
    songChecker: createGuestAppearances('song-checker-guest-appearances'),
    setlistBrowser: createGuestAppearances('setlist-browser-guest-appearances'),
    recentSetlists: createGuestAppearances('recent-setlists-guest-appearances'),
};
