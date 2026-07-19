<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.svelte';
    import PlainLayout from '@/layouts/app/PlainLayout.svelte';
    import type { BreadcrumbItem } from '@/types';

    let {
        breadcrumbs = [],
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        children?: Snippet;
    } = $props();

    const sidebarEnabled = $derived(page.props.sidebarEnabled);
</script>

{#if sidebarEnabled}
    <AppSidebarLayout {breadcrumbs}>
        {@render children?.()}
    </AppSidebarLayout>
{:else}
    <PlainLayout>
        {@render children?.()}
    </PlainLayout>
{/if}
