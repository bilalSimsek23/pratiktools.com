@extends('admin.layout')
@section('title', 'Genel Bakış')
@section('content')
<div class="page-head"><div><h1>Genel Bakış</h1><p class="lead">Platform geneli özet. Müşteri (son kullanıcı) kişisel verisi bu alanda gösterilmez.</p></div>
    <a class="btn btn-primary" href="{{ route('admin.tenants.create') }}">+ Yeni Müşteri</a></div>
<div class="grid g4">
    <div class="card kpi"><div class="label">Müşteriler</div><div class="value">{{ $totals['tenants'] }}</div></div>
    <div class="card kpi"><div class="label">Aktif</div><div class="value">{{ $totals['active'] }}</div></div>
    <div class="card kpi"><div class="label">Askıda</div><div class="value">{{ $totals['suspended'] }}</div></div>
    <div class="card kpi"><div class="label">Bekleyen davet</div><div class="value">{{ $totals['pending_invitations'] }}</div></div>
</div>
<div class="card"><h2>Sistem <span class="badge {{ $health['status'] === 'ok' ? 'b-ok' : 'b-bad' }}">{{ $health['status'] }}</span></h2>
    <p class="muted">Ayrıntılar: <a href="{{ route('admin.system') }}">Sistem Durumu</a></p></div>
<div class="card table-wrap">
<h2>Müşteri etkinliği (son 7 gün, yalnız sayaçlar)</h2>
<table>
    <thead><tr><th>Müşteri</th><th>Kod</th><th>Durum</th><th>Aktif üye</th><th>Entegrasyonlar</th><th>Kampanya</th><th>Otomasyon (yayında/toplam)</th><th>Lead (7g)</th></tr></thead>
    <tbody>
    @forelse ($tenants as $tenant)
        <tr>
            <td><a href="{{ route('admin.tenants.show', $tenant->id) }}">{{ $tenant->name }}</a></td>
            <td><code>{{ $tenant->slug }}</code></td>
            <td><span class="badge {{ $tenant->status === 'active' ? 'b-ok' : 'b-bad' }}">{{ $tenant->status }}</span></td>
            <td>{{ $tenant->members }}</td>
            <td class="muted">@forelse ($tenant->integrations as $integration){{ $integration->provider }}/{{ $integration->capability }}: {{ $integration->status }}<br>@empty yok @endforelse</td>
            <td>{{ array_sum($tenant->campaigns) }}</td>
            <td>{{ $tenant->automations['published'] }}/{{ $tenant->automations['total'] }}</td>
            <td>{{ $tenant->leads_7d['accepted'] }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">Henüz müşteri yok.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
