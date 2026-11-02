<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="ably-public-key" content="{{ config('broadcasting.connections.ably.public_key') }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <script>
            (() => {
                try {
                    const savedTheme = localStorage.getItem('admin-theme');
                    const dark = savedTheme === 'dark'
                        || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (_) {
                    document.documentElement.classList.toggle(
                        'dark',
                        window.matchMedia('(prefers-color-scheme: dark)').matches,
                    );
                }
            })();
        </script>
        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png" />
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png" />
        <link rel="manifest" href="/site.webmanifest"></link>
        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
        @inertia
    </body>
</html>
