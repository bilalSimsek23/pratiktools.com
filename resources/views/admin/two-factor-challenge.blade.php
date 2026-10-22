@extends('admin.layout')
@section('title', 'Verify')
@section('content')
<form class="card" method="post" action="{{ route('admin.two-factor.verify') }}">
    @csrf
    <h1>Enter your code</h1>
    <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
    <button type="submit">Verify</button>
</form>
@endsection
