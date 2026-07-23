<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import {
        currentYearSetlists,
        setlistsForYear,
    } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/SetlistView.svelte';
    import { createLivePoll, formatCountdown } from '@/lib/live-poll.svelte';
    import type { SetlistRow } from '@/types/phishnet';

    let {
        clientSyncInterval = 3600,
        clientSyncActiveInterval = 60,
    }: {
        clientSyncInterval?: number;
        clientSyncActiveInterval?: number;
    } = $props();

    let shows = $state<SetlistRow[][]>([]);
    let loaded = $state(false);

    const http = useHttp<Record<string, never>, { data: SetlistRow[] }>({});

    function groupIntoShows(rows: SetlistRow[]): SetlistRow[][] {
        const phishRows = rows.filter((row) => row.artistid === 1);

        const grouped = new SvelteMap<number, SetlistRow[]>();

        for (const row of phishRows) {
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

    const livePoll = createLivePoll({
        idleInterval: clientSyncInterval,
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

        return () => livePoll.stop();
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
    {/if}

    {#if !loaded}
        <p class="text-sm text-muted-foreground">Loading…</p>
    {:else if !shows.length}
        <p class="text-sm text-muted-foreground">No setlist data available.</p>
    {:else}
        <div class="max-w-2xl">
            {#each shows as rows (rows[0].showid)}
                <SetlistView
                    {rows}
                    awaitingNextSong={livePoll.inShowWindow &&
                        rows[0].showdate === livePoll.activeShowdate}
                />
            {/each}
        </div>
    {/if}
</div>
