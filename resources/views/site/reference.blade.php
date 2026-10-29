@extends('site.layout')
@section('content')
<section class="page-hero"><div class="wrap narrow-hero">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    <div class="ref-hero-mark">@include('site.partials.ref-logo', ['ref' => $ref])</div>
    <h1>{{ $ref['name'] }}</h1>
    <p class="lead">{{ $ref['short_description'] }}@if ($ref['industry']) · {{ $ref['industry'] }}@endif</p>
</div></section>
<div class="wrap content-grid section">
    <div class="prose">
        <h2>Neler yaptık</h2>
        {!! $html !!}
        @if ($ref['projects'] !== [])
            <h2>Projeler</h2>
            <div class="cards">@foreach ($ref['projects'] as $p)<article class="card"><h3 class="card-title"><a href="{{ $p['url'] }}">{{ $p['title'] }}</a></h3><p>{{ $p['excerpt'] }}</p><a class="more" href="{{ $p['url'] }}">Projeyi İncele →</a></article>@endforeach</div>
        @endif
    </div>
    <aside class="side">
        @if ($ref['capabilities'] !== [])<div class="side-box"><h2 class="side-title">Yetkinlikler</h2><ul class="chips">@foreach ($ref['capabilities'] as $c)<li>{{ $c }}</li>@endforeach</ul></div>@endif
        @if ($ref['services'] !== [])<div class="side-box"><h2 class="side-title">İlgili hizmetler</h2><ul class="link-list">@foreach ($ref['services'] as $s)<li><a href="{{ $s['url'] }}">{{ $s['title'] }}</a></li>@endforeach</ul></div>@endif
        @if ($ref['website_url'])<div class="side-box"><a href="{{ $ref['website_url'] }}" rel="noopener nofollow" target="_blank">Web sitesini ziyaret edin ↗</a></div>@endif
    </aside>
</div>
@endsection
