<script lang="ts">
    import { guestAppearances } from '@/lib/guest-appearances.svelte';

    /**
     * How many guest appearances the unfiltered list holds, so the checkbox can
     * say what it is hiding. Nothing is rendered when the list has none — a
     * control for something not on screen is only noise.
     */
    let { count = 0 }: { count?: number } = $props();
</script>

{#if count > 0}
    <label
        class="flex w-fit cursor-pointer items-center gap-2 text-sm text-muted-foreground select-none"
    >
        <input
            type="checkbox"
            checked={guestAppearances.shown}
            onchange={(event) =>
                (guestAppearances.shown = event.currentTarget.checked)}
            class="size-4 cursor-pointer rounded border-input accent-primary"
        />
        Guest appearances ({count})
    </label>
{/if}
