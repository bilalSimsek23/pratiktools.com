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
        <label>Profil bağlantıları (her satıra bir https adresi; schema sameAs) <textarea name="same_as" rows="3" @disabled(! $canSettings)>{{ implode("\n", $org['same_as']) }}</textarea></label>
        <label>KVKK aydınlatma metni adresi <input type="text" name="kvkk_url" value="{{ $org['kvkk_url'] }}" @disabled(! $canSettings)></label>
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
@endsection
