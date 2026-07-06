<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Live Chats';
$activeNav = 'chats';
$extraScripts = ['admin-chat.js'];
$loadRecordingAssets = lc_session_recording_enabled();
if ($loadRecordingAssets) {
    array_unshift($extraScripts, 'admin-recording.js');
}
require __DIR__ . '/includes/header.php';
?>

<div class="lc-chat-layout" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-selected="<?= $selectedId ?>">
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
                <div style="display:flex;gap:0.4rem">
                            <select id="lc-canned-select" class="lc-status-select"><option value="">Canned reply</option></select>
                            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-accept-chat" style="display:none"><i class="ti ti-check"></i> Accept</button>
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
            <div class="lc-session-recorder" id="lc-session-recorder" style="display:none">
                <div class="lc-session-recorder-head">
                    <div class="lc-session-recorder-title">
                        <i class="ti ti-device-desktop-analytics"></i>
                        <span>Live Session View</span>
                        <span class="lc-rec-badge" id="lc-recording-badge">STANDBY</span>
                    </div>
                    <div class="lc-session-recorder-actions">
                        <span class="lc-recording-events" id="lc-recording-events">0 events</span>
                        <button type="button" class="lc-recording-toggle" id="lc-recording-toggle" title="Collapse">
                            <i class="ti ti-chevron-up"></i>
                        </button>
                    </div>
                </div>
                <div class="lc-replayer-shell" id="lc-replayer-shell">
                    <div class="lc-replayer-empty" id="lc-replayer-empty">
                        <div class="lc-replayer-empty-icon"><i class="ti ti-player-play"></i></div>
                        <strong>Waiting for visitor screen</strong>
                        <p>Visitor website par widget load hone ke baad unki screen yahan live dikhegi — mouse, scroll aur clicks ke saath.</p>
                        <span class="lc-replayer-hint" id="lc-recording-status">Connecting…</span>
                    </div>
                    <div class="lc-replayer-stage" id="lc-replayer-mount"></div>
                </div>
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
