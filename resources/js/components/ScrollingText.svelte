<script lang="ts">
    type Props = {
        text: string;
        /**
         * Pixels travelled per second. Kept low so a long run stays readable
         * rather than racing past.
         */
        speed?: number;
        class?: string;
    };

    let { text, speed = 28, class: className = '' }: Props = $props();

    let container = $state<HTMLElement | null>(null);
    let content = $state<HTMLElement | null>(null);

    /** How many pixels the text spills past its container, 0 when it fits. */
    let overflow = $state(0);

    function measure() {
        if (!container || !content) {
            overflow = 0;

            return;
        }

        overflow = Math.max(0, content.scrollWidth - container.clientWidth);
    }

    // Re-measure whenever the text changes or either box is resized, so the
    // scroll only kicks in when it is actually needed and spans the right gap.
    $effect(() => {
        // Referenced so the effect re-runs — and re-measures — on a new song.
        text;

        measure();

        if (container === null) {
            return;
        }

        const observer = new ResizeObserver(() => measure());

        observer.observe(container);

        if (content !== null) {
            observer.observe(content);
        }

        return () => observer.disconnect();
    });

    // A duration proportional to the distance holds the travel speed constant,
    // so a longer title scrolls for longer rather than faster.
    const duration = $derived(overflow > 0 ? overflow / speed : 0);
</script>

<div bind:this={container} class="overflow-hidden {className}">
    {#key text}
        <div
            bind:this={content}
            class="w-max max-w-none whitespace-nowrap"
            class:scrolling={overflow > 0}
            style="--scroll-distance: {overflow}px; --scroll-duration: {duration}s;"
        >
            {text}
        </div>
    {/key}
</div>

<style>
    .scrolling {
        animation: scroll-back-and-forth var(--scroll-duration) ease-in-out
            infinite alternate;
    }

    /*
     * Sit still for a beat at each end so both ends can be read before it turns
     * around, rather than reversing the instant it arrives.
     */
    @keyframes scroll-back-and-forth {
        0%,
        18% {
            transform: translateX(0);
        }
        82%,
        100% {
            transform: translateX(calc(var(--scroll-distance) * -1));
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .scrolling {
            animation: none;
        }
    }
</style>
