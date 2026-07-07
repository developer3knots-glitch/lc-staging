<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
lc_ensure_brands_schema();

$db = lc_db();
$agentBrandId = lc_agent_brand_id($agent);

$search = trim($_GET['q'] ?? '');
$brandFilter = (int) ($_GET['brand'] ?? 0);
$agentFilter = (int) ($_GET['agent'] ?? 0);
$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all', 'today', 'week', 'month'], true)) {
    $period = 'all';
}

$scopeBrandId = $agentBrandId;
if ($scopeBrandId === null && $brandFilter > 0) {
    $scopeBrandId = $brandFilter;
}

$where = ["c.status = 'closed'"];
$params = [];

if ($scopeBrandId !== null) {
    $where[] = 'c.brand_id = ?';
    $params[] = $scopeBrandId;
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
            COALESCE(AVG(msg_counts.cnt), 0) AS avg_messages
        FROM conversations c
        LEFT JOIN (
            SELECT conversation_id, COUNT(*) AS cnt FROM messages GROUP BY conversation_id
        ) msg_counts ON msg_counts.conversation_id = c.id
        WHERE {$statsWhereSql}
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch() ?: ['total_closed' => 0, 'closed_today' => 0, 'closed_week' => 0, 'avg_messages' => 0];

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
    if ($agentBrandId !== null) {
        $agentsSql .= ' WHERE c.brand_id = ?';
        $agentsParams[] = $agentBrandId;
    }
    $agentsSql .= ' ORDER BY a.name ASC';
    $agentsStmt = $db->prepare($agentsSql);
    $agentsStmt->execute($agentsParams);
    $agents = $agentsStmt->fetchAll();
} catch (Throwable $e) {
    $totalMatching = 0;
    $stats = ['total_closed' => 0, 'closed_today' => 0, 'closed_week' => 0, 'avg_messages' => 0];
    $rows = [];
    $agents = [];
    $historyError = 'Unable to load history. Please refresh or contact support.';
}

$brands = $agentBrandId === null ? lc_get_all_brands(true) : [];

$formatDuration = static function (?string $started, ?string $closed): string {
    if (!$started || !$closed) {
        return '—';
    }
    try {
        $secs = (new DateTimeImmutable($closed))->getTimestamp() - (new DateTimeImmutable($started))->getTimestamp();
    } catch (Throwable $e) {
        return '—';
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

<div class="lc-history">
    <?php if (!empty($historyError)): ?>
        <div class="lc-alert lc-alert-error"><i class="ti ti-alert-circle"></i> <?= lc_sanitize($historyError) ?></div>
    <?php endif; ?>
    <div class="lc-hero lc-hero-pro lc-hero-history">
        <div class="lc-hero-glow lc-hero-glow-1"></div>
        <div class="lc-hero-glow lc-hero-glow-2"></div>
        <div class="lc-hero-main">
            <div class="lc-hero-eyebrow"><i class="ti ti-archive"></i> Conversation Archive</div>
            <h1 class="lc-hero-title">Chat History</h1>
            <p class="lc-hero-sub">Browse, search and review every closed conversation — visitor details, agent handoffs, and full transcripts.</p>
            <div class="lc-hero-actions">
                <a href="<?= lc_admin_url('chats') ?>" class="lc-hero-chip"><i class="ti ti-messages"></i> Live Inbox</a>
                <a href="<?= lc_admin_url('history') ?>" class="lc-hero-chip"><i class="ti ti-refresh"></i> Reset filters</a>
            </div>
        </div>
        <div class="lc-hero-metrics lc-hero-metrics-4">
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $stats['total_closed'] ?></span>
                <span class="lc-hero-metric-label">All closed</span>
            </div>
            <div class="lc-hero-metric is-accent">
                <span class="lc-hero-metric-val"><?= (int) $stats['closed_today'] ?></span>
                <span class="lc-hero-metric-label">Today</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= (int) $stats['closed_week'] ?></span>
                <span class="lc-hero-metric-label">This week</span>
            </div>
            <div class="lc-hero-metric">
                <span class="lc-hero-metric-val"><?= number_format((float) $stats['avg_messages'], 1) ?></span>
                <span class="lc-hero-metric-label">Avg msgs</span>
            </div>
        </div>
    </div>

    <div class="lc-kpi-grid lc-kpi-grid-pro lc-history-kpi">
        <div class="lc-kpi lc-kpi-pro lc-kpi-closed">
            <div class="lc-kpi-icon purple"><i class="ti ti-circle-check"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Showing</div>
                <div class="lc-kpi-val"><?= count($rows) ?></div>
                <div class="lc-kpi-hint">of <?= $totalMatching ?> matching</div>
            </div>
        </div>
        <div class="lc-kpi lc-kpi-pro lc-kpi-active">
            <div class="lc-kpi-icon green"><i class="ti ti-filter"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Filters</div>
                <div class="lc-kpi-val"><?= $hasFilters ? 'Active' : 'None' ?></div>
                <div class="lc-kpi-hint"><?= $hasFilters ? 'Refined results' : 'All closed chats' ?></div>
            </div>
        </div>
        <div class="lc-kpi lc-kpi-pro lc-kpi-agents">
            <div class="lc-kpi-icon blue"><i class="ti ti-users"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Agents</div>
                <div class="lc-kpi-val"><?= count($agents) ?></div>
                <div class="lc-kpi-hint">With closed chats</div>
            </div>
        </div>
        <div class="lc-kpi lc-kpi-pro lc-kpi-waiting">
            <div class="lc-kpi-icon orange"><i class="ti ti-clock"></i></div>
            <div class="lc-kpi-body">
                <div class="lc-kpi-name">Period</div>
                <div class="lc-kpi-val" style="font-size:1rem;text-transform:capitalize"><?= lc_sanitize($period === 'all' ? 'All time' : $period) ?></div>
                <div class="lc-kpi-hint">Date range</div>
            </div>
        </div>
    </div>

    <div class="lc-card lc-card-pro lc-history-filters">
        <div class="lc-card-head">
            <h3><i class="ti ti-search"></i> Search &amp; Filter</h3>
            <?php if ($hasFilters): ?>
                <a href="<?= lc_admin_url('history') ?>" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-x"></i> Clear all</a>
            <?php endif; ?>
        </div>
        <div class="lc-card-body">
            <form method="get" class="lc-history-form" id="lc-history-form">
                <div class="lc-history-form-grid">
                    <div class="lc-history-field lc-history-field-search">
                        <label for="lc-history-q">Search</label>
                        <div class="lc-history-search-wrap">
                            <i class="ti ti-search"></i>
                            <input type="search" id="lc-history-q" name="q" value="<?= lc_sanitize($search) ?>" placeholder="Ticket (LC-…), visitor, IP, brand, agent…" autocomplete="off">
                        </div>
                    </div>
                    <?php if ($agentBrandId === null && !empty($brands)): ?>
                    <div class="lc-history-field">
                        <label for="lc-history-brand">Brand</label>
                        <select id="lc-history-brand" name="brand">
                            <option value="0">All brands</option>
                            <?php foreach ($brands as $brand): ?>
                                <option value="<?= (int) $brand['id'] ?>"<?= $brandFilter === (int) $brand['id'] ? ' selected' : '' ?>><?= lc_sanitize($brand['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="lc-history-field">
                        <label for="lc-history-agent">Agent</label>
                        <select id="lc-history-agent" name="agent">
                            <option value="0">All agents</option>
                            <?php foreach ($agents as $ag): ?>
                                <option value="<?= (int) $ag['id'] ?>"<?= $agentFilter === (int) $ag['id'] ? ' selected' : '' ?>><?= lc_sanitize($ag['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lc-history-field">
                        <label for="lc-history-period">Period</label>
                        <select id="lc-history-period" name="period">
                            <option value="all"<?= $period === 'all' ? ' selected' : '' ?>>All time</option>
                            <option value="today"<?= $period === 'today' ? ' selected' : '' ?>>Today</option>
                            <option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Last 7 days</option>
                            <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Last 30 days</option>
                        </select>
                    </div>
                    <div class="lc-history-field lc-history-field-action">
                        <label>&nbsp;</label>
                        <button type="submit" class="lc-btn lc-btn-primary"><i class="ti ti-filter"></i> Apply</button>
                    </div>
                </div>
            </form>
            <div class="lc-history-period-pills">
                <?php
                $periods = ['all' => 'All', 'today' => 'Today', 'week' => '7 days', 'month' => '30 days'];
                foreach ($periods as $key => $label):
                    $href = lc_admin_url('history', array_filter([
                        'q' => $search !== '' ? $search : null,
                        'brand' => $brandFilter > 0 ? $brandFilter : null,
                        'agent' => $agentFilter > 0 ? $agentFilter : null,
                        'period' => $key !== 'all' ? $key : null,
                    ], static function ($value) {
                        return $value !== null && $value !== '';
                    }));
                ?>
                    <a href="<?= lc_sanitize($href) ?>" class="lc-history-pill<?= $period === $key ? ' is-active' : '' ?>"><?= lc_sanitize($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="lc-card lc-card-pro">
        <div class="lc-card-head">
            <h3><i class="ti ti-history"></i> Closed Conversations</h3>
            <span class="lc-card-sub"><?= $totalMatching ?> total<?= $totalMatching > 100 ? ' · showing latest 100' : '' ?></span>
        </div>
        <div class="lc-history-list" id="lc-history-list">
            <?php if (empty($rows)): ?>
                <div class="lc-history-empty">
                    <i class="ti ti-inbox-off"></i>
                    <p>No closed chats found</p>
                    <span><?= $hasFilters ? 'Try adjusting your filters or search terms.' : 'Closed conversations will appear here after agents end chats.' ?></span>
                    <?php if ($hasFilters): ?>
                        <a href="<?= lc_admin_url('history') ?>" class="lc-btn lc-btn-secondary lc-btn-sm"><i class="ti ti-x"></i> Clear filters</a>
                    <?php endif; ?>
                </div>
            <?php else: foreach ($rows as $r):
                $name = $r['visitor_name'] ?: 'Visitor';
                $duration = $formatDuration($r['started_at'], $r['closed_at']);
                $chatUrl = lc_admin_url('chats', ['id' => (int) $r['id']]);
                $brandColor = $r['brand_color'] ?? '#6FD943';
                $ticketRef = $r['ticket_reference'] ?: lc_conversation_reference((int) $r['id'], $r['started_at'] ?? null);
            ?>
                <a href="<?= lc_sanitize($chatUrl) ?>" class="lc-history-item" data-search="<?= lc_sanitize(strtolower($name . ' ' . ($r['visitor_email'] ?? '') . ' ' . ($r['visitor_ip'] ?? '') . ' ' . ($r['visitor_country'] ?? '') . ' ' . ($r['brand_name'] ?? '') . ' ' . ($r['agent_name'] ?? '') . ' ' . $ticketRef)) ?>">
                    <div class="lc-history-item-av"><?= lc_sanitize(lc_avatar_initials($name)) ?></div>
                    <div class="lc-history-item-body">
                        <div class="lc-history-item-top">
                            <strong><?= lc_sanitize($name) ?></strong>
                            <?php if (!empty($r['visitor_email'])): ?>
                                <span class="lc-history-email"><?= lc_sanitize($r['visitor_email']) ?></span>
                            <?php endif; ?>
                            <span class="lc-badge lc-badge-closed">Closed</span>
                            <span class="lc-history-ticket" title="Support ticket reference"><?= lc_sanitize($ticketRef) ?></span>
                        </div>
                        <div class="lc-history-item-tags">
                            <?php if (!empty($r['brand_name'])): ?>
                                <span class="lc-history-tag is-brand"><span class="lc-brand-dot" style="background:<?= lc_sanitize($brandColor) ?>"></span> <?= lc_sanitize($r['brand_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['visitor_ip'])): ?>
                                <span class="lc-history-tag"><i class="ti ti-world"></i> <?= lc_sanitize($r['visitor_ip']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['visitor_country'])): ?>
                                <span class="lc-history-tag"><i class="ti ti-map-pin"></i> <?= lc_sanitize($r['visitor_country']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['agent_name'])): ?>
                                <span class="lc-history-tag is-agent"><i class="ti ti-headset"></i> <?= lc_sanitize($r['agent_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="lc-history-item-stats">
                        <div class="lc-history-stat">
                            <span class="lc-history-stat-val"><?= (int) $r['msg_count'] ?></span>
                            <span class="lc-history-stat-label">Messages</span>
                        </div>
                        <div class="lc-history-stat">
                            <span class="lc-history-stat-val"><?= lc_sanitize($duration) ?></span>
                            <span class="lc-history-stat-label">Duration</span>
                        </div>
                    </div>
                    <div class="lc-history-item-side">
                        <div class="lc-history-closed-at"><?= $r['closed_at'] ? lc_time_span($r['closed_at'], 'absolute') : '—' ?></div>
                        <span class="lc-history-view"><i class="ti ti-eye"></i> View transcript</span>
                    </div>
                    <i class="ti ti-chevron-right lc-history-item-arrow"></i>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
