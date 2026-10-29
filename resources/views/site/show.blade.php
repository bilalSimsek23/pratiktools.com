@extends('site.layout')
@section('content')
@php
    $d = $item['data'];
    $list = fn (string $k) => array_values(array_filter((array) ($d[$k] ?? [])));
    $named = $type->value === 'project' && ($d['client_named'] ?? false) && isset($d['client_approval']) && ($d['client_name'] ?? '') !== '';
@endphp
<article>
<section class="page-hero"><div class="wrap narrow-hero">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    @if ($item['category'])<p class="eyebrow">{{ $item['category']['name'] }}</p>@endif
    <h1>{{ ($d['hero_title'] ?? '') !== '' ? $d['hero_title'] : $item['title'] }}</h1>
    <p class="lead">{{ ($d['hero_text'] ?? '') !== '' ? $d['hero_text'] : $item['excerpt'] }}</p>
    @if ($type->value === 'article')
        <p class="small muted">@if (($d['author'] ?? '') !== ''){{ $d['author'] }} · @endif<time datetime="{{ \Illuminate\Support\Carbon::parse($item['published_at'])->toDateString() }}">{{ \Illuminate\Support\Carbon::parse($item['published_at'])->translatedFormat('j F Y') }}</time></p>
    @elseif ($type->value === 'project')
        <p class="small muted">{{ $named ? $d['client_name'] : ($d['client_display'] ?? '') }}@if (($d['industry'] ?? '') !== '') · {{ $d['industry'] }}@endif</p>
    @else
        <div class="actions"><a class="btn btn-primary" href="#talep">{{ ($d['cta_label'] ?? '') !== '' ? $d['cta_label'] : 'Teklif alın' }}</a></div>
    @endif
</div></section>

<div class="wrap content-grid section">
    <div class="prose">
        @include('site.partials.picture', ['image' => $item['image'], 'eager' => true])
        @foreach ([['problem', 'Problem'], ['challenge', 'Problem'], ['approach', 'Yaklaşımımız'], ['solution_text', 'Çözüm']] as [$k, $h])
            @if (($d[$k] ?? '') !== '')<h2>{{ $h }}</h2><p>{!! nl2br(e($d[$k])) !!}</p>@endif
        @endforeach
        @foreach ([['benefits', 'Faydalar'], ['features', $type->value === 'solution' ? 'Neler sunuyoruz' : 'Kapsam'], ['use_cases', 'Örnek kullanım senaryoları'], ['examples', 'Örnekler']] as [$k, $h])
            @if ($list($k) !== [])<h2>{{ $h }}</h2><ul>@foreach ($list($k) as $x)<li>{{ $x }}</li>@endforeach</ul>@endif
        @endforeach
        @if (($d['outcome'] ?? '') !== '')<h2>Sonuç</h2><p>{!! nl2br(e($d['outcome'])) !!}</p>@endif
        {!! $html !!}
        @include('site.partials.faq', ['faq' => in_array($type->value, ['service', 'solution'], true) ? (array) ($d['faq'] ?? []) : []])
    </div>
    <aside class="side">
        @foreach ([['systems', 'Entegre olunan sistemler'], ['technologies', 'Teknolojiler'], ['integrations', 'Entegrasyonlar']] as [$k, $h])
            @if ($list($k) !== [])<div class="side-box"><h2 class="side-title">{{ $h }}</h2><ul class="chips">@foreach ($list($k) as $x)<li>{{ $x }}</li>@endforeach</ul></div>@endif
        @endforeach
        @php($links = [...$item['related'], ...$item['referenced_by']])
        @if ($links !== [])
            <div class="side-box"><h2 class="side-title">İlgili içerikler</h2><ul class="link-list">@foreach ($links as $r)<li><a href="{{ $r['url'] }}">{{ $r['title'] }}</a></li>@endforeach</ul></div>
        @endif
    </aside>
</div>
</article>

@if ($otherProjects !== [])
<section class="section wrap"><h2>Diğer projeler</h2><div class="cards">@foreach ($otherProjects as $c)@include('site.partials.card', ['c' => $c])@endforeach</div></section>
@endif

<section class="section wrap cta-block" id="talep" aria-labelledby="cta">
    <div>
        <h2 id="cta">{{ ($d['cta_label'] ?? '') !== '' ? $d['cta_label'] : 'Benzer bir ihtiyacınız mı var?' }}</h2>
        <p>{{ ($d['cta_text'] ?? '') !== '' ? $d['cta_text'] : 'Kullandığınız sistemleri ve çözmek istediğiniz sorunu kısaca yazın.' }}</p>
    </div>
    @include('site.partials.inquiry', ['kind' => $d['form_kind'] ?? ($type->value === 'project' ? 'proje' : 'teklif'),
        'interest' => in_array($type->value, ['service', 'solution'], true) ? $type->value.':'.$item['slug'] : '', 'button' => \App\Site\SiteInquiries::KINDS[$d['form_kind'] ?? 'teklif'] ?? 'Gönder'])
</section>
@endsection
