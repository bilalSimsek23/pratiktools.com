@php([$cls, $label] = ['published' => ['b-pass', 'Yayında'], 'draft' => ['b-muted', 'Taslak'], 'archived' => ['b-warn', 'Arşiv']][$status] ?? ['b-muted', $status])
<span class="badge {{ $cls }}">{{ $label }}</span>
