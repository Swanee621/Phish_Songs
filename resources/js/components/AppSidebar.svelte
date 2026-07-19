<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import BarChart3 from 'lucide-svelte/icons/bar-chart-3';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import Guitar from 'lucide-svelte/icons/guitar';
    import ListMusic from 'lucide-svelte/icons/list-music';
    import MapPin from 'lucide-svelte/icons/map-pin';
    import type { Snippet } from 'svelte';
    import {
        jamChartExplorer,
        recentSetlists,
        setlistBrowser,
        venueExplorer,
    } from '@/actions/App/Http/Controllers/PhishNetExamplesController';
    import AppLogo from '@/components/AppLogo.svelte';
    import NavMain from '@/components/NavMain.svelte';
    import NavUser from '@/components/NavUser.svelte';
    import {
        Sidebar,
        SidebarContent,
        SidebarFooter,
        SidebarHeader,
    } from '@/components/ui/sidebar';
    import { toUrl } from '@/lib/utils';
    import { home, tourExplorer } from '@/routes';
    import type { NavItem } from '@/types';

    let {
        children,
    }: {
        children?: Snippet;
    } = $props();

    const mainNavItems: NavItem[] = [
        {
            title: 'Jam Chart Explorer',
            href: jamChartExplorer(),
            icon: Guitar,
        },
        {
            title: 'Recent Setlists',
            href: recentSetlists(),
            icon: CalendarDays,
        },
        {
            title: 'Setlist Browser',
            href: setlistBrowser(),
            icon: ListMusic,
        },
        {
            title: 'Venue Explorer',
            href: venueExplorer(),
            icon: MapPin,
        },
        {
            title: 'Tour Explorer',
            href: tourExplorer(),
            icon: BarChart3,
        },
    ];
</script>

<Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
        <Link href={toUrl(home())} class="flex items-center justify-center">
            <AppLogo />
        </Link>
    </SidebarHeader>

    <SidebarContent>
        <NavMain items={mainNavItems} />
    </SidebarContent>

    <SidebarFooter>
        <NavUser />
    </SidebarFooter>
</Sidebar>
{@render children?.()}
