<script lang="ts">
    import type { Snippet } from 'svelte';
    import { Toaster } from '@/components/ui/sonner';

    let { children }: { children?: Snippet } = $props();
</script>

<div class="flex min-h-screen w-full flex-col">
    <main class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col">
        {@render children?.()}
    </main>
    <Toaster />
</div>
