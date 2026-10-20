        </main>
    </div>
</div>
<script>
window.LC_BASE_URL = <?= json_encode(lc_base_url()) ?>;
window.LC_AGENT_ID = <?= (int) ($agent['id'] ?? 0) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_account_setting(lc_current_account_id(), 'poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
window.LC_POLL_DISABLED = <?= lc_account_setting(lc_current_account_id(), 'poll_disabled', '0') === '1' ? 'true' : 'false' ?>;
window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;
window.LC_REALTIME = <?= json_encode(function_exists('lc_realtime_client_config') ? lc_realtime_client_config() : ['mode' => 'poll', 'wsUrl' => '', 'backupPollMs' => 15000]) ?>;
window.LC_ADMIN_TIMEZONE = <?= json_encode(function_exists('lc_account_timezone') ? lc_account_timezone(lc_current_account_id()) : 'UTC') ?>;
window.LC_NOTIFY_SOUNDS = <?= json_encode(function_exists('lc_notify_sound_map') ? lc_notify_sound_map(lc_current_account_id()) : new stdClass(), JSON_UNESCAPED_SLASHES) ?>;
window.LC_CMDK_NAV = <?= json_encode(array_map(static function (array $item): array {
    return [
        'id' => $item['id'],
        'label' => $item['label'],
        'icon' => $item['icon'],
        'href' => lc_admin_url($item['page']),
    ];
}, $navItems ?? []), JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-time.js?v=41"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-notify-sound.js?v=42"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-tab-notify.js?v=41"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/lc-realtime.js?v=13"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/admin-core.js?v=51"></script>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/admin-cmdk.js?v=40"></script>
<?php
$footerScripts = $extraScripts ?? [];
if (!empty($extraScript)) {
    $footerScripts[] = $extraScript;
}
foreach ($footerScripts as $scriptFile):
?>
<script src="<?= htmlspecialchars(lc_base_url(), ENT_QUOTES, 'UTF-8') ?>/assets/js/<?= lc_sanitize($scriptFile) ?>?v=158"></script>
<?php endforeach; ?>
</body>
</html>
