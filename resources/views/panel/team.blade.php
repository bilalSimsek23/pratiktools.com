@extends('panel.layout')
@section('title', 'Ekip')
@section('content')
@php($labels = \App\Http\Middleware\PanelTenant::ROLE_LABELS)
<div class="page-head"><div><h1>Ayarlar · Ekip</h1>
    <p>Bu müşteri hesabının kullanıcıları. Davet bağlantısı tek kullanımlık ve sürelidir; kişi şifresini kendisi belirler. E-posta gönderimi kapalı olduğundan bağlantıyı siz iletirsiniz.</p></div></div>

@if (session('invitation.url'))
<div class="card">
    <h2>Davet bağlantısı (yalnız bir kez gösterilir)</h2>
    <p class="small">Geçerlilik: {{ \Illuminate\Support\Carbon::parse(session('invitation.expires_at'))->format('d.m.Y H:i') }}. Güvenli bir kanaldan iletin; kaybolursa “Yeni bağlantı” ile yenileyin.</p>
    <p><code>{{ session('invitation.url') }}</code></p>
</div>
@endif

<div class="card table-wrap stack-top">
    <table class="table">
        <thead><tr><th>Ad</th><th>E-posta</th><th>Rol</th><th>Durum</th>@if ($assignable !== [])<th>İşlemler</th>@endif</tr></thead>
        <tbody>
        @foreach ($members as $m)
            @php($manageable = $m->id !== $meId && in_array(\App\Tenancy\TenantRole::from($m->role), $assignable, true))
            <tr>
                <td>{{ $m->name }}@if ($m->id === $meId) <span class="small muted">(siz)</span>@endif</td>
                <td class="small">{{ $m->email }}</td>
                <td>@if ($manageable)
                        <form method="post" action="{{ route('panel.team.role', $m->id) }}" class="actions">@csrf
                            <select name="role" aria-label="Rol">@foreach ($assignable as $r)<option value="{{ $r->value }}" @selected($m->role === $r->value)>{{ $labels[$r->value] }}</option>@endforeach</select>
                            <button class="btn btn-sm" type="submit">Kaydet</button></form>
                    @else{{ $labels[$m->role] ?? $m->role }}@endif</td>
                <td>@if ($m->status === 'active')<span class="badge b-pass">Aktif</span>@elseif ($m->status === 'invited')<span class="badge b-warn">Davet bekliyor</span>@else<span class="badge b-bad">Devre dışı</span>@endif</td>
                @if ($assignable !== [])<td>@if ($manageable)
                    <div class="actions">
                    @if ($m->status === 'invited')<form method="post" action="{{ route('panel.team.invitation', $m->id) }}">@csrf<button class="btn btn-sm" type="submit">Yeni bağlantı</button></form>@endif
                    @if ($m->status !== 'disabled')<form method="post" action="{{ route('panel.team.status', $m->id) }}">@csrf<input type="hidden" name="status" value="disabled"><button class="btn btn-sm btn-danger" type="submit">Devre dışı bırak</button></form>
                    @else<form method="post" action="{{ route('panel.team.status', $m->id) }}">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-sm" type="submit">Etkinleştir</button></form>@endif
                    </div>
                @endif</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@if ($assignable !== [])
<div class="card stack-top">
    <h2>Kullanıcı davet et</h2>
    <form method="post" action="{{ route('panel.team.invite') }}" class="form-grid">@csrf
        <label>E-posta <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"></label>
        <label>Ad (yeni hesap için) <input type="text" name="name" maxlength="120" value="{{ old('name') }}"></label>
        <label>Rol <select name="role">@foreach ($assignable as $r)<option value="{{ $r->value }}" @selected(old('role', 'viewer') === $r->value)>{{ $labels[$r->value] }}</option>@endforeach</select></label>
        <div class="actions"><button class="btn btn-primary" type="submit">Davet oluştur</button></div>
        <p class="hint">Owner (mağaza sahibi) rolü buradan verilemez; sahiplik devri PratikTools platform yönetimi üzerinden yapılır.</p>
    </form>
</div>
@endif
@endsection
