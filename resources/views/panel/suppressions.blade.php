@extends('panel.layout')
@section('title', 'Bastırma listesi')
@section('content')
@php($sources = ['UNSUBSCRIBE' => 'Abonelikten çıkma', 'HARD_BOUNCE' => 'Kalıcı geri dönme', 'COMPLAINT' => 'Şikâyet', 'MANUAL' => 'Manuel', 'IYS_RET' => 'İYS ret'])
<div class="page-head">
    <div><h1>Bastırma listesi</h1><p>Bu adreslere pazarlama iletisi gönderilmez. Adresler maskelenmiş gösterilir.</p></div>
</div>
<div class="grid g2">
    <div class="card table-wrap">
        <h2>Kaynağa göre (aktif)</h2>
        <table class="table">
            <thead><tr><th>Kaynak</th><th>Kanal</th><th>Adet</th></tr></thead>
            <tbody>
            @forelse ($bySource as $r)
                <tr><td>{{ $sources[$r->source] ?? $r->source }}</td><td>{{ $r->channel === 'ALL' ? 'Tümü' : $r->channel }}</td><td>{{ number_format($r->n) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">Bastırılmış adres yok.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="small muted">Kaldırılmış kayıt: {{ number_format($lifted) }}</p>
    </div>
    <div class="card table-wrap">
        <h2>Son eklenenler</h2>
        <table class="table">
            <thead><tr><th>Adres</th><th>Kaynak</th><th>Tarih</th></tr></thead>
            <tbody>
            @forelse ($recent as $r)
                <tr><td><code>{{ $r->address }}</code></td><td>{{ $sources[$r->source] ?? $r->source }}</td><td class="small muted">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d.m.Y H:i') }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">Kayıt yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
