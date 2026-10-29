@extends('panel.layout')
@section('title', 'Referanslar')
@section('content')
<div class="page-head">
    <div><h1>Web Sitesi · Referanslar</h1><p>Birlikte çalışılan kurumlar. Sitede yalnızca <strong>yayında + aktif + firma adı onaylı</strong> referanslar görünür; logo ayrıca logo kullanım onayı ister. Ekleme, düzenleme ve sıralama kod veya dağıtım gerektirmez.</p></div>
</div>
@if ($canEdit)
<div class="card">
    <form method="post" action="{{ route('panel.website.references.store') }}" class="actions">@csrf
        <input type="text" name="name" required maxlength="160" placeholder="Firma / kurum adı">
        <button class="btn btn-primary" type="submit">Referans ekle</button>
    </form>
</div>
@endif
<form method="post" action="{{ route('panel.website.references.arrange') }}" class="card stack-top table-wrap">@csrf
    <table class="table">
        <thead><tr><th>Sıra</th><th>Ana sayfa</th><th>Referans</th><th>Durum</th><th>Ad onayı</th><th>Logo</th><th>Sitede</th></tr></thead>
        <tbody>
        @forelse ($references as $r)
            @php($public = $r->status === 'published' && $r->active && $r->client_name_publication_approved)
            <tr>
                <td><input type="number" name="rows[{{ $r->id }}][order]" value="{{ $r->featured_order }}" min="-1000" max="1000" @disabled(! $canEdit) aria-label="Sıra"></td>
                <td><input type="hidden" name="rows[{{ $r->id }}][featured]" value="0"><input type="checkbox" name="rows[{{ $r->id }}][featured]" value="1" @checked($r->homepage_featured) @disabled(! $canEdit) aria-label="Ana sayfada göster"></td>
                <td><a href="{{ route('panel.website.references.edit', $r->id) }}"><strong>{{ $r->name }}</strong></a><br><span class="small muted">{{ $r->short_description }}</span></td>
                <td>@include('panel.website._status', ['status' => $r->status])@unless ($r->active) <span class="badge b-muted">Pasif</span>@endunless</td>
                <td>@if ($r->client_name_publication_approved)<span class="badge b-pass">Onaylı</span>@else<span class="badge b-warn">Yok</span>@endif</td>
                <td>@if (! $r->logo_media_id)<span class="small muted">Logo yok</span>@elseif ($r->logo_use_approved)<span class="badge b-pass">Onaylı</span>@else<span class="badge b-warn">Onay yok (ad metin olarak)</span>@endif</td>
                <td>@if ($public)<a href="/referanslar#{{ $r->slug }}" target="_blank" rel="noopener">Görünüyor</a>@else<span class="small muted">Görünmüyor</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">Henüz referans yok.</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($canEdit && $references->isNotEmpty())<div class="actions"><button class="btn" type="submit">Sırayı ve ana sayfa seçimini kaydet</button></div>@endif
</form>
@endsection
