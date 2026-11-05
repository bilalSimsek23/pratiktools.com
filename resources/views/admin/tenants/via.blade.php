@extends('admin.layout')
@section('title', $t->name.' · İYS / ViA')
@section('content')
@php($c = $config)
@php($badge = ['ready' => 'b-ok', 'incomplete' => 'b-warn', 'problem' => 'b-bad', 'not_configured' => 'b-muted'][$readiness->state])
<div class="page-head">
    <div><h1>İYS / ViA <span class="badge {{ $badge }}">{{ $readiness->label() }}</span></h1>
        <p class="lead">{{ $t->name }} · <code>{{ $t->slug }}</code> · yalnız bu müşterinin yapılandırması</p></div>
    <a class="btn" href="{{ route('admin.tenants.show', [$t->id, 'tab' => 'entegrasyonlar']) }}">← Entegrasyonlar</a>
</div>

<div class="card">
    <p class="muted">ViA Frame, ViA'nın resmî formudur: OTP'yi ve İYS kaydını ViA yönetir. PratikTools burada yalnız yapılandırmayı, hazırlık durumunu ve gömme kodunu tutar;
        ViA veya İYS'ye bağlanmaz, OTP göndermez, izin kaydı yazmaz. Tarayıcıdaki Frame olayları izin kanıtı sayılmaz.</p>
</div>

<div class="grid g2">
    <div class="card"><h2>Genel</h2>
        <dl class="kv">
            <dt>Sağlayıcı</dt><dd>ViA (İYS)</dd>
            <dt>İYS No</dt><dd>{{ $c?->iysNumber ?? '—' }}</dd>
            <dt>Marka</dt><dd>{{ $c?->brand ?? '—' }}</dd>
            <dt>Paket durumu</dt><dd>{{ ['active' => 'Aktif', 'inactive' => 'Pasif'][$c?->packageStatus] ?? 'Bilinmiyor' }}</dd>
            <dt>Form ID</dt><dd>@if ($c?->formId)<code>{{ $c->formId }}</code>@else — @endif</dd>
            <dt>Form tipi</dt><dd>{{ $c?->formType ?? '—' }}</dd>
            <dt>Onay tipi</dt><dd>{{ $c?->approvalType ?? '—' }}</dd>
            <dt>Form durumu</dt><dd>{{ $c?->formStatus->label() ?? '—' }}</dd>
            <dt>Frame origin'leri</dt><dd>@forelse ($c?->frameOrigins ?? [] as $o)<code>{{ $o }}</code><br>@empty — @endforelse</dd>
        </dl>
    </div>
    <div class="card"><h2>Hazırlık</h2>
        @if ($c === null)
            <p class="muted">Bu müşteri için ViA yapılandırması yok.</p>
        @else
            <ul class="list-plain">@foreach ($readiness->checks as $check)
                <li class="split"><span>{{ $check['label'] }}</span><span class="badge {{ $check['ok'] ? 'b-ok' : ($check['blocking'] ? 'b-bad' : 'b-warn') }}">{{ $check['ok'] ? 'Tamam' : ($check['blocking'] ? 'Sorun' : 'Eksik') }}</span></li>
            @endforeach</ul>
        @endif
        <p class="hint">"Yapılandırma hazır" yalnız kayıtlı bilgilerin eksiksiz olduğunu gösterir; ViA tarafında doğrulama yapılmaz. Sunucu tarafı OTP ve doğrudan İYS yazımı kapalıdır.</p>
    </div>
</div>

<div class="card"><h2>Yapılandırma</h2>
    @error('via')<div class="alert">{{ $message }}</div>@enderror
    <form class="form-grid" method="post" action="{{ route('admin.tenants.via.update', $t->id) }}">@csrf
        <label>İYS No <input type="text" name="iys_number" inputmode="numeric" maxlength="12" value="{{ old('iys_number', $c?->iysNumber) }}"></label>
        <label>Marka <input type="text" name="brand" maxlength="128" value="{{ old('brand', $c?->brand) }}"></label>
        <label>Paket <select name="package_status">
            <option value="">—</option>
            <option value="active" @selected(old('package_status', $c?->packageStatus) === 'active')>Aktif</option>
            <option value="inactive" @selected(old('package_status', $c?->packageStatus) === 'inactive')>Pasif</option>
        </select></label>
        <label>ViA Form ID (UUID) <input type="text" name="form_id" maxlength="64" autocomplete="off" value="{{ old('form_id', $c?->formId) }}"></label>
        <label>İzinli Frame origin'leri (her satıra bir tane, yalnız https://alanadi)
            <textarea name="frame_origins" rows="3" maxlength="2000">{{ old('frame_origins', implode("\n", $c?->frameOrigins ?? [])) }}</textarea></label>
        <p class="hint">Form tipi OTP, onay tipi ETK olarak kaydedilir. Form ID değişirse form durumu yeniden "Onay bekliyor" olur.</p>
        <div><button class="btn-primary" type="submit">Kaydet</button></div>
    </form>
</div>

@if ($c !== null)
<div class="card"><h2>Form durumu</h2>
    <p class="muted">İYS portalında gördüğünüz durumu kaydedin. "Onaylandı" yalnız portalda form "Yayında / Onaylandı" iken ve onay kutusu işaretlenerek kaydedilebilir.
        Geçişler: Taslak → Onay bekliyor → Onaylandı / Reddedildi; Onaylandı → Devre dışı.</p>
    @error('via_status')<div class="alert">{{ $message }}</div>@enderror
    <form class="form-grid" method="post" action="{{ route('admin.tenants.via.status', $t->id) }}">@csrf
        <label>Yeni durum <select name="status">@foreach ($statuses as $st)@if (in_array($st, $c->formStatus->next(), true))<option value="{{ $st->value }}">{{ $st->label() }}</option>@endif @endforeach</select></label>
        <label>Not <input type="text" name="note" required maxlength="255"></label>
        <label class="check"><input type="checkbox" name="portal_confirmed" value="1"> İYS portalında bu durumu gördüm</label>
        <div><button type="submit">Durumu kaydet</button></div>
    </form>
</div>
@endif

<div class="card"><h2>Gömme kodu</h2>
    @if ($snippet !== null)
        <p class="muted">Bu kodu yalnız izinli origin'lerdeki sayfaya ekleyin. Kod yalnız ViA'nın herkese açık Form ID'sini içerir.</p>
        <pre id="via-snippet">{{ $snippet }}</pre>
        <button type="button" data-copy="via-snippet">Kopyala</button>
        <script nonce="{{ $cspNonce }}">
            document.querySelectorAll('[data-copy]').forEach(function (b) {
                b.addEventListener('click', function () {
                    navigator.clipboard.writeText(document.getElementById(b.dataset.copy).textContent).then(function () { b.textContent = 'Kopyalandı'; });
                });
            });
        </script>
    @else
        <p class="muted">Gömme kodu, yapılandırma hazır olduğunda gösterilir.</p>
    @endif
</div>
@endsection
