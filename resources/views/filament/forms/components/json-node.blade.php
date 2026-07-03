{{-- Recursive block. Objects/lists → collapsible <details>; scalars → a smart/typed field-block
     coloured by semantic category, with references (player/curse/question ids) and timestamps
     rendered nicely. Bound to form state at $path. --}}
@php
    $parentIsList = $parentIsList ?? false;
    $refs = $refs ?? ['players' => [], 'cards' => [], 'questions' => []];
    $lc = is_string($label) ? strtolower($label) : (string) $label;

    $segments = [
        'units' => ['metric', 'imperial'], 'rule' => ['nearest', 'circle'], 'hiding_zone_rule' => ['nearest', 'circle'],
        'state' => ['lobby', 'hiding', 'seeking', 'round_end', 'finished'], 'game_mode' => ['hide_and_seek'],
        'game_size' => ['small', 'medium', 'large'], 'size' => ['small', 'medium', 'large'],
    ];
    $chipOptions = [
        'transit_modes' => ['metro', 'tram', 'bus', 'rail', 'light_rail', 'trolleybus'],
        'disabled_categories' => ['matching', 'measuring', 'radar', 'thermometer', 'photo', 'tentacles'],
    ];
    $playerIdKeys = ['hider_id', 'found_by', 'by', 'asked_by', 'player_id', 'host_player_id', 'seeker_id', 'winner_id', 'claimed_by'];
    $userIdKeys = ['host_user_id', 'user_id'];

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

    // Reference / timestamp / location detection (for colour + special rendering).
    $isPlayerRef = ! $isArray && $node !== null && in_array($lc, $playerIdKeys, true);
    $isUserRef = ! $isArray && $node !== null && in_array($lc, $userIdKeys, true);
    $isCurseRef = ! $isArray && $node !== null && $lc === 'curse_id';
    $isQuestionRef = ! $isArray && $node !== null && $lc === 'question_id';
    $isTime = ! $isArray && is_numeric($node) && $node > 0 && (str_ends_with($lc, '_at') || str_ends_with($lc, 'deadline') || in_array($lc, ['at', 'now', 'deadline'], true));
    $isLatLng = ! $isArray && in_array($lc, ['lat', 'lng', 'latitude', 'longitude'], true);

    // Resolve a player from the value (player uuid) or user id.
    $player = $isPlayerRef ? ($refs['players'][$node] ?? null) : ($isUserRef ? ($refs['players']['u' . $node] ?? null) : null);
    // Resolve the KEY as a player (e.g. a scores map keyed by player id).
    $keyPlayer = is_string($label) ? ($refs['players'][$label] ?? null) : null;

    if ($isArray) {
        $cat = '#64748b';
    } elseif ($isPlayerRef || $isUserRef || $isCurseRef || $isQuestionRef) {
        $cat = '#7c3aed';
    } elseif ($isTime) {
        $cat = '#2563eb';
    } elseif ($isLatLng) {
        $cat = '#0d9488';
    } elseif (is_bool($node)) {
        $cat = '#d97706';
    } elseif ($segOpts !== null) {
        $cat = '#4f46e5';
    } elseif ($isRadiusSlider || ($isNumber && $unit)) {
        $cat = '#e11d48';
    } else {
        $cat = '#94a3b8';
    }

    $open = ! $isList && ! $parentIsList;
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
    $avatarBg = fn ($role) => $role === 'hider' ? '#e11d48' : ($role === 'seeker' ? '#2563eb' : '#64748b');
@endphp

@if ($isArray && ! $isChipList)
    <details class="jt-obj" data-key="{{ $lc }}" @if ($open) open @endif>
        <summary>
            <x-filament::icon icon="heroicon-m-chevron-right" class="jt-chevron" />
            <span class="jt-key"><i class="jt-dot" style="background:{{ $cat }}"></i>{{ $label }}</span>
            <span class="jt-badge">{{ $isList ? 'list' : 'object' }} · {{ count($node) }}</span>
            @if ($preview)<span class="jt-preview">{{ $preview }}</span>@endif
        </summary>
        <div class="jt-grid">
            @forelse ($node as $k => $v)
                @include('filament.forms.components.json-node', [
                    'node' => $v, 'path' => $path . '.' . $k, 'label' => $isList ? '#' . $k : $k,
                    'depth' => $depth + 1, 'disabled' => $disabled, 'parentIsList' => $isList, 'refs' => $refs,
                ])
            @empty
                <div class="jt-empty">empty</div>
            @endforelse
        </div>
    </details>
@else
    <div class="jt-block @if ($wide) jt-wide @endif" data-key="{{ $lc }}">
        <div class="jt-key">
            <i class="jt-dot" style="background:{{ $cat }}"></i>
            @if ($keyPlayer)
                <span class="jt-avatar sm" style="background:{{ $avatarBg($keyPlayer['role']) }}">
                    @if ($keyPlayer['avatar'])<img src="{{ $keyPlayer['avatar'] }}" alt="">@else{{ mb_strtoupper(mb_substr($keyPlayer['name'] ?? '?', 0, 1)) }}@endif
                </span>
                <span>{{ $keyPlayer['name'] }}</span>
            @else
                {{ $label }}
            @endif
        </div>

        @if ($isChipList)
            <div class="jt-chips">
                @foreach ($chipOptions[$lc] as $opt)
                    <label class="jt-chip"><input type="checkbox" wire:model="{{ $path }}" value="{{ $opt }}" @checked(in_array($opt, $node, true)) @disabled($disabled)><span>{{ $opt }}</span></label>
                @endforeach
            </div>
        @elseif ($player)
            <div class="jt-idcard">
                <span class="jt-avatar" style="background:{{ $avatarBg($player['role']) }}">
                    @if ($player['avatar'])<img src="{{ $player['avatar'] }}" alt="">@else{{ mb_strtoupper(mb_substr($player['name'] ?? '?', 0, 1)) }}@endif
                </span>
                <span class="jt-idname">{{ $player['name'] }}@if ($player['role'])<span class="jt-role">{{ $player['role'] }}</span>@endif</span>
            </div>
            <input type="text" class="jt-num jt-idinput" value="{{ $node }}" wire:model="{{ $path }}" @disabled($disabled)>
        @elseif ($isCurseRef || $isQuestionRef)
            @php $name = $isCurseRef ? ($refs['cards'][$node] ?? null) : ($refs['questions'][$node] ?? null); @endphp
            @if ($name)<span class="jt-refchip" style="border-color:#7c3aed;color:#7c3aed">{{ $name }}</span>@endif
            <input type="text" class="jt-num jt-idinput" value="{{ $node }}" wire:model="{{ $path }}" @disabled($disabled)>
        @elseif ($isTime)
            <div class="jt-numwrap"><input type="number" step="1" class="jt-num" value="{{ $node }}" wire:model.number="{{ $path }}" @disabled($disabled)><span class="jt-unit">unix</span></div>
            <div class="jt-tshint">{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $node)->timezone(config('app.timezone', 'UTC'))->format('M j, Y · H:i:s') }}</div>
        @elseif (is_bool($node))
            <label class="jt-switch"><input type="checkbox" wire:model="{{ $path }}" @checked($node) @disabled($disabled)><span class="jt-knob"></span></label>
        @elseif ($segOpts !== null)
            <div class="jt-seg">
                @foreach ($segOpts as $opt)
                    <label><input type="radio" name="{{ $path }}" wire:model="{{ $path }}" value="{{ $opt }}" @checked($node === $opt) @disabled($disabled)><span>{{ $opt }}</span></label>
                @endforeach
            </div>
        @elseif ($isRadiusSlider && ! $disabled)
            <div class="jt-numwrap">
                <input type="range" min="0" max="{{ max(500, (int) $node * 2) }}" step="25" value="{{ $node }}" wire:model.number="{{ $path }}" oninput="this.parentNode.querySelector('.jt-num').value=this.value">
                <input type="number" step="any" class="jt-num" style="width:96px;flex:none" value="{{ $node }}" wire:model.number="{{ $path }}" oninput="this.parentNode.querySelector('input[type=range]').value=this.value">
                <span class="jt-unit">m</span>
            </div>
        @elseif ($isNumber)
            <div class="jt-numwrap"><input type="number" step="any" class="jt-num" value="{{ $node }}" wire:model.number="{{ $path }}" @disabled($disabled)>@if ($unit)<span class="jt-unit">{{ $unit }}</span>@endif</div>
        @else
            <input type="text" class="jt-num" value="{{ $node }}" wire:model="{{ $path }}" @if (is_null($node)) placeholder="null" @endif @disabled($disabled)>
        @endif
    </div>
@endif
