@extends('panel.layout')
@section('title', $type->plural())
@section('content')
<div class="page-head">
    <div><h1>Web Sitesi · {{ $type->plural() }}</h1><p>Herkese açık sitede yalnızca <strong>yayında</strong> olan içerik görünür. Taslaklar ve arşiv indekslenmez.</p></div>
</div>
@if ($canEdit)
<div class="card">
    <form method="post" action="{{ route('panel.website.store', $type->value) }}" class="actions">@csrf
        <input type="text" name="title" required maxlength="200" placeholder="Yeni {{ mb_strtolower($type->label()) }} başlığı">
        <button class="btn btn-primary" type="submit">Taslak oluştur</button>
    </form>
</div>
@endif
<div class="card stack-top table-wrap">
    <table class="table">
        <thead><tr><th>Başlık</th><th>Adres</th><th>Durum</th><th>Güncellendi</th></tr></thead>
        <tbody>
        @forelse ($items as $i)
            <tr>
                <td><a href="{{ route('panel.website.edit', $i->id) }}">{{ $i->title }}</a>@if ($i->featured) <span class="badge b-muted">Öne çıkan</span>@endif</td>
                <td class="small"><code>{{ \App\Site\SiteQuery::url($type->value, $i->slug) }}</code></td>
                <td>@include('panel.website._status', ['status' => $i->status])</td>
                <td class="small muted">{{ \Illuminate\Support\Carbon::parse($i->updated_at)->format('d.m.Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">Henüz içerik yok.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@if ($type->categoryKind() !== null)
<div class="card stack-top table-wrap">
    <h2>Kategoriler</h2>
    <table class="table">
        <thead><tr><th>Ad</th><th>Adres</th><th>Sıra</th><th>Aktif</th>@if ($canEdit)<th></th>@endif</tr></thead>
        <tbody>
        @foreach ($categories as $k)
            <tr>
                @if ($canEdit)
                <form method="post" action="{{ route('panel.website.categories.save', $type->categoryKind()) }}">@csrf<input type="hidden" name="id" value="{{ $k->id }}">
                    <td><input type="text" name="name" value="{{ $k->name }}" maxlength="120" required></td>
                    <td><input type="text" name="slug" value="{{ $k->slug }}" maxlength="120"></td>
                    <td><input type="number" name="sort_order" value="{{ $k->sort_order }}"></td>
                    <td><input type="checkbox" name="active" value="1" @checked($k->active)></td>
                    <td><button class="btn btn-sm" type="submit">Kaydet</button></td>
                </form>
                @else
                    <td>{{ $k->name }}</td><td>{{ $k->slug }}</td><td>{{ $k->sort_order }}</td><td>{{ $k->active ? 'Evet' : 'Hayır' }}</td>
                @endif
            </tr>
        @endforeach
        @if ($canEdit)
            <tr><form method="post" action="{{ route('panel.website.categories.save', $type->categoryKind()) }}">@csrf
                <td><input type="text" name="name" maxlength="120" required placeholder="Yeni kategori"></td><td><input type="text" name="slug" maxlength="120" placeholder="otomatik"></td>
                <td><input type="number" name="sort_order" value="0"></td><td><input type="checkbox" name="active" value="1" checked></td><td><button class="btn btn-sm" type="submit">Ekle</button></td>
            </form></tr>
        @endif
        </tbody>
    </table>
</div>
@endif
@endsection
