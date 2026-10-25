@extends('panel.layout')
@section('title', $a->name)
@section('content')
@php
    $c = $metrics['counters'];
    $dots = ['wait' => '⏱', 'check' => '?', 'action' => '✉', 'exit' => '■'];
    $runSt = ['waiting' => 'b-warn', 'running' => 'b-info', 'completed' => 'b-pass', 'exited' => 'b-muted', 'failed' => 'b-bad', 'cancelled' => 'b-muted'];
    $delayStep = collect((array) json_decode((string) $current->steps, true))->firstWhere('type', 'delay');
    $delayHours = max(1, (int) round(((int) ($delayStep['params']['minutes'] ?? 120)) / 60));
    $winbackDays = (int) (((array) json_decode((string) $current->trigger, true))['params']['days'] ?? 90);
@endphp
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.automations') }}">Otomasyonlar</a> /</div>
        <h1>{{ $a->name }} @include('panel.automations._status', ['status' => $a->status])</h1>
        <p>Gösterilen: v{{ $current->version }} ({{ $current->status === 'published' ? 'yayında' : $current->status }}) · Son değerlendirme {{ $a->last_evaluated_at ? \Illuminate\Support\Carbon::parse($a->last_evaluated_at)->diffForHumans() : '—' }}</p></div>
    <div class="actions">
        <form class="inline" method="post" action="{{ route('panel.automations.dry-run', $a->id) }}">@csrf<button class="btn" type="submit">Dry-run</button></form>
        @if ($canManage)
            @if ($a->status !== 'published' && $a->status !== 'archived')
                <form class="inline" method="post" action="{{ route('panel.automations.state', $a->id) }}">@csrf<input type="hidden" name="action" value="publish"><button class="btn btn-primary" type="submit">Yayınla</button></form>
            @endif
            @if ($a->status === 'published')
                <form class="inline" method="post" action="{{ route('panel.automations.state', $a->id) }}">@csrf<input type="hidden" name="action" value="pause"><button class="btn" type="submit">Duraklat</button></form>
            @endif
            @if ($a->status !== 'archived')
                <form class="inline" method="post" action="{{ route('panel.automations.state', $a->id) }}">@csrf<input type="hidden" name="action" value="archive"><button class="btn btn-danger" type="submit">Arşivle</button></form>
            @endif
        @endif
    </div>
</div>

<div class="grid g2">
    <div class="card">
        <h2>Akış</h2>
        <ul class="flow">
            <li><span class="dot trigger">▶</span><div><div class="t">Tetikleyici</div><div class="d">{{ $trigger }}</div></div></li>
            @foreach ($steps as $s)
                <li><span class="dot {{ $s['kind'] }}">{{ $dots[$s['kind']] ?? '•' }}</span><div><div class="t">{{ $s['title'] }}</div><div class="d">{{ $s['detail'] }}</div></div></li>
            @endforeach
        </ul>
        <p class="small"><span class="muted">Kitle:</span> {{ $audience }}</p>
    </div>
    <div class="card">
        <h2>Son 30 gün</h2>
        <div class="kpis">
            <div class="kpi"><div class="label">Başlayan</div><div class="value">{{ number_format($c['runs_started'] ?? 0) }}</div></div>
            <div class="kpi"><div class="label">Bekleyen</div><div class="value">{{ number_format($metrics['runs_waiting']) }}</div></div>
            <div class="kpi"><div class="label">Planlı e-posta</div><div class="value">{{ number_format($c['planned_email'] ?? 0) }}</div></div>
            <div class="kpi"><div class="label">Planlı push</div><div class="value">{{ number_format($c['planned_push'] ?? 0) }}</div></div>
        </div>
        <ul class="list-plain small stack-top">
            <li class="split"><span>İzin nedeniyle engellenen</span><span>{{ number_format($c['consent_blocked'] ?? 0) }}</span></li>
            <li class="split"><span>Bastırma nedeniyle engellenen</span><span>{{ number_format($c['suppression_blocked'] ?? 0) }}</span></li>
            <li class="split"><span>Sıklık sınırına takılan</span><span>{{ number_format($c['frequency_capped'] ?? 0) }}</span></li>
            <li class="split"><span>Tekrarı önlenen</span><span>{{ number_format($c['duplicate_prevented'] ?? 0) }}</span></li>
            @foreach ($metrics['exit_reasons'] as $reason => $n)<li class="split"><span class="muted">Çıkış: {{ $reason }}</span><span>{{ number_format($n) }}</span></li>@endforeach
        </ul>
    </div>
</div>

@if ($dryRun)
<div class="card stack-top">
    <h2>Dry-run sonucu</h2>
    <div class="kpis">
        <div class="kpi"><div class="label">Değerlendirilen</div><div class="value">{{ number_format($dryRun['evaluated'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">Akışa girecek</div><div class="value">{{ number_format($dryRun['entered'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">Planlı e-posta</div><div class="value">{{ number_format($dryRun['planned_email'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">Planlı push</div><div class="value">{{ number_format($dryRun['planned_push'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">İzin engeli</div><div class="value">{{ number_format($dryRun['consent_blocked'] ?? 0) }}</div></div>
    </div>
    @if (! empty($dryRun['blocked_reasons']))<p class="small muted">Nedenler: @foreach ($dryRun['blocked_reasons'] as $code => $n){{ $code }} {{ number_format($n) }}@if (! $loop->last) · @endif @endforeach</p>@endif
    <p class="small muted">Dry-run hiçbir kayıt oluşturmaz ve hiçbir sağlayıcıyı çağırmaz.</p>
</div>
@endif

<div class="grid g2 stack-top">
    <div class="card table-wrap">
        <h2>Son akışlar</h2>
        <table class="table">
            <thead><tr><th>Başladı</th><th>Durum</th><th>Adım</th><th>Devam</th><th>Çıkış nedeni</th></tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr><td class="small">{{ \Illuminate\Support\Carbon::parse($r->started_at)->format('d.m H:i') }}</td>
                    <td><span class="badge {{ $runSt[$r->status] ?? 'b-muted' }}">{{ $r->status }}</span></td>
                    <td>{{ $r->current_step }}</td>
                    <td class="small muted">{{ $r->resume_at ? \Illuminate\Support\Carbon::parse($r->resume_at)->format('d.m H:i') : '—' }}</td>
                    <td class="small">{{ $r->exit_reason ?? '' }}</td></tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz akış yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>Versiyonlar</h2>
        <ul class="list-plain">
            @foreach ($versions as $ver)
                <li class="split"><span>v{{ $ver->version }} <span class="small muted">{{ \Illuminate\Support\Carbon::parse($ver->created_at)->format('d.m.Y H:i') }}</span></span>
                    <span class="badge {{ $ver->id === $a->published_version_id ? 'b-pass' : 'b-muted' }}">{{ $ver->id === $a->published_version_id ? 'Yayında' : $ver->status }}</span></li>
            @endforeach
        </ul>
        @if ($canManage && in_array($a->template, ['abandoned_cart', 'winback'], true) && $a->status !== 'archived')
            <form class="form-grid stack-top" method="post" action="{{ route('panel.automations.store') }}">
                @csrf
                <input type="hidden" name="automation" value="{{ $a->id }}"><input type="hidden" name="template" value="{{ $a->template }}"><input type="hidden" name="name" value="{{ $a->name }}">
                <h3>Parametreleri değiştir (yeni versiyon)</h3>
                @if ($a->template === 'abandoned_cart')
                    <label class="field">Bekleme (saat) <input type="number" name="delay_hours" min="1" max="720" value="{{ $delayHours }}"></label>
                @else
                    <label class="field">Sipariş vermeme süresi (gün) <input type="number" name="days" min="1" max="3650" value="{{ $winbackDays }}"></label>
                @endif
                <div class="actions"><button class="btn" type="submit">Yeni versiyon kaydet</button></div>
            </form>
        @endif
    </div>
</div>
@endsection
