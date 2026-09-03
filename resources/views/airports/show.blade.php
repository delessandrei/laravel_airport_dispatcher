{{--
    @author       Delescu Andrei Vlad <andrei.delescu@gmail.com>
    @copyright    Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
    @link         https://www.deless.ro/
--}}
@extends('layouts.app')

@section('title', $airport->icao.' — '.$airport->name)

@section('headerMeta')
    {{ $date->format('D, j M Y') }} &middot; {{ $airport->timezone }}
@endsection

@section('content')
    @php
        $tz = $airport->timezone ?: 'UTC';
        $flights = $tab === 'departures' ? $board->departures : $board->arrivals;

        // Every link on this page carries the current view forward: changing the
        // day must not silently reset the board, the sort or the window.
        $carry = array_filter([
            'board' => $tab,
            'sort' => $board->sort,
            'dir' => $board->sortDirection,
            'window' => $windowed ? null : 'all',
        ], fn ($value) => $value !== null);
    @endphp

    <a href="{{ route('home', ['country' => $airport->country_code]) }}"
       class="mb-6 inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-sky-600 dark:text-slate-400">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
        All {{ $airport->country_name }} airports
    </a>

    {{-- ------------------------------------------------------------ header --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="rounded bg-sky-600 px-2 py-1 font-mono text-sm font-bold text-white">{{ $airport->icao }}</span>
                <span class="rounded border border-slate-300 px-2 py-1 font-mono text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">{{ $airport->iata }}</span>
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">{{ $airport->name }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ $airport->city }}, {{ $airport->country_name }}
                &middot; {{ number_format($airport->latitude, 4) }}, {{ number_format($airport->longitude, 4) }}
            </p>
        </div>

        {{-- ------------------------------------------------------ date picker --}}
        <form method="GET" class="flex items-end gap-2">
            @foreach ($carry as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <a href="{{ route('airports.show', array_merge([$airport->icao, 'date' => $date->subDay()->toDateString()], $carry)) }}"
               class="rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
               aria-label="Previous day">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
            </a>
            <div>
                <label for="date" class="block text-xs text-slate-500 dark:text-slate-400">Date</label>
                <input type="date" id="date" name="date" value="{{ $date->toDateString() }}"
                       class="mt-1 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900">
            </div>
            <a href="{{ route('airports.show', array_merge([$airport->icao, 'date' => $date->addDay()->toDateString()], $carry)) }}"
               class="rounded-lg border border-slate-300 bg-white px-2.5 py-2 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
               aria-label="Next day">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
            </a>
            <button type="submit" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900">
                Go
            </button>
        </form>
    </div>

    @if (session('status'))
        <div class="mb-6 rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-800/60 dark:bg-sky-950/40 dark:text-sky-200">
            {{ session('status') }}
        </div>
    @endif

    {{-- --------------------------------------------------------- demo notice --}}
    @if ($board->notice && ! $board->isDemo)
        <div class="mb-6 flex gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><path d="M12 16v-4m0-4h.01"/>
            </svg>
            <p class="text-sm text-slate-600 dark:text-slate-300">{{ $board->notice }}</p>
        </div>
    @endif

    @if ($board->isDemo)
        <div class="mb-6 flex gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700/60 dark:bg-amber-950/30">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
            </svg>
            <div class="text-sm">
                <p class="font-semibold text-amber-900 dark:text-amber-200">Demo data — not real traffic</p>
                <p class="mt-0.5 text-amber-800 dark:text-amber-300">{{ $board->notice }}</p>
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------------- stat cards --}}
    <div class="mb-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Terminals', $airport->terminalCount(), null],
            ['Gates', $airport->gateCount(), $closures->isNotEmpty() ? $closures->count().' closed' : null],
            ['Arrivals', $board->totalArrivals, null],
            ['Departures', $board->totalDepartures, null],
        ] as [$label, $value, $note])
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $value }}</p>
                @if ($note)
                    <p class="text-xs font-medium text-red-700 dark:text-red-400">{{ $note }}</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ----------------------------------------------------- terminals/gates --}}
    <section class="mb-8">
        <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Terminals &amp; gates</h2>
            <div class="flex items-center gap-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-sky-500"></span> Jet bridge</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-slate-400"></span> Remote stand</span>
                <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-red-800"></span> Closed</span>
            </div>
        </div>

        <div class="space-y-4">
            @foreach ($airport->terminals as $terminal)
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="mb-3 flex items-baseline gap-2">
                        <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-xs font-bold text-white dark:bg-slate-100 dark:text-slate-900">{{ $terminal['code'] }}</span>
                        <span class="text-sm font-medium">{{ $terminal['name'] }}</span>
                        <span class="text-xs text-slate-400">{{ count($terminal['gates']) }} gates</span>
                    </div>

                    {{-- Gate buttons carry their identity in data attributes so the
                         allocation step can bind behaviour without touching markup. --}}
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($terminal['gates'] as $gate)
                            @php
                                $closure = $closures[$gate['code']] ?? null;
                                $reason = $closure && filled($closure->reason) ? $closure->reason : 'Unknown';
                                $until = $closure?->until?->setTimezone($tz)->format('j M Y, H:i') ?: 'Unknown';
                                $from = $closure?->from?->setTimezone($tz)->format('j M Y, H:i') ?: 'Unknown';
                            @endphp
                            <button type="button"
                                    data-gate="{{ $gate['code'] }}"
                                    data-terminal="{{ $terminal['code'] }}"
                                    data-airport="{{ $airport->icao }}"
                                    data-gate-type="{{ $gate['type'] }}"
                                    @if ($closure)
                                        data-gate-closed="1"
                                        data-closure-reason="{{ $reason }}"
                                        data-closure-until="{{ $until }}"
                                        data-closure-from="{{ $from }}"
                                        title="Gate {{ $gate['code'] }} — closed: {{ $reason }}"
                                    @else
                                        title="Gate {{ $gate['code'] }} — {{ str_replace('jetbridge', 'jet bridge', $gate['type']) }}"
                                    @endif
                                    class="min-w-14 rounded-md px-2.5 py-1.5 font-mono text-xs font-semibold transition
                                           {{ $closure
                                                ? 'bg-red-800 text-red-50 line-through hover:bg-red-700 dark:bg-red-900 dark:text-red-100 dark:hover:bg-red-800'
                                                : ($gate['type'] === 'remote'
                                                    ? 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'
                                                    : 'bg-sky-50 text-sky-700 hover:bg-sky-100 dark:bg-sky-950/50 dark:text-sky-300 dark:hover:bg-sky-900/60') }}">
                                {{ $gate['code'] }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Filled in by resources/js/app.js when a closed gate is clicked. --}}
        <div id="gate-closure-detail" hidden
             class="mt-4 flex items-start justify-between gap-4 rounded-xl border border-red-300 bg-red-50 p-4 dark:border-red-800/60 dark:bg-red-950/40">
            <div class="text-sm">
                <p class="font-semibold text-red-900 dark:text-red-200">
                    Gate <span data-closure-gate class="font-mono"></span> is closed
                </p>
                <dl class="mt-2 grid gap-x-8 gap-y-1 text-red-800 sm:grid-cols-3 dark:text-red-300">
                    <div><dt class="inline font-medium">Reason:</dt> <dd class="inline" data-closure-reason></dd></div>
                    <div><dt class="inline font-medium">Closed from:</dt> <dd class="inline" data-closure-from></dd></div>
                    <div><dt class="inline font-medium">Closed until:</dt> <dd class="inline" data-closure-until></dd></div>
                </dl>
            </div>
            <button type="button" data-closure-dismiss aria-label="Dismiss"
                    class="rounded p-1 text-red-700 hover:bg-red-100 dark:text-red-300 dark:hover:bg-red-900/60">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    </section>

    {{-- --------------------------------------------------------- flight board --}}
    <section>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Flights</h2>

            <div class="flex flex-wrap items-center gap-3">

            {{-- Collection is explicit: opening a page never spends credits. --}}
            <form method="POST" action="{{ route('airports.collect', $airport->icao) }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                @foreach ($carry as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button type="submit"
                        title="Queries OpenSky for this airport and day. Costs 60 API credits."
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:border-sky-400 hover:text-sky-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-sky-600 dark:hover:text-sky-400">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3v12m0 0-4-4m4 4 4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>
                    </svg>
                    Collect from OpenSky
                </button>
            </form>

            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 dark:border-slate-800 dark:bg-slate-900">
                @foreach (['arrivals' => $board->totalArrivals, 'departures' => $board->totalDepartures] as $key => $count)
                    <a href="{{ route('airports.show', array_merge([$airport->icao, 'date' => $date->toDateString()], $carry, ['board' => $key])) }}"
                       class="rounded-md px-4 py-1.5 text-sm font-medium capitalize transition
                              {{ $tab === $key
                                    ? 'bg-sky-600 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                        {{ $key }} <span class="tabular-nums opacity-70">({{ $count }})</span>
                    </a>
                @endforeach
            </div>

            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table class="w-full text-sm">
                @php
                    // Clicking the column you are already on flips the direction.
                    $sortUrl = fn (string $key) => route('airports.show', array_merge(
                        [$airport->icao, 'date' => $date->toDateString()],
                        $carry,
                        [
                            'sort' => $key,
                            'dir' => $board->sort === $key && $board->sortDirection === 'desc' ? 'asc' : 'desc',
                        ],
                    ));
                @endphp

                <thead class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <tr>
                        @foreach ([
                            'time' => $tab === 'arrivals' ? 'Arrives' : 'Departs',
                            'gate' => 'Gate',
                        ] as $key => $label)
                            @php $active = $board->sort === $key; @endphp
                            <th class="px-4 py-3 font-medium {{ $key === 'gate' ? 'order-none' : '' }}"
                                @if ($key === 'gate') data-column="gate" @endif>
                                <a href="{{ $sortUrl($key) }}"
                                   class="group inline-flex items-center gap-1 {{ $active ? 'text-sky-600 dark:text-sky-400' : 'hover:text-slate-700 dark:hover:text-slate-200' }}">
                                    {{ $label }}
                                    {{-- An arrow with a shaft, not a bare chevron: at this size
                                         the shaft is what makes the direction readable. Points up
                                         for ascending, flipped for descending. --}}
                                    <svg class="h-3.5 w-3.5 shrink-0 transition-transform duration-150
                                                {{ $active
                                                    ? ($board->sortDirection === 'desc' ? 'rotate-180' : '')
                                                    : 'opacity-0 group-hover:opacity-50' }}"
                                         viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M12 19V5M5 12l7-7 7 7"/>
                                    </svg>
                                </a>
                            </th>
                            @if ($key === 'time')
                                <th class="px-4 py-3 font-medium">Callsign</th>
                                <th class="px-4 py-3 font-medium">Airline</th>
                                <th class="px-4 py-3 font-medium">{{ $tab === 'arrivals' ? 'From' : 'To' }}</th>
                                <th class="px-4 py-3 font-medium">Block time</th>
                            @endif
                        @endforeach
                        <th class="px-4 py-3 font-medium">Aircraft</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @php $crossedNow = false; @endphp
                    @forelse ($flights as $flight)
                        @if ($board->isWindowed && $board->sort === 'time' && $board->sortDirection === 'asc'
                                && ! $crossedNow && $flight->boardTime()?->greaterThan($board->pivot))
                            @php $crossedNow = true; @endphp
                            <tr class="bg-sky-50 dark:bg-sky-950/40">
                                <td colspan="7" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">
                                    now &middot; {{ $board->pivot->setTimezone($tz)->format('H:i') }}
                                </td>
                            </tr>
                        @endif
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3 font-mono font-semibold tabular-nums">
                                {{ $flight->boardTime()?->setTimezone($tz)->format('H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono">{{ $flight->callsign ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $flight->carrier() ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono">{{ $flight->counterpart() ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-500 dark:text-slate-400">{{ $flight->duration() }}</td>
                            <td class="px-4 py-3">
                                @if ($flight->isAllocated())
                                    <span class="rounded bg-sky-600 px-1.5 py-0.5 font-mono text-xs font-bold text-white">{{ $flight->gate_code }}</span>
                                @else
                                    <span class="text-xs text-slate-400">unassigned</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ $flight->icao24 }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500 dark:text-slate-400">
                                No {{ $tab }} recorded for {{ $date->format('j M Y') }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @php
            $isToday = $date->isSameDay(\Carbon\CarbonImmutable::now($tz));
            $total = $tab === 'arrivals' ? $board->totalArrivals : $board->totalDepartures;
            $link = fn (array $extra) => route('airports.show', array_merge([
                $airport->icao,
                'date' => $date->toDateString(),
                'board' => $tab,
                'sort' => $board->sort,
                'dir' => $board->sortDirection,
            ], $extra));
        @endphp

        @if ($board->isWindowed && $board->isTrimmed($tab === 'arrivals' ? 'arrival' : 'departure'))
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Showing the movements around now, {{ count($flights) }} of {{ $total }} for the day.
                <a href="{{ $link(['window' => 'all']) }}"
                   class="font-medium text-sky-600 hover:underline dark:text-sky-400">Show all {{ $total }}</a>.
            </p>
        @elseif ($isToday && ! $windowed && $total > 0)
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Showing all {{ $total }} movements for the day.
                <a href="{{ $link([]) }}"
                   class="font-medium text-sky-600 hover:underline dark:text-sky-400">Show only the ones around now</a>.
            </p>
        @endif

        <p class="mt-3 text-xs text-slate-400 dark:text-slate-600">
            @if ($board->isDemo)
                Generated data. Times are shown in {{ $tz }}.
            @else
                Stored in MongoDB from OpenSky. Times are shown in {{ $tz }}.
                @if ($board->importedAt)
                    &middot; Collected {{ $board->importedAt->setTimezone($tz)->format('j M Y, H:i') }}.
                @endif
                @if ($board->creditsRemaining !== null)
                    &middot; {{ number_format($board->creditsRemaining) }} API credits left today.
                @endif
            @endif
        </p>
    </section>
@endsection
