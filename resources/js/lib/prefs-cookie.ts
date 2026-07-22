const PREFS_COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

/**
 * Read a JSON preferences cookie. Returns a partial because the stored shape
 * may predate the current one, so every field still needs its own check.
 */
export function readPrefsCookie<T extends object>(
    name: string,
): Partial<T> | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith(`${name}=`));

    if (!match) {
        return null;
    }

    try {
        const parsed: unknown = JSON.parse(
            decodeURIComponent(match.slice(name.length + 1)),
        );

        return typeof parsed === 'object' && parsed !== null
            ? (parsed as Partial<T>)
            : null;
    } catch {
        return null;
    }
}

export function writePrefsCookie<T extends object>(name: string, prefs: T) {
    if (typeof document === 'undefined') {
        return;
    }

    document.cookie = `${name}=${encodeURIComponent(
        JSON.stringify(prefs),
    )}; path=/; max-age=${PREFS_COOKIE_MAX_AGE}`;
}
