@php($map = ['draft' => ['b-muted', 'Taslak'], 'ready' => ['b-info', 'Hazır'], 'scheduled' => ['b-warn', 'Planlandı'], 'paused' => ['b-muted', 'Duraklatıldı'],
    'completed' => ['b-pass', 'Tamamlandı'], 'cancelled' => ['b-bad', 'İptal'], 'archived' => ['b-muted', 'Arşiv']])
<span class="badge {{ $map[$status][0] ?? 'b-muted' }}">{{ $map[$status][1] ?? $status }}</span>
