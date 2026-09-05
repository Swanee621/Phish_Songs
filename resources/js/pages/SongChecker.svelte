<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import Check from 'lucide-svelte/icons/check';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import { onMount } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { slide } from 'svelte/transition';
    import {
        setlistsForYear,
        showYears,
        songs as songsRoute,
    } from '@/actions/App/Http/Controllers/AppController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';
    import type { SetlistRow, ShowYear, Song } from '@/types/phishnet';

    const BADGE_CLASSES =
        'inline-flex w-fit shrink-0 cursor-pointer items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-[color,box-shadow]';

    const OUTLINE_BUTTON_CLASSES =
        'inline-flex h-10 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 md:h-8 md:px-3 md:text-xs';

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

    type ViewMode = 'played' | 'not-played';

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

    type StoredPrefs = {
        year: number;
        tourid: number;
        minTimesPlayed: number;
        minGap: number;
        debutFrom: string | null;
        debutTo: string | null;
        statShown: StatShown;
        viewMode: ViewMode;
        onlyPhishSongs: boolean;
        filtersOpen: boolean;
        showFullSetlists: boolean;
    };

    const PREFS_COOKIE_NAME = 'tour-explorer-prefs';

    const savedPrefs = readPrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME);

    let {
        excludedSongs = [],
        defaultMinPlayed = 10,
        clientSyncActiveInterval = 60,
    }: {
        excludedSongs?: string[];
        defaultMinPlayed?: number;
        clientSyncActiveInterval?: number;
    } = $props();

    let years = $state<number[]>([]);
    let yearsLoaded = $state(false);
    let initialLoading = $state(true);
    let loadingYear = $state(false);
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
        savedPrefs?.viewMode === 'played' ? 'played' : 'not-played',
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

    let currentYear = $state<number | null>(null);
    let currentTours = $state<Tour[]>([]);
    let tourIndex = $state(0);

    let allSongs = $state<Song[] | null>(null);
    let allSongsLoading = $state(false);

    let dialogOpen = $state(false);
    let dialogSlug = $state<string | null>(null);

    let minTimesPlayed = $state(
        typeof savedPrefs?.minTimesPlayed === 'number'
            ? savedPrefs.minTimesPlayed
            : defaultMinPlayed,
    );
    let minGap = $state(
        typeof savedPrefs?.minGap === 'number' ? savedPrefs.minGap : 0,
    );
    let onlyPhishSongs = $state(
        typeof savedPrefs?.onlyPhishSongs === 'boolean'
            ? savedPrefs.onlyPhishSongs
            : true,
    );

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

        refreshHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearData.set(year, response.data);

                // Rebuild the tour list in case a new show or tour just landed,
                // preserving whichever tour is currently selected.
                if (currentYear === year && selectedTour) {
                    const preservedTourId = selectedTour.tourid;
                    currentTours = buildToursForYear(year);

                    const index = currentTours.findIndex(
                        (tour) => tour.tourid === preservedTourId,
                    );

                    if (index !== -1) {
                        tourIndex = index;
                    }
                }
            },
        });
    }

    const selectedTour = $derived<Tour | null>(currentTours[tourIndex] ?? null);

    const excludedSet = $derived(new SvelteSet(excludedSongs));

    const tourRows = $derived.by(() => {
        if (!selectedTour) {
            return [];
        }

        return (yearData.get(selectedTour.year) ?? []).filter(
            (row) => row.artistid === 1 && row.tourid === selectedTour.tourid,
        );
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

    /** Highest gap in the (optionally Phish-only) catalog, capping the slider. */
    const maxGap = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return allSongs.reduce(
            (highest, song) =>
                (!onlyPhishSongs || song.artist === 'Phish') &&
                song.gap > highest
                    ? song.gap
                    : highest,
            0,
        );
    });

    /** Highest play count in the (optionally Phish-only) catalog. */
    const maxTimesPlayed = $derived.by(() => {
        if (!allSongs) {
            return 0;
        }

        return allSongs.reduce(
            (highest, song) =>
                (!onlyPhishSongs || song.artist === 'Phish') &&
                song.times_played > highest
                    ? song.times_played
                    : highest,
            0,
        );
    });

    /** Oldest and newest debut dates in the (optionally Phish-only) catalog. */
    const debutBounds = $derived.by(() => {
        if (!allSongs) {
            return null;
        }

        let min = Infinity;
        let max = -Infinity;

        for (const song of allSongs) {
            if ((onlyPhishSongs && song.artist !== 'Phish') || !song.debut) {
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

    /**
     * The played list honours the play-count and debut-range sliders too, via
     * each song's catalog entry. A song the catalog has not loaded (or does not
     * know) is let through rather than hidden on missing data.
     */
    const playedAlphabetical = $derived(
        songCounts
            .filter((row) => {
                const catalogEntry = catalogBySlug.get(row.slug);

                if (!catalogEntry) {
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
            countedRows
                .filter((row) => row.showdate !== liveShowdate)
                .map((row) => row.slug),
        );

        return allSongs
            .filter(
                (song) =>
                    (!onlyPhishSongs || song.artist === 'Phish') &&
                    song.times_played >= minTimesPlayed &&
                    (minGap === 0 || song.gap >= minGap) &&
                    (debutFromDate === null || song.debut >= debutFromDate) &&
                    (debutToDate === null || song.debut <= debutToDate) &&
                    !playedSlugs.has(song.slug) &&
                    !excludedSet.has(song.slug),
            )
            .sort((a, b) => a.song.localeCompare(b.song));
    });

    const dialogCatalogEntry = $derived(
        allSongs?.find((song) => song.slug === dialogSlug) ?? null,
    );

    /** Newest first, the way the dialog lists every other performance. */
    const dialogPerformances = $derived(
        [...tourRows]
            .filter((row) => row.slug === dialogSlug)
            .sort((a, b) => b.showdate.localeCompare(a.showdate)),
    );

    const prevDisabled = $derived(
        loadingYear ||
            (tourIndex === 0 &&
                (currentYear === null || years.indexOf(currentYear) === 0)),
    );
    const nextDisabled = $derived(
        loadingYear ||
            (tourIndex === currentTours.length - 1 &&
                (currentYear === null ||
                    years.indexOf(currentYear) === years.length - 1)),
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

    function loadYear(year: number, onLoaded: () => void) {
        if (yearData.has(year)) {
            onLoaded();

            return;
        }

        loadingYear = true;

        setlistsHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearData.set(year, response.data);
                loadingYear = false;
                onLoaded();
            },
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

    function selectYear(
        year: number,
        which: 'first' | 'last',
        allowFallback = false,
        preferredTourId?: number,
    ) {
        loadYear(year, () => {
            const tours = buildToursForYear(year);

            if (!tours.length && allowFallback) {
                const yearPos = years.indexOf(year);
                const fallbackPos =
                    which === 'last' ? yearPos - 1 : yearPos + 1;

                if (fallbackPos >= 0 && fallbackPos < years.length) {
                    selectYear(
                        years[fallbackPos],
                        which,
                        true,
                        preferredTourId,
                    );
                }

                return;
            }

            const preferredIndex =
                preferredTourId !== undefined
                    ? tours.findIndex((tour) => tour.tourid === preferredTourId)
                    : -1;

            currentYear = year;
            currentTours = tours;
            tourIndex =
                preferredIndex !== -1
                    ? preferredIndex
                    : which === 'first'
                      ? 0
                      : mostRecentTourIndex(tours, yearData.get(year) ?? []);
        });
    }

    function selectTourIndex(index: number) {
        tourIndex = index;
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

    function cycleTour(direction: 1 | -1) {
        const newIndex = tourIndex + direction;

        if (newIndex >= 0 && newIndex < currentTours.length) {
            tourIndex = newIndex;

            return;
        }

        if (currentYear === null) {
            return;
        }

        const yearPos = years.indexOf(currentYear);
        const newYearPos = yearPos + direction;

        if (newYearPos < 0 || newYearPos >= years.length) {
            return;
        }

        selectYear(years[newYearPos], direction === 1 ? 'first' : 'last', true);
    }

    onMount(() => {
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

                if (years.length) {
                    const preferredYear = savedPrefs?.year;
                    const yearToSelect =
                        typeof preferredYear === 'number' &&
                        years.includes(preferredYear)
                            ? preferredYear
                            : years[years.length - 1];

                    selectYear(
                        yearToSelect,
                        'last',
                        true,
                        typeof savedPrefs?.tourid === 'number'
                            ? savedPrefs.tourid
                            : undefined,
                    );
                }

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

        return () => livePoll.stop();
    });

    // "Tour Plays" only exists for the played list, so fall back to Gap when the
    // user switches to the not-played view rather than leaving it selected.
    $effect(() => {
        if (viewMode !== 'played' && statShown === 'tour-plays') {
            statShown = 'play-count';
        }
    });

    $effect(() => {
        if (currentYear === null || !selectedTour) {
            return;
        }

        writePrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME, {
            year: currentYear,
            tourid: selectedTour.tourid,
            minTimesPlayed,
            minGap,
            debutFrom: debutFromDate,
            debutTo: debutToDate,
            statShown,
            viewMode,
            onlyPhishSongs,
            filtersOpen,
            showFullSetlists,
        });
    });
</script>

<AppHead />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div class="flex max-w-5xl items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Song Checker</h1>

        {#if !initialLoading && yearsLoaded}
            <button
                type="button"
                onclick={() => (filtersOpen = !filtersOpen)}
                aria-expanded={filtersOpen}
                aria-controls="song-checker-filters"
                aria-label={filtersOpen ? 'Hide filters' : 'Show filters'}
                class="inline-flex h-10 w-10 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:h-9 md:w-9"
            >
                <ChevronDown
                    class="size-4 transition-transform duration-200 {filtersOpen
                        ? 'rotate-180'
                        : ''}"
                />
            </button>
        {/if}
    </div>

    {#if initialLoading || !yearsLoaded}
        <p class="text-sm text-muted-foreground">Loading…</p>
    {:else}
        {#if filtersOpen}
            <div
                id="song-checker-filters"
                class="flex flex-col gap-4"
                transition:slide={{ duration: 200 }}
            >
                <div>
                    <h2
                        class="mb-2 text-sm font-semibold text-muted-foreground"
                    >
                        Browse by year
                    </h2>
                    <div class="flex flex-wrap gap-1.5">
                        {#each years as year (year)}
                            <button
                                type="button"
                                onclick={() => selectYear(year, 'first')}
                                class={badgeClasses(currentYear === year)}
                            >
                                {year}
                            </button>
                        {/each}
                    </div>
                </div>

                {#if currentYear && currentTours.length}
                    <div>
                        <h2
                            class="mb-2 text-sm font-semibold text-muted-foreground"
                        >
                            Tours in {currentYear}
                        </h2>
                        <div class="flex flex-wrap gap-1.5">
                            {#each currentTours as tour, index (tour.tourid)}
                                <button
                                    type="button"
                                    onclick={() => selectTourIndex(index)}
                                    class={badgeClasses(index === tourIndex)}
                                >
                                    {tour.tourname}
                                </button>
                            {/each}
                        </div>
                    </div>
                {/if}
            </div>
        {/if}

        <div class="max-w-5xl">
            {#if loadingYear}
                <p class="text-sm text-muted-foreground">Loading tour…</p>
            {:else if selectedTour}
                <div class="flex flex-col gap-4">
                    <div class="text-center">
                        <h2 class="font-serif text-xl font-medium">
                            {selectedTour.tourname}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {selectedTour.tourwhen}
                        </p>
                    </div>
                    <div class="flex items-start justify-between gap-4">
                        <button
                            type="button"
                            onclick={() => cycleTour(-1)}
                            disabled={prevDisabled}
                            class={OUTLINE_BUTTON_CLASSES}
                        >
                            &larr; Previous tour
                        </button>
                        <button
                            type="button"
                            onclick={() => cycleTour(1)}
                            disabled={nextDisabled}
                            class={OUTLINE_BUTTON_CLASSES}
                        >
                            Next tour &rarr;
                        </button>
                    </div>
                </div>

                <div
                    class="mt-4 flex flex-wrap items-center md:items-start gap-5"
                >
                    <!-- Played/Not Played -->
                    <div
                        class="flex w-full rounded-md border p-0.5 md:inline-flex md:w-auto"
                    >
                        <button
                            type="button"
                            onclick={() => (viewMode = 'played')}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                viewMode === 'played'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Played
                        </button>
                        <button
                            type="button"
                            onclick={() => (viewMode = 'not-played')}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                viewMode === 'not-played'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Not Played
                        </button>
                    </div>

                    <!-- Gap/Play Count -->
                    <div
                        class="flex w-full rounded-md border p-0.5 md:inline-flex md:w-auto"
                    >
                        {#if viewMode === 'played'}
                            <button
                                type="button"
                                onclick={() => (statShown = 'tour-plays')}
                                class={[
                                    'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                    statShown === 'tour-plays'
                                        ? 'bg-sky-700 text-sky-100'
                                        : 'text-muted-foreground hover:text-foreground',
                                ]}
                            >
                                Tour Plays
                            </button>
                        {/if}
                        <button
                            type="button"
                            onclick={() => (statShown = 'play-count')}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                statShown === 'play-count'
                                    ? 'bg-fuchsia-700 text-fuchsia-200'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Total Plays
                        </button>
                        <button
                            type="button"
                            onclick={() => (statShown = 'gap')}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                statShown === 'gap'
                                    ? 'bg-green-700 text-green-100'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Gap
                        </button>
                        <button
                            type="button"
                            onclick={() => (statShown = 'debut-year')}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                statShown === 'debut-year'
                                    ? 'bg-slate-700 text-slate-100'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Debut Year
                        </button>
                        <button
                            type="button"
                            onclick={() => (statShown = null)}
                            class={[
                                'flex-1 rounded px-4 py-2.5 text-sm font-medium transition-colors md:flex-none md:px-3 md:py-1 md:text-xs',
                                statShown === null
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            ]}
                        >
                            Off
                        </button>
                    </div>
                </div>

                {#snippet playCountSlider()}
                    <div class="flex items-center justify-between gap-3">
                        <label
                            for="min-times-played"
                            class="shrink-0 text-sm text-muted-foreground md:text-xs"
                        >
                            All-time Play Count
                        </label>
                        <span
                            class="shrink-0 text-sm font-medium tabular-nums text-muted-foreground md:text-xs"
                        >
                            {minTimesPlayed}+ times
                        </span>
                    </div>

                    <input
                        id="min-times-played"
                        type="range"
                        min="0"
                        max={maxTimesPlayed}
                        step="5"
                        bind:value={minTimesPlayed}
                        class="h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:rounded-full [&::-webkit-slider-runnable-track]:bg-secondary [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:rounded-full [&::-moz-range-track]:bg-secondary [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5"
                    />
                {/snippet}

                {#snippet debutRangeSlider()}
                    {#if debutBounds}
                        <div class="flex items-center justify-between gap-3">
                            <span
                                class="shrink-0 text-sm text-muted-foreground md:text-xs"
                            >
                                Debut Date
                            </span>
                            <span
                                class="shrink-0 text-sm font-medium tabular-nums text-muted-foreground md:text-xs"
                            >
                                {isoDateFromDay(debutFromValue)} &ndash; {isoDateFromDay(
                                    debutToValue,
                                )}
                            </span>
                        </div>

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
                    {/if}
                {/snippet}

                {#if viewMode === 'played'}
                    {#if allSongs}
                        <div class="mt-3 flex flex-col gap-3">
                            {@render playCountSlider()}
                            {@render debutRangeSlider()}
                        </div>
                    {/if}
                    {#if playedAlphabetical.length}
                        <div
                            class="mt-3 pt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                        >
                            {#each playedAlphabetical as row (row.slug)}
                                <button
                                    type="button"
                                    onclick={() => openSongDialog(row.slug)}
                                    class="flex items-baseline cursor-pointer ring-1 ring-slate-500/10 justify-between gap-2 rounded p-3 text-left text-base hover:bg-accent hover:text-primary {liveClasses(
                                        row.slug,
                                    )}"
                                >
                                    <span class="truncate">{row.song}</span>
                                    {#if statShown === 'tour-plays'}
                                        <span
                                            class="shrink-0 text-center text-xs font-medium ring-1 ring-sky-500/30 text-sky-400/60 rounded-4xl w-[18%] py-0.5"
                                        >
                                            {row.count}
                                        </span>
                                    {:else if statShown === 'play-count'}
                                        <span
                                            class="shrink-0 text-center text-xs font-medium ring-1 ring-fuchsia-500/30 text-fuchsia-500/60 rounded-4xl w-[18%] py-0.5"
                                        >
                                            {catalogBySlug.get(row.slug)
                                                ?.times_played ?? '—'}
                                        </span>
                                    {:else if statShown === 'gap'}
                                        <span
                                            class="shrink-0 text-center text-xs font-medium ring-1 ring-green-800/30 text-green-400/60 rounded-4xl w-[18%] py-0.5"
                                        >
                                            {liveGapBySlug.get(row.slug) ??
                                                catalogBySlug.get(row.slug)
                                                    ?.gap ??
                                                '—'}
                                        </span>
                                    {:else if statShown === 'debut-year'}
                                        <span
                                            class="shrink-0 text-center text-xs font-medium ring-1 ring-slate-500/30 rounded-4xl w-[18%] py-0.5"
                                        >
                                            {catalogBySlug
                                                .get(row.slug)
                                                ?.debut.slice(0, 4) || '—'}
                                        </span>
                                    {/if}
                                </button>
                            {/each}
                        </div>
                    {:else}
                        <p class="mt-3 text-sm text-muted-foreground">
                            No Phish songs found for this tour.
                        </p>
                    {/if}
                {:else if allSongsLoading}
                    <p class="mt-3 text-sm text-muted-foreground">
                        Loading full song catalog…
                    </p>
                {:else}
                    <div class="mt-3 flex flex-col gap-3">
                        {@render playCountSlider()}

                        <div class="flex items-center justify-between gap-3">
                            <label
                                for="min-gap"
                                class="shrink-0 text-sm text-muted-foreground md:text-xs"
                            >
                                Minimum Gap
                            </label>
                            <span
                                class="shrink-0 text-sm font-medium tabular-nums text-muted-foreground md:text-xs"
                            >
                                {minGap === 0 ? '∞' : `${minGap}+ shows`}
                            </span>
                        </div>

                        <input
                            id="min-gap"
                            type="range"
                            min="0"
                            max={maxGap}
                            step="5"
                            bind:value={minGap}
                            class="h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:rounded-full [&::-webkit-slider-runnable-track]:bg-secondary [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:rounded-full [&::-moz-range-track]:bg-secondary [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5"
                        />

                        {@render debutRangeSlider()}

                        <div class="flex items-center gap-2 pb-6 pt-4">
                            <button
                                type="button"
                                id="only-phish-songs"
                                role="checkbox"
                                aria-checked={onlyPhishSongs}
                                onclick={() =>
                                    (onlyPhishSongs = !onlyPhishSongs)}
                                class="size-4 shrink-0 rounded-lg border border-input shadow-xs transition-shadow outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 {onlyPhishSongs
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : ''}"
                            >
                                {#if onlyPhishSongs}
                                    <div
                                        class="grid place-content-center text-current"
                                    >
                                        <Check class="size-3.5" />
                                    </div>
                                {/if}
                            </button>
                            <label
                                for="only-phish-songs"
                                class="text-sm leading-none font-normal text-muted-foreground md:text-xs"
                            >
                                Only Phish Songs
                            </label>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <p class="text-sm text-muted-foreground">
                            {tourShows.length} show{tourShows.length !== 1
                                ? 's'
                                : ''} &middot;
                            {notPlayed.length} song{notPlayed.length !== 1
                                ? 's'
                                : ''} not played
                        </p>
                        <div
                            class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                        >
                            {#each notPlayed as song (song.slug)}
                                <button
                                    type="button"
                                    onclick={() => openSongDialog(song.slug)}
                                    class="flex items-baseline cursor-pointer ring-1 ring-slate-500/10 justify-between gap-2 rounded p-3 text-left text-base hover:bg-accent text-primary {liveClasses(
                                        song.slug,
                                    ) || 'text-muted-foreground'}"
                                >
                                    <span class="truncate">{song.song}</span>
                                    {#if statShown != null}
                                        {#if statShown === 'play-count'}
                                            <span
                                                class="shrink-0 text-center text-xs font-medium ring-1 ring-fuchsia-500/30 text-fuchsia-500/60 rounded-4xl w-[18%] py-0.5"
                                            >
                                                {song.times_played}
                                            </span>
                                        {:else if statShown === 'gap'}
                                            <span
                                                class="shrink-0 text-center text-xs font-medium ring-1 ring-green-800/30 text-green-400/60 rounded-4xl w-[18%] py-0.5"
                                            >
                                                {liveGapBySlug.get(song.slug) ??
                                                    song.gap}
                                            </span>
                                        {:else if statShown === 'debut-year'}
                                            <span
                                                class="shrink-0 text-center text-xs font-medium ring-1 ring-slate-500/30 rounded-4xl w-[18%] py-0.5"
                                            >
                                                {song.debut.slice(0, 4) || '—'}
                                            </span>
                                        {/if}
                                    {/if}
                                </button>
                            {/each}
                        </div>
                    </div>
                {/if}

                {#if songCounts.length}
                    <button
                        type="button"
                        class="mt-4 text-sm text-primary underline decoration-primary/30 underline-offset-4"
                        onclick={() => (showFullSetlists = !showFullSetlists)}
                    >
                        {showFullSetlists ? 'Hide' : 'Show'} setlists for tour
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
    tourId={selectedTour?.tourid ?? null}
    tourName={selectedTour?.tourname ?? 'this tour'}
    tourPerformances={dialogPerformances}
/>
