@extends('admin.layout')
@section('title', $t->name.' · Amazon SES')
@section('content')
@php($i = $integration)
@php($states = ['connected' => ['Bağlı', 'b-ok'], 'action_required' => ['İşlem gerekli', 'b-warn'], 'error' => ['Hata', 'b-bad'], 'pending' => ['Test bekliyor', 'b-muted']])
@php([$stateLabel, $badge] = $i === null ? ['Yapılandırılmadı', 'b-muted'] : ($states[$i['status']] ?? [$i['status'], 'b-muted']))
@php($overallBadges = ['not_configured' => 'b-muted', 'awaiting_test' => 'b-muted', 'action_required' => 'b-bad', 'incomplete' => 'b-warn', 'infrastructure_ready' => 'b-ok', 'ready' => 'b-ok'])
@php($fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y H:i') : '—')
@php($yes = fn ($v) => $v === null ? '—' : ($v ? 'Evet' : 'Hayır'))
@php($a = $i['account'] ?? [])
<div class="page-head">
    <div><h1>Amazon SES <span class="badge {{ $badge }}">{{ $stateLabel }}</span> <span class="badge {{ $overallBadges[$readiness['overall']] ?? 'b-muted' }}">{{ $readiness['overall_label'] }}</span></h1>
        <p class="lead">{{ $t->name }} · <code>{{ $t->slug }}</code> · yalnız bu müşterinin e-posta altyapısı</p></div>
    <a class="btn" href="{{ route('admin.tenants.show', [$t->id, 'tab' => 'entegrasyonlar']) }}">← Entegrasyonlar</a>
</div>

<div class="card">
    <p class="muted">Bu ekran AWS'de veya DNS'te hiçbir şey oluşturmaz ya da değiştirmez: SES'ten yalnız okuma yapar. Domain kimliği, Configuration Set, SNS topic ve abonelik AWS konsolunda;
        DNS kayıtları alan adı sağlayıcısında oluşturulur. AWS kimlik bilgileri şifreli kasada saklanır ve hiçbir zaman bu ekranda gösterilmez.
        Gönderim modu bu ekrandan değiştirilmez: platform modu Sistem Durumu'ndan, müşteri modu müşteri panelinden yönetilir.</p>
    <dl class="kv" id="gonderim-modlari">
        <dt>Platform modu</dt><dd><code>{{ strtoupper($readiness['mode']['platform']) }}</code> <a href="{{ route('admin.system') }}#eposta-gonderim-modu">Sistem Durumu</a></dd>
        <dt>{{ $t->name }} modu</dt><dd><code>{{ strtoupper($readiness['mode']['tenant']) }}</code></dd>
        <dt>Etkin mod</dt><dd><code>{{ strtoupper($readiness['mode']['effective']) }}</code> <span class="muted">— {{ $readiness['mode']['reason'] }}</span></dd>
    </dl>
</div>

<div class="grid g2">
    <div class="card"><h2>Durum</h2>
        <dl class="kv">
            <dt>Durum</dt><dd><span class="badge {{ $badge }}">{{ $stateLabel }}</span></dd>
            <dt>AWS kimlik bilgileri</dt><dd>{{ $i === null ? '—' : ($i['has_secret'] ? 'AWS kimlik bilgileri kayıtlı' : 'Kayıtlı değil') }}</dd>
            <dt>Son güncelleme</dt><dd>@if ($i['secret_changed_at'] ?? null){{ $fmt($i['secret_changed_at']) }} · {{ $i['secret_changed_by'] }}@else — @endif</dd>
            <dt>Son doğrulama</dt><dd>{{ $fmt($i['last_verified_at'] ?? null) }}</dd>
            <dt>Son hata</dt><dd>{{ $i['last_error'] ?? '—' }}</dd>
            <dt>Yetkiler</dt><dd>@if ($i){{ implode(', ', $i['scopes']) ?: '—' }}@else — @endif</dd>
        </dl>
    </div>
    <div class="card"><h2>SES bağlantı testi</h2>
        <p class="muted">SES'ten yalnız hesap bilgisini ve Configuration Set'i okur (GetAccount, GetConfigurationSet). Gönderim yapmaz.</p>
        @error('ses_test')<div class="alert">{{ $message }}</div>@enderror
        <dl class="kv" id="ses-account">
            <dt>Bölge</dt><dd>{{ $a['region'] ?? ($i['region'] ?? '—') }}</dd>
            <dt>Production access</dt><dd>{{ $yes($a['production_access'] ?? null) }}</dd>
            <dt>Gönderim açık</dt><dd>{{ $yes($a['sending_enabled'] ?? null) }}</dd>
            <dt>Enforcement</dt><dd>{{ $a['enforcement_status'] ?? '—' }}</dd>
            <dt>Günlük kota</dt><dd>{{ isset($a['max_24h_send']) ? number_format((float) $a['max_24h_send'], 0, ',', '.') : '—' }}</dd>
            <dt>Azami hız</dt><dd>{{ isset($a['max_send_rate']) ? rtrim(rtrim(number_format((float) $a['max_send_rate'], 2, ',', '.'), '0'), ',').' / sn' : '—' }}</dd>
            <dt>Son 24 saatte gönderilen</dt><dd>{{ isset($a['sent_last_24h']) ? number_format((float) $a['sent_last_24h'], 0, ',', '.') : '—' }}</dd>
            <dt>Son doğrulama</dt><dd>{{ $fmt($a['checked_at'] ?? null) }}</dd>
        </dl>
        @if ($i !== null && $i['has_secret'])
            <form method="post" action="{{ route('admin.tenants.ses.test', $t->id) }}">@csrf
                <button class="btn-primary" type="submit">SES Bağlantısını Test Et</button>
            </form>
        @else
            <p class="muted">Test için önce AWS kimlik bilgilerini kaydedin.</p>
        @endif
    </div>
</div>

<div class="card"><h2>Ayarlar</h2>
    @error('ses')<div class="alert">{{ $message }}</div>@enderror
    <form class="form-grid" method="post" action="{{ route('admin.tenants.ses.update', $t->id) }}" autocomplete="off">@csrf
        <label>Bölge (region) <input type="text" name="region" required maxlength="32" value="{{ old('region', $i['region'] ?? $defaultRegion) }}"></label>
        <label>Configuration Set <input type="text" name="configuration_set" maxlength="64" placeholder="ornek-olay-seti" value="{{ old('configuration_set', $i['configuration_set'] ?? '') }}"></label>
        <label>SNS Topic ARN (her satıra bir tane) <textarea name="sns_topic_arns" rows="2" maxlength="2000" placeholder="arn:aws:sns:<bölge>:<hesap-no>:<topic-adı>">{{ old('sns_topic_arns', implode("\n", $i['sns_topic_arns'] ?? [])) }}</textarea></label>
        <fieldset><legend>İzleme (Configuration Set olayları)</legend>
            @foreach (['open' => 'Açılma', 'click' => 'Tıklama'] as $kind => $kindLabel)
                <label><input type="checkbox" name="tracking[]" value="{{ $kind }}" @checked(in_array($kind, old('tracking', $i['tracking'] ?? []), true))> {{ $kindLabel }}</label>
            @endforeach
        </fieldset>
        <label>AWS Access Key ID <input type="password" name="aws_access_key_id" maxlength="128" autocomplete="off" @if ($i === null || ! $i['has_secret']) required @endif
            placeholder="{{ ($i['has_secret'] ?? false) ? 'Kayıtlı anahtar korunur; değiştirmek için yeni çifti girin' : 'IAM kullanıcısının access key ID değeri' }}"></label>
        <label>AWS Secret Access Key <input type="password" name="aws_secret_access_key" maxlength="256" autocomplete="off" @if ($i === null || ! $i['has_secret']) required @endif
            placeholder="{{ ($i['has_secret'] ?? false) ? 'Kayıtlı anahtar korunur' : 'IAM kullanıcısının secret access key değeri' }}"></label>
        <p class="hint">Anahtar çifti birlikte girilir; ikisi de boş bırakılırsa kayıtlı çift korunur. Yeni çift (veya başka bölge) girilirse eskisinin yerine şifrelenerek yazılır ve bağlantı yeniden test edilene kadar "Test bekliyor" olur.
            IAM yetkileri: <code>ses:GetAccount</code>, <code>ses:GetEmailIdentity</code>, <code>ses:GetConfigurationSet</code>, <code>ses:GetConfigurationSetEventDestinations</code>; gönderim için ayrıca <code>ses:SendEmail</code>.</p>
        <div><button class="btn-primary" type="submit">Kaydet</button></div>
    </form>
</div>

@if ($i !== null)
<div class="card table-wrap" id="gondericiler"><h2>Gönderici kimlikleri</h2>
    <p class="muted">Kimliği (domain) ve isteğe bağlı özel MAIL FROM alt alan adını AWS konsolunda oluşturun; aşağıdaki kayıtları alan adı sağlayıcınıza ekleyin, sonra Doğrula / Yenile ile durumunu okuyun.
        PratikTools DNS'i değiştirmez.</p>
    @error('ses_sender')<div class="alert">{{ $message }}</div>@enderror
    @php($senderBadge = fn ($s) => match (true) { $s === 'verified' || $s === 'aligned' || str_starts_with((string) $s, 'p=') && $s !== 'p=invalid' => 'b-ok', in_array($s, ['failed', 'p=invalid', 'missing'], true) => 'b-bad', default => 'b-muted' })
    @forelse ($senders as $s)
        <h3>{{ $s['from_email'] }} <span class="muted">· {{ $s['from_name'] ?? '—' }} · kimlik <code>{{ $s['provider_identity_id'] }}</code>@if ($s['reply_to_email']) · yanıt: {{ $s['reply_to_email'] }}@endif</span></h3>
        <p>
            Doğrulama <span class="badge {{ $senderBadge($s['verification_status']) }}">{{ $s['verification_status'] }}</span>
            DKIM <span class="badge {{ $senderBadge($s['dkim_status']) }}">{{ $s['dkim_status'] }}</span>
            SPF <span class="badge {{ $senderBadge($s['spf_status']) }}">{{ $s['spf_status'] }}</span>
            DMARC <span class="badge {{ $senderBadge($s['dmarc_status']) }}">{{ $s['dmarc_status'] }}</span>
            MAIL FROM <span class="badge {{ $senderBadge($s['mail_from_status']) }}">{{ $s['mail_from_status'] }}</span>@if ($s['mail_from_domain']) <code>{{ $s['mail_from_domain'] }}</code>@endif
            <span class="muted">· son okuma {{ $fmt($s['last_checked_at']) }}</span>
        </p>
        <form method="post" action="{{ route('admin.tenants.ses.senders.refresh', [$t->id, $s['id']]) }}">@csrf<button type="submit">Doğrula / Yenile</button></form>
        @if ($s['records'] !== [])
        @php($recordStatus = ['dkim' => $s['dkim_status'], 'mail_from' => $s['mail_from_status'], 'spf' => $s['mail_from_status'], 'dmarc' => $s['dmarc_status']])
        <table class="dns-records">
            <thead><tr><th>Amaç</th><th>Tür</th><th>Ad (host)</th><th>Değer</th><th>Öncelik</th><th>Durum</th><th></th></tr></thead>
            <tbody>@foreach ($s['records'] as $r)
                @php([$priority, $value] = $r['type'] === 'MX' && preg_match('/^(\d+)\s+(.+)$/', $r['value'], $m) === 1 ? [$m[1], $m[2]] : [null, $r['value']])
                <tr><td>{{ strtoupper(str_replace('_', ' ', $r['purpose'])) }}</td><td><code>{{ $r['type'] }}</code></td><td><code>{{ $r['name'] }}</code></td><td><code>{{ $value }}</code>@if ($r['note'] ?? null)<br><span class="muted">{{ $r['note'] }}</span>@endif</td>
                    <td>{{ $priority ?? '—' }}</td><td><span class="badge {{ $senderBadge($recordStatus[$r['purpose']] ?? null) }}">{{ $recordStatus[$r['purpose']] ?? '—' }}</span></td>
                    <td><button type="button" class="copy" data-copy="{{ $value }}">Kopyala</button></td></tr>
            @endforeach</tbody>
        </table>
        @else
            <p class="muted">DNS kayıtları ilk Doğrula / Yenile ile gelir.</p>
        @endif
    @empty
        <p class="muted">Henüz gönderici yok.</p>
    @endforelse
    <h3>Gönderici ekle / güncelle</h3>
    <form class="form-grid" method="post" action="{{ route('admin.tenants.ses.senders.store', $t->id) }}">@csrf
        <label>Gönderen adresi (From) <input type="email" name="from_email" required maxlength="320" placeholder="kampanya@news.ornek.com" value="{{ old('from_email') }}"></label>
        <label>Gönderen adı <input type="text" name="from_name" maxlength="120" value="{{ old('from_name') }}"></label>
        <label>Yanıt adresi (Reply-To) <input type="email" name="reply_to" maxlength="320" value="{{ old('reply_to') }}"></label>
        <label>SES kimliği (domain veya adres; boşsa From domaini) <input type="text" name="identity" maxlength="253" placeholder="news.ornek.com" value="{{ old('identity') }}"></label>
        <p class="hint">Özel MAIL FROM alt alan adı SES'te kimlik üzerinde ayarlanır; Doğrula / Yenile ile buraya okunur ve MX/SPF kayıtları listelenir.</p>
        <div><button class="btn-primary" type="submit">Göndericiyi kaydet</button></div>
    </form>
</div>

@php($sub = ['none' => ['Gelmedi', 'b-muted'], 'pending_confirmation' => ['Onay bekliyor', 'b-warn'], 'confirmed' => ['Onaylandı', 'b-ok'], 'error' => ['Hata', 'b-bad']])
@php([$subLabel, $subBadge] = $sub[$sns['state']] ?? [$sns['state'], 'b-muted'])
@php($cs = $i['configuration_set_check'])
<div class="card" id="sns"><h2>Configuration Set ve SNS olayları</h2>
    @error('ses_sns')<div class="alert">{{ $message }}</div>@enderror
    <dl class="kv">
        <dt>Configuration Set</dt><dd>@if ($i['configuration_set'])<code>{{ $i['configuration_set'] }}</code> · {{ ['ok' => 'SES\'te var', 'missing' => 'SES\'te yok', 'iam_required' => 'Doğrulanamadı – IAM yetkisi gerekli', 'error' => 'Doğrulanamadı', 'not_configured' => '—'][$cs['state'] ?? ''] ?? 'Henüz test edilmedi' }}@else — @endif</dd>
        <dt>SNS hedefleri</dt><dd>@forelse ($cs['sns_destinations'] ?? [] as $d)<code>{{ $d['topic_arn'] }}</code> · {{ $d['enabled'] ? 'açık' : 'kapalı' }} · {{ implode(', ', $d['events']) }}@if (! $d['topic_allowed']) · <span class="badge b-warn">kayıtlı değil</span>@endif<br>@empty — @endforelse</dd>
        <dt>İzleme</dt><dd>{{ implode(', ', $cs['open_click'] ?? []) ?: '—' }}@if ($cs['custom_redirect_domain'] ?? null) · yönlendirme: <code>{{ $cs['custom_redirect_domain'] }}</code>@endif</dd>
        <dt>Kayıtlı SNS topic</dt><dd>@forelse ($i['sns_topic_arns'] as $arn)<code>{{ $arn }}</code><br>@empty — @endforelse</dd>
        <dt>Webhook adresi</dt><dd><code id="webhook-url">{{ $webhookUrl }}</code> <button type="button" class="copy" data-copy="{{ $webhookUrl }}">Kopyala</button>
            <br><span class="muted">SNS topic'e HTTPS aboneliği olarak bu adresi ekleyin. Topic ARN'ı aboneliği eklemeden önce burada kaydedin; kayıtlı olmayan topic'lerden gelen istekler reddedilir.</span></dd>
        <dt>Abonelik</dt><dd><span class="badge {{ $subBadge }}">{{ $subLabel }}</span>@if ($sns['topic_arn']) <code>{{ $sns['topic_arn'] }}</code>@endif @if ($sns['at']) · {{ $fmt($sns['at']) }}@endif
            @if ($sns['error'])<br><span class="muted">Son hata: {{ $sns['error'] }}</span>@endif
            @if ($sns['expired'])<br><span class="muted">Onay isteğinin süresi doldu (3 gün): AWS konsolunda aboneliği seçip "Request confirmation" ile yeniden gönderin.</span>@endif</dd>
        <dt>Son webhook</dt><dd>@if ($sns['last_webhook']){{ $fmt($sns['last_webhook']['received_at']) }} · {{ $sns['last_webhook']['event_type'] ?? '—' }} · imza <code>{{ $sns['last_webhook']['signature_status'] }}</code> · {{ $sns['last_webhook']['processing_status'] }}@else — @endif
            @if ($sns['invalid_7d'] > 0)<br><span class="badge b-warn">Son 7 günde {{ $sns['invalid_7d'] }} imzasız/geçersiz istek</span>@endif</dd>
        <dt>Son SES olayı</dt><dd>@if ($sns['last_event']){{ $fmt($sns['last_event']['received_at']) }} · {{ $sns['last_event']['event_type'] }}@if ($sns['last_event']['bounce_class']) ({{ $sns['last_event']['bounce_class'] }})@endif @else — @endif</dd>
        <dt>Bounce / Complaint</dt><dd>{{ $readiness['steps']['bounce_complaint']['detail'] ?? '—' }}</dd>
    </dl>
    @if (in_array($sns['state'], ['pending_confirmation', 'error'], true) && $sns['topic_allowed'] && ! $sns['expired'])
        <form method="post" action="{{ route('admin.tenants.ses.sns.confirm', $t->id) }}">@csrf
            <button class="btn-primary" type="submit">Aboneliği Onayla</button>
            <span class="hint">AWS SNS'e tek bir onay isteği gönderir (yalnız amazonaws.com).</span>
        </form>
    @endif
</div>
@endif

<div class="card" id="unsubscribe"><h2>Unsubscribe</h2>
    <p class="muted"><code>EMAIL_UNSUBSCRIBE_KEY</code> bütün müşterilerin bağlantılarını imzalayan platform anahtarıdır; Laravel Cloud ortam ayarlarında tutulur, bu ekrandan gösterilmez ve değiştirilmez.
        Değiştirilirse verilmiş bütün abonelikten çıkma bağlantıları geçersiz olur.</p>
    <dl class="kv">
        <dt>Anahtar</dt><dd>@if ($unsubscribe['configured'])<span class="badge b-ok">Yapılandırıldı</span>@else<span class="badge b-bad">Eksik – BLOCKED</span>@endif</dd>
        <dt>Adres</dt><dd><code>{{ $unsubscribe['base_url'] }}/u/…</code> @if (! $unsubscribe['https'])<span class="badge b-bad">https değil</span>@endif</dd>
        <dt>RFC 8058 tek tık</dt><dd>{{ $unsubscribe['rfc8058'] ? 'Hazır (List-Unsubscribe + List-Unsubscribe-Post)' : 'Hazır değil' }}</dd>
        <dt>Bağlantı rotaları</dt><dd>{{ $unsubscribe['routes'] ? 'GET onay + POST uygula kayıtlı' : 'Eksik' }}</dd>
        <dt>İmza doğrulaması</dt><dd>{{ $unsubscribe['configured'] ? ($unsubscribe['round_trip'] ? 'Çalışıyor' : 'Başarısız') : '—' }}</dd>
    </dl>
</div>

<div class="grid g2">
    <div class="card table-wrap" id="test-alicilari"><h2>Test alıcıları</h2>
        <p class="muted">Test e-postası ve test_only modundaki her gönderim yalnız bu listedeki aktif adreslere gidebilir.</p>
        @error('ses_recipient')<div class="alert">{{ $message }}</div>@enderror
        <table>
            <thead><tr><th>Adres</th><th>Kime ait</th><th>Onaylayan</th><th>Durum</th><th></th></tr></thead>
            <tbody>@forelse ($test_recipients as $r)
                <tr><td>{{ $r['email'] }}</td><td>{{ $r['label'] }}</td><td>{{ $r['approved_by'] }}</td><td>{{ $r['active'] ? 'Aktif' : 'Pasif' }}</td>
                    <td>@if ($r['active'])<form method="post" action="{{ route('admin.tenants.ses.test-recipients.remove', $t->id) }}">@csrf<input type="hidden" name="email" value="{{ $r['email'] }}"><button type="submit">Kaldır</button></form>@endif</td></tr>
            @empty<tr><td colspan="5" class="muted">Test alıcısı yok.</td></tr>@endforelse</tbody>
        </table>
        <form class="form-grid" method="post" action="{{ route('admin.tenants.ses.test-recipients.store', $t->id) }}">@csrf
            <label>Adres <input type="email" name="email" required maxlength="320"></label>
            <label>Kime ait <input type="text" name="label" required maxlength="120" placeholder="QA kutusu"></label>
            <label>Onaylayan <input type="text" name="approved_by" required maxlength="64"></label>
            <div><button type="submit">Test alıcısı ekle</button></div>
        </form>
    </div>
    <div class="card" id="test-eposta"><h2>Test e-postası</h2>
        @error('ses_test_email')<div class="alert">{{ $message }}</div>@enderror
        @error('ses_scope')<div class="alert">{{ $message }}</div>@enderror
        <p class="muted">Seçilen test alıcılarına zararsız tek bir test e-postası gönderir (gerçek gönderim). Kampanya göndermez.</p>
        <dl class="kv">
            <dt>Son test</dt><dd>@if ($last_test){{ $fmt($last_test['created_at']) }} · {{ $last_test['status'] }}@if ($last_test['delivery_status']) · {{ $last_test['delivery_status'] }}@endif @if ($last_test['block_reason']) <code>{{ $last_test['block_reason'] }}</code>@endif @else — @endif</dd>
        </dl>
        @if ($test_blocker)
            <div class="alert" id="test-email-blocked">{{ $test_blocker['message'] }}</div>
        @endif
        <form class="form-grid" method="post" action="{{ route('admin.tenants.ses.test-email', $t->id) }}">@csrf
            <fieldset @disabled($test_blocker !== null)><legend>Alıcılar (en fazla {{ config('email.max_test_recipients') }})</legend>
                @foreach (collect($test_recipients)->where('active', true) as $r)
                    <label><input type="checkbox" name="recipients[]" value="{{ $r['email'] }}"> {{ $r['email'] }}</label>
                @endforeach
            </fieldset>
            <label>Gönderici <select name="from_email" @disabled($test_blocker !== null)>@foreach (collect($senders)->where('verification_status', 'verified') as $s)<option value="{{ $s['from_email'] }}">{{ $s['from_email'] }}</option>@endforeach</select></label>
            <label>Onay ifadesi: <code>{{ $confirmation }}</code> <input type="text" name="confirmation" autocomplete="off" @disabled($test_blocker !== null)></label>
            <label><input type="checkbox" name="acknowledge" value="1" @disabled($test_blocker !== null)> Gerçek bir e-posta gönderileceğini onaylıyorum.</label>
            <div><button class="btn-primary" type="submit" @disabled($test_blocker !== null)>Test e-postası gönder</button></div>
        </form>
        @if ($i !== null)
            <h3>Gönderim yetkisi (send_email)</h3>
            <p class="muted">Yetki tek başına gönderim yapmaz: platform ve müşteri gönderim modu ile gönderim kuralları ayrıca karar verir.</p>
            <form method="post" action="{{ route('admin.tenants.ses.scope', $t->id) }}">@csrf
                @if (in_array('send_email', $i['scopes'], true))
                    <input type="hidden" name="grant" value="0"><button type="submit">send_email yetkisini kapat</button>
                @else
                    <input type="hidden" name="grant" value="1">
                    <label>Açmak için <code>send_email</code> yazın <input type="text" name="confirm" autocomplete="off" maxlength="64"></label>
                    <button type="submit">send_email yetkisini aç</button>
                @endif
            </form>
        @endif
    </div>
</div>

<div class="grid g2">
    <div class="card" id="ses-hazirlik"><h2>E-posta / SES Hazırlık <span class="badge {{ $overallBadges[$readiness['overall']] ?? 'b-muted' }}">{{ $readiness['overall_label'] }}</span></h2>
        @include('admin.tenants._ses_readiness', ['readiness' => $readiness])
        <p class="hint">"Hazır" yalnız gönderim modu canlıyken görünür; altyapının hazır olması gönderimin açık olduğu anlamına gelmez.</p>
    </div>
    <div class="card" id="kampanya-hazirlik"><h2>11 Kasım E-posta Hazırlığı <span class="badge {{ $launch['sendable'] ? 'b-ok' : 'b-bad' }}">{{ $launch['verdict'] }}</span></h2>
        <p class="muted">Bu müşteri, izni olan mevcut kitlesine canlı bir pazarlama kampanyası gönderebilir mi? İzin dayanağı: <strong>{{ $launch['strategy']['label'] }}</strong>.
            @if ($launch['strategy']['strategy'] === 'merchant_verified') Mağaza (Ticimax) izinleri müşterinin beyanıyla kullanılır; bu bir İYS veya ViA doğrulaması değildir. @else Ticimax MailIzin bu stratejide izin yerine kullanılmaz. @endif</p>
        @foreach (['delivery' => 'Gönderim (hepsi PASS olmalı)', 'acquisition' => 'Yeni izin toplama (uyarı; gönderimi engellemez)'] as $group => $title)
        <h3>{{ $title }}</h3>
        <ul class="list-plain">@foreach (array_filter($launch['checks'], fn ($c) => $c['group'] === $group) as $key => $c)
            <li class="split" id="launch-{{ strtolower($key) }}"><span>{{ $c['label'] }}@if ($c['detail'])<br><span class="muted">{{ $c['detail'] }}</span>@endif</span>
                <span class="badge {{ ['PASS' => 'b-ok', 'WARN' => 'b-warn'][$c['status']] ?? 'b-bad' }}">{{ $c['status'] }}</span></li>
        @endforeach</ul>
        @endforeach
        <h3>E-posta alıcıları — neden kırılımı</h3>
        <table id="launch-breakdown"><tbody>
            @foreach ($launch['breakdown'] as $code => $n)
                <tr><td>{{ \App\Email\EmailLaunchReadiness::BREAKDOWN[$code] ?? $code }}</td><td>{{ number_format($n) }}</td></tr>
            @endforeach
            <tr><td><strong>Toplam e-posta alıcısı</strong></td><td><strong>{{ number_format($launch['counts']['recipients']) }}</strong></td></tr>
        </tbody></table>
        <p class="hint">Kampanya segmenti ve sıklık sınırı kampanyanın kendi hazırlığında ayrıca uygulanır.</p>
    </div>
</div>
<script nonce="{{ $cspNonce }}">
document.querySelectorAll('button.copy').forEach(function (b) {
    b.addEventListener('click', function () {
        if (navigator.clipboard) { navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { b.textContent = 'Kopyalandı'; }); }
    });
});
</script>
@endsection
