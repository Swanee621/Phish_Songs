<script module lang="ts">
    import { setlistBrowser } from '@/actions/App/Http/Controllers/PhishNetExamplesController';

    export const layout = {
        breadcrumbs: [{ title: 'Setlist Browser', href: setlistBrowser() }],
    };
</script>

<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { setlistForDate, setlistsForYear, showYears } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import SetlistView from '@/components/phishnet/SetlistView.svelte';
    import { Badge } from '@/components/ui/badge';
    import { Button } from '@/components/ui/button';
    import { Input } from '@/components/ui/input';
    import type { ShowYear, SetlistRow } from '@/types/phishnet';

    let years = $state<string[]>([]);
    let yearsLoaded = $state(false);
    let selectedYear = $state<string | null>(null);
    let yearShows = $state<SetlistRow[][]>([]);
    let yearLoading = $state(false);

    let showdate = $state('');
    let rows = $state<SetlistRow[] | null>(null);
    let loading = $state(false);
    let notFound = $state(false);

    const yearsHttp = useHttp<Record<string, never>, { data: ShowYear[] }>({});
    const yearShowsHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>({});
    const dateHttp = useHttp<Record<string, never>, { data: SetlistRow[] }>({});

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
                .sort((a, b) => Number(b) - Number(a));
            yearsLoaded = true;
        },
    });

    function loadYear(year: string) {
        selectedYear = year;
        yearLoading = true;
        yearShows = [];
        rows = null;

        yearShowsHttp.get(setlistsForYear.url(year), {
            onSuccess: (response) => {
                const grouped = new SvelteMap<number, SetlistRow[]>();

                for (const row of response.data) {
                    if (row.artistid !== 1) {
                        continue;
                    }

                    const existing = grouped.get(row.showid);

                    if (existing) {
                        existing.push(row);
                    } else {
                        grouped.set(row.showid, [row]);
                    }
                }

                yearShows = [...grouped.values()];
                yearLoading = false;
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
                loading = false;
            },
        });
    }
</script>

<AppHead title="Setlist Browser" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Setlist Browser</h1>
        <p class="text-muted-foreground">Look up any Phish setlist by date, or browse by year</p>
    </div>

    <form
        class="flex max-w-sm gap-2"
        onsubmit={(e) => {
            e.preventDefault();
            loadDate(showdate);
        }}
    >
        <Input type="date" bind:value={showdate} />
        <Button type="submit" disabled={loading}>{loading ? 'Loading…' : 'Load'}</Button>
    </form>

    <div>
        <h2 class="mb-2 text-sm font-semibold text-muted-foreground">Browse by year</h2>
        <div class="flex flex-wrap gap-1.5">
            {#if !yearsLoaded}
                <span class="text-sm text-muted-foreground">Loading years…</span>
            {:else}
                {#each years as year (year)}
                    <button type="button" onclick={() => loadYear(year)}>
                        <Badge variant={selectedYear === year ? 'default' : 'secondary'} class="cursor-pointer">
                            {year}
                        </Badge>
                    </button>
                {/each}
            {/if}
        </div>
    </div>

    {#if selectedYear}
        <div>
            <h2 class="mb-2 text-sm font-semibold text-muted-foreground">Shows in {selectedYear}</h2>
            <div class="flex flex-wrap gap-1.5">
                {#if yearLoading}
                    <span class="text-sm text-muted-foreground">Loading shows…</span>
                {:else if !yearShows.length}
                    <span class="text-sm text-muted-foreground">No shows found.</span>
                {:else}
                    {#each yearShows as show (show[0].showid)}
                        <button type="button" onclick={() => loadDate(show[0].showdate)}>
                            <Badge variant={showdate === show[0].showdate ? 'default' : 'secondary'} class="cursor-pointer">
                                {show[0].showdate}
                            </Badge>
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
            <p class="text-sm text-muted-foreground">No setlist found for {showdate}.</p>
        {:else if rows}
            <SetlistView {rows} />
        {/if}
    </div>
</div>
