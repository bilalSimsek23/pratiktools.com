@if (! empty($faq))
<section class="section" aria-labelledby="sss">
    <h2 id="sss">Sık sorulan sorular</h2>
    <div class="faq">
        @foreach ($faq as $f)
            <details><summary>{{ $f['q'] }}</summary><div class="faq-a">{!! nl2br(e($f['a'])) !!}</div></details>
        @endforeach
    </div>
</section>
@endif
