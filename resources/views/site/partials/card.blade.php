<article class="card">
    @if ($c['category'])<p class="eyebrow">{{ $c['category']['name'] }}</p>@endif
    <h3 class="card-title"><a href="{{ $c['url'] }}">{{ $c['title'] }}</a></h3>
    @if ($c['type'] === 'project' && ($c['data']['client_display'] ?? '') !== '')<p class="small muted">{{ ($c['data']['client_named'] ?? false) && isset($c['data']['client_approval']) ? ($c['data']['client_name'] ?? $c['data']['client_display']) : $c['data']['client_display'] }}</p>@endif
    <p>{{ $c['excerpt'] }}</p>
    <a class="more" href="{{ $c['url'] }}" aria-label="{{ $c['title'] }} — ayrıntılar">Ayrıntılar →</a>
</article>
