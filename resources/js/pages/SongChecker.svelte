<script module lang="ts">
    import { home } from '@/routes';

    export const layout = {
        breadcrumbs: [{ title: 'Song Checker', href: home() }],
    };
</script>

<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import ChevronDown from 'lucide-svelte/icons/chevron-down';
    import { onMount } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { slide } from 'svelte/transition';
    import {
        setlistsForYear,
        showYears,
        songs as songsRoute,
    } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/phishnet/SetlistView.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Checkbox } from '@/components/ui/checkbox';
    import {
        Dialog,
        DialogContent,
        DialogDescription,
        DialogFooter,
        DialogTitle,
    } from '@/components/ui/dialog';
    import { Label } from '@/components/ui/label';
    import type { SetlistRow, ShowYear, Song } from '@/types/phishnet';

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
    const PREFS_COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

    function readPrefsCookie(): Partial<StoredPrefs> | null {
        if (typeof document === 'undefined') {
            return null;
        }

        const match = document.cookie
            .split('; ')
            .find((row) => row.startsWith(`${PREFS_COOKIE_NAME}=`));

        if (!match) {
            return null;
        }

        try {
            const parsed: unknown = JSON.parse(
                decodeURIComponent(match.slice(PREFS_COOKIE_NAME.length + 1)),
            );

            return typeof parsed === 'object' && parsed !== null
                ? parsed
                : null;
        } catch {
            return null;
        }
    }

    function writePrefsCookie(prefs: StoredPrefs) {
        if (typeof document === 'undefined') {
            return;
        }

        document.cookie = `${PREFS_COOKIE_NAME}=${encodeURIComponent(
            JSON.stringify(prefs),
        )}; path=/; max-age=${PREFS_COOKIE_MAX_AGE}`;
    }

    const savedPrefs = readPrefsCookie();

    let {
        excludedSongs = [],
        defaultMinPlayed = 10,
    }: { excludedSongs?: string[]; defaultMinPlayed?: number } = $props();

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

        const playedSlugs = new SvelteSet(songCounts.map((row) => row.slug));

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
    });

    $effect(() => {
        if (currentYear === null || !selectedTour) {
            return;
        }

        writePrefsCookie({
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
            <Button
                variant="ghost"
                size="icon"
                onclick={() => (filtersOpen = !filtersOpen)}
                aria-expanded={filtersOpen}
                aria-controls="song-checker-filters"
                aria-label={filtersOpen ? 'Hide filters' : 'Show filters'}
            >
                <ChevronDown
                    class="size-4 transition-transform duration-200 {filtersOpen
                        ? 'rotate-180'
                        : ''}"
                />
            </Button>
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
                            >
                                <Badge
                                    variant={currentYear === year
                                        ? 'default'
                                        : 'secondary'}
                                    class="cursor-pointer"
                                >
                                    {year}
                                </Badge>
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
                                >
                                    <Badge
                                        variant={index === tourIndex
                                            ? 'default'
                                            : 'secondary'}
                                        class="cursor-pointer"
                                    >
                                        {tour.tourname}
                                    </Badge>
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
                        <Button
                            variant="outline"
                            size="sm"
                            onclick={() => cycleTour(-1)}
                            disabled={prevDisabled}
                        >
                            &larr; Previous tour
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onclick={() => cycleTour(1)}
                            disabled={nextDisabled}
                        >
                            Next tour &rarr;
                        </Button>
                    </div>
                </div>

                <div
                    class="mt-4 flex flex-wrap items-center justify-between gap-2"
                >
                    <p class="text-sm text-muted-foreground">
                        {tourShows.length} show{tourShows.length !== 1
                            ? 's'
                            : ''} &middot;
                        {#if viewMode === 'played'}
                            {songCounts.length} unique song{songCounts.length !==
                            1
                                ? 's'
                                : ''} played
                        {:else}
                            {notPlayed.length} song{notPlayed.length !== 1
                                ? 's'
                                : ''} not played
                        {/if}
                    </p>

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
                                    class="flex items-baseline cursor-pointer justify-between gap-2 rounded p-3 text-left text-base hover:bg-accent hover:text-primary"
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
                            <Checkbox
                                id="only-phish-songs"
                                bind:checked={onlyPhishSongs}
                            />
                            <Label
                                for="only-phish-songs"
                                class="text-sm font-normal text-muted-foreground md:text-xs"
                            >
                                Only Phish Songs
                            </Label>
                        </div>
                    </div>

                    <div
                        class="mt-3 grid grid-cols-2 gap-x-4 gap-y-0.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                    >
                        {#each notPlayed as song (song.slug)}
                            <button
                                type="button"
                                onclick={() => openSongDialog(song.slug)}
                                class="truncate rounded cursor-pointer p-3 text-left text-base text-muted-foreground hover:bg-accent hover:text-primary"
                            >
                                {song.song}
                            </button>
                        {/each}
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
                            {#each tourShows.reverse() as rows (rows[0].showid)}
                                <div class="border-b p-5 border-white">
                                    <SetlistView {rows} />
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

<Dialog bind:open={dialogOpen}>
    <DialogContent class="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogTitle>{dialogSongName}</DialogTitle>
        <DialogDescription>All the data!</DialogDescription>

        {#if dialogCatalogEntry}
            <div class="mt-4">
                <h3 class="mb-1 text-sm font-semibold text-muted-foreground">
                    Song catalog
                </h3>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
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
                    <div class="mt-2 rounded border p-2">
                        <p class="mb-1 text-xs font-medium">
                            {row.showdate} &mdash; {row.venue}
                        </p>
                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs"
                        >
                            {#each Object.entries(row) as [key, value] (key)}
                                <dt class="font-mono text-muted-foreground">
                                    {key}
                                </dt>
                                <dd class="wrap-break-word">
                                    {formatFieldValue(value)}
                                </dd>
                            {/each}
                        </dl>
                    </div>
                {/each}
            {:else}
                <p class="text-sm text-muted-foreground">
                    Not played in this tour.
                </p>
            {/if}
        </div>

        <DialogFooter class="mt-4">
            <Button variant="outline" onclick={() => (dialogOpen = false)}>
                Close
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
