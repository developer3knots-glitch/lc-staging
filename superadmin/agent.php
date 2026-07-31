<?php
require_once __DIR__ . '/../includes/auth.php';
$superAdmin = lc_require_superadmin();

$agentId = (int) ($_GET['id'] ?? 0);
$fromId = (int) ($_GET['from'] ?? 0);

$report = $agentId > 0 ? lc_superadmin_agent_report($agentId) : null;
if (!$report) {
    header('Location: ' . lc_superadmin_url());
    exit;
}

$agent = $report['agent'];
$stats = $report['stats'];
$chats = $report['chats'];
$logs = $report['logs'];

$ownerId = (int) ($agent['owner_agent_id'] ?? 0);
if ($fromId < 1 && $ownerId > 0 && $ownerId !== (int) $agent['id']) {
    $fromId = $ownerId;
}

$workspace = trim((string) ($agent['company_name'] ?? ''));
if ($workspace === '') {
    $workspace = !empty($agent['account_id']) ? ('Workspace #' . (int) $agent['account_id']) : 'Unassigned';
}

$pageTitle = 'Agent · ' . $agent['name'];
$activeNav = 'users';
$pageSub = 'Agent performance & activity';
require __DIR__ . '/includes/header.php';

$backUrl = $fromId > 0
    ? lc_superadmin_url('user', ['id' => $fromId, 'tab' => 'agents'])
    : lc_superadmin_url();
?>

<div class="sa-toolbar">
    <div class="sa-toolbar-left">
        <a href="<?= lc_sanitize($backUrl) ?>" class="sa-btn sa-btn-secondary sa-btn-sm">
            <i class="ti ti-arrow-left"></i> <?= $fromId > 0 ? 'Back to workspace' : 'All users' ?>
        </a>
    </div>
    <div class="sa-actions">
        <a href="<?= lc_superadmin_url('user', ['id' => (int) $agent['id']]) ?>" class="sa-btn sa-btn-secondary sa-btn-sm">
            <i class="ti ti-user"></i> Full profile
        </a>
    </div>
</div>

<section class="sa-hero sa-hero-report">
    <div class="sa-hero-inner">
        <div class="sa-hero-report-main">
            <div class="sa-hero-avatar"><?= lc_sanitize(strtoupper(substr($agent['name'], 0, 1))) ?></div>
            <div>
                <div class="sa-eyebrow"><i class="ti ti-headset"></i> Agent report</div>
                <h2><?= lc_sanitize($agent['name']) ?></h2>
                <p class="sa-hero-lead">
                    <?= lc_sanitize($agent['email']) ?>
                    · <?= lc_sanitize(ucfirst((string) $agent['role'])) ?>
                    · <?= lc_sanitize($workspace) ?>
                </p>
                <div class="sa-hero-pills">
                    <span class="sa-pill"><span class="sa-dot <?= lc_sanitize($agent['status'] ?? 'offline') ?>"></span><?= lc_sanitize(ucfirst((string) ($agent['status'] ?? 'offline'))) ?></span>
                    <span class="sa-pill"><?= !empty($agent['is_active']) ? 'Account active' : 'Account disabled' ?></span>
                    <span class="sa-pill">Last seen · <?= !empty($agent['last_seen']) ? lc_time_span($agent['last_seen']) : 'Never' ?></span>
                </div>
            </div>
        </div>
        <div class="sa-stat-grid sa-stat-grid-5">
            <div class="sa-stat is-accent">
                <span class="sa-stat-val"><?= (int) $stats['chats_total'] ?></span>
                <span class="sa-stat-label">Total chats</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['chats_active'] ?></span>
                <span class="sa-stat-label">Active</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['chats_closed'] ?></span>
                <span class="sa-stat-label">Closed</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['chats_waiting'] ?></span>
                <span class="sa-stat-label">Waiting</span>
            </div>
            <div class="sa-stat">
                <span class="sa-stat-val"><?= (int) $stats['messages_sent'] ?></span>
                <span class="sa-stat-label">Messages</span>
            </div>
        </div>
    </div>
</section>

<div class="sa-agent-layout">
    <section class="sa-panel sa-agent-chats">
        <div class="sa-panel-head">
            <h3><i class="ti ti-messages"></i> All handled chats</h3>
            <span class="sa-panel-count"><?= count($chats) ?> listed<?= (int) $stats['chats_total'] > count($chats) ? ' of ' . (int) $stats['chats_total'] : '' ?></span>
        </div>
        <?php if ($chats === []): ?>
        <div class="sa-empty-state">
            <i class="ti ti-message-off"></i>
            <strong>No chats handled</strong>
            <p>This agent has not accepted any conversations yet.</p>
        </div>
        <?php else: ?>
        <div class="sa-table-wrap">
            <table class="sa-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Visitor</th>
                        <th>Brand</th>
                        <th>Status</th>
                        <th>Msgs</th>
                        <th>Started</th>
                        <th>Last activity</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($chats as $c): ?>
                    <tr>
                        <td>#<?= (int) $c['id'] ?></td>
                        <td>
                            <div class="sa-user-name"><?= lc_sanitize($c['visitor_name'] ?? 'Visitor') ?></div>
                            <?php if (!empty($c['visitor_country'])): ?>
                            <div class="sa-user-meta"><?= lc_sanitize($c['visitor_country']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= lc_sanitize($c['brand_name'] ?? '-') ?></td>
                        <td>
                            <span class="sa-inbox-status is-<?= lc_sanitize((string) ($c['status'] ?? '')) ?>">
                                <?= lc_sanitize(ucfirst((string) ($c['status'] ?? ''))) ?>
                            </span>
                        </td>
                        <td><?= (int) ($c['agent_messages'] ?? 0) ?></td>
                        <td><?= !empty($c['started_at']) ? lc_time_span($c['started_at']) : '-' ?></td>
                        <td><?= !empty($c['last_message_at']) ? lc_time_span($c['last_message_at']) : '-' ?></td>
                        <td>
                            <a href="<?= lc_superadmin_url('chat', ['user' => (int) $agent['id'], 'id' => (int) $c['id']]) ?>" class="sa-btn sa-btn-primary sa-btn-sm">
                                <i class="ti ti-message-2"></i> View Chat
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <aside class="sa-panel sa-agent-logs">
        <div class="sa-panel-head">
            <h3><i class="ti ti-timeline"></i> Agent logs</h3>
            <span class="sa-panel-count"><?= count($logs) ?> events</span>
        </div>
        <?php if ($logs === []): ?>
        <div class="sa-empty-state">
            <i class="ti ti-history"></i>
            <strong>No activity yet</strong>
            <p>Accepts, messages, and closes will appear here.</p>
        </div>
        <?php else: ?>
        <ol class="sa-log-list">
            <?php foreach ($logs as $log):
                $type = (string) ($log['type'] ?? 'message');
                $icon = match ($type) {
                    'accepted' => 'ti-player-play',
                    'closed' => 'ti-circle-check',
                    'system' => 'ti-info-circle',
                    default => 'ti-message',
                };
            ?>
            <li class="sa-log-item is-<?= lc_sanitize($type) ?>">
                <span class="sa-log-icon"><i class="ti <?= $icon ?>"></i></span>
                <div class="sa-log-body">
                    <div class="sa-log-top">
                        <strong><?= lc_sanitize($log['label'] ?? 'Event') ?></strong>
                        <time><?= !empty($log['at']) ? lc_time_span($log['at']) : '' ?></time>
                    </div>
                    <p><?= lc_sanitize($log['detail'] ?? '') ?></p>
                    <?php if (!empty($log['conversation_id'])): ?>
                    <a class="sa-log-link" href="<?= lc_superadmin_url('chat', ['user' => (int) $agent['id'], 'id' => (int) $log['conversation_id']]) ?>">
                        Open chat #<?= (int) $log['conversation_id'] ?>
                    </a>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>
    </aside>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
