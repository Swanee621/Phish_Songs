<script lang="ts">
    import type { Snippet } from 'svelte';
    import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.svelte';
    import PlainLayout from '@/layouts/app/PlainLayout.svelte';
    import type { BreadcrumbItem } from '@/types';

    let {
        breadcrumbs = [],
        showSidebar = true,
        children,
    }: {
        breadcrumbs?: BreadcrumbItem[];
        showSidebar?: boolean;
        children?: Snippet;
    } = $props();
</script>

{#if showSidebar}
    <AppSidebarLayout {breadcrumbs}>
        {@render children?.()}
    </AppSidebarLayout>
{:else}
    <PlainLayout>
        {@render children?.()}
    </PlainLayout>
{/if}
