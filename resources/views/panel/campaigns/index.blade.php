@extends('panel.layout')
@section('title', 'Kampanyalar')
@section('content')
<div class="page-head">
    <div><h1>Kampanyalar</h1><p>Hazırla, onaya gönder, test et. Canlı gönderim harici engeller kalkana kadar kapalıdır.</p></div>
    <a class="btn btn-primary" href="{{ route('panel.campaigns.create') }}">+ Yeni kampanya</a>
</div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Kampanya</th><th>Tür</th><th>Kitle</th><th>İçerik</th><th>Gönderici</th><th>Onay</th><th>Durum</th><th>Güncellendi</th></tr></thead>
        <tbody>
        @forelse ($campaigns as $c)
            <tr>
                <td><a href="{{ route('panel.campaigns.show', $c->id) }}"><strong>{{ $c->name }}</strong></a><div class="small muted">{{ strtoupper($c->channel) }}</div></td>
                <td>{{ ['newsletter' => 'Bülten', 'promotional' => 'Kampanya', 'announcement' => 'Duyuru'][$c->type] ?? $c->type }}</td>
                <td>{{ $c->segment ?? '—' }}@if ($c->snapshot_size !== null)<div class="small muted">{{ number_format($c->snapshot_size) }} kişi (donduruldu)</div>@endif</td>
                <td>{{ $c->template ? $c->template.' v'.$c->template_version : '—' }}</td>
                <td class="small">{{ $c->sender ?? '—' }}</td>
                <td>{{ $c->approvals }}</td>
                <td>@include('panel.campaigns._status', ['status' => $c->status])</td>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($c->updated_at)->diffForHumans() }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Henüz kampanya yok. İlk kampanyanızı oluşturun.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
