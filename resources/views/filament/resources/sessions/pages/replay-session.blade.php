<x-filament-panels::page>
    <div
        x-data="replayApp(@js($this->replayBundle()))"
        x-init="init()"
        class="grid gap-4 lg:grid-cols-3"
    >
        {{-- Map + transport controls --}}
        <div class="space-y-3 lg:col-span-2">
            <div
                x-ref="map"
                wire:ignore
                class="h-[520px] w-full overflow-hidden rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10"
            ></div>

            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-white p-3 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <button
                    type="button"
                    @click="toggle()"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-500"
                >
                    <span x-text="playing ? '⏸' : '▶'"></span>
                    <span x-text="playing ? 'Pause' : 'Play'"></span>
                </button>

                <input
                    type="range"
                    :min="bundle.t0"
                    :max="bundle.t1"
                    step="1"
                    x-model.number="time"
                    @input="render()"
                    class="h-2 flex-1 cursor-pointer accent-primary-600"
                />

                <select x-model.number="speed" class="rounded-lg border-gray-300 bg-white py-1 text-sm dark:border-white/10 dark:bg-gray-800">
                    <option :value="15">15×</option>
                    <option :value="30">30×</option>
                    <option :value="60">60×</option>
                    <option :value="120">120×</option>
                </select>

                <span class="tabular-nums text-sm font-medium text-gray-500 dark:text-gray-400">
                    <span x-text="rel(time)"></span> / <span x-text="rel(bundle.t1)"></span>
                </span>
            </div>

            {{-- Player legend --}}
            <div class="flex flex-wrap gap-2">
                <template x-for="p in bundle.players" :key="p.id">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium dark:bg-white/5">
                        <span class="h-2.5 w-2.5 rounded-full" :style="`background:${p.color}`"></span>
                        <span x-text="p.name"></span>
                        <span class="text-gray-400" x-text="p.role ? '· ' + p.role : ''"></span>
                    </span>
                </template>
            </div>
        </div>

        {{-- Event feed --}}
        <div class="flex max-h-[600px] flex-col rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold dark:border-white/10">
                Timeline
                <span class="font-normal text-gray-400" x-text="`· ${bundle.events.length} events`"></span>
            </div>
            <div class="min-h-0 flex-1 overflow-auto p-2">
                <template x-if="bundle.events.length === 0">
                    <p class="p-3 text-sm text-gray-400">No recorded events for this game.</p>
                </template>
                <template x-for="(e, i) in bundle.events" :key="i">
                    <button
                        type="button"
                        @click="seek(e.at)"
                        class="flex w-full items-start gap-2 rounded-lg px-2.5 py-1.5 text-left text-sm transition"
                        :class="i === currentEventIndex ? 'bg-primary-50 dark:bg-primary-500/10' : 'hover:bg-gray-50 dark:hover:bg-white/5'"
                    >
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
                map: null,
                markers: {},
                qLayer: null,
                _timer: null,

                async init() {
                    await this.ensureLeaflet();
                    this.setupMap();
                    this.render();
                },

                ensureLeaflet() {
                    return new Promise((resolve) => {
                        if (window.L) return resolve();
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        document.head.appendChild(css);
                        const s = document.createElement('script');
                        s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        s.onload = () => resolve();
                        document.head.appendChild(s);
                    });
                },

                setupMap() {
                    const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    this.map = L.map(this.$refs.map, { attributionControl: false });
                    L.tileLayer(dark
                        ? 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png'
                        : 'https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png',
                        { subdomains: 'abcd', maxZoom: 19 }).addTo(this.map);

                    const pts = [];
                    this.bundle.players.forEach((p) => p.track.forEach((s) => pts.push([s[1], s[2]])));
                    this.bundle.players.forEach((p) => { if (p.last) pts.push(p.last); });
                    this.bundle.questions.forEach((q) => { if (q.ask) pts.push([q.ask.lat, q.ask.lng]); });
                    if (this.bundle.zone) pts.push([this.bundle.zone.lat, this.bundle.zone.lng]);
                    if (pts.length) this.map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 });
                    else this.map.setView([47.4979, 19.0402], 12);

                    if (this.bundle.zone) {
                        L.circle([this.bundle.zone.lat, this.bundle.zone.lng], {
                            radius: this.bundle.zone.radius_m, color: '#f59e0b', weight: 1, fillOpacity: 0.05, dashArray: '6',
                        }).addTo(this.map);
                    }

                    this.qLayer = L.layerGroup().addTo(this.map);

                    this.bundle.players.forEach((p) => {
                        const m = L.marker([0, 0], { icon: this.playerIcon(p), opacity: 0 }).addTo(this.map);
                        m.bindTooltip(p.name);
                        this.markers[p.id] = m;
                    });
                },

                playerIcon(p) {
                    const ring = `box-shadow:0 0 0 2px #fff, 0 0 0 4px ${p.color}`;
                    const inner = p.avatar
                        ? `background:${p.color} center/cover no-repeat url('${p.avatar}')`
                        : `background:${p.color};color:#fff;display:flex;align-items:center;justify-content:center;font:700 12px system-ui`;
                    const text = p.avatar ? '' : this.initials(p.name);
                    return L.divIcon({
                        className: '',
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        html: `<div style="width:30px;height:30px;border-radius:9999px;${inner};${ring}">${text}</div>`,
                    });
                },

                initials(n) {
                    return (n || '?').trim().split(/\s+/).map((x) => x[0]).slice(0, 2).join('').toUpperCase();
                },

                posAt(track, t) {
                    if (!track.length) return null;
                    if (t <= track[0][0]) return [track[0][1], track[0][2]];
                    const last = track[track.length - 1];
                    if (t >= last[0]) return [last[1], last[2]];
                    for (let i = 1; i < track.length; i++) {
                        if (track[i][0] >= t) {
                            const a = track[i - 1], b = track[i];
                            const f = (t - a[0]) / ((b[0] - a[0]) || 1);
                            return [a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f];
                        }
                    }
                    return [last[1], last[2]];
                },

                answerColor(a) {
                    if (['yes', 'in_range', 'closer'].includes(a)) return '#16a34a';
                    if (['no', 'out_of_range', 'further'].includes(a)) return '#dc2626';
                    return '#2563eb';
                },

                icon(kind) {
                    return { action: '⚑', ask: '❓', curse: '🎴' }[kind] || '•';
                },

                render() {
                    if (!this.map) return;
                    this.bundle.players.forEach((p) => {
                        const pos = this.posAt(p.track, this.time) || p.last;
                        const m = this.markers[p.id];
                        if (pos) { m.setLatLng(pos); m.setOpacity(1); } else { m.setOpacity(0); }
                    });
                    this.qLayer.clearLayers();
                    this.bundle.questions.filter((q) => q.ask && q.at <= this.time).forEach((q) => {
                        const color = this.answerColor(q.answer);
                        if (q.ask.radius_m) {
                            L.circle([q.ask.lat, q.ask.lng], { radius: q.ask.radius_m, color, weight: 1, fillOpacity: 0.04, dashArray: '4' }).addTo(this.qLayer);
                        }
                        L.circleMarker([q.ask.lat, q.ask.lng], { radius: 4, color, weight: 2, fillColor: color, fillOpacity: 0.9 }).addTo(this.qLayer);
                    });
                },

                get currentEventIndex() {
                    let idx = -1;
                    this.bundle.events.forEach((e, i) => { if (e.at <= this.time) idx = i; });
                    return idx;
                },

                seek(t) {
                    this.time = Math.min(Math.max(t, this.bundle.t0), this.bundle.t1);
                    this.render();
                },

                toggle() {
                    this.playing = !this.playing;
                    clearInterval(this._timer);
                    if (!this.playing) return;
                    if (this.time >= this.bundle.t1) this.time = this.bundle.t0;
                    this._timer = setInterval(() => {
                        this.time += this.speed * 0.2;
                        if (this.time >= this.bundle.t1) {
                            this.time = this.bundle.t1;
                            this.playing = false;
                            clearInterval(this._timer);
                        }
                        this.render();
                    }, 200);
                },

                rel(t) {
                    const s = Math.max(0, Math.round(t - this.bundle.t0));
                    const m = Math.floor(s / 60);
                    return m + ':' + String(s % 60).padStart(2, '0');
                },
            };
        };
    </script>
</x-filament-panels::page>
