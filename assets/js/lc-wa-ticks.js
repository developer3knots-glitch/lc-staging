(function () {
  'use strict';
  function paintTick(el, status) {
    if (!el) return;
    if (status === 'read') {
      el.style.setProperty('color', '#53bdeb', 'important');
      el.classList.remove('is-sent', 'is-delivered');
      el.classList.add('is-read');
      el.title = 'Seen';
      el.setAttribute('aria-label', 'Seen');
    } else if (status === 'delivered') {
      el.style.setProperty('color', 'rgba(255,255,255,0.55)', 'important');
      el.classList.remove('is-sent', 'is-read');
      el.classList.add('is-delivered');
    } else {
      el.style.setProperty('color', 'rgba(255,255,255,0.55)', 'important');
      el.classList.remove('is-delivered', 'is-read');
      el.classList.add('is-sent');
    }
  }
  function refreshTicks(root) {
    root = root || document;
    root.querySelectorAll('.lc-msg.agent .lc-msg-ticks.is-read, .lcw-visitor .lcw-msg-ticks.is-read').forEach(function (el) {
      paintTick(el, 'read');
    });
    root.querySelectorAll('.lc-msg.agent .lc-msg-ticks.is-delivered, .lcw-visitor .lcw-msg-ticks.is-delivered').forEach(function (el) {
      paintTick(el, 'delivered');
    });
  }
  var obs = new MutationObserver(function () { refreshTicks(document.getElementById('lc-messages') || document); });
  function boot() {
    refreshTicks();
    var box = document.getElementById('lc-messages') || document.body;
    if (box) obs.observe(box, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    setInterval(refreshTicks, 1500);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  window.LCWaTicks = { refresh: refreshTicks, paint: paintTick };
})();