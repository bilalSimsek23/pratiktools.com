@extends('admin.layout')
@section('title', 'Bağlantı kullanılamıyor')
@section('content')
<div class="card login">
    <h1>Bağlantı kullanılamıyor</h1>
    <p>Bu etkinleştirme bağlantısı geçersiz, süresi dolmuş, daha önce kullanılmış veya yenilenmiş. Yeni bir bağlantı için platform yöneticisine başvurun.</p>
    <a href="{{ route('admin.login') }}">Giriş sayfası</a>
</div>
@endsection
