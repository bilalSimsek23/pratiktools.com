@extends('panel.layout')
@section('title', $f->name)
@section('content')
@php
    $c = $report['counters'];
    $placements = ['popup' => 'Popup', 'footer' => 'Footer', 'landing_page' => 'Landing page', 'checkout' => 'Ödeme sayfası'];
    $snippet = "<form id=\"pt-lead\">\n  <input type=\"email\" name=\"email\" required>\n  <input type=\"text\" name=\"website\" tabindex=\"-1\" autocomplete=\"off\" hidden>\n  <label><input type=\"checkbox\" name=\"consent\"> İzin metni</label>\n  <button>Kaydol</button>\n</form>\n<script>\ndocument.getElementById('pt-lead').addEventListener('submit', async (e) => {\n  e.preventDefault();\n  const f = e.target;\n  const r = await fetch('".$endpoint."', {\n    method: 'POST',\n    headers: { 'Content-Type': 'application/json' },\n    body: JSON.stringify({\n      email: f.email.value, consent: f.consent.checked, website: f.website.value,\n      submission_id: crypto.randomUUID(), page_url: location.href\n    })\n  });\n  const data = await r.json();\n  f.replaceWith(data.message);\n});\n</script>";
@endphp
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.forms') }}">Lead formları</a> /</div>
        <h1>{{ $f->name }} <span class="badge {{ $f->active ? 'b-pass' : 'b-muted' }}">{{ $f->active ? 'Aktif' : 'Pasif' }}</span></h1>
        <p>{{ $placements[$f->placement] ?? $f->placement }} · Public ID <code>{{ $f->public_id }}</code> · İzin metni v{{ $f->consent_version }}{{ $f->double_opt_in ? ' · Çift onay' : '' }}</p></div>
    @if ($canManage)
    <div class="actions">
        <form class="inline" method="post" action="{{ route('panel.forms.active', $f->id) }}">@csrf<input type="hidden" name="active" value="{{ $f->active ? 0 : 1 }}">
            <button class="btn {{ $f->active ? 'btn-danger' : 'btn-primary' }}" type="submit">{{ $f->active ? 'Pasifleştir' : 'Aktifleştir' }}</button></form>
    </div>
    @endif
</div>
<div class="grid g4">
    <div class="card kpi"><div class="label">Başvuru (30 gün)</div><div class="value">{{ number_format($c['submissions'] ?? 0) }}</div></div>
    <div class="card kpi"><div class="label">Kabul edilen</div><div class="value">{{ number_format($c['accepted'] ?? 0) }}</div><div class="sub">{{ number_format($report['new_recipients']) }} yeni alıcı</div></div>
    <div class="card kpi"><div class="label">İzin işaretli</div><div class="value">{{ number_format($report['consent_checked']) }}</div><div class="sub">{{ number_format($report['consent_unchecked']) }} işaretsiz</div></div>
    <div class="card kpi"><div class="label">Reddedilen</div><div class="value">{{ number_format(($c['bot_suspected'] ?? 0) + ($c['origin_refused'] ?? 0) + ($c['invalid'] ?? 0)) }}</div>
        <div class="sub">bot {{ $c['bot_suspected'] ?? 0 }} · origin {{ $c['origin_refused'] ?? 0 }} · geçersiz {{ $c['invalid'] ?? 0 }}</div></div>
</div>
<div class="grid g2 stack-top">
    <div class="card">
        <h2>Entegrasyon</h2>
        <p class="small"><span class="muted">Uç nokta:</span> <code>POST {{ $endpoint }}</code></p>
        <p class="small"><span class="muted">İzinli adresler:</span> @forelse ($origins as $o)<code>{{ $o }}</code>@if (! $loop->last), @endif @empty — @endforelse</p>
        <p class="small muted">Sitenize eklenecek örnek kod. Tarayıcı yalnızca izinli adreslerden gönderim yapabilir; tüm yanıtlar aynıdır (adres sorgulanamaz).</p>
        <pre class="snippet">{{ $snippet }}</pre>
    </div>
    <div class="card form-grid">
        <h2>İzin metni (v{{ $f->consent_version }})</h2>
        @if ($canManage)
            <form method="post" action="{{ route('panel.forms.consent', $f->id) }}" class="form-grid">@csrf
                <textarea name="consent_text" maxlength="4000" rows="6" required>{{ old('consent_text', $f->consent_text) }}</textarea>
                <p class="hint">Değişiklik yeni versiyon oluşturur; önceki başvuruların kanıtı eski metinle kalır.</p>
                <div class="actions"><button class="btn" type="submit">Yeni versiyon olarak kaydet</button></div>
            </form>
        @else
            <p>{{ $f->consent_text }}</p>
        @endif
        <p class="small"><span class="muted">Başarı mesajı:</span> {{ $f->success_message }}</p>
        <p class="small"><span class="muted">İYS'ye yazılmayı bekleyen ONAY:</span> {{ number_format($report['pending_iys_onay']) }} <span class="muted">(İYS yazımı kapalı)</span></p>
        <p class="small"><span class="muted">ViA onayı bekleyen:</span> {{ number_format($report['via_pending']) }} · <span class="muted">ViA ile tamamlanan:</span> {{ number_format($report['via_succeeded']) }} · <span class="muted">başarısız/süresi dolan:</span> {{ number_format($report['via_failed']) }}</p>
        <h2>İzin modu</h2>
        <p class="small">{{ ($f->consent_mode ?? 'local_only') === 'via' ? 'ViA: pazarlama izni yalnızca İYS ViA onayı başarıyla tamamlanınca ONAY olur.' : 'Yalnızca yerel: işaretli kutu yerel kanıttır; İYS ONAY değildir.' }}</p>
        @if ($canManage)
            <form method="post" action="{{ route('panel.forms.consent-mode', $f->id) }}" class="actions">@csrf
                <select name="consent_mode"><option value="local_only" @selected(($f->consent_mode ?? 'local_only') === 'local_only')>Yalnızca yerel</option><option value="via" @selected(($f->consent_mode ?? '') === 'via')>ViA (İYS)</option></select>
                <button class="btn" type="submit">Kaydet</button>
            </form>
        @endif
    </div>
</div>
@endsection
