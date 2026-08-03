<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Live Chats';
$activeNav = 'chats';
$extraScripts = ['lc-chat-attachments.js', 'admin-chat.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-chats-pro">
    <div class="lc-chat-layout lc-chat-layout-pro" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role']) ?>" data-selected="<?= $selectedId ?>">
        <aside class="lc-chat-list lc-chat-inbox-pro">
            <div class="lc-chat-inbox-head">
                <div class="lc-chat-inbox-title">
                    <strong>Inbox</strong>
                    <span>Conversations</span>
                </div>
                <span id="lc-waiting-badge" class="lc-chat-inbox-waiting" style="display:none">0</span>
            </div>
            <div class="lc-chat-inbox-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-inbox-search" placeholder="Search…" autocomplete="off" aria-label="Search conversations">
            </div>
            <div class="lc-chat-tabs lc-chat-tabs-pro" role="tablist">
                <button type="button" class="lc-chat-tab active" data-tab="all" role="tab">All</button>
                <button type="button" class="lc-chat-tab" data-tab="waiting" role="tab">Waiting</button>
                <button type="button" class="lc-chat-tab" data-tab="active" role="tab">Active</button>
            </div>
            <div class="lc-chat-items" id="lc-conversation-list">
                <div class="lc-loading-inline" id="lc-list-loading"><i class="ti ti-loader-2"></i> Loading…</div>
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
                            <div class="lc-chat-header-title-row">
                                <strong id="lc-chat-visitor-name">Visitor</strong>
                                <div class="lc-chat-ref" id="lc-chat-ref" hidden>
                                    <span class="lc-chat-ref-icon" title="Ticket reference" aria-hidden="true"><i class="ti ti-ticket"></i></span>
                                    <code class="lc-chat-ref-code" id="lc-chat-ref-code">—</code>
                                    <button type="button" class="lc-chat-ref-copy" id="lc-chat-ref-copy" title="Copy ticket reference" aria-label="Copy ticket reference"><i class="ti ti-copy"></i></button>
                                </div>
                            </div>
                            <div class="lc-visitor-details lc-visitor-details-pro" id="lc-chat-visitor-details"></div>
                        </div>
                    </div>
                    <div class="lc-chat-header-actions">
                        <button type="button" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-accept-chat" style="display:none"><i class="ti ti-check"></i> Accept</button>
                        <div class="lc-chat-more-wrap" id="lc-chat-more-wrap">
                            <button type="button" class="lc-chat-more-btn" id="lc-chat-more-btn" aria-label="Chat actions" aria-haspopup="true" aria-expanded="false" title="More actions">
                                <i class="ti ti-dots-vertical"></i>
                            </button>
                            <div class="lc-chat-more-menu" id="lc-chat-more-menu" role="menu" hidden>
                                <div class="lc-chat-more-canned" role="none">
                                    <span class="lc-chat-more-canned-label"><i class="ti ti-bolt"></i> Canned reply</span>
                                    <select id="lc-canned-select" class="lc-status-select lc-canned-select-pro" aria-label="Canned reply"><option value="">Select a reply…</option></select>
                                </div>
                                <div class="lc-transfer-wrap" id="lc-transfer-wrap" style="display:none" role="none">
                                    <button type="button" class="lc-chat-more-item" id="lc-transfer-chat" role="menuitem" aria-expanded="false">
                                        <i class="ti ti-arrows-exchange"></i> Transfer
                                    </button>
                                </div>
                                <button type="button" class="lc-chat-more-item lc-chat-more-block" id="lc-block-ip-btn" role="menuitem" hidden>
                                    <i class="ti ti-ban"></i> <span id="lc-block-ip-label">Block IP</span>
                                </button>
                                <button type="button" class="lc-chat-more-item is-danger" id="lc-close-chat" role="menuitem">
                                    <i class="ti ti-x"></i> Close
                                </button>
                            </div>
                            <div class="lc-transfer-panel" id="lc-transfer-panel" hidden>
                                <div class="lc-transfer-panel-head">
                                    <strong><i class="ti ti-arrows-exchange"></i> Transfer chat</strong>
                                    <button type="button" class="lc-transfer-close" id="lc-transfer-close" title="Close"><i class="ti ti-x"></i></button>
                                </div>
                                <p class="lc-transfer-hint">Select an online agent to take over this conversation. The ticket reference stays the same — full chat history moves with it.</p>
                                <div class="lc-transfer-ref" id="lc-transfer-ref" hidden>
                                    <span class="lc-transfer-ref-label"><i class="ti ti-ticket"></i></span>
                                    <code id="lc-transfer-ref-code">—</code>
                                </div>
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
                    </div>
                </div>

                <div class="lc-chat-context-bar" id="lc-chat-visitor-meta" hidden></div>

                <div class="lc-page-journey lc-page-journey-pro" id="lc-page-journey" style="display:none">
                    <button type="button" class="lc-journey-bar" id="lc-journey-toggle" aria-expanded="false" aria-controls="lc-journey-track-wrap">
                        <span class="lc-journey-bar-icon" aria-hidden="true"><i class="ti ti-route"></i></span>
                        <span class="lc-journey-bar-text">
                            <strong>Page journey</strong>
                            <span class="lc-journey-bar-summary" id="lc-journey-summary">Visitor path on your site</span>
                        </span>
                        <span class="lc-journey-bar-count" id="lc-journey-count">0 pages</span>
                        <i class="ti ti-chevron-down lc-journey-bar-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="lc-journey-track-wrap" id="lc-journey-track-wrap" hidden>
                        <div class="lc-journey-track" id="lc-page-journey-list"></div>
                    </div>
                </div>

                <div class="lc-chat-messages lc-chat-messages-pro" id="lc-messages"></div>

                <div class="lc-chat-compose lc-chat-compose-pro">
                    <div class="lc-chat-compose-inner">
                        <button type="button" class="lc-chat-attach-btn" id="lc-attach-file" title="Attach image or PDF" aria-label="Attach file"><i class="ti ti-paperclip"></i></button>
                        <textarea id="lc-reply-input" rows="1" placeholder="Type your reply…"></textarea>
                        <button type="button" class="lc-chat-send-btn" id="lc-send-reply" title="Send message"><i class="ti ti-send"></i></button>
                        <input type="file" id="lc-file-input" accept="<?= lc_sanitize(lc_attachment_file_accept()) ?>" hidden>
                    </div>
                    <p class="lc-chat-compose-hint"><kbd>Enter</kbd> to send · <kbd>Shift+Enter</kbd> new line</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="lc-ip-modal" id="lc-ip-modal" hidden aria-hidden="true">
    <div class="lc-ip-modal-backdrop" id="lc-ip-modal-backdrop"></div>
    <div class="lc-ip-modal-card" role="dialog" aria-modal="true" aria-labelledby="lc-ip-modal-title">
        <div class="lc-ip-modal-icon" id="lc-ip-modal-icon"><i class="ti ti-ban"></i></div>
        <h4 id="lc-ip-modal-title">Block IP</h4>
        <p id="lc-ip-modal-msg"></p>
        <div class="lc-ip-modal-actions">
            <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm" id="lc-ip-modal-cancel">Cancel</button>
            <button type="button" class="lc-btn lc-btn-danger lc-btn-sm" id="lc-ip-modal-ok">OK</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
