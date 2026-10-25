@extends('panel.layout')
@section('title', 'Test gönderimi')
@section('content')
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.campaigns.show', $c->id) }}">{{ $c->name }}</a> /</div>
        <h1>Test gönderimi hazırla</h1>
        <p>Alıcılar yalnızca izinli test listesinden seçilir; serbest adres girilemez. Talep oluşturmak hiçbir şey göndermez — önce plan gösterilir.</p></div>
</div>
<form method="post" action="{{ route('panel.dispatches.store', $c->id) }}" class="grid g2">
    @csrf
    <div class="card form-grid">
        <h2>Kanal</h2>
        <label class="field"><span><input type="radio" name="channel" value="email" @checked(old('channel', 'email') === 'email')> E-posta (kampanya içeriği, konu başına [TEST])</span></label>
        <label class="field"><span><input type="radio" name="channel" value="push" @checked(old('channel') === 'push')> Push (aşağıdaki başlık ve metin)</span></label>
        <h2>Mod</h2>
        <label class="field"><span><input type="radio" checked disabled> Yalnızca test</span></label>
        <label class="field"><span><input type="radio" disabled> Canlı — kapalı</span><span class="hint">Live sending is disabled until external readiness requirements are satisfied.</span></label>
        <h2>Push içeriği</h2>
        <label class="field">Başlık <input type="text" name="title" maxlength="120" value="{{ old('title') }}"></label>
        <label class="field">Metin <textarea name="body" maxlength="1000" rows="3">{{ old('body') }}</textarea></label>
    </div>
    <div class="card form-grid">
        <h2>İzinli test alıcıları</h2>
        <p class="small muted">E-posta</p>
        @forelse ($emailRecipients as $r)
            <label class="field"><span><input type="checkbox" name="recipients[]" value="{{ $r->id }}"> {{ $r->label }} · {{ \App\Commerce\DataPreview::maskEmail($r->provider_recipient_id) }}</span></label>
        @empty
            <p class="small">E-posta test alıcısı tanımlı değil. Yönetici test alıcısı eklemelidir (SES sandbox'ta adres SES'te doğrulanmış olmalı).</p>
        @endforelse
        <p class="small muted">Push</p>
        @forelse ($pushRecipients as $r)
            <label class="field"><span><input type="checkbox" name="recipients[]" value="{{ $r->id }}"> {{ $r->label }} · abonelik {{ substr($r->provider_recipient_id, 0, 8) }}…</span></label>
        @empty
            <p class="small">Push test alıcısı tanımlı değil.</p>
        @endforelse
        <label class="field"><span><input type="checkbox" name="new_generation" value="1"> Yeni gönderim (önceki talepten bağımsız)</span>
            <span class="hint">İşaretlenmezse aynı kampanya/kitle/kanal için mevcut talep sürdürülür; tekrar gönderim oluşmaz.</span></label>
        <div class="actions"><button class="btn btn-primary" type="submit">Planı oluştur</button><a class="btn" href="{{ route('panel.campaigns.show', $c->id) }}">Vazgeç</a></div>
    </div>
</form>
@endsection
