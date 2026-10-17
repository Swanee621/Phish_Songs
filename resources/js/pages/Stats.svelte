<script lang="ts">
    import { useHttp } from '@inertiajs/svelte';
    import type * as Leaflet from 'leaflet';
    import { onMount } from 'svelte';
    import {
        bounds as boundsRoute,
        heatmap as heatmapRoute,
        visits as visitsRoute,
    } from '@/actions/App/Http/Controllers/StatsController';
    import AppHead from '@/components/AppHead.svelte';
    import RangeSlider, { clampRange } from '@/components/RangeSlider.svelte';
    import { dayFromIsoDate, isoDateFromDay } from '@/lib/day';
    import type {
        HeatPoint,
        StatsBounds,
        VisitRow,
        VisitsPage,
    } from '@/types/stats';

    import 'leaflet/dist/leaflet.css';

    const boundsHttp = useHttp<Record<string, never>, { data: StatsBounds }>(
        {},
    );
    const heatmapHttp = useHttp<Record<string, never>, { data: HeatPoint[] }>(
        {},
    );
    const visitsHttp = useHttp<Record<string, never>, VisitsPage>({});

    let bounds = $state<StatsBounds | null>(null);
    let points = $state<HeatPoint[]>([]);
    let visits = $state<VisitRow[]>([]);
    let visitsMeta = $state<VisitsPage['meta'] | null>(null);
    let selectedVisitId = $state<number | null>(null);

    /** Slider handles in days since the epoch; `null` means unbounded. */
    let fromDay = $state<number | null>(null);
    let toDay = $state<number | null>(null);

    const minDay = $derived(bounds?.from ? dayFromIsoDate(bounds.from) : 0);
    const maxDay = $derived(bounds?.to ? dayFromIsoDate(bounds.to) : 0);
    const range = $derived(clampRange(fromDay, toDay, minDay, maxDay));
    const hasVisits = $derived((bounds?.visits ?? 0) > 0);

    const visitsInRange = $derived(
        points.reduce((total, point) => total + point.count, 0),
    );

    const selected = $derived(
        visits.find((row) => row.id === selectedVisitId) ?? null,
    );

    let mapElement = $state<HTMLDivElement | null>(null);
    let L: typeof Leaflet | null = null;
    let map: Leaflet.Map | null = null;
    let heat: Leaflet.HeatLayer | null = null;
    let highlight: Leaflet.LayerGroup | null = null;

    /** The query the slider currently describes, or nothing when unbounded. */
    const rangeQuery = (): { from?: string; to?: string } => ({
        ...(fromDay === null ? {} : { from: isoDateFromDay(range.from) }),
        ...(toDay === null ? {} : { to: isoDateFromDay(range.to) }),
    });

    function loadRange() {
        const query = rangeQuery();
        const unbounded = fromDay === null && toDay === null;

        heatmapHttp.get(heatmapRoute.url({ query }), {
            onSuccess: (response) => {
                points = response.data;

                if (unbounded) {
                    peakCount = Math.max(
                        1,
                        ...points.map((point) => point.count),
                    );
                }

                heat?.setLatLngs(heatPoints(points));
            },
        });

        loadVisits(1);
    }

    function loadVisits(pageNumber: number) {
        visitsHttp.get(
            visitsRoute.url({ query: { ...rangeQuery(), page: pageNumber } }),
            {
                onSuccess: (response) => {
                    visits = response.data;
                    visitsMeta = response.meta;

                    if (!visits.some((row) => row.id === selectedVisitId)) {
                        selectedVisitId = null;
                    }
                },
            },
        );
    }

    /**
     * The busiest city over all time. Intensities are scaled against this
     * rather than the busiest city in the current range, so narrowing the
     * range cools the map instead of re-stretching whatever is left to red.
     */
    let peakCount = $state(1);

    /** Visit counts scaled to 0–1, which is the range the heat layer expects. */
    const heatPoints = (rows: HeatPoint[]): Array<[number, number, number]> =>
        rows.map((point) => [
            point.lat,
            point.lng,
            Math.max(0.08, point.count / peakCount),
        ]);

    /*
     * Leaflet reaches for `window` the moment it is imported, so it only
     * loads once the page is in a browser. The heat plugin then bolts itself
     * onto the global `L` Leaflet installs — not onto the module namespace
     * Vite hands back — so that global is the object to use from here on.
     */
    async function mountMap(element: HTMLDivElement) {
        await import('leaflet');
        await import('leaflet.heat');

        L = (globalThis as unknown as { L: typeof Leaflet }).L;

        map = L.map(element, { worldCopyJump: true }).setView([30, -20], 2);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        /*
         * The plugin dims every point by 2^(maxZoom - zoom), so `maxZoom`
         * is pinned to the opening view: cities read at full strength from
         * a continent out, and the weights above already carry the contrast.
         */
        heat = L.heatLayer(heatPoints(points), {
            radius: 16,
            blur: 10,
            maxZoom: 2,
            minOpacity: 0.15,
            gradient: {
                0.2: '#3b82f6',
                0.45: '#22c55e',
                0.7: '#eab308',
                1: '#ef4444',
            },
        }).addTo(map);
        highlight = L.layerGroup().addTo(map);
    }

    onMount(() => {
        boundsHttp.get(boundsRoute.url(), {
            onSuccess: (response) => {
                bounds = response.data;
                loadRange();
            },
        });

        if (mapElement) {
            mountMap(mapElement).catch((error: unknown) =>
                console.error('Could not set up the visitor map', error),
            );
        }

        return () => {
            map?.remove();
            map = null;
            heat = null;
            highlight = null;
        };
    });

    /*
     * Dragging a handle fires many times a second; the server is asked only
     * once the handle settles.
     */
    let debounce: ReturnType<typeof setTimeout> | undefined;
    let firstRangeRun = true;

    $effect(() => {
        void fromDay;
        void toDay;

        if (firstRangeRun) {
            firstRangeRun = false;

            return;
        }

        clearTimeout(debounce);
        debounce = setTimeout(loadRange, 250);

        return () => clearTimeout(debounce);
    });

    /** Marks the selected visit's city on the map. */
    $effect(() => {
        const row = selected;

        if (!L || !map || !highlight) {
            return;
        }

        highlight.clearLayers();

        if (!row || row.lat === null || row.lng === null) {
            return;
        }

        L.circleMarker([row.lat, row.lng], {
            radius: 8,
            color: '#1d4ed8',
            fillColor: '#ffffff',
            fillOpacity: 1,
            weight: 2,
        })
            .bindTooltip(placeLabel(row))
            .addTo(highlight);

        map.panTo([row.lat, row.lng]);
    });

    const placeLabel = (row: VisitRow | HeatPoint): string =>
        [row.city, row.region, row.country_code].filter(Boolean).join(', ') ||
        'Unknown';

    const formatDate = (iso: string): string =>
        new Date(iso).toLocaleString(undefined, {
            dateStyle: 'medium',
            timeStyle: 'short',
        });

    const tileClasses = 'rounded-lg border bg-card p-3 text-card-foreground';

    const outlineButtonClasses =
        'inline-flex h-10 items-center justify-center gap-2 rounded-md border border-input bg-background px-4 text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50 md:h-8 md:px-3 md:text-xs';
</script>

<AppHead title="Visitor Stats" />

<div class="flex h-full flex-1 flex-col gap-4 p-4">
    <div>
        <h1 class="text-2xl font-semibold">Visitor Stats</h1>
        <p class="text-sm text-muted-foreground">
            Page views by approximate city. No addresses are kept — only the
            place they resolved to. Nothing ties one visit to another.
        </p>
    </div>

    <div class="grid grid-cols-3 gap-3">
        <div class={tileClasses}>
            <p class="text-xs text-muted-foreground">Visits in range</p>
            <p class="text-2xl font-semibold tabular-nums">{visitsInRange}</p>
        </div>
        <div class={tileClasses}>
            <p class="text-xs text-muted-foreground">Visits total</p>
            <p class="text-2xl font-semibold tabular-nums">
                {bounds?.visits ?? '—'}
            </p>
        </div>
        <div class={tileClasses}>
            <p class="text-xs text-muted-foreground">Unplaced visits</p>
            <p class="text-2xl font-semibold tabular-nums">
                {bounds?.unresolved ?? '—'}
            </p>
        </div>
    </div>

    {#if hasVisits && maxDay > minDay}
        <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium">Date range</span>
                <span class="text-muted-foreground tabular-nums">
                    {isoDateFromDay(range.from)} – {isoDateFromDay(range.to)}
                </span>
            </div>
            <RangeSlider
                min={minDay}
                max={maxDay}
                bind:from={fromDay}
                bind:to={toDay}
                fromLabel="Earliest date"
                toLabel="Latest date"
                fromId="stats-from"
            />
        </div>
    {/if}

    <div
        bind:this={mapElement}
        class="z-0 h-[55vh] min-h-80 w-full overflow-hidden rounded-xl border bg-muted"
        aria-label="Visitor heatmap"
    ></div>

    <div class="space-y-2">
        <div class="flex items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold">Visits</h2>
            {#if visitsMeta && visitsMeta.total > 0}
                <span class="text-sm text-muted-foreground tabular-nums">
                    {visitsMeta.total} in range
                </span>
            {/if}
        </div>

        {#if visitsHttp.processing && visits.length === 0}
            <p class="text-sm text-muted-foreground">Loading…</p>
        {:else if visits.length === 0}
            <p class="text-sm text-muted-foreground">
                No visits in this range.
            </p>
        {:else}
            <p class="text-sm text-muted-foreground">
                Select a row to point out its city on the map.
            </p>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 font-medium">When</th>
                            <th class="px-3 py-2 font-medium">City</th>
                            <th class="px-3 py-2 font-medium">Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#each visits as row (row.id)}
                            <tr
                                class="cursor-pointer border-t hover:bg-accent/50 {selectedVisitId ===
                                row.id
                                    ? 'bg-accent'
                                    : ''}"
                                onclick={() =>
                                    (selectedVisitId =
                                        selectedVisitId === row.id
                                            ? null
                                            : row.id)}
                            >
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {formatDate(row.visited_at)}
                                </td>
                                <td class="px-3 py-2">
                                    {placeLabel(row)}
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    {row.path}
                                </td>
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>

            {#if visitsMeta && visitsMeta.lastPage > 1}
                <nav
                    aria-label="Visit pages"
                    class="flex items-center justify-between gap-2"
                >
                    <button
                        type="button"
                        disabled={visitsMeta.page === 1 ||
                            visitsHttp.processing}
                        onclick={() => loadVisits(visitsMeta!.page - 1)}
                        class={outlineButtonClasses}
                    >
                        Previous
                    </button>
                    <span class="text-sm text-muted-foreground">
                        Page {visitsMeta.page} of {visitsMeta.lastPage}
                    </span>
                    <button
                        type="button"
                        disabled={visitsMeta.page === visitsMeta.lastPage ||
                            visitsHttp.processing}
                        onclick={() => loadVisits(visitsMeta!.page + 1)}
                        class={outlineButtonClasses}
                    >
                        Next
                    </button>
                </nav>
            {/if}
        {/if}
    </div>
</div>
