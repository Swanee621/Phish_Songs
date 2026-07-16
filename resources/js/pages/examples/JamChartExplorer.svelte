<script module lang="ts">
    import { jamChartExplorer } from '@/actions/App/Http/Controllers/PhishNetExamplesController';

    export const layout = {
        breadcrumbs: [{ title: 'Jam Chart Explorer', href: jamChartExplorer() }],
    };
</script>

<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { SvelteSet } from 'svelte/reactivity';
    import { jamChart, jamCharts } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Input } from '@/components/ui/input';
    import type { JamChartEntry, JamChartSong } from '@/types/phishnet';

    let allSongs = $state<JamChartSong[]>([]);
    let songsLoaded = $state(false);
    let filter = $state('');
    let selectedSlug = $state<string | null>(null);
    let selectedSong = $state('');
    let entries = $state<JamChartEntry[]>([]);
    let entriesLoaded = $state(false);

    const songsHttp = useHttp<Record<string, never>, { data: JamChartSong[] }>({});
    const entriesHttp = useHttp<Record<string, never>, { data: JamChartEntry[] }>({});

    songsHttp.get(jamCharts.url(), {
        onSuccess: (response) => {
            const seen = new SvelteSet<string>();
            allSongs = response.data
                .reduce<JamChartSong[]>((songs, entry) => {
                    if (!seen.has(entry.slug)) {
                        seen.add(entry.slug);
                        songs.push({ slug: entry.slug, song: entry.song });
                    }

                    return songs;
                }, [])
                .sort((a, b) => a.song.localeCompare(b.song));
            songsLoaded = true;
        },
    });

    const filteredSongs = $derived(
        filter
            ? allSongs.filter((song) => song.song.toLowerCase().includes(filter.toLowerCase()))
            : allSongs,
    );

    function selectSong(song: JamChartSong) {
        selectedSlug = song.slug;
        selectedSong = song.song;
        entriesLoaded = false;
        entries = [];

        entriesHttp.get(jamChart.url(song.slug), {
            onSuccess: (response) => {
                entries = response.data;
                entriesLoaded = true;
            },
        });
    }
</script>

<AppHead title="Jam Chart Explorer" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Jam Chart Explorer</h1>
        <p class="text-muted-foreground">Browse notable jams via the Phish.net API</p>
    </div>

    <Input type="text" placeholder="Filter songs…" bind:value={filter} class="max-w-sm" />

    <div class="flex flex-wrap gap-1.5">
        {#if !songsLoaded}
            <span class="text-sm text-muted-foreground">Loading songs…</span>
        {:else if !filteredSongs.length}
            <span class="text-sm text-muted-foreground">No matching songs.</span>
        {:else}
            {#each filteredSongs as song (song.slug)}
                <button type="button" onclick={() => selectSong(song)}>
                    <Badge variant={selectedSlug === song.slug ? 'default' : 'secondary'} class="cursor-pointer">
                        {song.song}
                    </Badge>
                </button>
            {/each}
        {/if}
    </div>

    {#if selectedSlug}
        <div class="mt-4">
            <h2 class="mb-4 font-serif text-xl font-medium">
                <a
                    href={`https://phish.net/song/${selectedSlug}`}
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline decoration-primary/30 underline-offset-4"
                >
                    {selectedSong}
                </a>
                &mdash; Jam Chart
            </h2>

            {#if !entriesLoaded}
                <p class="text-sm text-muted-foreground">Loading…</p>
            {:else if !entries.length}
                <p class="text-sm text-muted-foreground">No jam chart entries found.</p>
            {:else}
                {#each entries as entry (entry.showdate)}
                    <div class="mb-6 border-b pb-6 last:border-b-0">
                        <h3 class="font-serif text-lg font-medium">
                            <a
                                href={entry.permalink ?? `https://phish.net/setlists/phish-${entry.showdate}`}
                                target="_blank"
                                rel="noopener"
                                class="text-primary underline decoration-primary/30 underline-offset-4"
                            >
                                {entry.showdate}
                            </a>
                        </h3>
                        <div class="mb-2 text-sm text-muted-foreground">
                            {entry.venue ?? ''}{entry.city ? `, ${entry.city}` : ''}{entry.state ? `, ${entry.state}` : ''}{entry.tracktime
                                ? ` · ${entry.tracktime}`
                                : ''}
                        </div>
                        {#if entry.jamchart_description}
                            <div class="text-sm leading-7">{entry.jamchart_description}</div>
                        {/if}
                    </div>
                {/each}
            {/if}
        </div>
    {/if}
</div>
