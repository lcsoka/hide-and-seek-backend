<x-filament-panels::page>
    {{-- Poll fast while a deploy streams its log; slow down to a status refresh when idle. --}}
    <div wire:poll.{{ $this->isDeploying() ? '2s' : '15s' }} class="space-y-6">

        {{-- Version --}}
        @php($v = $this->version())
        <div class="flex items-center gap-3 rounded-xl p-4"
             style="border:1px solid {{ $v['available'] ? '#fcd34d' : 'rgba(120,120,120,0.2)' }};background:{{ $v['available'] ? 'rgba(251,191,36,0.10)' : 'rgba(120,120,120,0.05)' }}">
            <div style="font-size:1.4rem">{{ $v['available'] ? '⬆️' : ($v['up_to_date'] ? '✅' : 'ℹ️') }}</div>
            <div style="min-width:0">
                <div class="text-sm font-semibold">
                    @if ($v['available']) Update available
                    @elseif ($v['up_to_date']) Up to date
                    @else Version @endif
                </div>
                <div class="text-xs" style="opacity:0.7">
                    @if ($v['error']) {{ $v['error'] }}
                    @else deployed {{ $v['current'] ?? '—' }} · latest {{ $v['remote'] ?? '—' }} @endif
                </div>
            </div>
        </div>

        {{-- Deploy availability hint --}}
        @unless ($this->deployEnabled())
            <div class="rounded-xl p-3 text-xs" style="border:1px solid rgba(120,120,120,0.15);opacity:0.75">
                The <strong>Deploy latest</strong> button is disabled. Set <code style="background:rgba(120,120,120,0.14);padding:0.1em 0.35em;border-radius:0.3rem">ADMIN_DEPLOY_ENABLED=true</code> on the server (and grant the <code style="background:rgba(120,120,120,0.14);padding:0.1em 0.35em;border-radius:0.3rem">deploy.sh</code> sudoers rule) to enable one-click deploys.
            </div>
        @endunless

        {{-- Services --}}
        <div class="grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
            @foreach ($this->services() as $s)
                <div class="flex items-center gap-3 rounded-xl p-4" style="border:1px solid rgba(120,120,120,0.15)">
                    <span class="rounded-full" style="width:10px;height:10px;flex:none;background:{{ $s['ok'] ? '#22c55e' : '#ef4444' }}"></span>
                    <div style="min-width:0;flex:1">
                        <div class="text-sm font-medium">{{ $s['label'] }}</div>
                        <div class="text-xs truncate" style="opacity:0.6">{{ $s['detail'] }}</div>
                    </div>
                    <span class="text-xs font-semibold" style="color:{{ $s['ok'] ? '#16a34a' : '#dc2626' }}">{{ $s['ok'] ? 'UP' : 'DOWN' }}</span>
                </div>
            @endforeach
        </div>

        {{-- Deploy log (one file per deploy; pick a past one, or watch the running one live). --}}
        @php($deploys = $this->deployLogs())
        @php($log = $this->deployLog())
        @if ($deploys !== [] || $this->isDeploying())
            <div class="rounded-xl p-4" style="border:1px solid rgba(120,120,120,0.15)">
                <div class="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold">
                    Deploy log
                    @if ($this->isDeploying())
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="background:rgba(225,29,72,0.12);color:#e11d48">running…</span>
                    @endif
                    @if ($deploys !== [])
                        <select wire:model.live="selectedDeploy" @disabled($this->isDeploying())
                                class="ml-auto rounded-lg px-2 py-1 text-xs" style="border:1px solid rgba(120,120,120,0.25);background:transparent">
                            <option value="">Latest</option>
                            @foreach ($deploys as $d)
                                <option value="{{ $d['name'] }}">{{ $d['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <pre id="deploy-log" class="overflow-auto rounded-lg p-3 text-xs" style="max-height:24rem;background:#0b1020;color:#e5e7eb;white-space:pre-wrap">{{ $log !== '' ? $log : 'Starting…' }}</pre>
            </div>
        @endif
    </div>

    {{-- Keep the log pinned to the newest line as it streams — unless the admin has scrolled up. --}}
    @script
    <script>
        setInterval(() => {
            const el = document.getElementById('deploy-log');
            if (el && el.scrollHeight - el.scrollTop - el.clientHeight < 60) {
                el.scrollTop = el.scrollHeight;
            }
        }, 1000);
    </script>
    @endscript
</x-filament-panels::page>
