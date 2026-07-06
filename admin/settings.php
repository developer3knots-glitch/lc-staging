<?php
require_once __DIR__ . '/../includes/auth.php';
lc_require_admin();
lc_ensure_blocking_schema();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_settings';

    if ($action === 'save_settings') {
        lc_set_setting('auto_assign', isset($_POST['auto_assign']) ? '1' : '0');
        lc_set_setting('poll_interval_ms', (string) max(800, (int) ($_POST['poll_interval_ms'] ?? 1500)));

        $countryCodes = $_POST['blocked_countries'] ?? [];
        if (!is_array($countryCodes)) {
            $countryCodes = [];
        }
        lc_save_blocked_country_codes($countryCodes);
        $message = 'Settings saved.';
    } elseif ($action === 'block_ip') {
        $ip = trim((string) ($_POST['ip_address'] ?? ''));
        $reason = trim((string) ($_POST['ip_reason'] ?? ''));
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $error = 'Enter a valid IP address.';
        } elseif (lc_is_local_ip($ip)) {
            $error = 'Local network IPs cannot be blocked.';
        } elseif (lc_block_ip($ip, (int) lc_auth_agent()['id'], $reason)) {
            $message = 'IP ' . $ip . ' has been blocked.';
        } else {
            $error = 'Unable to block that IP.';
        }
    } elseif ($action === 'unblock_ip') {
        $ip = trim((string) ($_POST['ip_address'] ?? ''));
        if ($ip !== '' && lc_unblock_ip($ip)) {
            $message = 'IP ' . $ip . ' has been unblocked.';
        } else {
            $error = 'IP not found in block list.';
        }
    }
}

$agent = lc_auth_agent();
$blockedCountries = lc_get_blocked_country_codes();
$blockedIps = lc_get_blocked_ips();
$allCountries = lc_country_list();
asort($allCountries);

$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?><div class="lc-alert lc-alert-success"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

<div class="lc-alert lc-alert-info"><i class="ti ti-info-circle"></i> Per-brand widget settings are managed on the <a href="<?= LC_BASE_URL ?>/admin/brands.php"><strong>Brands</strong></a> page.</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-settings"></i> System Settings</h3></div>
    <div class="lc-card-body">
        <form method="post" class="lc-form">
            <input type="hidden" name="action" value="save_settings">
            <label>Poll Interval (ms) — real-time speed</label>
            <input type="number" name="poll_interval_ms" min="800" max="5000" value="<?= lc_sanitize(lc_setting('poll_interval_ms', '1500')) ?>">
            <label style="text-transform:none;font-weight:600;color:var(--lc-ink)">
                <input type="checkbox" name="auto_assign" <?= lc_setting('auto_assign', '1') === '1' ? 'checked' : '' ?>> Auto-assign waiting chats to online agents
            </label>
            <button type="submit" class="lc-btn lc-btn-primary" style="margin-top:1rem"><i class="ti ti-device-floppy"></i> Save Settings</button>
        </form>
    </div>
</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-world-off"></i> Country Blocking</h3></div>
    <div class="lc-card-body">
        <p style="font-size:0.84rem;color:var(--lc-muted);margin:0 0 1rem">
            Visitors from selected countries will not see the chat widget on your website. Existing chats are not affected.
        </p>
        <form method="post" class="lc-form" id="lc-country-block-form">
            <input type="hidden" name="action" value="save_settings">
            <input type="hidden" name="poll_interval_ms" value="<?= lc_sanitize(lc_setting('poll_interval_ms', '1500')) ?>">
            <?php if (lc_setting('auto_assign', '1') === '1'): ?>
                <input type="hidden" name="auto_assign" value="1">
            <?php endif; ?>

            <label>Blocked countries</label>
            <div class="lc-country-picker" id="lc-country-picker">
                <div class="lc-country-tags" id="lc-country-tags">
                    <?php foreach ($blockedCountries as $code): ?>
                        <span class="lc-country-tag" data-code="<?= lc_sanitize($code) ?>">
                            <?= lc_sanitize(lc_country_name($code)) ?> (<?= lc_sanitize($code) ?>)
                            <button type="button" class="lc-country-tag-remove" aria-label="Remove">&times;</button>
                        </span>
                    <?php endforeach; ?>
                </div>
                <input type="search" class="lc-country-search" id="lc-country-search" placeholder="Search countries…" autocomplete="off">
                <div class="lc-country-options" id="lc-country-options">
                    <?php foreach ($allCountries as $code => $name): ?>
                        <label class="lc-country-option<?= in_array($code, $blockedCountries, true) ? ' is-selected' : '' ?>" data-name="<?= lc_sanitize(strtolower($name . ' ' . $code)) ?>">
                            <input type="checkbox" name="blocked_countries[]" value="<?= lc_sanitize($code) ?>"<?= in_array($code, $blockedCountries, true) ? ' checked' : '' ?>>
                            <span><?= lc_sanitize($name) ?> <small>(<?= lc_sanitize($code) ?>)</small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="lc-btn lc-btn-primary" style="margin-top:1rem"><i class="ti ti-shield-off"></i> Save Country Blocks</button>
        </form>
    </div>
</div>

<div class="lc-card">
    <div class="lc-card-head"><h3><i class="ti ti-ban"></i> IP Blocking</h3></div>
    <div class="lc-card-body">
        <p style="font-size:0.84rem;color:var(--lc-muted);margin:0 0 1rem">
            Block a specific visitor IP so the chat widget is hidden for that network. You can also block from the live chat screen.
        </p>
        <form method="post" class="lc-form lc-ip-block-form">
            <input type="hidden" name="action" value="block_ip">
            <div class="lc-ip-block-row">
                <div>
                    <label>IP address</label>
                    <input type="text" name="ip_address" placeholder="e.g. 203.0.113.45" required>
                </div>
                <div>
                    <label>Reason (optional)</label>
                    <input type="text" name="ip_reason" placeholder="Spam / abuse" maxlength="255">
                </div>
                <div class="lc-ip-block-action">
                    <button type="submit" class="lc-btn lc-btn-danger"><i class="ti ti-ban"></i> Block IP</button>
                </div>
            </div>
        </form>

        <?php if ($blockedIps): ?>
            <div class="lc-table-wrap" style="margin-top:1.25rem">
                <table class="lc-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Reason</th>
                            <th>Blocked by</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blockedIps as $row): ?>
                            <tr>
                                <td><code><?= lc_sanitize($row['ip_address']) ?></code></td>
                                <td><?= lc_sanitize($row['reason'] ?: '—') ?></td>
                                <td><?= lc_sanitize($row['blocked_by_name'] ?: '—') ?></td>
                                <td><?= lc_time_span($row['created_at']) ?></td>
                                <td>
                                    <form method="post" style="display:inline" onsubmit="return confirm('Unblock this IP?');">
                                        <input type="hidden" name="action" value="unblock_ip">
                                        <input type="hidden" name="ip_address" value="<?= lc_sanitize($row['ip_address']) ?>">
                                        <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-lock-open"></i> Unblock</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="font-size:0.84rem;color:var(--lc-muted);margin:1rem 0 0">No blocked IPs yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var picker = document.getElementById('lc-country-picker');
    if (!picker) return;

    var search = document.getElementById('lc-country-search');
    var tags = document.getElementById('lc-country-tags');
    var options = document.getElementById('lc-country-options');

    function syncTag(code, name, selected) {
        var existing = tags.querySelector('[data-code="' + code + '"]');
        if (selected && !existing) {
            var tag = document.createElement('span');
            tag.className = 'lc-country-tag';
            tag.dataset.code = code;
            tag.innerHTML = name + ' (' + code + ') <button type="button" class="lc-country-tag-remove" aria-label="Remove">&times;</button>';
            tags.appendChild(tag);
        } else if (!selected && existing) {
            existing.remove();
        }
    }

    options.addEventListener('change', function (e) {
        var cb = e.target;
        if (!cb || cb.type !== 'checkbox') return;
        var label = cb.closest('.lc-country-option');
        label.classList.toggle('is-selected', cb.checked);
        var name = label.querySelector('span').childNodes[0].textContent.trim();
        syncTag(cb.value, name, cb.checked);
    });

    tags.addEventListener('click', function (e) {
        var btn = e.target.closest('.lc-country-tag-remove');
        if (!btn) return;
        var tag = btn.closest('.lc-country-tag');
        var code = tag.dataset.code;
        var cb = options.querySelector('input[value="' + code + '"]');
        if (cb) {
            cb.checked = false;
            cb.closest('.lc-country-option').classList.remove('is-selected');
        }
        tag.remove();
    });

    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        options.querySelectorAll('.lc-country-option').forEach(function (el) {
            el.style.display = !q || el.dataset.name.indexOf(q) !== -1 ? '' : 'none';
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
