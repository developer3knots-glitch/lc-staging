<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();
lc_ensure_accounts_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);
$accountId = lc_agent_account_id($agent);

$search = trim($_GET['q'] ?? '');
$brandFilter = (int) ($_GET['brand'] ?? 0);
$agentFilter = (int) ($_GET['agent'] ?? 0);
$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all', 'today', 'week', 'month'], true)) {
    $period = 'all';
}

$scopeBrandId = $agentBrandId;
if ($scopeBrandId === null && $brandFilter > 0) {
    if ($accountId > 0) {
        $own = $db->prepare('SELECT id FROM brands WHERE id = ? AND account_id = ? LIMIT 1');
        $own->execute([$brandFilter, $accountId]);
        if ($own->fetch()) {
            $scopeBrandId = $brandFilter;
        }
    } else {
        $scopeBrandId = $brandFilter;
    }
}

$where = ["c.status = 'closed'"];
$params = [];

if ($scopeBrandId !== null) {
    $where[] = 'c.brand_id = ?';
    $params[] = $scopeBrandId;
} elseif ($accountId > 0) {
    $where[] = 'c.brand_id IN (SELECT id FROM brands WHERE account_id = ?)';
    $params[] = $accountId;
}

if ($search !== '') {
    $refId = lc_parse_conversation_reference($search);
    if ($refId !== null) {
        $where[] = '(c.id = ? OR c.ticket_reference = ?)';
        $params[] = $refId;
        $params[] = strtoupper(trim($search));
    } else {
        $where[] = '(v.name LIKE ? OR v.email LIKE ? OR v.ip_address LIKE ? OR b.name LIKE ? OR a.name LIKE ? OR v.country LIKE ? OR CAST(c.id AS CHAR) LIKE ? OR c.ticket_reference LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, array_fill(0, 8, $like));
    }
}


if ($agentFilter > 0) {
    $where[] = 'c.agent_id = ?';
    $params[] = $agentFilter;
}

if ($period === 'today') {
    $where[] = 'DATE(c.closed_at) = CURDATE()';
} elseif ($period === 'week') {
    $where[] = 'c.closed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($period === 'month') {
    $where[] = 'c.closed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
}

$whereSql = implode(' AND ', $where);

$baseFrom = "
    FROM conversations c
    JOIN visitors v ON v.id = c.visitor_id
    LEFT JOIN brands b ON b.id = c.brand_id
    LEFT JOIN agents a ON a.id = c.agent_id
    WHERE {$whereSql}
";

$statsWhere = ["c.status = 'closed'"];
$statsParams = [];
if ($agentBrandId !== null) {
    $statsWhere[] = 'c.brand_id = ?';
    $statsParams[] = $agentBrandId;
}
$statsWhereSql = implode(' AND ', $statsWhere);

$historyError = null;
try {
    $countStmt = $db->prepare('SELECT COUNT(*) ' . $baseFrom);
    $countStmt->execute($params);
    $totalMatching = (int) $countStmt->fetchColumn();

    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_closed,
            SUM(CASE WHEN DATE(c.closed_at) = CURDATE() THEN 1 ELSE 0 END) AS closed_today,
            SUM(CASE WHEN c.closed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS closed_week,
            SUM(CASE WHEN c.closed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS closed_month,
            COALESCE(AVG(msg_counts.cnt), 0) AS avg_messages
        FROM conversations c
        LEFT JOIN (
            SELECT conversation_id, COUNT(*) AS cnt FROM messages GROUP BY conversation_id
        ) msg_counts ON msg_counts.conversation_id = c.id
        WHERE {$statsWhereSql}
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch() ?: ['total_closed' => 0, 'closed_today' => 0, 'closed_week' => 0, 'closed_month' => 0, 'avg_messages' => 0];

    $sql = "
        SELECT c.id, c.status, c.started_at, c.closed_at, c.last_message_at, c.ticket_reference,
               v.name AS visitor_name, v.email AS visitor_email,
               v.ip_address AS visitor_ip, v.country AS visitor_country,
               b.name AS brand_name, b.widget_color AS brand_color,
               a.name AS agent_name,
               (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id) AS msg_count
        {$baseFrom}
        ORDER BY c.closed_at DESC
        LIMIT 100
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $agentsSql = "
        SELECT DISTINCT a.id, a.name
        FROM agents a
        INNER JOIN conversations c ON c.agent_id = a.id AND c.status = 'closed'
    ";
    $agentsParams = [];
    $agentsWhere = [];
    if ($agentBrandId !== null) {
        $agentsWhere[] = 'c.brand_id = ?';
        $agentsParams[] = $agentBrandId;
    } elseif ($accountId > 0) {
        $agentsWhere[] = 'a.account_id = ?';
        $agentsParams[] = $accountId;
    }
    if ($agentsWhere) {
        $agentsSql .= ' WHERE ' . implode(' AND ', $agentsWhere);
    }
    $agentsSql .= ' ORDER BY a.name ASC';
    $agentsStmt = $db->prepare($agentsSql);
    $agentsStmt->execute($agentsParams);
    $agents = $agentsStmt->fetchAll();
} catch (Throwable $e) {
    $totalMatching = 0;
    $stats = ['total_closed' => 0, 'closed_today' => 0, 'closed_week' => 0, 'closed_month' => 0, 'avg_messages' => 0];
    $rows = [];
    $agents = [];
    $historyError = 'Unable to load history. Please refresh or contact support.';
}

$brands = $agentBrandId === null ? lc_get_all_brands(true, $accountId > 0 ? $accountId : null) : [];

$formatDuration = static function (?string $started, ?string $closed): string {
    if (!$started || !$closed) {
        return ' - ';
    }
    try {
        $secs = (new DateTimeImmutable($closed))->getTimestamp() - (new DateTimeImmutable($started))->getTimestamp();
    } catch (Throwable $e) {
        return ' - ';
    }
    if ($secs < 60) {
        return $secs . 's';
    }
    if ($secs < 3600) {
        return floor($secs / 60) . 'm ' . ($secs % 60) . 's';
    }
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'm';
};

$hasFilters = $search !== '' || $brandFilter > 0 || $agentFilter > 0 || $period !== 'all';

$pageTitle = 'Chat History';
$activeNav = 'history';
$extraScript = 'admin-history.js';
require __DIR__ . '/includes/header.php';
?>

<div class="lc-history-pro">
    <?php if (!empty($historyError)): ?>
        <div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($historyError) ?></div>
    <?php endif; ?>

    <div class="lc-hx-panel">
        <div class="lc-hx-top">
            <div class="lc-hx-top-left">
                <h1 class="lc-hx-title">Closed conversations</h1>
                <p class="lc-hx-sub"><?= (int) $totalMatching ?> archived chat<?= (int) $totalMatching === 1 ? '' : 's' ?><?= $totalMatching > 100 ? ' · showing latest 100' : '' ?></p>
            </div>
            <div class="lc-hx-top-right">
                <a href="<?= lc_admin_url('chats') ?>" class="lc-hx-btn"><i class="ti ti-inbox"></i> Live Inbox</a>
                <?php if ($hasFilters): ?>
                    <a href="<?= lc_admin_url('history') ?>" class="lc-hx-btn"><i class="ti ti-x"></i> Clear filters</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="lc-hx-stats">
            <div class="lc-hx-stat">
                <span class="lc-hx-stat-label">All closed</span>
                <strong class="lc-hx-stat-val"><?= (int) $stats['total_closed'] ?></strong>
            </div>
            <div class="lc-hx-stat">
                <span class="lc-hx-stat-label">Today</span>
                <strong class="lc-hx-stat-val"><?= (int) $stats['closed_today'] ?></strong>
            </div>
            <div class="lc-hx-stat">
                <span class="lc-hx-stat-label">This week</span>
                <strong class="lc-hx-stat-val"><?= (int) $stats['closed_week'] ?></strong>
            </div>
            <div class="lc-hx-stat">
                <span class="lc-hx-stat-label">Avg messages</span>
                <strong class="lc-hx-stat-val"><?= number_format((float) $stats['avg_messages'], 1) ?></strong>
            </div>
        </div>

        <div class="lc-hx-tabs" role="tablist" aria-label="Period">
            <?php
            $periods = [
                'all' => ['label' => 'All closed', 'count' => (int) ($stats['total_closed'] ?? 0)],
                'today' => ['label' => 'Today', 'count' => (int) ($stats['closed_today'] ?? 0)],
                'week' => ['label' => '7 days', 'count' => (int) ($stats['closed_week'] ?? 0)],
                'month' => ['label' => '30 days', 'count' => (int) ($stats['closed_month'] ?? 0)],
            ];
            foreach ($periods as $key => $meta):
                $href = lc_admin_url('history', array_filter([
                    'q' => $search !== '' ? $search : null,
                    'brand' => $brandFilter > 0 ? $brandFilter : null,
                    'agent' => $agentFilter > 0 ? $agentFilter : null,
                    'period' => $key !== 'all' ? $key : null,
                ], static function ($value) {
                    return $value !== null && $value !== '';
                }));
            ?>
                <a href="<?= lc_sanitize($href) ?>" class="lc-hx-tab<?= $period === $key ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $period === $key ? 'true' : 'false' ?>">
                    <?= lc_sanitize($meta['label']) ?> <span class="lc-hx-tab-count">(<?= (int) $meta['count'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="get" class="lc-hx-toolbar" id="lc-history-form">
            <div class="lc-hx-filters-meta">
                <i class="ti ti-filter" aria-hidden="true"></i>
                <span>Match all filters</span>
            </div>
            <div class="lc-hx-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" id="lc-history-q" name="q" value="<?= lc_sanitize($search) ?>" placeholder="Search ticket, visitor, IP, brand, agent..." autocomplete="off" aria-label="Search history">
            </div>
            <?php if ($agentBrandId === null && !empty($brands)): ?>
            <select id="lc-history-brand" name="brand" aria-label="Brand">
                <option value="0">All brands</option>
                <?php foreach ($brands as $brand): ?>
                    <option value="<?= (int) $brand['id'] ?>"<?= $brandFilter === (int) $brand['id'] ? ' selected' : '' ?>><?= lc_sanitize($brand['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select id="lc-history-agent" name="agent" aria-label="Agent">
                <option value="0">All agents</option>
                <?php foreach ($agents as $ag): ?>
                    <option value="<?= (int) $ag['id'] ?>"<?= $agentFilter === (int) $ag['id'] ? ' selected' : '' ?>><?= lc_sanitize($ag['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="lc-history-period" name="period" aria-label="Period" hidden>
                <option value="all"<?= $period === 'all' ? ' selected' : '' ?>>All time</option>
                <option value="today"<?= $period === 'today' ? ' selected' : '' ?>>Today</option>
                <option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Last 7 days</option>
                <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Last 30 days</option>
            </select>
            <button type="submit" class="lc-hx-add-filter"><i class="ti ti-plus"></i> Apply filters</button>
        </form>

        <div class="lc-hx-table-wrap" id="lc-history-list">
            <?php if (empty($rows)): ?>
                <div class="lc-history-empty">
                    <i class="ti ti-inbox-off"></i>
                    <p>No closed chats found</p>
                    <span><?= $hasFilters ? 'Try adjusting your filters or search terms.' : 'Closed conversations will appear here after agents end chats.' ?></span>
                    <?php if ($hasFilters): ?>
                        <a href="<?= lc_admin_url('history') ?>" class="lc-hx-btn"><i class="ti ti-x"></i> Clear filters</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <table class="lc-hx-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Actions</th>
                        <th>Country</th>
                        <th>Activity</th>
                        <th>Agent</th>
                        <th>Duration</th>
                        <th>Ticket</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $name = $r['visitor_name'] ?: 'Visitor';
                    $duration = $formatDuration($r['started_at'], $r['closed_at']);
                    $chatUrl = lc_admin_url('chats', ['id' => (int) $r['id']]);
                    $ticketRef = $r['ticket_reference'] ?: lc_conversation_reference((int) $r['id'], $r['started_at'] ?? null);
                    $email = trim((string) ($r['visitor_email'] ?? ''));
                    $agentName = trim((string) ($r['agent_name'] ?? ''));
                    $searchBlob = strtolower($name . ' ' . $email . ' ' . ($r['visitor_ip'] ?? '') . ' ' . ($r['visitor_country'] ?? '') . ' ' . ($r['brand_name'] ?? '') . ' ' . $agentName . ' ' . $ticketRef);
                ?>
                    <tr class="lc-hx-row" data-search="<?= lc_sanitize($searchBlob) ?>">
                        <td class="lc-hx-visitor">
                            <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-hx-visitor-link">
                                <span class="lc-hx-av"><?= lc_sanitize(lc_avatar_initials($name)) ?></span>
                                <span class="lc-hx-visitor-copy">
                                    <strong><?= lc_sanitize($name) ?></strong>
                                    <?php if (!empty($r['visitor_ip'])): ?>
                                        <span><?= lc_sanitize($r['visitor_ip']) ?></span>
                                    <?php endif; ?>
                                </span>
                            </a>
                        </td>
                        <td class="lc-hx-email"><?= $email !== '' ? lc_sanitize($email) : '—' ?></td>
                        <td class="lc-hx-action">
                            <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-hx-go-chat">Go to chat</a>
                        </td>
                        <td class="lc-hx-country">
                            <?php if (!empty($r['visitor_country'])): ?>
                                <span class="lc-hx-country-pill"><?= lc_sanitize($r['visitor_country']) ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="lc-hx-activity">
                            <span class="lc-hx-activity-dot is-closed"></span>
                            <span>Closed</span>
                        </td>
                        <td class="lc-hx-agent">
                            <?php if ($agentName !== ''): ?>
                                <span class="lc-hx-agent-pill">
                                    <span class="lc-hx-agent-av"><?= lc_sanitize(lc_avatar_initials($agentName)) ?></span>
                                    <?= lc_sanitize($agentName) ?>
                                </span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="lc-hx-num"><?= lc_sanitize($duration) ?></td>
                        <td><code class="lc-hx-ticket"><?= lc_sanitize($ticketRef) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
