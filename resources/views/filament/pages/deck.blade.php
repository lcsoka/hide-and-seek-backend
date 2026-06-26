@php($c = $this->composition())

<x-filament-panels::page>
    {{-- Headline: total vs the official target --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-3xl font-bold text-gray-950 dark:text-white">{{ $c['total'] }} cards</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">in the shuffled hider deck</div>
            </div>
            <div class="text-right">
                @if ($c['delta'] === 0)
                    <x-filament::badge color="success" size="lg">Matches the official {{ $c['official'] }}</x-filament::badge>
                @else
                    <x-filament::badge color="warning" size="lg">
                        {{ $c['delta'] > 0 ? '+' : '' }}{{ $c['delta'] }} vs official {{ $c['official'] }}
                    </x-filament::badge>
                @endif
            </div>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($c['types'] as $type)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::badge :color="$type['color']">{{ $type['label'] }}</x-filament::badge>
                    </div>
                    <div class="mt-2 text-2xl font-bold text-gray-950 dark:text-white">{{ $type['copies'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $type['rows'] }} distinct {{ \Illuminate\Support\Str::plural('card', $type['rows']) }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Per-type breakdown with edit links --}}
    @foreach ($this->cardsByType() as $type => $cards)
        <x-filament::section :collapsible="true">
            <x-slot name="heading">{{ ucfirst(str_replace('_', ' ', $type)) }} ({{ $cards->count() }})</x-slot>

            <div class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($cards as $card)
                    <a href="{{ \App\Filament\Resources\Cards\CardResource::getUrl('edit', ['record' => $card]) }}"
                       class="flex items-center justify-between gap-3 py-2 text-sm hover:text-primary-600">
                        <span class="min-w-0 flex-1 truncate">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $card->getTranslation('name', 'en', false) ?: $card->name }}</span>
                            @if ($card->type === 'powerup')
                                <span class="text-gray-400">· {{ $card->power }}</span>
                            @elseif ($card->type === 'time_bonus')
                                <span class="text-gray-400">· +{{ implode('/', $card->minutes ?? []) }} min (S/M/L)</span>
                            @endif
                        </span>
                        <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600 dark:bg-white/10 dark:text-gray-300">×{{ $card->count }}</span>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
