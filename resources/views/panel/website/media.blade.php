@extends('panel.layout')
@section('title', 'Görseller')
@section('content')
<div class="page-head"><div><h1>Web Sitesi · Görseller</h1><p>JPEG, PNG veya WebP; en fazla 5 MB. Yüklenen görsel yeniden kodlanır (WebP + JPEG, iki boyut); konum ve kamera bilgileri silinir.</p></div></div>
@if ($ephemeral)<div class="card"><p><span class="badge b-warn">Uyarı</span> Seçili depolama diski kalıcı değil{{ $production ? '; production\'da görsel yükleme kapalı' : '' }}. Laravel Cloud'da bir object storage bucket bağlayıp <a href="{{ route('panel.website.settings') }}">Site Ayarları</a>'ndan seçin.</p></div>@endif
@if ($canEdit)
<div class="card stack-top">
    <form method="post" action="{{ route('panel.website.media.upload') }}" enctype="multipart/form-data" class="form-grid">@csrf
        <label>Görsel <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
        <label>Alternatif metin (erişilebilirlik için gerekli) <input type="text" name="alt" maxlength="200" required></label>
        <div class="actions"><button class="btn btn-primary" type="submit">Yükle</button></div>
    </form>
</div>
@endif
<div class="card stack-top table-wrap">
    <table class="table"><thead><tr><th>Önizleme</th><th>Alt metin</th><th>Boyut</th><th>Yüklendi</th></tr></thead><tbody>
    @forelse ($media as $m)
        @php($d = \App\Site\SiteMedia::describe($m->id))
        <tr><td>@if ($d && $d['jpg'])<img src="{{ reset($d['jpg']) }}" alt="" width="96" loading="lazy">@endif</td><td>{{ $m->alt }}</td><td class="small">{{ $m->width }}×{{ $m->height }}</td>
            <td class="small muted">{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('d.m.Y H:i') }}</td></tr>
    @empty
        <tr><td colspan="4" class="muted">Görsel yok.</td></tr>
    @endforelse
    </tbody></table>
</div>
@endsection
