<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        {{-- Core pages are preloaded by path; module pages (packages/workdo/*) are resolved by app.tsx --}}
        @php
            $viteEntries = ['resources/js/app.tsx'];
            if (file_exists(resource_path("js/Pages/{$page['component']}.tsx"))) {
                $viteEntries[] = "resources/js/Pages/{$page['component']}.tsx";
            }
        @endphp
        @vite($viteEntries)
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
