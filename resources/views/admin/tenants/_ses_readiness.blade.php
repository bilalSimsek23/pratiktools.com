@php($stepBadges = ['ok' => ['Tamam', 'b-ok'], 'warn' => ['Uyarı', 'b-warn'], 'pending' => ['Bekliyor', 'b-muted'], 'missing' => ['Eksik', 'b-muted'], 'failed' => ['Hata', 'b-bad'], 'blocked' => ['BLOCKED', 'b-bad']])
<ul class="list-plain">@foreach ($readiness['steps'] as $key => $step)
    @php([$stepLabel, $stepClass] = $stepBadges[$step['state']] ?? [$step['state'], 'b-muted'])
    <li class="split" id="ses-step-{{ $key }}"><span>{{ $step['label'] }}@if ($step['detail'])<br><span class="muted">{{ $step['detail'] }}</span>@endif</span><span class="badge {{ $stepClass }}">{{ $stepLabel }}</span></li>
@endforeach</ul>
