<x-filament-panels::page>
    @php $summary = $this->summary(); @endphp

    {{-- Summary header --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
        @foreach ([
            'Phase' => $summary['phase'],
            'Status' => $summary['status'],
            'Round' => $summary['round'] ?? '—',
            'Hider' => $summary['hider'] ?? '—',
            'Players' => $summary['players'],
            'Questions' => $summary['questions'],
            'Curses' => $summary['curses'],
        ] as $label => $value)
            <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $label }}</div>
                <div class="mt-0.5 truncate text-lg font-semibold" title="{{ $value }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    @if (filled($summary['timers']))
        <div class="flex flex-wrap gap-2">
            @foreach ($summary['timers'] as $label => $time)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-3 py-1 text-xs font-medium dark:bg-white/5">
                    <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5 text-gray-400" />
                    {{ $label }}: <span class="font-mono">{{ $time }}</span>
                </span>
            @endforeach
        </div>
    @endif

    {{-- Editable trees --}}
    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Game state</x-slot>
            <x-slot name="description">Mode-owned state (scores, hider zone, questions, curses…).</x-slot>
            <div class="space-y-0.5">
                @forelse ($stateData as $k => $v)
                    @include('filament.resources.sessions.pages.partials.json-node', ['node' => $v, 'path' => 'stateData.' . $k, 'label' => $k, 'depth' => 0])
                @empty
                    <p class="py-2 text-sm text-gray-400">No state data yet (the game hasn't started).</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Config</x-slot>
            <x-slot name="description">Resolved game configuration for this session.</x-slot>
            <div class="space-y-0.5">
                @forelse ($config as $k => $v)
                    @include('filament.resources.sessions.pages.partials.json-node', ['node' => $v, 'path' => 'config.' . $k, 'label' => $k, 'depth' => 0])
                @empty
                    <p class="py-2 text-sm text-gray-400">No config set.</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    <div class="flex justify-end">
        <x-filament::button wire:click="save" icon="heroicon-o-check">
            Save changes
        </x-filament::button>
    </div>
</x-filament-panels::page>
