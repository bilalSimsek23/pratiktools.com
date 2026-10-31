@extends('panel.layout')
@section('title', 'Site ayarları')
@section('content')
<div class="page-head"><div><h1>Web Sitesi · Formlar ve Site Ayarları</h1><p>Buradaki ayarlar veritabanında tutulur ve sitenin tek kaynağıdır; ortam değişkenleri yalnız ilk kurulum yedeğidir. Gizli değerler (depolama erişim bilgileri, API anahtarları) burada tutulmaz. Analitik sağlayıcıları yalnızca kimlik numarasıyla bağlanır ve ziyaretçi çerez onayı vermeden yüklenmez.</p></div></div>
<div class="grid g2">
<div class="card">
    <h2>Talep formu</h2>
    @if ($form)
        <ul class="list-plain small">
            <li class="split"><span>Lead formu</span><span>{{ $form->name }}</span></li>
            <li class="split"><span>Durum</span>@if ($form->active)<span class="badge b-pass">Aktif</span>@else<span class="badge b-warn">Pasif</span>@endif</li>
            <li class="split"><span>Site adresi izinli mi</span>@if (in_array($origin, (array) json_decode((string) $form->allowed_origins, true), true))<span class="badge b-pass">Evet</span>@else<span class="badge b-bad">Hayır ({{ $origin }})</span>@endif</li>
            <li class="split"><span>Talep kabulü</span>@if ($inquiryAvailable)<span class="badge b-pass">Açık</span>@else<span class="badge b-bad">Kapalı</span>@endif</li>
        </ul>
        <a class="small" href="{{ route('panel.forms.show', $form->id) }}">Form ayrıntıları ve metrikler →</a>
    @else
        <p class="small"><span class="badge b-bad">Kurulmadı</span> Operatör: <code>php artisan site:setup --tenant=&lt;slug&gt;</code></p>
    @endif
</div>
<div class="card">
    <h2>Kuruluş bilgileri</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'organization') }}" class="form-grid">@csrf
        <label>Ad <input type="text" name="name" maxlength="120" value="{{ $org['name'] }}" @disabled(! $canSettings)></label>
        <label>E-posta (sitede görünür) <input type="email" name="email" value="{{ $org['email'] }}" @disabled(! $canSettings)></label>
        <label>Telefon (sitede görünür) <input type="text" name="phone" value="{{ $org['phone'] }}" @disabled(! $canSettings)></label>
        <label>Adres <textarea name="address" rows="2" maxlength="300" @disabled(! $canSettings)>{{ $org['address'] }}</textarea></label>
        <label>Diğer profil bağlantıları (her satıra bir https adresi; schema sameAs — sosyal ağlar için aşağıdaki “Sosyal medya profilleri” bölümünü kullanın) <textarea name="same_as" rows="3" @disabled(! $canSettings)>{{ implode("\n", $org['same_as']) }}</textarea></label>
        <label>KVKK aydınlatma metni adresi <input type="text" name="kvkk_url" value="{{ $org['kvkk_url'] }}" @disabled(! $canSettings)></label>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Marka</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'brand') }}" class="form-grid">@csrf
        <label>Site adı <input type="text" name="site_name" maxlength="80" required value="{{ $brand['site_name'] }}" @disabled(! $canSettings)>
            <span class="hint">Sayfa başlıklarında, og:site_name'de ve WebSite schema'sında kullanılır.</span></label>
        <label>Kısa marka adı <input type="text" name="short_name" maxlength="30" value="{{ $brand['short_name'] }}" @disabled(! $canSettings)>
            <span class="hint">Logo yokken header/footer'da yazı olarak ve ana ekrana eklenen kısayolda görünür; boşsa site adı.</span></label>
        <div class="form-row">
            <label>Header zemini <select name="header_background" @disabled(! $canSettings)><option value="light" @selected($brand['header_background'] === 'light')>Açık</option><option value="dark" @selected($brand['header_background'] === 'dark')>Koyu</option></select></label>
            <label>Footer zemini <select name="footer_background" @disabled(! $canSettings)><option value="light" @selected($brand['footer_background'] === 'light')>Açık</option><option value="dark" @selected($brand['footer_background'] === 'dark')>Koyu</option></select></label>
        </div>
        <p class="hint">Zemine uygun logo (açık / koyu zemin) otomatik seçilir; o zemin için logo yüklenmemişse yazı logosu gösterilir. Logo ve ikonlar aşağıdaki “Marka görselleri” bölümünden yüklenir.</p>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>SEO</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'seo') }}" class="form-grid">@csrf
        <label>Varsayılan SEO başlığı <span class="count" data-count-for="default_title" data-ideal="60"></span>
            <input type="text" name="default_title" maxlength="120" value="{{ $seo['default_title'] }}" @disabled(! $canSettings)>
            <span class="hint">Ana sayfada ve kendi başlığı olmayan sayfalarda kullanılır; boşsa site adı.</span></label>
        <label>Başlık eki (title suffix) <input type="text" name="title_suffix" maxlength="60" value="{{ $seo['title_suffix'] }}" placeholder="| {{ $brand['site_name'] }}" @disabled(! $canSettings)>
            <span class="hint">Sayfa başlığının sonuna eklenir, örn. “| {{ $brand['site_name'] }}”. Boşsa “| site adı”.</span></label>
        <label>Varsayılan meta açıklama <span class="count" data-count-for="default_description" data-ideal="155"></span>
            <textarea name="default_description" rows="3" maxlength="300" @disabled(! $canSettings)>{{ $seo['default_description'] }}</textarea>
            <span class="hint">Sayfanın kendi açıklaması ve özeti yoksa kullanılır.</span></label>
        <label>Canonical temel adres <input type="url" name="canonical_base" maxlength="200" value="{{ $seo['canonical_base'] }}" placeholder="{{ rtrim((string) config('app.url'), '/') }}" @disabled(! $canSettings)>
            <span class="hint">Yalnız şema ve alan adı (örn. https://pratiktools.com). Production'da https zorunlu. Boşsa uygulama adresi kullanılır. Canonical, og:url, sitemap ve robots.txt bu adresi kullanır.</span></label>
        <label>Kuruluş adı (Organization schema) <input type="text" name="organization_name" maxlength="120" value="{{ $seo['organization_name'] }}" placeholder="{{ $org['name'] }}" @disabled(! $canSettings)></label>
        <label>Kuruluş açıklaması (Organization schema) <textarea name="organization_description" rows="2" maxlength="500" @disabled(! $canSettings)>{{ $seo['organization_description'] }}</textarea></label>
        <div class="form-row">
            <label class="small"><input type="checkbox" name="robots_index" value="1" @checked($seo['robots_index']) @disabled(! $canSettings)> Arama motorları indekslesin (index)</label>
            <label class="small"><input type="checkbox" name="robots_follow" value="1" @checked($seo['robots_follow']) @disabled(! $canSettings)> Bağlantıları izlesin (follow)</label>
        </div>
        <p class="hint">İndeksleme kapatılırsa tüm sayfalar <code>noindex</code> olur (robots.txt taramaya izin vermeye devam eder ki arama motorları noindex'i görebilsin).</p>
        <div><span class="small muted">Google önizlemesi (ana sayfa)</span>
            <div class="serp" data-serp data-site="{{ $brand['site_name'] }}" data-title-inputs="default_title" data-suffix-input="title_suffix" data-desc-inputs="default_description" data-url-input="canonical_base" data-base="{{ $canonicalBase }}" data-path="/">
                <div class="serp-url"></div><div class="serp-title"></div><div class="serp-desc"></div></div></div>
        <p class="small">Varsayılan Open Graph görseli: @if ($brandMedia['og_image'])<span class="badge b-pass">Yüklü</span>@else<span class="badge b-warn">Yok</span>@endif — “Marka görselleri” bölümünden yönetilir.</p>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Sosyal medya profilleri</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'social') }}" class="form-grid">@csrf
        @foreach (\App\Site\SiteSettings::SOCIAL as $key => $net)
            <label>{{ $net['label'] }} <input type="url" name="{{ $key }}" maxlength="200" value="{{ $social[$key] }}" placeholder="https://{{ $net['hosts'][0] }}/…" @disabled(! $canSettings)></label>
        @endforeach
        <p class="hint">İsteğe bağlı. Yalnız dolu olanlar Organization schema'sının <code>sameAs</code> alanına girer; boş olanlar hiçbir yerde üretilmez. Adres https olmalı ve ilgili ağın alan adında olmalı.</p>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Analitik ve dönüşüm</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'analytics') }}" class="form-grid">@csrf
        <label>Google Analytics 4 (G-…) <input type="text" name="ga4_id" value="{{ $analytics['ga4_id'] }}" @disabled(! $canSettings)></label>
        <label>Google Ads (AW-…) <input type="text" name="google_ads_id" value="{{ $analytics['google_ads_id'] }}" @disabled(! $canSettings)></label>
        <label>Google Ads lead dönüşüm etiketi <input type="text" name="google_ads_lead_label" value="{{ $analytics['google_ads_lead_label'] }}" @disabled(! $canSettings)></label>
        <label>Meta Pixel ID <input type="text" name="meta_pixel_id" value="{{ $analytics['meta_pixel_id'] }}" @disabled(! $canSettings)></label>
        <p class="hint">Meta Conversions API (sunucu tarafı) gizli anahtar gerektirir; kasaya (vault) bağlanan ayrı bir adımla eklenecek.</p>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Site sahibi</h2>
    <p class="small">Web sitesini bu tenant'ın üyeleri yönetir; web sitesi talepleri bu tenant'ın lead'leri olur. Kaynak:
        <strong>{{ ['settings' => 'Site ayarları', 'environment' => 'Ortam değişkeni (ilk kurulum yedeği)', 'none' => 'Tanımlı değil'][$owner['source']] }}</strong></p>
    <form method="post" action="{{ route('panel.website.settings.save', 'owner') }}" class="form-grid">@csrf
        <label>Tenant (slug) <input type="text" name="tenant" value="{{ $owner['slug'] }}" maxlength="63" required @disabled(! $canSettings)></label>
        <label class="small"><input type="checkbox" name="confirm" value="1" @disabled(! $canSettings)> Başka bir tenant seçersem bu ekrana erişimimin kalkacağını ve yeni taleplerin o tenant'a gideceğini biliyorum.</label>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Görseller ve önbellek</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'media') }}" class="form-grid">@csrf
        <label>Görsel depolama diski <select name="disk" @disabled(! $canSettings)>
            @foreach ($disks as $name => $driver)<option value="{{ $name }}" @selected($mediaDisk === $name)>{{ $name }} ({{ $driver }})</option>@endforeach</select></label>
        <p class="hint">Diskin erişim bilgileri gizli değerdir ve Laravel Cloud ortamında kalır; burada yalnızca hangi diskin kullanılacağı seçilir. Production'da yalnız kalıcı (object storage) diskler kabul edilir.</p>
        @if (! \App\Site\SiteSettings::persistentDisk($mediaDisk))<p class="small"><span class="badge b-warn">Kalıcı değil</span> Production'da görsel yükleme bu diskle kapalıdır.</p>@endif
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
    @if ($canSettings)
    <form method="post" action="{{ route('panel.website.storage-check') }}" class="actions">@csrf
        <button class="btn" type="submit">Depolamayı test et</button>
        <span class="hint">Seçili diske benzersiz bir test dosyası yazar, okur, doğrular ve siler. Erişim bilgisi gösterilmez.</span>
    </form>
    @endif
    <form method="post" action="{{ route('panel.website.settings.save', 'delivery') }}" class="form-grid stack-top">@csrf
        <label>Sayfa önbellek süresi (saniye, 0–3600) <input type="number" name="cache_seconds" min="0" max="3600" value="{{ $cacheSeconds }}" @disabled(! $canSettings)></label>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
<div class="card">
    <h2>Yapay zeka tarayıcıları</h2>
    <form method="post" action="{{ route('panel.website.settings.save', 'crawlers') }}" class="form-grid">@csrf
        <p class="small">OAI-SearchBot (ChatGPT arama sonuçları) ve arama motorları herkese açık sayfaları her zaman tarayabilir. GPTBot, OpenAI'nin model eğitimi için kullandığı ayrı bir tarayıcıdır.</p>
        <label>GPTBot (model eğitimi) <select name="gptbot" @disabled(! $canSettings)><option value="disallow" @selected($crawlers['gptbot'] === 'disallow')>Engelle</option><option value="allow" @selected($crawlers['gptbot'] === 'allow')>İzin ver</option></select></label>
        @if ($canSettings)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@endif
    </form>
</div>
</div>
<div class="card stack-top">
    <h2>Marka görselleri</h2>
    <p class="small">Yüklemeler güvenli görsel hattından geçer: yalnız PNG, JPEG ve WebP kabul edilir (SVG kapalı), dosya içeriğinden tür doğrulanır, görsel yeniden kodlanır (meta veriler ve ekli içerik atılır). Değiştirilen görsel yeni bir adres alır; eski görsel önbellekten gösterilmez.</p>
    @if ($uploadsOff)<p class="small"><span class="badge b-bad">Yükleme kapalı</span> Production'da kalıcı object storage diski yok; yerel diske yükleme yapılmaz. Mevcut görseller ve yazı logosu kullanılmaya devam eder.</p>@endif
    <table class="table">
        <thead><tr><th>Görsel</th><th>Şu an</th><th></th></tr></thead>
        <tbody>
        @foreach (\App\Site\SiteBrand::IMAGES as $slot => $spec)
            @php($m = $brandMedia[$slot])
            <tr>
                <td><strong>{{ $spec['label'] }}</strong><br><span class="hint">{{ $spec['hint'] }}</span></td>
                <td>@if ($m)
                        @php($src = $m['kind'] === 'icon' ? end($m['png']) : \App\Site\SiteBrand::logoSrc($m))
                        <img src="{{ $src }}" alt="{{ $m['alt'] }}" height="40" loading="lazy" @class(['brand-preview', 'on-dark' => str_ends_with($slot, '_dark')])> <span class="small muted">{{ $m['width'] }}×{{ $m['height'] }}</span>
                    @else<span class="small muted">{{ str_contains($slot, 'logo') ? 'Yok — yazı logosu' : 'Yok' }}</span>@endif</td>
                <td>@if ($canSettings)
                    <form method="post" action="{{ route('panel.website.settings.brand-image', $slot) }}" enctype="multipart/form-data" class="form-row">@csrf
                        <input type="file" name="image" accept="image/png,image/jpeg,image/webp" required @disabled($uploadsOff)>
                        <input type="text" name="alt" maxlength="200" placeholder="Alternatif metin (isteğe bağlı)" @disabled($uploadsOff)>
                        <button class="btn btn-sm" type="submit" @disabled($uploadsOff)>Yükle</button>
                    </form>
                    @if ($m)<form method="post" action="{{ route('panel.website.settings.brand-image', $slot) }}">@csrf<input type="hidden" name="remove" value="1"><button class="btn btn-sm" type="submit">Kaldır</button></form>@endif
                @endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<style nonce="{{ $cspNonce }}">.brand-preview { max-width: 180px; object-fit: contain; vertical-align: middle; padding: 4px; border-radius: 6px; background: #fff; border: 1px solid var(--line, #e2e8f0); } .brand-preview.on-dark { background: #0b1220; }</style>
@include('panel.website._seo-preview')
@endsection
