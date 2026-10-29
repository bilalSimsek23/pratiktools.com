@extends('panel.layout')
@section('title', 'Dashboard')
@section('content')
@php
    $s = $integrations;
    $ses = $s['amazon_ses']; $os = $s['onesignal']; $iys = $s['iys']; $tcx = $s['ticimax'];
    $sys = [
        ['Ticimax', $tcx['connected'] ? ['b-pass', 'Bağlı'] : ($tcx['configured'] ? ['b-warn', 'Kontrol gerekli'] : ['b-bad', 'Bağlı değil']),
            $tcx['last_sync'] ? 'Son senkron: '.\Illuminate\Support\Carbon::parse($tcx['last_sync']->started_at)->diffForHumans() : 'Henüz senkron yok'],
        ['İYS', $iys['strategy']['ready'] ? ['b-pass', 'Doğrulanıyor'] : ['b-bad', $iys['strategy']['strategy'] === 'direct_api' ? 'API erişimi bekleniyor' : 'ViA/mutabakat bekleniyor'],
            $iys['strategy']['label'].' · '.($iys['strategy']['ready'] ? 'izinler doğrulanıyor' : 'pazarlama e-postası İYS doğrulaması olmadan gönderilemez')],
        ['Amazon SES', ! $ses['configured'] ? ['b-bad', 'Bağlı değil'] : (($ses['production_access'] ?? null) === true ? ['b-pass', 'Production'] : ['b-bad', 'Production erişimi bekleniyor']),
            $ses['configured'] ? 'Bölge '.($ses['region'] ?? '—').' · gönderim '.strtoupper($ses['sending_mode']) : 'Hesap bu ortamda bağlı değil'],
        ['OneSignal', ! $os['configured'] ? ['b-bad', 'Bağlı değil'] : (($os['external_id_coverage']['with'] ?? 0) > 0 ? ['b-pass', 'Bağlı'] : ['b-warn', 'Kimlik eşleştirme gerekli']),
            $os['configured'] ? number_format($os['subscribed']).' abone · eşleşen '.($os['external_id_coverage']['with'] ?? 0).'/'.($os['external_id_coverage']['total'] ?? 0) : 'Hesap bu ortamda bağlı değil'],
    ];
@endphp
<div class="page-head">
    <div><h1>Genel bakış</h1><p>Kampanya, kitle ve sistem durumunun tek ekranda özeti. Kişisel veri gösterilmez.</p></div>
    @if ($panelCan(\App\Tenancy\Permission::CampaignsManage))<div class="actions"><a class="btn btn-primary" href="{{ route('panel.campaigns.create') }}">+ Yeni kampanya</a></div>@endif
</div>

<div class="grid g4">
    <div class="card kpi"><div class="label">Kampanyalar</div><div class="value">{{ $campaigns['active'] }}</div>
        <div class="sub">{{ $campaigns['draft'] }} taslak · {{ $campaigns['ready'] }} hazır · {{ $campaigns['scheduled'] }} planlı</div>
        @if ($campaigns['blocked'] > 0)<div class="sub"><span class="badge b-bad">{{ $campaigns['blocked'] }} harici engelde</span></div>@endif</div>
    <div class="card kpi"><div class="label">Müşteriler</div><div class="value">{{ number_format($customers) }}</div>
        <div class="sub">{{ number_format($consent['recipients']) }} e-posta alıcısı</div></div>
    <div class="card kpi"><div class="label">Yeni lead (30 gün)</div><div class="value">{{ number_format($leads_30d['accepted']) }}</div>
        <div class="sub">{{ number_format($leads_30d['submissions']) }} başvuru · {{ number_format($leads_30d['newsletter']) }} bülten kaydı</div></div>
    <div class="card kpi"><div class="label">Pazarlama izni uygun</div><div class="value">{{ number_format($consent['allowed']) }}</div>
        <div class="sub">{{ number_format($consent['blocked']) }} alıcı izin/engel nedeniyle dışarıda</div></div>
</div>

<div class="grid g3 stack-top">
    <div class="card">
        <h2>Otomasyonlar</h2>
        <div class="kpis">
            <div class="kpi"><div class="label">Aktif</div><div class="value">{{ $automations['published'] }}</div></div>
            <div class="kpi"><div class="label">Bekleyen akış</div><div class="value">{{ number_format($waitingRuns) }}</div></div>
            <div class="kpi"><div class="label">Planlı e-posta</div><div class="value">{{ number_format($automations['planned_email_30d']) }}</div></div>
            <div class="kpi"><div class="label">Planlı push</div><div class="value">{{ number_format($automations['planned_push_30d']) }}</div></div>
        </div>
        <p class="small muted">Planlı aksiyonlar gönderilmez; gönderim katmanı açıldığında tekrar kontrol edilir.</p>
    </div>
    <div class="card">
        <h2>Sistem hazırlığı</h2>
        <ul class="list-plain">
            @foreach ($sys as [$name, [$cls, $label], $detail])
                <li class="split"><div><strong>{{ $name }}</strong><div class="small muted">{{ $detail }}</div></div><span class="badge {{ $cls }}">{{ $label }}</span></li>
            @endforeach
        </ul>
        @if ($panelCan(\App\Tenancy\Permission::IntegrationsView))<a class="small" href="{{ route('panel.integrations') }}">Entegrasyon ayrıntıları →</a>@endif
    </div>
    <div class="card">
        <h2>Bildirimler</h2>
        @forelse ($notifications as $n)
            <div class="split small"><span>{{ $n->message }}</span>
                <form class="inline" method="post" action="{{ route('panel.notifications.read', $n->id) }}">@csrf<button class="btn btn-sm" type="submit">Okundu</button></form></div>
        @empty
            <p class="muted">Yeni bildirim yok.</p>
        @endforelse
        @if ($canApprove)<a class="small" href="{{ route('panel.approvals') }}">Onay kutusu →</a>@endif
    </div>
</div>

<div class="card stack-top">
    <div class="split"><h2>Harici engeller</h2><span class="small muted">Canlı gönderim bu maddeler tamamlanmadan açılamaz.</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Gereksinim</th><th>Durum</th><th>Ayrıntı</th><th>Yapılması gereken</th></tr></thead>
        <tbody>
        @foreach ($blockers as $b)
            <tr>
                <td><strong>{{ \Illuminate\Support\Str::of($b['blocker'])->replace('_', ' ')->title() }}</strong></td>
                <td>@if ($b['status'] === 'PASS')<span class="badge b-pass">Tamam</span>@elseif ($b['status'] === 'WAIT')<span class="badge b-warn">Bekliyor</span>@else<span class="badge b-bad">Engelli</span>@endif</td>
                <td class="muted">{{ $b['detail'] }}</td>
                <td>{{ $b['action'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

<div class="grid g2 stack-top">
    <div class="card">
        <h2>Gönderim modları</h2>
        <ul class="list-plain">
            <li class="split"><span>E-posta</span><span class="badge {{ $ses['sending_mode'] === 'off' ? 'b-muted' : 'b-warn' }}">{{ strtoupper($ses['sending_mode']) }}</span></li>
            <li class="split"><span>Push</span><span class="badge {{ $os['sending_mode'] === 'off' ? 'b-muted' : 'b-warn' }}">{{ strtoupper($os['sending_mode']) }}</span></li>
            <li class="split"><span>Unsubscribe anahtarı</span><span class="badge {{ $ses['unsubscribe_key'] === 'CONFIGURED' ? 'b-pass' : 'b-bad' }}">{{ $ses['unsubscribe_key'] === 'CONFIGURED' ? 'Tanımlı' : 'Eksik' }}</span></li>
        </ul>
    </div>
    <div class="card">
        <h2>Sepet verisi</h2>
        @if ($cart)
            <ul class="list-plain">
                <li class="split"><span>Sepet satırları (ürüne bağlı)</span><strong>{{ number_format($cart['cart_items']['linked']) }} / {{ number_format($cart['cart_items']['total']) }}</strong></li>
                <li class="split"><span>Müşteriye bağlı sepetler</span><strong>{{ number_format($cart['carts']['linked']) }} / {{ number_format($cart['carts']['total']) }}</strong></li>
                <li class="split"><span>Anonim sepetler</span><strong>{{ number_format($cart['carts']['anonymous']) }}</strong></li>
            </ul>
        @else
            <p class="muted">Sepet verisi yok.</p>
        @endif
    </div>
</div>
@endsection
