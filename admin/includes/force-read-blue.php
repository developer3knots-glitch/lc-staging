<?php
/** Agent bubble read ticks: WhatsApp blue. Keep on chats page so Cursor CSS reverts cannot hide it. */
$__lcBase = htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8');
?>
<style id="lc-force-agent-read-blue">
.lc-msg.agent .lc-msg-ticks.is-read,
.lc-chats-pro .lc-msg.agent .lc-msg-ticks.is-read {
  color: #53bdeb !important;
  background: none !important;
  padding: 0 !important;
  filter: none !important;
}
.lc-msg.agent .lc-msg-ticks.is-read path,
.lc-chats-pro .lc-msg.agent .lc-msg-ticks.is-read path {
  fill: #53bdeb !important;
}
</style>
<script id="lc-force-agent-read-blue-js">
(function () {
  var BLUE = '#53bdeb';
  function paint(root) {
    root = root || document;
    root.querySelectorAll('.lc-msg.agent .lc-msg-ticks.is-read').forEach(function (el) {
      el.style.setProperty('color', BLUE, 'important');
      el.querySelectorAll('path').forEach(function (p) { p.setAttribute('fill', BLUE); });
    });
  }
  paint();
  setInterval(paint, 700);
  if (window.MutationObserver) {
    var mo = new MutationObserver(function () { paint(); });
    document.addEventListener('DOMContentLoaded', function () {
      var box = document.getElementById('lc-messages') || document.getElementById('lc-chat-app');
      if (box) mo.observe(box, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
      paint();
    });
  }
})();
</script>