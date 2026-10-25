@extends('panel.layout')
@section('title', $campaign ? 'Kampanyayı düzenle' : 'Yeni kampanya')
@section('content')
<div class="page-head">
    <div><h1>{{ $campaign ? 'Kampanyayı düzenle' : 'Yeni kampanya' }}</h1><p>Bilgiler → Kitle → İçerik → Gönderici. Kaydettikten sonra önizleme, hazırlık ve onay adımları gelir.</p></div>
</div>
<div class="steps"><span class="now">1 Bilgiler</span><span class="now">2 Kitle</span><span class="now">3 İçerik</span><span class="now">4 Gönderici</span><span>5 Önizleme</span><span>6 Hazırlık</span><span>7 Onay</span><span>8 Gönderim</span></div>
<form method="post" action="{{ $campaign ? route('panel.campaigns.update', $campaign->id) : route('panel.campaigns.store') }}" class="grid g2">
    @csrf
    <div class="card form-grid">
        <h2>1 · Kampanya bilgileri</h2>
        <label class="field">Kampanya adı <input type="text" name="name" maxlength="160" required value="{{ old('name', $campaign->name ?? '') }}"></label>
        <label class="field">Tür
            <select name="type">
                @foreach (['newsletter' => 'Bülten', 'promotional' => 'Kampanya / promosyon', 'announcement' => 'Duyuru'] as $k => $v)
                    <option value="{{ $k }}" @selected(old('type', $campaign->type ?? 'newsletter') === $k)>{{ $v }}</option>
                @endforeach
            </select>
            <span class="hint">Tümü pazarlama iletisidir; işlemsel e-postalar kampanya ile gönderilmez.</span>
        </label>
        <label class="field">Not (isteğe bağlı) <input type="text" name="notes" maxlength="500" value="{{ old('notes', json_decode($campaign->settings ?? '{}', true)['notes'] ?? '') }}"></label>
    </div>
    <div class="card form-grid">
        <h2>2 · Kitle</h2>
        <label class="field">Segment
            <select name="segment_id"><option value="">— Seçin —</option>
                @foreach ($segments as $s)<option value="{{ $s->id }}" @selected(old('segment_id', $campaign->segment_id ?? '') === $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            <span class="hint">Kampanya hazır olduğunda kitle dondurulur; sonradan sessizce değişmez.</span>
        </label>
        <h2>3 · İçerik</h2>
        <label class="field">Yayınlanmış pazarlama şablonu
            <select name="template_version_id"><option value="">— Seçin —</option>
                @foreach ($versions as $v)<option value="{{ $v->id }}" @selected(old('template_version_id', $campaign->template_version_id ?? '') === $v->id)>{{ $v->name }} · v{{ $v->version }}</option>@endforeach
            </select>
        </label>
        <h2>4 · Gönderici</h2>
        <label class="field">Gönderici kimliği
            <select name="sender_identity_id"><option value="">— Seçin —</option>
                @foreach ($senders as $s)
                    <option value="{{ $s->id }}" @selected(old('sender_identity_id', $campaign->sender_identity_id ?? '') === $s->id)>{{ $s->from_email }} · {{ $s->verification_status === 'verified' ? 'doğrulanmış' : 'doğrulanmamış' }} · DKIM {{ $s->dkim_status }} · {{ $s->region ?? $s->provider }}</option>
                @endforeach
            </select>
        </label>
        <div class="actions"><button class="btn btn-primary" type="submit">{{ $campaign ? 'Kaydet' : 'Taslağı oluştur' }}</button>
            <a class="btn" href="{{ $campaign ? route('panel.campaigns.show', $campaign->id) : route('panel.campaigns') }}">Vazgeç</a></div>
    </div>
</form>
@endsection
