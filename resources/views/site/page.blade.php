@extends('site.layout')
@section('content')
<section class="page-hero"><div class="wrap narrow-hero">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    <h1>{{ ($item['data']['hero_title'] ?? '') !== '' ? $item['data']['hero_title'] : $item['title'] }}</h1>
    <p class="lead">{{ ($item['data']['hero_text'] ?? '') !== '' ? $item['data']['hero_text'] : $item['excerpt'] }}</p>
</div></section>
<div class="wrap section"><div class="prose">{!! $html !!}</div></div>
<section class="section wrap cta-block" aria-labelledby="cta">
    <div><h2 id="cta">Birlikte çalışalım</h2><p>Projenizi kısaca anlatın; size dönüş yapalım.</p></div>
    @include('site.partials.inquiry', ['kind' => 'gorusme', 'button' => 'Görüşme talep et'])
</section>
@endsection
