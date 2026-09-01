        </main>
    </div>
</div>
<script>
window.LC_BASE_URL = <?= json_encode(lc_base_url()) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_account_setting(lc_current_account_id(), 'poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;
window.LC_REALTIME = <?= json_encode(function_exists('lc_realtime_client_config') ? lc_realtime_client_config() : ['mode' => 'poll', 'wsUrl' => '', 'backupPollMs' => 15000]) ?>;
</script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-time.js?v=1"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-notify-sound.js?v=1"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-realtime.js?v=1"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/admin-core.js?v=2"></script>
<?php
$footerScripts = $extraScripts ?? [];
if (!empty($extraScript)) {
    $footerScripts[] = $extraScript;
}
foreach ($footerScripts as $scriptFile):
?>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/<?= lc_sanitize($scriptFile) ?>?v=33"></script>
<?php endforeach; ?>
</body>
</html>
