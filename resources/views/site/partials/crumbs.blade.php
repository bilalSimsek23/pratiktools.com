<nav class="crumbs small" aria-label="Sayfa yolu"><ol>
    @foreach ($crumbs as [$name, $path])
        <li>@if ($loop->last)<span aria-current="page">{{ $name }}</span>@else<a href="{{ $path }}">{{ $name }}</a>@endif</li>
    @endforeach
</ol></nav>
