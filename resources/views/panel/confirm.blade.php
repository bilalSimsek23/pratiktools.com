@extends('panel.auth-layout')
@section('title', 'Şifre doğrulama')
@section('content')
    <h1>Şifrenizi doğrulayın</h1>
    <p>Onay, gönderim, yayınlama ve benzeri hassas işlemler için kimliğinizi yeniden doğrulamanız gerekir.</p>
    <form method="post" action="{{ route('panel.confirm.attempt') }}">
        @csrf
        <label>Şifre <input type="password" name="password" required autocomplete="current-password" autofocus></label>
        <button type="submit">Doğrula</button>
    </form>
@endsection
