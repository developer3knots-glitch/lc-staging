<?php
require_once __DIR__ . '/../includes/auth.php';
$currentAgent = lc_require_admin();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$db = lc_db();
$accountId = lc_agent_account_id($currentAgent);
$message = '';
$error = '';
$openEditId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $key = trim($_POST['brand_key'] ?? '');
        $color = trim($_POST['widget_color'] ?? '#6FD943');
        $theme = strtolower(trim($_POST['widget_theme'] ?? 'dark'));
        if (!in_array($theme, ['light', 'dark'], true)) {
            $theme = 'dark';
        }
        $welcome = trim($_POST['welcome_message'] ?? '');
        $offline = trim($_POST['offline_message'] ?? '');
        lc_ensure_brand_prechat_columns();
        lc_ensure_brand_widget_columns();
        $prechatOn = isset($_POST['prechat_form_enabled']) ? 1 : 0;
        $prechatJson = lc_prechat_config_from_post();
        $startGreeting = isset($_POST['start_chat_greeting']) ? 1 : 0;
        $startGreetingMessage = trim((string) ($_POST['start_chat_greeting_message'] ?? ''));
        $startGreetingMessage = function_exists('mb_substr')
            ? mb_substr($startGreetingMessage, 0, 1000)
            : substr($startGreetingMessage, 0, 1000);

        if ($name === '') {
            $error = 'Brand name is required.';
        } else {
            $key = $key !== '' ? lc_slug_key($key) : lc_slug_key($name);
            $key = lc_unique_brand_key($key);
            $stmt = $db->prepare('INSERT INTO brands (account_id, name, brand_key, widget_color, widget_theme, welcome_message, offline_message, prechat_form_enabled, prechat_form_config, start_chat_greeting, start_chat_greeting_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$accountId > 0 ? $accountId : null, $name, $key, $color, $theme, $welcome, $offline, $prechatOn, $prechatJson, $startGreeting, $startGreetingMessage]);
            $message = 'Brand created. Copy its install code and add it to the website.';
            $openEditId = (int) $db->lastInsertId();
        }
    } elseif ($action === 'edit' && !empty($_POST['id'])) {
        $id = (int) $_POST['id'];
        if ($accountId > 0) {
            $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
            $own->execute([$id, $accountId]);
            if (!$own->fetch()) {
                $error = 'Brand not found.';
            }
        }
        if ($error === '') {
            $name = trim($_POST['name'] ?? '');
            $key = lc_slug_key(trim($_POST['brand_key'] ?? ''));
            $key = lc_unique_brand_key($key, $id);
            lc_ensure_brand_widget_columns();
            $theme = strtolower(trim($_POST['widget_theme'] ?? 'dark'));
            if (!in_array($theme, ['light', 'dark'], true)) {
                $theme = 'dark';
            }
            lc_ensure_brand_prechat_columns();
            lc_ensure_brand_widget_columns();
            $prechatOn = isset($_POST['prechat_form_enabled']) ? 1 : 0;
            $prechatJson = lc_prechat_config_from_post();
            $startGreeting = isset($_POST['start_chat_greeting']) ? 1 : 0;
            $startGreetingMessage = trim((string) ($_POST['start_chat_greeting_message'] ?? ''));
            $startGreetingMessage = function_exists('mb_substr')
                ? mb_substr($startGreetingMessage, 0, 1000)
                : substr($startGreetingMessage, 0, 1000);
            $sql = 'UPDATE brands SET name = ?, brand_key = ?, widget_color = ?, widget_theme = ?, welcome_message = ?, offline_message = ?, is_active = ?, prechat_form_enabled = ?, prechat_form_config = ?, start_chat_greeting = ?, start_chat_greeting_message = ? WHERE id = ?';
            $params = [
                $name,
                $key,
                trim($_POST['widget_color'] ?? '#6FD943'),
                $theme,
                trim($_POST['welcome_message'] ?? ''),
                trim($_POST['offline_message'] ?? ''),
                isset($_POST['is_active']) ? 1 : 0,
                $prechatOn,
                $prechatJson,
                $startGreeting,
                $startGreetingMessage,
                $id,
            ];
            if ($accountId > 0) {
                $sql .= ' AND account_id = ?';
                $params[] = $accountId;
            }
            $db->prepare($sql)->execute($params);
            $message = 'Brand settings saved.';
            $openEditId = $id;
        }
    } elseif ($action === 'toggle' && !empty($_POST['id'])) {
        $sql = 'UPDATE brands SET is_active = IF(is_active=1,0,1) WHERE id = ?';
        $params = [(int) $_POST['id']];
        if ($accountId > 0) {
            $sql .= ' AND account_id = ?';
            $params[] = $accountId;
        }
        $db->prepare($sql)->execute($params);
        $message = 'Brand status updated.';
    }
}

$brands = lc_get_all_brands(false, $accountId > 0 ? $accountId : null);
$activeCount = 0;
foreach ($brands as $b) {
    if (!empty($b['is_active'])) {
        $activeCount++;
    }
}
$inactiveCount = count($brands) - $activeCount;
$selectedBrandId = $openEditId;
if ($selectedBrandId < 1 && $brands) {
    $selectedBrandId = (int) $brands[0]['id'];
}

$pageTitle = 'Brands';
$activeNav = 'brands';
$extraScript = 'admin-brands.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-brands">
    <?php if ($message): ?><div class="lc-alert lc-alert-success lc-brands-alert"><i class="ti ti-circle-check"></i> <?= lc_sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="lc-alert lc-alert-error lc-brands-alert"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($error) ?></div><?php endif; ?>

    <div class="lc-hero lc-hero-pro lc-hero-brands">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-tags"></i> Multi-Brand Workspace</div>
            <h1 class="lc-hero-title">Chat Brands</h1>
            <p class="lc-hero-sub">Each brand gets its own widget, welcome message, pre-chat form, and inbox.</p>
            <div class="lc-hero-actions">
                <button type="button" class="lc-hero-chip" id="lc-brand-new-open"><i class="ti ti-plus"></i> New brand</button>
                <a href="<?= LC_BASE_URL ?>/demo.html" target="_blank" class="lc-hero-chip"><i class="ti ti-external-link"></i> Widget demo</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-3">
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= count($brands) ?></span>
                <span class="lc-hero-metric-label">Total brands</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $activeCount ?></span>
                <span class="lc-hero-metric-label">Active</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= $inactiveCount ?></span>
                <span class="lc-hero-metric-label">Inactive</span>
            </div>
        </div>
    </div>

    <div class="lc-brands-workspace">
        <aside class="lc-brands-list" id="lc-brands-grid">
            <div class="lc-brands-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-brands-search" placeholder="Search brands..." autocomplete="off">
            </div>
            <button type="button" class="lc-brand-pick lc-brand-pick-new" id="lc-brand-new-side" data-pick="new">
                <i class="ti ti-plus"></i>
                <span><strong>New brand</strong><small>Add another website</small></span>
            </button>
            <?php if (!$brands): ?>
                <p class="lc-brands-list-empty">No brands yet. Create the first one.</p>
            <?php endif; ?>
            <?php foreach ($brands as $b):
                $color = $b['widget_color'] ?? '#6FD943';
                $bid = (int) $b['id'];
                $isSelected = $selectedBrandId === $bid;
            ?>
            <button type="button" class="lc-brand-pick<?= $isSelected ? ' is-active' : '' ?><?= empty($b['is_active']) ? ' is-inactive' : '' ?>" data-pick="<?= $bid ?>" data-search="<?= lc_sanitize(strtolower($b['name'] . ' ' . $b['brand_key'])) ?>" style="--brand-color: <?= lc_sanitize($color) ?>">
                <span class="lc-brand-pick-dot"></span>
                <span class="lc-brand-pick-copy">
                    <strong><?= lc_sanitize($b['name']) ?></strong>
                    <small><?= lc_sanitize($b['brand_key']) ?></small>
                </span>
                <?php if (empty($b['is_active'])): ?>
                    <span class="lc-brand-pick-flag">Off</span>
                <?php elseif (lc_brand_prechat_enabled($b)): ?>
                    <span class="lc-brand-pick-flag is-form">Form</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </aside>

        <div class="lc-brands-stage">
            <section class="lc-brand-editor lc-brand-create" id="lc-brand-create" data-brand-panel="new"<?= $brands ? ' hidden' : '' ?>>
                <header class="lc-brand-editor-head">
                    <div>
                        <p class="lc-brand-kicker">New brand</p>
                        <h2>Create a widget</h2>
                        <p>Name the site, choose a color, then add messages or a pre-chat form if you want them.</p>
                    </div>
                </header>
                <form method="post" class="lc-form lc-brand-create-form">
                    <input type="hidden" name="action" value="add">
                    <div class="lc-brand-editor-body">
                        <div class="lc-brand-tabs" role="tablist">
                            <button type="button" class="is-active" data-brand-tab="widget">Widget</button>
                            <button type="button" data-brand-tab="messages">Messages</button>
                            <button type="button" data-brand-tab="prechat">Pre-chat</button>
                        </div>
                        <section class="lc-brand-pane is-active" data-brand-pane="widget">
                            <p class="lc-brand-pane-lead">This is what visitors see on the chat bubble.</p>
                            <div class="lc-brand-edit-grid">
                                <div>
                                    <label>Brand name</label>
                                    <input type="text" name="name" required placeholder="e.g. 3Knots Digital">
                                </div>
                                <div>
                                    <label>Brand key <small>(optional)</small></label>
                                    <input type="text" name="brand_key" placeholder="Auto-generated from the name">
                                </div>
                                <div class="lc-brand-color-field">
                                    <label>Widget color</label>
                                    <div class="lc-brand-color-input">
                                        <input type="color" name="widget_color" value="#6FD943" id="lc-new-brand-color">
                                        <span id="lc-new-brand-color-val">#6FD943</span>
                                    </div>
                                </div>
                                <div>
                                    <label>Widget theme</label>
                                    <select name="widget_theme">
                                        <option value="dark" selected>Dark</option>
                                        <option value="light">Light</option>
                                    </select>
                                </div>
                            </div>
                        </section>
                        <section class="lc-brand-pane" data-brand-pane="messages">
                            <p class="lc-brand-pane-lead">Welcome shows when a new chat starts. Offline shows when every agent is away.</p>
                            <label>Welcome message</label>
                            <textarea name="welcome_message" rows="3" placeholder="Leave blank if you do not want a greeting"></textarea>
                            <label>Offline message</label>
                            <textarea name="offline_message" rows="3" placeholder="We are away right now. Leave a message and we will reply."></textarea>
                            <label class="lc-settings-toggle">
                                <input type="checkbox" name="start_chat_greeting" checked>
                                <span class="lc-settings-toggle-ui"></span>
                                <span class="lc-settings-toggle-text">
                                    <strong>Send a message when an agent starts a chat</strong>
                                    <small>Only this brand. On: Traffic “Start chat” sends the message below. Off: the chat still opens, without that message.</small>
                                </span>
                            </label>
                            <label>Start chat message</label>
                            <textarea name="start_chat_greeting_message" rows="2" maxlength="1000">Hello How are you doing?</textarea>
                        </section>
                        <section class="lc-brand-pane" data-brand-pane="prechat">
                            <p class="lc-brand-pane-lead">Turn this on only for this brand. Other brands keep their own forms.</p>
                            <?= lc_prechat_builder_markup(lc_prechat_form_defaults(), false) ?>
                        </section>
                    </div>
                    <div class="lc-brand-editor-foot">
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-plus"></i> Create brand</button>
                    </div>
                </form>
            </section>

            <?php foreach ($brands as $b):
                $color = $b['widget_color'] ?? '#6FD943';
                $embed = lc_brand_embed_code($b);
                $bid = (int) $b['id'];
                $isDefault = ($b['brand_key'] ?? '') === 'default';
                $theme = strtolower((string) ($b['widget_theme'] ?? 'dark'));
                $isSelected = $selectedBrandId === $bid;
            ?>
            <section class="lc-brand-editor" id="lc-brand-edit-<?= $bid ?>" data-brand-panel="<?= $bid ?>"<?= $isSelected ? '' : ' hidden' ?>>
                <header class="lc-brand-editor-head">
                    <div>
                        <p class="lc-brand-kicker"><?= !empty($b['is_active']) ? 'Active brand' : 'Inactive brand' ?></p>
                        <h2><?= lc_sanitize($b['name']) ?></h2>
                        <p>Changes here apply only to <code><?= lc_sanitize($b['brand_key']) ?></code>.</p>
                    </div>
                    <div class="lc-brand-editor-tools">
                        <a href="<?= LC_BASE_URL ?>/demo.html?brand=<?= urlencode($b['brand_key']) ?>" target="_blank" class="lc-btn lc-btn-secondary lc-btn-sm">
                            <i class="ti ti-external-link"></i> Test
                        </a>
                        <?php if (!$isDefault): ?>
                        <form method="post" onsubmit="return confirm('Change brand status?');">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $bid ?>">
                            <button type="submit" class="lc-btn lc-btn-secondary lc-btn-sm">
                                <i class="ti ti-power"></i> <?= !empty($b['is_active']) ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </header>
                <form method="post" class="lc-form lc-brand-edit-form">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= $bid ?>">
                    <div class="lc-brand-editor-body">
                        <div class="lc-brand-tabs" role="tablist">
                            <button type="button" class="is-active" data-brand-tab="widget">Widget</button>
                            <button type="button" data-brand-tab="messages">Messages</button>
                            <button type="button" data-brand-tab="prechat">Pre-chat</button>
                            <button type="button" data-brand-tab="install">Install</button>
                        </div>
                        <section class="lc-brand-pane is-active" data-brand-pane="widget">
                            <p class="lc-brand-pane-lead">Name, color, and theme for this website only.</p>
                            <div class="lc-brand-edit-grid">
                                <div>
                                    <label>Brand name</label>
                                    <input type="text" name="name" value="<?= lc_sanitize($b['name']) ?>" required>
                                </div>
                                <div>
                                    <label>Brand key</label>
                                    <input type="text" name="brand_key" value="<?= lc_sanitize($b['brand_key']) ?>" required>
                                </div>
                                <div class="lc-brand-color-field">
                                    <label>Widget color</label>
                                    <div class="lc-brand-color-input">
                                        <input type="color" name="widget_color" value="<?= lc_sanitize($color) ?>">
                                        <span><?= lc_sanitize($color) ?></span>
                                    </div>
                                </div>
                                <div>
                                    <label>Widget theme</label>
                                    <select name="widget_theme">
                                        <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>Dark</option>
                                        <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>Light</option>
                                    </select>
                                </div>
                            </div>
                            <label class="lc-brand-check">
                                <input type="checkbox" name="is_active" <?= !empty($b['is_active']) ? 'checked' : '' ?>> Brand is active
                            </label>
                        </section>
                        <section class="lc-brand-pane" data-brand-pane="messages">
                            <p class="lc-brand-pane-lead">Welcome is the first line in a new chat. Leave it blank for no greeting. Offline is the away note.</p>
                            <label>Welcome message</label>
                            <textarea name="welcome_message" rows="3"><?= lc_sanitize($b['welcome_message']) ?></textarea>
                            <label>Offline message</label>
                            <textarea name="offline_message" rows="3"><?= lc_sanitize($b['offline_message']) ?></textarea>
                            <label class="lc-settings-toggle">
                                <input type="checkbox" name="start_chat_greeting" <?= !isset($b['start_chat_greeting']) || (int) $b['start_chat_greeting'] === 1 ? 'checked' : '' ?>>
                                <span class="lc-settings-toggle-ui"></span>
                                <span class="lc-settings-toggle-text">
                                    <strong>Send a message when an agent starts a chat</strong>
                                    <small>Only this brand. On: Traffic “Start chat” sends the message below. Off: the chat still opens, without that message.</small>
                                </span>
                            </label>
                            <label>Start chat message</label>
                            <textarea name="start_chat_greeting_message" rows="2" maxlength="1000"><?= lc_sanitize((string) ($b['start_chat_greeting_message'] ?? 'Hello How are you doing?')) ?></textarea>
                        </section>
                        <section class="lc-brand-pane" data-brand-pane="prechat">
                            <p class="lc-brand-pane-lead">Ask for details before a new chat. This form is not shared with other brands.</p>
                            <?= lc_prechat_builder_markup(lc_brand_prechat_config($b), lc_brand_prechat_enabled($b)) ?>
                        </section>
                        <section class="lc-brand-pane" data-brand-pane="install">
                            <p class="lc-brand-pane-lead">Paste this before <code>&lt;/body&gt;</code> on this brand's website.</p>
                            <div class="lc-brand-embed-block">
                                <div class="lc-brand-embed-head">
                                    <span><i class="ti ti-code"></i> Embed code</span>
                                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-brand-copy" data-embed-id="lc-embed-<?= $bid ?>">
                                        <i class="ti ti-copy"></i> Copy
                                    </button>
                                </div>
                                <pre class="lc-brand-embed" id="lc-embed-<?= $bid ?>"><?= lc_sanitize($embed) ?></pre>
                            </div>
                        </section>
                    </div>
                    <div class="lc-brand-editor-foot">
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-device-floppy"></i> Save this brand</button>
                    </div>
                </form>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
