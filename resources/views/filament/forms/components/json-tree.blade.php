<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <style>
        .jt .jt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;align-items:start}
        .jt .jt-wide{grid-column:1/-1}
        .jt .jt-block{background:#fff;border:1px solid rgb(17 24 39 / .08);border-radius:12px;padding:10px 12px;min-width:0}
        .dark .jt .jt-block{background:rgb(255 255 255 / .03);border-color:rgb(255 255 255 / .08)}
        .jt details.jt-obj{grid-column:1/-1;border:1px solid rgb(17 24 39 / .08);border-radius:12px;padding:8px 12px;background:rgb(17 24 39 / .02)}
        .dark .jt details.jt-obj{border-color:rgb(255 255 255 / .08);background:rgb(255 255 255 / .02)}
        .jt details.jt-obj>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;padding:2px 0}
        .jt details.jt-obj>summary::-webkit-details-marker{display:none}
        .jt details.jt-obj[open]>summary{margin-bottom:10px}
        .jt .jt-chevron{width:14px;height:14px;flex:none;color:rgb(148 163 184);transition:transform .12s}
        .jt details.jt-obj[open]>summary .jt-chevron{transform:rotate(90deg)}
        .jt .jt-key{display:flex;align-items:center;gap:6px;font-size:11px;letter-spacing:.02em;color:rgb(107 114 128);font-family:ui-monospace,monospace;margin-bottom:7px;min-width:0}
        .jt summary .jt-key{margin-bottom:0}
        .dark .jt .jt-key{color:rgb(148 163 184)}
        .jt .jt-dot{width:8px;height:8px;border-radius:2px;flex:none}
        .jt .jt-badge{font-size:10px;font-family:ui-monospace,monospace;color:rgb(107 114 128);background:rgb(17 24 39 / .05);padding:2px 7px;border-radius:6px;flex:none}
        .dark .jt .jt-badge{background:rgb(255 255 255 / .08);color:rgb(148 163 184)}
        .jt .jt-preview{flex:1;min-width:0;text-align:right;font-size:11px;color:rgb(148 163 184);font-family:ui-monospace,monospace;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .jt .jt-item{display:flex;align-items:center;gap:8px;flex:1;min-width:0}
        .jt .jt-item-ic{font-size:14px;flex:none}
        .jt .jt-item-name{font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .jt .jt-item-sub{font-size:11px;color:rgb(148 163 184);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .jt .jt-item-tag{font-size:10px;padding:1px 8px;border-radius:999px;text-transform:capitalize;flex:none}
        .jt .jt-item-seq{font-size:11px;color:rgb(148 163 184);margin-left:auto;font-family:ui-monospace,monospace;flex:none}
        .jt input.jt-num{width:100%;box-sizing:border-box;border:1px solid rgb(17 24 39 / .14);border-radius:8px;padding:7px 10px;font-size:13px;background:#fff;color:inherit}
        .dark .jt input.jt-num{background:rgb(255 255 255 / .05);border-color:rgb(255 255 255 / .14);color:#fff}
        .jt input.jt-num:focus{outline:0;border-color:rgb(var(--primary-500,244 63 94));box-shadow:0 0 0 3px rgb(var(--primary-500,244 63 94) / .15)}
        .jt input.jt-idinput{font-size:11px;padding:5px 8px;color:rgb(107 114 128);font-family:ui-monospace,monospace}
        .jt .jt-numwrap{display:flex;align-items:center;gap:10px;min-width:0}
        .jt .jt-unit{font-size:12px;color:rgb(107 114 128);flex:none}
        .jt .jt-idcard{display:flex;align-items:center;gap:8px;margin-bottom:6px;min-width:0}
        .jt .jt-avatar{width:26px;height:26px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;flex:none}
        .jt .jt-avatar.sm{width:18px;height:18px;font-size:9px}
        .jt .jt-avatar img{width:100%;height:100%;object-fit:cover}
        .jt .jt-idname{font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .jt .jt-role{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:rgb(148 163 184);margin-left:6px}
        .jt .jt-refchip{display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;border:1px solid;background:rgb(124 58 237 / .08);margin-bottom:6px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .jt .jt-tshint{font-size:11px;color:rgb(37 99 235);margin-top:5px}
        .dark .jt .jt-tshint{color:rgb(147 197 253)}
        .jt .jt-seg{display:flex;width:100%;border:1px solid rgb(17 24 39 / .14);border-radius:8px;overflow:hidden}
        .dark .jt .jt-seg{border-color:rgb(255 255 255 / .14)}
        .jt .jt-seg label{flex:1;position:relative;cursor:pointer;min-width:0}
        .jt .jt-seg label+label{border-left:1px solid rgb(17 24 39 / .1)}
        .dark .jt .jt-seg label+label{border-left-color:rgb(255 255 255 / .1)}
        .jt .jt-seg input{position:absolute;inset:0;opacity:0;margin:0;cursor:pointer}
        .jt .jt-seg span{display:block;text-align:center;padding:7px 8px;font-size:12px;color:rgb(75 85 99);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .dark .jt .jt-seg span{color:rgb(203 213 225)}
        .jt .jt-seg input:checked+span{background:rgb(79 70 229);color:#fff}
        .jt .jt-chips{display:flex;flex-wrap:wrap;gap:6px}
        .jt .jt-chip{position:relative;cursor:pointer}
        .jt .jt-chip input{position:absolute;opacity:0;margin:0}
        .jt .jt-chip span{display:inline-block;padding:5px 12px;border-radius:999px;font-size:12px;border:1px solid rgb(17 24 39 / .14);color:rgb(75 85 99)}
        .dark .jt .jt-chip span{border-color:rgb(255 255 255 / .14);color:rgb(203 213 225)}
        .jt .jt-chip input:checked+span{background:rgb(var(--primary-600,225 29 72));color:#fff;border-color:transparent}
        .jt .jt-switch{position:relative;display:inline-block;width:36px;height:21px;cursor:pointer}
        .jt .jt-switch input{position:absolute;opacity:0;margin:0}
        .jt .jt-knob{position:absolute;inset:0;border-radius:999px;background:rgb(217 119 6 / .4)}
        .jt .jt-knob::after{content:"";position:absolute;top:2px;left:2px;width:17px;height:17px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgb(0 0 0 / .15)}
        .jt .jt-switch input:checked+.jt-knob{background:rgb(217 119 6)}
        .jt .jt-switch input:checked+.jt-knob::after{left:16px}
        .jt input[type=range]{flex:1;min-width:0;accent-color:rgb(var(--primary-600,225 29 72))}
        .jt .jt-empty{font-size:12px;color:rgb(148 163 184);font-family:ui-monospace,monospace}
        .jt input:disabled{opacity:.55;cursor:not-allowed}
        .jt .jt-toolbar{display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
        .jt .jt-tbtn{font-size:12px;padding:7px 11px;border:1px solid rgb(17 24 39 / .14);border-radius:8px;background:#fff;color:rgb(75 85 99);cursor:pointer}
        .dark .jt .jt-tbtn{background:rgb(255 255 255 / .05);border-color:rgb(255 255 255 / .14);color:rgb(203 213 225)}
        .jt .jt-tbtn:hover{border-color:rgb(var(--primary-500,244 63 94))}
        .jt .jt-legend{display:flex;flex-wrap:wrap;gap:8px 14px;margin-bottom:14px;font-size:11px;color:rgb(148 163 184)}
        .jt .jt-legend span{display:inline-flex;align-items:center;gap:5px}
        .jt .jt-map{height:170px;border-radius:10px;overflow:hidden;margin-bottom:10px;border:1px solid rgb(17 24 39 / .1);background:rgb(17 24 39 / .03)}
        .dark .jt .jt-map{border-color:rgb(255 255 255 / .12)}
        .jt .jt-thumb{max-height:72px;max-width:100%;border-radius:8px;border:1px solid rgb(17 24 39 / .12);display:block;margin-bottom:6px;object-fit:cover}
        .jt .jt-swatch{width:22px;height:22px;border-radius:6px;border:1px solid rgb(17 24 39 / .18);flex:none}
    </style>
    @php
        $record = $getRecord();
        $refs = ['players' => [], 'cards' => [], 'questions' => []];
        if ($record && $record->exists) {
            foreach ($record->players()->with('user')->get() as $p) {
                $info = ['name' => $p->display_name, 'avatar' => $p->user?->avatar, 'role' => $p->role];
                $refs['players'][$p->id] = $info;
                if ($p->user_id) {
                    $refs['players']['u' . $p->user_id] = $info;
                }
            }
            $str = fn ($v) => is_array($v) ? (string) (array_values($v)[0] ?? '') : (string) $v;
            $refs['cards'] = \App\Models\Card::query()->get(['id', 'name'])->mapWithKeys(fn ($c) => [$c->id => $str($c->name)])->all();
            $refs['questions'] = \App\Models\Question::query()->get(['id', 'title'])->mapWithKeys(fn ($q) => [$q->id => $str($q->title)])->all();
        }
    @endphp
    <div class="jt" x-data="{
        q: '',
        toggleAll(open) { this.$el.querySelectorAll('details.jt-obj').forEach(d => d.open = open); },
        filter() {
            const q = this.q.trim().toLowerCase();
            let shown = 0;
            this.$el.querySelectorAll(':scope > .jt-grid > [data-key]').forEach(el => {
                const match = !q || el.dataset.key.includes(q);
                el.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            this.$refs.empty.style.display = shown === 0 ? '' : 'none';
        },
        ensureLeaflet(cb) {
            if (window.L) return cb();
            if (!document.getElementById('jt-leaflet-css')) {
                const l = document.createElement('link');
                l.id = 'jt-leaflet-css'; l.rel = 'stylesheet'; l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(l);
            }
            window.__jtCbs = window.__jtCbs || []; window.__jtCbs.push(cb);
            if (window.__jtLoading) return; window.__jtLoading = true;
            const s = document.createElement('script'); s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            s.onload = () => { (window.__jtCbs || []).forEach(f => f()); window.__jtCbs = []; };
            document.head.appendChild(s);
        },
        initMap(el) {
            if (el.__jtInited) return; el.__jtInited = true;
            this.ensureLeaflet(() => {
                const lat = parseFloat(el.dataset.lat), lng = parseFloat(el.dataset.lng), r = parseFloat(el.dataset.radius || '0');
                if (isNaN(lat) || isNaN(lng)) return;
                const map = L.map(el, { attributionControl: false, zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false });
                L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { subdomains: 'abcd', maxZoom: 19 }).addTo(map);
                L.circleMarker([lat, lng], { radius: 6, color: '#e11d48', weight: 2, fillColor: '#e11d48', fillOpacity: 1 }).addTo(map);
                if (r > 0) { const c = L.circle([lat, lng], { radius: r, color: '#e11d48', weight: 1, fillColor: '#e11d48', fillOpacity: 0.08 }).addTo(map); map.fitBounds(c.getBounds().pad(0.25)); }
                else { map.setView([lat, lng], 15); }
                el.__jtMap = map;
                setTimeout(() => map.invalidateSize(), 60);
                setTimeout(() => map.invalidateSize(), 350);
            });
        },
        initMaps() {
            const self = this;
            const io = ('IntersectionObserver' in window) ? new IntersectionObserver((entries) => {
                entries.forEach((e) => { if (!e.isIntersecting) return; const el = e.target; if (!el.__jtInited) self.initMap(el); else if (el.__jtMap) el.__jtMap.invalidateSize(); });
            }, { threshold: 0.01 }) : null;
            this.$el.querySelectorAll('.jt-map').forEach((el) => { if (el.__jtObserved) return; el.__jtObserved = true; if (io) io.observe(el); else self.initMap(el); });
        },
    }" x-init="$nextTick(() => initMaps())">
        <div class="jt-legend">
            <span><i class="jt-dot" style="background:#7c3aed"></i>reference</span>
            <span><i class="jt-dot" style="background:#2563eb"></i>time</span>
            <span><i class="jt-dot" style="background:#0d9488"></i>location</span>
            <span><i class="jt-dot" style="background:#d97706"></i>flag</span>
            <span><i class="jt-dot" style="background:#4f46e5"></i>option</span>
            <span><i class="jt-dot" style="background:#e11d48"></i>measure</span>
        </div>
        <div class="jt-toolbar">
            <input type="search" placeholder="Filter keys…" x-model="q" @input="filter()" class="jt-num" style="max-width:240px">
            <button type="button" class="jt-tbtn" @click="toggleAll(true)">Expand all</button>
            <button type="button" class="jt-tbtn" @click="toggleAll(false)">Collapse all</button>
        </div>
        @php $state = $getState() ?? []; @endphp
        <div class="jt-grid">
            @forelse ($state as $key => $value)
                @include('filament.forms.components.json-node', [
                    'node' => $value, 'path' => $getStatePath() . '.' . $key, 'label' => $key,
                    'depth' => 0, 'disabled' => $isDisabled(), 'parentIsList' => false, 'refs' => $refs,
                ])
            @empty
                <p class="jt-empty">Empty — nothing set yet.</p>
            @endforelse
        </div>
        <p class="jt-empty" x-ref="empty" style="display:none;margin-top:8px">No keys match that filter.</p>
    </div>
</x-dynamic-component>
