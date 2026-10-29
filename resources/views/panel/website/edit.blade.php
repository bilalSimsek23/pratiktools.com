@extends('panel.layout')
@section('title', $c->title)
@section('content')
@php($v = fn (string $k, $default = '') => old($k, $default))
<div class="page-head">
    <div><h1>{{ $c->title }}</h1>
        <p>{{ $type->label() }} · @include('panel.website._status', ['status' => $c->status]) · <code>{{ $publicUrl }}</code>
            @if ($c->status === 'published') · <a href="{{ $publicUrl }}" target="_blank" rel="noopener">Yayındaki sayfa</a>@endif
            · <a href="{{ route('panel.website.preview', $c->id) }}" target="_blank" rel="noopener">Önizleme</a></p></div>
    @if ($canPublish)
    <div class="actions">
        @foreach (['published' => 'Yayınla', 'draft' => 'Taslağa al', 'archived' => 'Arşivle'] as $st => $label)
            @if ($c->status !== $st)<form method="post" action="{{ route('panel.website.status', $c->id) }}">@csrf<input type="hidden" name="status" value="{{ $st }}"><button class="btn {{ $st === 'published' ? 'btn-primary' : '' }}" type="submit">{{ $label }}</button></form>@endif
        @endforeach
    </div>
    @endif
</div>

<form method="post" action="{{ route('panel.website.update', $c->id) }}" class="grid g2">@csrf
    <div class="card form-grid">
        <h2>İçerik</h2>
        <label>Başlık <input type="text" name="title" maxlength="200" required value="{{ $v('title', $c->title) }}"></label>
        <label>Adres (slug) <input type="text" name="slug" maxlength="140" required value="{{ $v('slug', $c->slug) }}" @readonly($c->status === 'published')>
            <span class="hint">Yayındaki içeriğin adresi değiştirilemez.</span></label>
        <label>Özet (kartlarda ve meta açıklamada kullanılır; yayın için gerekli) <textarea name="excerpt" maxlength="600" rows="3">{{ $v('excerpt', $c->excerpt) }}</textarea></label>
        <label>Gövde (Markdown — HTML ve script çalıştırılmaz) <textarea name="body" maxlength="60000" rows="14">{{ $v('body', $c->body) }}</textarea>
            <span class="hint">## Başlık, - liste, **kalın**, [bağlantı](https://...). Görsel yalnızca /media/ adresinden.</span></label>
        @if ($categories->isNotEmpty())
            <label>Kategori <select name="category_id"><option value="">—</option>@foreach ($categories as $k)<option value="{{ $k->id }}" @selected($v('category_id', $c->category_id) === $k->id)>{{ $k->name }}</option>@endforeach</select></label>
        @endif
        <label class="small"><input type="checkbox" name="featured" value="1" @checked($v('featured', $c->featured))> Öne çıkan (ana sayfada göster)</label>
        <label>Sıra <input type="number" name="sort_order" value="{{ $v('sort_order', $c->sort_order) }}"></label>
    </div>
    <div class="card form-grid">
        <h2>{{ $type->label() }} alanları</h2>
        @foreach ($type->fields() as $f)
            @php($val = \App\Site\SiteContents::formValue($f, $data[$f['key']] ?? null))
            @if ($f['kind'] === 'bool')
                <label class="small"><input type="checkbox" name="{{ $f['key'] }}" value="1" @checked($val === '1')> {{ $f['label'] }}</label>
            @elseif ($f['kind'] === 'select')
                <label>{{ $f['label'] }} <select name="{{ $f['key'] }}">@foreach ($f['options'] as $ok => $ol)<option value="{{ $ok }}" @selected($val === $ok)>{{ $ol }}</option>@endforeach</select></label>
            @elseif ($f['kind'] === 'text')
                <label>{{ $f['label'] }} <input type="text" name="{{ $f['key'] }}" maxlength="{{ $f['max'] ?? 300 }}" value="{{ old($f['key'], $val) }}"></label>
            @else
                <label>{{ $f['label'] }}@if ($f['kind'] === 'list') <span class="hint">(her satır bir madde)</span>@endif
                    <textarea name="{{ $f['key'] }}" rows="{{ in_array($f['kind'], ['faq', 'textarea'], true) ? 5 : 4 }}">{{ old($f['key'], $val) }}</textarea></label>
            @endif
            @if (isset($f['help']))<span class="hint">{{ $f['help'] }}</span>@endif
        @endforeach
    </div>
    <div class="card form-grid">
        <h2>SEO ve paylaşım</h2>
        <label>SEO başlığı (≤ 70) <input type="text" name="seo_title" maxlength="70" value="{{ $v('seo_title', $c->seo_title) }}"></label>
        <label>Meta açıklama (≤ 170) <textarea name="seo_description" maxlength="170" rows="3">{{ $v('seo_description', $c->seo_description) }}</textarea></label>
        <label>Paylaşım görseli <select name="social_image_id"><option value="">—</option>@foreach ($media as $m)<option value="{{ $m->id }}" @selected($v('social_image_id', $c->social_image_id) === $m->id)>{{ $m->alt }} ({{ $m->width }}×{{ $m->height }})</option>@endforeach</select>
            <span class="hint"><a href="{{ route('panel.website.media') }}">Görsel yükle</a></span></label>
    </div>
    <div class="card form-grid">
        <h2>İlgili içerikler (iç bağlantılar)</h2>
        <select name="related[]" multiple size="10">
            @foreach ($relatable as $r)<option value="{{ $r->id }}" @selected(in_array($r->id, old('related', $related), true))>[{{ \App\Site\ContentType::from($r->type)->label() }}] {{ $r->title }}{{ $r->status !== 'published' ? ' (yayında değil)' : '' }}</option>@endforeach
        </select>
        <span class="hint">Yalnızca yayındaki içerikler sitede bağlantı olarak görünür.</span>
    </div>
    @if ($canEdit)<div class="actions"><button class="btn btn-primary" type="submit">Kaydet</button></div>@endif
</form>

@if ($type->value === 'project')
<div class="card stack-top">
    <h2>Müşteri adı yayın onayı</h2>
    @if (isset($data['client_approval']))
        <p class="small"><span class="badge b-pass">Onay kayıtlı</span> {{ \Illuminate\Support\Carbon::parse($data['client_approval']['at'])->format('d.m.Y H:i') }} · {{ $data['client_approval']['note'] }}</p>
    @else
        <p class="small muted">Varsayılan olarak proje anonim yayınlanır. Gerçek müşteri adı yalnızca "Müşteri adı yayınlansın" işaretliyse ve aşağıdaki onay kaydedildiyse görünür.</p>
        @if ($canPublish)
        <form method="post" action="{{ route('panel.website.client-approval', $c->id) }}" class="form-grid">@csrf
            <label>Onay nasıl alındı? <input type="text" name="note" maxlength="255" required placeholder="Örn. müşteriden yazılı e-posta onayı, tarih"></label>
            <label class="small"><input type="checkbox" name="confirm" value="1" required> Müşterinin adının ve bu içeriğin yayınlanması için onay verdiğini doğruluyorum.</label>
            <div class="actions"><button class="btn" type="submit">Onayı kaydet</button></div>
        </form>
        @endif
    @endif
</div>
@endif
@endsection
