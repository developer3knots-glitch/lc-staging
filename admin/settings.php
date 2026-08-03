<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_admin();
lc_ensure_blocking_schema();
lc_ensure_accounts_schema();

$message = '';
$error = '';
$accountId = lc_agent_account_id($agent);

// Flash messages from Post-Redirect-Get (avoids browser resubmit alert on refresh)
if (!empty($_SESSION['lc_settings_flash'])) {
    $flash = $_SESSION['lc_settings_flash'];
    unset($_SESSION['lc_settings_flash']);
    if (!empty($flash['message'])) {
        $message = (string) $flash['message'];
    }
    if (!empty($flash['error'])) {
        $error = (string) $flash['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flashMessage = '';
    $flashError = '';

    try {
        if ($action === 'save_system') {
            $poll = (int) ($_POST['poll_interval_ms'] ?? 1500);
            $poll = max(800, min(5000, $poll));
            lc_set_account_setting($accountId, 'auto_assign', isset($_POST['auto_assign']) ? '1' : '0');
            lc_set_account_setting($accountId, 'poll_interval_ms', (string) $poll);
            $prechatOn = isset($_POST['prechat_form_enabled']) ? '1' : '0';
            lc_set_account_setting($accountId, 'prechat_form_enabled', $prechatOn);
            // Keep global fallback in sync (brands without account_id / legacy installs)
            if (function_exists('lc_set_setting')) {
                lc_set_setting('prechat_form_enabled', $prechatOn);
            }
            $flashMessage = 'System settings saved successfully.';
        } elseif ($action === 'save_tickets') {
            $displayOn = isset($_POST['ticket_display_enabled']) ? '1' : '0';
            $resumeOn = isset($_POST['ticket_resume_enabled']) ? '1' : '0';
            lc_set_account_setting($accountId, 'ticket_display_enabled', $displayOn);
            lc_set_account_setting($accountId, 'ticket_resume_enabled', $resumeOn);
            if (function_exists('lc_set_setting')) {
                lc_set_setting('ticket_display_enabled', $displayOn);
                lc_set_setting('ticket_resume_enabled', $resumeOn);
            }
            $flashMessage = 'Ticket settings saved successfully.';
        } elseif ($action === 'save_countries') {
            $countryCodes = $_POST['blocked_countries'] ?? [];
            if (!is_array($countryCodes)) {
                $countryCodes = [];
            }
            lc_save_blocked_country_codes($countryCodes, $accountId);
            $flashMessage = 'Country block list updated.';
        } elseif ($action === 'block_ip') {
            $ip = trim((string) ($_POST['ip_address'] ?? ''));
            $reason = trim((string) ($_POST['ip_reason'] ?? ''));
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $flashError = 'Enter a valid IP address.';
            } elseif (lc_is_local_ip($ip)) {
                $flashError = 'Local network IPs cannot be blocked.';
            } elseif (lc_block_ip($ip, (int) $agent['id'], $reason, $accountId)) {
                $flashMessage = 'IP ' . $ip . ' has been blocked.';
            } else {
                $flashError = 'Unable to block that IP.';
            }
        } elseif ($action === 'unblock_ip') {
            $ip = trim((string) ($_POST['ip_address'] ?? ''));
            if ($ip !== '' && lc_unblock_ip($ip, $accountId)) {
                $flashMessage = 'IP ' . $ip . ' has been unblocked.';
            } else {
                $flashError = 'IP not found in block list.';
            }
        }
    } catch (Throwable $e) {
        $flashError = 'Could not save settings. Please try again.';
        error_log('LiveChat settings save error: ' . $e->getMessage());
    }

    $_SESSION['lc_settings_flash'] = [
        'message' => $flashMessage,
        'error' => $flashError,
    ];
    header('Location: ' . lc_admin_url('settings'));
    exit;
}

$blockedCountries = lc_get_blocked_country_codes($accountId);
$blockedIps = lc_get_blocked_ips($accountId);
$allCountries = lc_country_list();
asort($allCountries);

$pollMs = (int) lc_account_setting($accountId, 'poll_interval_ms', '1500');
$autoAssign = lc_account_setting($accountId, 'auto_assign', '1') === '1';
$prechatFormEnabled = lc_prechat_form_enabled($accountId);
$ticketDisplayEnabled = lc_ticket_display_enabled($accountId);
$ticketResumeEnabled = lc_ticket_resume_enabled($accountId);

$pageTitle = 'Settings';
$activeNav = 'settings';
$extraScript = 'admin-settings.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-settings-page">
    <?php if ($message): ?><div class="lc-alert lc-alert-success lc-settings-alert"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error lc-settings-alert"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-settings">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-adjustments"></i> Control Center</div>
            <h1 class="lc-hero-title">Settings</h1>
            <p class="lc-hero-sub">System performance, geo-blocking, and IP restrictions — secure your chat widget and fine-tune how your team operates.</p>
            <div class="lc-hero-actions">
                <a href="<?= lc_admin_url('brands') ?>" class="lc-hero-chip"><i class="ti ti-tags"></i> Brand widgets</a>
                <a href="#lc-settings-security" class="lc-hero-chip"><i class="ti ti-shield"></i> Security</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $pollMs ?></span>
                <span class="lc-hero-metric-label">Poll (ms)</span>
            </div>
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= count($blockedCountries) ?></span>
                <span class="lc-hero-metric-label">Countries</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= count($blockedIps) ?></span>
                <span class="lc-hero-metric-label">Blocked IPs</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $ticketDisplayEnabled ? 'On' : 'Off' ?></span>
                <span class="lc-hero-metric-label">Ticket ID</span>
            </div>
        </div>
    </div>

    <nav class="lc-settings-nav" aria-label="Settings sections">
        <a href="#lc-settings-system" class="lc-settings-nav-link is-active"><i class="ti ti-settings"></i> System</a>
        <a href="#lc-settings-tickets" class="lc-settings-nav-link"><i class="ti ti-ticket"></i> Tickets</a>
        <a href="#lc-settings-geo" class="lc-settings-nav-link"><i class="ti ti-world-off"></i> Countries</a>
        <a href="#lc-settings-security" class="lc-settings-nav-link"><i class="ti ti-ban"></i> IP Block</a>
    </nav>

    <div class="lc-settings-layout">
        <div class="lc-settings-main">
            <section class="lc-card lc-card-pro lc-settings-section" id="lc-settings-system">
                <div class="lc-card-head">
                    <h3><i class="ti ti-settings"></i> System Settings</h3>
                    <span class="lc-card-sub">Real-time &amp; routing</span>
                </div>
                <div class="lc-card-body">
                    <form method="post" class="lc-form lc-settings-system-form">
                        <input type="hidden" name="action" value="save_system">
                        <div class="lc-settings-tiles">
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon blue"><i class="ti ti-refresh"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label for="lc-poll-interval">Poll interval (ms)</label>
                                    <p class="lc-settings-tile-hint">How fast the inbox refreshes. Lower = faster, more server load.</p>
                                    <input type="range" id="lc-poll-interval-range" min="800" max="5000" step="100" value="<?= $pollMs ?>">
                                    <div class="lc-settings-range-val">
                                        <input type="number" name="poll_interval_ms" id="lc-poll-interval" min="800" max="5000" value="<?= $pollMs ?>">
                                        <span>ms</span>
                                    </div>
                                </div>
                            </div>
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon green"><i class="ti ti-users"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label class="lc-settings-toggle">
                                        <input type="checkbox" name="auto_assign" <?= $autoAssign ? 'checked' : '' ?>>
                                        <span class="lc-settings-toggle-ui"></span>
                                        <span class="lc-settings-toggle-text">
                                            <strong>Auto-assign chats</strong>
                                            <small>Waiting chats go to the next available online agent</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon blue"><i class="ti ti-forms"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label class="lc-settings-toggle">
                                        <input type="checkbox" name="prechat_form_enabled" <?= $prechatFormEnabled ? 'checked' : '' ?>>
                                        <span class="lc-settings-toggle-ui"></span>
                                        <span class="lc-settings-toggle-text">
                                            <strong>Pre-chat form</strong>
                                            <small>Require name, email &amp; first message before a <em>new</em> chat. Active chats keep going without the form (LiveChat standard)</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save system settings</button>
                    </form>
                </div>
            </section>

            <section class="lc-card lc-card-pro lc-settings-section" id="lc-settings-tickets">
                <div class="lc-card-head">
                    <h3><i class="ti ti-ticket"></i> Support Tickets</h3>
                    <span class="lc-card-sub">Visitor widget features</span>
                </div>
                <div class="lc-card-body">
                    <p class="lc-settings-desc">Visitor-facing ticket features only. Tickets are always saved for agents. Returning visitors do <strong>not</strong> see old chats automatically — only via ticket resume when enabled below.</p>
                    <form method="post" class="lc-form lc-settings-system-form">
                        <input type="hidden" name="action" value="save_tickets">
                        <div class="lc-settings-tiles">
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon violet"><i class="ti ti-hash"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label class="lc-settings-toggle">
                                        <input type="checkbox" name="ticket_display_enabled" <?= $ticketDisplayEnabled ? 'checked' : '' ?>>
                                        <span class="lc-settings-toggle-ui"></span>
                                        <span class="lc-settings-toggle-text">
                                            <strong>Show tickets to visitors</strong>
                                            <small>Ticket ID in widget header, system chat message, and closed screen. Off = visitors never see ticket numbers</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon amber"><i class="ti ti-login-2"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label class="lc-settings-toggle">
                                        <input type="checkbox" name="ticket_resume_enabled" <?= $ticketResumeEnabled ? 'checked' : '' ?>>
                                        <span class="lc-settings-toggle-ui"></span>
                                        <span class="lc-settings-toggle-text">
                                            <strong>Resume chat by ticket</strong>
                                            <small>Visitor enters ticket + email → both visitor and agent open the <em>same</em> old conversation. Off = no resume form</small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save ticket settings</button>
                    </form>
                </div>
            </section>

            <section class="lc-card lc-card-pro lc-settings-section lc-settings-geo-pro" id="lc-settings-geo">
                <div class="lc-card-head">
                    <h3><i class="ti ti-world-off"></i> Country Blocking</h3>
                    <span class="lc-card-sub"><?= count($blockedCountries) ?> blocked</span>
                </div>
                <div class="lc-card-body">
                    <p class="lc-settings-desc">Visitors from selected countries will <strong>not see the chat widget</strong> on your site. Existing chats are not affected.</p>
                    <form method="post" class="lc-form" id="lc-country-block-form">
                        <input type="hidden" name="action" value="save_countries">
                        <div class="lc-country-picker lc-country-picker-pro" id="lc-country-picker">
                            <div class="lc-country-tags" id="lc-country-tags">
                                <?php if (empty($blockedCountries)): ?>
                                    <span class="lc-country-tags-empty">No countries blocked yet — select below</span>
                                <?php endif; ?>
                                <?php foreach ($blockedCountries as $code): ?>
                                    <span class="lc-country-tag" data-code="<?= lc_sanitize($code) ?>">
                                        <?= lc_sanitize(lc_country_name($code)) ?> (<?= lc_sanitize($code) ?>)
                                        <button type="button" class="lc-country-tag-remove" aria-label="Remove">&times;</button>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            <div class="lc-country-search-wrap">
                                <i class="ti ti-search"></i>
                                <input type="search" class="lc-country-search" id="lc-country-search" placeholder="Search countries…" autocomplete="off">
                            </div>
                            <div class="lc-country-options" id="lc-country-options">
                                <?php foreach ($allCountries as $code => $name): ?>
                                    <label class="lc-country-option<?= in_array($code, $blockedCountries, true) ? ' is-selected' : '' ?>" data-name="<?= lc_sanitize(strtolower($name . ' ' . $code)) ?>">
                                        <span class="lc-country-option-main">
                                            <span class="lc-country-code"><?= lc_sanitize($code) ?></span>
                                            <span class="lc-country-name"><?= lc_sanitize($name) ?></span>
                                        </span>
                                        <span class="lc-country-check">
                                            <input type="checkbox" name="blocked_countries[]" value="<?= lc_sanitize($code) ?>"<?= in_array($code, $blockedCountries, true) ? ' checked' : '' ?>>
                                            <span class="lc-country-check-ui" aria-hidden="true"><i class="ti ti-check"></i></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary lc-country-save-btn"><i class="ti ti-shield-off"></i> Save country blocks</button>
                    </form>
                </div>
            </section>
        </div>

        <aside class="lc-settings-aside">
            <section class="lc-card lc-card-pro lc-settings-section" id="lc-settings-security">
                <div class="lc-card-head">
                    <h3><i class="ti ti-ban"></i> IP Blocking</h3>
                    <span class="lc-card-sub"><?= count($blockedIps) ?> blocked</span>
                </div>
                <div class="lc-card-body">
                    <p class="lc-settings-desc">Block a specific visitor IP — widget hidden for that network. You can also block from the live chat screen.</p>
                    <form method="post" class="lc-form lc-ip-block-form-pro">
                        <input type="hidden" name="action" value="block_ip">
                        <label>IP address</label>
                        <input type="text" name="ip_address" placeholder="e.g. 203.0.113.45" required>
                        <label>Reason <small>(optional)</small></label>
                        <input type="text" name="ip_reason" placeholder="Spam / abuse" maxlength="255">
                        <button type="submit" class="lc-btn lc-btn-danger lc-btn-block"><i class="ti ti-ban"></i> Block this IP</button>
                    </form>

                    <div class="lc-blocked-ip-list">
                        <div class="lc-blocked-ip-head"><i class="ti ti-list"></i> Blocked list</div>
                        <?php if (empty($blockedIps)): ?>
                            <div class="lc-blocked-ip-empty">
                                <i class="ti ti-shield-check"></i>
                                <p>No blocked IPs</p>
                                <span>Your block list is empty — all visitors can see the widget.</span>
                            </div>
                        <?php else: foreach ($blockedIps as $row): ?>
                            <div class="lc-blocked-ip-item">
                                <div class="lc-blocked-ip-main">
                                    <code><?= lc_sanitize($row['ip_address']) ?></code>
                                    <span><?= lc_sanitize($row['reason'] ?: 'No reason') ?></span>
                                    <small>
                                        <?= lc_sanitize($row['blocked_by_name'] ?: 'System') ?>
                                        · <?= lc_time_span($row['created_at']) ?>
                                    </small>
                                </div>
                                <form method="post" onsubmit="return confirm('Unblock this IP?');">
                                    <input type="hidden" name="action" value="unblock_ip">
                                    <input type="hidden" name="ip_address" value="<?= lc_sanitize($row['ip_address']) ?>">
                                    <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm" title="Unblock"><i class="ti ti-lock-open"></i></button>
                                </form>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </section>

            <div class="lc-card lc-card-pro">
                <div class="lc-card-body">
                    <div class="lc-settings-tip">
                        <i class="ti ti-info-circle"></i>
                        <div>
                            <strong>Brand-specific settings</strong>
                            <p>Widget color, welcome message, and offline text are managed per brand on the <a href="<?= lc_admin_url('brands') ?>">Brands</a> page.</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
