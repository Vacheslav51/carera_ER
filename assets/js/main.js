(function () {
  'use strict';
  var CFG = window.SITE_CONFIG || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- Безопасное хранилище ---------- */
  function store(kind) {
    try { var s = window[kind]; var k = '__t'; s.setItem(k, 1); s.removeItem(k); return s; } catch (e) { return null; }
  }
  var LS = store('localStorage'), SS = store('sessionStorage');
  function get(s, k) { try { return s ? s.getItem(k) : null; } catch (e) { return null; } }
  function set(s, k, v) { try { if (s) s.setItem(k, v); } catch (e) {} }

  /* ---------- Аналитика ---------- */
  var analyticsAllowed = get(LS, 'cookie_consent') === 'all';
  window.dataLayer = window.dataLayer || [];
  function track(name, params) {
    if (!analyticsAllowed) return;
    var p = params || {};
    window.dataLayer.push(Object.assign({ event: name }, p));
    if (CFG.metrikaId && typeof window.ym === 'function') {
      try { window.ym(CFG.metrikaId, 'reachGoal', name, p); } catch (e) {}
    }
  }
  function loadMetrika() {
    if (!CFG.metrikaId || window.ym) return;
    (function (m, e, t, r, i, k, a) {
      m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
      m[i].l = 1 * new Date();
      k = e.createElement(t); a = e.getElementsByTagName(t)[0];
      k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
    })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
    window.ym(CFG.metrikaId, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true });
  }

  /* ---------- Cookie-баннер ---------- */
  var cookieBox = $('#cookie');
  if (!get(LS, 'cookie_consent')) cookieBox.classList.add('show');
  else if (analyticsAllowed) loadMetrika();
  $$('[data-cookie]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-cookie');
      set(LS, 'cookie_consent', v);
      analyticsAllowed = v === 'all';
      if (analyticsAllowed) loadMetrika();
      cookieBox.classList.remove('show');
    });
  });
  $$('[data-cookie-settings]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); cookieBox.classList.add('show'); });
  });

  /* ---------- Мобильное меню ---------- */
  var burger = $('.burger'), menu = $('#mobile-menu');
  function closeMenu() { menu.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); burger.setAttribute('aria-label', 'Открыть меню'); }
  burger.addEventListener('click', function () {
    var open = !menu.classList.contains('open');
    menu.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', String(open));
    burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
  });
  $$('a', menu).forEach(function (a) { a.addEventListener('click', closeMenu); });

  /* ---------- Нижняя панель CTA на мобильном ---------- */
  var bar = $('.cta-bar'), hero = $('.hero'), apply = $('#apply'), footer = $('.site-footer');
  if ('IntersectionObserver' in window && bar) {
    var state = { hero: true, apply: false, footer: false };
    var upd = function () { bar.classList.toggle('show', !state.hero && !state.apply && !state.footer); };
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (e.target === hero) state.hero = e.isIntersecting;
        if (e.target === apply) state.apply = e.isIntersecting;
        if (e.target === footer) state.footer = e.isIntersecting;
      });
      upd();
    }, { threshold: 0 });
    io.observe(hero); io.observe(apply); io.observe(footer);
  }

  /* ---------- Клики по контактам и официальным ссылкам ---------- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (!a) return;
    var href = a.getAttribute('href') || '';
    if (/^(tel:|mailto:)/.test(href)) track('contact_click', { type: href.split(':')[0] });
    if (a.hasAttribute('data-official')) track('official_link_click', { url: a.href });
  });

  /* ---------- UTM-метки ---------- */
  var UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
  (function () {
    var q = new URLSearchParams(location.search);
    UTM.forEach(function (k) { if (q.get(k)) set(SS, k, q.get(k).slice(0, 200)); });
  })();

  /* ---------- Маска телефона +7 ---------- */
  function digits(v) { return (v || '').replace(/\D/g, ''); }
  function formatPhone(v) {
    var d = digits(v);
    if (d[0] === '8') d = '7' + d.slice(1);
    if (d[0] !== '7') d = '7' + d;
    d = d.slice(0, 11);
    var r = '+7', n = d.slice(1);
    if (n.length) r += ' (' + n.slice(0, 3);
    if (n.length >= 3) r += ')';
    if (n.length > 3) r += ' ' + n.slice(3, 6);
    if (n.length > 6) r += '-' + n.slice(6, 8);
    if (n.length > 8) r += '-' + n.slice(8, 10);
    return r;
  }
  $$('[data-phone]').forEach(function (inp) {
    inp.addEventListener('focus', function () { if (!inp.value) inp.value = '+7 ('; });
    inp.addEventListener('blur', function () { if (digits(inp.value).length <= 1) inp.value = ''; });
    inp.addEventListener('input', function () {
      var d = digits(inp.value);
      inp.value = d.length ? formatPhone(inp.value) : '';
    });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && /[)\s-]$/.test(inp.value)) {
        e.preventDefault();
        var d = digits(inp.value).slice(0, -1);
        inp.value = d.length > 1 ? formatPhone(d) : '+7 (';
      }
    });
  });

  /* ---------- Формы ---------- */
  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var modal = $('#success');

  function openModal(id, demo) {
    $('#success-id').textContent = id;
    $('#success-demo').hidden = !demo;
    modal.hidden = false;
    modal.classList.add('open');
    $('[data-close]', modal).focus();
  }
  function closeModal() { modal.classList.remove('open'); modal.hidden = true; }
  $('[data-close]', modal).addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { if (modal.classList.contains('open')) closeModal(); closeMenu(); }
  });

  function fieldOf(el) { return el.closest('.field'); }
  function setInvalid(el, bad) {
    var f = fieldOf(el);
    if (f) f.classList.toggle('invalid', bad);
    el.setAttribute('aria-invalid', bad ? 'true' : 'false');
  }
  function validate(form) {
    var ok = true, first = null;
    $$('input.input, select.select', form).forEach(function (el) {
      var v = el.value.trim(), bad = false;
      if (el.required && !v) bad = true;
      if (el.hasAttribute('data-phone') && v && digits(v).length !== 11) bad = true;
      if (el.hasAttribute('data-phone') && el.required && digits(v).length !== 11) bad = true;
      if (el.type === 'email' && v && !EMAIL_RE.test(v)) bad = true;
      if (el.type === 'text' && el.required && v.length < 2) bad = true;
      setInvalid(el, bad);
      if (bad) { ok = false; first = first || el; }
    });
    $$('.check[data-req] input', form).forEach(function (cb) {
      cb.closest('.check').classList.toggle('invalid', !cb.checked);
      if (!cb.checked) { ok = false; first = first || cb; }
    });
    if (first) first.focus();
    return ok;
  }

  function syncButton(form) {
    var all = $$('.check[data-req] input', form).every(function (c) { return c.checked; });
    $('.form-submit', form).disabled = !all;
  }

  function showError(form, msg) {
    var box = $('.form-alert', form);
    box.textContent = msg;
    box.classList.add('show');
  }
  function clearError(form) { $('.form-alert', form).classList.remove('show'); }

  function getCsrf() {
    return fetch(CFG.endpoint + '?csrf=1', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { return j.token; });
  }

  var isFile = location.protocol === 'file:';
  var seq = 0;

  $$('form[data-form]').forEach(function (form) {
    var type = form.getAttribute('data-form');
    var startedAt = 0;

    // Просмотр формы
    if ('IntersectionObserver' in window) {
      var seen = false;
      new IntersectionObserver(function (es, obs) {
        es.forEach(function (e) {
          if (e.isIntersecting && !seen) { seen = true; track(type + '_form_view'); obs.disconnect(); }
        });
      }, { threshold: 0.35 }).observe(form);
    }

    form.addEventListener('focusin', function () { if (!startedAt) startedAt = Date.now(); });
    form.addEventListener('change', function (e) {
      if (e.target.closest('.check')) { e.target.closest('.check').classList.remove('invalid'); syncButton(form); }
    });
    form.addEventListener('input', function (e) {
      clearError(form);
      if (fieldOf(e.target) && fieldOf(e.target).classList.contains('invalid')) setInvalid(e.target, false);
    });
    syncButton(form);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearError(form);
      if (!validate(form)) return;

      var btn = $('.form-submit', form);
      var label = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = 'Отправляем…';

      var fd = new FormData(form);
      UTM.forEach(function (k) { fd.append(k, get(SS, k) || ''); });
      fd.append('page_url', location.href.split('#')[0].slice(0, 500));
      fd.append('consent_version', CFG.consentVersion || '');
      fd.append('fill_ms', String(startedAt ? Date.now() - startedAt : 0));

      function done() { btn.innerHTML = label; syncButton(form); }
      function success(id, demo) {
        track(type + '_form_submit');
        form.reset();
        startedAt = 0;
        $$('.field.invalid', form).forEach(function (f) { f.classList.remove('invalid'); });
        done();
        openModal(id, demo);
      }

      if (isFile && CFG.demoWhenNoServer) {
        seq += 1;
        setTimeout(function () { success('DEMO-' + String(seq).padStart(4, '0'), true); }, 500);
        return;
      }

      getCsrf().then(function (token) {
        fd.append('csrf', token || '');
        return fetch(CFG.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Сервер вернул неожиданный ответ. Попробуйте ещё раз чуть позже.' }; });
      }).then(function (j) {
        if (j && j.ok) { success(j.id, false); return; }
        done();
        if (j && j.fields) {
          Object.keys(j.fields).forEach(function (n) {
            var el = form.querySelector('[name="' + n + '"]');
            if (el) setInvalid(el, true);
          });
        }
        showError(form, (j && j.error) || 'Не удалось отправить заявку. Попробуйте ещё раз.');
      }).catch(function () {
        done();
        showError(form, 'Не удалось связаться с сервером. Проверьте интернет-соединение и попробуйте ещё раз или позвоните нам.');
      });
    });
  });
})();
