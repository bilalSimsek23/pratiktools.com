@extends('panel.layout')
@section('title', $r->name)
@section('content')
@php($public = $r->status === 'published' && $r->active && $r->client_name_publication_approved)
<div class="page-head">
    <div><h1>{{ $r->name }}</h1>
        <p>@include('panel.website._status', ['status' => $r->status]) · {{ $public ? 'Sitede görünüyor' : 'Sitede görünmüyor' }}
            @if ($public) · <a href="/referanslar#{{ $r->slug }}" target="_blank" rel="noopener">Referanslar sayfası</a>@endif · <a href="{{ route('panel.website.references') }}">Tüm referanslar</a></p></div>
    @if ($can['publish'])
    <div class="actions">
        @foreach (['published' => 'Yayınla', 'draft' => 'Taslağa al', 'archived' => 'Arşivle'] as $st => $label)
            @if ($r->status !== $st)<form method="post" action="{{ route('panel.website.references.status', $r->id) }}">@csrf<input type="hidden" name="status" value="{{ $st }}"><button class="btn {{ $st === 'published' ? 'btn-primary' : '' }}" type="submit">{{ $label }}</button></form>@endif
        @endforeach
        @if ($r->status !== 'published')<form method="post" action="{{ route('panel.website.references.delete', $r->id) }}">@csrf<button class="btn" type="submit">Sil</button></form>@endif
    </div>
    @endif
</div>

<div class="grid g2">
<form method="post" action="{{ route('panel.website.references.update', $r->id) }}" class="card form-grid">@csrf
    <h2>Bilgiler</h2>
    <label>Firma / kurum adı <input type="text" name="name" maxlength="160" required value="{{ old('name', $r->name) }}"></label>
    <span class="hint">Adı değiştirmek firma adı onayını sıfırlar.</span>
    <label>Adres (slug) <input type="text" name="slug" maxlength="140" required value="{{ old('slug', $r->slug) }}" @readonly($r->status === 'published')></label>
    <label>Web sitesi <input type="url" name="website_url" maxlength="300" value="{{ old('website_url', $r->website_url) }}" placeholder="https://"></label>
    <label>Sektör <input type="text" name="industry" maxlength="120" value="{{ old('industry', $r->industry) }}"></label>
    <label>Kısa açıklama (yayın için gerekli) <input type="text" name="short_description" maxlength="300" value="{{ old('short_description', $r->short_description) }}"></label>
    <label>Ayrıntılı açıklama (Markdown; HTML çalışmaz) <textarea name="body" rows="6" maxlength="20000">{{ old('body', $r->body) }}</textarea></label>
    <span class="hint">Ayrıntılı açıklama veya bağlı yayındaki proje varsa referansın kendi sayfası olur; yoksa yalnız listede görünür.</span>
    <label>Yetkinlikler / hizmetler (her satır bir etiket) <textarea name="capabilities" rows="5">{{ old('capabilities', implode("\n", $capabilities)) }}</textarea></label>
    <label>Bağlı projeler ve hizmetler <select name="contents[]" multiple size="10">
        @foreach ($contents as $c)<option value="{{ $c->id }}" @selected(in_array($c->id, old('contents', $linked), true))>[{{ \App\Site\ContentType::from($c->type)->label() }}] {{ $c->title }}{{ $c->status !== 'published' ? ' (yayında değil)' : '' }}</option>@endforeach
    </select></label>
    <span class="hint">Bir projeyi bu referansa bağlamak, projenin bu firma için yapıldığını sitede gösterir. Anonim kalması gereken projeleri bağlamayın.</span>
    <label class="small"><input type="checkbox" name="active" value="1" @checked(old('active', $r->active))> Aktif</label>
    @if ($can['edit'])<div class="actions"><button class="btn btn-primary" type="submit">Kaydet</button></div>@endif
</form>

<div>
    <div class="card form-grid">
        <h2>Müşteri onayları</h2>
        <ul class="list-plain small">
            <li class="split"><span>Firma adının yayınlanması</span>@if ($r->client_name_publication_approved)<span class="badge b-pass">Onaylı · {{ \Illuminate\Support\Carbon::parse($r->name_approved_at)->format('d.m.Y') }}</span>@else<span class="badge b-warn">Onay yok</span>@endif</li>
            <li class="split"><span>Logo kullanımı</span>@if ($r->logo_use_approved)<span class="badge b-pass">Onaylı · {{ \Illuminate\Support\Carbon::parse($r->logo_approved_at)->format('d.m.Y') }}</span>@else<span class="badge b-warn">Onay yok</span>@endif</li>
            @if ($r->approval_note)<li class="split"><span>Not</span><span>{{ $r->approval_note }}</span></li>@endif
        </ul>
        <p class="hint">Firma adı onayı olmadan referans yayınlanamaz. Logo onayı yoksa sitede logo yerine firma adı metin olarak gösterilir. Hiçbir onay varsayılmaz.</p>
        @if ($can['approve'])
            @foreach (['name' => 'Firma adı', 'logo' => 'Logo'] as $what => $label)
                @php($on = $what === 'name' ? $r->client_name_publication_approved : $r->logo_use_approved)
                <form method="post" action="{{ route('panel.website.references.approval', $r->id) }}" class="form-grid stack-top">@csrf
                    <input type="hidden" name="what" value="{{ $what }}"><input type="hidden" name="approved" value="{{ $on ? 0 : 1 }}">
                    @if (! $on)
                        <label>{{ $label }} onayı nasıl alındı? <input type="text" name="note" maxlength="255" required placeholder="Örn. yazılı e-posta onayı, tarih"></label>
                        <label class="small"><input type="checkbox" name="confirm" value="1" required> {{ $label }} kullanımı için müşterinin onay verdiğini doğruluyorum.</label>
                    @endif
                    <div class="actions"><button class="btn" type="submit">{{ $on ? $label.' onayını geri al' : $label.' onayını kaydet' }}</button></div>
                </form>
            @endforeach
        @endif
    </div>

    <div class="card form-grid stack-top">
        <h2>Logo</h2>
        <p class="hint">JPEG, PNG veya WebP (SVG desteklenmez). Görsel yeniden kodlanır; konum/kamera bilgileri silinir. Yeni logo, logo onayını sıfırlar.</p>
        @foreach (['light' => ['Açık zemin logosu', $logo], 'dark' => ['Koyu zemin logosu (isteğe bağlı)', $logoDark]] as $variant => [$label, $m])
            <div class="stack-top">
                <strong class="small">{{ $label }}</strong>
                @if ($m)<div><img src="{{ reset($m['jpg']) }}" alt="{{ $m['alt'] }}" height="48" loading="lazy"> <span class="small muted">{{ $m['alt'] }}</span></div>@endif
                @if ($can['logo'])
                    <form method="post" action="{{ route('panel.website.references.logo', $r->id) }}" enctype="multipart/form-data" class="form-grid">@csrf
                        <input type="hidden" name="variant" value="{{ $variant }}">
                        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" required>
                        <label>Alternatif metin <input type="text" name="alt" maxlength="200" required value="{{ $r->name }} logosu"></label>
                        <div class="actions"><button class="btn" type="submit">Yükle</button></div>
                    </form>
                    @if ($m)<form method="post" action="{{ route('panel.website.references.logo', $r->id) }}">@csrf<input type="hidden" name="variant" value="{{ $variant }}"><input type="hidden" name="remove" value="1"><button class="btn btn-sm" type="submit">Kaldır</button></form>@endif
                @endif
            </div>
        @endforeach
    </div>
</div>
</div>
@endsection
