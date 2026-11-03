@extends('site.layout')
@section('content')
<section class="page-hero"><div class="wrap">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    <h1>{{ $category ? $category['name'].' — ' : '' }}{{ $title }}</h1>
    <p class="lead">{{ $intro }}</p>
    @if ($type->value === 'article' && $categories !== [])
        <nav class="chips" aria-label="Kategoriler"><a href="/blog" @class(['on' => ! $category])>Tümü</a>@foreach ($categories as $k)<a href="/blog?kategori={{ $k['slug'] }}" @class(['on' => ($category['slug'] ?? '') === $k['slug']])>{{ $k['name'] }}</a>@endforeach</nav>
    @endif
</div></section>
<div class="wrap section">
@if ($type->value === 'service')
    @foreach ($categories as $k)
        @php($inCat = array_values(array_filter($items, fn ($i) => ($i['category']['id'] ?? null) === $k['id'])))
        <section class="cat" id="{{ $k['slug'] }}" aria-labelledby="c-{{ $k['slug'] }}">
            <h2 id="c-{{ $k['slug'] }}">{{ $k['name'] }}</h2>
            @if ($k['description'])<p class="muted">{{ $k['description'] }}</p>@endif
            {{-- The section heading already names the category; cards do not repeat it. --}}
            <div class="cards">@foreach ($inCat as $c)@include('site.partials.card', ['c' => $c, 'eyebrow' => false])@endforeach</div>
        </section>
    @endforeach
@elseif ($items === [])
    <p class="muted">{{ $type->value === 'project' ? 'Proje örnekleri hazırlanıyor. Benzer bir ihtiyacınız varsa ' : 'Henüz içerik yok. ' }}<a href="/iletisim">bize yazın</a>.</p>
@else
    <div class="cards">@foreach ($items as $c)@include('site.partials.card', ['c' => $c])@endforeach</div>
@endif
</div>
@endsection
