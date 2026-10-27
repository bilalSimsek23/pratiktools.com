@extends('panel.layout')
@section('title', 'Bastırma listesi')
@section('content')
@php($sources = ['UNSUBSCRIBE' => 'Abonelikten çıkma', 'HARD_BOUNCE' => 'Kalıcı geri dönme', 'COMPLAINT' => 'Şikâyet', 'MANUAL' => 'Manuel', 'IYS_RET' => 'İYS ret'])
<div class="page-head">
    <div><h1>Bastırma listesi</h1><p>Bu adreslere pazarlama iletisi gönderilmez. Adresler maskelenmiş gösterilir.</p></div>
</div>
@php($retLabel = fn (?string $s) => match ($s) { null => '—', 'confirmed' => 'İYS RET onaylandı', 'failed' => 'Yerel RET · İYS yazımı başarısız', default => 'Yerel RET · İYS mutabakatı bekleniyor' })
<div class="card">
    <h2>Abonelikten çıkma → İYS RET</h2>
    <div class="kpis">
        <div class="kpi"><div class="label">Yerel RET (anında engelli)</div><div class="value">{{ number_format(array_sum($ret)) }}</div></div>
        <div class="kpi"><div class="label">İYS mutabakatı bekleniyor</div><div class="value">{{ number_format($ret['pending'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">İYS RET onaylandı</div><div class="value">{{ number_format($ret['confirmed'] ?? 0) }}</div></div>
        <div class="kpi"><div class="label">İYS/ViA yazımı başarısız</div><div class="value">{{ number_format($ret['failed'] ?? 0) }}</div></div>
    </div>
    <p class="small muted">Yerel engel İYS/ViA cevabını beklemez ve İYS yazımı başarısız olsa da kalkmaz. İYS'ye yazma bu aşamada kapalıdır; onay, İYS değişiklik raporu veya ViA Ret sonucuyla gelir.</p>
</div>
<div class="grid g2 stack-top">
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
            <thead><tr><th>Adres</th><th>Kaynak</th><th>İYS RET</th><th>Tarih</th></tr></thead>
            <tbody>
            @forelse ($recent as $r)
                <tr><td><code>{{ $r->address }}</code></td><td>{{ $sources[$r->source] ?? $r->source }}</td><td class="small">{{ $retLabel($r->ret_state) }}</td><td class="small muted">{{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d.m.Y H:i') }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">Kayıt yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
