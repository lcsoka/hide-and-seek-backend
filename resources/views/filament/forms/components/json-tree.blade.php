<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .jt .jt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;align-items:start}
        .jt .jt-wide{grid-column:1/-1}
        .jt .jt-block{background:#fff;border:1px solid rgb(17 24 39 / .08);border-radius:12px;padding:10px 12px;min-width:0}
        .dark .jt .jt-block{background:rgb(255 255 255 / .03);border-color:rgb(255 255 255 / .08)}
        .jt .jt-obj{grid-column:1/-1;border:1px solid rgb(17 24 39 / .08);border-radius:12px;padding:10px 12px;background:rgb(17 24 39 / .02)}
        .dark .jt .jt-obj{border-color:rgb(255 255 255 / .08);background:rgb(255 255 255 / .02)}
        .jt .jt-obj-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
        .jt .jt-key{font-size:11px;letter-spacing:.02em;color:rgb(107 114 128);font-family:ui-monospace,monospace;margin-bottom:7px}
        .jt .jt-obj-head .jt-key{margin-bottom:0}
        .dark .jt .jt-key{color:rgb(148 163 184)}
        .jt .jt-badge{font-size:10px;font-family:ui-monospace,monospace;color:rgb(107 114 128);background:rgb(17 24 39 / .05);padding:2px 7px;border-radius:6px}
        .dark .jt .jt-badge{background:rgb(255 255 255 / .08);color:rgb(148 163 184)}
        .jt input.jt-num{width:100%;box-sizing:border-box;border:1px solid rgb(17 24 39 / .14);border-radius:8px;padding:7px 10px;font-size:13px;background:#fff;color:inherit}
        .dark .jt input.jt-num{background:rgb(255 255 255 / .05);border-color:rgb(255 255 255 / .14);color:#fff}
        .jt input.jt-num:focus{outline:0;border-color:rgb(var(--primary-500,245 158 11));box-shadow:0 0 0 3px rgb(var(--primary-500,245 158 11) / .15)}
        .jt .jt-numwrap{display:flex;align-items:center;gap:10px;min-width:0}
        .jt .jt-unit{font-size:12px;color:rgb(107 114 128);flex:none}
        .jt .jt-seg{display:flex;width:100%;border:1px solid rgb(17 24 39 / .14);border-radius:8px;overflow:hidden}
        .dark .jt .jt-seg{border-color:rgb(255 255 255 / .14)}
        .jt .jt-seg label{flex:1;position:relative;cursor:pointer;min-width:0}
        .jt .jt-seg label+label{border-left:1px solid rgb(17 24 39 / .1)}
        .dark .jt .jt-seg label+label{border-left-color:rgb(255 255 255 / .1)}
        .jt .jt-seg input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer}
        .jt .jt-seg span{display:block;text-align:center;padding:7px 8px;font-size:12px;color:rgb(75 85 99);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .dark .jt .jt-seg span{color:rgb(203 213 225)}
        .jt .jt-seg input:checked+span{background:rgb(var(--primary-600,217 119 6));color:#fff}
        .jt .jt-chips{display:flex;flex-wrap:wrap;gap:6px}
        .jt .jt-chip{position:relative;cursor:pointer}
        .jt .jt-chip input{position:absolute;opacity:0;margin:0}
        .jt .jt-chip span{display:inline-block;padding:5px 12px;border-radius:999px;font-size:12px;border:1px solid rgb(17 24 39 / .14);color:rgb(75 85 99)}
        .dark .jt .jt-chip span{border-color:rgb(255 255 255 / .14);color:rgb(203 213 225)}
        .jt .jt-chip input:checked+span{background:rgb(var(--primary-600,217 119 6));color:#fff;border-color:transparent}
        .jt .jt-switch{position:relative;display:inline-block;width:36px;height:21px;cursor:pointer}
        .jt .jt-switch input{position:absolute;opacity:0;margin:0}
        .jt .jt-knob{position:absolute;inset:0;border-radius:999px;background:rgb(17 24 39 / .22)}
        .jt .jt-knob::after{content:"";position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgb(0 0 0 / .15)}
        .jt .jt-switch input:checked+.jt-knob{background:rgb(var(--primary-600,217 119 6))}
        .jt .jt-switch input:checked+.jt-knob::after{left:16px}
        .jt input[type=range]{flex:1;min-width:0;accent-color:rgb(var(--primary-600,217 119 6))}
        .jt .jt-empty{font-size:12px;color:rgb(148 163 184);font-family:ui-monospace,monospace}
        .jt input:disabled{opacity:.55;cursor:not-allowed}
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
