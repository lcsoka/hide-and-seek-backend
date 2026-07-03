{{-- Recursive block. Objects/lists → collapsible <details> (known lists like hand/questions get a
     rich summary card); scalars → a smart/typed field-block coloured by category, with references
     (players/curses/questions) + timestamps rendered nicely. Bound to form state at $path. --}}
@php
    $parentIsList = $parentIsList ?? false;
    $listKind = $listKind ?? null;
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
    $listKinds = ['hand' => 'card', 'cards' => 'card', 'deckpool' => 'card', 'deck' => 'card', 'questions' => 'question', 'curses_played' => 'curse', 'curses' => 'curse', 'transit_log' => 'transit'];
    $catEmoji = ['radar' => '📡', 'thermometer' => '🌡️', 'matching' => '🧩', 'measuring' => '📏', 'tentacles' => '🐙', 'photo' => '📷'];

    $isArray = is_array($node);
    $isChipList = $isArray && isset($chipOptions[$lc]) && (count($node) === 0 || ! is_array(reset($node)));
    $isNumber = is_int($node) || is_float($node);
    $isList = $isArray ? array_is_list($node) : false;
    $childListKind = ($isArray && $isList) ? ($listKinds[$lc] ?? null) : null;

    $segOpts = $segments[$lc] ?? null;
    if ($segOpts !== null && $node !== null && ! in_array($node, $segOpts, true)) {
        $segOpts[] = $node;
    }
    $isRadiusSlider = $isNumber && str_contains($lc, 'radius') && str_ends_with($lc, '_m');
    $unit = str_ends_with($lc, '_km') ? 'km' : (str_ends_with($lc, '_m') ? 'm' : (str_ends_with($lc, '_s') ? 's' : null));
    $wide = $isChipList || $isRadiusSlider || ($segOpts !== null && count($segOpts) >= 3);

    $isPlayerRef = ! $isArray && $node !== null && in_array($lc, $playerIdKeys, true);
    $isUserRef = ! $isArray && $node !== null && in_array($lc, $userIdKeys, true);
    $isCurseRef = ! $isArray && $node !== null && $lc === 'curse_id';
    $isQuestionRef = ! $isArray && $node !== null && $lc === 'question_id';
    $isTime = ! $isArray && is_numeric($node) && $node > 0 && (str_ends_with($lc, '_at') || str_ends_with($lc, 'deadline') || in_array($lc, ['at', 'now', 'deadline'], true));
    $isLatLng = ! $isArray && in_array($lc, ['lat', 'lng', 'latitude', 'longitude'], true);

    $player = $isPlayerRef ? ($refs['players'][$node] ?? null) : ($isUserRef ? ($refs['players']['u' . $node] ?? null) : null);
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

    $sstr = fn ($v, $d = null) => is_scalar($v) ? (string) $v : $d;

    // Image / colour / duration leaves (nicer display than a bare input).
    $isImageUrl = ! $isArray && is_string($node) && (str_ends_with($lc, '_url') || $lc === 'avatar') && (str_starts_with($node, 'http') || str_starts_with($node, '/'));
    $isColor = ! $isArray && is_string($node) && in_array($lc, ['color', 'colour'], true) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $node);
    $isDuration = ! $isArray && is_numeric($node) && ! $isTime && ($unit === 's' || $keyPlayer !== null);
    $fmtDur = function ($v) {
        $s = max(0, (int) $v);
        $h = intdiv($s, 3600);
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    };

    // Rich summary pill for known list items (hand cards, questions, curses).
    $pill = null;
    if ($isArray && $listKind === 'card') {
        $t = $sstr($node['type'] ?? null);
        $mins = $node['minutes'] ?? null;
        $minLabel = is_array($mins) ? implode('/', array_map('strval', $mins)) : $sstr($mins, '?');
        $pill = [
            'name' => $sstr($node['name'] ?? null) ?: ($t === 'time_bonus' ? '+' . $minLabel . ' min' : ($sstr($node['power'] ?? null) ?: ($refs['cards'][$node['curse_id'] ?? ''] ?? 'Card'))),
            'tag' => $t, 'color' => $t === 'curse' ? '#7c3aed' : ($t === 'powerup' ? '#2563eb' : '#16a34a'),
            'sub' => $sstr($node['cost'] ?? null), 'icon' => null, 'seq' => null,
        ];
    } elseif ($isArray && $listKind === 'question') {
        $qc = $sstr($node['category'] ?? '', '');
        $ask = $refs['players'][$node['asked_by'] ?? ''] ?? null;
        $ans = is_array($node['answer'] ?? null) ? $sstr($node['answer']['answer'] ?? null) : $sstr($node['answer'] ?? null);
        $pill = [
            'name' => $qc !== '' ? ucfirst($qc) : 'Question', 'icon' => $catEmoji[$qc] ?? '❓',
            'sub' => $ask ? 'by ' . $ask['name'] : null, 'tag' => $ans,
            'color' => in_array($ans, ['yes', 'in_range', 'closer', 'hotter'], true) ? '#16a34a' : (in_array($ans, ['no', 'out_of_range', 'further', 'colder'], true) ? '#dc2626' : '#64748b'),
            'seq' => is_numeric($node['seq'] ?? null) ? $node['seq'] : null,
        ];
    } elseif ($isArray && $listKind === 'curse') {
        $by = $refs['players'][$node['by'] ?? ''] ?? null;
        $pill = [
            'name' => ($refs['cards'][$node['curse_id'] ?? ''] ?? null) ?: ($sstr($node['name'] ?? null) ?: 'Curse'),
            'sub' => $by ? 'by ' . $by['name'] : null, 'tag' => $sstr($node['status'] ?? null),
            'color' => '#7c3aed', 'icon' => null, 'seq' => null,
        ];
    } elseif ($isArray && $listKind === 'transit') {
        $pl = $refs['players'][$node['player_id'] ?? ''] ?? null;
        $line = $sstr($node['line'] ?? null);
        $mode = $sstr($node['mode'] ?? null);
        $dur = isset($node['duration_s']) && is_numeric($node['duration_s']) ? $fmtDur($node['duration_s']) : null;
        $pill = [
            'name' => $line ?: ($mode ?: 'Leg'), 'icon' => '🚆',
            'sub' => trim(($pl ? $pl['name'] : '') . ($dur ? ' · ' . $dur : '')) ?: null,
            'tag' => $mode, 'color' => '#0891b2', 'seq' => null,
        ];
    }

    // Location map for objects carrying coordinates.
    $mapLat = $mapLng = $mapRadius = null;
    if ($isArray && ! $isList) {
        if (isset($node['lat'], $node['lng']) && is_numeric($node['lat']) && is_numeric($node['lng'])) {
            $mapLat = (float) $node['lat'];
            $mapLng = (float) $node['lng'];
        } elseif (isset($node['center']) && is_array($node['center'])) {
            $c = $node['center'];
            if (isset($c['lat'], $c['lng']) && is_numeric($c['lat'])) {
                $mapLat = (float) $c['lat'];
                $mapLng = (float) $c['lng'];
            } elseif (array_is_list($c) && count($c) >= 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                $mapLat = (float) $c[0];
                $mapLng = (float) $c[1];
            }
            $mapRadius = isset($node['radius_m']) && is_numeric($node['radius_m']) ? (float) $node['radius_m'] : null;
        }
    }
@endphp

@if ($isArray && ! $isChipList)
    <details class="jt-obj" data-key="{{ $lc }}" @if ($open) open @endif>
        <summary>
            <x-filament::icon icon="heroicon-m-chevron-right" class="jt-chevron" />
            @if ($pill)
                <span class="jt-item">
                    @if ($pill['icon'])<span class="jt-item-ic">{{ $pill['icon'] }}</span>@endif
                    <span class="jt-item-name">{{ $pill['name'] }}</span>
                    @if ($pill['sub'])<span class="jt-item-sub">{{ $pill['sub'] }}</span>@endif
                    @if ($pill['tag'])<span class="jt-item-tag" style="color:{{ $pill['color'] }};border:1px solid {{ $pill['color'] }}55;background:{{ $pill['color'] }}14">{{ $pill['tag'] }}</span>@endif
                    @if ($pill['seq'] !== null)<span class="jt-item-seq">#{{ $pill['seq'] }}</span>@endif
                </span>
            @else
                <span class="jt-key"><i class="jt-dot" style="background:{{ $cat }}"></i>{{ $label }}</span>
                <span class="jt-badge">{{ $isList ? 'list' : 'object' }} · {{ count($node) }}</span>
                @if ($preview)<span class="jt-preview">{{ $preview }}</span>@endif
            @endif
        </summary>
        @if ($mapLat !== null)
            <div class="jt-map" wire:ignore data-lat="{{ $mapLat }}" data-lng="{{ $mapLng }}" @if ($mapRadius) data-radius="{{ $mapRadius }}" @endif></div>
        @endif
        <div class="jt-grid">
            @forelse ($node as $k => $v)
                @include('filament.forms.components.json-node', [
                    'node' => $v, 'path' => $path . '.' . $k, 'label' => $isList ? '#' . $k : $k,
                    'depth' => $depth + 1, 'disabled' => $disabled, 'parentIsList' => $isList,
                    'refs' => $refs, 'listKind' => $childListKind,
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
        @elseif ($isImageUrl)
            <a href="{{ $node }}" target="_blank" rel="noopener"><img class="jt-thumb" src="{{ $node }}" alt="" loading="lazy"></a>
            <input type="text" class="jt-num jt-idinput" value="{{ $node }}" wire:model="{{ $path }}" @disabled($disabled)>
        @elseif ($isColor)
            <div class="jt-numwrap"><span class="jt-swatch" style="background:{{ $node }}"></span><input type="text" class="jt-num" value="{{ $node }}" wire:model="{{ $path }}" @disabled($disabled)></div>
        @elseif ($isTime)
            <div class="jt-numwrap"><input type="number" step="1" class="jt-num" value="{{ $node }}" wire:model.number="{{ $path }}" @disabled($disabled)><span class="jt-unit">unix</span></div>
            <div class="jt-tshint">{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $node)->timezone(config('app.timezone', 'UTC'))->format('M j, Y · H:i:s') }}</div>
        @elseif ($isDuration)
            <div class="jt-numwrap"><input type="number" step="any" class="jt-num" value="{{ $node }}" wire:model.number="{{ $path }}" @disabled($disabled)><span class="jt-unit">s</span></div>
            <div class="jt-tshint" style="color:#e11d48">{{ $fmtDur($node) }}</div>
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
