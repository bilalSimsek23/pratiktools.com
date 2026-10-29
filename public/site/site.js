/* PratikTools public site: attribution, consent-gated analytics, inquiry form, events. No dependencies. */
(function () {
  'use strict';
  var cfgEl = document.getElementById('pt-config');
  var cfg = {};
  try { cfg = JSON.parse(cfgEl ? cfgEl.textContent : '{}') || {}; } catch (e) { cfg = {}; }
  var store = { get: function (k, s) { try { return (s ? sessionStorage : localStorage).getItem(k); } catch (e) { return null; } },
                set: function (k, v, s) { try { (s ? sessionStorage : localStorage).setItem(k, v); } catch (e) {} } };

  // Attribution: first touch (kept 90 days) and last touch (this visit). Only utm_*, click-id presence, landing path and referrer host.
  function touch() {
    var q = new URLSearchParams(location.search), t = { landing: location.pathname, at: new Date().toISOString().slice(0, 10) };
    ['source', 'medium', 'campaign', 'content', 'term'].forEach(function (k) { var v = q.get('utm_' + k); if (v) { t[k] = v.slice(0, 100); } });
    ['gclid', 'fbclid', 'msclkid'].forEach(function (k) { if (q.get(k)) { t[k] = 1; } });
    if (document.referrer) { try { var h = new URL(document.referrer).hostname; if (h !== location.hostname) { t.referrer = h; } } catch (e) {} }
    return t;
  }
  var current = touch(), fresh = current.source || current.gclid || current.fbclid || current.referrer;
  var first = store.get('pt_ft');
  try { if (!first || (JSON.parse(first).exp || 0) < Date.now()) { current.exp = Date.now() + 90 * 864e5; store.set('pt_ft', JSON.stringify(current)); } } catch (e) {}
  if (fresh || !store.get('pt_lt', true)) { store.set('pt_lt', JSON.stringify(current), true); }

  // Analytics providers: only after consent.
  var a = cfg.analytics || {}, hasProviders = !!(a.ga4_id || a.google_ads_id || a.meta_pixel_id);
  function loadProviders() {
    if (a.ga4_id || a.google_ads_id) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      var s = document.createElement('script'); s.async = true; s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(a.ga4_id || a.google_ads_id); document.head.appendChild(s);
      window.gtag('js', new Date());
      if (a.ga4_id) { window.gtag('config', a.ga4_id, { anonymize_ip: true }); }
      if (a.google_ads_id) { window.gtag('config', a.google_ads_id); }
    }
    if (a.meta_pixel_id) {
      var f = window.fbq = function () { f.callMethod ? f.callMethod.apply(f, arguments) : f.queue.push(arguments); };
      f.queue = []; f.loaded = true; f.version = '2.0'; window._fbq = f;
      var m = document.createElement('script'); m.async = true; m.src = 'https://connect.facebook.net/en_US/fbevents.js'; document.head.appendChild(m);
      window.fbq('init', a.meta_pixel_id); window.fbq('track', 'PageView');
    }
  }
  var consent = store.get('pt_consent');
  if (hasProviders) {
    if (consent === 'allow') { loadProviders(); }
    else if (consent !== 'deny') {
      var bar = document.getElementById('pt-consent');
      if (bar) { bar.hidden = false; bar.addEventListener('click', function (e) {
        var c = e.target && e.target.getAttribute('data-consent'); if (!c) { return; }
        store.set('pt_consent', c); bar.hidden = true; consent = c; if (c === 'allow') { loadProviders(); }
      }); }
    }
  }

  // Events: first-party counter + providers (if consented).
  function track(name) {
    try { navigator.sendBeacon && navigator.sendBeacon('/t', new Blob([JSON.stringify({ e: name, p: location.pathname })], { type: 'application/json' })); } catch (e) {}
    if (consent !== 'allow') { return; }
    if (window.gtag) {
      window.gtag('event', name);
      if (name === 'lead_submit' && a.google_ads_id && a.google_ads_lead_label) { window.gtag('event', 'conversion', { send_to: a.google_ads_id + '/' + a.google_ads_lead_label }); }
    }
    if (window.fbq) { window.fbq(name === 'lead_submit' ? 'track' : 'trackCustom', name === 'lead_submit' ? 'Lead' : name); }
  }
  window.ptTrack = track;
  track('page_view');
  if (cfg.event && cfg.event !== 'page_view') { track(cfg.event); }
  document.addEventListener('click', function (e) { var l = e.target.closest && e.target.closest('[data-track]'); if (l) { track(l.getAttribute('data-track')); } });

  // Inquiry forms.
  Array.prototype.forEach.call(document.querySelectorAll('form[data-inquiry]'), function (form) {
    var opened = false;
    form.addEventListener('focusin', function () { if (!opened) { opened = true; track('lead_form_open'); } });
    var sid = form.querySelector('[name=sid]'); if (sid && window.crypto && crypto.randomUUID) { sid.value = crypto.randomUUID(); }
    form.querySelector('[name=first_touch]').value = store.get('pt_ft') || '';
    form.querySelector('[name=last_touch]').value = store.get('pt_lt', true) || '';
    form.addEventListener('submit', function (e) {
      if (!window.fetch) { return; }
      e.preventDefault();
      var btn = form.querySelector('button[type=submit]'), status = form.querySelector('.form-status');
      Array.prototype.forEach.call(form.querySelectorAll('[data-error]'), function (el) { el.textContent = ''; });
      Array.prototype.forEach.call(form.querySelectorAll('[aria-invalid]'), function (el) { el.removeAttribute('aria-invalid'); });
      btn.disabled = true;
      fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          status.innerHTML = '';
          var span = document.createElement('span'); span.className = res.ok ? 'ok' : 'error'; span.textContent = res.j.message || ''; status.appendChild(span);
          if (res.ok) { track('lead_submit'); form.reset(); if (sid && crypto.randomUUID) { sid.value = crypto.randomUUID(); } btn.disabled = true; return; }
          Object.keys(res.j.errors || {}).forEach(function (k) {
            var el = form.querySelector('[data-error=' + k + ']'), input = form.querySelector('[name=' + k + ']');
            if (el) { el.textContent = res.j.errors[k]; } if (input) { input.setAttribute('aria-invalid', 'true'); }
          });
          btn.disabled = false;
        })
        .catch(function () { status.textContent = 'Bağlantı hatası; lütfen tekrar deneyin.'; btn.disabled = false; });
    });
  });
})();
