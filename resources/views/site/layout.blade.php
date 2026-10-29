<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $meta['title'] }}</title>
    <meta name="description" content="{{ $meta['description'] }}">
    <meta name="robots" content="{{ $meta['robots'] }}">
    <link rel="canonical" href="{{ $meta['canonical'] }}">
    @foreach ($meta['og'] as $k => $v)<meta property="{{ $k }}" content="{{ $v }}">
    @endforeach
    @foreach ($meta['twitter'] as $k => $v)<meta name="{{ $k }}" content="{{ $v }}">
    @endforeach
    <meta name="theme-color" content="#0b1220">
    <link rel="icon" href="/favicon.ico">
    <link rel="stylesheet" href="/site/site.css?v={{ @filemtime(public_path('site/site.css')) }}">
    @if ($jsonLd)<script type="application/ld+json" nonce="{{ $cspNonce }}">{!! $jsonLd !!}</script>@endif
    <script type="application/json" id="pt-config" nonce="{{ $cspNonce }}">{!! json_encode(['analytics' => $analytics, 'event' => $event, 'path' => '/'.ltrim(request()->path(), '/')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) !!}</script>
    <script src="/site/site.js?v={{ @filemtime(public_path('site/site.js')) }}" defer></script>
</head>
<body>
<a class="skip" href="#main">İçeriğe geç</a>
<header class="site-header">
    <div class="wrap header-row">
        <a class="brand" href="/" aria-label="PratikTools ana sayfa"><span class="brand-mark" aria-hidden="true">P</span>PratikTools</a>
        <nav class="nav" aria-label="Ana menü">
            <a href="/hizmetler" @class(['active' => request()->is('hizmetler*')])>Hizmetler</a>
            <a href="/cozumler" @class(['active' => request()->is('cozumler*')])>Çözümler</a>
            <a href="/projeler" @class(['active' => request()->is('projeler*')])>Projeler</a>
            <a href="/blog" @class(['active' => request()->is('blog*')])>Blog</a>
            <a href="/hakkimizda" @class(['active' => request()->is('hakkimizda')])>Hakkımızda</a>
        </nav>
        <a class="btn btn-primary btn-sm header-cta" href="/iletisim">Projenizi anlatın</a>
    </div>
</header>
<main id="main">
@yield('content')
</main>
<footer class="site-footer">
    <div class="wrap footer-grid">
        <div>
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">P</span>PratikTools</a>
            <p class="muted small">E-ticaret entegrasyonu, otomasyon ve yapay zeka çözümleri.</p>
            @if ($org['email'])<p class="small"><a href="mailto:{{ $org['email'] }}" data-track="contact_click">{{ $org['email'] }}</a></p>@endif
            @if ($org['phone'])<p class="small"><a href="tel:{{ preg_replace('/[^0-9+]/', '', $org['phone']) }}" data-track="contact_click">{{ $org['phone'] }}</a></p>@endif
        </div>
        <div>
            <h2 class="footer-title">Hizmet alanları</h2>
            <ul class="footer-links">@foreach (array_slice($serviceCategories, 0, 8) as $c)<li><a href="/hizmetler#{{ $c['slug'] }}">{{ $c['name'] }}</a></li>@endforeach</ul>
        </div>
        <div>
            <h2 class="footer-title">Site</h2>
            <ul class="footer-links">
                <li><a href="/cozumler">Çözümler</a></li><li><a href="/projeler">Projeler</a></li><li><a href="/blog">Blog</a></li>
                <li><a href="/hakkimizda">Hakkımızda</a></li><li><a href="/iletisim">İletişim</a></li>
                @if ($org['kvkk_url'])<li><a href="{{ $org['kvkk_url'] }}">KVKK Aydınlatma Metni</a></li>@endif
            </ul>
        </div>
    </div>
    <div class="wrap footer-bottom small muted">
        <span>© {{ date('Y') }} PratikTools</span>
        <span>Adı geçen ürün ve marka adları sahiplerine aittir; anılmaları iş ortaklığı veya onay anlamına gelmez.</span>
    </div>
</footer>
@if ($analytics !== [])
<div class="consent" id="pt-consent" hidden role="dialog" aria-labelledby="pt-consent-title">
    <div class="wrap consent-row">
        <p id="pt-consent-title" class="small">Siteyi geliştirmek ve reklamlarımızı ölçmek için, onay verirseniz analiz çerezleri kullanıyoruz. Zorunlu olmayan çerezler onayınız olmadan yüklenmez.</p>
        <div class="consent-actions"><button class="btn btn-sm" type="button" data-consent="deny">Reddet</button><button class="btn btn-primary btn-sm" type="button" data-consent="allow">Kabul et</button></div>
    </div>
</div>
@endif
</body>
</html>
