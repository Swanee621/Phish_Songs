<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import PanelLeftClose from 'lucide-svelte/icons/panel-left-close';
    import PanelLeftOpen from 'lucide-svelte/icons/panel-left-open';
    import { onMount } from 'svelte';
    import type { Snippet } from 'svelte';
    import AppSidebar from '@/components/AppSidebar.svelte';
    import { sidebar } from '@/lib/sidebar.svelte';

    let { children }: { children?: Snippet } = $props();

    const sidebarEnabled = $derived(page.props.sidebarEnabled);

    if (page.props.sidebarEnabled && typeof window !== 'undefined') {
        sidebar.restore(!page.props.sidebarCollapsed);
    }

    onMount(() => {
        if (!page.props.sidebarEnabled) {
            return;
        }

        return sidebar.listen();
    });
</script>

{#if sidebarEnabled}
    <div
        class="group/sidebar-wrapper flex min-h-svh w-full has-data-[variant=inset]:bg-sidebar"
        style="--sidebar-width: 16rem; --sidebar-width-icon: 3rem;"
    >
        <AppSidebar />

        <main
            class="relative flex w-full flex-1 flex-col overflow-x-hidden bg-background md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-xl md:peer-data-[variant=inset]:shadow-sm md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-2"
        >
            <header
                class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
            >
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="-ml-1 inline-flex h-14 w-14 items-center justify-center gap-2 rounded-md text-sm font-medium whitespace-nowrap transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none md:h-12 md:w-12"
                        onclick={() => sidebar.toggle()}
                    >
                        {#if sidebar.isMobile || sidebar.collapsed}
                            <PanelLeftOpen class="size-6" />
                        {:else}
                            <PanelLeftClose class="size-6" />
                        {/if}
                        <span class="sr-only">Toggle sidebar</span>
                    </button>
                </div>
            </header>

            {@render children?.()}
        </main>
    </div>
{:else}
    <div class="flex min-h-screen w-full flex-col">
        <main class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col">
            {@render children?.()}
        </main>
    </div>
{/if}
