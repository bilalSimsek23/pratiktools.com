@extends('admin.layout')
@section('title', 'Set up two-factor authentication')
@section('content')
<form class="card" method="post" action="{{ route('admin.two-factor.confirm') }}">
    @csrf
    <h1>Two-factor authentication</h1>
    <p>Two-factor authentication is required. Add this key to an authenticator app, then enter the 6-digit code.</p>
    <p><code>{{ $secret }}</code></p>
    <p class="muted"><code>{{ $uri }}</code></p>
    <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
    <button type="submit">Enable</button>
</form>
@endsection
