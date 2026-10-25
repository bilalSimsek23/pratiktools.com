@extends('panel.layout')
@section('title', 'Segmentler')
@section('content')
@php
    $groups = old('groups', [['match' => 'all', 'rows' => [['field' => 'last_order_at', 'op' => 'before_days_ago', 'value' => '90'], ['field' => 'order_count', 'op' => 'gte', 'value' => '2']]]]);
    $reasonLabels = ['NO_EMAIL_RECIPIENT' => 'E-posta adresi yok', 'NO_PUSH_IDENTITY' => 'Push kimliği yok', 'NOT_SUBSCRIBED_OR_SUPPRESSED' => 'Abone değil / bastırılmış',
        'IYS_UNAVAILABLE' => 'İYS doğrulanamıyor', 'IYS_UNKNOWN' => 'İYS durumu bilinmiyor', 'IYS_RET' => 'İYS ret', 'LOCAL_SUPPRESSED' => 'Bastırılmış', 'MARKETPLACE_IDENTITY' => 'Pazaryeri adresi',
        'INVALID_EMAIL' => 'Geçersiz adres', 'IDENTITY_CONFLICT' => 'Kimlik çakışması', 'IYS_STALE' => 'İYS verisi eski'];
@endphp
<div class="page-head">
    <div><h1>Segmentler</h1><p>Kuralları listeden seçerek kitle tanımlayın; önizleme eşleşen kişi sayısını ve gönderilebilir kitleyi gösterir. Kişi listesi gösterilmez.</p></div>
</div>
<div class="grid g2">
    <form class="card form-grid" method="post" action="{{ route('panel.segments.store') }}">
        @csrf
        <h2>Kural oluşturucu</h2>
        <label class="field">Gruplar arasında
            <select name="match"><option value="all" @selected(old('match', 'all') === 'all')>VE — tüm gruplar sağlanmalı</option><option value="any" @selected(old('match') === 'any')>VEYA — herhangi bir grup yeterli</option></select></label>
        @for ($g = 0; $g < 2; $g++)
            @php($group = $groups[$g] ?? ['match' => 'all', 'rows' => []])
            <div class="group-box">
                <div class="split"><strong>Grup {{ $g + 1 }}</strong>
                    <select name="groups[{{ $g }}][match]" aria-label="Grup {{ $g + 1 }} eşleşme"><option value="all" @selected(($group['match'] ?? 'all') === 'all')>Tüm kurallar (VE)</option><option value="any" @selected(($group['match'] ?? '') === 'any')>Herhangi biri (VEYA)</option></select></div>
                @for ($r = 0; $r < 4; $r++)
                    @php($row = $group['rows'][$r] ?? ['field' => '', 'op' => '', 'value' => ''])
                    <div class="rule-row">
                        <select name="groups[{{ $g }}][rows][{{ $r }}][field]" aria-label="Alan"><option value="">— alan —</option>
                            @foreach ($fields as $k => $label)<option value="{{ $k }}" @selected(($row['field'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>
                        <select name="groups[{{ $g }}][rows][{{ $r }}][op]" aria-label="Koşul">
                            @foreach ($operators as $k => $label)<option value="{{ $k }}" @selected(($row['op'] ?? '') === $k)>{{ $label }}</option>@endforeach</select>
                        <input type="text" name="groups[{{ $g }}][rows][{{ $r }}][value]" value="{{ $row['value'] ?? '' }}" maxlength="500" aria-label="Değer" placeholder="değer">
                    </div>
                @endfor
            </div>
        @endfor
        <p class="hint">Boş satırlar yok sayılır. Tarih alanlarında "N günden eski" / "son N gün içinde" için gün sayısı girin; kategori ve ürünlerde ad veya kodu virgülle ayırın.</p>
        <div class="actions">
            <button class="btn" type="submit" formaction="{{ route('panel.segments.preview') }}">Önizle (kişi sayısı)</button>
            @if ($canManage)
                <input type="text" name="name" maxlength="120" placeholder="Segment adı" aria-label="Segment adı" value="{{ old('name') }}">
                <button class="btn btn-primary" type="submit">Kaydet</button>
            @endif
        </div>
    </form>
    <div class="stack">
        @if ($preview)
        <div class="card">
            <h2>Önizleme</h2>
            <p class="small muted">{{ $preview['description'] }}</p>
            <div class="kpis">
                <div class="kpi"><div class="label">Eşleşen müşteri</div><div class="value">{{ number_format($preview['matched']) }}</div></div>
                <div class="kpi"><div class="label">E-posta gönderilebilir</div><div class="value">{{ number_format($preview['email']['eligible']) }}</div></div>
                <div class="kpi"><div class="label">Push gönderilebilir</div><div class="value">{{ number_format($preview['push']['eligible']) }}</div></div>
            </div>
            @if ($preview['email']['reasons'])
                <h3 class="stack-top">E-posta dışında kalanlar</h3>
                <ul class="list-plain small">@foreach ($preview['email']['reasons'] as $code => $n)<li class="split"><span>{{ $reasonLabels[$code] ?? $code }}</span><span>{{ number_format($n) }}</span></li>@endforeach</ul>
            @endif
            <p class="small muted">{{ $preview['duration_ms'] }} ms · salt okunur sorgu</p>
        </div>
        @endif
        <div class="card table-wrap">
            <h2>Kayıtlı segmentler</h2>
            <table class="table">
                <thead><tr><th>Segment</th><th>Kural</th><th>Durum</th></tr></thead>
                <tbody>
                @forelse ($segments as $s)
                    <tr><td><strong>{{ $s->name }}</strong></td><td class="small">{{ $s->description }}</td>
                        <td><span class="badge {{ $s->status === 'active' ? 'b-pass' : 'b-muted' }}">{{ $s->status === 'active' ? 'Aktif' : $s->status }}</span></td></tr>
                @empty
                    <tr><td colspan="3" class="muted">Henüz segment yok.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
