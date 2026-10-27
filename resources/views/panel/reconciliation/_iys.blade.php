@php
    $r = $state['reconciliation'];
    $viaLabels = ['not_configured' => ['b-bad', 'Yapılandırılmadı'], 'awaiting_official_spec' => ['b-warn', 'Resmî event formatı bekleniyor'], 'configured' => ['b-pass', 'Yapılandırıldı']];
    [$viaCls, $viaLabel] = $viaLabels[$state['via']['state']] ?? ['b-muted', $state['via']['state']];
    $batchLabels = ['analyzing' => 'Analiz ediliyor', 'analyzed' => 'Analiz edildi (dry-run)', 'unsupported' => 'Format tanımlı değil', 'failed' => 'Hata', 'applied' => 'Uygulandı'];
@endphp
<ul class="list-plain small">
    <li class="split"><span class="muted">İzin stratejisi</span><strong>{{ $state['label'] }}</strong></li>
    <li class="split"><span class="muted">Durum</span>@if ($state['ready'])<span class="badge b-pass">Karar verebilir</span>@else<span class="badge b-bad">Fail-closed · {{ $state['blocker'] }}</span>@endif</li>
    <li class="split"><span class="muted">İYS API</span><span>{{ $state['direct_api']['available'] ? 'Erişilebilir' : 'Erişim yok' }} · {{ $state['direct_api']['required'] ? 'gerekli' : 'gerekli değil' }}</span></li>
    <li class="split"><span class="muted">ViA</span><span class="badge {{ $viaCls }}">{{ $viaLabel }}</span></li>
    <li class="split"><span class="muted">Son mutabakat</span><span>@if ($r['last_applied']){{ \Illuminate\Support\Carbon::parse($r['last_applied']['period_end'])->format('d.m.Y') }} dönemi · {{ $r['age_hours'] }} saat önce @else Henüz yok @endif</span></li>
    <li class="split"><span class="muted">Tazelik politikası</span><span>{{ $r['max_age_hours'] !== null ? $r['max_age_hours'].' saat' : 'Tanımlı değil (IYS_RECONCILIATION_MAX_AGE)' }}@if ($r['stale']) · <span class="badge b-bad">Eski</span>@endif</span></li>
    <li class="split"><span class="muted">Son rapor</span><span>{{ $r['last_batch'] ? ($batchLabels[$r['last_batch']['status']] ?? $r['last_batch']['status']) : '—' }}</span></li>
    @if ($r['last_applied'])
        <li class="split"><span class="muted">Son uygulanan değişiklikler</span><span>ONAY {{ $r['last_applied']['counts']['onay'] ?? 0 }} · RET {{ $r['last_applied']['counts']['ret'] ?? 0 }} · çakışma {{ $r['last_applied']['counts']['conflict'] ?? 0 }} · bilinmeyen alıcı {{ $r['last_applied']['counts']['unknown_recipient'] ?? 0 }}</span></li>
    @endif
    <li class="split"><span class="muted">Açık izin çakışması</span><span>{{ $r['open_conflicts'] }}</span></li>
    <li class="split"><span class="muted">Rapor formatı</span><span>{{ $r['format_configured'] ? 'Tanımlı' : 'Tanımlı değil (resmî format bekleniyor)' }}</span></li>
</ul>
