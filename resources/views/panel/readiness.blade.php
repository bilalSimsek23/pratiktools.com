@extends('panel.layout')
@section('title', 'Hazırlık')
@section('content')
<div class="page-head">
    <div><h1>Hazırlık</h1><p>Canlı gönderimin önündeki harici gereksinimler. Bu maddeler gerçek verilerden hesaplanır; panelden atlanamaz.</p></div>
</div>
<div class="card">
    <ul class="readiness">
        @foreach ($blockers as $b)
            <li>
                @php([$ic, $sym, $bc, $bl] = ['PASS' => ['pass', '✓', 'b-pass', 'Tamam'], 'WAIT' => ['warn', '…', 'b-warn', 'Bekliyor']][$b['status']] ?? ['bad', '✕', 'b-bad', 'Engelli'])
                <span class="ricon {{ $ic }}">{{ $sym }}</span>
                <div><div class="title">{{ \Illuminate\Support\Str::of($b['blocker'])->replace('_', ' ')->title() }}</div>
                    <div class="why">{{ $b['detail'] }}</div>
                    @if ($b['status'] !== 'PASS' && $b['action'])<div class="todo">→ {{ $b['action'] }}</div>@endif</div>
                <span class="badge {{ $bc }}">{{ $bl }}</span>
            </li>
        @endforeach
    </ul>
</div>
<div class="card stack-top">
    <h2>Kampanya hazırlığı</h2>
    <p class="small muted">Her kampanyanın ayrıntılı hazırlık listesi (içerik, kitle, gönderici, DKIM, MAIL FROM, İYS…) kampanya sayfasındadır.</p>
    <ul class="list-plain">
        @forelse ($campaigns as $c)
            <li class="split"><a href="{{ route('panel.campaigns.show', $c->id) }}">{{ $c->name }}</a>@include('panel.campaigns._status', ['status' => $c->status])</li>
        @empty
            <li class="muted">Aktif kampanya yok.</li>
        @endforelse
    </ul>
</div>
@endsection
