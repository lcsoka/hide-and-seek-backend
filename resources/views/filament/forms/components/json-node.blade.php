{{-- Recursive block. Objects/lists render as a collapsible <details> (lists + list-items closed
     by default, other objects open); scalars render as a smart/typed field-block. Bound to form
     state at $path. --}}
@php
    $parentIsList = $parentIsList ?? false;
    $lc = is_string($label) ? strtolower($label) : (string) $label;

    $segments = [
        'units' => ['metric', 'imperial'],
        'rule' => ['nearest', 'circle'],
        'hiding_zone_rule' => ['nearest', 'circle'],
        'state' => ['lobby', 'hiding', 'seeking', 'round_end', 'finished'],
        'game_mode' => ['hide_and_seek'],
        'game_size' => ['small', 'medium', 'large'],
        'size' => ['small', 'medium', 'large'],
    ];
    $chipOptions = [
        'transit_modes' => ['metro', 'tram', 'bus', 'rail', 'light_rail', 'trolleybus'],
        'disabled_categories' => ['matching', 'measuring', 'radar', 'thermometer', 'photo', 'tentacles'],
    ];

    $isArray = is_array($node);
    $isChipList = $isArray && isset($chipOptions[$lc]) && (count($node) === 0 || ! is_array(reset($node)));
    $isNumber = is_int($node) || is_float($node);
    $isList = $isArray ? array_is_list($node) : false;

    $segOpts = $segments[$lc] ?? null;
    if ($segOpts !== null && $node !== null && ! in_array($node, $segOpts, true)) {
        $segOpts[] = $node;
    }
    $isRadiusSlider = $isNumber && str_contains($lc, 'radius') && str_ends_with($lc, '_m');
    $unit = str_ends_with($lc, '_km') ? 'km' : (str_ends_with($lc, '_m') ? 'm' : (str_ends_with($lc, '_s') ? 's' : null));
    $wide = $isChipList || $isRadiusSlider || ($segOpts !== null && count($segOpts) >= 3);

    // Keep the page scannable: open plain objects, but collapse lists and anything nested in a list.
    $open = ! $isList && ! $parentIsList;
    $dataKey = $lc;

    // A one-line preview of an object's first scalar fields, so collapsed items are self-describing.
    $preview = null;
    if ($isArray && ! $isList) {
        $bits = [];
        foreach ($node as $pk => $pv) {
            if (! is_array($pv)) {
                $bits[] = $pk . ': ' . (is_bool($pv) ? ($pv ? 'true' : 'false') : (is_null($pv) ? 'null' : (string) $pv));
            }
            if (count($bits) >= 2) {
                break;
            }
        }
        $preview = implode(' · ', $bits);
        $preview = mb_strlen($preview) > 64 ? mb_substr($preview, 0, 64) . '…' : $preview;
    }
@endphp

@if ($isArray && ! $isChipList)
    <details class="jt-obj" data-key="{{ $dataKey }}" @if ($open) open @endif>
        <summary class="jt-obj-head">
            <x-filament::icon icon="heroicon-m-chevron-right" class="jt-chevron" />
            <span class="jt-key">{{ $label }}</span>
            <span class="jt-badge">{{ $isList ? 'list' : 'object' }} · {{ count($node) }}</span>
            @if ($preview)<span class="jt-preview">{{ $preview }}</span>@endif
        </summary>
        <div class="jt-grid">
            @forelse ($node as $k => $v)
                @include('filament.forms.components.json-node', [
                    'node' => $v,
                    'path' => $path . '.' . $k,
                    'label' => $isList ? '#' . $k : $k,
                    'depth' => $depth + 1,
                    'disabled' => $disabled,
                    'parentIsList' => $isList,
                ])
            @empty
                <div class="jt-empty">empty</div>
            @endforelse
        </div>
    </details>
@else
    <div class="jt-block @if ($wide) jt-wide @endif" data-key="{{ $dataKey }}">
        <div class="jt-key">{{ $label }}</div>

        @if ($isChipList)
            <div class="jt-chips">
                @foreach ($chipOptions[$lc] as $opt)
                    <label class="jt-chip">
                        <input type="checkbox" wire:model="{{ $path }}" value="{{ $opt }}" @checked(in_array($opt, $node, true)) @disabled($disabled)>
                        <span>{{ $opt }}</span>
                    </label>
                @endforeach
            </div>
        @elseif (is_bool($node))
            <label class="jt-switch">
                <input type="checkbox" wire:model="{{ $path }}" @checked($node) @disabled($disabled)>
                <span class="jt-knob"></span>
            </label>
        @elseif ($segOpts !== null)
            <div class="jt-seg">
                @foreach ($segOpts as $opt)
                    <label>
                        <input type="radio" name="{{ $path }}" wire:model="{{ $path }}" value="{{ $opt }}" @checked($node === $opt) @disabled($disabled)>
                        <span>{{ $opt }}</span>
                    </label>
                @endforeach
            </div>
        @elseif ($isRadiusSlider && ! $disabled)
            <div class="jt-numwrap">
                <input type="range" min="0" max="{{ max(500, (int) $node * 2) }}" step="25" value="{{ $node }}"
                       wire:model.number="{{ $path }}" oninput="this.parentNode.querySelector('.jt-num').value=this.value">
                <input type="number" step="any" class="jt-num" style="width:96px;flex:none" value="{{ $node }}"
                       wire:model.number="{{ $path }}" oninput="this.parentNode.querySelector('input[type=range]').value=this.value">
                <span class="jt-unit">m</span>
            </div>
        @elseif ($isNumber)
            <div class="jt-numwrap">
                <input type="number" step="any" class="jt-num" value="{{ $node }}" wire:model.number="{{ $path }}" @disabled($disabled)>
                @if ($unit)<span class="jt-unit">{{ $unit }}</span>@endif
            </div>
        @else
            <input type="text" class="jt-num" value="{{ $node }}" wire:model="{{ $path }}" @if (is_null($node)) placeholder="null" @endif @disabled($disabled)>
        @endif
    </div>
@endif
