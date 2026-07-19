<script module lang="ts">
    import { recentSetlists } from '@/actions/App/Http/Controllers/PhishNetExamplesController';

    export const layout = {
        breadcrumbs: [{ title: 'Recent Setlists', href: recentSetlists() }],
    };
</script>

<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { onMount } from 'svelte';
    import { SvelteMap } from 'svelte/reactivity';
    import { currentYearSetlists } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/phishnet/SetlistView.svelte';
    import type { SetlistRow } from '@/types/phishnet';

    let shows = $state<SetlistRow[][]>([]);
    let loaded = $state(false);

    const http = useHttp<Record<string, never>, { data: SetlistRow[] }>({});

    onMount(() => {
        http.get(currentYearSetlists.url(), {
            onSuccess: (response) => {
                const rows = response.data.filter((row) => row.artistid === 1);

                const grouped = new SvelteMap<number, SetlistRow[]>();

                for (const row of rows) {
                    const existing = grouped.get(row.showid);

                    if (existing) {
                        existing.push(row);
                    } else {
                        grouped.set(row.showid, [row]);
                    }
                }

                shows = [...grouped.values()].reverse();
                loaded = true;
            },
        });
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

    {#if !loaded}
        <p class="text-sm text-muted-foreground">Loading…</p>
    {:else if !shows.length}
        <p class="text-sm text-muted-foreground">No setlist data available.</p>
    {:else}
        <div class="max-w-2xl">
            {#each shows as rows (rows[0].showid)}
                <SetlistView {rows} />
            {/each}
        </div>
    {/if}
</div>
