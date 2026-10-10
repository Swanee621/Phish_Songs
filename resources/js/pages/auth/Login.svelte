<script lang="ts">
    import { Form } from '@inertiajs/svelte';
    import { store } from '@/actions/Laravel/Fortify/Http/Controllers/AuthenticatedSessionController';
    import AppHead from '@/components/AppHead.svelte';

    let { status }: { status?: string | null } = $props();

    const inputClasses =
        'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50';
</script>

<AppHead title="Log in" />

<div class="flex flex-1 items-start justify-center p-4 pt-16">
    <Form
        action={store.url()}
        method="post"
        class="w-full max-w-sm space-y-4 rounded-xl border bg-card p-6 text-card-foreground shadow-sm"
    >
        {#snippet children({ errors, processing })}
            <h1 class="text-xl font-semibold">Log in</h1>

            {#if status}
                <p class="text-sm text-muted-foreground">{status}</p>
            {/if}

            <div class="space-y-1">
                <label for="email" class="text-sm font-medium">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    autocomplete="username"
                    required
                    class={inputClasses}
                />
                {#if errors.email}
                    <p class="text-sm text-destructive">{errors.email}</p>
                {/if}
            </div>

            <div class="space-y-1">
                <label for="password" class="text-sm font-medium"
                    >Password</label
                >
                <input
                    id="password"
                    name="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class={inputClasses}
                />
                {#if errors.password}
                    <p class="text-sm text-destructive">{errors.password}</p>
                {/if}
            </div>

            <label
                class="flex w-fit cursor-pointer items-center gap-2 text-sm text-muted-foreground select-none"
            >
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    class="size-4 cursor-pointer rounded border-input accent-primary"
                />
                Remember me
            </label>

            <button
                type="submit"
                disabled={processing}
                class="inline-flex h-9 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
            >
                {processing ? 'Logging in…' : 'Log in'}
            </button>
        {/snippet}
    </Form>
</div>
