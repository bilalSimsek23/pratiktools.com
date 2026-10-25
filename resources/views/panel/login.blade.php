@extends('panel.auth-layout')
@section('title', 'Giriş')
@section('content')
    <h1>Hoş geldiniz</h1>
    <p>Pazarlama yönetim paneline giriş yapın.</p>
    <form method="post" action="{{ route('panel.login.attempt') }}">
        @csrf
        <label>E-posta <input type="email" name="email" value="{{ old('email') }}" required autocomplete="username" autofocus></label>
        <label>Şifre <input type="password" name="password" required autocomplete="current-password"></label>
        <button type="submit">Giriş yap</button>
    </form>
    <div class="foot">Gerçek gönderimler, harici onaylar (İYS, Amazon SES) tamamlanana kadar kapalıdır.</div>
@endsection
