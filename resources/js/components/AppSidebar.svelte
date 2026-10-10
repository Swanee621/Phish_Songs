<script lang="ts">
    import { Link, page, router } from '@inertiajs/svelte';
    import BarChart3 from 'lucide-svelte/icons/bar-chart-3';
    import CalendarDays from 'lucide-svelte/icons/calendar-days';
    import ListMusic from 'lucide-svelte/icons/list-music';
    import LogOut from 'lucide-svelte/icons/log-out';
    import MapIcon from 'lucide-svelte/icons/map';
    import type { Component, SvelteComponent } from 'svelte';
    import { cubicOut } from 'svelte/easing';
    import { fade, fly } from 'svelte/transition';
    import {
        recentSetlists,
        setlistBrowser,
    } from '@/actions/App/Http/Controllers/AppController';
    import { sidebar } from '@/lib/sidebar.svelte';
    import { home, logout, stats } from '@/routes';

    /** lucide-svelte ships icons in both shapes depending on the build. */
    type NavIcon =
        | Component<{ class?: string }>
        | (new (...args: any[]) => SvelteComponent<{ class?: string }>);

    type NavItem = {
        title: string;
        href: string;
        icon: NavIcon;
    };

    const user = $derived(page.props.auth?.user ?? null);

    /** The stats page only exists for whoever is logged in. */
    const navItems = $derived<NavItem[]>([
        { title: 'Song Checker', href: home().url, icon: BarChart3 },
        {
            title: 'Setlist Browser',
            href: setlistBrowser().url,
            icon: ListMusic,
        },
        {
            title: 'Recent Setlists',
            href: recentSetlists().url,
            icon: CalendarDays,
        },
        ...(user
            ? [{ title: 'Visitor Stats', href: stats().url, icon: MapIcon }]
            : []),
    ]);

    const currentPath = $derived.by(() => {
        try {
            return new URL(page.url, window.location.origin).pathname;
        } catch {
            return page.url;
        }
    });

    /** Only shown on the desktop rail, where the labels are hidden. */
    const showTooltips = $derived(
        sidebar.state === 'collapsed' && !sidebar.isMobile,
    );

    const menuButtonClasses =
        'flex h-16 w-full items-center gap-2 overflow-hidden rounded-md p-2 text-left text-lg outline-hidden ring-sidebar-ring transition-[width,height,padding] hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-2 active:bg-sidebar-accent active:text-sidebar-accent-foreground lg:h-12 lg:text-base group-data-[collapsible=icon]:size-8! group-data-[collapsible=icon]:p-2! [&>span:last-child]:truncate [&>svg]:size-4 [&>svg]:shrink-0';

    const activeClasses =
        'bg-sidebar-accent font-medium text-sidebar-accent-foreground';
</script>

{#snippet tooltip(title: string)}
    {#if showTooltips}
        <span
            class="pointer-events-none absolute top-1/2 left-full z-50 ml-2 w-fit -translate-y-1/2 rounded-md bg-foreground px-3 py-1.5 text-xs text-balance text-background opacity-0 transition-opacity group-hover/menu-item:opacity-100 group-focus-within/menu-item:opacity-100"
            role="tooltip"
        >
            <span
                class="absolute top-1/2 -left-1 size-2 -translate-y-1/2 rotate-45 bg-foreground"
            ></span>
            {title}
        </span>
    {/if}
{/snippet}

{#snippet nav()}
    <div
        class="flex min-h-0 flex-1 flex-col gap-2 overflow-auto group-data-[collapsible=icon]:overflow-hidden"
    >
        <div class="relative flex w-full min-w-0 flex-col p-2">
            <ul class="flex w-full min-w-0 flex-col gap-1">
                {#each navItems as item (item.href)}
                    <li class="group/menu-item relative">
                        <Link
                            href={item.href}
                            class="{menuButtonClasses} {currentPath ===
                            item.href
                                ? activeClasses
                                : ''}"
                        >
                            <item.icon class="size-4 shrink-0" />
                            <span>{item.title}</span>
                        </Link>

                        {@render tooltip(item.title)}
                    </li>
                {/each}
            </ul>
        </div>

        {#if user}
            <div class="mt-auto p-2">
                <div class="group/menu-item relative">
                    <button
                        type="button"
                        class={menuButtonClasses}
                        onclick={() => router.post(logout.url())}
                    >
                        <LogOut class="size-4 shrink-0" />
                        <span>Log out</span>
                    </button>

                    {@render tooltip('Log out')}
                </div>
            </div>
        {/if}
    </div>
{/snippet}

{#if sidebar.isMobile}
    {#if sidebar.openMobile}
        <div class="fixed inset-0 z-50">
            <button
                type="button"
                class="fixed inset-0 bg-black/50"
                aria-label="Close"
                onclick={() => sidebar.closeMobile()}
                transition:fade={{ duration: 200 }}
            ></button>
            <div
                class="fixed inset-y-0 left-0 h-svh w-(--sidebar-width) border-r bg-sidebar p-0 text-sidebar-foreground"
                style="--sidebar-width: 18rem;"
                transition:fly={{
                    x: -320,
                    duration: 300,
                    opacity: 1,
                    easing: cubicOut,
                }}
            >
                <div class="flex h-full w-full flex-col">
                    {@render nav()}
                </div>
            </div>
        </div>
    {/if}
{:else}
    <!--
        The data attributes are what the layout styles against: the inset main
        reads `peer-data-[state]` for its margin, and the rail's own widths and
        the header height key off `data-collapsible`.
    -->
    <div
        class="group peer hidden text-sidebar-foreground md:block"
        data-state={sidebar.state}
        data-collapsible={sidebar.collapsed ? 'icon' : ''}
        data-variant="inset"
    >
        <!-- Reserves the width the fixed panel below takes out of flow. -->
        <div
            class="relative w-(--sidebar-width) bg-transparent transition-[width] duration-200 ease-linear group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]"
        ></div>
        <div
            class="fixed inset-y-0 left-0 z-10 hidden h-svh w-(--sidebar-width) p-2 transition-[left,right,width] duration-200 ease-linear group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)] md:flex"
        >
            <div class="flex h-full w-full flex-col bg-sidebar">
                {@render nav()}
            </div>
        </div>
    </div>
{/if}
