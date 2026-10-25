@extends('panel.layout')
@section('title', 'Otomasyonlar')
@section('content')
<div class="page-head">
    <div><h1>Otomasyonlar</h1><p>Otomasyonlar yalnızca planlı aksiyon üretir; gönderim katmanı kapalıyken hiçbir mesaj gönderilmez.</p></div>
</div>
<div class="grid g2">
    <div class="card table-wrap">
        <table class="table">
            <thead><tr><th>Otomasyon</th><th>Tetikleyici</th><th>Versiyon</th><th>Bekleyen</th><th>Durum</th></tr></thead>
            <tbody>
            @forelse ($rows as $a)
                <tr>
                    <td><a href="{{ route('panel.automations.show', $a->id) }}"><strong>{{ $a->name }}</strong></a></td>
                    <td class="small">{{ $a->trigger_text }}</td>
                    <td>{{ $a->published_version ? 'v'.$a->published_version.' yayında' : '—' }}@if ($a->latest_version && $a->latest_version !== $a->published_version)<div class="small muted">v{{ $a->latest_version }} taslak</div>@endif</td>
                    <td>{{ number_format($a->waiting) }}</td>
                    <td>@include('panel.automations._status', ['status' => $a->status])</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz otomasyon yok. Hazır şablonlardan biriyle başlayın.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($canManage)
    <form class="card form-grid" method="post" action="{{ route('panel.automations.store') }}">
        @csrf
        <h2>Hazır şablondan oluştur</h2>
        <label class="field">Şablon
            <select name="template"><option value="abandoned_cart" @selected(old('template') === 'abandoned_cart')>Sepeti terk edenler</option><option value="winback" @selected(old('template') === 'winback')>Geri kazanım (uzun süredir sipariş vermeyenler)</option></select></label>
        <label class="field">Ad <input type="text" name="name" maxlength="120" required value="{{ old('name') }}"></label>
        <div class="form-row">
            <label class="field">Bekleme (saat, sepet) <input type="number" name="delay_hours" min="1" max="720" value="{{ old('delay_hours', 2) }}"></label>
            <label class="field">Sipariş vermeme süresi (gün, geri kazanım) <input type="number" name="days" min="1" max="3650" value="{{ old('days', 90) }}"></label>
        </div>
        <p class="hint">Taslak olarak oluşturulur. Yayınlamadan önce dry-run ile etkisini görebilirsiniz.</p>
        <div class="actions"><button class="btn btn-primary" type="submit">Taslak oluştur</button></div>
    </form>
    @endif
</div>
@endsection
