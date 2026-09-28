<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Link previews for Slack, Facebook, X, etc. Crawlers don't run JS, so these must be server-rendered. --}}
        <meta name="description" content="OneDrop is vibe coding for production: describe an app and watch it get built as real, tested code. Bring your own ChatGPT plan or AI key, then deploy it anywhere.">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="OneDrop">
        <meta property="og:title" content="OneDrop: vibe-code apps for production">
        <meta property="og:description" content="OneDrop is vibe coding for production: describe an app and watch it get built as real, tested code. Bring your own ChatGPT plan or AI key, then deploy it anywhere.">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:image" content="{{ asset('images/og.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="OneDrop: Vibe-code apps for production. Bring your own subscription. Deploy anywhere.">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="OneDrop: vibe-code apps for production">
        <meta name="twitter:description" content="OneDrop is vibe coding for production: describe an app and watch it get built as real, tested code. Bring your own ChatGPT plan or AI key, then deploy it anywhere.">
        <meta name="twitter:image" content="{{ asset('images/og.png') }}">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'OneDrop') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
