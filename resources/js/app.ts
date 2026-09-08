import { createInertiaApp, router } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import { lastVisit } from '@/lib/last-visit';
import { initializeTheme } from '@/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// We put visitors back where they were ourselves, from a stored offset that
// survives the tab closing. The browser's own guess would only fight that.
if (typeof history !== 'undefined' && 'scrollRestoration' in history) {
    history.scrollRestoration = 'manual';
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => AppLayout,
    progress: {
        color: '#4B5563',
    },
}).then(() => {
    // A returning visitor who opens the bare site lands back on the page they
    // were last using. `lastVisit` resolved this before Inertia mounted, so an
    // in-app visit to `/` cannot trigger it. `replace` keeps `/` out of the
    // history, so Back does not lead to a page we just navigated away from.
    const target = lastVisit.bounceTarget;

    if (target) {
        // Cleared before the visit, so the page we land on gets a scroll memory
        // of its own rather than being treated as still on its way somewhere.
        lastVisit.consumeBounce();

        router.visit(target, { replace: true });
    }
});

// This will set light / dark mode on page load...
initializeTheme();
