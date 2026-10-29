@extends('panel.layout')
@section('title', 'SEO ve ölçüm')
@section('content')
@php($eventLabels = ['page_view' => 'Sayfa görüntüleme', 'service_view' => 'Hizmet görüntüleme', 'solution_view' => 'Çözüm görüntüleme', 'project_view' => 'Proje görüntüleme', 'article_view' => 'Yazı görüntüleme', 'lead_form_open' => 'Form açma', 'lead_submit' => 'Talep gönderimi', 'contact_click' => 'İletişim tıklaması'])
<div class="page-head"><div><h1>Web Sitesi · SEO ve ölçüm</h1><p>Site haritası: <a href="/sitemap.xml" target="_blank" rel="noopener">/sitemap.xml</a> · Robots: <a href="/robots.txt" target="_blank" rel="noopener">/robots.txt</a>. Taslak ve arşiv sayfalar site haritasında yer almaz ve açılmaz.</p></div></div>
<div class="grid g2">
    <div class="card"><h2>Son 30 gün (çerezsiz, kişisel veri içermeyen sayaçlar)</h2>
        <ul class="list-plain small">@foreach ($eventLabels as $k => $l)<li class="split"><span>{{ $l }}</span><strong>{{ number_format((int) ($events[$k] ?? 0)) }}</strong></li>@endforeach</ul></div>
    <div class="card"><h2>En çok görüntülenen sayfalar</h2>
        <ul class="list-plain small">@forelse ($topPaths as $p)<li class="split"><code>{{ $p->path }}</code><strong>{{ number_format((int) $p->n) }}</strong></li>@empty<li class="muted">Veri yok.</li>@endforelse</ul></div>
    <div class="card"><h2>Tarayıcı erişimi</h2>
        <ul class="list-plain small">
            <li class="split"><span>Arama motorları</span><span class="badge b-pass">İzinli</span></li>
            <li class="split"><span>OAI-SearchBot (ChatGPT arama)</span><span class="badge b-pass">İzinli</span></li>
            <li class="split"><span>GPTBot (model eğitimi)</span><span class="badge {{ $crawlers['gptbot'] === 'allow' ? 'b-pass' : 'b-muted' }}">{{ $crawlers['gptbot'] === 'allow' ? 'İzinli' : 'Engelli' }}</span></li>
            <li class="split"><span>/app, API, teknik uç noktalar</span><span class="badge b-muted">Engelli + noindex</span></li>
        </ul><a class="small" href="{{ route('panel.website.settings') }}">Ayarları değiştir →</a></div>
    <div class="card"><h2>İçerik durumu</h2>
        <ul class="list-plain small">@foreach ($statusCounts as $s)<li class="split"><span>{{ \App\Site\ContentType::from($s->type)->plural() }} · {{ \App\Site\ContentStatus::from($s->status)->label() }}</span><strong>{{ $s->n }}</strong></li>@endforeach</ul></div>
</div>
<div class="card stack-top table-wrap">
    <h2>Yayındaki içerikte iyileştirme önerileri</h2>
    <table class="table"><thead><tr><th>İçerik</th><th>Öneri</th></tr></thead><tbody>
        @forelse ($issues as $x)
            <tr><td><a href="{{ route('panel.website.edit', $x['r']->id) }}">{{ $x['r']->title }}</a><br><code class="small">{{ $x['url'] }}</code></td><td class="small">{{ implode(' · ', $x['issues']) }}</td></tr>
        @empty
            <tr><td colspan="2" class="muted">Öneri yok.</td></tr>
        @endforelse
    </tbody></table>
</div>
@endsection
