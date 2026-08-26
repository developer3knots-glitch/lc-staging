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
$smtpTestSteps = [];
if (!empty($_SESSION['lc_smtp_test_steps']) && is_array($_SESSION['lc_smtp_test_steps'])) {
    $smtpTestSteps = $_SESSION['lc_smtp_test_steps'];
    unset($_SESSION['lc_smtp_test_steps']);
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
            $inactOn = isset($_POST['inactivity_close_enabled']) ? '1' : '0';
            $closeMin = max(5, min(240, (int) ($_POST['inactivity_close_minutes'] ?? 15)));
            $warnMin = max(3, min(239, (int) ($_POST['inactivity_warn_minutes'] ?? 12)));
            if ($warnMin >= $closeMin) {
                $warnMin = max(3, $closeMin - 3);
            }
            lc_set_account_setting($accountId, 'inactivity_close_enabled', $inactOn);
            lc_set_account_setting($accountId, 'inactivity_close_minutes', (string) $closeMin);
            lc_set_account_setting($accountId, 'inactivity_warn_minutes', (string) $warnMin);
            $flashMessage = 'System settings saved successfully.';
        } elseif ($action === 'save_email') {
            lc_set_account_setting($accountId, 'mail_from', trim((string) ($_POST['mail_from'] ?? '')));
            lc_set_account_setting($accountId, 'mail_from_name', trim((string) ($_POST['mail_from_name'] ?? '')));
            lc_set_account_setting($accountId, 'smtp_enabled', isset($_POST['smtp_enabled']) ? '1' : '0');
            lc_set_account_setting($accountId, 'smtp_host', trim((string) ($_POST['smtp_host'] ?? '')));
            $port = (int) ($_POST['smtp_port'] ?? 587);
            lc_set_account_setting($accountId, 'smtp_port', (string) max(1, min(65535, $port)));
            lc_set_account_setting($accountId, 'smtp_user', trim((string) ($_POST['smtp_user'] ?? '')));
            $newPass = (string) ($_POST['smtp_pass'] ?? '');
            if (trim($newPass) !== '') {
                lc_set_account_setting($accountId, 'smtp_pass', $newPass);
            }
            $enc = strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls')));
            if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
                $enc = 'tls';
            }
            lc_set_account_setting($accountId, 'smtp_encryption', $enc);
            $flashMessage = 'Email / SMTP settings saved.';
        } elseif ($action === 'test_smtp') {
            $override = [
                'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
                'smtp_port' => trim((string) ($_POST['smtp_port'] ?? '')),
                'smtp_user' => trim((string) ($_POST['smtp_user'] ?? '')),
                'smtp_pass' => (string) ($_POST['smtp_pass'] ?? ''),
                'smtp_encryption' => trim((string) ($_POST['smtp_encryption'] ?? '')),
                'smtp_enabled' => '1',
            ];
            $result = function_exists('lc_mail_test_smtp')
                ? lc_mail_test_smtp($accountId, $override)
                : ['ok' => false, 'error' => 'SMTP test not available'];
            $_SESSION['lc_smtp_test_steps'] = $result['steps'] ?? [];
            if (!empty($result['ok'])) {
                $flashMessage = $result['detail'] ?? 'SMTP connection test passed.';
            } else {
                $flashError = $result['error'] ?? 'SMTP connection test failed.';
            }
        } elseif ($action === 'test_email') {
            $testTo = strtolower(trim((string) ($_POST['test_email_to'] ?? '')));
            if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $flashError = 'Enter a valid test email address.';
            } else {
                $sent = lc_mail_send(
                    $testTo,
                    'LiveChat test email',
                    "This is a test email from your LiveChat admin.\nIf you received this, SMTP/mail is working.\n",
                    '<p>This is a <strong>test email</strong> from your LiveChat admin.</p><p>If you received this, mail delivery is working.</p>',
                    $accountId
                );
                if (!empty($sent['ok'])) {
                    $via = $sent['via'] ?? 'mail';
                    $flashMessage = 'Test email sent via ' . $via . ' to ' . $testTo . '. Check inbox and Spam.';
                } else {
                    $flashError = $sent['error'] ?? 'Test email failed.';
                }
            }
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
$inactivityCloseEnabled = lc_account_setting($accountId, 'inactivity_close_enabled', '1') === '1';
$inactivityCloseMinutes = (int) lc_account_setting($accountId, 'inactivity_close_minutes', '15');
$inactivityWarnMinutes = (int) lc_account_setting($accountId, 'inactivity_warn_minutes', '12');
if ($inactivityCloseMinutes < 5) { $inactivityCloseMinutes = 15; }
if ($inactivityWarnMinutes < 3 || $inactivityWarnMinutes >= $inactivityCloseMinutes) {
    $inactivityWarnMinutes = max(3, $inactivityCloseMinutes - 3);
}
$prechatFormEnabled = lc_prechat_form_enabled($accountId);
$ticketDisplayEnabled = lc_ticket_display_enabled($accountId);
$ticketResumeEnabled = lc_ticket_resume_enabled($accountId);
$mailSettings = function_exists('lc_mail_settings') ? lc_mail_settings($accountId) : [];
$mailFrom = $mailSettings['mail_from'] ?? '';
$mailFromName = $mailSettings['mail_from_name'] ?? '';
$smtpEnabled = ($mailSettings['smtp_enabled'] ?? '0') === '1';
$smtpHost = $mailSettings['smtp_host'] ?? '';
$smtpPort = $mailSettings['smtp_port'] ?? '587';
$smtpUser = $mailSettings['smtp_user'] ?? '';
$smtpEnc = $mailSettings['smtp_encryption'] ?? 'tls';
$smtpPassSet = trim((string) ($mailSettings['smtp_pass'] ?? '')) !== '';

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
            <p class="lc-hero-sub">System performance, geo-blocking, and IP restrictions - secure your chat widget and fine-tune how your team operates.</p>
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
                            <div class="lc-settings-tile">
                                <div class="lc-settings-tile-icon violet"><i class="ti ti-hourglass"></i></div>
                                <div class="lc-settings-tile-body">
                                    <label class="lc-settings-toggle">
                                        <input type="checkbox" name="inactivity_close_enabled" <?= $inactivityCloseEnabled ? 'checked' : '' ?>>
                                        <span class="lc-settings-toggle-ui"></span>
                                        <span class="lc-settings-toggle-text">
                                            <strong>Auto-close inactive chats</strong>
                                            <small>Warn, then close waiting/active chats with no messages</small>
                                        </span>
                                    </label>
                                    <div class="lc-settings-inline-fields" style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-top:0.75rem">
                                        <label style="display:flex;flex-direction:column;gap:0.25rem;font-size:0.85rem">
                                            Warn after (minutes)
                                            <input type="number" name="inactivity_warn_minutes" min="3" max="239" value="<?= (int) $inactivityWarnMinutes ?>">
                                        </label>
                                        <label style="display:flex;flex-direction:column;gap:0.25rem;font-size:0.85rem">
                                            Close after (minutes)
                                            <input type="number" name="inactivity_close_minutes" min="5" max="240" value="<?= (int) $inactivityCloseMinutes ?>">
                                        </label>
                                    </div>
                                </div>
                            </div>                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save system settings</button>
                    </form>
                </div>
            </section>

            <section class="lc-card lc-card-pro lc-settings-section" id="lc-settings-email">
                <div class="lc-card-head">
                    <h3><i class="ti ti-mail"></i> Email / SMTP</h3>
                    <span class="lc-card-sub">Chat transcript delivery</span>
                </div>
                <div class="lc-card-body">
                    <p class="lc-settings-desc">Gmail often blocks plain PHP <code>mail()</code>. Use your <strong>cPanel email</strong> over SMTP (recommended) so transcript emails actually arrive.</p>
                    <form method="post" class="lc-form lc-settings-system-form">
                        <input type="hidden" name="action" value="save_email">
                        <div class="lc-form-row">
                            <label for="lc-mail-from">From email</label>
                            <input type="email" id="lc-mail-from" name="mail_from" value="<?= lc_sanitize($mailFrom) ?>" placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="lc-form-row">
                            <label for="lc-mail-from-name">From name (fallback)</label>
                            <input type="text" id="lc-mail-from-name" name="mail_from_name" value="<?= lc_sanitize($mailFromName) ?>" placeholder="LiveChat Support">
                            <small class="lc-settings-desc" style="margin-top:0.35rem;display:block">Chat transcripts use the <strong>brand name</strong> of the widget/conversation as the From name. This field is only used when brand is missing.</small>
                        </div>
                        <label class="lc-settings-toggle" style="margin:1rem 0">
                            <input type="checkbox" name="smtp_enabled" <?= $smtpEnabled ? 'checked' : '' ?>>
                            <span class="lc-settings-toggle-ui"></span>
                            <span class="lc-settings-toggle-text">
                                <strong>Use SMTP (recommended)</strong>
                                <small>Send via cPanel mailbox - host often <code>mail.yourdomain.com</code>, port <code>587</code>, encryption <code>TLS</code></small>
                            </span>
                        </label>
                        <div class="lc-form-row">
                            <label for="lc-smtp-host">SMTP host</label>
                            <input type="text" id="lc-smtp-host" name="smtp_host" value="<?= lc_sanitize($smtpHost) ?>" placeholder="mail.yourdomain.com">
                        </div>
                        <div class="lc-form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                            <div>
                                <label for="lc-smtp-port">Port</label>
                                <input type="number" id="lc-smtp-port" name="smtp_port" value="<?= lc_sanitize($smtpPort) ?>" min="1" max="65535">
                            </div>
                            <div>
                                <label for="lc-smtp-enc">Encryption</label>
                                <select id="lc-smtp-enc" name="smtp_encryption">
                                    <option value="tls" <?= $smtpEnc === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                    <option value="ssl" <?= $smtpEnc === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                    <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>None</option>
                                </select>
                            </div>
                        </div>
                        <div class="lc-form-row">
                            <label for="lc-smtp-user">SMTP username</label>
                            <input type="text" id="lc-smtp-user" name="smtp_user" value="<?= lc_sanitize($smtpUser) ?>" placeholder="noreply@yourdomain.com" autocomplete="off">
                        </div>
                        <div class="lc-form-row">
                            <label for="lc-smtp-pass">SMTP password</label>
                            <input type="password" id="lc-smtp-pass" name="smtp_pass" value="" placeholder="<?= $smtpPassSet ? '-------- (saved - leave blank to keep)' : 'Mailbox password' ?>" autocomplete="new-password">
                        </div>
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save email settings</button>
                    </form>
                    <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--lc-border)">
                    <div class="lc-smtp-test-block">
                        <h4 style="margin:0 0 0.35rem;font-size:0.95rem">Test mode - SMTP connection</h4>
                        <p class="lc-settings-desc" style="margin-bottom:0.75rem">Checks connect → TLS → login only (does not send mail). Uses the fields above; password can be typed here without saving first.</p>
                        <form method="post" class="lc-form" id="lc-smtp-test-form" style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;margin-bottom:0.75rem">
                            <input type="hidden" name="action" value="test_smtp">
                            <input type="hidden" name="smtp_host" id="lc-test-smtp-host" value="">
                            <input type="hidden" name="smtp_port" id="lc-test-smtp-port" value="">
                            <input type="hidden" name="smtp_user" id="lc-test-smtp-user" value="">
                            <input type="hidden" name="smtp_pass" id="lc-test-smtp-pass" value="">
                            <input type="hidden" name="smtp_encryption" id="lc-test-smtp-enc" value="">
                            <button type="submit" class="lc-btn lc-btn-secondary"><i class="ti ti-plug-connected"></i> Test SMTP connection</button>
                        </form><?php if (!empty($smtpTestSteps)): ?>
                        <ul class="lc-smtp-test-steps" style="list-style:none;margin:0;padding:0.5rem 0.75rem;border:1px solid var(--lc-border);border-radius:8px;font-size:0.85rem;background:var(--lc-surface-2, #f8fafc)">
<?php foreach ($smtpTestSteps as $st): ?>
                            <li style="padding:0.2rem 0;display:flex;gap:0.5rem;align-items:baseline">
                                <span style="font-weight:600;min-width:5.5rem;color:<?= !empty($st['ok']) ? '#15803d' : '#b91c1c' ?>"><?= !empty($st['ok']) ? '✓' : '✗' ?> <?= lc_sanitize((string) ($st['step'] ?? '')) ?></span>
                                <span style="color:var(--lc-muted, #64748b)"><?= lc_sanitize((string) ($st['detail'] ?? '')) ?></span>
                            </li>
<?php endforeach; ?>
                        </ul>
<?php endif; ?>
                    </div>
                    <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--lc-border)">                    <form method="post" class="lc-form" style="display:flex;gap:0.6rem;align-items:flex-end;flex-wrap:wrap">
                        <input type="hidden" name="action" value="test_email">
                        <div class="lc-form-row" style="flex:1;min-width:220px;margin:0">
                            <label for="lc-test-email">Send test email to</label>
                            <input type="email" id="lc-test-email" name="test_email_to" required placeholder="you@gmail.com" value="<?= lc_sanitize($agent['email'] ?? '') ?>">
                        </div>
                        <button type="submit" class="lc-btn lc-btn-secondary"><i class="ti ti-send"></i> Send test email</button>
                    </form>
                    <script>
(function () {
  var form = document.getElementById('lc-smtp-test-form');
  if (!form) return;
  form.addEventListener('submit', function () {
    var g = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };
    document.getElementById('lc-test-smtp-host').value = g('lc-smtp-host');
    document.getElementById('lc-test-smtp-port').value = g('lc-smtp-port');
    document.getElementById('lc-test-smtp-user').value = g('lc-smtp-user');
    document.getElementById('lc-test-smtp-pass').value = g('lc-smtp-pass');
    document.getElementById('lc-test-smtp-enc').value = g('lc-smtp-enc');
  });
})();
                    </script>
                </div>
            </section>
            <section class="lc-card lc-card-pro lc-settings-section" id="lc-settings-tickets">
                <div class="lc-card-head">
                    <h3><i class="ti ti-ticket"></i> Support Tickets</h3>
                    <span class="lc-card-sub">Visitor widget features</span>
                </div>
                <div class="lc-card-body">
                    <p class="lc-settings-desc">Visitor-facing ticket features only. Tickets are always saved for agents. Returning visitors do <strong>not</strong> see old chats automatically - only via ticket resume when enabled below.</p>
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
                                    <span class="lc-country-tags-empty">No countries blocked yet - select below</span>
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
                                <input type="search" class="lc-country-search" id="lc-country-search" placeholder="Search countries..." autocomplete="off">
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
                    <p class="lc-settings-desc">Block a specific visitor IP - widget hidden for that network. You can also block from the live chat screen.</p>
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
                                <span>Your block list is empty - all visitors can see the widget.</span>
                            </div>
                        <?php else: foreach ($blockedIps as $row): ?>
                            <div class="lc-blocked-ip-item">
                                <div class="lc-blocked-ip-main">
                                    <code><?= lc_sanitize($row['ip_address']) ?></code>
                                    <span><?= lc_sanitize($row['reason'] ?: 'No reason') ?></span>
                                    <small>
                                        <?= lc_sanitize($row['blocked_by_name'] ?: 'System') ?> - <?= lc_time_span($row['created_at']) ?>
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
