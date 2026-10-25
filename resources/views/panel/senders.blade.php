@extends('panel.layout')
@section('title', 'Gönderici kimlikleri')
@section('content')
@php($b = fn ($s, $ok = 'verified') => $s === $ok ? 'b-pass' : ($s === 'pending' ? 'b-warn' : 'b-bad'))
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.templates') }}">Şablonlar</a> /</div><h1>Gönderici kimlikleri</h1>
        <p>Sağlayıcıdan okunan durum. Kimlik ekleme ve DNS işlemleri entegrasyon ayarlarından yapılır; bu ekran yalnızca görüntüler.</p></div>
</div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Gönderici</th><th>Sağlayıcı</th><th>Doğrulama</th><th>DKIM</th><th>SPF</th><th>DMARC</th><th>MAIL FROM</th><th>Son kontrol</th></tr></thead>
        <tbody>
        @forelse ($senders as $s)
            <tr>
                <td><strong>{{ $s->from_email }}</strong>@if ($s->from_name)<div class="small muted">{{ $s->from_name }}</div>@endif</td>
                <td>{{ $s->provider === 'ses' ? 'Amazon SES' : $s->provider }} <span class="small muted">{{ $s->region }}</span></td>
                <td><span class="badge {{ $b($s->verification_status) }}">{{ $s->verification_status }}</span></td>
                <td><span class="badge {{ $b($s->dkim_status) }}">{{ $s->dkim_status }}</span></td>
                <td><span class="badge {{ $b($s->spf_status, 'pass') }}">{{ $s->spf_status }}</span></td>
                <td><span class="badge {{ $b($s->dmarc_status, 'pass') }}">{{ $s->dmarc_status }}</span></td>
                <td><span class="badge {{ $s->mail_from_status === 'verified' ? 'b-pass' : 'b-warn' }}">{{ $s->mail_from_status }}</span>@if ($s->mail_from_domain)<div class="small muted">{{ $s->mail_from_domain }}</div>@endif</td>
                <td class="small muted">{{ $s->last_checked_at ? \Illuminate\Support\Carbon::parse($s->last_checked_at)->diffForHumans() : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">Gönderici kimliği yok. Amazon SES entegrasyonu bağlandıktan sonra kimlikler burada görünür.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
