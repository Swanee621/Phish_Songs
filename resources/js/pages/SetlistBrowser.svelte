<script lang="ts">
    import { page, useHttp } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import {
        setlistForDate,
        setlistsForYear,
        showYears,
    } from '@/actions/App/Http/Controllers/AppController';
    import AppHead from '@/components/AppHead.svelte';
    import GuestAppearancesToggle from '@/components/GuestAppearancesToggle.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { guestAppearances } from '@/lib/guest-appearances.svelte';
    import { createScrollMemory, toPath } from '@/lib/last-visit';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import { readPrefsCookie, writePrefsCookie } from '@/lib/prefs-cookie';
    import type { ShowYear, SetlistRow } from '@/types/phishnet';

    const BADGE_CLASSES =
        'inline-flex w-fit shrink-0 cursor-pointer items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap transition-[color,box-shadow]';

    /**
     * A guest appearance's pill is set in muted grey so it reads as a lesser
     * entry among the year's shows. Selecting one drops that: on the primary
     * fill it would be the selected pill that was hardest to read.
     */
    const badgeClasses = (isSelected: boolean, isGuest = false): string =>
        `${BADGE_CLASSES} ${
            isSelected
                ? 'bg-primary text-primary-foreground'
                : isGuest
                  ? 'bg-secondary text-muted-foreground'
                  : 'bg-secondary text-secondary-foreground'
        }`;

    type StoredPrefs = {
        year: string | null;
        showdate: string;
    };

    const PREFS_COOKIE_NAME = 'setlist-browser-prefs';

    const savedPrefs = readPrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME);

    const scrollMemory = createScrollMemory(toPath(page.url));

    let {
        clientSyncActiveInterval = 60,
    }: {
        clientSyncActiveInterval?: number;
    } = $props();

    let years = $state<string[]>([]);
    let yearsLoaded = $state(false);
    let selectedYear = $state<string | null>(null);
    let yearShows = $state<SetlistRow[][]>([]);
    let yearLoading = $state(false);

    let showdate = $state(
        typeof savedPrefs?.showdate === 'string' ? savedPrefs.showdate : '',
    );
    let rows = $state<SetlistRow[] | null>(null);
    let loading = $state(false);
    let notFound = $state(false);

    let songDialogOpen = $state(false);
    let songDialogSlug = $state<string | null>(null);
    let songDialogName = $state('');
    let songDialogTourId = $state<number | null>(null);
    let songDialogTourName = $state<string | null>(null);

    /**
     * The tour the clicked show belongs to, so the dialog can call out the rest
     * of that tour's plays the way the song checker does. Not every show is on
     * one — a one-off or a guest appearance has no tour — and those open with
     * the plain history instead.
     */
    function openSongDialog(row: SetlistRow) {
        songDialogSlug = row.slug;
        songDialogName = row.song;
        songDialogTourId = row.tourid || null;
        songDialogTourName = songDialogTourId === null ? null : row.tourname;
        songDialogOpen = true;
    }

    // Holds the cookie write back until the saved year/date have been restored,
    // so an early unmount can't overwrite them with empty defaults.
    let prefsHydrated = $state(false);

    // `showdate` follows the date picker as it is typed in; only a date that
    // actually returned a setlist is worth restoring on the next visit.
    let loadedShowdate = $state(
        typeof savedPrefs?.showdate === 'string' ? savedPrefs.showdate : '',
    );

    const yearsHttp = useHttp<Record<string, never>, { data: ShowYear[] }>({});
    const yearShowsHttp = useHttp<
        Record<string, never>,
        { data: SetlistRow[] }
    >({});
    const dateHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>({});
    const refreshHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>(
        {},
    );

    // Refetch the on-screen date without blanking it out, so a new song slots in
    // under the spinner rather than flashing a loading state.
    function refreshActiveDate() {
        refreshHttp.get(setlistForDate.url(loadedShowdate), {
            onSuccess: (response) => {
                if (response.data.length) {
                    rows = response.data;
                }
            },
        });
    }

    const livePoll = createLivePoll({
        activeInterval: clientSyncActiveInterval,
        /*
         * A moved version can carry a change to any date, not just tonight's —
         * a late correction, or a catch-up import back-filling a show the sync
         * missed — so refresh whatever is on screen rather than only the show
         * being played. Both refetches are served from the server's cache, so
         * an untouched date costs next to nothing.
         */
        onStale: (status) => {
            if (rows !== null && loadedShowdate !== '') {
                refreshActiveDate();
            }

            if (selectedYear !== null && Number(selectedYear) === status.year) {
                refreshYearShows(selectedYear);
            }
        },
    });

    /**
     * The loaded date, split per show. A date can carry more than one — a guest
     * appearance alongside the band's own show — and each is its own setlist
     * with its own header, rather than one block with both nights' sets run
     * together under whichever happened to come back first.
     *
     * Hiding guest appearances thins a mixed date but never blanks one: looking
     * a date up is an explicit request for it, so the last show standing is
     * shown whatever it is.
     */
    const dateShows = $derived.by(() => {
        const grouped = rows === null ? [] : groupShows(rows);

        if (guestAppearances.shown) {
            return grouped;
        }

        const phishShows = grouped.filter((show) => show[0].artistid === 1);

        return phishShows.length ? phishShows : grouped;
    });

    const visibleYearShows = $derived(
        guestAppearances.shown
            ? yearShows
            : yearShows.filter((show) => show[0].artistid === 1),
    );

    const guestCount = $derived(
        yearShows.filter((show) => show[0].artistid !== 1).length,
    );

    // True only while the setlist on screen is the show currently being played.
    const viewingActiveShow = $derived(
        livePoll.inShowWindow &&
            rows !== null &&
            loadedShowdate !== '' &&
            loadedShowdate === livePoll.activeShowdate,
    );

    onMount(() => {
        yearsHttp.get(showYears.url(), {
            onSuccess: (response) => {
                const seen = new SvelteSet<string>();
                years = response.data
                    .map((show) => show.showyear)
                    .filter((year) => {
                        if (seen.has(year)) {
                            return false;
                        }

                        seen.add(year);

                        return true;
                    })
                    .sort((a, b) => Number(a) - Number(b));
                yearsLoaded = true;

                restorePrefs();
            },
        });

        livePoll.start();

        const stopTracking = scrollMemory.track();

        return () => {
            stopTracking();
            livePoll.stop();
        };
    });

    // Only once the restored year and date have actually come back is the
    // document tall enough to hold the offset the visitor left at.
    $effect(() => {
        if (prefsHydrated && !yearLoading && !loading) {
            scrollMemory.restore();
        }
    });

    /**
     * Re-select the year and re-fetch the date the visitor was last looking at.
     * `loadYear` clears `rows`, so it has to run before `loadDate`.
     */
    function restorePrefs() {
        const savedYear = savedPrefs?.year;

        if (typeof savedYear === 'string' && years.includes(savedYear)) {
            loadYear(savedYear);
        }

        if (loadedShowdate) {
            loadDate(loadedShowdate);
        }

        prefsHydrated = true;
    }

    $effect(() => {
        if (!prefsHydrated) {
            return;
        }

        writePrefsCookie<StoredPrefs>(PREFS_COOKIE_NAME, {
            year: selectedYear,
            showdate: loadedShowdate,
        });
    });

    /**
     * Guest appearances (`artistid !== 1`) get a badge of their own rather than
     * being dropped — the date lookup above has always been able to pull one up,
     * so leaving them out of the year list only made them unfindable.
     */
    function groupShows(data: SetlistRow[]): SetlistRow[][] {
        const grouped = new SvelteMap<number, SetlistRow[]>();

        for (const row of data) {
            const existing = grouped.get(row.showid);

            if (existing) {
                existing.push(row);
            } else {
                grouped.set(row.showid, [row]);
            }
        }

        return [...grouped.values()];
    }

    function loadYear(year: string) {
        selectedYear = year;
        yearLoading = true;
        yearShows = [];
        rows = null;

        yearShowsHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearShows = groupShows(response.data);
                yearLoading = false;
            },
        });
    }

    // Re-pull the show list for the year on screen without blanking it, so a
    // show that just landed slots into the badges rather than resetting the UI.
    function refreshYearShows(year: string) {
        yearShowsHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                yearShows = groupShows(response.data);
            },
        });
    }

    function loadDate(date: string) {
        if (!date) {
            return;
        }

        showdate = date;
        loading = true;
        notFound = false;
        rows = null;

        dateHttp.get(setlistForDate.url(date), {
            onSuccess: (response) => {
                const data = response.data;
                rows = data.length ? data : null;
                notFound = !data.length;
                loadedShowdate = data.length ? date : '';
                loading = false;
            },
        });
    }
</script>

<AppHead title="Setlist Browser" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Setlist Browser</h1>
        <p class="text-muted-foreground">
            Look up any Phish setlist by date, or browse by year
        </p>
    </div>

    <form
        class="flex max-w-sm gap-2"
        onsubmit={(e) => {
            e.preventDefault();
            loadDate(showdate);
        }}
    >
        <input
            type="date"
            bind:value={showdate}
            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
        />
        <button
            type="submit"
            disabled={loading}
            class="inline-flex h-11 items-center justify-center gap-2 rounded-md bg-primary px-5 py-2.5 text-base font-medium whitespace-nowrap text-primary-foreground shadow transition-colors hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 md:h-9 md:px-4 md:py-2 md:text-sm"
        >
            {loading ? 'Loading…' : 'Load'}
        </button>
    </form>

    <div>
        <h2 class="mb-2 text-sm font-semibold text-muted-foreground">
            Browse by year
        </h2>
        <div class="flex flex-wrap gap-1.5">
            {#if !yearsLoaded}
                <span class="text-sm text-muted-foreground">Loading years…</span
                >
            {:else}
                {#each years as year (year)}
                    <button
                        type="button"
                        onclick={() => loadYear(year)}
                        class={badgeClasses(selectedYear === year)}
                    >
                        {year}
                    </button>
                {/each}
            {/if}
        </div>
    </div>

    {#if selectedYear}
        <div>
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Shows in {selectedYear}
                </h2>

                {#if !yearLoading}
                    <GuestAppearancesToggle count={guestCount} />
                {/if}
            </div>
            <div class="flex flex-wrap gap-1.5">
                {#if yearLoading}
                    <span class="text-sm text-muted-foreground"
                        >Loading shows…</span
                    >
                {:else if !visibleYearShows.length}
                    <span class="text-sm text-muted-foreground"
                        >No shows found.</span
                    >
                {:else}
                    {#each visibleYearShows as show (show[0].showid)}
                        <button
                            type="button"
                            onclick={() => loadDate(show[0].showdate)}
                            class={badgeClasses(
                                showdate === show[0].showdate,
                                show[0].artistid !== 1,
                            )}
                            title={show[0].artistid !== 1
                                ? `Guest appearance${show[0].artist_name ? ` — ${show[0].artist_name}` : ''}`
                                : undefined}
                            >{show[0].showdate}
                        </button>
                    {/each}
                {/if}
            </div>
        </div>
    {/if}

    <div class="max-w-2xl">
        {#if loading}
            <p class="text-sm text-muted-foreground">Loading…</p>
        {:else if notFound}
            <p class="text-sm text-muted-foreground">
                No setlist found for {showdate}.
            </p>
        {:else if rows}
            {#if viewingActiveShow}
                <div
                    class="mb-3 flex items-center gap-2 text-sm text-muted-foreground"
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
            {#each dateShows as showRows (showRows[0].showid)}
                <SetlistView
                    rows={showRows}
                    awaitingNextSong={viewingActiveShow}
                    onSongClick={openSongDialog}
                />
            {/each}
        {/if}
    </div>
</div>

<SongHistoryDialog
    bind:open={songDialogOpen}
    slug={songDialogSlug}
    songName={songDialogName}
    tourId={songDialogTourId}
    tourName={songDialogTourName}
/>
