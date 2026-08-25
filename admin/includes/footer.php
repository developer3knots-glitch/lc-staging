        </main>
    </div>
</div>
<script>
window.LC_BASE_URL = <?= json_encode(LC_BASE_URL) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_account_setting(lc_current_account_id(), 'poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;
</script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-time.js?v=102"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=102"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=102"></script>
<?php
$footerScripts = $extraScripts ?? [];
if (!empty($extraScript)) {
    $footerScripts[] = $extraScript;
}
foreach ($footerScripts as $scriptFile):
?>
<script src="<?= LC_BASE_URL ?>/assets/js/<?= lc_sanitize($scriptFile) ?>?v=102"></script>
<?php endforeach; ?>
</body>
</html>
