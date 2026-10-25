@extends('panel.layout')
@section('title', 'Onaylar')
@section('content')
@php
    $apStatus = ['pending' => ['b-warn', 'Bekliyor'], 'approved' => ['b-pass', 'Onaylandı'], 'rejected' => ['b-bad', 'Reddedildi'], 'expired' => ['b-muted', 'Süresi doldu'], 'invalidated' => ['b-muted', 'Geçersiz']];
    $who = fn ($id) => $id === null ? '—' : ($people[$id] ?? 'Üye');
@endphp
<div class="page-head">
    <div><h1>Onaylar</h1><p>Onay, kampanyanın dondurulmuş içerik/kitle/gönderici haline bağlıdır; herhangi bir değişiklik onayı geçersiz kılar. Kendi isteğinizi veya oluşturduğunuz kampanyayı onaylayamazsınız.</p></div>
</div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Kampanya</th><th>Adım</th><th>Durum</th><th>İsteyen</th><th>Karar veren</th><th>Son geçerlilik</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $a)
            <tr>
                <td><a href="{{ route('panel.campaigns.show', $a->campaign_id) }}"><strong>{{ $a->campaign }}</strong></a></td>
                <td>{{ $a->slot === 'review' ? 'İnceleme' : 'Onay' }}</td>
                <td><span class="badge {{ $apStatus[$a->status][0] ?? 'b-muted' }}">{{ $apStatus[$a->status][1] ?? $a->status }}</span></td>
                <td class="small">{{ $who($a->requested_by) }}</td>
                <td class="small">{{ $who($a->decided_by) }}@if ($a->reason)<div class="muted">{{ $a->reason }}</div>@endif</td>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($a->expires_at)->format('d.m.Y') }}</td>
                <td>@if ($a->status === 'pending' && $canApprove && $a->requested_by !== $me)<a class="btn btn-sm btn-primary" href="{{ route('panel.campaigns.show', $a->campaign_id) }}">İncele</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Onay bekleyen kampanya yok.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
