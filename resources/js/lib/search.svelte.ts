import Fuse from 'fuse.js';

export type SearchShow = {
    date: string;
    venue: string | null;
    city: string | null;
    state: string | null;
    artist: string | null;
    slugs: string[];
};

/** The text typed in the top-bar search box, shared with the pages. */
export const searchState = $state({ query: '' });

const FUSE_OPTIONS = {
    threshold: 0.3,
    ignoreLocation: true,
    minMatchCharLength: 2,
};

export function createShowSearcher(shows: SearchShow[]) {
    const fuse = new Fuse(shows, {
        ...FUSE_OPTIONS,
        keys: [
            { name: 'venue', weight: 3 },
            { name: 'city', weight: 2 },
            { name: 'state', weight: 1 },
            { name: 'artist', weight: 2 },
            { name: 'date', weight: 2 },
        ],
    });

    return (query: string): SearchShow[] =>
        fuse.search(query).map((result) => result.item);
}

export function createSongSearcher<
    T extends { song: string; artist: string | null },
>(songs: T[]) {
    const fuse = new Fuse(songs, {
        ...FUSE_OPTIONS,
        keys: [
            { name: 'song', weight: 3 },
            { name: 'artist', weight: 1 },
        ],
    });

    return (query: string): T[] =>
        fuse.search(query).map((result) => result.item);
}
