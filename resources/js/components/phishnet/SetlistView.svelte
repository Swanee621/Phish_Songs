<script lang="ts">
    import LoaderCircle from 'lucide-svelte/icons/loader-circle';
    import { SvelteMap } from 'svelte/reactivity';
    import type { SetlistRow } from '@/types/phishnet';

    let {
        rows,
        awaitingNextSong = false,
    }: { rows: SetlistRow[]; awaitingNextSong?: boolean } = $props();

    const setLabels: Record<string, string> = {
        '1': 'Set 1',
        '2': 'Set 2',
        '3': 'Set 3',
        e: 'Encore',
        e2: 'Encore 2',
    };

    const first = $derived(rows[0]);

    const sets = $derived.by(() => {
        const grouped = new SvelteMap<string, SetlistRow[]>();

        for (const row of rows) {
            const existing = grouped.get(row.set);

            if (existing) {
                existing.push(row);
            } else {
                grouped.set(row.set, [row]);
            }
        }

        return [...grouped.entries()];
    });

    const notes = $derived(first?.setlistnotes?.trim());
</script>

{#if first}
    <div class="mb-8">
        <h3 class="mb-2 font-serif text-lg font-medium">
            {first.showdate} &mdash;
            <a
                href={first.permalink}
                target="_blank"
                rel="noopener"
                class="text-primary underline decoration-primary/30 underline-offset-4"
            >
                {first.venue}
            </a>
            <span class="text-sm font-normal text-muted-foreground">
                {first.city}, {first.state}{first.country !== 'USA' ? `, ${first.country}` : ''}
            </span>
        </h3>

        {#each sets as [key, songs], setIndex (key)}
            <h4 class="mt-3 mb-1 text-sm font-semibold text-muted-foreground">
                {setLabels[key] ?? `Set ${key}`}
            </h4>
            <p class="text-sm leading-7">
                {#each songs as song, i (song.slug + i)}<a
                        href={`https://phish.net/song/${song.slug}`}
                        target="_blank"
                        rel="noopener"
                        class="hover:text-primary hover:underline">{song.song}</a
                    >{i < songs.length - 1 ? song.trans_mark || ', ' : ''}{/each}{#if awaitingNextSong && setIndex === sets.length - 1}<span
                        class="ml-1 inline-flex items-center align-middle text-muted-foreground"
                        title="Waiting for the next song…"
                        aria-label="Waiting for the next song"
                    >
                        &nbsp;<LoaderCircle class="size-4 animate-spin" />
                    </span>{/if}
            </p>
        {/each}

        {#if notes}
            <p class="mt-3 text-sm text-muted-foreground">{@html notes}</p>
        {/if}
    </div>
{/if}
