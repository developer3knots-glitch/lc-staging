<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Chats';
$activeNav = 'chats';
$extraScripts = ['lc-chat-attachments.js', 'admin-chat.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="lc-chats-pro">
    <div class="lc-chat-layout lc-chat-layout-pro lc-chat-desk" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role']) ?>" data-selected="<?= $selectedId ?>">
        <aside class="lc-chat-list lc-chat-inbox-pro">
            <div class="lc-chat-inbox-head">
                <div class="lc-chat-inbox-title">
                    <strong>Chats</strong>
                    <span class="lc-chat-inbox-live"><span class="lc-status-dot online"></span> Live inbox</span>
                </div>
                <span id="lc-waiting-badge" class="lc-chat-inbox-waiting" style="display:none">0</span>
            </div>
            <div class="lc-chat-inbox-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-inbox-search" placeholder="Search customers…" autocomplete="off" aria-label="Search conversations">
            </div>
            <div class="lc-chat-tabs lc-chat-tabs-pro" role="tablist">
                <button type="button" class="lc-chat-tab active" data-tab="all" role="tab">All</button>
                <button type="button" class="lc-chat-tab" data-tab="waiting" role="tab">Unassigned</button>
                <button type="button" class="lc-chat-tab" data-tab="active" role="tab">My chats</button>
            </div>
            <div class="lc-chat-items" id="lc-conversation-list">
                <div class="lc-loading-inline" id="lc-list-loading"><i class="ti ti-loader-2"></i> Loading…</div>
            </div>
        </aside>

        <div class="lc-chat-panel lc-chat-panel-pro">
            <div class="lc-chat-panel-empty" id="lc-chat-empty">
                <div class="lc-chat-empty-art">
                    <span class="lc-chat-empty-ring"></span>
                    <span class="lc-chat-empty-icon"><i class="ti ti-message-circle"></i></span>
                </div>
                <h3>Select a chat</h3>
                <p>Pick a conversation from the list to reply, transfer, or close.</p>
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
                            <div class="lc-visitor-details lc-visitor-details-pro lc-visitor-details-inline" id="lc-chat-visitor-details"></div>
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
                                    <i class="ti ti-x"></i> Close chat
                                </button>
                            </div>
                            <div class="lc-transfer-panel" id="lc-transfer-panel" hidden>
                                <div class="lc-transfer-panel-head">
                                    <strong><i class="ti ti-arrows-exchange"></i> Transfer chat</strong>
                                    <button type="button" class="lc-transfer-close" id="lc-transfer-close" title="Close"><i class="ti ti-x"></i></button>
                                </div>
                                <p class="lc-transfer-hint">Select an online agent. Ticket reference stays the same.</p>
                                <div class="lc-transfer-ref" id="lc-transfer-ref" hidden>
                                    <span class="lc-transfer-ref-label"><i class="ti ti-ticket"></i></span>
                                    <code id="lc-transfer-ref-code">—</code>
                                </div>
                                <div class="lc-transfer-agents" id="lc-transfer-agents">
                                    <div class="lc-transfer-loading"><i class="ti ti-loader-2"></i> Loading agents…</div>
                                </div>
                                <label class="lc-transfer-note-label" for="lc-transfer-note">Note for the next agent (optional)</label>
                                <textarea id="lc-transfer-note" rows="2" maxlength="500" placeholder="Brief context…"></textarea>
                                <button type="button" class="lc-btn lc-btn-primary lc-btn-sm lc-transfer-submit" id="lc-transfer-submit" disabled>
                                    <i class="ti ti-send"></i> Transfer
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kept for JS hooks; content mirrored to right details rail -->
                <div class="lc-chat-context-bar" id="lc-chat-visitor-meta" hidden></div>

                <div class="lc-chat-messages lc-chat-messages-pro" id="lc-messages"></div>

                <div class="lc-typing-indicator" id="lc-typing-indicator" hidden aria-live="polite" aria-atomic="true">
                    <div class="lc-typing-row">
                        <div class="lc-typing-bubble" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <span class="lc-typing-label" id="lc-typing-label">Visitor is typing…</span>
                    </div>
                    <div class="lc-typing-preview" id="lc-typing-preview" hidden></div>
                </div>

                <div class="lc-chat-compose lc-chat-compose-pro">
                    <div class="lc-chat-compose-inner">
                        <button type="button" class="lc-chat-attach-btn" id="lc-attach-file" title="Attach image or PDF" aria-label="Attach file"><i class="ti ti-paperclip"></i></button>
                        <textarea id="lc-reply-input" rows="1" placeholder="Type a message…"></textarea>
                        <button type="button" class="lc-chat-send-btn" id="lc-send-reply" title="Send message"><i class="ti ti-send"></i></button>
                        <input type="file" id="lc-file-input" accept="<?= lc_sanitize(lc_attachment_file_accept()) ?>" hidden>
                    </div>
                    <p class="lc-chat-compose-hint"><kbd>Enter</kbd> send · <kbd>Shift+Enter</kbd> new line</p>
                </div>
            </div>
        </div>

        <aside class="lc-chat-details" id="lc-chat-details" aria-label="Customer details">
            <div class="lc-chat-details-empty" id="lc-chat-details-empty">
                <i class="ti ti-user-circle"></i>
                <p>Customer details appear when you open a chat.</p>
            </div>
            <div class="lc-chat-details-body" id="lc-chat-details-body" hidden>
                <div class="lc-details-section">
                    <h4 class="lc-details-heading">Customer</h4>
                    <div class="lc-details-customer">
                        <div class="lc-details-av" id="lc-details-av">V</div>
                        <div>
                            <strong id="lc-details-name">Visitor</strong>
                            <span class="lc-details-email" id="lc-details-email">—</span>
                        </div>
                    </div>
                </div>
                <div class="lc-details-section">
                    <h4 class="lc-details-heading">Details</h4>
                    <dl class="lc-details-list">
                        <div class="lc-details-row">
                            <dt><i class="ti ti-world"></i> IP</dt>
                            <dd id="lc-details-ip">—</dd>
                        </div>
                        <div class="lc-details-row">
                            <dt><i class="ti ti-map-pin"></i> Location</dt>
                            <dd id="lc-details-country">—</dd>
                        </div>
                        <div class="lc-details-row">
                            <dt><i class="ti ti-tag"></i> Brand</dt>
                            <dd id="lc-details-brand">—</dd>
                        </div>
                        <div class="lc-details-row">
                            <dt><i class="ti ti-user"></i> Agent</dt>
                            <dd id="lc-details-agent">—</dd>
                        </div>
                        <div class="lc-details-row">
                            <dt><i class="ti ti-ticket"></i> Ticket</dt>
                            <dd id="lc-details-ticket">—</dd>
                        </div>
                    </dl>
                </div>
                <div class="lc-details-section">
                    <h4 class="lc-details-heading">Current page</h4>
                    <a class="lc-details-page" id="lc-details-page" href="#" target="_blank" rel="noopener noreferrer">—</a>
                </div>
                <div class="lc-details-section lc-details-journey-section">
                    <div class="lc-page-journey lc-page-journey-pro" id="lc-page-journey" style="display:none">
                        <button type="button" class="lc-journey-bar" id="lc-journey-toggle" aria-expanded="false" aria-controls="lc-journey-track-wrap">
                            <span class="lc-journey-bar-icon" aria-hidden="true"><i class="ti ti-route"></i></span>
                            <span class="lc-journey-bar-text">
                                <strong>Visited pages</strong>
                                <span class="lc-journey-bar-summary" id="lc-journey-summary">Path on your site</span>
                            </span>
                            <span class="lc-journey-bar-count" id="lc-journey-count">0</span>
                            <i class="ti ti-chevron-down lc-journey-bar-chevron" aria-hidden="true"></i>
                        </button>
                        <div class="lc-journey-track-wrap" id="lc-journey-track-wrap" hidden>
                            <div class="lc-journey-track" id="lc-page-journey-list"></div>
                        </div>
                    </div>
                </div>
            </div>
        </aside>
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
