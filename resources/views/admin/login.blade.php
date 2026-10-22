@extends('admin.layout')
@section('title', 'Sign in')
@section('content')
<form class="card" method="post" action="{{ route('admin.login.attempt') }}">
    @csrf
    <h1>Platform Admin</h1>
    <input type="email" name="email" autocomplete="username" placeholder="Email" required>
    <input type="password" name="password" autocomplete="current-password" placeholder="Password" required>
    <button type="submit">Continue</button>
</form>
@endsection
