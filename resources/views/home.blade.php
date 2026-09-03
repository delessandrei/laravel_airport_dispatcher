{{--
    @author       Delescu Andrei Vlad <andrei.delescu@gmail.com>
    @copyright    Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
    @link         https://www.deless.ro/
--}}
@extends('layouts.app')

@section('title', 'Airports — Airport Dispatcher')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight">Airports</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Select an airport to view its terminals, gates and the flights scheduled for a given day.
        </p>
    </div>

    {{-- One tab per country. Romania is the default. --}}
    <div class="mb-6 inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
        @foreach ($countries as $code => [$label, $name])
            <a href="{{ route('home', ['country' => $code]) }}"
               title="{{ $name }}"
               class="rounded-md px-4 py-1.5 text-sm font-medium transition
                      {{ $selectedCountry === $code
                            ? 'bg-sky-600 text-white shadow-sm'
                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                {{ $label }}
                <span class="tabular-nums opacity-70">({{ $counts[$code] ?? 0 }})</span>
            </a>
        @endforeach
    </div>

    <h2 class="mb-3 text-sm font-medium text-slate-500 dark:text-slate-400">
        {{ $countries[$selectedCountry][1] }} — {{ $airports->count() }} airports
    </h2>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($airports as $airport)
            <a href="{{ route('airports.show', $airport->icao) }}"
               class="group rounded-xl border border-slate-200 bg-white p-4 transition hover:border-sky-400 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-sky-600">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-sky-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">{{ $airport->icao }}</span>
                            <span class="font-mono text-xs text-slate-400">{{ $airport->iata }}</span>
                        </div>
                        <p class="mt-2 truncate text-sm font-medium">{{ $airport->name }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $airport->city }}</p>
                    </div>
                    <svg class="h-4 w-4 shrink-0 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-sky-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 18l6-6-6-6"/>
                    </svg>
                </div>
                <div class="mt-3 flex gap-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <span>{{ $airport->terminalCount() }} terminals</span>
                    <span>{{ $airport->gateCount() }} gates</span>
                </div>
            </a>
        @empty
            <p class="text-sm text-slate-500">No airports seeded for this country yet.</p>
        @endforelse
    </div>
@endsection
