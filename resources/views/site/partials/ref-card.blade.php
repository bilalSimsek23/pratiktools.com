@php($project = $ref['projects'][0] ?? null)
@php($link = $project['url'] ?? ($ref['has_detail'] ? $ref['url'] : null))
<article class="card ref-card">
    <p class="eyebrow">{{ $ref['name'] }}@if ($ref['industry']) · {{ $ref['industry'] }}@endif</p>
    <h3 class="card-title">@if ($link)<a href="{{ $link }}">{{ $project['title'] ?? $ref['short_description'] }}</a>@else{{ $project['title'] ?? $ref['short_description'] }}@endif</h3>
    <p>{{ $project ? ($project['excerpt'] ?: $ref['short_description']) : (\App\Site\SiteMarkdown::plain($ref['body'], 220) ?: $ref['short_description']) }}</p>
    @if ($ref['capabilities'] !== [])<ul class="chips">@foreach (array_slice($ref['capabilities'], 0, 4) as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
    @if ($link)<a class="more" href="{{ $link }}">{{ $project ? 'Projeyi İncele' : 'Ayrıntılar' }} →</a>@endif
</article>
