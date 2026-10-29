{{-- Inquiry form: stateless POST (no session); site.js adds the submission id and attribution and posts with fetch. --}}
@php($errs = $formErrors ?? [])
@php($old = $old ?? [])
<form class="inquiry" method="post" action="/iletisim/talep" data-inquiry novalidate>
    <input type="hidden" name="kind" value="{{ $old['kind'] ?? ($kind ?? 'teklif') }}">
    <input type="hidden" name="ft" value="{{ $formStamp }}">
    <input type="hidden" name="sid" value="">
    <input type="hidden" name="first_touch" value="">
    <input type="hidden" name="last_touch" value="">
    <input type="hidden" name="landing" value="/{{ ltrim(request()->path(), '/') }}">
    <div class="hp" aria-hidden="true"><label>Web sitesi <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <p class="form-status" role="status" aria-live="polite">@if (isset($errs['form']))<span class="error">{{ $errs['form'] }}</span>@endif</p>
    @unless ($inquiryAvailable)<p class="notice small">Form şu anda talep kabul etmiyor. Lütfen daha sonra tekrar deneyin.</p>@endunless
    <div class="grid2">
        <label>Ad soyad *<input type="text" name="name" required maxlength="120" autocomplete="name" value="{{ $old['name'] ?? '' }}" @if (isset($errs['name'])) aria-invalid="true" @endif>
            @if (isset($errs['name']))<span class="error" data-error="name">{{ $errs['name'] }}</span>@else<span class="error" data-error="name"></span>@endif</label>
        <label>E-posta *<input type="email" name="email" required maxlength="254" autocomplete="email" value="{{ $old['email'] ?? '' }}" @if (isset($errs['email'])) aria-invalid="true" @endif>
            <span class="error" data-error="email">{{ $errs['email'] ?? '' }}</span></label>
        <label>Telefon<input type="tel" name="phone" maxlength="24" autocomplete="tel" value="{{ $old['phone'] ?? '' }}"><span class="error" data-error="phone">{{ $errs['phone'] ?? '' }}</span></label>
        <label>Şirket<input type="text" name="company" maxlength="160" autocomplete="organization" value="{{ $old['company'] ?? '' }}"></label>
    </div>
    <label>İlgilendiğiniz konu
        <select name="interest"><option value="">Seçin (isteğe bağlı)</option>
            @foreach ($interests as $key => $label)<option value="{{ $key }}" @selected(($old['interest'] ?? ($interest ?? '')) === $key)>{{ $label }}</option>@endforeach
        </select></label>
    <label>Mesajınız *<textarea name="message" required minlength="10" maxlength="4000" rows="5" placeholder="Kullandığınız sistemler ve çözmek istediğiniz sorun">{{ $old['message'] ?? '' }}</textarea>
        <span class="error" data-error="message">{{ $errs['message'] ?? '' }}</span></label>
    <label class="check small"><input type="checkbox" name="marketing_consent" value="1"> Kampanya ve duyurulardan e-posta ile haberdar olmak istiyorum (isteğe bağlı).</label>
    <p class="small muted">Bilgileriniz yalnızca talebinizi yanıtlamak için kullanılır.@if ($org['kvkk_url']) <a href="{{ $org['kvkk_url'] }}">KVKK Aydınlatma Metni</a>@endif</p>
    <button class="btn btn-primary" type="submit" @disabled(! $inquiryAvailable)>{{ $button ?? 'Gönder' }}</button>
</form>
