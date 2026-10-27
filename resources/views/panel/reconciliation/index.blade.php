@extends('panel.layout')
@section('title', 'İYS mutabakatı')
@section('content')
@php($batchLabels = ['analyzing' => ['b-muted', 'Analiz ediliyor'], 'analyzed' => ['b-warn', 'Dry-run hazır'], 'unsupported' => ['b-bad', 'Format tanımlı değil'], 'failed' => ['b-bad', 'Hata'], 'applied' => ['b-pass', 'Uygulandı']])
<div class="page-head">
    <div><h1>İYS › Mutabakat</h1><p>İYS günlük değişiklik raporları: vatandaşın İYS kanallarından yaptığı ONAY/RET değişiklikleri. Önce dry-run (izin durumu değişmez), sonra uygulama. RET her zaman ONAY'dan önce gelir; yerel engeller (abonelikten çıkma, bastırma) rapor tarafından kaldırılmaz.</p></div>
</div>
<div class="grid g2">
    <div class="card"><h2>İzin kaynağı durumu</h2>@include('panel.reconciliation._iys', ['state' => $state])</div>
    <div class="card">
        <h2>Rapor yükle</h2>
        @if ($canReconcile)
            <form method="post" action="{{ route('panel.reconciliation.store') }}" enctype="multipart/form-data" class="stack">
                @csrf
                <label>İYS değişiklik raporu <input type="file" name="report" required></label>
                <label>Dönem başlangıcı <input type="date" name="period_start"></label>
                <label>Dönem bitişi <input type="date" name="period_end" required></label>
                <button class="btn btn-primary" type="submit">Yükle ve analiz et (dry-run)</button>
                <p class="small muted">En fazla {{ $maxMb }} MB. Dosya analizden sonra saklanmaz; satırlarda adres değil, maskeli gösterim ve anahtarlı özet tutulur.</p>
                @unless ($state['reconciliation']['format_configured'])<p class="small"><span class="badge b-warn">Format tanımlı değil</span> Resmî İYS rapor formatı/örneği gelene kadar yüklenen dosyalar işlenmez.</p>@endunless
            </form>
        @else
            <p class="muted">Rapor yükleme yetkiniz yok.</p>
        @endif
    </div>
</div>
<div class="card stack-top table-wrap">
    <h2>Raporlar</h2>
    <table class="table">
        <thead><tr><th>Dosya</th><th>Dönem</th><th>Durum</th><th>ONAY</th><th>RET</th><th>Çakışma</th><th>Geçersiz</th><th>Yüklendi</th></tr></thead>
        <tbody>
        @forelse ($batches as $b)
            @php($c = (array) json_decode((string) ($b->status === 'applied' ? $b->applied_counts : $b->dry_run_counts), true))
            @php([$cls, $label] = $batchLabels[$b->status] ?? ['b-muted', $b->status])
            <tr>
                <td><a href="{{ route('panel.reconciliation.show', $b->id) }}">{{ $b->original_filename }}</a></td>
                <td class="small">{{ $b->period_start ? \Illuminate\Support\Carbon::parse($b->period_start)->format('d.m.Y').' – ' : '' }}{{ \Illuminate\Support\Carbon::parse($b->period_end)->format('d.m.Y') }}</td>
                <td><span class="badge {{ $cls }}">{{ $label }}</span></td>
                <td>{{ $c['onay'] ?? '—' }}</td><td>{{ $c['ret'] ?? '—' }}</td><td>{{ $c['conflict'] ?? '—' }}</td><td>{{ $c['invalid'] ?? '—' }}</td>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($b->created_at)->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Henüz rapor yüklenmedi.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
