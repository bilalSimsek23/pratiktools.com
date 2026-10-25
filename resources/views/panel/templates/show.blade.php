@extends('panel.layout')
@section('title', $t->name)
@section('content')
@php
    $vst = ['draft' => ['b-muted', 'Taslak'], 'published' => ['b-pass', 'Yayında'], 'archived' => ['b-muted', 'Arşiv']];
    $issueLabels = ['TAG_SCRIPT' => 'Script etiketi', 'TAG_IFRAME' => 'iframe', 'TAG_FORM' => 'Form', 'EVENT_HANDLER' => 'on* olay niteliği', 'UNSAFE_URL' => 'Güvensiz bağlantı (javascript:/data:)',
        'INSECURE_URL' => 'https olmayan bağlantı', 'UNSAFE_CSS' => 'Güvensiz CSS', 'DISALLOWED_TAG' => 'İzin verilmeyen etiket', 'DISALLOWED_ATTRIBUTE' => 'İzin verilmeyen nitelik', 'META_HTTP_EQUIV' => 'meta http-equiv'];
    $editable = $canEdit && $t->status !== 'archived';
@endphp
<div class="page-head">
    <div><div class="small muted"><a href="{{ route('panel.templates') }}">Şablonlar</a> /</div>
        <h1>{{ $t->name }} <span class="badge {{ $vst[$t->status][0] ?? 'b-muted' }}">{{ $vst[$t->status][1] ?? $t->status }}</span></h1>
        <p>{{ $t->message_type === 'marketing' ? 'Pazarlama' : 'İşlemsel' }} şablonu · {{ $versions->count() }} versiyon</p></div>
    <div class="actions">
        @if ($canPublish && $t->status !== 'archived')
            <form class="inline" method="post" action="{{ route('panel.templates.archive', $t->id) }}">@csrf<button class="btn" type="submit">Arşivle</button></form>
        @endif
    </div>
</div>

<div class="grid g3">
    <div class="card">
        <h2>Versiyonlar</h2>
        <ul class="list-plain">
            @forelse ($versions as $ver)
                <li class="split"><a href="{{ route('panel.templates.show', ['template' => $t->id, 'version' => $ver->id]) }}">@if ($v && $v->id === $ver->id)<strong>v{{ $ver->version }}</strong>@else v{{ $ver->version }}@endif</a>
                    <span class="badge {{ $vst[$ver->status][0] ?? 'b-muted' }}">{{ $vst[$ver->status][1] ?? $ver->status }}</span></li>
            @empty
                <li class="muted">Henüz versiyon yok.</li>
            @endforelse
        </ul>
        @if ($v && $v->status === 'draft' && $canPublish)
            <form method="post" action="{{ route('panel.templates.publish', $v->id) }}" class="stack-top">@csrf
                <button class="btn btn-primary" type="submit" @disabled(($safety['issues'] ?? []) !== [] || ($safety['syntax'] ?? null) !== null)>v{{ $v->version }} yayınla</button></form>
            <p class="small muted">Yayınlanan versiyon değiştirilemez; değişiklik yeni versiyon olarak kaydedilir.</p>
        @endif
    </div>
    <div class="card">
        <h2>Güvenlik ve uyum</h2>
        @if ($safety)
            <ul class="readiness">
                <li><span class="ricon {{ $safety['issues'] === [] ? 'pass' : 'bad' }}">{{ $safety['issues'] === [] ? '✓' : '✕' }}</span>
                    <div><div class="title">HTML güvenliği</div>
                        @if ($safety['issues'] === [])<div class="why">Script, iframe, olay niteliği veya güvensiz bağlantı yok.</div>
                        @else<div class="why">@foreach ($safety['issues'] as $i){{ $issueLabels[$i] ?? $i }}@if (! $loop->last), @endif @endforeach</div><div class="todo">→ Bu öğeleri kaldırın; yayınlama engellenir.</div>@endif</div><span></span></li>
                <li><span class="ricon {{ $safety['syntax'] === null ? 'pass' : 'bad' }}">{{ $safety['syntax'] === null ? '✓' : '✕' }}</span>
                    <div><div class="title">Değişkenler</div><div class="why">{{ $safety['syntax'] ?? 'Tüm değişkenler tanımlı.' }}</div></div><span></span></li>
                @if ($t->message_type === 'marketing')
                    @php($hasUnsub = $v && str_contains((string) $v->html_template.$v->text_template, 'unsubscribe_url'))
                    <li><span class="ricon {{ $hasUnsub ? 'pass' : 'warn' }}">{{ $hasUnsub ? '✓' : '!' }}</span>
                        <div><div class="title">Abonelikten çıkma bağlantısı</div><div class="why">@if ($hasUnsub)İçerikte <code>&#123;&#123; unsubscribe_url &#125;&#125;</code> var.@else Pazarlama içeriğinde <code>&#123;&#123; unsubscribe_url &#125;&#125;</code> bulunmalı.@endif</div></div><span></span></li>
                @endif
            </ul>
        @else
            <p class="muted">Kontrol için bir versiyon kaydedin.</p>
        @endif
    </div>
    <div class="card">
        <h2>Kullanılabilir değişkenler</h2>
        <p class="small muted">Değerler gönderimde HTML-escape edilir. Bağlantı değişkenleri yalnızca https olarak üretilir.</p>
        <ul class="list-plain small">@foreach ($variables as $var)<li><code>&#123;&#123; {{ $var }} &#125;&#125;</code></li>@endforeach</ul>
    </div>
</div>

@if ($v && $safety && $safety['rendered'])
<div class="card stack-top">
    <div class="split"><h2>Önizleme · v{{ $v->version }}</h2><span class="small muted">Örnek değerlerle; güvenli sandbox görünümü.</span></div>
    <p><span class="muted">Konu:</span> <strong>{{ $safety['rendered']['subject'] }}</strong>@if ($safety['rendered']['preheader']) <span class="muted">— {{ $safety['rendered']['preheader'] }}</span>@endif</p>
    <div class="previews">
        <div><div class="small muted">Masaüstü</div><iframe class="preview" title="Masaüstü önizleme" sandbox src="{{ route('panel.templates.frame', $v->id) }}"></iframe></div>
        <div><div class="small muted">Mobil</div><iframe class="preview mobile" title="Mobil önizleme" sandbox src="{{ route('panel.templates.frame', $v->id) }}"></iframe></div>
    </div>
    <h3 class="stack-top">Düz metin</h3>
    <pre class="snippet">{{ $safety['rendered']['text'] }}</pre>
</div>
@endif

@if ($editable)
<form class="card form-grid stack-top" method="post" action="{{ route('panel.templates.versions.store', $t->id) }}">
    @csrf
    <h2>Yeni versiyon {{ $v ? '(v'.$v->version.' temel alınarak)' : '' }}</h2>
    <div class="form-row">
        <label class="field">Konu <input type="text" name="subject" maxlength="400" required value="{{ old('subject', $v->subject_template ?? '') }}"></label>
        <label class="field">Önizleme metni (preheader) <input type="text" name="preheader" maxlength="200" value="{{ old('preheader', $v->preheader ?? '') }}"></label>
    </div>
    <label class="field">HTML <textarea name="html" rows="14" spellcheck="false" class="code">{{ old('html', $v->html_template ?? '') }}</textarea>
        <span class="hint">Script, iframe, form, on* nitelikleri ve https olmayan bağlantılar kabul edilmez.</span></label>
    <label class="field">Düz metin <textarea name="text" rows="6" required>{{ old('text', $v->text_template ?? '') }}</textarea></label>
    <div class="actions"><button class="btn btn-primary" type="submit">Taslak versiyon olarak kaydet</button></div>
</form>
@endif
@endsection
