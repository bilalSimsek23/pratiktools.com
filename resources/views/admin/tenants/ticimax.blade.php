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
        Yetki kodu (UyeKodu) şifreli kasada saklanır ve hiçbir zaman bu ekranda gösterilmez. Bu ekran senkron başlatmaz.</p>
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
@endsection
