@extends('admin.layout')
@section('title', 'Müşteriler')
@section('content')
<div class="page-head"><div><h1>Müşteriler</h1><p class="lead">PratikTools'u kullanan şirketler (tenant). Her biri verisiyle tamamen ayrıdır.</p></div>
    <a class="btn btn-primary" href="{{ route('admin.tenants.create') }}">+ Yeni Müşteri</a></div>
<form class="card inline" method="get" action="{{ route('admin.tenants') }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="Şirket adı, kod veya owner e-posta" aria-label="Ara">
    <select name="durum" aria-label="Durum"><option value="">Tüm durumlar</option><option value="active" @selected($status === 'active')>Aktif</option><option value="suspended" @selected($status === 'suspended')>Askıda</option></select>
    <button type="submit">Filtrele</button>
</form>
<div class="card table-wrap">
<table>
    <thead><tr><th>Şirket</th><th>Kod</th><th>Durum</th><th>Oluşturulma</th><th>Owner</th><th>Owner e-posta</th><th>Kullanıcı</th><th>Entegrasyon</th><th>Son aktivite</th><th>Web sitesi</th></tr></thead>
    <tbody>
    @forelse ($tenants as $t)
        <tr>
            <td><a href="{{ route('admin.tenants.show', $t->id) }}"><strong>{{ $t->name }}</strong></a></td>
            <td><code>{{ $t->slug }}</code></td>
            <td><span class="badge {{ $t->status === 'active' ? 'b-ok' : 'b-bad' }}">{{ $t->status === 'active' ? 'Aktif' : 'Askıda' }}</span></td>
            <td class="muted">{{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d.m.Y') }}</td>
            <td>{{ $t->owner->name ?? '—' }}@if (($t->owner->status ?? null) === 'invited') <span class="badge b-warn">davet bekliyor</span>@endif</td>
            <td>{{ $t->owner->email ?? '—' }}</td>
            <td>{{ $t->members }}</td>
            <td>{{ \App\Tenancy\TenantOnboarding::readyCount($t->onboarding) }}/{{ count($t->onboarding) }} hazır</td>
            <td class="muted">{{ $t->last_activity ? \Illuminate\Support\Carbon::parse($t->last_activity)->diffForHumans() : '—' }}</td>
            <td>@if ($t->site_owner)<span class="badge">pratiktools.com sahibi</span>@else<span class="muted">—</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="10" class="muted">Sonuç yok.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
