@extends('admin.layout')
@section('title', 'Yeni Müşteri')
@section('content')
<div class="page-head"><div><h1>Yeni Müşteri</h1>
    <p class="lead">Müşteri, owner üyeliği ve veri şifreleme anahtarı tek işlemde oluşturulur. Owner'a tek kullanımlık, süreli bir hesap etkinleştirme bağlantısı verilir; şifresini kendisi belirler. Entegrasyonlar “yapılandırılmadı” olarak başlar; web sitesi sahipliği değişmez.</p></div></div>
<form class="card form-grid" method="post" action="{{ route('admin.tenants.store') }}">
    @csrf
    <label>Şirket adı <input type="text" name="name" maxlength="160" required value="{{ old('name') }}" data-slug-source></label>
    <label>Müşteri kodu (slug) <input type="text" name="slug" maxlength="63" required pattern="[a-z0-9][a-z0-9-]{1,62}" value="{{ old('slug') }}" data-slug-target>
        <span class="hint">Küçük harf, rakam ve tire. Şirket adından önerilir; kaydetmeden önce değiştirebilirsiniz. Sonradan değiştirilemez.</span></label>
    <label>Owner adı <input type="text" name="owner_name" maxlength="120" required value="{{ old('owner_name') }}"></label>
    <label>Owner e-posta <input type="email" name="owner_email" maxlength="255" required value="{{ old('owner_email') }}">
        <span class="hint">Bu adresle zaten bir hesap varsa, o kişi kendi hesabıyla giriş yapıp daveti kabul eder.</span></label>
    <label>Telefon (isteğe bağlı) <input type="text" name="contact_phone" maxlength="24" value="{{ old('contact_phone') }}"></label>
    <label>Platform notu (isteğe bağlı) <textarea name="platform_note" rows="3" maxlength="1000">{{ old('platform_note') }}</textarea>
        <span class="hint">Yalnız platform yöneticileri görür; müşteri okuyamaz. Şifre, anahtar veya token yazmayın.</span></label>
    <div><button class="btn-primary" type="submit">Müşteriyi oluştur</button> <a class="btn" href="{{ route('admin.tenants') }}">Vazgeç</a></div>
</form>
<script nonce="{{ $cspNonce }}">
(() => {
    const source = document.querySelector('[data-slug-source]'), target = document.querySelector('[data-slug-target]');
    let touched = target.value !== '';
    target.addEventListener('input', () => { touched = true; });
    const map = { 'ç': 'c', 'ğ': 'g', 'ı': 'i', 'i̇': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u', 'â': 'a', 'î': 'i', 'û': 'u' };
    source.addEventListener('input', () => {
        if (touched) return;
        target.value = source.value.toLocaleLowerCase('tr-TR').replace(/[çğıöşüâîû]|i̇/g, c => map[c] || c)
            .normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 63);
    });
})();
</script>
@endsection
