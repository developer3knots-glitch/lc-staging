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
                    
                </div>
                <span id="lc-waiting-badge" class="lc-chat-inbox-waiting" style="display:none">0</span>
            </div>
            <div class="lc-chat-inbox-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-inbox-search" placeholder="Search customers..." autocomplete="off" aria-label="Search conversations">
            </div>
            <div class="lc-chat-tabs lc-chat-tabs-pro" role="tablist">
                <button type="button" class="lc-chat-tab active" data-tab="all" role="tab">All</button>
                <button type="button" class="lc-chat-tab" data-tab="waiting" role="tab">Unassigned</button>
                <button type="button" class="lc-chat-tab" data-tab="active" role="tab">My chats</button>
            </div>
            <div class="lc-chat-items" id="lc-conversation-list">
                <div class="lc-loading-inline" id="lc-list-loading"><i class="ti ti-loader-2"></i> Loading...</div>
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
                                <span id="lc-chat-visitor-kind" class="lc-visitor-kind-badge" hidden></span>
                                <div class="lc-chat-ref" id="lc-chat-ref" hidden>
                                    <span class="lc-chat-ref-icon" title="Ticket reference" aria-hidden="true"><i class="ti ti-ticket"></i></span>
                                    <code class="lc-chat-ref-code" id="lc-chat-ref-code"> - </code>
                                    <button type="button" class="lc-chat-ref-copy" id="lc-chat-ref-copy" title="Copy ticket reference" aria-label="Copy ticket reference"><i class="ti ti-copy"></i></button>
                                </div>
                            </div>
                            <div class="lc-visitor-details lc-visitor-details-pro lc-visitor-details-inline" id="lc-chat-visitor-details"></div>
                        </div>
                    </div>
                    <div class="lc-chat-header-actions">
                        <button type="button" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-accept-chat" style="display:none" hidden aria-hidden="true">Assign to me</button>
                        <div class="lc-chat-more-wrap" id="lc-chat-more-wrap">
                            <button type="button" class="lc-chat-more-btn" id="lc-chat-more-btn" aria-label="Chat actions" aria-haspopup="true" aria-expanded="false" title="More actions">
                                <i class="ti ti-dots-vertical"></i>
                            </button>
                            <div class="lc-chat-more-menu" id="lc-chat-more-menu" role="menu" hidden>
                                <div class="lc-chat-more-canned" role="none">
                                    <span class="lc-chat-more-canned-label"><i class="ti ti-bolt"></i> Canned reply</span>
                                    <select id="lc-canned-select" class="lc-status-select lc-canned-select-pro" aria-label="Canned reply"><option value="">Select a reply...</option></select>
                                </div>
                                <div class="lc-transfer-wrap" id="lc-transfer-wrap" style="display:none" role="none">
                                    <button type="button" class="lc-chat-more-item" id="lc-transfer-chat" role="menuitem" aria-expanded="false">
                                        <i class="ti ti-arrows-exchange"></i> Transfer
                                    </button>
                                </div>
                                <button type="button" class="lc-chat-more-item" id="lc-email-transcript-btn" role="menuitem">
                                    <i class="ti ti-mail"></i> Email chat to customer
                                </button>
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
                                    <code id="lc-transfer-ref-code"> - </code>
                                </div>
                                <div class="lc-transfer-agents" id="lc-transfer-agents">
                                    <div class="lc-transfer-loading"><i class="ti ti-loader-2"></i> Loading agents...</div>
                                </div>
                                <label class="lc-transfer-note-label" for="lc-transfer-note">Note for the next agent (optional)</label>
                                <textarea id="lc-transfer-note" rows="2" maxlength="500" placeholder="Brief context..."></textarea>
                                <button type="button" class="lc-btn lc-btn-primary lc-btn-sm lc-transfer-submit" id="lc-transfer-submit" disabled>
                                    <i class="ti ti-send"></i> Transfer
                                </button>
                            </div>
                            <div class="lc-email-panel" id="lc-email-panel" hidden>
                                <div class="lc-email-panel-head">
                                    <strong><i class="ti ti-mail"></i> Email chat transcript</strong>
                                    <button type="button" class="lc-transfer-close" id="lc-email-panel-close" title="Close"><i class="ti ti-x"></i></button>
                                </div>
                                <p class="lc-transfer-hint">Choose what to email, then send to the customer.</p>
                                <div class="lc-email-scope" role="radiogroup" aria-label="Transcript scope">
                                    <label class="lc-email-scope-option">
                                        <input type="radio" name="lc-email-scope" id="lc-email-scope-current" value="current" checked>
                                        <span><strong>This chat only</strong><small>Just the current conversation</small></span>
                                    </label>
                                    <label class="lc-email-scope-option">
                                        <input type="radio" name="lc-email-scope" id="lc-email-scope-overall" value="overall">
                                        <span><strong>Overall history</strong><small>All related chats for this visitor</small></span>
                                    </label>
                                </div>
                                <label class="lc-transfer-note-label" for="lc-email-transcript-to">Customer email</label>
                                <input type="email" id="lc-email-transcript-to" class="lc-email-transcript-input" placeholder="customer@email.com" autocomplete="email">
                                <p class="lc-email-panel-status" id="lc-email-panel-status" hidden></p>
                                <button type="button" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-email-transcript-send">
                                    <i class="ti ti-send"></i> Send transcript
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
                        <span class="lc-typing-label" id="lc-typing-label">Visitor is typing...</span>
                    </div>
                </div>

                                <div class="lc-unassigned-bar" id="lc-unassigned-bar" hidden>
                    <p class="lc-unassigned-title">This chat is unassigned.</p>
                    <div class="lc-unassigned-actions">
                        <button type="button" class="lc-btn lc-btn-archive" id="lc-archive-chat">Close &amp; archive</button>
                        <button type="button" class="lc-btn lc-btn-assign" id="lc-assign-to-me">Assign to me</button>
                    </div>
                    <div class="lc-chat-tags-row">
                        <div class="lc-chat-tags" id="lc-chat-tags"></div>
                        <button type="button" class="lc-add-tag-btn" id="lc-add-tag-btn"><i class="ti ti-tag"></i> Add tag</button>
                        <form class="lc-add-tag-form" id="lc-add-tag-form" hidden>
                            <input type="text" id="lc-add-tag-input" maxlength="40" placeholder="Tag name" autocomplete="off">
                            <button type="submit">Add</button>
                            <button type="button" id="lc-add-tag-cancel">Cancel</button>
                        </form>
                    </div>
                </div>
                <div class="lc-chat-compose lc-chat-compose-pro" id="lc-chat-compose">
                    <div id="lc-attach-preview" class="lc-attach-preview" hidden></div>
                    <div class="lc-chat-compose-inner">
                        <button type="button" class="lc-chat-attach-btn" id="lc-attach-file" title="Attach files (images, PDF, Word, Excel…)" aria-label="Attach file"><i class="ti ti-paperclip"></i></button>
                        <textarea id="lc-reply-input" rows="1" placeholder="Type a message..."></textarea>
                        <button type="button" class="lc-chat-send-btn" id="lc-send-reply" title="Send message"><i class="ti ti-send"></i></button>
                        <input type="file" id="lc-file-input" accept="<?= lc_sanitize(lc_attachment_file_accept()) ?>" hidden>
                    </div>
                    <p class="lc-chat-compose-hint"><kbd>Enter</kbd> send <span class="lc-meta-sep" aria-hidden="true"></span> <kbd>Shift+Enter</kbd> new line</p>
                </div>
            </div>
        </div>

        <aside class="lc-chat-details lc-details-rail" id="lc-chat-details" aria-label="Customer details">
            <div class="lc-chat-details-empty" id="lc-chat-details-empty">
                <i class="ti ti-user-circle"></i>
                <p>Customer details appear when you open a chat.</p>
            </div>
            <div class="lc-chat-details-body lc-details-rail-body" id="lc-chat-details-body" hidden>
                <div class="lc-details-rail-top">
                    <div class="lc-details-rail-tabs">
                        <button type="button" class="lc-details-rail-tab is-active" aria-label="Customer profile"><i class="ti ti-user"></i></button>
                        <button type="button" class="lc-details-rail-tab" aria-label="Add tab" disabled><i class="ti ti-plus"></i></button>
                    </div>
                </div>

                <div class="lc-details-hero">
                    <div class="lc-details-hero-av" id="lc-details-av">V</div>
                    <h3 class="lc-details-hero-name" id="lc-details-name">Visitor</h3>
                    <p class="lc-details-hero-location" id="lc-details-location">-</p>
                    <p class="lc-details-hero-sub" id="lc-details-local-time"></p>
                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-details-email-btn" id="lc-details-email-chat">
                        <i class="ti ti-mail"></i> Email chat to customer
                    </button>
                </div>

                <div class="lc-details-map-wrap" id="lc-details-map-wrap" hidden>
                    <iframe id="lc-details-map" title="Customer location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>

                <div class="lc-details-acc is-open" id="lc-details-additional-acc">
                    <button type="button" class="lc-details-acc-head" id="lc-details-additional-toggle" aria-expanded="true" aria-controls="lc-details-additional-body">
                        <span>Additional info</span>
                        <i class="ti ti-chevron-up lc-details-acc-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="lc-details-acc-body" id="lc-details-additional-body">
                        <dl class="lc-details-kv-list">
                            <div class="lc-details-kv">
                                <dt>Returning visitor</dt>
                                <dd id="lc-details-returning">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Last seen</dt>
                                <dd id="lc-details-last-seen">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Came from</dt>
                                <dd id="lc-details-came-from">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Chat duration</dt>
                                <dd id="lc-details-chat-duration">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Groups</dt>
                                <dd id="lc-details-groups">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Email</dt>
                                <dd id="lc-details-email">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Ticket</dt>
                                <dd id="lc-details-ticket">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Agent</dt>
                                <dd id="lc-details-agent">-</dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>IP address</dt>
                                <dd id="lc-details-ip">-</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="lc-details-acc is-open" id="lc-details-pages-acc">
                    <button type="button" class="lc-details-acc-head" id="lc-details-pages-toggle" aria-expanded="true" aria-controls="lc-details-pages-body">
                        <span>Visited pages</span>
                        <i class="ti ti-chevron-up lc-details-acc-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="lc-details-acc-body" id="lc-details-pages-body">
                        <ul class="lc-details-pages-list" id="lc-details-pages-list"></ul>
                        <p class="lc-details-pages-empty" id="lc-details-pages-empty">No pages recorded yet</p>
                    </div>
                </div>

                <div class="lc-page-journey lc-page-journey-pro" id="lc-page-journey" hidden aria-hidden="true">
                    <div class="lc-journey-track" id="lc-page-journey-list"></div>
                    <span id="lc-journey-count" hidden></span>
                    <span id="lc-journey-summary" hidden></span>
                    <button type="button" id="lc-journey-toggle" hidden></button>
                    <div id="lc-journey-track-wrap" hidden></div>
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

<?php require __DIR__ . '/includes/force-read-blue.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
