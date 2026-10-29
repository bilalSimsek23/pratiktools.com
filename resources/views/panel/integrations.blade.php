@extends('panel.layout')
@section('title', 'Entegrasyonlar')
@section('content')
@php
    $when = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->diffForHumans() : '—';
    $conn = fn (array $i) => ! $i['configured'] ? ['b-bad', 'Bağlı değil'] : ($i['connected'] ? ['b-pass', 'Bağlı'] : ['b-warn', $i['status']]);
    $ses = $s['amazon_ses']; $os = $s['onesignal']; $iys = $s['iys']; $tcx = $s['ticimax'];
    $blocked = fn (string ...$keys) => array_values(array_filter(array_map(fn ($k) => ($blockers[$k]['status'] ?? 'PASS') !== 'PASS' ? $blockers[$k] : null, $keys)));
    $cards = [
        ['Ticimax', 'E-ticaret verisi (müşteri, sipariş, sepet, ürün) — salt okunur', $tcx, [
            'Sağlık' => ($tcx['last_error'] ?? null) ? 'Son hata: '.$tcx['last_error'] : ($tcx['configured'] ? 'Sorun yok' : 'Bağlantı yok'),
            'Son senkron' => $tcx['last_sync'] ? $when($tcx['last_sync']->started_at).' · '.$tcx['last_sync']->entity.' · '.$tcx['last_sync']->status : 'Henüz yok',
            'Yetenek' => 'Veri okuma · Ticimax\'e yazma kapalı',
        ], $blocked('TICIMAX_VAULT_CONNECTION')],
        ['İYS', 'İleti Yönetim Sistemi — pazarlama izni · strateji: '.$iys['strategy']['label'], $iys, [
            'Sağlık' => $iys['strategy']['ready'] ? 'İzin kararı verilebiliyor' : 'Fail-closed ('.$iys['strategy']['blocker'].')',
            'İYS API senkronu' => $iys['last_successful_status_sync'] ? $when($iys['last_successful_status_sync']) : 'Henüz yok'.($iys['strategy']['direct_api']['required'] ? '' : ' · bu stratejide gerekli değil'),
            'Yetenek' => 'İzin okuma · İYS/ViA\'ya yazma kapalı',
        ], $blocked('IYS_API_ACCESS', 'IYS_VIA_RECONCILIATION'), 'iys'],
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
    @foreach ($cards as $card)
        @php([$name, $what, $i, $facts, $bs] = $card)
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
            @if (($card[5] ?? null) === 'iys')
                <h3 class="stack-top">ViA + günlük mutabakat</h3>
                @include('panel.reconciliation._iys', ['state' => $iys['strategy']])
                <a class="small" href="{{ route('panel.reconciliation') }}">İYS › Mutabakat →</a>
            @endif
        </div>
    @endforeach
</div>
@php($via = $s['via'])
<div class="card stack-top" id="via">
    @php([$vcls, $vlabel] = ['PASS' => ['b-pass', 'Hazır'], 'WAIT' => ['b-warn', 'Bekliyor']][$via['level']] ?? ['b-bad', 'Engelli'])
    <div class="split"><div><h2>İYS ViA</h2><div class="small muted">ViA OTP ile ETK izni alma — İYS'nin resmî teknik bilgileri gelene kadar bağlantı kapalı</div></div><span class="badge {{ $vcls }}">{{ $vlabel }}</span></div>
    @if ($via['configured'])
        @php($integrationLabels = ['ready' => 'Hazır', 'waiting_iys_approval' => 'İYS onayı bekleniyor', 'waiting_specification' => 'İYS teknik dokümanı bekleniyor', 'blocked' => 'Engelli', 'not_configured' => 'Yapılandırılmadı'])
        <ul class="list-plain small stack-top">
            <li class="split"><span class="muted">Hizmet sağlayıcı</span><span>İYS No {{ $via['iys_number'] ?? '—' }} · Marka {{ $via['brand'] ?? '—' }}</span></li>
            <li class="split"><span class="muted">Paket</span><span>{{ $via['package_status'] === 'active' ? 'Aktif' : ($via['package_status'] === 'inactive' ? 'Pasif' : 'Bilinmiyor') }}</span></li>
            <li class="split"><span class="muted">Form tipi</span><span>{{ $via['form_type'] }}</span></li>
            <li class="split"><span class="muted">Onay tipi</span><span>{{ $via['approval_type'] }}</span></li>
            <li class="split"><span class="muted">Form ID</span><span><code>{{ $via['form_id_masked'] ?? 'Tanımlı değil' }}</code></span></li>
            <li class="split"><span class="muted">Form durumu</span><span>{{ $via['form_status_label'] }}</span></li>
            <li class="split"><span class="muted">Teknik entegrasyon</span><span>{{ $integrationLabels[$via['integration_status']] ?? $via['integration_status'] }}</span></li>
            <li class="split"><span class="muted">Production izin toplama</span>@if ($via['acquisition_allowed'])<span class="badge b-pass">Açık</span>@else<span class="badge b-bad">Kapalı</span>@endif</li>
        </ul>
        @unless ($via['acquisition_allowed'])<p class="small"><strong>{{ $via['detail'] }}</strong></p>@endunless
        @if ($canManageVia && $via['next_form_statuses'] !== [])
            @php($statusLabels = ['pending_approval' => 'Onaya gönderildi', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi', 'disabled' => 'Devre dışı'])
            <form method="post" action="{{ route('panel.integrations.via-status') }}" class="form-grid stack-top">@csrf
                <label>Form durumunu güncelle (İYS portalında gördüğünüz duruma göre)
                    <select name="status">@foreach ($via['next_form_statuses'] as $st)<option value="{{ $st }}">{{ $statusLabels[$st] ?? $st }}</option>@endforeach</select></label>
                <label>Not <input type="text" name="note" maxlength="255" required placeholder="Örn. İYS portalında onay ekranı görüldü"></label>
                @if (in_array('approved', $via['next_form_statuses'], true))
                    <label class="small"><input type="checkbox" name="portal_confirmed" value="1"> Formun İYS portalında "Onaylandı" durumunda olduğunu gördüm.</label>
                @endif
                <div class="actions"><button class="btn" type="submit">Durumu kaydet</button></div>
                <p class="hint">Onaylandı olarak işaretlemek tek başına izin toplamayı açmaz: İYS'nin resmî teknik entegrasyonu da gerekir. İşlem denetim kaydına yazılır.</p>
            </form>
        @endif
    @else
        <p class="muted stack-top">ViA yapılandırılmadı. Operatör: <code>php artisan consent:via</code></p>
    @endif
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
