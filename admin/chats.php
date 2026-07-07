<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Live Chats';
$activeNav = 'chats';
$extraScripts = ['admin-chat.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-chats-pro">
    <div class="lc-chat-layout lc-chat-layout-pro" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role']) ?>" data-selected="<?= $selectedId ?>">
        <aside class="lc-chat-list lc-chat-inbox-pro">
            <div class="lc-chat-inbox-head">
                <div class="lc-chat-inbox-title">
                    <span class="lc-chat-inbox-icon"><i class="ti ti-inbox"></i></span>
                    <div>
                        <strong>Inbox</strong>
                        <span>Live conversations</span>
                    </div>
                </div>
                <span id="lc-waiting-badge" class="lc-chat-inbox-waiting" style="display:none">0</span>
            </div>
            <div class="lc-chat-inbox-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-inbox-search" placeholder="Search visitors…" autocomplete="off" aria-label="Search conversations">
            </div>
            <div class="lc-chat-tabs lc-chat-tabs-pro">
                <button type="button" class="lc-chat-tab active" data-tab="all"><i class="ti ti-layout-grid"></i> All</button>
                <button type="button" class="lc-chat-tab" data-tab="waiting"><i class="ti ti-clock"></i> Waiting</button>
                <button type="button" class="lc-chat-tab" data-tab="active"><i class="ti ti-message-circle"></i> Active</button>
            </div>
            <div class="lc-chat-items" id="lc-conversation-list">
                <div class="lc-loading-inline" id="lc-list-loading"><i class="ti ti-loader-2"></i> Loading conversations…</div>
            </div>
        </aside>

        <div class="lc-chat-panel lc-chat-panel-pro">
            <div class="lc-chat-panel-empty" id="lc-chat-empty">
                <div class="lc-chat-empty-art">
                    <span class="lc-chat-empty-ring"></span>
                    <span class="lc-chat-empty-icon"><i class="ti ti-messages"></i></span>
                </div>
                <h3>Pick a conversation</h3>
                <p>Select a chat from the inbox to view messages, visitor details, and page journey.</p>
            </div>

            <div id="lc-chat-active" class="lc-chat-active-pro" style="display:none">
                <div class="lc-chat-header lc-chat-header-pro">
                    <div class="lc-chat-header-visitor">
                        <div class="lc-chat-header-av" id="lc-chat-header-av" aria-hidden="true">V</div>
                        <div class="lc-chat-header-info">
                            <strong id="lc-chat-visitor-name">Visitor</strong>
                            <div class="lc-visitor-details lc-visitor-details-pro" id="lc-chat-visitor-details"></div>
                            <div class="lc-chat-visitor-meta" id="lc-chat-visitor-meta"></div>
                        </div>
                    </div>
                    <div class="lc-chat-header-actions">
                        <div class="lc-chat-action-canned">
                            <i class="ti ti-bolt"></i>
                            <select id="lc-canned-select" class="lc-status-select lc-canned-select-pro"><option value="">Canned reply</option></select>
                        </div>
                        <button type="button" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-accept-chat" style="display:none"><i class="ti ti-check"></i> Accept</button>
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

                <div class="lc-page-journey lc-page-journey-pro" id="lc-page-journey" style="display:none">
                    <div class="lc-page-journey-head">
                        <span><i class="ti ti-route"></i> Page journey</span>
                        <span class="lc-page-journey-count" id="lc-journey-count"></span>
                    </div>
                    <div class="lc-page-journey-list" id="lc-page-journey-list"></div>
                </div>

                <div class="lc-chat-messages lc-chat-messages-pro" id="lc-messages"></div>

                <div class="lc-chat-compose lc-chat-compose-pro">
                    <div class="lc-chat-compose-inner">
                        <textarea id="lc-reply-input" rows="1" placeholder="Type your reply…"></textarea>
                        <button type="button" class="lc-chat-send-btn" id="lc-send-reply" title="Send message"><i class="ti ti-send"></i></button>
                    </div>
                    <p class="lc-chat-compose-hint"><kbd>Enter</kbd> to send · <kbd>Shift+Enter</kbd> new line</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
