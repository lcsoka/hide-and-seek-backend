<x-filament-panels::page>
    <div wire:poll.10s class="space-y-6">

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

        {{-- Deploy log --}}
        @php($log = $this->deployLog())
        @if ($log !== '' || $this->isDeploying())
            <div class="rounded-xl p-4" style="border:1px solid rgba(120,120,120,0.15)">
                <div class="mb-2 flex items-center gap-2 text-sm font-semibold">
                    Deploy log
                    @if ($this->isDeploying())
                        <span class="rounded-full px-2 py-0.5 text-xs font-medium" style="background:rgba(225,29,72,0.12);color:#e11d48">running…</span>
                    @endif
                </div>
                <pre class="overflow-auto rounded-lg p-3 text-xs" style="max-height:24rem;background:#0b1020;color:#e5e7eb;white-space:pre-wrap">{{ $log !== '' ? $log : 'Starting…' }}</pre>
            </div>
        @endif
    </div>
</x-filament-panels::page>
