<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .jt .jt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px}
        .jt .jt-span{grid-column:1/-1}
        .jt .jt-block{background:#fff;border:1px solid rgb(17 24 39 / .08);border-radius:10px;padding:8px 10px}
        .dark .jt .jt-block{background:rgb(255 255 255 / .04);border-color:rgb(255 255 255 / .1)}
        .jt .jt-obj{border:1px solid rgb(17 24 39 / .08);border-radius:10px;padding:8px;background:rgb(17 24 39 / .015)}
        .dark .jt .jt-obj{border-color:rgb(255 255 255 / .1);background:rgb(255 255 255 / .02)}
        .jt .jt-obj-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}
        .jt .jt-key{font-size:12px;color:rgb(107 114 128);font-family:ui-monospace,monospace;margin-bottom:6px}
        .jt .jt-obj-head .jt-key{margin-bottom:0}
        .dark .jt .jt-key{color:rgb(156 163 175)}
        .jt .jt-badge{font-size:10px;font-family:ui-monospace,monospace;color:rgb(107 114 128);background:rgb(17 24 39 / .05);padding:1px 6px;border-radius:6px}
        .dark .jt .jt-badge{background:rgb(255 255 255 / .08);color:rgb(156 163 175)}
        .jt .jt-num{width:100%;border:1px solid rgb(17 24 39 / .12);border-radius:8px;padding:5px 8px;font-size:13px;font-family:ui-monospace,monospace;background:#fff;color:inherit}
        .dark .jt .jt-num{background:rgb(255 255 255 / .05);border-color:rgb(255 255 255 / .12);color:#fff}
        .jt .jt-numwrap{display:flex;align-items:center;gap:8px}
        .jt .jt-unit{font-size:12px;color:rgb(107 114 128)}
        .jt .jt-seg{display:inline-flex;border:1px solid rgb(17 24 39 / .12);border-radius:8px;overflow:hidden;flex-wrap:wrap}
        .dark .jt .jt-seg{border-color:rgb(255 255 255 / .12)}
        .jt .jt-seg label{position:relative;cursor:pointer}
        .jt .jt-seg input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
        .jt .jt-seg span{display:block;padding:4px 10px;font-size:12px;color:rgb(75 85 99)}
        .dark .jt .jt-seg span{color:rgb(209 213 219)}
        .jt .jt-seg input:checked+span{background:rgb(var(--primary-600,217 119 6));color:#fff}
        .jt .jt-chips{display:flex;flex-wrap:wrap;gap:6px}
        .jt .jt-chip{position:relative;cursor:pointer}
        .jt .jt-chip input{position:absolute;opacity:0;margin:0}
        .jt .jt-chip span{display:inline-block;padding:4px 11px;border-radius:999px;font-size:12px;border:1px solid rgb(17 24 39 / .12);color:rgb(75 85 99)}
        .dark .jt .jt-chip span{border-color:rgb(255 255 255 / .12);color:rgb(209 213 219)}
        .jt .jt-chip input:checked+span{background:rgb(var(--primary-600,217 119 6));color:#fff;border-color:transparent}
        .jt .jt-switch{position:relative;display:inline-block;width:34px;height:20px;cursor:pointer}
        .jt .jt-switch input{position:absolute;opacity:0;margin:0}
        .jt .jt-knob{position:absolute;inset:0;border-radius:999px;background:rgb(17 24 39 / .2)}
        .jt .jt-knob::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff}
        .jt .jt-switch input:checked+.jt-knob{background:rgb(var(--primary-600,217 119 6))}
        .jt .jt-switch input:checked+.jt-knob::after{left:16px}
        .jt .jt-empty{font-size:12px;color:rgb(156 163 175);font-family:ui-monospace,monospace}
        .jt input:disabled{opacity:.55;cursor:not-allowed}
        .jt input[type=range]{accent-color:rgb(var(--primary-600,217 119 6))}
    </style>
    <div class="jt">
        @php $state = $getState() ?? []; @endphp
        <div class="jt-grid">
            @forelse ($state as $key => $value)
                @include('filament.forms.components.json-node', [
                    'node' => $value,
                    'path' => $getStatePath() . '.' . $key,
                    'label' => $key,
                    'depth' => 0,
                    'disabled' => $isDisabled(),
                ])
            @empty
                <p class="jt-empty">Empty — nothing set yet.</p>
            @endforelse
        </div>
    </div>
</x-dynamic-component>
