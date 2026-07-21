<script lang="ts">
    import { getContext } from 'svelte';
    import { PanelLeftClose, PanelLeftOpen } from 'lucide-svelte';
    import { Button } from '@/components/ui/button';
    import { cn } from '@/lib/utils';
    import { SIDEBAR_CONTEXT, type SidebarContext } from './context';

    let { class: className = '' } = $props();

    const { isMobile, state, toggleSidebar } = getContext<SidebarContext>(SIDEBAR_CONTEXT);
</script>

<Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    class={cn('h-14 w-14 md:h-12 md:w-12', className)}
    onclick={toggleSidebar}
>
    {#if $isMobile || $state === 'collapsed'}
        <PanelLeftOpen class="size-6" />
    {:else}
        <PanelLeftClose class="size-6" />
    {/if}
    <span class="sr-only">Toggle sidebar</span>
</Button>
