        </main>
    </div>
</div>

<script>
window.LC_BASE_URL = <?= json_encode(LC_BASE_URL) ?>;
window.LC_POLL_INTERVAL = <?= (int) lc_account_setting(lc_current_account_id(), 'poll_interval_ms', (string) LC_POLL_INTERVAL) ?>;
window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;
window.LC_ADMIN_TIMEZONE = <?= json_encode(function_exists('lc_account_timezone') ? lc_account_timezone(lc_current_account_id()) : 'UTC') ?>;
window.LC_CMDK_NAV = <?= json_encode(array_map(static function (array $item): array {
    return [
        'id' => $item['id'],
        'label' => $item['label'],
        'icon' => $item['icon'],
        'href' => lc_admin_url($item['page']),
    ];
}, $navItems), JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-time.js?v=136"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=136"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/lc-tab-notify.js?v=136"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=161"></script>
<script src="<?= LC_BASE_URL ?>/assets/js/admin-cmdk.js?v=161"></script>
<?php
$footerScripts = $extraScripts ?? [];
if (!empty($extraScript)) {
    $footerScripts[] = $extraScript;
}
foreach ($footerScripts as $scriptFile):
?>
<script src="<?= LC_BASE_URL ?>/assets/js/<?= lc_sanitize($scriptFile) ?>?v=161"></script>
<?php endforeach; ?>
</body>
</html>
