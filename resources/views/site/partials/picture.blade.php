@if ($image)
@php($ws = array_keys($image['webp']))
<picture>
    <source type="image/webp" srcset="{{ implode(', ', array_map(fn ($w) => $image['webp'][$w].' '.$w.'w', $ws)) }}" sizes="{{ $sizes ?? '(max-width: 800px) 100vw, 800px' }}">
    <img src="{{ end($image['jpg']) }}" srcset="{{ implode(', ', array_map(fn ($w) => $image['jpg'][$w].' '.$w.'w', array_keys($image['jpg']))) }}" sizes="{{ $sizes ?? '(max-width: 800px) 100vw, 800px' }}"
         width="{{ $image['width'] }}" height="{{ $image['height'] }}" alt="{{ $image['alt'] }}" loading="{{ $eager ?? false ? 'eager' : 'lazy' }}" decoding="async">
</picture>
@endif
