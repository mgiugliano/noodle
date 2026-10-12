// Applies the visitor's saved theme before the page is painted (avoids a flash). Progressive enhancement only.
(function () {
  'use strict';
  try {
    var t = localStorage.getItem('noodle-theme');
    if (t === 'dark' || t === 'light') { document.documentElement.setAttribute('data-theme', t); }
  } catch (e) { /* storage unavailable: follow the system setting */ }
})();
