@php($map = ['draft' => ['b-muted', 'Taslak'], 'awaiting_approval' => ['b-warn', 'Onay bekliyor'], 'approved' => ['b-info', 'Onaylı'], 'queued' => ['b-info', 'Kuyrukta'],
    'running' => ['b-info', 'Çalışıyor'], 'completed' => ['b-pass', 'Tamamlandı'], 'failed' => ['b-bad', 'Hata'], 'cancelled' => ['b-muted', 'İptal'], 'blocked' => ['b-bad', 'Engellendi']])
<span class="badge {{ $map[$status][0] ?? 'b-muted' }}">{{ $map[$status][1] ?? $status }}</span>
