{{-- Approved logo, else the name as text (never an unapproved logo). --}}
@if ($ref['logo'])
    <picture class="ref-logo">
        @if ($ref['logo_dark'])<source media="(prefers-color-scheme: dark)" srcset="{{ end($ref['logo_dark']['webp']) }}" type="image/webp">@endif
        <source srcset="{{ implode(', ', array_map(fn ($w) => $ref['logo']['webp'][$w].' '.$w.'w', array_keys($ref['logo']['webp']))) }}" sizes="200px" type="image/webp">
        <img src="{{ end($ref['logo']['jpg']) }}" alt="{{ $ref['logo']['alt'] }}" width="{{ $ref['logo']['width'] }}" height="{{ $ref['logo']['height'] }}" loading="lazy" decoding="async">
    </picture>
@else
    <span class="ref-wordmark">{{ $ref['name'] }}</span>
@endif
