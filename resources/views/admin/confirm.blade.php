@extends('admin.layout')
@section('title', 'Şifre doğrulama')
@section('content')
<form class="card login" method="post" action="{{ route('admin.confirm.attempt') }}">
    @csrf
    <h1>Şifrenizi doğrulayın</h1>
    <p class="muted">Müşteri değişiklikleri için şifrenizi yeniden girmeniz gerekir.</p>
    <label>Şifre <input type="password" name="password" required autocomplete="current-password" autofocus></label>
    <button class="btn-primary" type="submit">Doğrula</button>
</form>
@endsection
