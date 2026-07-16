<script module lang="ts">
    import { venueExplorer } from '@/actions/App/Http/Controllers/PhishNetExamplesController';

    export const layout = {
        breadcrumbs: [{ title: 'Venue Explorer', href: venueExplorer() }],
    };
</script>

<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import { venueShows, venues } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppHead from '@/components/AppHead.svelte';
    import { Input } from '@/components/ui/input';
    import type { Venue, VenueShow } from '@/types/phishnet';

    let allVenues = $state<Venue[]>([]);
    let venuesLoaded = $state(false);
    let filter = $state('');

    let selectedVenue = $state<Venue | null>(null);
    let shows = $state<VenueShow[]>([]);
    let showsLoaded = $state(false);

    const venuesHttp = useHttp<Record<string, never>, { data: Venue[] }>({});
    const showsHttp = useHttp<Record<string, never>, { data: VenueShow[] }>({});

    venuesHttp.get(venues.url(), {
        onSuccess: (response) => {
            allVenues = response.data.sort((a, b) => a.venuename.localeCompare(b.venuename));
            venuesLoaded = true;
        },
    });

    const filteredVenues = $derived.by(() => {
        const lower = filter.toLowerCase();
        const matches = lower
            ? allVenues.filter(
                  (venue) =>
                      venue.venuename?.toLowerCase().includes(lower) ||
                      venue.city?.toLowerCase().includes(lower) ||
                      venue.state?.toLowerCase().includes(lower) ||
                      venue.country?.toLowerCase().includes(lower),
              )
            : allVenues.slice(0, 80);

        return matches.slice(0, 200);
    });

    function selectVenue(venue: Venue) {
        selectedVenue = venue;
        showsLoaded = false;
        shows = [];

        showsHttp.get(venueShows.url(venue.venueid), {
            onSuccess: (response) => {
                shows = response.data.filter((show) => show.artistid === 1);
                showsLoaded = true;
            },
        });
    }
</script>

<AppHead title="Venue Explorer" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Venue Explorer</h1>
        <p class="text-muted-foreground">Every venue Phish has played, and when</p>
    </div>

    <Input type="text" placeholder="Search venues…" bind:value={filter} class="max-w-sm" />

    <div class="flex max-h-64 max-w-2xl flex-col gap-1 overflow-y-auto rounded-md border p-2">
        {#if !venuesLoaded}
            <span class="p-2 text-sm text-muted-foreground">Loading venues…</span>
        {:else if !filteredVenues.length}
            <span class="p-2 text-sm text-muted-foreground">No matching venues.</span>
        {:else}
            {#each filteredVenues as venue (venue.venueid)}
                <button
                    type="button"
                    onclick={() => selectVenue(venue)}
                    class="flex items-baseline justify-between rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                    class:bg-accent={selectedVenue?.venueid === venue.venueid}
                >
                    <span>{venue.venuename}</span>
                    <span class="text-xs text-muted-foreground">
                        {venue.city}, {venue.state}{venue.country !== 'USA' ? `, ${venue.country}` : ''}
                    </span>
                </button>
            {/each}
        {/if}
    </div>

    {#if selectedVenue}
        <div class="max-w-2xl">
            <h2 class="font-serif text-xl font-medium">
                <a
                    href={`https://phish.net/venue/${selectedVenue.venueid}`}
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline decoration-primary/30 underline-offset-4"
                >
                    {selectedVenue.venuename}
                </a>
            </h2>
            <p class="mb-4 text-sm text-muted-foreground">
                {selectedVenue.city}, {selectedVenue.state}{selectedVenue.country !== 'USA' ? `, ${selectedVenue.country}` : ''}
            </p>

            {#if !showsLoaded}
                <p class="text-sm text-muted-foreground">Loading shows…</p>
            {:else if !shows.length}
                <p class="text-sm text-muted-foreground">No Phish shows found at this venue.</p>
            {:else}
                <p class="mb-2 text-sm text-muted-foreground">{shows.length} show{shows.length !== 1 ? 's' : ''}</p>
                <div class="divide-y">
                    {#each shows as show (show.showdate)}
                        <div class="flex items-baseline gap-4 py-2">
                            <span class="text-sm font-medium whitespace-nowrap">
                                <a
                                    href={show.permalink ?? `https://phish.net/setlists/?d=${show.showdate}`}
                                    target="_blank"
                                    rel="noopener"
                                    class="hover:text-primary hover:underline"
                                >
                                    {show.showdate}
                                </a>
                            </span>
                            <span class="text-sm text-muted-foreground">{show.tourname ?? ''}</span>
                        </div>
                    {/each}
                </div>
            {/if}
        </div>
    {/if}
</div>
