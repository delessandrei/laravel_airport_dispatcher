{{--
    @author       Delescu Andrei Vlad <andrei.delescu@gmail.com>
    @copyright    Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
    @link         https://www.deless.ro/
--}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Airport Dispatcher')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-sky-600 text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/>
                    </svg>
                </span>
                <span>
                    <span class="block text-sm font-semibold tracking-tight">Airport Dispatcher</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Gate and flight overview</span>
                </span>
            </a>

            @hasSection('headerMeta')
                <div class="text-right text-xs text-slate-500 dark:text-slate-400">@yield('headerMeta')</div>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 pb-10 text-xs text-slate-400 sm:px-6 lg:px-8 dark:text-slate-600">
        Proof of concept — Romania, Germany and the United Kingdom only.
        Flight data from the OpenSky Network.
    </footer>
</body>
</html>
