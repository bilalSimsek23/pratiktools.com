@extends('admin.layout')
@section('title', $t->name.' · Ticimax')
@section('content')
@php($i = $integration)
@php($states = ['connected' => ['Bağlı', 'b-ok'], 'action_required' => ['İşlem gerekli', 'b-warn'], 'error' => ['Hata', 'b-bad'], 'pending' => ['Test bekliyor', 'b-muted']])
@php([$stateLabel, $badge] = $i === null ? ['Yapılandırılmadı', 'b-muted'] : ($states[$i['status']] ?? [$i['status'], 'b-muted']))
<div class="page-head">
    <div><h1>Ticimax <span class="badge {{ $badge }}">{{ $stateLabel }}</span></h1>
        <p class="lead">{{ $t->name }} · <code>{{ $t->slug }}</code> · yalnız bu müşterinin entegrasyonu</p></div>
    <a class="btn" href="{{ route('admin.tenants.show', [$t->id, 'tab' => 'entegrasyonlar']) }}">← Entegrasyonlar</a>
</div>

<div class="card">
    <p class="muted">Ticimax entegrasyonu salt okunurdur: PratikTools Ticimax'ten müşteri, sipariş, ürün ve sepet okur, Ticimax'e hiçbir şey yazmaz.
        Yetki kodu (UyeKodu) şifreli kasada saklanır ve hiçbir zaman bu ekranda gösterilmez.</p>
</div>

<div class="grid g2">
    <div class="card"><h2>Durum</h2>
        <dl class="kv">
            <dt>Durum</dt><dd><span class="badge {{ $badge }}">{{ $stateLabel }}</span></dd>
            <dt>Yetki kodu</dt><dd>{{ $i === null ? '—' : ($i['has_secret'] ? 'Yetki kodu kayıtlı' : 'Yetki kodu kayıtlı değil') }}</dd>
            <dt>Son güncelleme</dt><dd>@if ($i['secret_changed_at'] ?? null){{ \Illuminate\Support\Carbon::parse($i['secret_changed_at'])->format('d.m.Y H:i') }} · {{ $i['secret_changed_by'] }}@else — @endif</dd>
            <dt>Son doğrulama</dt><dd>{{ ($i['last_verified_at'] ?? null) ? \Illuminate\Support\Carbon::parse($i['last_verified_at'])->format('d.m.Y H:i') : '—' }}</dd>
            <dt>Son hata</dt><dd>{{ $i['last_error'] ?? '—' }}</dd>
            <dt>Servis adresi</dt><dd>@if ($i['service_url'] ?? null)<code>{{ $i['service_url'] }}</code>@else — @endif</dd>
            <dt>Mağaza adresi</dt><dd>@if ($i['storefront_url'] ?? null)<code>{{ $i['storefront_url'] }}</code>@else — @endif</dd>
        </dl>
    </div>
    <div class="card"><h2>Bağlantı testi</h2>
        <p class="muted">Ticimax'ten yalnız okuma yapar: sipariş durumları, 1 üye ve 1 sipariş. Sonuç durumu, son doğrulama zamanını ve son hatayı günceller. Senkron başlatmaz.</p>
        @error('ticimax_test')<div class="alert">{{ $message }}</div>@enderror
        @if ($i !== null && $i['has_secret'])
            <form method="post" action="{{ route('admin.tenants.ticimax.test', $t->id) }}">@csrf
                <button class="btn-primary" type="submit">Bağlantıyı Test Et</button>
            </form>
            <p class="hint">Test birkaç saniye sürebilir; Ticimax yanıt vermezse daha uzun sürer.</p>
        @else
            <p class="muted">Test için önce yetki kodunu kaydedin.</p>
        @endif
    </div>
</div>

<div class="card"><h2>Ayarlar</h2>
    @error('ticimax')<div class="alert">{{ $message }}</div>@enderror
    <form class="form-grid" method="post" action="{{ route('admin.tenants.ticimax.update', $t->id) }}" autocomplete="off">@csrf
        <label>Servis adresi <input type="url" name="service_url" required maxlength="512" placeholder="https://www.ornek.com/Servis/" value="{{ old('service_url', $i['service_url'] ?? '') }}"></label>
        <label>Mağaza adresi <input type="url" name="storefront_url" required maxlength="512" placeholder="https://www.ornek.com" value="{{ old('storefront_url', $i['storefront_url'] ?? '') }}"></label>
        <label>Ticimax yetki kodu (UyeKodu) <input type="password" name="secret" maxlength="512" autocomplete="off" @if ($i === null || ! $i['has_secret']) required @endif
            placeholder="{{ ($i['has_secret'] ?? false) ? 'Kayıtlı kod korunur; değiştirmek için yeni kodu girin' : 'Ticimax panelindeki web servis yetki kodu' }}"></label>
        <p class="hint">Boş bırakılırsa kayıtlı kod korunur. Yeni kod girilirse eskisinin yerine şifrelenerek yazılır ve bağlantı yeniden test edilene kadar "Test bekliyor" olur.
            Servis adresi değişirse de yeniden test gerekir.</p>
        <div><button class="btn-primary" type="submit">Kaydet</button></div>
    </form>
</div>
@if ($sync !== null)
@php($stateBadges = ['not_started' => ['Başlamadı', 'b-muted'], 'queued' => ['Sırada', 'b-muted'], 'running' => ['Çalışıyor', 'b-warn'], 'retrying' => ['Yeniden deneniyor', 'b-warn'], 'succeeded' => ['Başarılı', 'b-ok'], 'failed' => ['Başarısız', 'b-bad']])
@php($overallBadge = ['ready' => 'b-ok', 'syncing' => 'b-warn', 'action_required' => 'b-bad'][$sync['overall']] ?? 'b-muted')
@php($busy = collect($sync['steps'])->contains(fn ($s) => $s['busy']))
@php($fmt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y H:i') : '—')
<div class="card table-wrap" id="veri-senkronizasyonu"><h2>Veri Senkronizasyonu <span class="badge {{ $overallBadge }}">{{ \App\Commerce\Onboarding\TicimaxOnboarding::OVERALL[$sync['overall']] }}</span></h2>
    <p class="muted">Adımlar sırayla ve arka planda (kuyrukta) çalışır; tarayıcıyı kapatabilirsiniz. Her adım bir öncekinin başarıyla bitmesini bekler.
        Kurulum, başarıyla biten adımları tekrar çalıştırmaz; bir adım başarısız olursa durur ve o adımdan devam ettirilebilir.
        Ticimax'ten yalnız okuma yapılır. Geçici bağlantı hatalarında adım kaldığı sayfadan otomatik olarak yeniden denenir.</p>
    @error('ticimax_sync')<div class="alert">{{ $message }}</div>@enderror
    @if ($sync['overall'] !== 'ready')
        <form method="post" action="{{ route('admin.tenants.ticimax.onboarding', $t->id) }}">@csrf
            <button class="btn-primary" type="submit" @disabled($busy)>{{ collect($sync['steps'])->contains(fn ($s) => $s['state'] === 'failed') ? 'Kuruluma başarısız adımdan devam et' : 'Kurulumu Başlat' }}</button>
            @if ($sync['next'])<span class="hint">Sıradaki adım: {{ \App\Commerce\Onboarding\TicimaxOnboarding::LABELS[$sync['next']] }}</span>@endif
        </form>
    @endif
<table>
    <thead><tr><th>Adım</th><th>Durum</th><th>Okunan</th><th>Eklenen</th><th>Güncellenen</th><th>Atlanan</th><th>Hata / deneme</th><th>API</th><th>Başlangıç</th><th>Bitiş / süre</th><th>Son başarılı</th><th></th></tr></thead>
    <tbody>@foreach ($sync['steps'] as $step => $s)
        @php([$label, $cls] = $stateBadges[$s['state']] ?? [$s['state'], 'b-muted'])
        @php($r = $s['run'])
        <tr id="step-{{ $step }}">
            <td>{{ $s['label'] }}@if ($r && $r['page_token'] && $s['state'] !== 'succeeded')<br><span class="muted">Kaldığı yer: <code>{{ \Illuminate\Support\Str::limit($r['page_token'], 40) }}</code></span>@endif</td>
            <td><span class="badge {{ $cls }}">{{ $label }}</span>@if ($s['done'] && $s['state'] !== 'succeeded')<br><span class="muted">Daha önce tamamlandı</span>@endif</td>
            <td>{{ $r['records_read'] ?? '—' }}</td><td>{{ $r['records_inserted'] ?? '—' }}</td><td>{{ $r['records_updated'] ?? '—' }}</td><td>{{ $r['records_skipped'] ?? '—' }}</td>
            <td>{{ $r['errors'] ?? '—' }}</td><td>{{ $r['api_calls'] ?? '—' }}</td>
            <td class="muted">{{ $fmt($r['started_at'] ?? null) }}</td>
            <td class="muted">{{ $fmt($r['finished_at'] ?? null) }}@if ($r['duration_ms'] ?? null) · {{ round($r['duration_ms'] / 1000, 1) }} sn @endif</td>
            <td class="muted">{{ $fmt($s['last_success_at']) }}</td>
            <td>@if ($step !== 'connection' && ! $s['busy'])
                <form method="post" action="{{ route('admin.tenants.ticimax.sync', [$t->id, $step]) }}">@csrf
                    <button type="submit">{{ $s['resumable'] ? 'Devam Et' : ($s['state'] === 'failed' ? 'Yeniden Dene' : ($s['done'] ? 'Yeniden çalıştır' : 'Başlat')) }}</button>
                </form>
            @endif</td>
        </tr>
        @if ($r && $r['error_message'])
            <tr><td></td><td colspan="11" class="muted">{{ $s['state'] === 'retrying' ? 'Yeniden denenecek: ' : 'Hata: ' }}{{ $r['error_message'] }}@if ($r['error_category']) <code>{{ $r['error_category'] }}</code>@endif</td></tr>
        @endif
    @endforeach</tbody>
</table>
    @if ($busy)
        <p class="hint">Çalışan adım var; sayfa 15 saniyede bir yenilenir.</p>
        <script nonce="{{ $cspNonce }}">setTimeout(function () { window.location.reload(); }, 15000);</script>
    @endif
</div>
@endif
@endsection
