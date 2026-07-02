{{-- Recursive editable node bound to form state at $path. Params: $node, $path, $label, $depth, $disabled. --}}
@php $isArray = is_array($node); @endphp

@if ($isArray)
    <details @class(['group']) @if ($depth < 1) open @endif>
        <summary class="flex cursor-pointer select-none items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-gray-100 dark:hover:bg-white/5">
            <x-filament::icon icon="heroicon-m-chevron-right" class="h-3.5 w-3.5 text-gray-400 transition group-open:rotate-90" />
            <span class="font-mono font-medium text-primary-600 dark:text-primary-400">{{ $label }}</span>
            <span class="rounded bg-gray-200 px-1.5 py-0.5 font-mono text-[10px] text-gray-500 dark:bg-white/10 dark:text-gray-400">
                {{ array_is_list($node) ? 'list' : 'object' }} · {{ count($node) }}
            </span>
        </summary>
        <div class="ml-3 border-l border-gray-200 pl-3 dark:border-white/10">
            @forelse ($node as $k => $v)
                @include('filament.forms.components.json-node', [
                    'node' => $v,
                    'path' => $path . '.' . $k,
                    'label' => $k,
                    'depth' => $depth + 1,
                    'disabled' => $disabled,
                ])
            @empty
                <div class="py-1 font-mono text-xs text-gray-400">empty</div>
            @endforelse
        </div>
    </details>
@else
    <div class="flex items-center gap-2 py-1 pl-5">
        <span class="min-w-[9rem] shrink-0 truncate font-mono text-xs text-gray-500 dark:text-gray-400" title="{{ $label }}">{{ $label }}</span>
        @if (is_bool($node))
            <input type="checkbox" wire:model="{{ $path }}" @disabled($disabled)
                   class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 disabled:opacity-60 dark:border-white/20 dark:bg-white/5" />
        @elseif (is_int($node) || is_float($node))
            <input type="number" step="any" wire:model.number="{{ $path }}" @disabled($disabled)
                   class="w-full rounded-md border-gray-300 py-1 font-mono text-sm disabled:opacity-60 dark:border-white/10 dark:bg-white/5" />
        @else
            <input type="text" wire:model="{{ $path }}" @disabled($disabled) @if (is_null($node)) placeholder="null" @endif
                   class="w-full rounded-md border-gray-300 py-1 font-mono text-sm disabled:opacity-60 dark:border-white/10 dark:bg-white/5" />
        @endif
    </div>
@endif
