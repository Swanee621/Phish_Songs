<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        <title>Offline - {{ config('app.name', 'Laravel') }}</title>

        {{-- Styles are inlined so this page renders with no network connection. --}}
        <style>
            :root {
                color-scheme: light dark;
                --background: #ffffff;
                --foreground: #0a0a0a;
                --muted-foreground: #737373;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --background: #0a0a0a;
                    --foreground: #fafafa;
                    --muted-foreground: #a1a1a1;
                }
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background-color: var(--background);
                color: var(--foreground);
                font-family: 'Instrument Sans', ui-sans-serif, system-ui,
                    -apple-system, 'Segoe UI', Roboto, sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            main {
                flex: 1;
                width: 100%;
                max-width: 80rem;
                margin: 0 auto;
                padding: 1rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.75rem;
                text-align: center;
            }

            svg {
                width: 2.5rem;
                height: 2.5rem;
                color: var(--muted-foreground);
            }

            h1 {
                margin: 0;
                font-size: 1.5rem;
                font-weight: 600;
                line-height: 1.3;
            }

            p {
                margin: 0;
                font-size: 0.875rem;
                color: var(--muted-foreground);
            }
        </style>
    </head>
    <body>
        <main>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true">
                <path d="m2 2 20 20"/>
                <path d="M8.5 16.5a5 5 0 0 1 7 0"/>
                <path d="M2 8.82a15 15 0 0 1 4.17-2.65"/>
                <path d="M10.66 5c4.01-.36 8.14.9 11.34 3.76"/>
                <path d="M16.85 11.25a10 10 0 0 1 2.22 1.68"/>
                <path d="M5 13a10 10 0 0 1 5.24-2.76"/>
                <line x1="12" x2="12.01" y1="20" y2="20"/>
            </svg>

            <h1>You are currently not connected to any networks.</h1>

            <p>Check your connection and try again.</p>
        </main>
    </body>
</html>
