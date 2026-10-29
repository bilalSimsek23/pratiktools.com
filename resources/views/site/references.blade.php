@extends('site.layout')
@section('content')
<section class="page-hero"><div class="wrap narrow-hero">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    <h1>Referanslar</h1>
    <p class="lead">{{ $intro }}</p>
</div></section>
<div class="wrap section">
@if ($references === [])
    <p class="muted">Referans listesi hazırlanıyor. Benzer bir ihtiyacınız varsa <a href="/iletisim">bize yazın</a>.</p>
@else
    <div class="ref-list">
    @foreach ($references as $ref)
        <article class="ref-row" id="{{ $ref['slug'] }}">
            <div class="ref-mark">@include('site.partials.ref-logo', ['ref' => $ref])</div>
            <div>
                <h2 class="ref-name">@if ($ref['has_detail'])<a href="{{ $ref['url'] }}">{{ $ref['name'] }}</a>@else{{ $ref['name'] }}@endif</h2>
                <p class="ref-short">{{ $ref['short_description'] }}@if ($ref['industry']) · <span class="muted">{{ $ref['industry'] }}</span>@endif</p>
                @if ($ref['body'] !== '')<p>{{ \App\Site\SiteMarkdown::plain($ref['body'], 320) }}</p>@endif
                @if ($ref['capabilities'] !== [])<ul class="chips">@foreach ($ref['capabilities'] as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
                <p class="small">
                    @foreach ($ref['projects'] as $p)<a href="{{ $p['url'] }}">{{ $p['title'] }}</a> · @endforeach
                    @if ($ref['website_url'])<a href="{{ $ref['website_url'] }}" rel="noopener nofollow" target="_blank">Web sitesi</a>@endif
                </p>
            </div>
        </article>
    @endforeach
    </div>
@endif
</div>
<section class="section wrap cta-block" aria-labelledby="cta">
    <div><h2 id="cta">Projenizi anlatın</h2><p>Kullandığınız sistemleri ve çözmek istediğiniz sorunu kısaca yazın.</p></div>
    @include('site.partials.inquiry', ['kind' => 'proje', 'button' => 'Gönder'])
</section>
@endsection
