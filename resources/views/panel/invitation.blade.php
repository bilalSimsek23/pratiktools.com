@extends('panel.auth-layout')
@section('title', 'Hesap etkinleştirme')
@section('content')
    <h1>{{ $invitation['tenant_name'] }}</h1>
    <p>{{ $invitation['email'] }} adresi için {{ \App\Http\Middleware\PanelTenant::ROLE_LABELS[$invitation['role']] ?? $invitation['role'] }} daveti.</p>
    @if ($invitation['new_account'])
        <form method="post" action="{{ $action }}">
            @csrf
            <label>Şifre belirleyin (en az 12 karakter) <input type="password" name="password" required minlength="12" autocomplete="new-password" autofocus></label>
            <label>Şifre (tekrar) <input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
            <button type="submit">Hesabı etkinleştir ve giriş yap</button>
        </form>
        <div class="foot">Şifrenizi yalnız siz belirlersiniz; PratikTools ekibi dahil kimse göremez. Bağlantı tek kullanımlıktır.</div>
    @elseif ($signedInAsInvitee)
        <form method="post" action="{{ $action }}">
            @csrf
            <button type="submit">Daveti kabul et</button>
        </form>
    @else
        <p>Bu davet mevcut bir hesaba ait. Daveti kabul etmek için önce bu hesapla giriş yapın; giriş sonrası bu sayfaya döneceksiniz.</p>
        <a href="{{ route('panel.login') }}"><button type="button">Giriş yap</button></a>
    @endif
@endsection
