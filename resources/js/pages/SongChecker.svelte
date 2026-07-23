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
        songPerformances,
        songs as songsRoute,
    } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';
    import type { SetlistRow, ShowYear, Song } from '@/types/phishnet';

    const BADGE_CLASSES =
        'inline-flex w-fit shrink-0 cursor-pointer items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-[color,box-shadow]';

    const OUTLINE_BUTTON_CLASSES =
        'inline-flex h-10 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 md:h-8 md:px-3 md:text-xs';

    /** Played at some point during the show being treated as current. */
    const PLAYED_TONIGHT_CLASSES =
        'bg-green-500/10 text-green-700 dark:text-green-400';

    /** The most recent song of that show: on stage now, or the closer. */
    const LATEST_SONG_CLASSES =
        'bg-amber-500/15 font-medium text-amber-700 ring-1 ring-amber-500/40 dark:text-amber-300';

    /**
     * Sets the dialog's boxes for the tour on screen apart from the older
     * performances listed underneath them. Border colour and width are separate
     * properties from the `border` the box already carries, so these can safely
     * be appended rather than swapped in.
     */
    const CURRENT_TOUR_BOX_CLASSES = 'border-amber-500/70 bg-amber-500/5';

    /**
     * The fields each performance box lists, in the order they are shown. The
     * underlying rows carry far more than this — ids, slugs, jam flags — which
     * is noise once the box is something you read rather than debug with.
     */
    const PERFORMANCE_FIELDS: {
        key: keyof SetlistRow;
        label: string;
        html?: boolean;
    }[] = [
        { key: 'song', label: 'Song' },
        { key: 'showdate', label: 'Date' },
        { key: 'setlistnotes', label: 'Notes', html: true },
        { key: 'venue', label: 'Venue' },
        { key: 'city', label: 'City' },
        { key: 'state', label: 'State' },
        { key: 'country', label: 'Country' },
        { key: 'tourname', label: 'Tour' },
        { key: 'tourwhen', label: 'Tour Run' },
    ];

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
        clientSyncInterval = 3600,
        clientSyncActiveInterval = 60,
    }: {
        excludedSongs?: string[];
        defaultMinPlayed?: number;
        clientSyncInterval?: number;
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

    let currentYear = $state<number | null>(null);
    let currentTours = $state<Tour[]>([]);
    let tourIndex = $state(0);

    let allSongs = $state<Song[] | null>(null);
    let allSongsLoading = $state(false);

    let dialogOpen = $state(false);
    let dialogSlug = $state<string | null>(null);

    /**
     * The song's most recent performances anywhere, which the server looks up
     * per dialog rather than the page holding every year in memory.
     */
    let recentPerformances = $state<SetlistRow[]>([]);
    let recentPerformancesLoading = $state(false);

    let minTimesPlayed = $state(
        typeof savedPrefs?.minTimesPlayed === 'number'
            ? savedPrefs.minTimesPlayed
            : defaultMinPlayed,
    );
    let onlyPhishSongs = $state(
        typeof savedPrefs?.onlyPhishSongs === 'boolean'
            ? savedPrefs.onlyPhishSongs
            : true,
    );

    const yearData = new SvelteMap<number, SetlistRow[]>();

    const yearsHttp = useHttp<Record<string, never>, { data: ShowYear[] }>({});
    const setlistsHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {},
    );
    const songsHttp = useHttp<Record<string, never>, { data: Song[] }>({});
    const refreshHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {},
    );
    const performancesHttp = useHttp<
        Record<string, never>,
        { data: SetlistRow[] }
    >({});

    // Shared poll loop: refetch the live year whenever its version hash moves,
    // and expose the show-window flag + countdown the setlists section renders.
    const livePoll = createLivePoll({
        idleInterval: clientSyncInterval,
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
     * The newest entry of that show: the song on stage while the show is being
     * played, and the one it closed with afterwards. It holds its colour for
     * the same grace period as the rest, so nothing shifts under a page left
     * open overnight.
     */
    const latestSongSlug = $derived(liveShowRows.at(-1)?.slug ?? null);

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

        return [...grouped.values()].sort((a, b) =>
            a[0].showdate.localeCompare(b[0].showdate),
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

    const playedAlphabetical = $derived(
        [...songCounts].sort((a, b) => a.song.localeCompare(b.song)),
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
                    !playedSlugs.has(song.slug) &&
                    !excludedSet.has(song.slug),
            )
            .sort((a, b) => a.song.localeCompare(b.song));
    });

    const dialogCatalogEntry = $derived(
        allSongs?.find((song) => song.slug === dialogSlug) ?? null,
    );

    const dialogPerformances = $derived(
        [...tourRows]
            .filter((row) => row.slug === dialogSlug)
            .sort((a, b) => a.showdate.localeCompare(b.showdate)),
    );

    const dialogSongName = $derived(
        dialogCatalogEntry?.song ??
            dialogPerformances[0]?.song ??
            dialogSlug ??
            '',
    );

    /**
     * phish.net has no permalink on the song catalog itself, only on shows, so
     * a song's page is addressed by its slug the same way the setlists do.
     */
    const dialogSongUrl = $derived(
        dialogSlug === null ? null : `https://phish.net/song/${dialogSlug}`,
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

    function openSongDialog(slug: string) {
        dialogSlug = slug;
        dialogOpen = true;
        recentPerformances = [];
        recentPerformancesLoading = true;

        performancesHttp.get(
            songPerformances.url(slug, {
                // The tour on screen is already listed in full above these, so
                // asking for it back would waste slots on duplicates.
                query: { exclude_tour: selectedTour?.tourid ?? null },
            }),
            {
                onSuccess: (response) => {
                    // A dialog closed or reopened on another song mid-flight
                    // must not have this response land under it.
                    if (dialogSlug !== slug) {
                        return;
                    }

                    recentPerformances = response.data;
                    recentPerformancesLoading = false;
                },
                onError: () => (recentPerformancesLoading = false),
                onNetworkError: () => (recentPerformancesLoading = false),
            },
        );
    }

    function formatFieldValue(value: unknown): string {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        return String(value);
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

    $effect(() => {
        if (currentYear === null || !selectedTour) {
            return;
        }

        writePrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME, {
            year: currentYear,
            tourid: selectedTour.tourid,
            minTimesPlayed,
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
                    class="mt-4 flex flex-wrap items-center justify-between gap-2"
                >
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
                </div>

                {#if viewMode === 'played'}
                    {#if playedAlphabetical.length}
                        <div
                            class="mt-3 grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                        >
                            {#each playedAlphabetical as row (row.slug)}
                                <button
                                    type="button"
                                    onclick={() => openSongDialog(row.slug)}
                                    class="flex items-baseline cursor-pointer justify-between gap-2 rounded p-3 text-left text-base hover:bg-accent hover:text-primary {liveClasses(
                                        row.slug,
                                    )}"
                                >
                                    <span class="truncate">{row.song}</span>
                                    <span
                                        class="shrink-0 text-xs font-medium text-muted-foreground"
                                        >{row.count}</span
                                    >
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
                            max="300"
                            step="5"
                            bind:value={minTimesPlayed}
                            class="h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:rounded-full [&::-webkit-slider-runnable-track]:bg-secondary [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:rounded-full [&::-moz-range-track]:bg-secondary [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5"
                        />

                        <div class="flex items-center gap-2">
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

                    <div class="mt-8 flex flex-col gap-2">
                        <p class="text-sm text-muted-foreground">
                            {tourShows.length} show{tourShows.length !== 1
                                ? 's'
                                : ''} &middot;
                            {notPlayed.length} song{notPlayed.length !== 1
                                ? 's'
                                : ''} not played
                        </p>
                        <div
                            class="mt-3 grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                        >
                            {#each notPlayed as song (song.slug)}
                                <button
                                    type="button"
                                    onclick={() => openSongDialog(song.slug)}
                                    class="truncate rounded cursor-pointer p-3 text-left text-base hover:bg-accent hover:text-primary {liveClasses(
                                        song.slug,
                                    ) || 'text-muted-foreground'}"
                                >
                                    {song.song}
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
                            {#each tourShows.reverse() as rows (rows[0].showid)}
                                <div class="border-b p-5 border-white">
                                    <SetlistView
                                        {rows}
                                        awaitingNextSong={livePoll.inShowWindow &&
                                            rows[0].showdate ===
                                                livePoll.activeShowdate}
                                    />
                                </div>
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

{#snippet performanceBox(row: SetlistRow, fromCurrentTour: boolean)}
    <div
        class="mt-2 rounded border p-2 {fromCurrentTour
            ? CURRENT_TOUR_BOX_CLASSES
            : ''}"
    >
        <p class="mb-1 text-xs font-medium">
            <a
                href={row.permalink}
                target="_blank"
                rel="noopener"
                class="text-primary underline decoration-primary/30 underline-offset-4"
            >
                {row.showdate} &mdash; {row.venue}
            </a>
        </p>
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
            {#each PERFORMANCE_FIELDS as field (field.key)}
                <dt class="font-medium text-muted-foreground">{field.label}</dt>
                <dd class="wrap-break-word">
                    {#if field.html && row[field.key]}
                        <!--
                            Setlist notes arrive from phish.net as a fragment of
                            markup — footnote links and emphasis — so they are
                            rendered rather than escaped, the same way the
                            setlist views do.
                          -->
                        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                        {@html row[field.key]}
                    {:else}
                        {formatFieldValue(row[field.key])}
                    {/if}
                </dd>
            {/each}
        </dl>
    </div>
{/snippet}

{#if dialogOpen}
    <div class="fixed inset-0 z-50 flex items-center justify-center">
        <button
            type="button"
            class="fixed inset-0 bg-black/50"
            aria-label="Close"
            onclick={() => (dialogOpen = false)}
        ></button>
        <div
            class="relative z-10 max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg border bg-background p-6 shadow-lg"
            role="dialog"
            aria-modal="true"
        >
            <h2 class="text-lg leading-none font-semibold tracking-tight">
                <a
                    href={dialogSongUrl}
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline decoration-primary/30 underline-offset-4"
                >
                    {dialogSongName}
                </a>
            </h2>

            {#if dialogCatalogEntry}
                <div class="mt-4">
                    <h3
                        class="mb-1 text-sm font-semibold text-muted-foreground"
                    >
                        Song catalog
                    </h3>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm"
                    >
                        {#each Object.entries(dialogCatalogEntry) as [key, value] (key)}
                            <dt class="font-mono text-xs text-muted-foreground">
                                {key}
                            </dt>
                            <dd class="wrap-break-word">
                                {#if typeof value === 'string' && value.startsWith('https://')}
                                    <a
                                        href={value}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {value}
                                    </a>
                                {:else}
                                    {formatFieldValue(value)}
                                {/if}
                            </dd>
                        {/each}
                    </dl>
                </div>
            {/if}

            <div class="mt-4">
                <h3 class="mb-1 text-sm font-semibold text-muted-foreground">
                    Performances in {selectedTour?.tourname ?? 'this tour'} ({dialogPerformances.length})
                </h3>
                {#if dialogPerformances.length}
                    {#each dialogPerformances as row, i (row.showid + '-' + i)}
                        {@render performanceBox(row, true)}
                    {/each}
                {:else}
                    <p class="text-sm text-muted-foreground">
                        Not played in this tour.
                    </p>
                {/if}
            </div>

            <div class="mt-4">
                <h3 class="mb-1 text-sm font-semibold text-muted-foreground">
                    Most recent past performances
                </h3>
                {#if recentPerformancesLoading}
                    <p class="text-sm text-muted-foreground">Loading…</p>
                {:else if recentPerformances.length}
                    {#each recentPerformances as row (row.showid + '-' + row.position)}
                        {@render performanceBox(row, false)}
                    {/each}
                {:else}
                    <p class="text-sm text-muted-foreground">New song</p>
                {/if}
            </div>

            <div
                class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
            >
                <button
                    type="button"
                    onclick={() => (dialogOpen = false)}
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-input bg-background px-5 py-2.5 text-base font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:h-9 md:px-4 md:py-2 md:text-sm"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
{/if}
