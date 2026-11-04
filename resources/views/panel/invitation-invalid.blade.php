@extends('panel.auth-layout')
@section('title', 'Davet bağlantısı')
@section('content')
    <h1>Bağlantı kullanılamıyor</h1>
    <p>Bu davet bağlantısı geçersiz, süresi dolmuş, daha önce kullanılmış veya iptal edilmiş. Yeni bir bağlantı için sizi davet eden kişiyle iletişime geçin.</p>
    <a href="{{ route('panel.login') }}">Giriş sayfası</a>
@endsection
