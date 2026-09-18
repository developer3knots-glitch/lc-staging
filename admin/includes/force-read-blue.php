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
/* Inline internal note styling: right-aligned under agent bubbles */
.lc-msg.is-note,
.lc-chats-pro .lc-msg.is-note,
body.lc-page-chats .lc-chats-pro .lc-msg.is-note {
  align-self: flex-end !important;
  max-width: min(78%, 480px) !important;
  min-width: 250px !important;
  min-height: 90px !important;
  width: fit-content !important;
  margin: 0.45rem 0 !important;
  background: #fffbeb !important;
  border: 1.5px solid #fde68a !important;
  border-left: 4.5px solid #f59e0b !important;
  border-radius: 14px 14px 4px 14px !important;
  padding: 0 !important;
  box-shadow: 0 2px 10px rgba(245, 158, 11, 0.12) !important;
  overflow: hidden !important;
  display: flex !important;
  flex-direction: column !important;
  box-sizing: border-box !important;
}
.lc-msg.is-note .lc-msg-note-head {
  display: flex !important;
  align-items: center !important;
  gap: 0.45rem !important;
  padding: 0.45rem 0.85rem 0.3rem !important;
  font-size: 0.74rem !important;
  color: #b45309 !important;
  font-weight: 600 !important;
  border-bottom: 1px dashed rgba(245, 158, 11, 0.3) !important;
  background: rgba(254, 243, 199, 0.65) !important;
}
.lc-msg.is-note .lc-msg-note-tag {
  display: inline-flex !important;
  align-items: center !important;
  gap: 0.28rem !important;
  color: #92400e !important;
  font-weight: 700 !important;
  text-transform: uppercase !important;
  font-size: 0.68rem !important;
  letter-spacing: 0.03em !important;
}
.lc-msg.is-note .lc-msg-note-author {
  color: #92400e !important;
  font-weight: 700 !important;
}
.lc-msg.is-note .lc-msg-body {
  padding: 0.55rem 0.85rem 0.4rem !important;
  color: #78350f !important;
  font-size: 0.875rem !important;
  line-height: 1.45 !important;
  word-break: break-word !important;
  flex: 1 1 auto !important;
}
.lc-msg.is-note .lc-msg-foot {
  display: flex !important;
  align-items: center !important;
  justify-content: flex-end !important;
  padding: 0.1rem 0.85rem 0.4rem !important;
  font-size: 0.68rem !important;
  color: #d97706 !important;
  margin-top: 0 !important;
}
.lc-msg.is-note .lc-msg-time {
  color: #b45309 !important;
  font-size: 0.68rem !important;
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