@extends('admin.layout')
@section('title', 'Hesap etkinleştirme')
@section('content')
<form class="card login" method="post" action="{{ $action }}">
    @csrf
    <h1>Platform Admin</h1>
    <p>{{ $name }} · {{ $email }}</p>
    <label>Şifre belirleyin (en az 16 karakter) <input type="password" name="password" required minlength="16" maxlength="255" autocomplete="new-password" autofocus></label>
    <label>Şifre (tekrar) <input type="password" name="password_confirmation" required minlength="16" maxlength="255" autocomplete="new-password"></label>
    <button type="submit">Hesabı etkinleştir</button>
    <p class="muted">Bağlantı tek kullanımlıktır. Sonraki adımda iki adımlı doğrulama (2FA) kurulumu zorunludur.</p>
</form>
@endsection
