<script lang="ts">
    import { page, router, useHttp } from '@inertiajs/svelte';
    import Search from 'lucide-svelte/icons/search';
    import { search } from '@/actions/App/Http/Controllers/AppController';
    import SongHistoryDialog from '@/components/SongHistoryDialog.svelte';
    import { writePrefsCookie } from '@/lib/prefs-cookie';
    import { searchState } from '@/lib/search.svelte';

    type SongHit = {
        song: string;
        slug: string;
        artist: string | null;
        times_played: number;
    };
    type ShowHit = {
        showdate: string;
        artist_name: string | null;
        venuename: string | null;
        city: string | null;
        state: string | null;
        country: string | null;
    };

    /*
     * `collapsed` shrinks the box to just its magnifying glass; focusing it
     * (tapping the icon) flips `expanded` so the parent can make room.
     */
    let {
        collapsed = false,
        expanded = $bindable(false),
    }: { collapsed?: boolean; expanded?: boolean } = $props();

    const DEBOUNCE_MS = 200;

    /*
     * On the song checker the box filters the song grid in place (the page
     * reads `searchState`); everywhere else it drops down quick results.
     */
    const filtersPage = $derived(page.url.split('?')[0] === '/');

    let focused = $state(false);
    let songs = $state<SongHit[]>([]);
    let shows = $state<ShowHit[]>([]);
    let searched = $state('');

    let dialogOpen = $state(false);
    let dialogSlug = $state<string | null>(null);
    let dialogName = $state('');

    const http = useHttp<
        Record<string, never>,
        { data: { songs: SongHit[]; shows: ShowHit[] } }
    >({});

    const trimmed = $derived(searchState.query.trim());
    const showPanel = $derived(!filtersPage && focused && trimmed.length >= 2);

    $effect(() => {
        const term = trimmed;

        if (filtersPage || term.length < 2) {
            songs = [];
            shows = [];
            searched = '';

            return;
        }

        const timer = setTimeout(() => {
            http.get(search.url({ query: { q: term } }), {
                onSuccess: (response) => {
                    // Ignore a slow answer to a term that has been typed over.
                    if (term !== searchState.query.trim()) {
                        return;
                    }

                    songs = response.data.songs;
                    shows = response.data.shows;
                    searched = term;
                },
            });
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    });

    function openSong(hit: SongHit) {
        dialogSlug = hit.slug;
        dialogName = hit.song;
        dialogOpen = true;
        focused = false;
    }

    function openShow(hit: ShowHit) {
        const year = hit.showdate.slice(0, 4);

        writePrefsCookie('setlist-browser-prefs', {
            year,
            showdate: hit.showdate,
        });
        focused = false;
        searchState.query = '';
        router.visit('/setlist-browser', { preserveState: false });
    }

    const place = (hit: ShowHit) =>
        [hit.venuename, hit.city, hit.state].filter(Boolean).join(', ');
</script>

<div
    class={[
        'relative ml-auto min-w-0',
        collapsed ? 'w-9 shrink-0' : 'w-full max-w-xs',
    ]}
>
    <Search
        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
    />
    <input
        type="search"
        bind:value={searchState.query}
        onfocus={() => {
            focused = true;
            expanded = true;
        }}
        onblur={() =>
            setTimeout(() => {
                focused = false;
                expanded = false;
            }, 150)}
        onkeydown={(event) => event.key === 'Escape' && (focused = false)}
        placeholder={collapsed ? '' : 'Search songs, venues, artists, dates…'}
        aria-label="Search"
        class={[
            'h-9 w-full rounded-md border bg-transparent text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50',
            collapsed
                ? 'cursor-pointer border-transparent pr-0 pl-9 hover:bg-accent'
                : 'border-input pr-3 pl-8',
        ]}
    />

    {#if showPanel}
        <div
            class="absolute right-0 z-30 mt-1 max-h-[70vh] w-[min(24rem,calc(100vw-1rem))] overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
            {#if searched !== trimmed}
                <p class="p-3 text-sm text-muted-foreground">Searching…</p>
            {:else if !songs.length && !shows.length}
                <p class="p-3 text-sm text-muted-foreground">No results.</p>
            {/if}

            {#if songs.length}
                <p
                    class="px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground"
                >
                    Songs
                </p>
                {#each songs as hit (hit.slug)}
                    <button
                        type="button"
                        onmousedown={(event) => event.preventDefault()}
                        onclick={() => openSong(hit)}
                        class="flex w-full cursor-pointer items-baseline justify-between gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                    >
                        <span class="truncate">{hit.song}</span>
                        <span class="shrink-0 text-xs text-muted-foreground">
                            {hit.artist && hit.artist !== 'Phish'
                                ? hit.artist
                                : `${hit.times_played}×`}
                        </span>
                    </button>
                {/each}
            {/if}

            {#if shows.length}
                <p
                    class="px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground"
                >
                    Shows
                </p>
                {#each shows as hit (hit.showdate + (hit.venuename ?? '') + (hit.artist_name ?? ''))}
                    <button
                        type="button"
                        onmousedown={(event) => event.preventDefault()}
                        onclick={() => openShow(hit)}
                        class="flex w-full cursor-pointer flex-col rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                    >
                        <span class="truncate">
                            {hit.showdate}
                            {#if hit.artist_name && hit.artist_name !== 'Phish'}
                                · {hit.artist_name}
                            {/if}
                        </span>
                        <span class="truncate text-xs text-muted-foreground">
                            {place(hit)}
                        </span>
                    </button>
                {/each}
            {/if}
        </div>
    {/if}
</div>

<SongHistoryDialog
    bind:open={dialogOpen}
    slug={dialogSlug}
    songName={dialogName}
/>
