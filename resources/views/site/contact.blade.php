@extends('site.layout')
@section('content')
<section class="page-hero"><div class="wrap narrow-hero">
    @include('site.partials.crumbs', ['crumbs' => $crumbs])
    <h1>{{ ($item['data']['hero_title'] ?? '') !== '' ? $item['data']['hero_title'] : $item['title'] }}</h1>
    <p class="lead">{{ ($item['data']['hero_text'] ?? '') !== '' ? $item['data']['hero_text'] : $item['excerpt'] }}</p>
</div></section>
<div class="wrap section content-grid">
    <div>@include('site.partials.inquiry', ['kind' => request('tur') && array_key_exists(request('tur'), \App\Site\SiteInquiries::KINDS) ? request('tur') : ($old['kind'] ?? 'teklif'), 'button' => 'Gönder'])</div>
    <aside class="side">
        <div class="side-box"><h2 class="side-title">Ne yazmalısınız?</h2>
            <ul class="link-list small"><li>Kullandığınız sistemler (ör. Ticimax, Nebim V3, pazaryerleri)</li><li>Çözmek istediğiniz sorun veya otomatikleştirmek istediğiniz iş</li><li>Varsa zaman planınız</li></ul></div>
        @if ($org['email'] || $org['phone'])
            <div class="side-box"><h2 class="side-title">Doğrudan ulaşın</h2>
                @if ($org['email'])<p><a href="mailto:{{ $org['email'] }}" data-track="contact_click">{{ $org['email'] }}</a></p>@endif
                @if ($org['phone'])<p><a href="tel:{{ preg_replace('/[^0-9+]/', '', $org['phone']) }}" data-track="contact_click">{{ $org['phone'] }}</a></p>@endif</div>
        @endif
        @if ($html !== '')<div class="prose small">{!! $html !!}</div>@endif
    </aside>
</div>
@endsection
