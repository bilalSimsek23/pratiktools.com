@extends('panel.layout')
@section('title', 'Lead formları')
@section('content')
@php($placements = ['popup' => 'Popup', 'footer' => 'Footer', 'landing_page' => 'Landing page', 'checkout' => 'Ödeme sayfası'])
<div class="page-head">
    <div><h1>Lead formları</h1><p>Sitenize eklenen bülten / lead formları. Form onayı mağaza tarafı kayıttır; pazarlama izni İYS'den doğrulanır.</p></div>
    <a class="btn" href="{{ route('panel.leads') }}">Lead metrikleri</a>
</div>
<div class="grid g2">
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Form</th><th>Yerleşim</th><th>Public ID</th><th>Lead (30 gün)</th><th>Durum</th></tr></thead>
            <tbody>
            @forelse ($forms as $f)
                <tr>
                    <td><a href="{{ route('panel.forms.show', $f->id) }}"><strong>{{ $f->name }}</strong></a></td>
                    <td>{{ $placements[$f->placement] ?? $f->placement }}</td>
                    <td><code class="small">{{ $f->public_id }}</code></td>
                    <td>{{ number_format($f->accepted_30d) }}</td>
                    <td><span class="badge {{ $f->active ? 'b-pass' : 'b-muted' }}">{{ $f->active ? 'Aktif' : 'Pasif' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz form yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($canManage)
    <form class="card form-grid" method="post" action="{{ route('panel.forms.store') }}">
        @csrf
        <h2>Yeni form</h2>
        <div class="form-row">
            <label class="field">Ad <input type="text" name="name" maxlength="120" required value="{{ old('name') }}"></label>
            <label class="field">Yerleşim <select name="placement">@foreach ($placements as $k => $v)<option value="{{ $k }}" @selected(old('placement') === $k)>{{ $v }}</option>@endforeach</select></label>
        </div>
        <label class="field">İzin metni <textarea name="consent_text" maxlength="4000" required rows="4">{{ old('consent_text') }}</textarea>
            <span class="hint">Metin versiyonlanır; her başvuru hangi metni onayladığıyla birlikte saklanır.</span></label>
        <label class="field">Başarı mesajı <input type="text" name="success_message" maxlength="300" required value="{{ old('success_message', 'Teşekkürler! Bültenimize kaydoldunuz.') }}"></label>
        <label class="field">İzinli site adresleri (origin) <textarea name="origins" maxlength="1000" required rows="2" placeholder="https://www.ornek.com">{{ old('origins') }}</textarea>
            <span class="hint">Yalnızca bu adreslerden gelen başvurular kabul edilir. Boşluk veya virgülle ayırın.</span></label>
        <label class="field"><span><input type="checkbox" name="double_opt_in" value="1" @checked(old('double_opt_in'))> Çift onay (double opt-in)</span></label>
        <div class="actions"><button class="btn btn-primary" type="submit">Formu oluştur (pasif)</button></div>
    </form>
    @endif
</div>
@endsection
