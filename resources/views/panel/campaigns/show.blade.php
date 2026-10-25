@extends('panel.layout')
@section('title', $c->name)
@section('content')
@php
    $ricon = ['PASS' => ['pass', '✓'], 'WARN' => ['warn', '!'], 'BLOCKED' => ['bad', '✕']];
    $checkLabels = ['CONTENT' => 'İçerik', 'AUDIENCE' => 'Kitle', 'SENDER' => 'Gönderici', 'DKIM' => 'DKIM', 'MAIL_FROM' => 'MAIL FROM', 'UNSUBSCRIBE' => 'Abonelikten çıkma',
        'IYS' => 'İYS izin doğrulaması', 'ELIGIBLE_AUDIENCE' => 'Gönderilebilir alıcı', 'SES_PRODUCTION_ACCESS' => 'Amazon SES production', 'EMAIL_SENDING_MODE' => 'E-posta gönderim modu',
        'DISPATCH' => 'Canlı gönderim'];
    $slotLabels = ['review' => 'İnceleme', 'approve' => 'Onay'];
    $apStatus = ['pending' => ['b-warn', 'Bekliyor'], 'approved' => ['b-pass', 'Onaylandı'], 'rejected' => ['b-bad', 'Reddedildi'], 'expired' => ['b-muted', 'Süresi doldu'], 'invalidated' => ['b-muted', 'Geçersiz']];
    $dStatus = ['draft' => 'b-muted', 'awaiting_approval' => 'b-warn', 'approved' => 'b-info', 'queued' => 'b-info', 'running' => 'b-info', 'completed' => 'b-pass', 'failed' => 'b-bad', 'cancelled' => 'b-muted', 'blocked' => 'b-bad'];
    $frozen = $c->frozen_at !== null;
    $openApprovals = $approvals->whereIn('status', ['pending', 'approved']);
    $creator = str_starts_with((string) $c->created_by, 'user:') ? substr((string) $c->created_by, 5) : null;
    $step = ! $frozen ? 4 : ($validApprovals->count() < $required ? 7 : 8);
    $who = fn ($id) => $id === null ? '—' : ($people[$id] ?? 'Üye');
@endphp
<div class="page-head">
    <div>
        <div class="small muted"><a href="{{ route('panel.campaigns') }}">Kampanyalar</a> /</div>
        <h1>{{ $c->name }} @include('panel.campaigns._status', ['status' => $c->status])</h1>
        <p>{{ ['newsletter' => 'Bülten', 'promotional' => 'Kampanya', 'announcement' => 'Duyuru'][$c->type] ?? $c->type }} · {{ strtoupper($c->channel) }}
            @if ($frozen) · İçerik, kitle ve gönderici {{ \Illuminate\Support\Carbon::parse($c->frozen_at)->format('d.m.Y H:i') }} itibarıyla donduruldu @endif</p>
    </div>
    <div class="actions">
        @if ($can['manage'] && $c->status === 'draft')<a class="btn" href="{{ route('panel.campaigns.edit', $c->id) }}">Düzenle</a>@endif
        @if ($can['manage'])
            <form class="inline" method="post" action="{{ route('panel.campaigns.dry-run', $c->id) }}">@csrf<button class="btn" type="submit">Dry-run</button></form>
        @endif
    </div>
</div>

<div class="steps">
    @foreach (['Bilgiler', 'Kitle', 'İçerik', 'Gönderici', 'Önizleme', 'Hazırlık', 'Onay', 'Gönderim'] as $i => $label)
        <span class="{{ $i + 1 < $step ? 'done' : ($i + 1 === $step ? 'now' : '') }}">{{ $i + 1 }} {{ $label }}</span>
    @endforeach
</div>

<div class="grid g3">
    <div class="card">
        <h2>Kitle</h2>
        <p><strong>{{ $segment->name ?? 'Segment seçilmedi' }}</strong></p>
        @if ($audience)
            <ul class="list-plain small">
                <li class="split"><span>Segmentteki müşteri</span><strong>{{ number_format($audience['raw_audience']) }}</strong></li>
                <li class="split"><span>E-posta adresi olan</span><strong>{{ number_format($audience['email_recipients']) }}</strong></li>
                <li class="split"><span>İzin/İYS nedeniyle dışarıda</span><strong>{{ number_format($audience['consent_blocked']) }}</strong></li>
                <li class="split"><span>Bastırılmış (suppression)</span><strong>{{ number_format($audience['suppressed']) }}</strong></li>
                <li class="split"><span>Pazaryeri adresi</span><strong>{{ number_format($audience['marketplace_excluded']) }}</strong></li>
                <li class="split"><span>Frekans sınırına takılan</span><strong>{{ number_format($audience['frequency_capped']) }}</strong></li>
                <li class="split"><span><strong>Şu an gönderilebilir</strong></span><strong>{{ number_format($audience['currently_eligible']) }}</strong></li>
            </ul>
            @if ($audience['explanation'])<p class="small muted">İYS izni doğrulanamadığı için pazarlama gönderimi engelli.</p>@endif
            @if ($audience['audience_frozen'])<span class="badge b-info">Kitle donduruldu</span>@endif
        @else
            <p class="muted small">Kitle hesaplanamadı (segment seçilmemiş olabilir).</p>
        @endif
    </div>
    <div class="card">
        <h2>İçerik</h2>
        @if ($template)
            <p><strong>{{ $template->name }}</strong> · v{{ $template->version }} <span class="badge {{ $template->status === 'published' ? 'b-pass' : 'b-warn' }}">{{ $template->status === 'published' ? 'Yayında' : $template->status }}</span></p>
            @if ($preview)<p class="small"><span class="muted">Konu:</span> {{ $preview['subject'] }}</p>
                @if ($preview['preheader'])<p class="small"><span class="muted">Önizleme metni:</span> {{ $preview['preheader'] }}</p>@endif
            @endif
            <a class="small" href="{{ route('panel.templates.show', $template->template_id) }}">Şablonu aç →</a>
        @else
            <p class="muted">Şablon seçilmedi.</p>
        @endif
    </div>
    <div class="card">
        <h2>Gönderici</h2>
        @if ($sender)
            <p><strong>{{ $sender->from_name ? $sender->from_name.' · ' : '' }}{{ $sender->from_email }}</strong></p>
            <ul class="list-plain small">
                <li class="split"><span>Doğrulama</span><span class="badge {{ $sender->verification_status === 'verified' ? 'b-pass' : 'b-bad' }}">{{ $sender->verification_status }}</span></li>
                <li class="split"><span>DKIM</span><span class="badge {{ $sender->dkim_status === 'verified' ? 'b-pass' : 'b-bad' }}">{{ $sender->dkim_status }}</span></li>
                <li class="split"><span>MAIL FROM</span><span class="badge {{ $sender->mail_from_status === 'verified' ? 'b-pass' : 'b-warn' }}">{{ $sender->mail_from_status ?? '—' }}</span></li>
            </ul>
        @else
            <p class="muted">Gönderici seçilmedi.</p>
        @endif
    </div>
</div>

@if ($preview && $preview['html'])
<div class="card stack-top">
    <div class="split"><h2>Önizleme</h2><span class="small muted">Örnek değerlerle, güvenli (sandbox) görünümde. Gerçek kişi verisi kullanılmaz.</span></div>
    <div class="previews">
        <div><div class="small muted">Masaüstü</div><iframe class="preview" title="Masaüstü önizleme" sandbox src="{{ route('panel.templates.frame', $template->id) }}"></iframe></div>
        <div><div class="small muted">Mobil</div><iframe class="preview mobile" title="Mobil önizleme" sandbox src="{{ route('panel.templates.frame', $template->id) }}"></iframe></div>
    </div>
</div>
@endif

<div class="card stack-top">
    <div class="split"><h2>Hazırlık</h2>
        <span>@if ($readiness['can_prepare'])<span class="badge b-pass">Hazırlık tamam</span>@else<span class="badge b-warn">Hazırlık eksik</span>@endif
            @if (! $readiness['sendable'])<span class="badge b-bad">Canlı gönderim engelli</span>@endif</span></div>
    <ul class="readiness">
        @foreach ($rows as $r)
            @php([$cls, $sym] = $ricon[$r['status']] ?? ['warn', '?'])
            <li>
                <span class="ricon {{ $cls }}">{{ $sym }}</span>
                <div>
                    <div class="title">{{ $checkLabels[$r['check']] ?? $r['check'] }}</div>
                    @if ($r['explanation'])<div class="why">{{ $r['explanation'] }}</div>@endif
                    @if ($r['action'])<div class="todo">→ {{ $r['action'] }}</div>@endif
                </div>
                <span class="small muted">{{ $r['group'] === 'preparation' ? 'Hazırlık' : ($r['group'] === 'delivery' ? 'Teslimat' : 'Öneri') }}</span>
            </li>
        @endforeach
    </ul>
    @if ($can['publish'] || $can['manage'])
    <div class="actions stack-top">
        @if ($c->status === 'draft' && $can['publish'])
            <form class="inline" method="post" action="{{ route('panel.campaigns.transition', $c->id) }}">@csrf<input type="hidden" name="to" value="ready">
                <button class="btn btn-primary" type="submit" @disabled(! $readiness['can_prepare'])>Hazır olarak işaretle (dondur)</button></form>
        @endif
        @if ($c->status === 'ready' && $can['manage'])
            <form class="inline" method="post" action="{{ route('panel.campaigns.reopen', $c->id) }}">@csrf<button class="btn" type="submit">Düzenlemeye geri aç</button></form>
        @endif
        @if (in_array($c->status, ['draft', 'ready', 'scheduled', 'paused'], true) && $can['publish'])
            <form class="inline" method="post" action="{{ route('panel.campaigns.transition', $c->id) }}">@csrf<input type="hidden" name="to" value="cancelled">
                <button class="btn btn-danger" type="submit">Kampanyayı iptal et</button></form>
        @endif
        @if (in_array($c->status, ['draft', 'completed', 'cancelled'], true) && $can['publish'])
            <form class="inline" method="post" action="{{ route('panel.campaigns.transition', $c->id) }}">@csrf<input type="hidden" name="to" value="archived">
                <button class="btn" type="submit">Arşivle</button></form>
        @endif
    </div>
    @if ($c->status === 'draft')<p class="small muted">Hazır olarak işaretlenince içerik, kitle ve gönderici dondurulur. Değişiklik için kampanya geri açılır ve önceki onaylar geçersiz olur.</p>@endif
    @endif
</div>

@if ($dryRun)
<div class="card stack-top">
    <h2>Dry-run sonucu</h2>
    <div class="kpis">
        <div class="kpi"><div class="label">Kitle</div><div class="value">{{ number_format($dryRun['raw_audience']) }}</div></div>
        <div class="kpi"><div class="label">İzinli</div><div class="value">{{ number_format($dryRun['consent_allowed']) }}</div></div>
        <div class="kpi"><div class="label">Gönderilebilir</div><div class="value">{{ number_format($dryRun['currently_eligible']) }}</div></div>
        <div class="kpi"><div class="label">Planlanan e-posta</div><div class="value">{{ number_format($dryRun['planned_email']) }}</div></div>
        <div class="kpi"><div class="label">Sağlayıcı çağrısı</div><div class="value">{{ $dryRun['provider_calls'] }}</div></div>
    </div>
    @if (! empty($dryRun['reasons']))
        <p class="small muted">Nedenler: @foreach ($dryRun['reasons'] as $code => $n){{ $code }} {{ number_format($n) }}@if (! $loop->last) · @endif @endforeach</p>
    @endif
</div>
@endif

<div class="grid g2 stack-top">
    <div class="card">
        <div class="split"><h2>Onay</h2><span class="badge {{ $validApprovals->count() >= $required ? 'b-pass' : 'b-warn' }}">{{ $validApprovals->count() }} / {{ $required }} test onayı</span></div>
        <p class="small muted">Test gönderimi {{ $required }} onay, canlı gönderim iki farklı kişiden {{ $liveRequired }} onay gerektirir. Kampanyayı oluşturan veya onay isteyen kişi onaylayamaz; yönetici dahil kimse bu kuralı atlayamaz.</p>
        @if ($frozen && $can['manage'] && $openApprovals->isEmpty() && in_array($c->status, ['ready', 'scheduled'], true))
            <form method="post" action="{{ route('panel.campaigns.approvals.request', $c->id) }}">@csrf<button class="btn btn-primary" type="submit">Onaya gönder</button></form>
        @elseif (! $frozen)
            <p class="small">Onay istemek için kampanyayı önce hazır olarak işaretleyin.</p>
        @endif
        <div class="table-wrap stack-top"><table class="table">
            <thead><tr><th>Adım</th><th>Durum</th><th>İsteyen</th><th>Karar veren</th><th>Not</th><th></th></tr></thead>
            <tbody>
            @forelse ($approvals as $a)
                <tr>
                    <td>{{ $slotLabels[$a->slot] ?? $a->slot }}</td>
                    <td><span class="badge {{ $apStatus[$a->status][0] ?? 'b-muted' }}">{{ $apStatus[$a->status][1] ?? $a->status }}</span>
                        @if ($a->close_reason)<div class="small muted">{{ ['campaign_changed' => 'içerik/kitle/gönderici değişti', 'ttl' => 'süre doldu', 'campaign_reopened' => 'kampanya geri açıldı', 'campaign_cancelled' => 'kampanya iptal edildi', 'campaign_archived' => 'kampanya arşivlendi'][$a->close_reason] ?? $a->close_reason }}</div>@endif</td>
                    <td class="small">{{ $who($a->requested_by) }}<div class="muted">{{ \Illuminate\Support\Carbon::parse($a->requested_at)->format('d.m H:i') }}</div></td>
                    <td class="small">{{ $who($a->decided_by) }}@if ($a->decided_at)<div class="muted">{{ \Illuminate\Support\Carbon::parse($a->decided_at)->format('d.m H:i') }}</div>@endif</td>
                    <td class="small">{{ $a->reason ?? '' }}</td>
                    <td>
                        @if ($a->status === 'pending' && $can['approve'] && $a->requested_by !== $me && $creator !== $me)
                            <form method="post" action="{{ route('panel.campaigns.approvals.decide', [$c->id, $a->id]) }}" class="form-row">@csrf
                                <input type="text" name="reason" maxlength="500" placeholder="Not (ret için zorunlu)" aria-label="Not">
                                <button class="btn btn-sm btn-primary" name="decision" value="approve" type="submit">Onayla</button>
                                <button class="btn btn-sm btn-danger" name="decision" value="reject" type="submit">Reddet</button>
                            </form>
                        @elseif ($a->status === 'pending' && $can['approve'])
                            <span class="small muted">Kendi isteğinizi onaylayamazsınız</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Henüz onay isteği yok.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
    <div class="card">
        <div class="split"><h2>Gönderim</h2><span class="badge b-muted">Yalnızca test</span></div>
        <p class="small muted">Gönderim yalnızca izinli test alıcılarına yapılabilir. Canlı gönderim harici gereksinimler (İYS, SES production, unsubscribe anahtarı) tamamlanana kadar kapalıdır.</p>
        @if ($frozen && $can['dispatch'] && in_array($c->status, ['ready', 'scheduled'], true))
            <a class="btn btn-primary" href="{{ route('panel.dispatches.create', ['campaign' => $c->id]) }}">Test gönderimi hazırla</a>
        @endif
        <button class="btn" type="button" disabled title="Live sending is disabled until external readiness requirements are satisfied.">Canlı gönderim (kapalı)</button>
        <div class="table-wrap stack-top"><table class="table">
            <thead><tr><th>Talep</th><th>Kanal</th><th>Durum</th><th>Alıcı</th><th>Tarih</th></tr></thead>
            <tbody>
            @forelse ($dispatches as $d)
                <tr>
                    <td><a href="{{ route('panel.dispatches.show', $d->id) }}">#{{ $d->generation }} · {{ substr($d->id, 0, 8) }}</a></td>
                    <td>{{ $d->channel === 'email' ? 'E-posta' : 'Push' }}</td>
                    <td><span class="badge {{ $dStatus[$d->status] ?? 'b-muted' }}">{{ $d->status }}</span></td>
                    <td>{{ count((array) json_decode($d->recipients, true)) }}</td>
                    <td class="small muted">{{ \Illuminate\Support\Carbon::parse($d->requested_at)->format('d.m H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz gönderim talebi yok.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
@endsection
