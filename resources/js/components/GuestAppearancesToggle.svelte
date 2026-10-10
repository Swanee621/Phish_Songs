<script lang="ts">
    import type { GuestAppearancesSetting } from '@/lib/guest-appearances.svelte';

    /**
     * How many guest appearances the unfiltered list holds, so the checkbox can
     * say what it is hiding. Nothing is rendered when the list has none — a
     * control for something not on screen is only noise. `null` shows it
     * regardless, without a count, for a page where it filters more than one
     * list at once. `setting` is the page's own guest-appearances preference.
     */
    let {
        setting,
        count = 0,
    }: { setting: GuestAppearancesSetting; count?: number | null } = $props();
</script>

{#if count === null || count > 0}
    <label
        class="flex w-fit cursor-pointer items-center gap-2 text-sm text-muted-foreground select-none"
    >
        <input
            type="checkbox"
            checked={setting.shown}
            onchange={(event) => (setting.shown = event.currentTarget.checked)}
            class="size-4 cursor-pointer rounded border-input accent-primary"
        />
        Guest appearances{count === null ? '' : ` (${count})`}
    </label>
{/if}
