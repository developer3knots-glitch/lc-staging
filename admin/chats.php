<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Live Chats';
$activeNav = 'chats';
$extraScripts = ['admin-chat.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-chat-layout" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role']) ?>" data-selected="<?= $selectedId ?>">
    <div class="lc-chat-list">
        <div class="lc-chat-list-head"><i class="ti ti-inbox"></i> Inbox <span id="lc-waiting-badge" class="lc-badge lc-badge-waiting" style="display:none">0</span></div>
        <div class="lc-chat-tabs">
            <button type="button" class="lc-chat-tab active" data-tab="all">All</button>
            <button type="button" class="lc-chat-tab" data-tab="waiting">Waiting</button>
            <button type="button" class="lc-chat-tab" data-tab="active">Active</button>
        </div>
        <div class="lc-chat-items" id="lc-conversation-list">
            <div class="lc-loading-inline" id="lc-list-loading"><i class="ti ti-loader-2"></i> Loading conversations…</div>
        </div>
    </div>
    <div class="lc-chat-panel" id="lc-chat-panel">
        <div class="lc-chat-panel-empty" id="lc-chat-empty">
            <i class="ti ti-message-circle"></i>
            <p>Select a conversation to start chatting</p>
        </div>
        <div id="lc-chat-active" style="display:none;flex:1;flex-direction:column;min-height:0">
            <div class="lc-chat-header">
                <div>
                    <strong id="lc-chat-visitor-name">Visitor</strong>
                    <div class="lc-visitor-details" id="lc-chat-visitor-details"></div>
                    <div style="font-size:0.78rem;color:var(--lc-muted)" id="lc-chat-visitor-meta"></div>
                </div>
                <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap">
                            <select id="lc-canned-select" class="lc-status-select"><option value="">Canned reply</option></select>
                            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-accept-chat" style="display:none"><i class="ti ti-check"></i> Accept</button>
                            <div class="lc-transfer-wrap" id="lc-transfer-wrap" style="display:none">
                                <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-transfer-chat" aria-expanded="false">
                                    <i class="ti ti-arrows-exchange"></i> Transfer
                                </button>
                                <div class="lc-transfer-panel" id="lc-transfer-panel" hidden>
                                    <div class="lc-transfer-panel-head">
                                        <strong><i class="ti ti-arrows-exchange"></i> Transfer chat</strong>
                                        <button type="button" class="lc-transfer-close" id="lc-transfer-close" title="Close"><i class="ti ti-x"></i></button>
                                    </div>
                                    <p class="lc-transfer-hint">Select an online agent to take over this conversation.</p>
                                    <div class="lc-transfer-agents" id="lc-transfer-agents">
                                        <div class="lc-transfer-loading"><i class="ti ti-loader-2"></i> Loading agents…</div>
                                    </div>
                                    <label class="lc-transfer-note-label" for="lc-transfer-note">Note for the next agent (optional)</label>
                                    <textarea id="lc-transfer-note" rows="2" maxlength="500" placeholder="Brief context for the receiving agent…"></textarea>
                                    <button type="button" class="lc-btn lc-btn-primary lc-btn-sm lc-transfer-submit" id="lc-transfer-submit" disabled>
                                        <i class="ti ti-send"></i> Transfer chat
                                    </button>
                                </div>
                            </div>
                            <button type="button" class="lc-btn lc-btn-danger lc-btn-sm" id="lc-close-chat"><i class="ti ti-x"></i> Close</button>
                </div>
            </div>
            <div class="lc-page-journey" id="lc-page-journey" style="display:none">
                <div class="lc-page-journey-head">
                    <span><i class="ti ti-route"></i> Page journey</span>
                    <span class="lc-page-journey-count" id="lc-journey-count"></span>
                </div>
                <div class="lc-page-journey-list" id="lc-page-journey-list"></div>
            </div>
            <div class="lc-chat-messages" id="lc-messages"></div>
            <div class="lc-chat-compose">
                <textarea id="lc-reply-input" rows="1" placeholder="Type your reply…"></textarea>
                <button type="button" class="lc-btn lc-btn-primary" id="lc-send-reply"><i class="ti ti-send"></i></button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
