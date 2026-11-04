@extends('admin.layout')
@section('title', 'Sistem Durumu')
@section('content')
<div class="page-head"><div><h1>Sistem Durumu <span class="badge {{ $health['status'] === 'ok' ? 'b-ok' : 'b-bad' }}">{{ $health['status'] }}</span></h1>
    <p class="lead">Altyapı bileşenleri. Gizli değer gösterilmez.</p></div></div>
<div class="card table-wrap">
<table>
    <thead><tr><th>Bileşen</th><th>Durum</th><th>Ayrıntı</th></tr></thead>
    <tbody>
    @foreach ($health['checks'] as $name => $check)
        <tr>
            <td>{{ $name }}</td>
            <td class="{{ $check['status'] === 'ok' ? 'ok' : 'fail' }}">{{ $check['status'] }}</td>
            <td class="muted">
                @isset($check['latency_ms']) {{ $check['latency_ms'] }} ms @endisset
                @isset($check['horizon']) Horizon: {{ $check['horizon'] }} @endisset
                @isset($check['backlog'])
                    · backlog @foreach ($check['backlog'] as $queue => $size) {{ $queue }}={{ $size }} @endforeach
                @endisset
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endsection
