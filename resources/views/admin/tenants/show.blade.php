@extends('admin.layout')
@section('title', $t->name)
@section('content')
<div class="page-head">
    <div><h1>{{ $t->name }} <span class="badge {{ $t->status === 'active' ? 'b-ok' : 'b-bad' }}">{{ $t->status === 'active' ? 'Aktif' : 'Askıda' }}</span>
        @if ($t->site_owner)<span class="badge">pratiktools.com sahibi</span>@endif</h1>
        <p class="lead"><code>{{ $t->slug }}</code> · {{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d.m.Y H:i') }} tarihinde oluşturuldu</p></div>
    <a class="btn" href="{{ route('admin.tenants') }}">← Müşteriler</a>
</div>

@if (session('invitation.url'))
<div class="card">
    <h2>Davet bağlantısı (yalnız bir kez gösterilir)</h2>
    <p class="muted">@if (session('invitation.email')){{ session('invitation.email') }} için · @endif Geçerlilik: {{ \Illuminate\Support\Carbon::parse(session('invitation.expires_at'))->format('d.m.Y H:i') }}.
        E-posta gönderimi kapalı olduğundan bağlantıyı güvenli bir kanaldan kendiniz iletin. Tek kullanımlıktır; kaybolursa “Yeni bağlantı” ile yenileyin (eskisi iptal olur).</p>
    <span class="secret-link">{{ session('invitation.url') }}</span>
</div>
@endif

<nav class="tabs" aria-label="Müşteri sekmeleri">
    @foreach ($tabs as $key => $label)<a href="{{ route('admin.tenants.show', [$t->id, 'tab' => $key]) }}" @class(['active' => $tab === $key])>{{ $label }}</a>@endforeach
</nav>

@if ($tab === 'genel')
<div class="grid g2">
    <div class="card"><h2>Genel</h2>
        <dl class="kv">
            <dt>Şirket adı</dt><dd>{{ $t->name }}</dd>
            <dt>Kod</dt><dd><code>{{ $t->slug }}</code></dd>
            <dt>Durum</dt><dd>{{ $t->status === 'active' ? 'Aktif' : 'Askıda' }}@if ($t->suspended_at) · {{ \Illuminate\Support\Carbon::parse($t->suspended_at)->format('d.m.Y H:i') }} — {{ $t->suspension_reason }}@endif</dd>
            <dt>Owner</dt><dd>{{ $t->owner->name ?? '—' }} {{ isset($t->owner->email) ? '· '.$t->owner->email : '' }}@if (($t->owner->status ?? null) === 'invited') <span class="badge b-warn">davet bekliyor</span>@endif</dd>
            <dt>Kullanıcı</dt><dd>{{ $members->where('status', 'active')->count() }} aktif · {{ $members->where('status', 'invited')->count() }} davetli</dd>
            <dt>Telefon</dt><dd>{{ $t->contact_phone ?: '—' }}</dd>
            <dt>Plan</dt><dd class="muted">{{ $t->plan ?: 'Tanımlı değil (ödeme/faturalama yok)' }}</dd>
            <dt>Bölge</dt><dd>{{ $t->country }} · {{ $t->timezone }} · {{ $t->currency }} · {{ $t->locale }}</dd>
        </dl>
    </div>
    <div class="card"><h2>Temel bilgileri düzenle</h2>
        <p class="muted">Müşteri kimliği (ID), webhook kimliği, owner, durum ve veri anahtarı buradan değişmez. Değişiklikler audit'e yazılır.</p>
        @error('metadata')<div class="alert">{{ $message }}</div>@enderror
        <form class="form-grid" method="post" action="{{ route('admin.tenants.metadata', $t->id) }}">@csrf
            <label>Şirket adı <input type="text" name="name" required maxlength="160" value="{{ old('name', $t->name) }}"></label>
            @if ($t->site_owner)
                <input type="hidden" name="slug" value="{{ $t->slug }}">
                <p class="hint">Kod (<code>{{ $t->slug }}</code>) değiştirilemez: web sitesi sahibini koduyla bulur.</p>
            @else
                <label>Kod <input type="text" name="slug" required maxlength="63" pattern="[a-z0-9][a-z0-9-]{1,62}" value="{{ old('slug', $t->slug) }}"></label>
                <label>Kod değişiyorsa yeni kodu tekrar yazın <input type="text" name="slug_confirmation" maxlength="63" autocomplete="off" value=""></label>
                <p class="hint">Kod yalnız operatör komutlarında (<code>--tenant=</code>) kullanılır; ilişkiler ve bağlantılar müşteri kimliğine bağlıdır. Eski kod serbest kalır.</p>
            @endif
            <label>Telefon <input type="text" name="contact_phone" maxlength="32" value="{{ old('contact_phone', $t->contact_phone) }}"></label>
            <label>Ülke (ISO) <input type="text" name="country" required maxlength="2" value="{{ old('country', $t->country) }}"></label>
            <label>Saat dilimi <input type="text" name="timezone" required maxlength="64" value="{{ old('timezone', $t->timezone) }}"></label>
            <label>Para birimi (ISO) <input type="text" name="currency" required maxlength="3" value="{{ old('currency', $t->currency) }}"></label>
            <label>Dil <input type="text" name="locale" required maxlength="16" value="{{ old('locale', $t->locale) }}"></label>
            <div><button class="btn-primary" type="submit">Kaydet</button></div>
        </form>
    </div>
    <div class="card"><h2>Platform notu</h2>
        <p class="muted">Yalnız PratikTools platform yöneticileri görür; müşteri hiçbir yoldan okuyamaz. Şifre, anahtar veya token yazmayın.</p>
        @if (session('note_error'))<div class="alert">{{ session('note_error') }}</div>@endif
        <form class="form-grid" method="post" action="{{ route('admin.tenants.note', $t->id) }}">@csrf
            <textarea name="note" rows="4" maxlength="1000" aria-label="Platform notu">{{ old('note', $t->platform_note) }}</textarea>
            <div><button type="submit">Notu kaydet</button></div>
        </form>
    </div>
    <div class="card"><h2>Durum</h2>
        @if ($t->status === 'active')
            <p class="muted">Askıya alınan müşteri /app'e giremez; kampanya, otomasyon, gönderim ve entegrasyon senkronu çalışmaz. Veriler silinmez; tekrar aktif edilebilir.</p>
            @if ($t->site_owner)<p><span class="badge b-warn">Web sitesi sahibi askıya alınamaz.</span></p>@else
            <form class="form-grid" method="post" action="{{ route('admin.tenants.status', $t->id) }}">@csrf
                <input type="hidden" name="action" value="suspend">
                <label>Neden <input type="text" name="reason" maxlength="255" required></label>
                <label>Onay için müşteri kodunu yazın ({{ $t->slug }}) <input type="text" name="confirm_slug" required autocomplete="off"></label>
                <div><button class="btn-danger" type="submit">Askıya al</button></div>
            </form>@endif
        @else
            <form class="form-grid" method="post" action="{{ route('admin.tenants.status', $t->id) }}">@csrf
                <input type="hidden" name="action" value="reactivate">
                <label>Onay için müşteri kodunu yazın ({{ $t->slug }}) <input type="text" name="confirm_slug" required autocomplete="off"></label>
                <div><button class="btn-primary" type="submit">Yeniden aktif et</button></div>
            </form>
        @endif
    </div>
</div>
@endif

@if ($tab === 'kullanicilar')
<div class="card table-wrap"><h2>Kullanıcılar</h2>
<table>
    <thead><tr><th>Ad</th><th>E-posta</th><th>Rol</th><th>Durum</th><th>İşlemler</th></tr></thead>
    <tbody>
    @foreach ($members as $m)
        <tr>
            <td>{{ $m->name }}</td><td>{{ $m->email }}</td>
            <td><form class="inline" method="post" action="{{ route('admin.tenants.members.role', [$t->id, $m->membership_id]) }}">@csrf
                <select name="role" aria-label="Rol">@foreach ($roles as $r)<option value="{{ $r->value }}" @selected($m->role === $r->value)>{{ \App\Http\Middleware\PanelTenant::ROLE_LABELS[$r->value] }} ({{ $r->value }})</option>@endforeach</select>
                <button type="submit">Kaydet</button></form></td>
            <td>@if ($m->status === 'active')<span class="badge b-ok">Aktif</span>@elseif ($m->status === 'invited')<span class="badge b-warn">Davet bekliyor</span>
                @if ($m->invitation_expires_at)<span class="hint">bitiş {{ \Illuminate\Support\Carbon::parse($m->invitation_expires_at)->format('d.m H:i') }}</span>@else<span class="hint">bağlantı yok/süresi doldu</span>@endif
                @else<span class="badge b-muted">Devre dışı</span>@endif</td>
            <td>
                @if ($m->status === 'invited')<form class="inline" method="post" action="{{ route('admin.tenants.members.invitation', [$t->id, $m->membership_id]) }}">@csrf<button type="submit">Yeni bağlantı</button></form>@endif
                @if ($m->status !== 'disabled')<form class="inline" method="post" action="{{ route('admin.tenants.members.status', [$t->id, $m->membership_id]) }}">@csrf<input type="hidden" name="status" value="disabled"><button class="btn-danger" type="submit">Devre dışı bırak</button></form>
                @else<form class="inline" method="post" action="{{ route('admin.tenants.members.status', [$t->id, $m->membership_id]) }}">@csrf<input type="hidden" name="status" value="active"><button type="submit">Etkinleştir</button></form>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
<div class="card"><h2>Kullanıcı davet et</h2>
    <form class="form-grid" method="post" action="{{ route('admin.tenants.members.store', $t->id) }}">@csrf
        <label>E-posta <input type="email" name="email" required maxlength="255" value="{{ old('email') }}"></label>
        <label>Ad (yeni hesap için) <input type="text" name="name" maxlength="120" value="{{ old('name') }}"></label>
        <label>Rol <select name="role">@foreach ($roles as $r)<option value="{{ $r->value }}" @selected(old('role', 'viewer') === $r->value)>{{ \App\Http\Middleware\PanelTenant::ROLE_LABELS[$r->value] }} ({{ $r->value }})</option>@endforeach</select></label>
        <div><button class="btn-primary" type="submit">Davet oluştur</button></div>
        <p class="hint">Platform yöneticisi hiçbir kullanıcının şifresini belirlemez ve müşteriye üye olmaz.</p>
    </form>
</div>
@endif

@if ($tab === 'entegrasyonlar')
@if ($ticimax !== null)
@php($ready = ['ready' => 'b-ok', 'syncing' => 'b-warn', 'action_required' => 'b-bad'][$ticimax['overall']] ?? 'b-muted')
<div class="card" id="ticimax-hazirlik"><h2>Ticimax Hazırlık <span class="badge {{ $ready }}">{{ \App\Commerce\Onboarding\TicimaxOnboarding::OVERALL[$ticimax['overall']] }}</span></h2>
    @if ($ticimax['steps'] === [])
        <p class="muted">Ticimax entegrasyonu yapılandırılmadı.</p>
    @else
        <ul class="list-plain">@foreach ($ticimax['steps'] as $s)
            <li class="split"><span>{{ $s['label'] }}</span><span>{{ $s['done'] ? '✅' : (in_array($s['state'], ['failed'], true) ? '❌' : ($s['busy'] ? '⏳' : '—')) }}</span></li>
        @endforeach</ul>
    @endif
    <p><a class="btn" href="{{ route('admin.tenants.ticimax', $t->id) }}#veri-senkronizasyonu">Veri senkronizasyonu</a></p>
</div>
@endif
<div class="card table-wrap"><h2>Entegrasyonlar</h2>
    <p class="muted">Yalnız durum gösterilir; secret, anahtar veya ayar değeri bu alanda hiçbir zaman gösterilmez.</p>
<table>
    <thead><tr><th>Entegrasyon</th><th>Durum</th><th>Son doğrulama</th></tr></thead>
    <tbody>@foreach ($integrations as $provider => $i)
        <tr><td>@if ($provider === 'iys')<a href="{{ route('admin.tenants.via', $t->id) }}">{{ $i['label'] }}</a>@elseif ($provider === 'ticimax')<a href="{{ route('admin.tenants.ticimax', $t->id) }}">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif</td><td>@include('admin.tenants._state', ['state' => $i['state']])</td>
            <td class="muted">{{ $i['last_verified_at'] ? \Illuminate\Support\Carbon::parse($i['last_verified_at'])->format('d.m.Y H:i') : '—' }}</td></tr>
    @endforeach</tbody>
</table>
</div>
@endif

@if ($tab === 'kullanim')
<div class="grid g4">
    <div class="card kpi"><div class="label">Kampanya</div><div class="value">{{ array_sum($usage['campaigns']) }}</div>
        <div class="muted">@foreach ($usage['campaigns'] as $st => $n){{ $st }}: {{ $n }} @endforeach</div></div>
    <div class="card kpi"><div class="label">Otomasyon</div><div class="value">{{ $usage['automations'] }}</div><div class="muted">{{ $usage['automations_published'] }} yayında</div></div>
    <div class="card kpi"><div class="label">Lead (30 gün)</div><div class="value">{{ $usage['leads_30d'] }}</div><div class="muted">toplam {{ $usage['leads_total'] }}</div></div>
    <div class="card kpi"><div class="label">Gönderim planı</div><div class="value">{{ $usage['dispatches'] }}</div></div>
</div>
<div class="card"><dl class="kv">
    <dt>Son senkron</dt><dd>{{ $usage['last_sync'] ? \Illuminate\Support\Carbon::parse($usage['last_sync'])->format('d.m.Y H:i') : '—' }}</dd>
    <dt>Son aktivite</dt><dd>{{ $usage['last_activity'] ? \Illuminate\Support\Carbon::parse($usage['last_activity'])->format('d.m.Y H:i') : '—' }}</dd>
</dl></div>
@endif

@if ($tab === 'audit')
<div class="card table-wrap"><h2>Audit (son 50)</h2>
    <p class="muted">Eylem, aktör türü ve zaman. Ayrıntı alanları ve kişisel veri gösterilmez.</p>
<table>
    <thead><tr><th>Zaman</th><th>Eylem</th><th>Aktör</th><th>Kaynak</th></tr></thead>
    <tbody>@forelse ($audit as $a)
        <tr><td class="muted">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('d.m.Y H:i:s') }}</td><td><code>{{ $a->action }}</code></td><td>{{ $a->actor_type }}</td><td class="muted">{{ $a->resource_type ?? '—' }}</td></tr>
    @empty<tr><td colspan="4" class="muted">Kayıt yok.</td></tr>@endforelse</tbody>
</table>
</div>
@endif
@endsection
