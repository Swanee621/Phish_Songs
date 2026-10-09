<script lang="ts" module>
    /**
     * Handle positions clamped to the bounds, lower never past upper. A `null`
     * handle rests at its own end of the range.
     */
    export function clampRange(
        from: number | null,
        to: number | null,
        min: number,
        max: number,
    ): { from: number; to: number } {
        const clampedFrom = Math.min(Math.max(from ?? min, min), max);

        return {
            from: clampedFrom,
            to: Math.max(Math.min(to ?? max, max), clampedFrom),
        };
    }
</script>

<script lang="ts">
    /**
     * One of the two stacked inputs forming the slider. The shared track is
     * drawn separately underneath, so each input's own track is transparent
     * and only its thumb accepts the pointer — otherwise the input on top
     * would swallow every click meant for the one below.
     */
    const INPUT_CLASSES =
        'pointer-events-none absolute inset-0 h-6 w-full cursor-pointer appearance-none bg-transparent [&::-webkit-slider-runnable-track]:h-2 [&::-webkit-slider-runnable-track]:bg-transparent [&::-webkit-slider-thumb]:pointer-events-auto [&::-webkit-slider-thumb]:-mt-2 [&::-webkit-slider-thumb]:h-6 [&::-webkit-slider-thumb]:w-6 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-moz-range-track]:h-2 [&::-moz-range-track]:bg-transparent [&::-moz-range-thumb]:pointer-events-auto [&::-moz-range-thumb]:h-6 [&::-moz-range-thumb]:w-6 [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-0 [&::-moz-range-thumb]:bg-primary md:h-5 md:[&::-webkit-slider-thumb]:-mt-1.5 md:[&::-webkit-slider-thumb]:h-5 md:[&::-webkit-slider-thumb]:w-5 md:[&::-moz-range-thumb]:h-5 md:[&::-moz-range-thumb]:w-5';

    /**
     * A two-handled range slider. `from` and `to` are `null` while their handle
     * rests at its end of the range — no filter — which lets it follow the
     * bounds if they move instead of pinning to a stale value.
     */
    let {
        min,
        max,
        step = 1,
        from = $bindable(null),
        to = $bindable(null),
        fromLabel,
        toLabel,
        fromId,
    }: {
        min: number;
        max: number;
        step?: number;
        from?: number | null;
        to?: number | null;
        fromLabel: string;
        toLabel: string;
        fromId?: string;
    } = $props();

    const range = $derived(clampRange(from, to, min, max));

    const percentOf = (value: number): number =>
        max === min ? 0 : ((value - min) / (max - min)) * 100;

    /**
     * When both handles sit together, only the input on top can be grabbed.
     * Raising the lower handle whenever it is past the midpoint means the
     * grabbable one is always the handle that still has somewhere to go.
     */
    const fromOnTop = $derived(range.from > (min + max) / 2);

    /**
     * The handles are clamped so they cannot cross, and a handle pushed back to
     * its own end of the range dissolves into "no filter". The DOM value is
     * written back because Svelte only re-renders `value` when the clamped
     * result changes — a thumb dragged past the other handle would otherwise
     * leave the DOM ahead of the state.
     */
    function onFromInput(event: Event) {
        const input = event.currentTarget as HTMLInputElement;
        const clamped = Math.min(Number(input.value), range.to);

        from = clamped <= min ? null : clamped;
        input.value = String(clamped);
    }

    function onToInput(event: Event) {
        const input = event.currentTarget as HTMLInputElement;
        const clamped = Math.max(Number(input.value), range.from);

        to = clamped >= max ? null : clamped;
        input.value = String(clamped);
    }
</script>

<div class="relative h-6 md:h-5">
    <div
        class="absolute inset-x-0 top-1/2 h-2 -translate-y-1/2 rounded-full bg-secondary"
    ></div>
    <div
        class="absolute top-1/2 h-2 -translate-y-1/2 rounded-full bg-primary/30"
        style="left: {percentOf(range.from)}%; right: {100 -
            (max === min ? 100 : percentOf(range.to))}%"
    ></div>
    <input
        id={fromId}
        type="range"
        aria-label={fromLabel}
        {min}
        {max}
        {step}
        value={range.from}
        oninput={onFromInput}
        class="{INPUT_CLASSES} {fromOnTop ? 'z-30' : 'z-10'}"
    />
    <input
        type="range"
        aria-label={toLabel}
        {min}
        {max}
        {step}
        value={range.to}
        oninput={onToInput}
        class="{INPUT_CLASSES} z-20"
    />
</div>
