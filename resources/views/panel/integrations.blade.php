@extends('panel.layout')
@section('title', 'Entegrasyonlar')
@section('content')
@php
    $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->diffForHumans() : '—';
    $conn = fn (array $i) => ! $i['configured'] ? ['b-bad', 'Bağlı değil'] : ($i['connected'] ? ['b-pass', 'Bağlı'] : ['b-warn', $i['status']]);
    $ses = $s['amazon_ses']; $os = $s['onesignal']; $iys = $s['iys']; $tcx = $s['ticimax'];
    $blocked = fn (string ...$keys) => array_values(array_filter(array_map(fn ($k) => ($blockers[$k]['status'] ?? 'PASS') === 'BLOCKED' ? $blockers[$k] : null, $keys)));
    $cards = [
        ['Ticimax', 'E-ticaret verisi (müşteri, sipariş, sepet, ürün) — salt okunur', $tcx, [
            'Sağlık' => ($tcx['last_error'] ?? null) ? 'Son hata: '.$tcx['last_error'] : ($tcx['configured'] ? 'Sorun yok' : 'Bağlantı yok'),
            'Son senkron' => $tcx['last_sync'] ? $when($tcx['last_sync']->started_at).' · '.$tcx['last_sync']->entity.' · '.$tcx['last_sync']->status : 'Henüz yok',
            'Yetenek' => 'Veri okuma · Ticimax\'e yazma kapalı',
        ], $blocked('TICIMAX_VAULT_CONNECTION')],
        ['İYS', 'İleti Yönetim Sistemi — pazarlama izni doğrulama', $iys, [
            'Sağlık' => $iys['consent_available'] ? 'İzin doğrulaması çalışıyor' : 'İzin doğrulanamıyor',
            'Son senkron' => $iys['last_successful_status_sync'] ? $when($iys['last_successful_status_sync']) : 'Henüz yok',
            'Yetenek' => 'İzin okuma · İYS\'ye yazma kapalı',
        ], $blocked('IYS_API_ACCESS')],
        ['Amazon SES', 'E-posta gönderim altyapısı', $ses, [
            'Sağlık' => ($ses['production_access'] ?? null) === true ? 'Production erişimi var' : (($ses['production_access'] ?? null) === false ? 'Sandbox (yalnızca doğrulanmış alıcılar)' : 'Hesap durumu bilinmiyor'),
            'Son kontrol' => $when($ses['account_checked_at'] ?? null).' · bölge '.($ses['region'] ?? '—'),
            'Yetenek' => 'E-posta · mod '.strtoupper($ses['sending_mode']).' · kota '.($ses['quota']['max_24h'] ?? '—').'/24s · unsubscribe anahtarı '.($ses['unsubscribe_key'] === 'CONFIGURED' ? 'tanımlı' : 'EKSİK'),
        ], $blocked('SES_VAULT_CONNECTION', 'SES_PRODUCTION_ACCESS', 'EMAIL_UNSUBSCRIBE_KEY')],
        ['OneSignal', 'Mobil/web push', $os, [
            'Sağlık' => number_format($os['subscribed']).' aktif abone / '.number_format($os['subscriptions']).' abonelik',
            'Son senkron' => $os['last_sync'] ? $when($os['last_sync']->started_at).' · '.$os['last_sync']->status : 'Henüz yok',
            'Yetenek' => 'Push · mod '.strtoupper($os['sending_mode']).' · External ID Coverage: '.number_format($os['external_id_coverage']['with']).' / '.number_format($os['external_id_coverage']['total']),
        ], $blocked('ONESIGNAL_VAULT_CONNECTION', 'ONESIGNAL_EXTERNAL_ID')],
    ];
@endphp
<div class="page-head">
    <div><h1>Entegrasyonlar</h1><p>Bağlantı ve sağlık durumu. Anahtar ve gizli değerler hiçbir ekranda gösterilmez; yalnızca kasada (vault) olup olmadıkları gösterilir.</p></div>
</div>
<div class="grid g2">
    @foreach ($cards as [$name, $what, $i, $facts, $bs])
        @php([$cls, $label] = $conn($i))
        <div class="card">
            <div class="split"><div><h2>{{ $name }}</h2><div class="small muted">{{ $what }}</div></div><span class="badge {{ $cls }}">{{ $label }}</span></div>
            <ul class="list-plain small stack-top">
                <li class="split"><span class="muted">Bağlantı</span><span>{{ $i['configured'] ? ($i['vault_secret'] ? 'Kimlik bilgisi kasada' : 'Kimlik bilgisi yok') : 'Yapılandırılmadı' }}@if ($i['last_verified_at'] ?? null) · doğrulandı {{ $when($i['last_verified_at']) }}@endif</span></li>
                @foreach ($facts as $k => $v)<li class="split"><span class="muted">{{ $k }}</span><span>{{ $v }}</span></li>@endforeach
                <li class="split"><span class="muted">Engel</span><span>@if ($bs === [])<span class="badge b-pass">Yok</span>@else<span class="badge b-bad">{{ count($bs) }} engel</span>@endif</span></li>
            </ul>
            @foreach ($bs as $b)
                <p class="small"><strong>{{ $b['detail'] }}</strong>@if ($b['action'])<br><span class="todo">→ {{ $b['action'] }}</span>@endif</p>
            @endforeach
        </div>
    @endforeach
</div>
@if ($cart)
<div class="card stack-top">
    <h2>Sepet verisi</h2>
    <div class="kpis">
        <div class="kpi"><div class="label">Ürüne bağlı sepet satırı</div><div class="value">{{ number_format($cart['cart_items']['linked']) }} / {{ number_format($cart['cart_items']['total']) }}</div></div>
        <div class="kpi"><div class="label">Müşteriye bağlı sepet</div><div class="value">{{ number_format($cart['carts']['linked']) }} / {{ number_format($cart['carts']['total']) }}</div></div>
        <div class="kpi"><div class="label">Anonim sepet</div><div class="value">{{ number_format($cart['carts']['anonymous']) }}</div></div>
    </div>
</div>
@endif
@endsection
