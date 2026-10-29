@extends('site.layout')
@section('content')
@php($d = $page['data'])
<section class="hero">
    <div class="wrap hero-grid">
        <div>
            <p class="eyebrow">Entegrasyon · Otomasyon · Yapay Zeka</p>
            <h1>{{ $d['hero_title'] ?? $page['title'] }}</h1>
            <p class="lead">{{ $d['hero_text'] ?? $page['excerpt'] }}</p>
            <div class="actions"><a class="btn btn-primary" href="/iletisim">Projenizi anlatın</a><a class="btn" href="/hizmetler">Hizmetleri inceleyin</a></div>
        </div>
        <div class="hero-panel" aria-hidden="true">
            <div class="flow"><span>Ticimax</span><i></i><span>Nebim V3</span></div>
            <div class="flow"><span>Pazaryerleri</span><i></i><span>Raporlama</span></div>
            <div class="flow"><span>Müşteri verisi</span><i></i><span>E-posta · Push</span></div>
            <div class="flow"><span>Operasyon</span><i></i><span>Yapay zeka</span></div>
        </div>
    </div>
</section>

<section class="section wrap" aria-labelledby="yetkinlikler">
    <h2 id="yetkinlikler">Neler yapıyoruz</h2>
    @if (! empty($d['capabilities_intro']))<p class="section-lead">{{ $d['capabilities_intro'] }}</p>@endif
    <div class="cap-grid">
        @foreach ($categories as $c)
            <a class="cap" href="{{ count($c['services']) === 1 ? $c['services'][0]['url'] : '/hizmetler#'.$c['slug'] }}">
                <span class="cap-name">{{ $c['name'] }}</span>
                @php($sub = array_values(array_filter(array_map(fn ($s) => $s['title'], array_slice($c['services'], 0, 2)), fn ($t) => mb_strtolower($t) !== mb_strtolower($c['name']))))
                @if ($sub !== [])<span class="small muted">{{ implode(' · ', $sub) }}</span>@endif
            </a>
        @endforeach
    </div>
</section>

@if ($solutions !== [])
<section class="section band" aria-labelledby="cozumler">
    <div class="wrap">
        <h2 id="cozumler">Öne çıkan çözümler</h2>
        <div class="cards">@foreach ($solutions as $c)@include('site.partials.card', ['c' => $c])@endforeach</div>
    </div>
</section>
@endif

@if ($references !== [])
<section class="section wrap" aria-labelledby="markalar">
    <div class="section-head"><h2 id="markalar">Birlikte çalıştığımız markalar</h2><a href="/referanslar">Tüm referanslar →</a></div>
    <ul class="ref-strip">@foreach ($references as $ref)<li>@if ($ref['has_detail'])<a href="{{ $ref['url'] }}">@include('site.partials.ref-logo', ['ref' => $ref])</a>@else @include('site.partials.ref-logo', ['ref' => $ref])@endif</li>@endforeach</ul>
    <h3 class="sub-head">Seçili çalışmalar</h3>
    <div class="cards">@foreach (array_slice($references, 0, 6) as $ref)@include('site.partials.ref-card', ['ref' => $ref])@endforeach</div>
</section>
@elseif ($projects !== [])
<section class="section wrap" aria-labelledby="projeler">
    <div class="section-head"><h2 id="projeler">Seçili projeler</h2><a href="/projeler">Tüm projeler →</a></div>
    <div class="cards">@foreach ($projects as $c)@include('site.partials.card', ['c' => $c])@endforeach</div>
</section>
@endif

@if (! empty($d['integrations']))
<section class="section wrap" aria-labelledby="platformlar">
    <h2 id="platformlar">Çalıştığımız platformlar ve teknolojiler</h2>
    <ul class="chips">@foreach ($d['integrations'] as $i)<li>{{ $i }}</li>@endforeach</ul>
    <p class="small muted">Markalar sahiplerine aittir; burada yalnızca entegrasyon geliştirdiğimiz veya kullandığımız platformlar olarak anılır.</p>
</section>
@endif

@if (! empty($d['how_we_work']))
<section class="section band" aria-labelledby="nasil">
    <div class="wrap">
        <h2 id="nasil">Nasıl çalışıyoruz</h2>
        <ol class="steps">@foreach ($d['how_we_work'] as $s)<li><h3>{{ $s['title'] }}</h3><p>{{ $s['text'] }}</p></li>@endforeach</ol>
    </div>
</section>
@endif

@if ($articles !== [])
<section class="section wrap" aria-labelledby="blog">
    <div class="section-head"><h2 id="blog">Blogdan</h2><a href="/blog">Tüm yazılar →</a></div>
    <div class="cards">@foreach ($articles as $c)@include('site.partials.card', ['c' => $c])@endforeach</div>
</section>
@endif

<section class="section wrap cta-block" aria-labelledby="cta">
    <div>
        <h2 id="cta">{{ $d['cta_title'] ?? 'Projenizi anlatın' }}</h2>
        <p>{{ $d['cta_text'] ?? '' }}</p>
    </div>
    @include('site.partials.inquiry', ['kind' => 'proje', 'button' => 'Gönder'])
</section>
@endsection
