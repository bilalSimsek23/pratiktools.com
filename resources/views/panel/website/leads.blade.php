@extends('panel.layout')
@section('title', 'Web sitesi talepleri')
@section('content')
@php
    $kinds = \App\Site\SiteInquiries::KINDS;
    $statusLabels = ['new' => 'Yeni', 'contacted' => 'İletişime geçildi', 'closed' => 'Kapandı'];
    $sourceLabels = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'ai_search' => 'AI arama', 'campaign' => 'Kampanya (UTM)', 'organic' => 'Organik arama', 'referral' => 'Yönlendiren site', 'direct' => 'Doğrudan'];
@endphp
<div class="page-head"><div><h1>Web Sitesi · Leadler</h1><p>Web sitesi formlarından gelen talepler. Kişisel veridir; yalnızca yanıt vermek için kullanın.</p></div></div>
<div class="grid g2">
    <div class="card"><h2>Durum</h2><div class="kpis">
        @foreach ($statusLabels as $k => $l)<div class="kpi"><div class="label"><a href="?durum={{ $k }}">{{ $l }}</a></div><div class="value">{{ (int) ($counts[$k] ?? 0) }}</div></div>@endforeach
    </div>@if ($status)<a class="small" href="{{ route('panel.website.leads') }}">Tümünü göster</a>@endif</div>
    <div class="card"><h2>Kaynak (son 30 gün, son temas)</h2>
        <ul class="list-plain small">@forelse ($sources as $k => $n)<li class="split"><span>{{ $sourceLabels[$k] ?? ($k ?: 'Bilinmiyor') }}</span><strong>{{ $n }}</strong></li>@empty<li class="muted">Henüz talep yok.</li>@endforelse</ul></div>
</div>
<div class="card stack-top table-wrap">
    <table class="table">
        <thead><tr><th>Tarih</th><th>Kişi</th><th>Talep</th><th>Mesaj</th><th>Kaynak</th><th>Durum</th></tr></thead>
        <tbody>
        @forelse ($inquiries as $i)
            @php($a = json_decode((string) $i->attribution, true) ?: [])
            <tr>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($i->created_at)->format('d.m.Y H:i') }}</td>
                <td><strong>{{ $i->name }}</strong><br><span class="small">{{ $i->email }}@if ($i->phone) · {{ $i->phone }}@endif</span>@if ($i->company)<br><span class="small muted">{{ $i->company }}</span>@endif</td>
                <td class="small">{{ $kinds[$i->kind] ?? $i->kind }}@if ($i->interest)<br><span class="muted">{{ $i->interest }}</span>@endif</td>
                <td class="small">{{ \Illuminate\Support\Str::limit($i->message, 400) }}</td>
                <td class="small">{{ $sourceLabels[$a['last']['class'] ?? ''] ?? '—' }}@if (isset($a['last']['campaign']))<br><span class="muted">{{ $a['last']['campaign'] }}</span>@endif
                    @if (isset($a['last']['landing']))<br><code>{{ $a['last']['landing'] }}</code>@endif
                    @if (isset($a['first']['class']) && ($a['first']['class'] ?? null) !== ($a['last']['class'] ?? null))<br><span class="muted">ilk: {{ $sourceLabels[$a['first']['class']] ?? $a['first']['class'] }}</span>@endif</td>
                <td><form method="post" action="{{ route('panel.website.leads.status', $i->id) }}">@csrf
                    <select name="status">@foreach ($statusLabels as $k => $l)<option value="{{ $k }}" @selected($i->status === $k)>{{ $l }}</option>@endforeach</select>
                    <button class="btn btn-sm" type="submit">Kaydet</button></form></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Talep yok.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
