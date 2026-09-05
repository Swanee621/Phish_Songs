<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import {
        songPerformances,
        songTourPerformances,
        songs as songsRoute,
    } from '@/actions/App/Http/Controllers/AppController';
    import type { SetlistRow, Song } from '@/types/phishnet';

    /**
     * Sets the boxes for the tour the dialog was opened from apart from the
     * older performances listed underneath them. Border colour and width are
     * separate properties from the `border` the box already carries, so these
     * can safely be appended rather than swapped in.
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

    let {
        open = $bindable(false),
        slug,
        songName = '',
        catalogEntry = undefined,
        tourId = null,
        tourName = null,
        tourPerformances = undefined,
    }: {
        open?: boolean;
        slug: string | null;
        /** Shown as the heading until the catalog entry lands. */
        songName?: string;
        /**
         * The song's catalog row, for a caller that already holds the whole
         * catalog. Left off, the dialog fetches the catalog itself the first
         * time it is opened — a page that only lists setlists has no other
         * reason to carry every song in memory.
         */
        catalogEntry?: Song | null;
        /**
         * The tour to call out above the song's wider history: its performances
         * are listed first, highlighted, and left out of the history below so
         * they are not repeated directly under themselves.
         */
        tourId?: number | null;
        tourName?: string | null;
        /**
         * That tour's performances, for a caller that already holds the tour in
         * memory. Left off, the dialog fetches them for `tourId` itself.
         */
        tourPerformances?: SetlistRow[];
    } = $props();

    /**
     * The song's most recent performances anywhere, which the server looks up
     * per dialog rather than the page holding every year in memory. A page at a
     * time, extended as the dialog is scrolled: a well-worn song has hundreds of
     * these behind it, and most dialogs are closed after the first few.
     */
    let recentPerformances = $state<SetlistRow[]>([]);
    let recentPerformancesLoading = $state(false);
    let recentPerformancesLoadingMore = $state(false);
    let recentPerformancesHasMore = $state(false);

    /**
     * The tour the dialog was opened from, held for the length of the dialog so
     * every page asks the server to exclude the same one. Deliberately not
     * reactive: the effect below both writes and reads it, which as state would
     * make that effect its own dependency and refetch the first page twice.
     */
    let heldExcludeTourId: number | null = null;

    /** The catalog, once fetched, for a caller that did not supply an entry. */
    let fetchedSongs = $state<Song[] | null>(null);
    let songsRequested = false;

    /** The tour's performances, for a caller that did not supply them. */
    let fetchedTourPerformances = $state<SetlistRow[]>([]);
    let tourPerformancesLoading = $state(false);

    let dialogScroller = $state<HTMLDivElement | null>(null);
    let performancesSentinel = $state<HTMLDivElement | null>(null);

    const performancesHttp = useHttp<
        Record<string, never>,
        { data: SetlistRow[]; meta: { hasMore: boolean } }
    >({});
    const songsHttp = useHttp<Record<string, never>, { data: Song[] }>({});
    const tourHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>({});

    const tourRows = $derived(tourPerformances ?? fetchedTourPerformances);

    const catalog = $derived(
        catalogEntry !== undefined
            ? catalogEntry
            : (fetchedSongs?.find((song) => song.slug === slug) ?? null),
    );

    const displayName = $derived(
        songName ||
            catalog?.song ||
            tourRows[0]?.song ||
            recentPerformances[0]?.song ||
            slug ||
            '',
    );

    /**
     * phish.net has no permalink on the song catalog itself, only on shows, so
     * a song's page is addressed by its slug the same way the setlists do.
     */
    const songUrl = $derived(
        slug === null ? null : `https://phish.net/song/${slug}`,
    );

    // Start over whenever the dialog is opened, or moved to another song while
    // it is open: the list on screen belongs to the song it was fetched for.
    $effect(() => {
        const openedSlug = open ? slug : null;

        if (openedSlug === null) {
            return;
        }

        const openedTourId = tourId;

        heldExcludeTourId = openedTourId;
        recentPerformances = [];
        recentPerformancesHasMore = false;
        performancesSentinel = null;

        fetchPerformancesPage(openedSlug, 0);

        if (tourPerformances === undefined) {
            fetchedTourPerformances = [];

            if (openedTourId !== null) {
                fetchTourPerformances(openedSlug, openedTourId);
            }
        }

        if (catalogEntry === undefined && !songsRequested) {
            songsRequested = true;

            songsHttp.get(songsRoute.url(), {
                onSuccess: (response) => {
                    fetchedSongs = response.data;
                },
                onError: () => {
                    songsRequested = false;
                },
                onNetworkError: () => {
                    songsRequested = false;
                },
            });
        }
    });

    /**
     * The song's run through the tour it was clicked in. Guarded the same way
     * the history is: a dialog that has moved on to another song by the time
     * this lands is no longer showing the tour it was asked for.
     */
    function fetchTourPerformances(pageSlug: string, tour: number) {
        tourPerformancesLoading = true;

        const settle = () => {
            tourPerformancesLoading = false;
        };

        tourHttp.get(songTourPerformances.url({ slug: pageSlug, tour }), {
            onSuccess: (response) => {
                if (slug !== pageSlug || tourId !== tour) {
                    return;
                }

                fetchedTourPerformances = response.data;
                settle();
            },
            onError: settle,
            onNetworkError: settle,
        });
    }

    /**
     * One page of past performances, appended to what the dialog already holds.
     *
     * `offset` doubles as the guard against a stale response: a dialog that has
     * since been closed, reopened, or moved to another song no longer has a list
     * that page belongs on the end of.
     */
    function fetchPerformancesPage(pageSlug: string, offset: number) {
        if (offset === 0) {
            recentPerformancesLoading = true;
        } else {
            recentPerformancesLoadingMore = true;
        }

        const settle = () => {
            recentPerformancesLoading = false;
            recentPerformancesLoadingMore = false;
        };

        performancesHttp.get(
            songPerformances.url(pageSlug, {
                query: {
                    // A tour listed in full above these is already on screen, so
                    // asking for it back would waste slots on duplicates.
                    exclude_tour: heldExcludeTourId,
                    offset,
                },
            }),
            {
                onSuccess: (response) => {
                    if (
                        slug !== pageSlug ||
                        recentPerformances.length !== offset
                    ) {
                        return;
                    }

                    recentPerformances = [
                        ...recentPerformances,
                        ...response.data,
                    ];
                    recentPerformancesHasMore = response.meta.hasMore;
                    settle();
                },
                onError: settle,
                onNetworkError: settle,
            },
        );
    }

    function loadMorePerformances() {
        if (
            slug === null ||
            !recentPerformancesHasMore ||
            recentPerformancesLoading ||
            recentPerformancesLoadingMore
        ) {
            return;
        }

        fetchPerformancesPage(slug, recentPerformances.length);
    }

    function formatFieldValue(value: unknown): string {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        return String(value);
    }

    // Pull the next page of past performances in as the foot of the list comes
    // into view inside the dialog, a screen ahead of the user reaching it.
    $effect(() => {
        /*
         * Read so a landed page re-runs this and re-observes: an observer
         * reports crossings, not the standing state, so a page too short to push
         * the sentinel back off screen would otherwise stall the list there.
         */
        const loaded = recentPerformances.length;

        const sentinel = performancesSentinel;
        const root = dialogScroller;

        if (loaded === 0 || sentinel === null || root === null) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    loadMorePerformances();
                }
            },
            { root, rootMargin: '200px' },
        );

        observer.observe(sentinel);

        return () => observer.disconnect();
    });
</script>

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

{#if open}
    <div class="fixed inset-0 z-50 flex items-center justify-center">
        <button
            type="button"
            class="fixed inset-0 bg-black/50"
            aria-label="Close"
            onclick={() => (open = false)}
        ></button>
        <div
            bind:this={dialogScroller}
            class="relative z-10 max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-lg border bg-background p-6 shadow-lg"
            role="dialog"
            aria-modal="true"
        >
            <h2 class="text-lg leading-none font-semibold tracking-tight">
                <a
                    href={songUrl}
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline decoration-primary/30 underline-offset-4"
                >
                    {displayName}
                </a>
            </h2>

            {#if catalog}
                <div class="mt-4">
                    <h3
                        class="mb-1 text-sm font-semibold text-muted-foreground"
                    >
                        Song catalog
                    </h3>
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm"
                    >
                        {#each Object.entries(catalog) as [key, value] (key)}
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

            {#if tourName !== null}
                <div class="mt-4">
                    <h3
                        class="mb-1 text-sm font-semibold text-muted-foreground"
                    >
                        Performances in {tourName}{tourPerformancesLoading
                            ? ''
                            : ` (${tourRows.length})`}
                    </h3>
                    {#if tourPerformancesLoading}
                        <p class="text-sm text-muted-foreground">Loading…</p>
                    {:else if tourRows.length}
                        {#each tourRows as row, i (row.showid + '-' + i)}
                            {@render performanceBox(row, true)}
                        {/each}
                    {:else}
                        <p class="text-sm text-muted-foreground">
                            Not played in this tour.
                        </p>
                    {/if}
                </div>
            {/if}

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
                    {#if recentPerformancesHasMore}
                        <div
                            bind:this={performancesSentinel}
                            class="py-3 text-center text-sm text-muted-foreground"
                        >
                            {recentPerformancesLoadingMore ? 'Loading…' : ''}
                        </div>
                    {/if}
                {:else}
                    <p class="text-sm text-muted-foreground">New song</p>
                {/if}
            </div>

            <div
                class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"
            >
                <button
                    type="button"
                    onclick={() => (open = false)}
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-md border border-input bg-background px-5 py-2.5 text-base font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:h-9 md:px-4 md:py-2 md:text-sm"
                >
                    Close
                </button>
            </div>
        </div>
    </div>
{/if}
