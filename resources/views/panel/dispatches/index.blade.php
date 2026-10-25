@extends('panel.layout')
@section('title', 'Gönderimler')
@section('content')
<div class="page-head">
    <div><h1>Gönderimler</h1><p>Kampanya test gönderim talepleri. Canlı gönderim kapalıdır; her talep izinli test alıcılarıyla sınırlıdır.</p></div>
</div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Kampanya</th><th>Kanal</th><th>Mod</th><th>Nesil</th><th>Alıcı</th><th>Durum</th><th>Neden</th><th>Talep</th></tr></thead>
        <tbody>
        @forelse ($rows as $d)
            <tr>
                <td><a href="{{ route('panel.dispatches.show', $d->id) }}"><strong>{{ $d->campaign }}</strong></a></td>
                <td>{{ $d->channel === 'email' ? 'E-posta' : 'Push' }}</td>
                <td><span class="badge b-muted">{{ $d->mode === 'test_only' ? 'Yalnızca test' : 'Canlı' }}</span></td>
                <td>#{{ $d->generation }}</td>
                <td>{{ count((array) json_decode($d->recipients, true)) }}</td>
                <td>@include('panel.dispatches._status', ['status' => $d->status])</td>
                <td class="small muted">{{ $d->block_reason ?? '' }}</td>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($d->requested_at)->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Henüz gönderim talebi yok. Bir kampanyayı hazırlayıp onaylattıktan sonra test gönderimi oluşturabilirsiniz.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
