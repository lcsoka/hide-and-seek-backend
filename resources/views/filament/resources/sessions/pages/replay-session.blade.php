<x-filament-panels::page>
    <style>
        @keyframes jtr-pulse { 0% { transform: scale(.5); opacity: .85; } 100% { transform: scale(2.4); opacity: 0; } }
        .jtr-ring { position: absolute; inset: 0; border-radius: 9999px; border: 2px solid currentColor; animation: jtr-pulse 1.1s ease-out infinite; }
        .jtr-banner { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); z-index: 500; }
        /* Leaflet re-positions each marker by setting a new transform on every zoom; a framework
           transition on that transform makes the icons (thermometer end, stations…) slide/drift
           during the zoom instead of snapping to their point. Force it off. */
        .leaflet-marker-icon, .leaflet-marker-shadow { transition: none !important; }
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
                <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm border border-dashed border-amber-500 bg-amber-500/10"></span> Hiding zone (🚉 carved by stops)</span>
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
                    <div class="rounded-lg transition" :class="i === currentEventIndex ? 'bg-primary-50 dark:bg-primary-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'">
                        <button type="button" @click="seek(e.at)" class="flex w-full items-start gap-2 px-2.5 py-1.5 text-left text-sm">
                            <span class="w-14 shrink-0 pt-0.5 text-right font-mono text-xs tabular-nums text-gray-400" x-text="clock(e.at)"></span>
                            <span class="text-base leading-none" x-text="icon(e.kind)"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium" x-text="e.label"></span>
                                <span class="block truncate text-xs text-gray-400" x-text="e.by || ''"></span>
                                <template x-if="e.card">
                                    <span class="mt-1 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold"
                                          :style="`color:${e.card.color};border:1px solid ${e.card.color}`">
                                        <span>🎴</span><span x-text="e.card.name"></span>
                                    </span>
                                </template>
                            </span>
                        </button>
                        {{-- Photo/video clues sent for this question or curse — tap to view full-screen. --}}
                        <template x-if="e.media && e.media.length">
                            <div class="flex flex-wrap gap-1.5 pb-2 pl-[4.5rem] pr-2.5">
                                <template x-for="(m, mi) in e.media" :key="mi">
                                    <button type="button" @click="openMedia(m)"
                                            class="relative h-12 w-12 overflow-hidden rounded-md ring-1 ring-gray-950/10 dark:ring-white/10">
                                        <template x-if="isVideo(m)">
                                            <span class="block h-full w-full">
                                                <video :src="m" class="h-full w-full object-cover" muted playsinline preload="metadata"></video>
                                                <span class="absolute inset-0 grid place-items-center"><span class="grid h-5 w-5 place-items-center rounded-full bg-black/55 text-[10px] text-white">▶</span></span>
                                            </span>
                                        </template>
                                        <template x-if="!isVideo(m)"><img :src="m" class="h-full w-full object-cover" alt=""></template>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        {{-- Full-screen media viewer for a tapped photo/video clue. --}}
        <template x-if="viewer">
            <div @click="closeMedia()" class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/85 p-4">
                <button type="button" @click="closeMedia()" class="absolute right-4 top-4 grid h-10 w-10 place-items-center rounded-full bg-white/15 text-xl text-white hover:bg-white/25">✕</button>
                <template x-if="viewer.video"><video :src="viewer.url" controls autoplay playsinline @click.stop class="max-h-[85vh] max-w-full rounded-xl bg-black"></video></template>
                <template x-if="!viewer.video"><img :src="viewer.url" @click.stop class="max-h-[85vh] max-w-full rounded-xl" alt=""></template>
            </div>
        </template>
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
                viewer: null,
                map: null,
                markers: {},
                qLayer: null, dedLayer: null, traceLayer: null, evLayer: null,
                _timer: null, _dedKey: null, _dedGeo: null,

                async init() {
                    await Promise.all([this.load('https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', () => window.L),
                        this.load('https://unpkg.com/@turf/turf@7/turf.min.js', null, () => window.turf)]);
                    this.setupMap();
                    this.render();
                    // The Filament grid settles its width after first paint; recompute the map size
                    // once it has, so the projection (and every layer's placement on zoom) is correct.
                    setTimeout(() => this.map && this.map.invalidateSize(), 200);
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
                    // zoomAnimation off: the Filament panel CSS interferes with the timed transform
                    // Leaflet applies to its panes during an animated zoom, so the layers (region,
                    // radar circles, thermometer lines, markers) slide out of place mid-zoom. An
                    // instant zoom redraws every layer at the correct spot with nothing to desync.
                    this.map = L.map(this.$refs.map, { attributionControl: false, zoomAnimation: false, markerZoomAnimation: false });
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
                        if (track[i][0] >= t) {
                            const a = track[i - 1], b = track[i], gap = b[0] - a[0];
                            // A big gap between fixes means the player was stationary (e.g. the hider sitting in
                            // their zone all round, or a backgrounded phone) — hold at the earlier fix instead of
                            // gliding a straight line across it, which looked like the hider drifting away.
                            if (gap > 150) return [a[1], a[2]];
                            const f = (t - a[0]) / (gap || 1);
                            return [a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f];
                        }
                    }
                    return [last[1], last[2]];
                },
                hider() { return this.bundle.players.find((p) => p.role === 'hider'); },
                answerColor(a) { if (['yes', 'in_range', 'closer', 'hotter'].includes(a)) return '#16a34a'; if (['no', 'out_of_range', 'further', 'colder'].includes(a)) return '#dc2626'; return '#2563eb'; },
                icon(kind) { return { action: '⚑', ask: '❓', curse: '🎴' }[kind] || '•'; },

                // --- deduction (radar + thermometer, via turf) ---
                // Half-plane of points closer to B than A (perpendicular bisector of A–B). a/b are
                // [lat,lng]; towardB keeps B's (warmer) side. Built as a large PLANAR quad in lng/lat
                // (lng scaled by cos(lat) so the bisector is truly perpendicular) — the earlier
                // geodesic-destination quad self-intersected over its long reach and silently left the
                // candidate uncut (so thermometer answers never cut). Mirrors the web app's version.
                halfPlane(a, b, towardB) {
                    const aLat = a[0], aLng = a[1], bLat = b[0], bLng = b[1];
                    const midLat = (aLat + bLat) / 2, k = Math.cos(midLat * Math.PI / 180) || 1;
                    let ux = (bLng - aLng) * k, uy = bLat - aLat;
                    const len = Math.hypot(ux, uy) || 1; ux /= len; uy /= len;
                    if (!towardB) { ux = -ux; uy = -uy; }
                    const px = -uy, py = ux, mx = (aLng + bLng) / 2, my = midLat, reach = 8;
                    const at = (sx, sy) => [mx + sx / k, my + sy];
                    return turf.polygon([[
                        at(px * reach, py * reach),
                        at(px * reach + ux * 2 * reach, py * reach + uy * 2 * reach),
                        at(-px * reach + ux * 2 * reach, -py * reach + uy * 2 * reach),
                        at(-px * reach, -py * reach),
                        at(px * reach, py * reach),
                    ]]);
                },
                computeCandidate(qs) {
                    if (!window.turf) return null;
                    let cand;
                    if (this.bundle.playAreaGeo) cand = turf.feature(this.bundle.playAreaGeo);
                    else if (this.bundle.playArea) cand = turf.circle([this.bundle.playArea.lng, this.bundle.playArea.lat], this.bundle.playArea.radiusKm, { units: 'kilometers', steps: 96 });
                    else return null;
                    const fc = (a) => turf.featureCollection(a);
                    for (const q of qs) {
                        try {
                            if (q.category === 'radar' && q.ask.radius_m) {
                                const c = turf.circle([q.ask.lng, q.ask.lat], q.ask.radius_m / 1000, { units: 'kilometers', steps: 64 });
                                cand = ['yes', 'in_range'].includes(q.answer) ? turf.intersect(fc([cand, c])) : turf.difference(fc([cand, c]));
                            } else if (q.category === 'thermometer' && q.end) {
                                cand = turf.intersect(fc([cand, this.halfPlane([q.ask.lat, q.ask.lng], [q.end.lat, q.end.lng], q.answer === 'hotter')]));
                            } else if (q.category === 'tentacles' && q.geo) {
                                // Within the radius, keep the Voronoi cell of the matched place (in_range),
                                // or remove the whole radius circle (out_of_range).
                                const circle = turf.circle([q.ask.lng, q.ask.lat], (q.ask.radius_m || 1609) / 1000, { units: 'kilometers', steps: 64 });
                                cand = q.answer === 'out_of_range'
                                    ? turf.difference(fc([cand, circle]))
                                    : turf.intersect(fc([cand, this.voronoiRegion(q.geo, [q.ask.lng, q.ask.lat], circle) || circle]));
                            } else if (q.category === 'matching' && q.geo && q.geo.pois && q.geo.pois.length >= 2) {
                                // Keep (yes) / remove (no) the Voronoi cell of the seeker's matched place.
                                const cell = this.voronoiRegion(q.geo, [q.ask.lng, q.ask.lat], null);
                                if (cell) cand = q.answer === 'yes' ? turf.intersect(fc([cand, cell])) : turf.difference(fc([cand, cell]));
                            } else if (q.category === 'measuring' && q.geo && q.geo.ref) {
                                // A circle around the reference at the seeker's own distance — keep inside
                                // (closer) or outside (further).
                                const ref = [q.geo.ref.lng, q.geo.ref.lat];
                                const d = Math.max(turf.distance(turf.point([q.ask.lng, q.ask.lat]), turf.point(ref), { units: 'kilometers' }), 0.05);
                                const circle = turf.circle(ref, d, { units: 'kilometers', steps: 64 });
                                cand = q.answer === 'closer' ? turf.intersect(fc([cand, circle])) : turf.difference(fc([cand, circle]));
                            }
                        } catch (e) { /* geometry hiccup — keep the last candidate */ }
                        if (!cand) break;
                    }
                    return cand;
                },

                // The Voronoi cell of the seeker's reference place among the candidate POIs, optionally
                // clipped to a radius circle (tentacles). Mirrors the web app's osm-deduction.
                voronoiRegion(geo, askLngLat, clip) {
                    const pts = (geo.pois || []).map((p) => turf.point([p[1], p[0]], { name: p[2] }));
                    if (pts.length < 2) return clip || null;
                    const box = turf.bbox(clip || turf.circle(askLngLat, 80, { units: 'kilometers' }));
                    const cells = turf.voronoi(turf.featureCollection(pts), { bbox: box });
                    const ref = geo.ref ? turf.point([geo.ref.lng, geo.ref.lat]) : turf.point(askLngLat);
                    const cell = cells.features.find((c) => c && turf.booleanPointInPolygon(ref, c));
                    if (!cell) return clip || null;
                    return clip ? turf.intersect(turf.featureCollection([cell, clip])) : cell;
                },
                renderDeduction() {
                    this.dedLayer.clearLayers();
                    if (!this.showDeduction || !window.turf || (!this.bundle.playAreaGeo && !this.bundle.playArea)) return;
                    // The hider relocates each round, so a round's deduction starts fresh from the play area and
                    // only uses that round's questions — carrying earlier cuts over would point at the wrong spot.
                    const r = this.activeRound;
                    const from = r ? r.start : this.bundle.t0;
                    const qs = this.bundle.questions.filter((q) => q.ask && q.at <= this.time && q.at >= from && ['radar', 'thermometer', 'tentacles', 'matching', 'measuring'].includes(q.category));
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
                // The zone the hider must stay in: the chosen stop's cell (radius circle carved wherever another
                // transit stop becomes closer), matching how the live game draws it. Falls back to the circle.
                carveZone(z) {
                    if (!window.turf) return null;
                    try {
                        let cell = turf.circle([z.lng, z.lat], z.radius_m / 1000, { units: 'kilometers', steps: 64 });
                        for (const s of (z.stations || [])) {
                            if (turf.distance(turf.point([z.lng, z.lat]), turf.point([s[1], s[0]]), { units: 'meters' }) < 25) continue; // the chosen stop itself
                            cell = turf.intersect(turf.featureCollection([cell, this.halfPlane([z.lat, z.lng], [s[0], s[1]], false)]));
                            if (!cell) return null;
                        }
                        return cell;
                    } catch (e) { return null; }
                },
                stationIcon() {
                    return L.divIcon({ className: '', iconSize: [12, 12], iconAnchor: [6, 6], html: `<div style="width:12px;height:12px;border-radius:3px;background:#f59e0b;border:1.5px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.5);font-size:8px;line-height:9px;text-align:center">🚉</div>` });
                },
                renderZone() {
                    if (!this.zoneLayer) return;
                    this.zoneLayer.clearLayers();
                    const z = this.activeZone();
                    if (!z) return;
                    const carved = this.carveZone(z);
                    if (carved) {
                        L.geoJSON(carved, { style: { color: '#f59e0b', weight: 2, fillColor: '#f59e0b', fillOpacity: 0.08, dashArray: '6', interactive: false } }).addTo(this.zoneLayer);
                    } else {
                        L.circle([z.lat, z.lng], { radius: z.radius_m, color: '#f59e0b', weight: 1, fillOpacity: 0.05, dashArray: '6', interactive: false }).addTo(this.zoneLayer);
                    }
                    (z.stations || []).forEach((s) => {
                        L.marker([s[0], s[1]], { icon: this.stationIcon(), zIndexOffset: 400 }).bindTooltip('Transit stop').addTo(this.zoneLayer);
                    });
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
                // Wall-clock hh:mm:ss for the timeline (the actual time of day the event happened).
                clock(t) { const d = new Date(t * 1000), p = (n) => String(n).padStart(2, '0'); return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); },
                isVideo(url) { return /\.(mp4|mov|m4v|webm|3gp|ogv)(\?|#|$)/i.test(url || ''); },
                openMedia(url) { this.viewer = { url, video: this.isVideo(url) }; },
                closeMedia() { this.viewer = null; },
            };
        };
    </script>
</x-filament-panels::page>
