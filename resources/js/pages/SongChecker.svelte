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
        songs as songsRoute
    } from '@/actions/App/Http/Controllers/AppController';
    import AppHead from '@/components/AppHead.svelte';
    import GuestAppearancesToggle from '@/components/GuestAppearancesToggle.svelte';
    import RangeSlider, { clampRange } from '@/components/RangeSlider.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { dayFromIsoDate, isoDateFromDay } from '@/lib/day';
    import { guestAppearances as guestAppearanceSettings } from '@/lib/guest-appearances.svelte';
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

    /** Played at some point during the show being treated as current. */
    const PLAYED_TONIGHT_CLASSES =
        'bg-green-500/10 text-green-700 dark:text-green-400';

    /** On stage right now — replaced by the green above once the show ends. */
    const LATEST_SONG_CLASSES =
        'bg-amber-500/15 font-medium text-amber-700 ring-1 ring-amber-500/40 dark:text-amber-300';

    /** Guest-only years and tours are muted, as on the setlist browser. */
    const badgeClasses = (isSelected: boolean, isGuest = false): string =>
        `${BADGE_CLASSES} ${
            isSelected
                ? 'bg-primary text-primary-foreground'
                : isGuest
                  ? 'bg-secondary text-muted-foreground'
                  : 'bg-secondary text-secondary-foreground'
        }`;

    type ViewMode = 'played' | 'not-played' | 'all';

    type Tour = {
        tourid: number;
        tourname: string;
        tourwhen: string;
        year: number;
        /** Holds nothing but guest appearances. */
        guestOnly: boolean;
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
        { value: 'both', label: 'Both' }
    ];

    type StoredPrefs = {
        /** Empty means every year — the whole catalog. */
        years: number[];
        /** `year:tourid` keys; empty for a year means every tour in it. */
        tours: string[];
        /** `null` (or 0, in older cookies) means that end of the slider is open. */
        minTimesPlayed: number | null;
        maxTimesPlayed: number | null;
        minGap: number | null;
        maxGap: number | null;
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
        songSource: 'both' as SongSource
    };

    const PREFS_COOKIE_NAME = 'tour-explorer-prefs';

    const savedPrefs = readPrefsCookie<StoredPrefs & LegacyPrefs>(
        PREFS_COOKIE_NAME
    );

    const scrollMemory = createScrollMemory(toPath(page.url));

    /** This page's own guest-appearances checkbox, apart from the other pages'. */
    const guestAppearances = guestAppearanceSettings.songChecker;

    let {
        excludedSongs = [],
        clientSyncActiveInterval = 60
    }: {
        excludedSongs?: string[];
        clientSyncActiveInterval?: number;
    } = $props();

    let years = $state<number[]>([]);
    let yearsLoaded = $state(false);
    /** Each year's show counts, for the guest-only flag and the every-year total. */
    let yearTotals = $state<ShowYear[]>([]);
    let initialLoading = $state(true);
    let showFullSetlists = $state(
        typeof savedPrefs?.showFullSetlists === 'boolean'
            ? savedPrefs.showFullSetlists
            : false
    );
    let filtersOpen = $state(
        typeof savedPrefs?.filtersOpen === 'boolean'
            ? savedPrefs.filtersOpen
            : true
    );
    let viewMode = $state<ViewMode>(
        savedPrefs?.viewMode === 'played' ||
        savedPrefs?.viewMode === 'all' ||
        savedPrefs?.viewMode === 'not-played'
            ? savedPrefs.viewMode
            : DEFAULT_FILTERS.viewMode
    );

    type StatShown = 'gap' | 'play-count' | 'tour-plays' | 'debut-year' | null;
    const STAT_SHOWN_VALUES: StatShown[] = [
        'gap',
        'play-count',
        'tour-plays',
        'debut-year',
        null
    ];
    let statShown = $state<StatShown>(
        STAT_SHOWN_VALUES.includes(savedPrefs?.statShown ?? null)
            ? (savedPrefs?.statShown ?? null)
            : null
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

    const savedHandle = (value: unknown): number | null =>
        typeof value === 'number' && value > 0 ? value : null;

    /**
     * Where the play-count and gap handles have been dragged to. `null` means
     * the handle rests at its end of the range — no filter.
     */
    let timesPlayedFrom = $state(savedHandle(savedPrefs?.minTimesPlayed));
    let timesPlayedTo = $state(savedHandle(savedPrefs?.maxTimesPlayed));
    let gapFrom = $state(savedHandle(savedPrefs?.minGap));
    let gapTo = $state(savedHandle(savedPrefs?.maxGap));

    const savedSongSource = (): SongSource => {
        if (
            SONG_SOURCE_OPTIONS.some(
                (option) => option.value === savedPrefs?.songSource
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
        savedDebutDay(savedPrefs?.debutFrom)
    );
    let debutToDay = $state<number | null>(savedDebutDay(savedPrefs?.debutTo));

    const yearData = new SvelteMap<number, SetlistRow[]>();

    const yearsHttp = useHttp<Record<string, never>, { data: ShowYear[] }>({});
    const setlistsHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {}
    );
    const songsHttp = useHttp<Record<string, never>, { data: Song[] }>({});
    const searchSlugsHttp = useHttp<Record<string, never>, { data: string[] }>(
        {}
    );
    const refreshHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {}
    );
    // Shared poll loop: refetch the live year whenever its version hash moves,
    // and expose the show-window flag + countdown the setlists section renders.
    const livePoll = createLivePoll({
        activeInterval: clientSyncActiveInterval,
        onStale: (status) => {
            if (status.year !== null) {
                refreshLoadedYear(status.year);
            }
        }
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
            }
        });
    }

    /** No years picked: the catalog stands in for every show ever played. */
    const isEverything = $derived(selectedYears.size === 0);

    /**
     * Played / Not Played only mean something against a set of shows, so with
     * no years picked the page lists all songs. The picked mode is kept, and
     * comes back as soon as a year is.
     */
    const effectiveViewMode = $derived<ViewMode>(
        isEverything ? 'all' : viewMode
    );

    const sortedSelectedYears = $derived(
        [...selectedYears].sort((a, b) => a - b)
    );

    /** Every selected year's setlists are in, so the lists can be built. */
    const scopeLoading = $derived(
        sortedSelectedYears.some((year) => !yearData.has(year))
    );

    /** Whether a setlist row's show is in play under the guest checkbox. */
    const includesRow = (row: SetlistRow): boolean =>
        guestAppearances.shown || row.artistid === 1;

    const guestOnlyYears = $derived(
        new SvelteSet(
            yearTotals
                .filter((year) => !year.has_phish_show)
                .map((year) => Number(year.showyear))
        )
    );

    /**
     * A guest-only year goes with the guest appearances, unless it is picked —
     * that stays put so it can still be unpicked.
     */
    const visibleYears = $derived(
        years.filter(
            (year) =>
                guestAppearances.shown ||
                !guestOnlyYears.has(year) ||
                selectedYears.has(year)
        )
    );

    /** The tour chips on offer: every tour in the selected years. */
    const availableTours = $derived(
        sortedSelectedYears.flatMap((year) => buildToursForYear(year))
    );

    /**
     * The tours actually in play: just the picked ones once any are, across
     * every selected year, or every tour in those years when none are.
     */
    const scopeTours = $derived.by(() => {
        const picked = availableTours.filter((tour) =>
            selectedTourKeys.has(tourKey(tour))
        );

        return picked.length ? picked : availableTours;
    });

    /** The one tour in play, when there is exactly one — Previous/Next step from it. */
    const singleTour = $derived<Tour | null>(
        scopeTours.length === 1 ? scopeTours[0] : null
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
                (row) => includesRow(row) && ids?.has(row.tourid)
            );
        });
    });

    const countedRows = $derived(
        tourRows.filter((row) => !excludedSet.has(row.slug))
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
            : tourRows.filter((row) => row.showdate === liveShowdate)
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
        new SvelteMap(liveShowRows.map((row) => [row.slug, row.gap]))
    );

    /**
     * The song on stage, which is the newest entry of the show being played.
     *
     * Only while the show is actually on: once it ends, this song stops being
     * the exception and joins the rest of the night in green, which stays put
     * until the grace period closes the next afternoon.
     */
    const latestSongSlug = $derived(
        livePoll.inShowWindow ? (liveShowRows.at(-1)?.slug ?? null) : null
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
            b[0].showdate.localeCompare(a[0].showdate)
        );
    });

    const SETLISTS_PER_PAGE = 20;

    let setlistPage = $state(1);
    let setlistsSection = $state<HTMLElement | null>(null);

    const setlistPageCount = $derived(
        Math.max(1, Math.ceil(tourShows.length / SETLISTS_PER_PAGE))
    );

    /**
     * Clamped rather than trusted, so a refresh that drops a show (or a
     * narrower selection) can never leave the list on a page past its end.
     */
    const currentSetlistPage = $derived(
        Math.min(setlistPage, setlistPageCount)
    );

    const pagedShows = $derived(
        tourShows.slice(
            (currentSetlistPage - 1) * SETLISTS_PER_PAGE,
            currentSetlistPage * SETLISTS_PER_PAGE
        )
    );

    /**
     * Back to the first page whenever the tours in play change. Keyed on the
     * tours rather than the shows, so a new show landing mid-browse leaves
     * the page where it is.
     */
    const scopeTourKeys = $derived(scopeTours.map(tourKey).join(','));

    $effect(() => {
        void scopeTourKeys;
        setlistPage = 1;
    });

    function goToSetlistPage(pageNumber: number) {
        setlistPage = pageNumber;
        setlistsSection?.scrollIntoView({ block: 'start' });
    }

    const songCounts = $derived.by<SongCount[]>(() => {
        // Every year would mean fetching every setlist ever, but the catalog
        // already holds each song's all-time count and first/last dates.
        if (isEverything) {
            return (allSongs ?? [])
                .filter(
                    (song) =>
                        song.times_played > 0 && !excludedSet.has(song.slug)
                )
                .map((song) => ({
                    song: song.song,
                    slug: song.slug,
                    count: song.times_played,
                    first: song.debut,
                    last: song.last_played
                }))
                .sort(
                    (a, b) => b.count - a.count || a.song.localeCompare(b.song)
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
                    last: row.showdate
                });
            }
        }

        return [...counts.values()].sort(
            (a, b) => b.count - a.count || a.song.localeCompare(b.song)
        );
    });

    /** Catalog entry by slug, so played rows can show all-time play count / gap. */
    const catalogBySlug = $derived(
        new SvelteMap((allSongs ?? []).map((song) => [song.slug, song]))
    );

    /** The play-count and gap sliders move in fives, so their tops do too. */
    const COUNT_SLIDER_STEP = 5;

    const roundUpToStep = (value: number): number =>
        Math.ceil(value / COUNT_SLIDER_STEP) * COUNT_SLIDER_STEP;

    /** Highest gap among the songs the source control lets through, capping the slider. */
    const maxGap = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return roundUpToStep(
            allSongs.reduce(
                (highest, song) =>
                    matchesSongSource(song.artist) && song.gap > highest
                        ? song.gap
                        : highest,
                0
            )
        );
    });

    /** Highest play count among the songs the source control lets through. */
    const maxTimesPlayed = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return roundUpToStep(
            allSongs.reduce(
                (highest, song) =>
                    matchesSongSource(song.artist) &&
                    song.times_played > highest
                        ? song.times_played
                        : highest,
                0
            )
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

    const debutRange = $derived(
        debutBounds === null
            ? null
            : clampRange(
                debutFromDay,
                debutToDay,
                debutBounds.min,
                debutBounds.max
            )
    );

    const debutFromDate = $derived(
        debutRange === null || debutFromDay === null
            ? null
            : isoDateFromDay(debutRange.from)
    );
    const debutToDate = $derived(
        debutRange === null || debutToDay === null
            ? null
            : isoDateFromDay(debutRange.to)
    );

    const timesPlayedRange = $derived(
        clampRange(timesPlayedFrom, timesPlayedTo, 0, maxTimesPlayed)
    );
    const gapRange = $derived(clampRange(gapFrom, gapTo, 0, maxGap));

    /** Whether a value passes a slider, whose `null` handles leave that side open. */
    const withinRange = (
        value: number,
        range: { from: number; to: number },
        from: number | null,
        to: number | null
    ): boolean =>
        (from === null || value >= range.from) &&
        (to === null || value <= range.to);

    const passesTimesPlayed = (timesPlayed: number): boolean =>
        withinRange(
            timesPlayed,
            timesPlayedRange,
            timesPlayedFrom,
            timesPlayedTo
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
                }
            });
        }, SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timer);
    });

    /**
     * The played list honours the play-count and debut-range sliders too, via
     * each song's catalog entry. A song the catalog has not loaded (or does not
     * know) is let through rather than hidden on missing data.
     *
     * A search narrows within the filters rather than setting them aside.
     */
    const playedAlphabetical = $derived(
        songCounts
            .filter((row) => searchSlugs === null || searchSlugs.has(row.slug))
            .filter((row) => {
                const catalogEntry = catalogBySlug.get(row.slug);

                if (!catalogEntry) {
                    return true;
                }

                return (
                    matchesSongSource(catalogEntry.artist) &&
                    passesTimesPlayed(catalogEntry.times_played) &&
                    (debutFromDate === null ||
                        catalogEntry.debut >= debutFromDate) &&
                    (debutToDate === null || catalogEntry.debut <= debutToDate)
                );
            })
            .sort((a, b) => a.song.localeCompare(b.song))
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
                    .map((row) => row.slug)
        );

        return allSongs
            .filter(
                (song) =>
                    matchesSongSource(song.artist) &&
                    passesTimesPlayed(song.times_played) &&
                    withinRange(song.gap, gapRange, gapFrom, gapTo) &&
                    (debutFromDate === null || song.debut >= debutFromDate) &&
                    (debutToDate === null || song.debut <= debutToDate) &&
                    !playedSlugs.has(song.slug) &&
                    !excludedSet.has(song.slug) &&
                    (searchSlugs === null || searchSlugs.has(song.slug))
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
                    passesTimesPlayed(song.times_played) &&
                    (debutFromDate === null || song.debut >= debutFromDate) &&
                    (debutToDate === null || song.debut <= debutToDate) &&
                    !excludedSet.has(song.slug) &&
                    (searchSlugs === null || searchSlugs.has(song.slug))
            )
            .sort((a, b) => a.song.localeCompare(b.song));
    });

    const tourCountBySlug = $derived(
        new Map(songCounts.map((row) => [row.slug, row.count]))
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
        if (effectiveViewMode === 'played') {
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
                    muted: false
                };
            });
        }

        return (effectiveViewMode === 'all' ? allSorted : notPlayed).map(
            (song) => ({
                slug: song.slug,
                song: song.song,
                tourPlays: tourCountBySlug.get(song.slug) ?? 0,
                totalPlays: song.times_played,
                gap: liveGapBySlug.get(song.slug) ?? song.gap,
                debutYear: song.debut.slice(0, 4),
                muted:
                    effectiveViewMode === 'not-played' ||
                    !tourCountBySlug.has(song.slug)
            })
        );
    });

    const VIEW_MODE_OPTIONS: { value: ViewMode; label: string }[] = [
        { value: 'played', label: 'Played' },
        { value: 'not-played', label: 'Not Played' },
        { value: 'all', label: 'All' }
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
            badgeClass: 'ring-sky-500/30 text-sky-400/60'
        },
        {
            value: 'play-count',
            label: 'Total Plays',
            activeClass: 'bg-fuchsia-700 text-fuchsia-200',
            badgeClass: 'ring-fuchsia-500/30 text-fuchsia-500/60'
        },
        {
            value: 'gap',
            label: 'Gap',
            activeClass: 'bg-green-700 text-green-100',
            badgeClass: 'ring-green-800/30 text-green-400/60'
        },
        {
            value: 'debut-year',
            label: 'Debut Year',
            activeClass: 'bg-slate-700 text-slate-100',
            badgeClass: 'ring-slate-500/30'
        },
        {
            value: null,
            label: 'None',
            activeClass: 'bg-primary text-primary-foreground',
            badgeClass: ''
        }
    ];

    /**
     * "Tour Plays" means nothing for songs the selected shows never played, nor
     * with no years picked, where it would only repeat Total Plays.
     */
    const hidesTourPlays = $derived(
        isEverything || effectiveViewMode === 'not-played'
    );

    const statOptions = $derived(
        STAT_OPTIONS.filter(
            (option) => !hidesTourPlays || option.value !== 'tour-plays'
        )
    );

    const statBadgeClass = $derived(
        STAT_OPTIONS.find((option) => option.value === statShown)?.badgeClass ??
        ''
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
            effectiveViewMode === 'played'
                ? ' played'
                : effectiveViewMode === 'not-played'
                    ? ' not played'
                    : '';

        return `${count} ${kind}${count !== 1 ? 's' : ''}${suffix}`;
    });

    /** Shows across every year, guest appearances counted only while shown. */
    const everyYearShowCount = $derived(
        yearTotals.reduce(
            (total, year) =>
                total +
                (guestAppearances.shown
                    ? year.show_count
                    : year.phish_show_count),
            0
        )
    );

    const everyYearShowCountLabel = $derived(
        `${everyYearShowCount.toLocaleString()} show${everyYearShowCount !== 1 ? 's' : ''}`
    );

    const showCountLabel = $derived(
        `${tourShows.length} show${tourShows.length !== 1 ? 's' : ''}`
    );

    const pickedTourCount = $derived(
        availableTours.filter((tour) => selectedTourKeys.has(tourKey(tour)))
            .length
    );

    /** "30 Shows (3 years selected)", or "8 Shows (2 tours selected from 3 years)". */
    const showsHeading = $derived.by(() => {
        const shows = `${tourShows.length} Show${tourShows.length !== 1 ? 's' : ''}`;
        const years = `${selectedYears.size} year${selectedYears.size !== 1 ? 's' : ''}`;

        if (!pickedTourCount) {
            return `${shows} (${years} selected)`;
        }

        const tours = `${pickedTourCount} tour${pickedTourCount !== 1 ? 's' : ''}`;

        return `${shows} (${tours} selected from ${years})`;
    });

    const dialogCatalogEntry = $derived(
        allSongs?.find((song) => song.slug === dialogSlug) ?? null
    );

    /** Newest first, the way the dialog lists every other performance. */
    /**
     * The song dialog only calls out a tour's performances when exactly one is
     * picked; otherwise every performance sits in the plain history list.
     */
    const highlightedTour = $derived(pickedTourCount === 1 ? singleTour : null);

    const dialogPerformances = $derived(
        [...tourRows]
            .filter((row) => row.slug === dialogSlug)
            .sort((a, b) => b.showdate.localeCompare(a.showdate))
    );

    /**
     * `includeGuests` follows the checkbox unless told otherwise: the default
     * selection always lands on a Phish tour, and unpicking a year has to clear
     * all of its tour keys whichever way the checkbox is set.
     */
    function buildToursForYear(
        year: number,
        includeGuests = guestAppearances.shown
    ): Tour[] {
        const rows = yearData.get(year) ?? [];

        const sorted = [...rows]
            .filter((row) => includeGuests || row.artistid === 1)
            .sort((a, b) => a.showdate.localeCompare(b.showdate));

        const tours: Tour[] = [];
        const seen = new SvelteMap<number, Tour>();

        for (const row of sorted) {
            const existing = seen.get(row.tourid);

            if (existing) {
                existing.guestOnly &&= row.artistid !== 1;

                continue;
            }

            const tour: Tour = {
                tourid: row.tourid,
                tourname: row.tourname,
                tourwhen: row.tourwhen,
                year,
                guestOnly: row.artistid !== 1
            };

            seen.set(row.tourid, tour);
            tours.push(tour);
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
            onNetworkError: fail
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
            const tours = buildToursForYear(year, false);

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
        for (const tour of buildToursForYear(year, true)) {
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
        timesPlayedFrom = null;
        timesPlayedTo = null;
        gapFrom = null;
        gapTo = null;
        debutFromDay = null;
        debutToDay = null;
        searchState.query = '';

        if (years.length) {
            selectLatestTour(years[years.length - 1]);
        }
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
                (key): key is string => typeof key === 'string'
            )
            : (savedYears ?? []).flatMap((year) =>
                legacyTourIds.map((tourid) => tourKey({ year, tourid }))
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

                yearTotals = response.data.filter(
                    (show) => Number(show.showyear) <= thisYear
                );

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
            }
        });

        allSongsLoading = true;

        songsHttp.get(songsRoute.url(), {
            onSuccess: (response) => {
                allSongs = response.data;
                allSongsLoading = false;
            }
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

    // Fall back to Total Plays when "Tour Plays" is taken off the options,
    // rather than leaving a hidden option selected.
    $effect(() => {
        if (hidesTourPlays && statShown === 'tour-plays') {
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
            minTimesPlayed: timesPlayedFrom,
            maxTimesPlayed: timesPlayedTo,
            minGap: gapFrom,
            maxGap: gapTo,
            debutFrom: debutFromDate,
            debutTo: debutToDate,
            statShown,
            viewMode,
            songSource,
            filtersOpen,
            showFullSetlists
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
    disabled = false,
)}
    <button
        type="button"
        role="radio"
        aria-checked={isActive}
        {disabled}
        {onclick}
        class={[
            'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors disabled:cursor-not-allowed md:flex-none md:px-3 md:py-1 md:text-xs',
            isActive
                ? activeClass
                : 'text-muted-foreground enabled:hover:text-foreground',
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
            <div
                class="flex flex-col sm:flex-row w-full justify-between sm:justify-end gap-2"
            >
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

                    <GuestAppearancesToggle setting={guestAppearances} count={null} />

                    <div class="flex flex-col gap-2">
                        {@render fieldLabel(
                            'Years',
                            selectedYears.size
                                ? `${selectedYears.size} selected`
                                : 'None selected = every year',
                        )}
                        <div class="flex flex-wrap gap-1.5">
                            {#each visibleYears as year (year)}
                                <button
                                    type="button"
                                    onclick={() => toggleYear(year)}
                                    aria-pressed={selectedYears.has(year)}
                                    class={badgeClasses(
                                        selectedYears.has(year),
                                        guestOnlyYears.has(year),
                                    )}
                                    title={guestOnlyYears.has(year)
                                        ? 'Guest appearances only'
                                        : undefined}
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
                                            tour.guestOnly,
                                        )}
                                        title={tour.guestOnly
                                            ? 'Guest appearances only'
                                            : undefined}
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
                        {@render fieldLabel(
                            'Show songs that were',
                            isEverything ? 'Pick a year to choose' : '',
                        )}
                        <div
                            role="radiogroup"
                            aria-label="Played status"
                            aria-disabled={isEverything}
                            class={[
                                SEGMENTED_CLASSES,
                                isEverything && 'opacity-50',
                            ]}
                        >
                            {#each VIEW_MODE_OPTIONS as option (option.value)}
                                {@render segmentButton(
                                    option.label,
                                    effectiveViewMode === option.value,
                                    () => (viewMode = option.value),
                                    undefined,
                                    isEverything,
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
                                'Played',
                                `${timesPlayedRange.from} – ${timesPlayedRange.to} times`,
                                'min-times-played',
                            )}
                            <RangeSlider
                                min={0}
                                max={maxTimesPlayed}
                                step={COUNT_SLIDER_STEP}
                                bind:from={timesPlayedFrom}
                                bind:to={timesPlayedTo}
                                fromId="min-times-played"
                                fromLabel="Fewest times played"
                                toLabel="Most times played"
                            />
                        </div>

                        <!-- A gap only means something for songs still waiting to be played. -->
                        {#if effectiveViewMode === 'not-played'}
                            <div class="flex flex-col gap-1">
                                {@render fieldLabel(
                                    'Gap',
                                    `${gapRange.from} – ${gapRange.to} shows`,
                                    'min-gap',
                                )}
                                <RangeSlider
                                    min={0}
                                    max={maxGap}
                                    step={COUNT_SLIDER_STEP}
                                    bind:from={gapFrom}
                                    bind:to={gapTo}
                                    fromId="min-gap"
                                    fromLabel="Shortest gap"
                                    toLabel="Longest gap"
                                />
                            </div>
                        {/if}

                        {#if debutBounds && debutRange}
                            <div class="flex flex-col gap-1">
                                {@render fieldLabel(
                                    'Debuted between',
                                    `${isoDateFromDay(debutRange.from)} – ${isoDateFromDay(debutRange.to)}`,
                                )}
                                <RangeSlider
                                    min={debutBounds.min}
                                    max={debutBounds.max}
                                    bind:from={debutFromDay}
                                    bind:to={debutToDay}
                                    fromLabel="Earliest debut date"
                                    toLabel="Latest debut date"
                                />
                            </div>
                        {/if}
                    {/if}
                </section>
            </div>
        {/if}

        <div class="max-w-5xl">
            <!-- Results: what is being looked at, how many songs, and the number on each card. -->
            <div
                class="flex flex-col gap-3 border-b pb-3 md:flex-row md:items-end md:justify-between"
            >
                <div>
                    <h2 class="font-serif text-xl font-medium">
                        {#if singleTour}
                            {singleTour.tourname}
                        {:else if isEverything}
                            Every year, {everyYearShowCountLabel}
                        {:else}
                            {showsHeading}
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
        </div>

        {#if tourShows.length}
            <section
                bind:this={setlistsSection}
                class="flex max-w-2xl scroll-mt-20 flex-col gap-4 border-t pt-4"
            >
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-serif text-xl font-medium">Setlists</h2>
                    <span class="text-sm text-muted-foreground">
                        {showCountLabel}
                    </span>
                </div>

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

                {@render setlistPager()}

                <div>
                    {#each pagedShows as rows (rows[0].showid)}
                        <SetlistView
                            {rows}
                            awaitingNextSong={livePoll.inShowWindow &&
                                rows[0].showdate === livePoll.activeShowdate}
                            onSongClick={(row) => openSongDialog(row.slug)}
                        />
                    {/each}
                </div>

                {@render setlistPager()}
            </section>
        {/if}
    {/if}
</div>

{#snippet setlistPager()}
    {#if setlistPageCount > 1}
        <nav
            aria-label="Setlist pages"
            class="flex items-center justify-between gap-2"
        >
            <button
                type="button"
                disabled={currentSetlistPage === 1}
                onclick={() => goToSetlistPage(currentSetlistPage - 1)}
                class={OUTLINE_BUTTON_CLASSES}
            >
                Previous
            </button>
            <span class="text-sm text-muted-foreground">
                Page {currentSetlistPage} of {setlistPageCount}
            </span>
            <button
                type="button"
                disabled={currentSetlistPage === setlistPageCount}
                onclick={() => goToSetlistPage(currentSetlistPage + 1)}
                class={OUTLINE_BUTTON_CLASSES}
            >
                Next
            </button>
        </nav>
    {/if}
{/snippet}

<SongHistoryDialog
    bind:open={dialogOpen}
    slug={dialogSlug}
    catalogEntry={dialogCatalogEntry}
    tourId={highlightedTour?.tourid ?? null}
    tourName={highlightedTour?.tourname ?? null}
    tourPerformances={dialogPerformances}
/>
