<script lang="ts">
    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 44" class={className} {...rest}>
    <path
        fill="currentColor"
        d="M2 22
           C2 12 13 5 25 5
           C34 5 41 9 45 14
           L60 4
           L53 22
           L60 40
           L45 30
           C41 35 34 39 25 39
           C13 39 2 32 2 22
           Z"
    />
    <path fill="currentColor" d="M18 8 L24 1 L28 10 Z" />
    <circle cx="11" cy="17" r="2.6" class="fill-sidebar" />
</svg>
