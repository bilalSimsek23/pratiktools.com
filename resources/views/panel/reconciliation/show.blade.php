@extends('panel.layout')
@section('title', 'İYS mutabakat raporu')
@section('content')
@php
    $labels = ['onay' => 'ONAY', 'ret' => 'RET', 'unchanged' => 'Değişmeyen', 'conflict' => 'Çakışma', 'invalid' => 'Geçersiz', 'unknown_recipient' => 'Bilinmeyen alıcı',
        'marketplace_excluded' => 'Pazaryeri (hariç)', 'duplicate' => 'Tekrar'];
    $statusLabels = ['GRANTED' => 'ONAY', 'REVOKED' => 'RET'];
@endphp
<div class="page-head">
    <div><h1>{{ $b->original_filename }}</h1>
        <p>Dönem {{ $b->period_start ? \Illuminate\Support\Carbon::parse($b->period_start)->format('d.m.Y').' – ' : '' }}{{ \Illuminate\Support\Carbon::parse($b->period_end)->format('d.m.Y') }}
            · {{ $b->status === 'applied' ? 'Uygulandı '.\Illuminate\Support\Carbon::parse($b->applied_at)->format('d.m.Y H:i') : ($b->status === 'analyzed' ? 'Dry-run — izin durumu henüz değişmedi' : 'Durum: '.$b->status) }}
            @if ($b->error) · <span class="badge b-bad">{{ \App\Http\Controllers\Panel\ReconciliationController::message($b->error) }}</span>@endif</p></div>
    @if ($canApply && $b->status === 'analyzed')
        <div class="actions"><form method="post" action="{{ route('panel.reconciliation.apply', $b->id) }}">@csrf<button class="btn btn-primary" type="submit">Uygula</button></form></div>
    @endif
</div>
<div class="card">
    <div class="kpis">
        <div class="kpi"><div class="label">Satır</div><div class="value">{{ number_format($counts['rows'] ?? 0) }}</div></div>
        @foreach ($labels as $k => $l)
            <div class="kpi"><div class="label"><a href="{{ route('panel.reconciliation.show', ['batch' => $b->id, 'outcome' => $k]) }}">{{ $l }}</a></div><div class="value">{{ number_format($counts[$k] ?? 0) }}</div></div>
        @endforeach
    </div>
    <p class="small muted">Çakışma: rapordaki değişiklik bilinen daha yeni resmî durumla çelişiyor veya yerel olarak engellenmiş (abonelikten çıkmış) bir adres için ONAY. Yerel engel otomatik kaldırılmaz.</p>
</div>
<div class="card stack-top table-wrap">
    <div class="split"><h2>Satırlar @if ($outcome)· {{ $labels[$outcome] }}@endif</h2>@if ($outcome)<a class="small" href="{{ route('panel.reconciliation.show', $b->id) }}">Tümü</a>@endif</div>
    <table class="table">
        <thead><tr><th>#</th><th>Alıcı</th><th>Kanal</th><th>Tip</th><th>Durum</th><th>Tarih</th><th>Sonuç</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td class="small muted">{{ $r->row_number }}</td>
                <td><code>{{ $r->masked ?? ($r->address_hash ? 'bilinmeyen · '.substr($r->address_hash, 0, 8) : '—') }}</code></td>
                <td>{{ $r->channel ?? '—' }}</td><td class="small">{{ $r->recipient_type ?? '—' }}</td>
                <td>{{ $statusLabels[$r->status] ?? '—' }}</td>
                <td class="small muted">{{ $r->occurred_at ? \Illuminate\Support\Carbon::parse($r->occurred_at)->format('d.m.Y H:i') : '—' }}</td>
                <td>{{ $labels[$r->outcome] ?? $r->outcome }}@if ($r->detail) <span class="small muted">({{ $r->detail }})</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Satır yok.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="small muted">En fazla 200 satır gösterilir. Adresler maskelenmiştir; bilinmeyen alıcılar için adres saklanmaz.</p>
</div>
@endsection
