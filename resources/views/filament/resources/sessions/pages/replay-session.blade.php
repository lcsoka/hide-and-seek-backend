<x-filament-panels::page>
    <style>
        @keyframes jtr-pulse { 0% { transform: scale(.5); opacity: .85; } 100% { transform: scale(2.4); opacity: 0; } }
        .jtr-ring { position: absolute; inset: 0; border-radius: 9999px; border: 2px solid currentColor; animation: jtr-pulse 1.1s ease-out infinite; }
        .jtr-banner { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 500; }
    </style>
    <div x-data="replayApp(@js($this->replayBundle()))" x-init="init()" class="grid gap-4 lg:grid-cols-3">
        {{-- Map + transport controls --}}
        <div class="space-y-3 lg:col-span-2">
            <div class="relative">
                <div x-ref="map" wire:ignore class="h-[520px] w-full overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10"></div>
                <div class="jtr-banner" x-show="banner" x-transition style="display:none">
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm font-medium text-white shadow-lg"
                          :style="`background:${banner ? banner.color : '#111'}`">
                        <span x-text="banner ? banner.icon : ''"></span>
                        <span x-text="banner ? banner.text : ''"></span>
                    </span>
                </div>
            </div>

            <div class="space-y-2.5 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                {{-- Round switcher (only for multi-round games) --}}
                <div x-show="rounds.length > 1" style="display:none" class="flex items-center gap-1.5">
                    <span class="mr-1 text-xs font-medium text-gray-400">Round</span>
                    <template x-for="r in rounds" :key="r.round">
                        <button type="button" @click="gotoRound(r)"
                                class="rounded-lg px-3 py-1 text-xs font-semibold transition"
                                :class="activeRound && activeRound.round === r.round ? 'bg-primary-600 text-white shadow' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10'"
                                x-text="r.round"></button>
                    </template>
                </div>

                {{-- Transport --}}
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" @click="toggle()" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500">
                        <span x-text="playing ? '⏸' : '▶'"></span><span x-text="playing ? 'Pause' : 'Play'"></span>
                    </button>
                    <input type="range" :min="bundle.t0" :max="bundle.t1" step="1" x-model.number="time" @input="render()" class="h-2 flex-1 cursor-pointer accent-primary-600">
                    <select x-model.number="speed" class="rounded-lg border-gray-300 bg-white py-1 text-sm dark:border-white/10 dark:bg-gray-800">
                        <option :value="1">1× realtime</option><option :value="2">2×</option><option :value="5">5×</option><option :value="15">15×</option><option :value="30">30×</option><option :value="60">60×</option><option :value="120">120×</option>
                    </select>
                    <span class="tabular-nums text-sm font-medium text-gray-500 dark:text-gray-400"><span x-text="rel(time)"></span> / <span x-text="rel(bundle.t1)"></span></span>
                </div>

                {{-- Current action at the scrubbed moment --}}
                <div class="flex items-center gap-2 border-t border-gray-100 pt-2 text-sm dark:border-white/10">
                    <span class="shrink-0 text-xs font-medium uppercase tracking-wide text-gray-400">Now</span>
                    <template x-if="currentAction">
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <span class="text-base leading-none" x-text="icon(currentAction.kind)"></span>
                            <span class="truncate font-medium" x-text="currentAction.label"></span>
                            <span class="shrink-0 text-xs text-gray-400" x-text="currentAction.by ? '· ' + currentAction.by : ''"></span>
                        </span>
                    </template>
                    <template x-if="!currentAction"><span class="text-gray-400">Waiting for the first move…</span></template>
                </div>
            </div>

            {{-- Layer toggles --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="showDeduction = !showDeduction; render()"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 transition"
                        :class="showDeduction ? 'bg-green-50 text-green-700 ring-green-600/30 dark:bg-green-500/10 dark:text-green-300' : 'bg-gray-50 text-gray-500 ring-gray-950/10 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10'">
                    <span class="h-2.5 w-2.5 rounded-sm bg-green-600"></span> Deduction cuts
                </button>
                <button type="button" @click="showTraces = !showTraces; render()"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ring-1 transition"
                        :class="showTraces ? 'bg-primary-50 text-primary-700 ring-primary-600/30 dark:bg-primary-500/10 dark:text-primary-300' : 'bg-gray-50 text-gray-500 ring-gray-950/10 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10'">
                    <span class="h-2.5 w-2.5 rounded-full bg-primary-600"></span> Movement trails
                </button>
                <template x-for="p in bundle.players" :key="p.id">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium dark:bg-white/5">
                        <span class="h-2.5 w-2.5 rounded-full" :style="`background:${p.color}`"></span>
                        <span x-text="p.name"></span><span class="text-gray-400" x-text="p.role ? '· ' + p.role : ''"></span>
                    </span>
                </template>
            </div>

            {{-- Map symbol legend --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full border border-dashed border-gray-400"></span> Radar range</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full border-2 border-gray-400 bg-white"></span>→ 🌡️ Thermometer start → end</span>
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full bg-green-600"></span> hotter / <span class="inline-block h-2.5 w-2.5 rounded-full bg-red-600"></span> colder</span>
            </div>
        </div>

        {{-- Event feed --}}
        <div class="flex max-h-[600px] flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-white/10">
                Timeline <span class="font-normal text-gray-400" x-text="`· ${bundle.events.length} events`"></span>
            </div>
            <div class="min-h-0 flex-1 overflow-auto p-2">
                <template x-if="bundle.events.length === 0"><p class="p-3 text-sm text-gray-400">No recorded events for this game.</p></template>
                <template x-for="(e, i) in bundle.events" :key="i">
                    <button type="button" @click="seek(e.at)" class="flex w-full items-start gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm transition"
                            :class="i === currentEventIndex ? 'bg-primary-50 dark:bg-primary-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'">
                        <span class="w-10 shrink-0 pt-0.5 text-right font-mono text-xs text-gray-400" x-text="rel(e.at)"></span>
                        <span class="text-base leading-none" x-text="icon(e.kind)"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="e.label"></span>
                            <span class="block truncate text-xs text-gray-400" x-text="e.by || ''"></span>
                        </span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    <script>
        window.replayApp = function (bundle) {
            return {
                bundle,
                time: bundle.t0,
                playing: false,
                speed: 30,
                showDeduction: true,
                showTraces: false,
                banner: null,
                map: null,
                markers: {},
                qLayer: null, dedLayer: null, traceLayer: null, evLayer: null,
                _timer: null, _dedKey: null, _dedGeo: null,

                async init() {
                    await Promise.all([this.load('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', () => window.L),
                        this.load('https://unpkg.com/@turf/turf@7/turf.min.js', null, () => window.turf)]);
                    this.setupMap();
                    this.render();
                },

                load(src, css, ready) {
                    return new Promise((resolve) => {
                        if (ready()) return resolve();
                        if (css && !document.querySelector(`link[href="${css}"]`)) {
                            const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = css; document.head.appendChild(l);
                        }
                        let s = document.querySelector(`script[src="${src}"]`);
                        if (s) { s.addEventListener('load', () => resolve()); if (ready()) resolve(); return; }
                        s = document.createElement('script'); s.src = src; s.onload = () => resolve(); document.head.appendChild(s);
                    });
                },

                setupMap() {
                    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    this.map = L.map(this.$refs.map, { attributionControl: false });
                    L.tileLayer(dark ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                        { subdomains: 'abcd', maxZoom: 19 }).addTo(this.map);

                    const pts = [];
                    this.bundle.players.forEach((p) => p.track.forEach((s) => pts.push([s[1], s[2]])));
                    this.bundle.players.forEach((p) => { if (p.last) pts.push(p.last); });
                    this.bundle.questions.forEach((q) => { if (q.ask) pts.push([q.ask.lat, q.ask.lng]); });
                    this.zoneList().forEach((z) => pts.push([z.lat, z.lng]));
                    if (this.bundle.playAreaGeo && window.turf) { const bb = turf.bbox(this.bundle.playAreaGeo); pts.push([bb[1], bb[0]], [bb[3], bb[2]]); }
                    if (pts.length) this.map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 });
                    else this.map.setView([47.4979, 19.0402], 12);

                    this.zoneLayer = L.layerGroup().addTo(this.map);
                    this.dedLayer = L.layerGroup().addTo(this.map);
                    this.traceLayer = L.layerGroup().addTo(this.map);
                    this.qLayer = L.layerGroup().addTo(this.map);
                    this.evLayer = L.layerGroup().addTo(this.map);
                    this.bundle.players.forEach((p) => {
                        const m = L.marker([0, 0], { icon: this.playerIcon(p), opacity: 0, zIndexOffset: 1000 }).addTo(this.map);
                        m.bindTooltip(p.name);
                        this.markers[p.id] = m;
                    });
                },

                playerIcon(p) {
                    const ring = `box-shadow:0 0 0 2px #fff, 0 0 0 4px ${p.color}`;
                    const inner = p.avatar ? `background:${p.color} center/cover no-repeat url('${p.avatar}')` : `background:${p.color};color:#fff;display:flex;align-items:center;justify-content:center;font:700 12px system-ui`;
                    return L.divIcon({ className: '', iconSize: [30, 30], iconAnchor: [15, 15], html: `<div style="width:30px;height:30px;border-radius:9999px;${inner};${ring}">${p.avatar ? '' : this.initials(p.name)}</div>` });
                },
                initials(n) { return (n || '?').trim().split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase(); },

                posAt(track, t) {
                    if (!track.length) return null;
                    if (t <= track[0][0]) return [track[0][1], track[0][2]];
                    const last = track[track.length - 1];
                    if (t >= last[0]) return [last[1], last[2]];
                    for (let i = 1; i < track.length; i++) {
                        if (track[i][0] >= t) { const a = track[i - 1], b = track[i], f = (t - a[0]) / ((b[0] - a[0]) || 1); return [a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f]; }
                    }
                    return [last[1], last[2]];
                },
                hider() { return this.bundle.players.find((p) => p.role === 'hider'); },
                answerColor(a) { if (['yes', 'in_range', 'closer', 'hotter'].includes(a)) return '#16a34a'; if (['no', 'out_of_range', 'further', 'colder'].includes(a)) return '#dc2626'; return '#2563eb'; },
                icon(kind) { return { action: '⚑', ask: '❓', curse: '🎴' }[kind] || '•'; },

                // --- deduction (radar + thermometer, via turf) ---
                halfPlane(a, b, towardB) {
                    const A = turf.point([a[1], a[0]]), B = turf.point([b[1], b[0]]);
                    const mid = turf.midpoint(A, B), keep = turf.bearing(A, B) + (towardB ? 0 : 180), D = 400;
                    const p1 = turf.destination(mid, D, keep - 90), p2 = turf.destination(mid, D, keep + 90);
                    const p3 = turf.destination(p2, D, keep), p4 = turf.destination(p1, D, keep);
                    return turf.polygon([[p1.geometry.coordinates, p2.geometry.coordinates, p3.geometry.coordinates, p4.geometry.coordinates, p1.geometry.coordinates]]);
                },
                computeCandidate(qs) {
                    if (!window.turf) return null;
                    let cand;
                    if (this.bundle.playAreaGeo) cand = turf.feature(this.bundle.playAreaGeo);
                    else if (this.bundle.playArea) cand = turf.circle([this.bundle.playArea.lng, this.bundle.playArea.lat], this.bundle.playArea.radiusKm, { units: 'kilometers', steps: 96 });
                    else return null;
                    for (const q of qs) {
                        try {
                            if (q.category === 'radar' && q.ask.radius_m) {
                                const c = turf.circle([q.ask.lng, q.ask.lat], q.ask.radius_m / 1000, { units: 'kilometers', steps: 64 });
                                cand = ['yes', 'in_range'].includes(q.answer) ? turf.intersect(turf.featureCollection([cand, c])) : turf.difference(turf.featureCollection([cand, c]));
                            } else if (q.category === 'thermometer' && q.end) {
                                cand = turf.intersect(turf.featureCollection([cand, this.halfPlane([q.ask.lat, q.ask.lng], [q.end.lat, q.end.lng], q.answer === 'hotter')]));
                            }
                        } catch (e) { /* geometry hiccup — keep the last candidate */ }
                        if (!cand) break;
                    }
                    return cand;
                },
                renderDeduction() {
                    this.dedLayer.clearLayers();
                    if (!this.showDeduction || !window.turf || (!this.bundle.playAreaGeo && !this.bundle.playArea)) return;
                    // The hider relocates each round, so a round's deduction starts fresh from the play area and
                    // only uses that round's questions — carrying earlier cuts over would point at the wrong spot.
                    const r = this.activeRound;
                    const from = r ? r.start : this.bundle.t0;
                    const qs = this.bundle.questions.filter((q) => q.ask && q.at <= this.time && q.at >= from && (q.category === 'radar' || q.category === 'thermometer'));
                    const key = (r ? r.round : 0) + ':' + qs.length;
                    if (key !== this._dedKey) { this._dedKey = key; this._dedGeo = this.computeCandidate(qs); }
                    if (this._dedGeo) L.geoJSON(this._dedGeo, { style: { color: '#16a34a', weight: 2, fillColor: '#16a34a', fillOpacity: 0.09 }, interactive: false }).addTo(this.dedLayer);
                },

                renderTraces() {
                    this.traceLayer.clearLayers();
                    if (!this.showTraces) return;
                    this.bundle.players.forEach((p) => {
                        const pts = p.track.filter((s) => s[0] <= this.time).map((s) => [s[1], s[2]]);
                        if (pts.length > 1) {
                            L.polyline(pts, { color: '#ffffff', weight: 5, opacity: 0.6 }).addTo(this.traceLayer);
                            L.polyline(pts, { color: p.color, weight: 2.5, opacity: 0.95 }).addTo(this.traceLayer);
                        }
                    });
                },

                renderEvents() {
                    this.evLayer.clearLayers();
                    const W = 14; // game-seconds a flash stays up
                    let active = null;
                    this.bundle.questions.forEach((q) => {
                        if (!q.ask || this.time < q.at || this.time - q.at >= W) return;
                        const color = this.answerColor(q.answer);
                        L.marker([q.ask.lat, q.ask.lng], { icon: this.pulseIcon(color), interactive: false }).addTo(this.evLayer);
                        active = { icon: '❓', color, text: (q.category || 'question') + (q.answer ? ' → ' + q.answer.replace(/_/g, ' ') : '') };
                    });
                    this.bundle.curses.forEach((c) => {
                        if (this.time < c.at || this.time - c.at >= W) return;
                        const h = this.hider(); const z = this.activeZone(); const pos = (h && (this.posAt(h.track, this.time) || h.last)) || (z ? [z.lat, z.lng] : null);
                        if (pos) L.marker(pos, { icon: this.pulseIcon('#7c3aed'), interactive: false }).addTo(this.evLayer);
                        active = { icon: '🎴', color: '#7c3aed', text: 'Curse: ' + c.name };
                    });
                    this.banner = active;
                },
                pulseIcon(color) {
                    return L.divIcon({ className: '', iconSize: [16, 16], iconAnchor: [8, 8], html: `<div style="position:relative;width:16px;height:16px;color:${color}"><div class="jtr-ring"></div><div style="position:absolute;inset:4px;border-radius:9999px;background:${color};box-shadow:0 0 0 2px #fff"></div></div>` });
                },
                thermoIcon(color) {
                    return L.divIcon({ className: '', iconSize: [22, 22], iconAnchor: [11, 11], html: `<div style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:9999px;background:${color};box-shadow:0 0 0 2px #fff;font-size:12px;line-height:1">🌡️</div>` });
                },

                // The hider re-hides each round, so the zone moves. Use the per-round zones when present.
                zoneList() { return (this.bundle.zones && this.bundle.zones.length) ? this.bundle.zones : (this.bundle.zone ? [this.bundle.zone] : []); },
                activeZone() {
                    const zs = this.zoneList();
                    if (!zs.length) return null;
                    let z = zs[0];
                    for (const cand of zs) { if ((cand.at ?? -Infinity) <= this.time) z = cand; }
                    return z;
                },
                renderZone() {
                    if (!this.zoneLayer) return;
                    this.zoneLayer.clearLayers();
                    const z = this.activeZone();
                    if (!z) return;
                    L.circle([z.lat, z.lng], { radius: z.radius_m, color: '#f59e0b', weight: 1, fillOpacity: 0.05, dashArray: '6', interactive: false }).addTo(this.zoneLayer);
                },

                render() {
                    if (!this.map) return;
                    this.bundle.players.forEach((p) => {
                        const pos = this.posAt(p.track, this.time) || p.last, m = this.markers[p.id];
                        if (pos) { m.setLatLng(pos); m.setOpacity(1); } else { m.setOpacity(0); }
                    });
                    this.renderZone();
                    this.renderDeduction();
                    this.renderTraces();
                    this.qLayer.clearLayers();
                    const r = this.activeRound, from = r ? r.start : this.bundle.t0;
                    this.bundle.questions.filter((q) => q.ask && q.at <= this.time && q.at >= from).forEach((q) => {
                        const color = this.answerColor(q.answer);
                        const a = [q.ask.lat, q.ask.lng];
                        if (q.category === 'thermometer' && q.end) {
                            // The seeker's thermometer walk: hollow start ●, line, 🌡️ end (green = hotter, red = colder).
                            const b = [q.end.lat, q.end.lng];
                            L.polyline([a, b], { color, weight: 3, opacity: 0.85, dashArray: '1 6', interactive: false }).addTo(this.qLayer);
                            L.circleMarker(a, { radius: 5, color, weight: 2, fillColor: '#fff', fillOpacity: 1 }).bindTooltip('Thermometer start').addTo(this.qLayer);
                            L.marker(b, { icon: this.thermoIcon(color), zIndexOffset: 600 }).bindTooltip('Thermometer end · ' + (q.answer || '')).addTo(this.qLayer);
                        } else if (q.category === 'radar' && q.ask.radius_m) {
                            L.circle(a, { radius: q.ask.radius_m, color, weight: 1, fillOpacity: 0.03, dashArray: '4', interactive: false }).addTo(this.qLayer);
                            L.circleMarker(a, { radius: 4, color, weight: 2, fillColor: color, fillOpacity: 0.9, interactive: false }).addTo(this.qLayer);
                        } else {
                            L.circleMarker(a, { radius: 4, color, weight: 2, fillColor: color, fillOpacity: 0.9, interactive: false }).addTo(this.qLayer);
                        }
                    });
                    this.renderEvents();
                },

                get currentEventIndex() { let idx = -1; this.bundle.events.forEach((e, i) => { if (e.at <= this.time) idx = i; }); return idx; },
                get currentAction() { const i = this.currentEventIndex; return i >= 0 ? this.bundle.events[i] : null; },
                get rounds() { return this.bundle.rounds || []; },
                get activeRound() { let r = null; for (const c of this.rounds) { if (this.time >= c.start) r = c; } return r || this.rounds[0] || null; },
                gotoRound(r) { this.seek(r.start + 1); },
                seek(t) { this.time = Math.min(Math.max(t, this.bundle.t0), this.bundle.t1); this.render(); },
                toggle() {
                    this.playing = !this.playing; clearInterval(this._timer);
                    if (!this.playing) return;
                    if (this.time >= this.bundle.t1) this.time = this.bundle.t0;
                    this._timer = setInterval(() => {
                        this.time += this.speed * 0.2;
                        if (this.time >= this.bundle.t1) { this.time = this.bundle.t1; this.playing = false; clearInterval(this._timer); }
                        this.render();
                    }, 200);
                },
                rel(t) { const s = Math.max(0, Math.round(t - this.bundle.t0)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); },
            };
        };
    </script>
</x-filament-panels::page>
