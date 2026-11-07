@extends('admin.layout')
@section('title', 'Sistem Durumu')
@section('content')
<div class="page-head"><div><h1>Sistem Durumu <span class="badge {{ $health['status'] === 'ok' ? 'b-ok' : 'b-bad' }}">{{ $health['status'] }}</span></h1>
    <p class="lead">Altyapı bileşenleri. Gizli değer gösterilmez.</p></div></div>
<div class="card table-wrap">
<table>
    <thead><tr><th>Bileşen</th><th>Durum</th><th>Ayrıntı</th></tr></thead>
    <tbody>
    @foreach ($health['checks'] as $name => $check)
        <tr>
            <td>{{ $name }}</td>
            <td class="{{ $check['status'] === 'ok' ? 'ok' : 'fail' }}">{{ $check['status'] }}</td>
            <td class="muted">
                @isset($check['latency_ms']) {{ $check['latency_ms'] }} ms @endisset
                @isset($check['horizon']) Horizon: {{ $check['horizon'] }} @endisset
                @isset($check['backlog'])
                    · backlog @foreach ($check['backlog'] as $queue => $size) {{ $queue }}={{ $size }} @endforeach
                @endisset
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>

@php($badges = ['off' => ['🔴 Kapalı', 'b-bad'], 'test_only' => ['🟡 Yalnızca Test', 'b-warn'], 'live' => ['🟢 Canlı', 'b-ok']])
@php($b = fn ($m) => $badges[$m->value ?? $m] ?? [$m, 'b-muted'])
<div class="card" id="eposta-gonderim-modu"><h2>E-posta Gönderim Modu <span class="badge {{ $b($email['effective'])[1] }}">{{ $b($email['effective'])[0] }}</span></h2>
    <p class="muted">Etkin platform modu, ortam üst sınırı (<code>EMAIL_SENDING_MODE</code>) ile bu paneldeki ayarın daha kısıtlayıcısıdır. Her müşteri için ayrıca müşterinin kendi modu uygulanır:
        etkin mod daima en kısıtlayıcı olandır. Yalnızca Test modunda e-posta yalnız aktif test alıcılarına gidebilir. send_email yetkisi, İYS, suppression ve diğer bütün kurallar her durumda ayrıca geçerlidir.</p>
    @error('email_mode')<div class="alert">{{ $message }}</div>@enderror
    <dl class="kv">
        <dt>Ortam üst sınırı</dt><dd><span class="badge {{ $b($email['ceiling'])[1] }}">{{ $b($email['ceiling'])[0] }}</span> <span class="muted">Laravel Cloud ortam ayarı; yalnız oradan değişir.</span></dd>
        <dt>Panel ayarı</dt><dd><span class="badge {{ $b($email['stored'])[1] }}">{{ $b($email['stored'])[0] }}</span>@if ($email['updated_at']) <span class="muted">· {{ \Illuminate\Support\Carbon::parse($email['updated_at'])->format('d.m.Y H:i') }}@if ($email['updated_by_name']) · {{ $email['updated_by_name'] }}@endif</span>@endif</dd>
        <dt>Etkin platform modu</dt><dd><span class="badge {{ $b($email['effective'])[1] }}">{{ $b($email['effective'])[0] }}</span></dd>
    </dl>
    <div class="grid g2">
        <form method="post" action="{{ route('admin.system.email-sending-mode') }}">@csrf
            <input type="hidden" name="mode" value="off">
            <button type="submit" @disabled($email['stored']->value === 'off')>🔴 Kapalı yap</button>
        </form>
        <form method="post" action="{{ route('admin.system.email-sending-mode') }}">@csrf
            <input type="hidden" name="mode" value="test_only">
            <button type="submit" @disabled($email['stored']->value === 'test_only' || $email['ceiling']->rank() < 1)>🟡 Yalnızca Test yap</button>
            @if ($email['ceiling']->rank() < 1)<p class="hint">Ortam üst sınırı kapalı olduğu için açılamaz.</p>@endif
        </form>
    </div>
    <details @if ($errors->has('email_mode')) open @endif>
        <summary>🟢 Canlı moda geç (kritik)</summary>
        @if ($email['ceiling']->rank() < 2)
            <p class="hint">Ortam üst sınırı canlı moda izin vermiyor; önce Laravel Cloud'da <code>EMAIL_SENDING_MODE=live</code> yapılmalı.</p>
        @else
        <div class="alert">Canlı modda, müşteri modu da canlı olan her müşteri gerçek alıcılara e-posta gönderebilir (send_email yetkisi, İYS onayı ve diğer kurallar geçerli kalır).</div>
        <form class="form-grid" method="post" action="{{ route('admin.system.email-sending-mode') }}" autocomplete="off">@csrf
            <input type="hidden" name="mode" value="live">
            <label><input type="checkbox" name="acknowledge" value="1"> Canlı gönderimin gerçek müşterilere e-posta gidebileceği anlamına geldiğini anlıyorum.</label>
            <label>Onay ifadesini aynen yazın: <code>{{ $phrase }}</code> <input type="text" name="phrase" maxlength="64" autocomplete="off"></label>
            <label>Şifreniz <input type="password" name="password" maxlength="255" autocomplete="current-password"></label>
            <label>2FA kodu <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="10"></label>
            <div><button class="btn-primary" type="submit">Canlı moda geç</button></div>
        </form>
        @endif
    </details>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Müşteri</th><th>Müşteri modu</th><th>Etkin mod</th><th>Neden</th></tr></thead>
        <tbody>@forelse ($tenants as $t)
            <tr><td>{{ $t['name'] }} <span class="muted">· {{ $t['slug'] }}</span></td><td><code>{{ strtoupper($t['mode']) }}</code></td>
                <td><span class="badge {{ $b($t['effective'])[1] }}">{{ $b($t['effective'])[0] }}</span></td><td class="muted">{{ $t['reason'] }}</td></tr>
        @empty<tr><td colspan="4" class="muted">Müşteri yok.</td></tr>@endforelse</tbody>
    </table>
    </div>
</div>
@endsection
