<script lang="ts">
    import { page, useHttp } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import {
        currentYearSetlists,
        setlistsForYear,
    } from '@/actions/App/Http/Controllers/AppController';
    import AppHead from '@/components/AppHead.svelte';
    import GuestAppearancesToggle from '@/components/GuestAppearancesToggle.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { guestAppearances } from '@/lib/guest-appearances.svelte';
    import { createScrollMemory, toPath } from '@/lib/last-visit';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import type { SetlistRow } from '@/types/phishnet';

    let {
        clientSyncActiveInterval = 60,
    }: {
        clientSyncActiveInterval?: number;
    } = $props();

    let shows = $state<SetlistRow[][]>([]);
    let loaded = $state(false);

    const scrollMemory = createScrollMemory(toPath(page.url));

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

    const http = useHttp<Record<string, never>, { data: SetlistRow[] }>({});

    /**
     * Guest appearances (`artistid !== 1`) are kept: they are nights the band
     * turned up and played, and dropping them left the list silently missing a
     * show. `SetlistView` labels them with the host act.
     */
    function groupIntoShows(rows: SetlistRow[]): SetlistRow[][] {
        const grouped = new SvelteMap<number, SetlistRow[]>();

        for (const row of rows) {
            const existing = grouped.get(row.showid);

            if (existing) {
                existing.push(row);
            } else {
                grouped.set(row.showid, [row]);
            }
        }

        // Most recent show first.
        return [...grouped.values()].reverse();
    }

    const guestCount = $derived(
        shows.filter((rows) => rows[0].artistid !== 1).length,
    );

    const visibleShows = $derived(
        guestAppearances.shown
            ? shows
            : shows.filter((rows) => rows[0].artistid === 1),
    );

    const livePoll = createLivePoll({
        activeInterval: clientSyncActiveInterval,
        onStale: (status) => {
            if (status.year === null) {
                return;
            }

            http.get(setlistsForYear.url(status.year), {
                onSuccess: (response) => {
                    shows = groupIntoShows(response.data);
                },
            });
        },
    });

    onMount(() => {
        http.get(currentYearSetlists.url(), {
            onSuccess: (response) => {
                shows = groupIntoShows(response.data);
                loaded = true;
            },
        });

        livePoll.start();

        const stopTracking = scrollMemory.track();

        return () => {
            stopTracking();
            livePoll.stop();
        };
    });

    // The setlists arrive over XHR after mount, so `loaded` is the first moment
    // the document is tall enough to hold the offset the visitor left at.
    $effect(() => {
        if (loaded) {
            scrollMemory.restore();
        }
    });
</script>

<AppHead title="Recent Setlists" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Recent Setlists</h1>
        <p class="text-muted-foreground">
            Pulling this year's setlists via the Phish.net API
        </p>
    </div>

    {#if loaded}
        <div
            class="flex items-center gap-2 text-sm text-muted-foreground"
            aria-live="polite"
        >
            {#if livePoll.inShowWindow}
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
            {/if}
        </div>

        <GuestAppearancesToggle count={guestCount} />
    {/if}

    {#if !loaded}
        <p class="text-sm text-muted-foreground">Loading…</p>
    {:else if !visibleShows.length}
        <p class="text-sm text-muted-foreground">No setlist data available.</p>
    {:else}
        <div class="max-w-2xl">
            {#each visibleShows as rows (rows[0].showid)}
                <SetlistView
                    {rows}
                    awaitingNextSong={livePoll.inShowWindow &&
                        rows[0].showdate === livePoll.activeShowdate}
                    onSongClick={openSongDialog}
                />
            {/each}
        </div>
    {/if}
</div>

<SongHistoryDialog
    bind:open={songDialogOpen}
    slug={songDialogSlug}
    songName={songDialogName}
    tourId={songDialogTourId}
    tourName={songDialogTourName}
/>
