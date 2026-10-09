<script lang="ts">
    import { page, useHttp } from '@inertiajs/svelte';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import { onMount } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { slide } from 'svelte/transition';
    import {
        setlistsForYear,
        showYears,
        searchSlugs as searchSlugsRoute,
        songs as songsRoute,
    } from '@/actions/App/Http/Controllers/AppController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { createScrollMemory, lastVisit, toPath } from '@/lib/last-visit';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';
    import { searchState } from '@/lib/search.svelte';
    import type { SetlistRow, ShowYear, Song } from '@/types/phishnet';

    const BADGE_CLASSES =
        'inline-flex w-fit shrink-0 cursor-pointer items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-[color,box-shadow]';

    const OUTLINE_BUTTON_CLASSES =
        'inline-flex h-10 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 md:h-8 md:px-3 md:text-xs';

    const FILTER_CARD_CLASSES =
        'flex flex-col gap-4 rounded-lg border bg-card p-4';

    const FILTER_HEADING_CLASSES =
        'text-xs font-semibold tracking-wide text-muted-foreground uppercase';

    const SEGMENTED_CLASSES =
        'flex w-full rounded-md border p-0.5 md:inline-flex md:w-auto md:self-start';

    const SLIDER_CLASSES =
        'h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:rounded-full [&::-webkit-slider-runnable-track]:bg-secondary [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:rounded-full [&::-moz-range-track]:bg-secondary [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5';

    /**
     * One of the two stacked inputs forming the debut-date range slider. The
     * shared track is drawn separately underneath, so each input's own track is
     * transparent and only its thumb accepts the pointer — otherwise the input
     * on top would swallow every click meant for the one below.
     */
    const DUAL_RANGE_INPUT_CLASSES =
        'pointer-events-none absolute inset-0 h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:bg-transparent [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5';

    /** Played at some point during the show being treated as current. */
    const PLAYED_TONIGHT_CLASSES =
        'bg-green-500/10 text-green-700 dark:text-green-400';

    /** On stage right now — replaced by the green above once the show ends. */
    const LATEST_SONG_CLASSES =
        'bg-amber-500/15 font-medium text-amber-700 ring-1 ring-amber-500/40 dark:text-amber-300';

    const badgeClasses = (isSelected: boolean): string =>
        `${BADGE_CLASSES} ${
            isSelected
                ? 'bg-primary text-primary-foreground'
                : 'bg-secondary text-secondary-foreground'
        }`;

    type ViewMode = 'played' | 'not-played' | 'all';

    type Tour = {
        tourid: number;
        tourname: string;
        tourwhen: string;
        year: number;
    };

    type SongCount = {
        song: string;
        slug: string;
        count: number;
        first: string;
        last: string;
    };

    /** Phish originals, covers of other artists' songs, or both. */
    type SongSource = 'phish' | 'covers' | 'both';

    const SONG_SOURCE_OPTIONS: { value: SongSource; label: string }[] = [
        { value: 'phish', label: 'Phish Songs' },
        { value: 'covers', label: 'Covers' },
        { value: 'both', label: 'Both' },
    ];

    type StoredPrefs = {
        /** Empty means every year — the whole catalog. */
        years: number[];
        /** `year:tourid` keys; empty for a year means every tour in it. */
        tours: string[];
        minTimesPlayed: number;
        minGap: number;
        debutFrom: string | null;
        debutTo: string | null;
        statShown: StatShown;
        viewMode: ViewMode;
        songSource: SongSource;
        filtersOpen: boolean;
        showFullSetlists: boolean;
    };

    /** Fields older cookies carry, from before years and tours were multi-select. */
    type LegacyPrefs = {
        year: number;
        tourid: number;
        /** Tour ids without their year, from before "Not Part of a Tour" was told apart per year. */
        tourids: number[];
        onlyPhishSongs: boolean;
    };

    /**
     * What the reset button puts back. A new visitor starts here too, so the
     * page they land on is the one the button returns them to.
     */
    const DEFAULT_FILTERS = {
        viewMode: 'played' as ViewMode,
        songSource: 'both' as SongSource,
        minTimesPlayed: 0,
        minGap: 0,
    };

    const PREFS_COOKIE_NAME = 'tour-explorer-prefs';

    const savedPrefs = readPrefsCookie<StoredPrefs & LegacyPrefs>(
        PREFS_COOKIE_NAME,
    );

    const scrollMemory = createScrollMemory(toPath(page.url));

    let {
        excludedSongs = [],
        clientSyncActiveInterval = 60,
    }: {
        excludedSongs?: string[];
        clientSyncActiveInterval?: number;
    } = $props();

    let years = $state<number[]>([]);
    let yearsLoaded = $state(false);
    let initialLoading = $state(true);
    let showFullSetlists = $state(
        typeof savedPrefs?.showFullSetlists === 'boolean'
            ? savedPrefs.showFullSetlists
            : false,
    );
    let filtersOpen = $state(
        typeof savedPrefs?.filtersOpen === 'boolean'
            ? savedPrefs.filtersOpen
            : true,
    );
    let viewMode = $state<ViewMode>(
        savedPrefs?.viewMode === 'played' ||
            savedPrefs?.viewMode === 'all' ||
            savedPrefs?.viewMode === 'not-played'
            ? savedPrefs.viewMode
            : DEFAULT_FILTERS.viewMode,
    );

    type StatShown = 'gap' | 'play-count' | 'tour-plays' | 'debut-year' | null;
    const STAT_SHOWN_VALUES: StatShown[] = [
        'gap',
        'play-count',
        'tour-plays',
        'debut-year',
        null,
    ];
    let statShown = $state<StatShown>(
        STAT_SHOWN_VALUES.includes(savedPrefs?.statShown ?? null)
            ? (savedPrefs?.statShown ?? null)
            : null,
    );

    /**
     * The years and tours being looked at. No years means every show ever, and
     * a selected year with none of its tours picked means all of that year.
     */
    const selectedYears = new SvelteSet<number>();

    /**
     * Picked tours, keyed by year as well as id: phish.net files every one-off
     * show under the same "Not Part of a Tour" id, so picking it in one year
     * must not pick it in every other.
     */
    const selectedTourKeys = new SvelteSet<string>();

    const tourKey = (tour: { year: number; tourid: number }): string =>
        `${tour.year}:${tour.tourid}`;

    /**
     * Held back until the first selection is in place, so the cookie is not
     * overwritten with an empty (= every year) selection while the page loads.
     */
    let selectionReady = $state(false);

    let allSongs = $state<Song[] | null>(null);
    let allSongsLoading = $state(false);

    let dialogOpen = $state(false);
    let dialogSlug = $state<string | null>(null);

    let minTimesPlayed = $state(
        typeof savedPrefs?.minTimesPlayed === 'number'
            ? savedPrefs.minTimesPlayed
            : DEFAULT_FILTERS.minTimesPlayed,
    );
    let minGap = $state(
        typeof savedPrefs?.minGap === 'number'
            ? savedPrefs.minGap
            : DEFAULT_FILTERS.minGap,
    );

    const savedSongSource = (): SongSource => {
        if (
            SONG_SOURCE_OPTIONS.some(
                (option) => option.value === savedPrefs?.songSource,
            )
        ) {
            return savedPrefs!.songSource!;
        }

        if (typeof savedPrefs?.onlyPhishSongs === 'boolean') {
            return savedPrefs.onlyPhishSongs ? 'phish' : 'both';
        }

        return DEFAULT_FILTERS.songSource;
    };

    let songSource = $state<SongSource>(savedSongSource());

    /** Whether a catalog artist passes the Phish / Covers / Both control. */
    const matchesSongSource = (artist: string): boolean =>
        songSource === 'both' ||
        (songSource === 'phish') === (artist === 'Phish');

    const DAY_MS = 86_400_000;

    /** ISO dates compare lexicographically, so days only matter for the slider. */
    const dayFromIsoDate = (date: string): number =>
        Math.round(Date.parse(date) / DAY_MS);

    const isoDateFromDay = (day: number): string =>
        new Date(day * DAY_MS).toISOString().slice(0, 10);

    const savedDebutDay = (value: unknown): number | null => {
        if (typeof value !== 'string' || value === '') {
            return null;
        }

        const day = dayFromIsoDate(value);

        return Number.isNaN(day) ? null : day;
    };

    /**
     * Where the debut-range handles have been dragged to, as days since the
     * epoch. `null` means the handle is resting at its end of the range — no
     * filter — which lets it follow the bounds if they move (say, the Phish-only
     * toggle flips) instead of pinning to a stale date.
     */
    let debutFromDay = $state<number | null>(
        savedDebutDay(savedPrefs?.debutFrom),
    );
    let debutToDay = $state<number | null>(savedDebutDay(savedPrefs?.debutTo));

    const yearData = new SvelteMap<number, SetlistRow[]>();

    const yearsHttp = useHttp<Record<string, never>, { data: ShowYear[] }>({});
    const setlistsHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {},
    );
    const songsHttp = useHttp<Record<string, never>, { data: Song[] }>({});
    const searchSlugsHttp = useHttp<Record<string, never>, { data: string[] }>(
        {},
    );
    const refreshHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {},
    );
    // Shared poll loop: refetch the live year whenever its version hash moves,
    // and expose the show-window flag + countdown the setlists section renders.
    const livePoll = createLivePoll({
        activeInterval: clientSyncActiveInterval,
        onStale: (status) => {
            if (status.year !== null) {
                refreshLoadedYear(status.year);
            }
        },
    });

    function refreshLoadedYear(year: number) {
        if (!yearData.has(year)) {
            return;
        }

        // The tour chips and everything below them derive from `yearData`, so a
        // new show or tour that just landed shows up without further work.
        refreshHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearData.set(year, response.data);
            },
        });
    }

    /** No years picked: the catalog stands in for every show ever played. */
    const isEverything = $derived(selectedYears.size === 0);

    const sortedSelectedYears = $derived(
        [...selectedYears].sort((a, b) => a - b),
    );

    /** Every selected year's setlists are in, so the lists can be built. */
    const scopeLoading = $derived(
        sortedSelectedYears.some((year) => !yearData.has(year)),
    );

    /** The tour chips on offer: every tour in the selected years. */
    const availableTours = $derived(
        sortedSelectedYears.flatMap((year) => buildToursForYear(year)),
    );

    /**
     * The tours actually in play. A year narrows to whichever of its tours are
     * picked, or keeps all of them when none are — so picking a tour in one
     * year never empties another.
     */
    const scopeTours = $derived(
        sortedSelectedYears.flatMap((year) => {
            const yearTours = buildToursForYear(year);
            const picked = yearTours.filter((tour) =>
                selectedTourKeys.has(tourKey(tour)),
            );

            return picked.length ? picked : yearTours;
        }),
    );

    /** The one tour in play, when there is exactly one — Previous/Next step from it. */
    const singleTour = $derived<Tour | null>(
        scopeTours.length === 1 ? scopeTours[0] : null,
    );

    const excludedSet = $derived(new SvelteSet(excludedSongs));

    const tourRows = $derived.by(() => {
        const toursByYear = new SvelteMap<number, SvelteSet<number>>();

        for (const tour of scopeTours) {
            const ids = toursByYear.get(tour.year) ?? new SvelteSet<number>();

            ids.add(tour.tourid);
            toursByYear.set(tour.year, ids);
        }

        return sortedSelectedYears.flatMap((year) => {
            const ids = toursByYear.get(year);

            return (yearData.get(year) ?? []).filter(
                (row) => row.artistid === 1 && ids?.has(row.tourid),
            );
        });
    });

    const countedRows = $derived(
        tourRows.filter((row) => !excludedSet.has(row.slug)),
    );

    /**
     * The show the page is treating as the current one. That is the show being
     * played while one is on, and stays put afterwards until the server's
     * cutoff — 2pm venue time the next day — so the page still reads as "last
     * night's show" when it is opened in the morning.
     */
    const liveShowdate = $derived(livePoll.highlightShowdate);

    /**
     * Rows from that show, and only when it belongs to the tour on screen —
     * browsing away to another tour turns every highlight below off.
     */
    const liveShowRows = $derived(
        liveShowdate === null
            ? []
            : tourRows.filter((row) => row.showdate === liveShowdate),
    );

    const liveSlugs = $derived(new SvelteSet(liveShowRows.map((r) => r.slug)));

    /**
     * The gap each song carried into the highlighted show — how many shows it
     * had been missing before it was played there. This lives on the setlist
     * row, so it survives the catalog's own gap resetting to zero once the play
     * is imported, and it is the value these songs keep showing until the
     * highlight clears the next day.
     */
    const liveGapBySlug = $derived(
        new SvelteMap(liveShowRows.map((row) => [row.slug, row.gap])),
    );

    /**
     * The song on stage, which is the newest entry of the show being played.
     *
     * Only while the show is actually on: once it ends, this song stops being
     * the exception and joins the rest of the night in green, which stays put
     * until the grace period closes the next afternoon.
     */
    const latestSongSlug = $derived(
        livePoll.inShowWindow ? (liveShowRows.at(-1)?.slug ?? null) : null,
    );

    /**
     * Tailwind puts no weight on the order classes appear in the attribute, so
     * these deliberately avoid restating a colour the base classes already set;
     * callers swap them in rather than append them.
     */
    function liveClasses(slug: string): string {
        if (slug === latestSongSlug) {
            return LATEST_SONG_CLASSES;
        }

        return liveSlugs.has(slug) ? PLAYED_TONIGHT_CLASSES : '';
    }

    const tourShows = $derived.by(() => {
        const grouped = new SvelteMap<number, SetlistRow[]>();

        for (const row of tourRows) {
            const existing = grouped.get(row.showid);

            if (existing) {
                existing.push(row);
            } else {
                grouped.set(row.showid, [row]);
            }
        }

        // Newest show first, so the current (or most recent) show sits on top.
        return [...grouped.values()].sort((a, b) =>
            b[0].showdate.localeCompare(a[0].showdate),
        );
    });

    const songCounts = $derived.by<SongCount[]>(() => {
        // Every year would mean fetching every setlist ever, but the catalog
        // already holds each song's all-time count and first/last dates.
        if (isEverything) {
            return (allSongs ?? [])
                .filter(
                    (song) =>
                        song.times_played > 0 && !excludedSet.has(song.slug),
                )
                .map((song) => ({
                    song: song.song,
                    slug: song.slug,
                    count: song.times_played,
                    first: song.debut,
                    last: song.last_played,
                }))
                .sort(
                    (a, b) => b.count - a.count || a.song.localeCompare(b.song),
                );
        }

        const counts = new SvelteMap<string, SongCount>();

        for (const row of countedRows) {
            const existing = counts.get(row.slug);

            if (existing) {
                existing.count += 1;
                existing.first =
                    row.showdate < existing.first
                        ? row.showdate
                        : existing.first;
                existing.last =
                    row.showdate > existing.last ? row.showdate : existing.last;
            } else {
                counts.set(row.slug, {
                    song: row.song,
                    slug: row.slug,
                    count: 1,
                    first: row.showdate,
                    last: row.showdate,
                });
            }
        }

        return [...counts.values()].sort(
            (a, b) => b.count - a.count || a.song.localeCompare(b.song),
        );
    });

    /** Catalog entry by slug, so played rows can show all-time play count / gap. */
    const catalogBySlug = $derived(
        new SvelteMap((allSongs ?? []).map((song) => [song.slug, song])),
    );

    /** Highest gap among the songs the source control lets through, capping the slider. */
    const maxGap = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return allSongs.reduce(
            (highest, song) =>
                matchesSongSource(song.artist) && song.gap > highest
                    ? song.gap
                    : highest,
            0,
        );
    });

    /** Highest play count among the songs the source control lets through. */
    const maxTimesPlayed = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return allSongs.reduce(
            (highest, song) =>
                matchesSongSource(song.artist) && song.times_played > highest
                    ? song.times_played
                    : highest,
            0,
        );
    });

    /** Oldest and newest debut dates among the songs the source control lets through. */
    const debutBounds = $derived.by(() => {
        if (!allSongs) {
            return null;
        }

        let min = Infinity;
        let max = -Infinity;

        for (const song of allSongs) {
            if (!matchesSongSource(song.artist) || !song.debut) {
                continue;
            }

            const day = dayFromIsoDate(song.debut);

            if (day < min) {
                min = day;
            }

            if (day > max) {
                max = day;
            }
        }

        return min === Infinity ? null : { min, max };
    });

    /** Handle positions clamped to the current bounds, lower before upper. */
    const debutFromValue = $derived(
        debutBounds === null
            ? 0
            : Math.min(
                  Math.max(debutFromDay ?? debutBounds.min, debutBounds.min),
                  debutBounds.max,
              ),
    );
    const debutToValue = $derived(
        debutBounds === null
            ? 0
            : Math.max(
                  Math.min(debutToDay ?? debutBounds.max, debutBounds.max),
                  debutFromValue,
              ),
    );

    const debutFromPercent = $derived(
        debutBounds === null || debutBounds.max === debutBounds.min
            ? 0
            : ((debutFromValue - debutBounds.min) /
                  (debutBounds.max - debutBounds.min)) *
                  100,
    );
    const debutToPercent = $derived(
        debutBounds === null || debutBounds.max === debutBounds.min
            ? 100
            : ((debutToValue - debutBounds.min) /
                  (debutBounds.max - debutBounds.min)) *
                  100,
    );

    /**
     * When both handles sit together, only the input on top can be grabbed.
     * Raising the lower handle whenever it is past the midpoint means the
     * grabbable one is always the handle that still has somewhere to go.
     */
    const debutFromOnTop = $derived(
        debutBounds !== null &&
            debutFromValue > (debutBounds.min + debutBounds.max) / 2,
    );

    const debutFromDate = $derived(
        debutBounds === null || debutFromDay === null
            ? null
            : isoDateFromDay(debutFromValue),
    );
    const debutToDate = $derived(
        debutBounds === null || debutToDay === null
            ? null
            : isoDateFromDay(debutToValue),
    );

    /*
     * Top-bar search, answered by the server's search engine. A song stays on
     * the grid if its own name matches, or if it was played at any show that
     * matches — so a venue narrows the grid to what was played there. The
     * previous answer stays up while the next one is in flight.
     */
    const SEARCH_DEBOUNCE_MS = 200;

    /** Null when no search is active, so every list is left untouched. */
    let searchSlugs = $state<Set<string> | null>(null);

    const searchTerm = $derived(searchState.query.trim());

    $effect(() => {
        const term = searchTerm;

        if (term.length < 2) {
            searchSlugs = null;

            return;
        }

        const timer = setTimeout(() => {
            searchSlugsHttp.get(searchSlugsRoute.url({ query: { q: term } }), {
                onSuccess: (response) => {
                    // Ignore a slow answer to a term that has been typed over.
                    if (term === searchState.query.trim()) {
                        searchSlugs = new Set(response.data);
                    }
                },
            });
        }, SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timer);
    });

    /**
     * The played list honours the play-count and debut-range sliders too, via
     * each song's catalog entry. A song the catalog has not loaded (or does not
     * know) is let through rather than hidden on missing data.
     *
     * A search sets the sliders aside, but not the Phish / Covers / Both
     * control: that narrows search results too.
     */
    const playedAlphabetical = $derived(
        songCounts
            .filter((row) => searchSlugs === null || searchSlugs.has(row.slug))
            .filter((row) => {
                const catalogEntry = catalogBySlug.get(row.slug);

                if (!catalogEntry) {
                    return true;
                }

                if (!matchesSongSource(catalogEntry.artist)) {
                    return false;
                }

                if (searchSlugs !== null) {
                    return true;
                }

                return (
                    catalogEntry.times_played >= minTimesPlayed &&
                    (debutFromDate === null ||
                        catalogEntry.debut >= debutFromDate) &&
                    (debutToDate === null || catalogEntry.debut <= debutToDate)
                );
            })
            .sort((a, b) => a.song.localeCompare(b.song)),
    );

    const notPlayed = $derived.by(() => {
        if (!allSongs) {
            return [];
        }

        /*
         * The current show's plays are deliberately left out, so a song it
         * debuts stays on this list rather than vanishing out from under
         * whoever is watching. It is lit up in the meantime, and drops off on
         * its own when the grace period ends the next afternoon and
         * `liveShowdate` goes null.
         */
        const playedSlugs = new SvelteSet(
            isEverything
                ? songCounts.map((row) => row.slug)
                : countedRows
                      .filter((row) => row.showdate !== liveShowdate)
                      .map((row) => row.slug),
        );

        return allSongs
            .filter(
                (song) =>
                    matchesSongSource(song.artist) &&
                    (searchSlugs !== null ||
                        (song.times_played >= minTimesPlayed &&
                            (minGap === 0 || song.gap >= minGap) &&
                            (debutFromDate === null ||
                                song.debut >= debutFromDate) &&
                            (debutToDate === null ||
                                song.debut <= debutToDate))) &&
                    !playedSlugs.has(song.slug) &&
                    !excludedSet.has(song.slug) &&
                    (searchSlugs === null || searchSlugs.has(song.slug)),
            )
            .sort((a, b) => a.song.localeCompare(b.song));
    });

    /**
     * Every catalog song passing the sliders, played this tour or not. Played
     * ones keep the played list's full-strength text; the rest are muted the
     * way the not-played list shows them.
     */
    const allSorted = $derived.by(() => {
        if (!allSongs) {
            return [];
        }

        return allSongs
            .filter(
                (song) =>
                    matchesSongSource(song.artist) &&
                    (searchSlugs !== null ||
                        (song.times_played >= minTimesPlayed &&
                            (debutFromDate === null ||
                                song.debut >= debutFromDate) &&
                            (debutToDate === null ||
                                song.debut <= debutToDate))) &&
                    !excludedSet.has(song.slug) &&
                    (searchSlugs === null || searchSlugs.has(song.slug)),
            )
            .sort((a, b) => a.song.localeCompare(b.song));
    });

    const tourCountBySlug = $derived(
        new Map(songCounts.map((row) => [row.slug, row.count])),
    );

    /** One card in the song grid, whichever of the three lists it came from. */
    type SongCard = {
        slug: string;
        song: string;
        tourPlays: number;
        totalPlays: number | null;
        gap: number | null;
        debutYear: string;
        /** Not played in the selected shows, so shown dimmed. */
        muted: boolean;
    };

    /**
     * The list the view toggle asks for, flattened to the fields a card shows.
     * The gap prefers the one carried into tonight's show, so a song just
     * played keeps the gap it broke until the highlight clears.
     */
    const songCards = $derived.by<SongCard[]>(() => {
        if (viewMode === 'played') {
            return playedAlphabetical.map((row) => {
                const catalogEntry = catalogBySlug.get(row.slug);

                return {
                    slug: row.slug,
                    song: row.song,
                    tourPlays: row.count,
                    totalPlays: catalogEntry?.times_played ?? null,
                    gap:
                        liveGapBySlug.get(row.slug) ??
                        catalogEntry?.gap ??
                        null,
                    debutYear: catalogEntry?.debut.slice(0, 4) ?? '',
                    muted: false,
                };
            });
        }

        return (viewMode === 'all' ? allSorted : notPlayed).map((song) => ({
            slug: song.slug,
            song: song.song,
            tourPlays: tourCountBySlug.get(song.slug) ?? 0,
            totalPlays: song.times_played,
            gap: liveGapBySlug.get(song.slug) ?? song.gap,
            debutYear: song.debut.slice(0, 4),
            muted: viewMode === 'not-played' || !tourCountBySlug.has(song.slug),
        }));
    });

    const VIEW_MODE_OPTIONS: { value: ViewMode; label: string }[] = [
        { value: 'played', label: 'Played' },
        { value: 'not-played', label: 'Not Played' },
        { value: 'all', label: 'All' },
    ];

    /** The number each card can carry, with the colours of its button and badge. */
    const STAT_OPTIONS: {
        value: StatShown;
        label: string;
        activeClass: string;
        badgeClass: string;
    }[] = [
        {
            value: 'tour-plays',
            label: 'Tour Plays',
            activeClass: 'bg-sky-700 text-sky-100',
            badgeClass: 'ring-sky-500/30 text-sky-400/60',
        },
        {
            value: 'play-count',
            label: 'Total Plays',
            activeClass: 'bg-fuchsia-700 text-fuchsia-200',
            badgeClass: 'ring-fuchsia-500/30 text-fuchsia-500/60',
        },
        {
            value: 'gap',
            label: 'Gap',
            activeClass: 'bg-green-700 text-green-100',
            badgeClass: 'ring-green-800/30 text-green-400/60',
        },
        {
            value: 'debut-year',
            label: 'Debut Year',
            activeClass: 'bg-slate-700 text-slate-100',
            badgeClass: 'ring-slate-500/30',
        },
        {
            value: null,
            label: 'None',
            activeClass: 'bg-primary text-primary-foreground',
            badgeClass: '',
        },
    ];

    /** "Tour Plays" means nothing for songs the selected shows never played. */
    const statOptions = $derived(
        STAT_OPTIONS.filter(
            (option) =>
                viewMode !== 'not-played' || option.value !== 'tour-plays',
        ),
    );

    const statBadgeClass = $derived(
        STAT_OPTIONS.find((option) => option.value === statShown)?.badgeClass ??
            '',
    );

    function statValue(card: SongCard): string | number {
        switch (statShown) {
            case 'tour-plays':
                return card.tourPlays;
            case 'play-count':
                return card.totalPlays ?? '—';
            case 'gap':
                return card.gap ?? '—';
            case 'debut-year':
                return card.debutYear || '—';
            default:
                return '';
        }
    }

    const songCountLabel = $derived.by(() => {
        const count = songCards.length;
        const kind =
            songSource === 'phish'
                ? 'Phish song'
                : songSource === 'covers'
                  ? 'cover song'
                  : 'song';
        const suffix =
            viewMode === 'played'
                ? ' played'
                : viewMode === 'not-played'
                  ? ' not played'
                  : '';

        return `${count} ${kind}${count !== 1 ? 's' : ''}${suffix}`;
    });

    const showCountLabel = $derived(
        `${tourShows.length} show${tourShows.length !== 1 ? 's' : ''}`,
    );

    const dialogCatalogEntry = $derived(
        allSongs?.find((song) => song.slug === dialogSlug) ?? null,
    );

    /** Newest first, the way the dialog lists every other performance. */
    const dialogPerformances = $derived(
        [...tourRows]
            .filter((row) => row.slug === dialogSlug)
            .sort((a, b) => b.showdate.localeCompare(a.showdate)),
    );

    function buildToursForYear(year: number): Tour[] {
        const rows = yearData.get(year) ?? [];

        const sorted = [...rows]
            .filter((row) => row.artistid === 1)
            .sort((a, b) => a.showdate.localeCompare(b.showdate));

        const tours: Tour[] = [];
        const seen = new SvelteSet<number>();

        for (const row of sorted) {
            if (seen.has(row.tourid)) {
                continue;
            }

            seen.add(row.tourid);
            tours.push({
                tourid: row.tourid,
                tourname: row.tourname,
                tourwhen: row.tourwhen,
                year,
            });
        }

        return tours;
    }

    /**
     * Years waiting to be fetched, and whoever is waiting on each. Fetched one
     * at a time through the one request helper, so picking several years at
     * once queues them instead of having each request trip over the last.
     */
    const yearQueue: number[] = [];
    const yearCallbacks = new SvelteMap<number, (() => void)[]>();
    let yearRequestInFlight = false;

    function loadYear(year: number, onLoaded?: () => void) {
        if (yearData.has(year)) {
            onLoaded?.();

            return;
        }

        const waiting = yearCallbacks.get(year);

        if (waiting) {
            if (onLoaded) {
                waiting.push(onLoaded);
            }

            return;
        }

        yearCallbacks.set(year, onLoaded ? [onLoaded] : []);
        yearQueue.push(year);
        loadNextYear();
    }

    function loadNextYear() {
        if (yearRequestInFlight) {
            return;
        }

        const year = yearQueue.shift();

        if (year === undefined) {
            return;
        }

        yearRequestInFlight = true;

        const settle = () => {
            yearRequestInFlight = false;
            loadNextYear();
        };

        // A failed year is forgotten rather than retried in a loop; picking it
        // again asks for it again.
        const fail = () => {
            yearCallbacks.delete(year);
            settle();
        };

        setlistsHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearData.set(year, response.data);

                const callbacks = yearCallbacks.get(year) ?? [];

                yearCallbacks.delete(year);
                callbacks.forEach((callback) => callback());
                settle();
            },
            onError: fail,
            onNetworkError: fail,
        });
    }

    function mostRecentTourIndex(tours: Tour[], rows: SetlistRow[]): number {
        let bestIndex = Math.max(tours.length - 1, 0);
        let bestDate = '';

        for (const row of rows) {
            if (row.artistid !== 1 || row.showdate <= bestDate) {
                continue;
            }

            const index = tours.findIndex((tour) => tour.tourid === row.tourid);

            if (index !== -1) {
                bestDate = row.showdate;
                bestIndex = index;
            }
        }

        return bestIndex;
    }

    /**
     * Narrow the selection to the most recent tour of a year. A year with no
     * Phish tours (only guest appearances) falls back to the year before.
     */
    function selectLatestTour(year: number) {
        loadYear(year, () => {
            const tours = buildToursForYear(year);

            if (!tours.length) {
                const previousYear = years[years.indexOf(year) - 1];

                if (previousYear !== undefined) {
                    selectLatestTour(previousYear);
                }

                return;
            }

            const tour =
                tours[mostRecentTourIndex(tours, yearData.get(year) ?? [])];

            selectedYears.clear();
            selectedYears.add(tour.year);
            selectedTourKeys.clear();
            selectedTourKeys.add(tourKey(tour));
            selectionReady = true;
        });
    }

    function toggleYear(year: number) {
        if (!selectedYears.has(year)) {
            selectedYears.add(year);
            loadYear(year);

            return;
        }

        selectedYears.delete(year);

        // Its tours go with it.
        for (const tour of buildToursForYear(year)) {
            selectedTourKeys.delete(tourKey(tour));
        }
    }

    function toggleTour(tour: Tour) {
        const key = tourKey(tour);

        if (selectedTourKeys.has(key)) {
            selectedTourKeys.delete(key);
        } else {
            selectedTourKeys.add(key);
        }
    }

    /** Back to the most recent tour, played songs, Phish and covers, sliders wide open. */
    function resetFilters() {
        viewMode = DEFAULT_FILTERS.viewMode;
        songSource = DEFAULT_FILTERS.songSource;
        minTimesPlayed = DEFAULT_FILTERS.minTimesPlayed;
        minGap = DEFAULT_FILTERS.minGap;
        debutFromDay = null;
        debutToDay = null;

        if (years.length) {
            selectLatestTour(years[years.length - 1]);
        }
    }

    /**
     * The handles are clamped so they cannot cross, and a handle pushed back to
     * its own end of the range dissolves into "no filter". The DOM value is
     * written back because Svelte only re-renders `value` when the clamped
     * result changes — a thumb dragged past the other handle would otherwise
     * leave the DOM ahead of the state.
     */
    function onDebutFromInput(event: Event) {
        if (debutBounds === null) {
            return;
        }

        const input = event.currentTarget as HTMLInputElement;
        const clamped = Math.min(Number(input.value), debutToValue);

        debutFromDay = clamped <= debutBounds.min ? null : clamped;
        input.value = String(clamped);
    }

    function onDebutToInput(event: Event) {
        if (debutBounds === null) {
            return;
        }

        const input = event.currentTarget as HTMLInputElement;
        const clamped = Math.max(Number(input.value), debutFromValue);

        debutToDay = clamped >= debutBounds.max ? null : clamped;
        input.value = String(clamped);
    }

    function openSongDialog(slug: string) {
        dialogSlug = slug;
        dialogOpen = true;
    }

    /**
     * Put back the years and tours from the last visit — including an empty
     * selection, which is a deliberate "every year" — or, failing that, the
     * most recent tour.
     */
    function restoreSelection() {
        const isNumber = (value: unknown): value is number =>
            typeof value === 'number';

        const savedYears = Array.isArray(savedPrefs?.years)
            ? savedPrefs.years.filter(isNumber)
            : isNumber(savedPrefs?.year)
              ? [savedPrefs.year]
              : null;

        const legacyTourIds = Array.isArray(savedPrefs?.tourids)
            ? savedPrefs.tourids.filter(isNumber)
            : isNumber(savedPrefs?.tourid)
              ? [savedPrefs.tourid]
              : [];

        // Older cookies held bare tour ids, which applied to every saved year.
        const savedTourKeys = Array.isArray(savedPrefs?.tours)
            ? savedPrefs.tours.filter(
                  (key): key is string => typeof key === 'string',
              )
            : (savedYears ?? []).flatMap((year) =>
                  legacyTourIds.map((tourid) => tourKey({ year, tourid })),
              );

        const knownYears = savedYears?.filter((year) => years.includes(year));

        // A cookie naming only years that no longer exist gets the default
        // rather than silently widening to every year.
        if (
            savedYears === null ||
            (savedYears.length > 0 && knownYears?.length === 0)
        ) {
            if (years.length) {
                selectLatestTour(years[years.length - 1]);
            }

            return;
        }

        for (const year of knownYears ?? []) {
            selectedYears.add(year);
            loadYear(year);
        }

        for (const key of savedTourKeys) {
            selectedTourKeys.add(key);
        }

        selectionReady = true;
    }

    onMount(() => {
        // A returning visitor whose last page was elsewhere is already on their
        // way there. Fetching the years, every song and a tour's setlists just
        // to unmount a moment later is three requests thrown away.
        if (lastVisit.bouncePending) {
            return;
        }

        yearsHttp.get(showYears.url(), {
            onSuccess: (response) => {
                const seen = new SvelteSet<number>();
                const thisYear = new Date().getFullYear();

                years = response.data
                    .map((show) => Number(show.showyear))
                    .filter((year) => {
                        if (year > thisYear || seen.has(year)) {
                            return false;
                        }

                        seen.add(year);

                        return true;
                    })
                    .sort((a, b) => a - b);

                yearsLoaded = true;

                restoreSelection();

                initialLoading = false;
            },
        });

        allSongsLoading = true;

        songsHttp.get(songsRoute.url(), {
            onSuccess: (response) => {
                allSongs = response.data;
                allSongsLoading = false;
            },
        });

        // Establish the version baseline and start the self-pacing poll loop.
        livePoll.start();

        const stopTracking = scrollMemory.track();

        return () => {
            stopTracking();
            livePoll.stop();
        };
    });

    /*
     * The song list is built from three separate fetches, and the years arriving
     * only means the page has begun filling in. Waiting on the tour's setlists
     * and the song catalog too is what makes the document tall enough to hold
     * the offset the visitor left at.
     */
    $effect(() => {
        if (
            !initialLoading &&
            yearsLoaded &&
            !scopeLoading &&
            !allSongsLoading
        ) {
            scrollMemory.restore();
        }
    });

    // "Tour Plays" only exists for the played list, so fall back to Gap when the
    // user switches to the not-played view rather than leaving it selected.
    $effect(() => {
        if (viewMode === 'not-played' && statShown === 'tour-plays') {
            statShown = 'play-count';
        }
    });

    $effect(() => {
        if (!selectionReady) {
            return;
        }

        writePrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME, {
            years: sortedSelectedYears,
            tours: [...selectedTourKeys],
            minTimesPlayed,
            minGap,
            debutFrom: debutFromDate,
            debutTo: debutToDate,
            statShown,
            viewMode,
            songSource,
            filtersOpen,
            showFullSetlists,
        });
    });
</script>

<AppHead />

<!-- One button of a segmented control. -->
{#snippet segmentButton(
    label: string,
    isActive: boolean,
    onclick: () => void,
    activeClass = 'bg-primary text-primary-foreground',
)}
    <button
        type="button"
        role="radio"
        aria-checked={isActive}
        {onclick}
        class={[
            'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
            isActive
                ? activeClass
                : 'text-muted-foreground hover:text-foreground',
        ]}
    >
        {label}
    </button>
{/snippet}

<!-- A labelled row in a filter card: what the control does on the left, the control under it. -->
{#snippet fieldLabel(label: string, hint = '', forId = '')}
    <div class="flex items-baseline justify-between gap-3">
        {#if forId}
            <label for={forId} class="text-sm font-medium">{label}</label>
        {:else}
            <span class="text-sm font-medium">{label}</span>
        {/if}
        {#if hint}
            <span class="text-right text-xs tabular-nums text-muted-foreground">
                {hint}
            </span>
        {/if}
    </div>
{/snippet}

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div class="flex max-w-5xl flex-col items-start gap-2">
        <h1 class="text-2xl font-semibold">Song Checker</h1>

        {#if !initialLoading && yearsLoaded}
            <div class="flex flex-col sm:flex-row w-full justify-between sm:justify-end gap-2">
                <button
                    type="button"
                    onclick={resetFilters}
                    class={OUTLINE_BUTTON_CLASSES}
                >
                    Reset filters
                </button>
                <button
                    type="button"
                    onclick={() => (filtersOpen = !filtersOpen)}
                    aria-expanded={filtersOpen}
                    aria-controls="song-checker-filters"
                    class={OUTLINE_BUTTON_CLASSES}
                >
                    {filtersOpen ? 'Hide filters' : 'Show filters'}
                    <ChevronDown
                        class="size-4 transition-transform duration-200 {filtersOpen
                            ? 'rotate-180'
                            : ''}"
                    />
                </button>
            </div>
        {/if}
    </div>

    {#if initialLoading || !yearsLoaded}
        <p class="text-sm text-muted-foreground">Loading…</p>
    {:else}
        {#if filtersOpen}
            <div
                id="song-checker-filters"
                class="grid max-w-5xl gap-4 md:grid-cols-2"
                transition:slide={{ duration: 200 }}
            >
                <!-- Step 1: which shows to look at. -->
                <section class={FILTER_CARD_CLASSES}>
                    <h2 class={FILTER_HEADING_CLASSES}>1 · Shows</h2>

                    <div class="flex flex-col gap-2">
                        {@render fieldLabel(
                            'Years',
                            selectedYears.size
                                ? `${selectedYears.size} selected`
                                : 'None selected = every year',
                        )}
                        <div class="flex flex-wrap gap-1.5">
                            {#each years as year (year)}
                                <button
                                    type="button"
                                    onclick={() => toggleYear(year)}
                                    aria-pressed={selectedYears.has(year)}
                                    class={badgeClasses(
                                        selectedYears.has(year),
                                    )}
                                >
                                    {year}
                                </button>
                            {/each}
                        </div>
                    </div>

                    {#if availableTours.length}
                        <div class="flex flex-col gap-2">
                            {@render fieldLabel(
                                'Tours',
                                availableTours.some((tour) =>
                                    selectedTourKeys.has(tourKey(tour)),
                                )
                                    ? ''
                                    : 'None selected = every tour',
                            )}
                            <div class="flex flex-wrap gap-1.5">
                                {#each availableTours as tour (tourKey(tour))}
                                    <button
                                        type="button"
                                        onclick={() => toggleTour(tour)}
                                        aria-pressed={selectedTourKeys.has(
                                            tourKey(tour),
                                        )}
                                        class={badgeClasses(
                                            selectedTourKeys.has(tourKey(tour)),
                                        )}
                                    >
                                        {selectedYears.size > 1 &&
                                        !tour.tourname.includes(
                                            String(tour.year),
                                        )
                                            ? `${tour.tourname} (${tour.year})`
                                            : tour.tourname}
                                    </button>
                                {/each}
                            </div>
                        </div>
                    {/if}
                </section>

                <!-- Step 2: which songs from those shows to list. -->
                <section class={FILTER_CARD_CLASSES}>
                    <h2 class={FILTER_HEADING_CLASSES}>2 · Songs</h2>

                    <div class="flex flex-col gap-2">
                        {@render fieldLabel('Show songs that were')}
                        <div
                            role="radiogroup"
                            aria-label="Played status"
                            class={SEGMENTED_CLASSES}
                        >
                            {#each VIEW_MODE_OPTIONS as option (option.value)}
                                {@render segmentButton(
                                    option.label,
                                    viewMode === option.value,
                                    () => (viewMode = option.value),
                                )}
                            {/each}
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        {@render fieldLabel('Written by')}
                        <div
                            role="radiogroup"
                            aria-label="Song source"
                            class={SEGMENTED_CLASSES}
                        >
                            {#each SONG_SOURCE_OPTIONS as option (option.value)}
                                {@render segmentButton(
                                    option.label,
                                    songSource === option.value,
                                    () => (songSource = option.value),
                                )}
                            {/each}
                        </div>
                    </div>

                    {#if allSongs}
                        <div class="flex flex-col gap-1">
                            {@render fieldLabel(
                                'Played at least',
                                `${minTimesPlayed}+ times all-time`,
                                'min-times-played',
                            )}
                            <input
                                id="min-times-played"
                                type="range"
                                min="0"
                                max={maxTimesPlayed}
                                step="5"
                                bind:value={minTimesPlayed}
                                class={SLIDER_CLASSES}
                            />
                        </div>

                        <!-- A gap only means something for songs still waiting to be played. -->
                        {#if viewMode === 'not-played'}
                            <div class="flex flex-col gap-1">
                                {@render fieldLabel(
                                    'Gap of at least',
                                    minGap === 0 ? 'Any' : `${minGap}+ shows`,
                                    'min-gap',
                                )}
                                <input
                                    id="min-gap"
                                    type="range"
                                    min="0"
                                    max={maxGap}
                                    step="5"
                                    bind:value={minGap}
                                    class={SLIDER_CLASSES}
                                />
                            </div>
                        {/if}

                        {#if debutBounds}
                            <div class="flex flex-col gap-1">
                                {@render fieldLabel(
                                    'Debuted between',
                                    `${isoDateFromDay(debutFromValue)} – ${isoDateFromDay(debutToValue)}`,
                                )}
                                <div class="relative h-6 md:h-5">
                                    <div
                                        class="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-secondary"
                                    ></div>
                                    <div
                                        class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-primary/30"
                                        style="left: {debutFromPercent}%; right: {100 -
                                            debutToPercent}%"
                                    ></div>
                                    <input
                                        type="range"
                                        aria-label="Earliest debut date"
                                        min={debutBounds.min}
                                        max={debutBounds.max}
                                        step="1"
                                        value={debutFromValue}
                                        oninput={onDebutFromInput}
                                        class="{DUAL_RANGE_INPUT_CLASSES} {debutFromOnTop
                                            ? 'z-30'
                                            : 'z-10'}"
                                    />
                                    <input
                                        type="range"
                                        aria-label="Latest debut date"
                                        min={debutBounds.min}
                                        max={debutBounds.max}
                                        step="1"
                                        value={debutToValue}
                                        oninput={onDebutToInput}
                                        class="{DUAL_RANGE_INPUT_CLASSES} z-20"
                                    />
                                </div>
                            </div>
                        {/if}
                    {/if}
                </section>
            </div>
        {/if}

        <div class="max-w-5xl">
            {#if scopeLoading}
                <p class="text-sm text-muted-foreground">Loading shows…</p>
            {:else if years.length}
                <!-- Results: what is being looked at, how many songs, and the number on each card. -->
                <div
                    class="flex flex-col gap-3 border-b pb-3 md:flex-row md:items-end md:justify-between"
                >
                    <div>
                        <h2 class="font-serif text-xl font-medium">
                            {#if singleTour}
                                {singleTour.tourname}
                            {:else if isEverything}
                                Every year
                            {:else}
                                {showCountLabel}
                            {/if}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {#if singleTour}
                                {singleTour.tourwhen} &middot; {showCountLabel}
                                &middot;
                            {/if}
                            {allSongs ? songCountLabel : 'Loading songs…'}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1 md:items-end">
                        <span class="text-xs text-muted-foreground">
                            Number on each song tile
                        </span>
                        <div
                            role="radiogroup"
                            aria-label="Number on each song tile"
                            class={SEGMENTED_CLASSES}
                        >
                            {#each statOptions as option (option.label)}
                                {@render segmentButton(
                                    option.label,
                                    statShown === option.value,
                                    () => (statShown = option.value),
                                    option.activeClass,
                                )}
                            {/each}
                        </div>
                    </div>
                </div>

                {#if !allSongs}
                    <p class="mt-4 text-sm text-muted-foreground">
                        Loading full song catalog…
                    </p>
                {:else if songCards.length}
                    <div
                        class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                    >
                        {#each songCards as card (card.slug)}
                            <button
                                type="button"
                                onclick={() => openSongDialog(card.slug)}
                                class="flex items-baseline cursor-pointer ring-1 ring-slate-500/10 justify-between gap-2 rounded p-3 text-left text-base hover:bg-accent {liveClasses(
                                    card.slug,
                                ) ||
                                    (card.muted
                                        ? 'text-muted-foreground'
                                        : 'hover:text-primary')}"
                            >
                                <span class="truncate">{card.song}</span>
                                {#if statShown !== null}
                                    <span
                                        class="shrink-0 text-center text-xs font-medium ring-1 rounded-4xl w-[18%] py-0.5 {statBadgeClass}"
                                    >
                                        {statValue(card)}
                                    </span>
                                {/if}
                            </button>
                        {/each}
                    </div>
                {:else}
                    <p class="mt-4 text-sm text-muted-foreground">
                        No songs match these filters.
                    </p>
                {/if}

                {#if tourShows.length}
                    <button
                        type="button"
                        class="mt-4 text-sm text-primary underline decoration-primary/30 underline-offset-4"
                        onclick={() => (showFullSetlists = !showFullSetlists)}
                    >
                        {showFullSetlists ? 'Hide' : 'Show'} setlists for {singleTour
                            ? 'tour'
                            : 'selection'}
                    </button>

                    {#if showFullSetlists}
                        <div
                            class="mt-4 max-w-2xl flex flex-col space-y-5 border-t border-white pt-5"
                        >
                            {#if livePoll.inShowWindow}
                                <div
                                    class="flex items-center gap-2 text-sm text-muted-foreground"
                                    aria-live="polite"
                                >
                                    <span class="relative flex size-2">
                                        <span
                                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"
                                        ></span>
                                        <span
                                            class="relative inline-flex size-2 rounded-full bg-green-500"
                                        ></span>
                                    </span>
                                    <span
                                        >Next update: {formatCountdown(
                                            livePoll.secondsRemaining,
                                        )}</span
                                    >
                                </div>
                            {/if}
                            {#each tourShows as rows (rows[0].showid)}
                                <SetlistView
                                    {rows}
                                    awaitingNextSong={livePoll.inShowWindow &&
                                        rows[0].showdate ===
                                            livePoll.activeShowdate}
                                />
                            {/each}
                        </div>
                    {/if}
                {/if}
            {:else}
                <p class="text-sm text-muted-foreground">
                    No tour data available.
                </p>
            {/if}
        </div>
    {/if}
</div>

<SongHistoryDialog
    bind:open={dialogOpen}
    slug={dialogSlug}
    catalogEntry={dialogCatalogEntry}
    tourId={singleTour?.tourid ?? null}
    tourName={isEverything ? null : (singleTour?.tourname ?? 'this selection')}
    tourPerformances={dialogPerformances}
/>
