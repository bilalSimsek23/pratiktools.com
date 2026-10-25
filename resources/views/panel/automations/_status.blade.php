@php($map = ['draft' => ['b-muted', 'Taslak'], 'published' => ['b-pass', 'Aktif'], 'paused' => ['b-warn', 'Duraklatıldı'], 'archived' => ['b-muted', 'Arşiv']])
<span class="badge {{ $map[$status][0] ?? 'b-muted' }}">{{ $map[$status][1] ?? $status }}</span>
