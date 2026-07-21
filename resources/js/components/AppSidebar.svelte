<script lang="ts">
    import BarChart3 from 'lucide-svelte/icons/bar-chart-3';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ListMusic from 'lucide-svelte/icons/list-music';
    import type { Snippet } from 'svelte';
    import {
        recentSetlists,
        setlistBrowser,
    } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
    } from '@/components/ui/sidebar';
    import { home } from '@/routes';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems: NavItem[] = [
        {
            title: 'Song Checker',
            href: home(),
            icon: BarChart3,
        },
        {
            title: 'Setlist Browser',
            href: setlistBrowser(),
            icon: ListMusic,
        },
        {
            title: 'Recent Setlists',
            href: recentSetlists(),
            icon: CalendarDays,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>
    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
