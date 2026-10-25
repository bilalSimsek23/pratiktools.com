@extends('panel.layout')
@section('title', 'Ayarlar')
@section('content')
@php($roles = \App\Http\Middleware\PanelTenant::ROLE_LABELS)
<div class="page-head">
    <div><h1>Ayarlar</h1><p>Gönderim modu ve ekip.</p></div>
</div>
<div class="grid g2">
    <div class="card">
        <h2>Gönderim modu</h2>
        <form method="post" action="{{ route('panel.settings.sending-mode') }}" class="form-grid">
            @csrf
            <label class="field"><span><input type="radio" name="mode" value="off" @checked($tenantMode === 'off') @disabled(! $canChange)> <strong>Kapalı (OFF)</strong> — hiçbir ileti gönderilmez</span></label>
            <label class="field"><span><input type="radio" name="mode" value="internal" @checked($tenantMode === 'internal') @disabled(! $canChange)> <strong>Yalnızca test (TEST_ONLY)</strong> — sadece izinli test alıcıları</span></label>
            <label class="field"><span><input type="radio" disabled> <strong>Canlı (LIVE)</strong> — kapalı</span><span class="hint">{{ $reason }}</span></label>
            @if ($canChange)<div class="actions"><button class="btn" type="submit">Kaydet</button></div>@else<p class="small muted">Gönderim modunu yalnızca hesap sahibi değiştirebilir.</p>@endif
        </form>
        <ul class="list-plain small stack-top">
            <li class="split"><span>Etkin e-posta modu</span><span class="badge {{ $emailMode === 'off' ? 'b-muted' : 'b-warn' }}">{{ strtoupper($emailMode) }}</span></li>
            <li class="split"><span>Etkin push modu</span><span class="badge {{ $pushMode === 'off' ? 'b-muted' : 'b-warn' }}">{{ strtoupper($pushMode) }}</span></li>
            <li class="split"><span>Platform sınırı (e-posta / push)</span><span>{{ strtoupper($platformEmail) }} / {{ strtoupper($platformPush) }}</span></li>
        </ul>
        <p class="small muted">Etkin mod, hesap ayarı ile platform sınırının en kısıtlayıcı olanıdır.</p>
    </div>
    <div class="card table-wrap">
        <h2>Ekip</h2>
        <table class="table">
            <thead><tr><th>Ad</th><th>Rol</th><th>Durum</th></tr></thead>
            <tbody>
            @foreach ($members as $m)
                <tr><td>{{ $m->name }}</td><td>{{ $roles[$m->role] ?? $m->role }}</td><td><span class="badge {{ $m->status === 'active' ? 'b-pass' : 'b-muted' }}">{{ $m->status === 'active' ? 'Aktif' : 'Pasif' }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
        <p class="small muted">Onay kuralı: kampanyayı oluşturan veya onay isteyen kişi onaylayamaz. Canlı gönderim için iki farklı onaylayıcı gerekir.</p>
    </div>
</div>
@endsection
