@extends('panel.layout')
@section('title', 'Şablonlar')
@section('content')
@php($st = ['draft' => ['b-muted', 'Taslak'], 'published' => ['b-pass', 'Yayında'], 'archived' => ['b-muted', 'Arşiv']])
<div class="page-head">
    <div><h1>Şablonlar</h1><p>E-posta içerikleri versiyonludur; yayınlanan versiyon değiştirilemez. Kampanyalar yalnızca yayınlanmış pazarlama şablonlarını kullanır.</p></div>
    <a class="btn" href="{{ route('panel.senders') }}">Gönderici kimlikleri</a>
</div>
<div class="grid g2">
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Şablon</th><th>Tür</th><th>Versiyon</th><th>Durum</th><th>Güncellendi</th></tr></thead>
            <tbody>
            @forelse ($templates as $t)
                <tr>
                    <td><a href="{{ route('panel.templates.show', $t->id) }}"><strong>{{ $t->name }}</strong></a></td>
                    <td>{{ $t->message_type === 'marketing' ? 'Pazarlama' : 'İşlemsel' }}</td>
                    <td>{{ $t->current_version ? 'v'.$t->current_version.' yayında' : '—' }} <span class="small muted">({{ $t->versions }} versiyon)</span></td>
                    <td><span class="badge {{ $st[$t->status][0] ?? 'b-muted' }}">{{ $st[$t->status][1] ?? $t->status }}</span></td>
                    <td class="small muted">{{ \Illuminate\Support\Carbon::parse($t->updated_at)->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz şablon yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($canEdit ?? true)
    <form class="card form-grid" method="post" action="{{ route('panel.templates.store') }}">
        @csrf
        <h2>Yeni şablon</h2>
        <label class="field">Ad <input type="text" name="name" maxlength="120" required value="{{ old('name') }}"></label>
        <label class="field">Tür
            <select name="message_type"><option value="marketing">Pazarlama</option><option value="transactional">İşlemsel</option></select>
            <span class="hint">Pazarlama içeriği izin, İYS ve abonelikten çıkma kurallarına tabidir.</span>
        </label>
        <div class="actions"><button class="btn btn-primary" type="submit">Oluştur</button></div>
    </form>
    @endif
</div>
@endsection
