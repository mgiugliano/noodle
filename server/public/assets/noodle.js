// Progressive enhancement only: the page works without JavaScript.
(function () {
  'use strict';

  // ---- Light / dark theme button ----
  var themeBtn = document.querySelector('[data-theme-toggle]');
  if (themeBtn) {
    var root = document.documentElement;
    var systemDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var current = function () {
      var t = root.getAttribute('data-theme');
      return t === 'dark' || t === 'light' ? t : (systemDark && systemDark.matches ? 'dark' : 'light');
    };
    var paint = function () {
      var dark = current() === 'dark';
      var use = themeBtn.querySelector('use');
      if (use) { use.setAttribute('href', dark ? '#i-sun' : '#i-moon'); }
      themeBtn.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
      themeBtn.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
    };
    themeBtn.hidden = false;
    paint();
    themeBtn.addEventListener('click', function () {
      var next = current() === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('noodle-theme', next); } catch (e) { /* ignore */ }
      paint();
    });
    if (systemDark && systemDark.addEventListener) { systemDark.addEventListener('change', paint); }
  }

  // ---- Time zone toggle: show times in the visitor's own zone ----
  var els = Array.prototype.slice.call(document.querySelectorAll('[data-start]'));
  var btn = document.querySelector('[data-tz-toggle]');
  var pollTz = null;
  var chip = document.querySelector('.chips li span');
  if (chip) { pollTz = chip.textContent.replace(/^Times in /, '').replace(/ /g, '_'); }
  var myTz = null;
  try { myTz = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { /* ignore */ }

  function fmt(iso, tz, role) {
    var d = new Date(iso);
    if (isNaN(d)) { return null; }
    try {
      if (role === 'day') {
        return new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: tz }).format(d).replace(',', '');
      }
      return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: tz }).format(d);
    } catch (e) { return null; }
  }
  function apply(tz) {
    els.forEach(function (el) {
      var role = el.getAttribute('data-role');
      var a = fmt(el.getAttribute('data-start'), tz, role);
      if (a === null) { return; }
      if (role === 'range') {
        var b = fmt(el.getAttribute('data-end'), tz, role);
        el.textContent = a + '–' + b;
      } else { el.textContent = a; }
    });
    if (chip) { chip.textContent = 'Times in ' + tz.replace(/_/g, ' '); }
  }
  if (btn && myTz && pollTz && myTz !== pollTz) {
    var mine = false;
    btn.hidden = false;
    var label = function () { btn.textContent = mine ? 'Show in ' + pollTz.replace(/_/g, ' ') : 'Show in my time zone'; };
    label();
    btn.addEventListener('click', function () {
      mine = !mine;
      apply(mine ? myTz : pollTz);
      label();
      try { localStorage.setItem('noodle-tz', mine ? '1' : '0'); } catch (e) { /* ignore */ }
    });
    try {
      if (localStorage.getItem('noodle-tz') === '1') { mine = true; apply(myTz); label(); }
    } catch (e) { /* ignore */ }
  }

  // ---- Quick fill buttons ----
  var quick = document.querySelector('[data-quick]');
  if (quick) {
    quick.hidden = false;
    quick.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!(t instanceof HTMLElement) || !t.hasAttribute('data-fill')) { return; }
      var v = t.getAttribute('data-fill');
      document.querySelectorAll('.opts input[type=radio][value="' + v + '"]').forEach(function (r) { r.checked = true; });
    });
  }

  // ---- Basic client-side hint if the name is empty (server validates anyway) ----
  var form = document.querySelector('form[method=post]');
  if (form) {
    form.addEventListener('submit', function (ev) {
      var n = form.querySelector('input[name=name]');
      if (n && n.value.trim() === '') { ev.preventDefault(); n.focus(); n.setAttribute('aria-invalid', 'true'); }
    });
  }
})();
