<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php $state = $getState(); @endphp
    <div class="space-y-0.5 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        @forelse (($state ?? []) as $key => $value)
            @include('filament.forms.components.json-node', [
                'node' => $value,
                'path' => $getStatePath() . '.' . $key,
                'label' => $key,
                'depth' => 0,
                'disabled' => $isDisabled(),
            ])
        @empty
            <p class="py-1 text-sm text-gray-400">Empty — nothing set yet.</p>
        @endforelse
    </div>
</x-dynamic-component>
