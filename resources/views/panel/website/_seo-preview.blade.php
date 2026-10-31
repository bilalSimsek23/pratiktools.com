{{-- Google result preview and length hints. Guidance only: nothing here limits what can be saved. --}}
<style nonce="{{ $cspNonce }}">
    .serp { border: 1px solid var(--line, #e2e8f0); border-radius: 10px; padding: 12px 14px; background: #fff; font-family: Arial, sans-serif; max-width: 600px; }
    .serp-url { color: #202124; font-size: 13px; overflow-wrap: anywhere; } .serp-title { color: #1a0dab; font-size: 19px; line-height: 1.3; margin: 3px 0; overflow-wrap: anywhere; }
    .serp-desc { color: #4d5156; font-size: 14px; line-height: 1.5; overflow-wrap: anywhere; } .serp-empty { color: #9aa0a6; font-style: italic; }
    .count { font-size: 12px; color: var(--muted, #64748b); font-weight: 400; } .count.over { color: #b45309; font-weight: 600; }
</style>
<script nonce="{{ $cspNonce }}">
(() => {
    const field = (form, name) => form.querySelector('[name="' + name + '"]');
    const first = (form, names) => (names || '').split(',').filter(Boolean).map(n => (field(form, n) || {}).value || '').map(v => v.trim()).find(v => v !== '') || '';
    document.querySelectorAll('[data-count-for]').forEach(el => {
        const input = field(el.closest('form'), el.dataset.countFor);
        if (!input) return;
        const ideal = Number(el.dataset.ideal);
        const update = () => {
            const n = [...input.value].length;
            el.textContent = n + ' / ~' + ideal + ' karakter' + (n > ideal ? ' — arama sonucunda kısaltılabilir' : '');
            el.classList.toggle('over', n > ideal);
        };
        input.addEventListener('input', update);
        update();
    });
    document.querySelectorAll('[data-serp]').forEach(box => {
        const form = box.closest('form'), d = box.dataset;
        const [url, title, desc] = ['.serp-url', '.serp-title', '.serp-desc'].map(s => box.querySelector(s));
        const update = () => {
            const site = d.site, suffix = first(form, d.suffixInput) || d.suffix || ('| ' + site);
            const t = first(form, d.titleInputs) || d.titleDefault || site;
            const full = t.includes(site) || t.includes(suffix) ? t : t + (/^[\p{L}\p{N}]/u.test(suffix) ? ' | ' : ' ') + suffix;
            title.textContent = full.length > 62 ? full.slice(0, 60) + '…' : full;
            const base = (first(form, d.urlInput) || d.base).replace(/\/+$/, '');
            url.textContent = base.replace(/^https?:\/\//, '') + (d.path && d.path !== '/' ? d.path.replace(/\//g, ' › ').replace(/^ › /, ' › ') : '');
            const text = first(form, d.descInputs) || d.descDefault || '';
            desc.textContent = text === '' ? 'Açıklama yok — arama motoru sayfadan bir metin seçer.' : (text.length > 160 ? text.slice(0, 158) + '…' : text);
            desc.classList.toggle('serp-empty', text === '');
        };
        form.addEventListener('input', update);
        update();
    });
})();
</script>
