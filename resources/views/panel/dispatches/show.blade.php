@extends('panel.layout')
@section('title', 'Gönderim talebi')
@section('content')
@php($q = $plan['quota'] ?? null)
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.dispatches') }}">Gönderimler</a> / <a href="{{ route('panel.campaigns.show', $campaign->id) }}">{{ $campaign->name }}</a></div>
        <h1>{{ $d->channel === 'email' ? 'E-posta' : 'Push' }} test gönderimi #{{ $d->generation }} @include('panel.dispatches._status', ['status' => $d->status])</h1>
        <p>Mod: yalnızca test · Talep {{ \Illuminate\Support\Carbon::parse($d->requested_at)->format('d.m.Y H:i') }}
            @if ($d->block_reason) · <strong>Neden:</strong> {{ $d->block_reason }}@endif</p></div>
    <div class="actions">
        @if ($canQueue)
            <form class="inline" method="post" action="{{ route('panel.dispatches.queue', $d->id) }}">@csrf<button class="btn btn-primary" type="submit">{{ $d->status === 'failed' ? 'Yeniden dene' : 'Kuyruğa al' }}</button></form>
        @endif
        @if ($canCancel)
            <form class="inline" method="post" action="{{ route('panel.dispatches.cancel', $d->id) }}">@csrf<button class="btn btn-danger" type="submit">İptal et</button></form>
        @endif
    </div>
</div>
@if ($d->status === 'awaiting_approval')
    <div class="alert">Bu talep kampanya onayı bekliyor. Onay tamamlandığında kuyruğa alınabilir.</div>
@endif
<div class="grid g2">
    <div class="card">
        <h2>Gönderim planı</h2>
        <p class="small muted">Plan sağlayıcıya hiçbir çağrı yapmadan hesaplandı.</p>
        <ul class="list-plain">
            <li class="split"><span>Değerlendirilen alıcı</span><strong>{{ $plan['recipients_evaluated'] ?? 0 }}</strong></li>
            <li class="split"><span>Gönderilebilir</span><strong>{{ $plan['recipients_eligible'] ?? 0 }}</strong></li>
            <li class="split"><span>Engellenen</span><strong>{{ $plan['recipients_blocked'] ?? 0 }}</strong></li>
            @foreach (($plan['blocked_reasons'] ?? []) as $code => $n)<li class="split small"><span class="muted">· {{ $code }}</span><span>{{ $n }}</span></li>@endforeach
            <li class="split"><span>Sağlayıcı</span><strong>{{ $plan['provider'] ?? '—' }}</strong></li>
            @if ($plan['sender'] ?? null)<li class="split"><span>Gönderici</span><strong>{{ $plan['sender'] }}</strong></li>@endif
            <li class="split"><span>Tahmini çağrı / süre</span><strong>{{ $plan['estimated_calls'] ?? 0 }} / {{ $plan['estimated_duration_seconds'] ?? 0 }} sn</strong></li>
            <li class="split"><span>Plan sırasında sağlayıcı çağrısı</span><strong>{{ $plan['provider_calls_made'] ?? 0 }}</strong></li>
            @isset($plan['sending_mode'])<li class="split"><span>Gönderim modu (plan anında)</span><span class="badge {{ $plan['sending_mode'] === 'test_only' ? 'b-info' : 'b-muted' }}">{{ strtoupper($plan['sending_mode']) }}</span></li>@endisset
        </ul>
        @if (($plan['sending_mode'] ?? null) === 'off')<p class="small muted">Gönderim modu kapalı: bu talep kuyruğa alınamaz. Test gönderimi için operatörün modu TEST_ONLY yapması gerekir.</p>@endif
        @if ($q)
            <h3>Amazon SES kotası</h3>
            <ul class="list-plain small">
                <li class="split"><span>Hesap</span><span class="badge {{ $q['sandbox'] ? 'b-warn' : 'b-pass' }}">{{ $q['sandbox'] ? 'Sandbox' : 'Production' }}</span></li>
                <li class="split"><span>24 saatlik limit</span><span>{{ $q['max_24h'] ?? '—' }}</span></li>
                <li class="split"><span>Son 24 saatte gönderilen</span><span>{{ $q['sent_last_24h'] ?? '—' }}</span></li>
                <li class="split"><span>Gönderim sonrası</span><span>{{ $q['after_dispatch'] ?? '—' }}</span></li>
            </ul>
            @if ($q['sandbox'])<p class="small muted">SES sandbox: her test alıcısı SES'te doğrulanmış kimlik olmalıdır.</p>@endif
        @endif
    </div>
    <div class="card">
        <h2>Test alıcıları</h2>
        <ul class="list-plain">
            @foreach ($recipients as $r)<li class="split"><span>{{ $r->label }}</span><span class="badge b-muted">{{ $r->channel === 'email' ? 'E-posta' : 'Push' }}</span></li>@endforeach
        </ul>
        <h2 class="stack-top">Gözlem</h2>
        <ul class="list-plain">
            <li class="split"><span>Oluşturulan mesaj</span><strong>{{ $observed['messages'] }}</strong></li>
            @foreach ($observed['by_status'] as $st => $n)<li class="split small"><span class="muted">· {{ $st }}</span><span>{{ $n }}</span></li>@endforeach
            @foreach ($observed['blocked_reasons'] as $code => $n)<li class="split small"><span class="muted">· engel {{ $code ?: '—' }}</span><span>{{ $n }}</span></li>@endforeach
            @foreach ($observed['events'] as $ev => $n)<li class="split small"><span class="muted">· olay {{ $ev }}</span><span>{{ $n }}</span></li>@endforeach
            <li class="split"><span>Süre</span><strong>{{ $observed['duration_seconds'] !== null ? $observed['duration_seconds'].' sn' : '—' }}</strong></li>
        </ul>
        @if ($d->status === 'cancelled')<p class="small muted">İptal edilen talep sürdürülemez; yeni gönderim için kampanyadan yeni talep oluşturun.</p>@endif
    </div>
</div>
@endsection
