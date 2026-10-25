@extends('panel.layout')
@section('title', 'Lead metrikleri')
@section('content')
@php
    $c = $report['counters'];
    $outcomeLabels = ['accepted' => 'Kabul', 'duplicate' => 'Tekrar', 'marketplace_relay' => 'Pazaryeri adresi', 'identity_conflict' => 'Kimlik çakışması'];
@endphp
<div class="page-head">
    <div><h1>Lead metrikleri</h1><p>Son 30 gün, toplu sayılar. Kişisel veri gösterilmez.</p></div>
    <a class="btn" href="{{ route('panel.forms') }}">Formlar</a>
</div>
<div class="grid g4">
    <div class="card kpi"><div class="label">Başvuru</div><div class="value">{{ number_format($c['submissions'] ?? 0) }}</div></div>
    <div class="card kpi"><div class="label">Tekil e-posta</div><div class="value">{{ number_format($report['unique_emails']) }}</div></div>
    <div class="card kpi"><div class="label">Yeni alıcı</div><div class="value">{{ number_format($report['new_recipients']) }}</div><div class="sub">{{ number_format($report['existing_recipients']) }} mevcut</div></div>
    <div class="card kpi"><div class="label">İYS ONAY bekleyen</div><div class="value">{{ number_format($report['pending_iys_onay']) }}</div><div class="sub">İYS yazımı kapalı</div></div>
</div>
<div class="grid g3 stack-top">
    <div class="card"><h2>Sonuçlar</h2><ul class="list-plain">@forelse ($report['outcomes'] as $k => $n)<li class="split"><span>{{ $outcomeLabels[$k] ?? $k }}</span><strong>{{ number_format($n) }}</strong></li>@empty<li class="muted">Veri yok</li>@endforelse</ul></div>
    <div class="card"><h2>Yerleşim</h2><ul class="list-plain">@forelse ($report['by_placement'] as $k => $n)<li class="split"><span>{{ $k }}</span><strong>{{ number_format($n) }}</strong></li>@empty<li class="muted">Veri yok</li>@endforelse</ul></div>
    <div class="card"><h2>Kaynak (UTM)</h2><ul class="list-plain">@forelse ($report['by_utm_source'] as $k => $n)<li class="split"><span>{{ $k }}</span><strong>{{ number_format($n) }}</strong></li>@empty<li class="muted">Veri yok</li>@endforelse</ul></div>
</div>
<div class="card table-wrap stack-top">
    <h2>Formlara göre</h2>
    <table class="table">
        <thead><tr><th>Form</th><th>Başvuru</th><th>Kabul</th><th>İzin işaretli</th><th>Bot</th><th>Origin reddi</th></tr></thead>
        <tbody>
        @forelse ($perForm as $row)
            <tr><td><a href="{{ route('panel.forms.show', $row['form']->id) }}">{{ $row['form']->name }}</a></td>
                <td>{{ number_format($row['report']['counters']['submissions'] ?? 0) }}</td><td>{{ number_format($row['report']['counters']['accepted'] ?? 0) }}</td>
                <td>{{ number_format($row['report']['consent_checked']) }}</td><td>{{ number_format($row['report']['counters']['bot_suspected'] ?? 0) }}</td>
                <td>{{ number_format($row['report']['counters']['origin_refused'] ?? 0) }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted">Form yok.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
