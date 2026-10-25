@extends('panel.auth-layout')
@section('title', 'Mağaza seçin')
@section('content')
    <h1>Mağaza seçin</h1>
    <p>Hangi mağazada çalışmak istiyorsunuz?</p>
    @forelse ($tenants as $t)
        <form method="post" action="{{ route('panel.tenants.choose') }}">
            @csrf<input type="hidden" name="tenant" value="{{ $t->tenant_id }}">
            <button type="submit">{{ $t->name }}</button>
        </form><br>
    @empty
        <div class="alert">Bu hesabın aktif bir mağaza üyeliği yok.</div>
    @endforelse
    <form method="post" action="{{ route('panel.logout') }}">@csrf<button type="submit">Çıkış</button></form>
@endsection
