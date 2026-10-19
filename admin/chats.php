<?php
require_once __DIR__ . '/../includes/auth.php';
$agent = lc_require_auth();
$selectedId = (int) ($_GET['id'] ?? 0);
$pageTitle = 'Chats';
$activeNav = 'chats';
$extraScripts = ['lc-chat-attachments.js', 'admin-chat.js'];
require __DIR__ . '/includes/header.php';
?>
<script>window.LC_CHAT_PAGE = true;</script>

<div class="lc-chats-pro">
    <div class="lc-chat-layout lc-chat-layout-pro lc-chat-desk" id="lc-chat-app" data-agent-id="<?= (int)$agent['id'] ?>" data-agent-role="<?= lc_sanitize($agent['role']) ?>" data-selected="<?= $selectedId ?>">
        <aside class="lc-chat-list lc-chat-inbox-pro">
            <div class="lc-chat-inbox-head">
                <div class="lc-chat-inbox-title">
                    <strong>Chats</strong>
                    
                </div>
                <span id="lc-waiting-badge" class="lc-chat-inbox-waiting" style="display:none">0</span>
            </div>
            <div class="lc-inbox-return-bar">
                <button type="button" class="lc-inbox-return" id="lc-inbox-return" aria-label="Close chat list"><i class="ti ti-x"></i></button>
            </div>
            <div class="lc-chat-inbox-search-wrap">
                <i class="ti ti-search"></i>
                <input type="search" id="lc-inbox-search" placeholder="Search name, email, phone, ticket (incl. closed)..." autocomplete="off" aria-label="Search conversations">
            </div>
            <?php
            $requestedTab = (string) ($_GET['tab'] ?? '');
            $defaultTab = (($agent['role'] ?? '') === 'admin') ? 'all' : 'waiting';
            if ($requestedTab !== '' && in_array($requestedTab, ['all', 'waiting', 'active'], true)) {
                if (($agent['role'] ?? '') !== 'admin' && $requestedTab === 'all') {
                    $defaultTab = 'waiting';
                } else {
                    $defaultTab = $requestedTab;
                }
            }
            $isSuperviseReq = !empty($_GET['supervise']);
            if ($selectedId > 0) {
                try {
                    $cStmt = lc_db()->prepare('SELECT status, agent_id FROM conversations WHERE id = ? LIMIT 1');
                    $cStmt->execute([$selectedId]);
                    $selConv = $cStmt->fetch(PDO::FETCH_ASSOC);
                    $cAgentId = (int) ($selConv['agent_id'] ?? 0);
                    if ($selConv && ($selConv['status'] ?? '') === 'active') {
                        if ($cAgentId === (int) $agent['id']) {
                            $defaultTab = 'active';
                        } elseif ($isSuperviseReq && function_exists('lc_record_agent_supervising')) {
                            lc_record_agent_supervising($selectedId, (int) $agent['id']);
                            $defaultTab = 'waiting';
                        }
                    }
                } catch (Throwable $e) {}
            }
            ?>
            <div class="lc-chat-tabs lc-chat-tabs-pro" role="tablist">
                <?php if (($agent['role'] ?? '') === 'admin'): ?>
                    <button type="button" class="lc-chat-tab <?= $defaultTab === 'all' ? 'active' : '' ?>" data-tab="all" role="tab">All</button>
                    <button type="button" class="lc-chat-tab <?= $defaultTab === 'waiting' ? 'active' : '' ?>" data-tab="waiting" role="tab">Unassigned</button>
                    <button type="button" class="lc-chat-tab <?= $defaultTab === 'active' ? 'active' : '' ?>" data-tab="active" role="tab">My chats</button>
                <?php else: ?>
                    <button type="button" class="lc-chat-tab <?= $defaultTab === 'waiting' ? 'active' : '' ?>" data-tab="waiting" role="tab">Unassigned</button>
                    <button type="button" class="lc-chat-tab <?= $defaultTab === 'active' ? 'active' : '' ?>" data-tab="active" role="tab">My chats</button>
                <?php endif; ?>
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
                    <button type="button" class="lc-chat-back" id="lc-chat-back" aria-label="Back to chats">
                        <i class="ti ti-arrow-left"></i>
                    </button>
                    <div class="lc-chat-header-visitor">
                        <div class="lc-chat-header-av" id="lc-chat-header-av" aria-hidden="true">V</div>
                        <div class="lc-chat-header-info">
                            <div class="lc-chat-header-title-row">
                                <strong id="lc-chat-visitor-name">Visitor</strong>
                                <span id="lc-chat-visitor-kind" class="lc-visitor-kind-badge" hidden></span>
                                <span id="lc-chat-transfer-badge" class="lc-visitor-kind-badge is-transferred" hidden title="This chat is active. It was transferred to you.">Active · Transferred to you</span>
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
                        <button type="button" class="lc-chat-details-toggle" id="lc-details-toggle" title="Customer details" aria-label="Customer details" aria-expanded="false" aria-controls="lc-chat-details">
                            <i class="ti ti-user-circle"></i>
                            <span>Details</span>
                        </button>
                        <div class="lc-chat-more-wrap" id="lc-chat-more-wrap">
                            <button type="button" class="lc-chat-more-btn" id="lc-chat-more-btn" aria-label="Chat actions" aria-haspopup="true" aria-expanded="false" title="More actions">
                                <i class="ti ti-dots-vertical"></i>
                            </button>
                            <div class="lc-chat-more-menu" id="lc-chat-more-menu" role="menu" hidden>
                                <div class="lc-transfer-wrap" id="lc-transfer-wrap" style="display:none" role="none">
                                    <button type="button" class="lc-chat-more-item" id="lc-transfer-chat" role="menuitem" aria-expanded="false">
                                        <i class="ti ti-arrows-exchange"></i> Transfer
                                    </button>
                                </div>
                                <button type="button" class="lc-chat-more-item lc-chat-more-takeover" id="lc-takeover-chat" role="menuitem" hidden>
                                    <i class="ti ti-arrow-right-circle"></i> Take over chat
                                </button>
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

                <div id="lc-visitor-presence-bar" class="lc-visitor-presence-bar" hidden role="status" aria-live="polite">
                    <span class="lc-visitor-presence-dot" aria-hidden="true"></span>
                    <span id="lc-visitor-presence-text">Visitor status unknown</span>
                </div>

                <div class="lc-chat-messages lc-chat-messages-pro" id="lc-messages"></div>

                <div class="lc-typing-indicator" id="lc-typing-indicator" hidden aria-live="polite" aria-atomic="true">
                    <div class="lc-typing-row">
                        <div class="lc-typing-bubble" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </div>
                        <span class="lc-typing-label" id="lc-typing-label">Visitor is typing...</span>
                    </div>
                </div>

                                <div class="lc-reopen-bar" id="lc-reopen-bar" hidden>
                    <p class="lc-reopen-title">Visitor is browsing.</p>
                    <div class="lc-reopen-actions">
                        <button type="button" class="lc-btn lc-btn-open" id="lc-reopen-chat">Open</button>
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

                <div class="lc-supervise-bar" id="lc-supervise-bar" hidden>
                    <div class="lc-supervise-bar-compact" id="lc-supervise-bar-main">
                        <span class="lc-supervise-badge" title="You are supervising this chat">
                            <i class="ti ti-shield-check"></i> Supervising
                        </span>
                        <span class="lc-supervise-bar-agent" id="lc-supervise-agent-label">
                            <strong id="lc-supervise-agent-name">Agent</strong>
                        </span>
                        <div class="lc-supervise-bar-actions">
                            <div class="lc-supervise-menu" id="lc-supervise-menu">
                                <button type="button" class="lc-supervise-menu-btn" id="lc-supervise-menu-toggle" aria-expanded="false" aria-haspopup="true" title="Supervise actions">
                                    <i class="ti ti-dots"></i>
                                </button>
                                <div class="lc-supervise-menu-panel" id="lc-supervise-menu-panel" hidden>
                                    <p class="lc-supervise-menu-note">Assigned to <strong id="lc-supervise-menu-agent">Agent</strong>. View live messages and write internal notes.</p>
                                    <button type="button" class="lc-supervise-menu-item lc-supervise-menu-item-warn" id="lc-supervise-takeover-btn">
                                        <i class="ti ti-arrow-right-circle"></i> Take Over Chat
                                    </button>
                                </div>
                            </div>
                            <button type="button" class="lc-supervise-min-btn" id="lc-supervise-minimize-btn" title="Minimize" aria-label="Minimize supervise bar">
                                <i class="ti ti-chevron-up"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="lc-supervise-chip" id="lc-supervise-chip" hidden title="Expand supervise mode">
                        <i class="ti ti-shield-check"></i>
                        <span>Supervising</span>
                        <i class="ti ti-chevron-down"></i>
                    </button>
                </div>

                <div class="lc-chat-compose lc-chat-compose-pro" id="lc-chat-compose">
                    <div class="lc-canned-hash-picker" id="lc-canned-hash-picker" hidden role="listbox" aria-label="Canned response shortcuts">
                        <div class="lc-canned-hash-list" id="lc-canned-hash-list"></div>
                        <div class="lc-canned-hash-foot">
                            <button type="button" class="lc-canned-hash-new" id="lc-canned-hash-new">+ New</button>
                            <a class="lc-canned-hash-gear" href="<?= lc_sanitize(lc_admin_url('canned')) ?>" title="Canned replies" aria-label="Manage canned replies"><i class="ti ti-settings"></i></a>
                        </div>
                    </div>
                    <div class="lc-compose-shell">
                        <div class="lc-compose-toolbar" id="lc-compose-mode-bar">
                            <div class="lc-compose-mode-tabs" role="tablist" aria-label="Compose mode">
                                <button type="button" class="lc-compose-mode-tab is-active" id="lc-mode-tab-reply" role="tab" aria-selected="true" title="Send message to customer">
                                    <i class="ti ti-message-dots" aria-hidden="true"></i>
                                    <span>Reply</span>
                                </button>
                                <button type="button" class="lc-compose-mode-tab" id="lc-mode-tab-note" role="tab" aria-selected="false" title="Send internal note (agents only)">
                                    <i class="ti ti-lock" aria-hidden="true"></i>
                                    <span>Note</span>
                                </button>
                            </div>
                            <div class="lc-chat-compose-tools" id="lc-chat-compose-tools">
                                <div class="lc-chat-canned-bar">
                                    <button type="button" class="lc-chat-canned-toggle" id="lc-canned-toggle" aria-expanded="false" aria-controls="lc-canned-panel" title="Canned replies">
                                        <i class="ti ti-bolt" aria-hidden="true"></i>
                                        <span>Canned</span>
                                    </button>
                                    <div class="lc-chat-canned-panel" id="lc-canned-panel" hidden>
                                        <input type="search" class="lc-chat-canned-search" id="lc-canned-search-live" placeholder="Shortcut, or 3+ letters to search the message" autocomplete="off" aria-label="Search canned replies by shortcut">
                                        <div class="lc-chat-canned-list" id="lc-canned-list-live" role="listbox" aria-label="Canned replies"></div>
                                        <select id="lc-canned-select" class="lc-canned-select-hidden" aria-hidden="true" tabindex="-1"><option value="">Select a reply...</option></select>
                                    </div>
                                </div>
                                <a class="lc-chat-canned-manage" href="<?= lc_sanitize(lc_admin_url('canned')) ?>" title="Manage canned replies"><i class="ti ti-settings" aria-hidden="true"></i></a>
                            </div>
                        </div>
                        <div id="lc-attach-preview" class="lc-attach-preview" hidden></div>
                        <div class="lc-chat-compose-inner">
                            <button type="button" class="lc-chat-attach-btn" id="lc-attach-file" title="Attach files (images, PDF, Word, Excel…)" aria-label="Attach file"><i class="ti ti-paperclip"></i></button>
                            <textarea id="lc-reply-input" rows="4" placeholder="Type a message…"></textarea>
                            <button type="button" class="lc-chat-send-btn" id="lc-send-reply" title="Send message"><i class="ti ti-send"></i></button>
                            <input type="file" id="lc-file-input" accept="<?= lc_sanitize(lc_attachment_file_accept()) ?>" hidden>
                        </div>
                        <div class="lc-compose-footer">
                            <p class="lc-chat-compose-hint"><kbd>Enter</kbd> send · <kbd>Shift+Enter</kbd> new line · <kbd>#</kbd> canned</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="lc-details-backdrop" id="lc-details-backdrop" hidden></div>
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
                    <button type="button" class="lc-details-drawer-close" id="lc-details-close" title="Close details" aria-label="Close details">
                        <i class="ti ti-x"></i>
                    </button>
                </div>

                <div class="lc-details-hero">
                    <div class="lc-details-hero-av" id="lc-details-av">V</div>
                    <h3 class="lc-details-hero-name" id="lc-details-name">Visitor</h3>
                    <p class="lc-details-hero-location lc-details-copyline">
                        <span id="lc-details-location">-</span>
                        <button type="button" class="lc-meta-copy" data-copy-target="lc-details-location" aria-label="Copy location" hidden><i class="ti ti-copy"></i></button>
                    </p>
                    <p class="lc-details-hero-sub lc-details-copyline">
                        <span id="lc-details-local-time"></span>
                        <button type="button" class="lc-meta-copy" data-copy-target="lc-details-local-time" aria-label="Copy IP" hidden><i class="ti ti-copy"></i></button>
                    </p>
                    <button type="button" class="lc-btn lc-btn-secondary lc-btn-sm lc-details-email-btn" id="lc-details-email-chat">
                        <i class="ti ti-mail"></i> Email chat to customer
                    </button>
                </div>

                <div class="lc-details-contact" id="lc-details-contact">
                    <h4 class="lc-details-contact-title">Contact</h4>
                    <label class="lc-details-contact-label" for="lc-details-name-input">Name</label>
                    <div class="lc-details-contact-copy">
                        <input type="text" id="lc-details-name-input" class="lc-details-contact-input" placeholder="Add customer name" autocomplete="off" maxlength="120">
                        <button type="button" class="lc-meta-copy" data-copy-input="lc-details-name-input" aria-label="Copy name"><i class="ti ti-copy"></i></button>
                    </div>
                    <label class="lc-details-contact-label" for="lc-details-email-input">Email</label>
                    <div class="lc-details-contact-copy">
                        <input type="email" id="lc-details-email-input" class="lc-details-contact-input" placeholder="Add email address" autocomplete="off" inputmode="email">
                        <button type="button" class="lc-meta-copy" data-copy-input="lc-details-email-input" aria-label="Copy email"><i class="ti ti-copy"></i></button>
                    </div>
                    <label class="lc-details-contact-label" for="lc-details-phone-input">Phone</label>
                    <div class="lc-details-contact-copy">
                        <input type="tel" id="lc-details-phone-input" class="lc-details-contact-input" placeholder="Add phone number" autocomplete="off" inputmode="tel">
                        <button type="button" class="lc-meta-copy" data-copy-input="lc-details-phone-input" aria-label="Copy phone"><i class="ti ti-copy"></i></button>
                    </div>
                    <div class="lc-details-contact-actions">
                        <div id="lc-details-contact-readonly-note" class="lc-details-contact-readonly-note" style="display:none"><i class="ti ti-lock"></i> <span>View only (Supervising)</span></div>
                        <button type="button" class="lc-btn lc-btn-sm lc-btn-primary" id="lc-details-contact-save">Save contact</button>
                        <span class="lc-details-contact-status" id="lc-details-contact-status" aria-live="polite"></span>
                    </div>
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
                                <dd class="lc-details-copyline"><span id="lc-details-returning">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-returning" aria-label="Copy returning visitor" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Last seen</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-last-seen">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-last-seen" aria-label="Copy last seen" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Came from</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-came-from">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-came-from" aria-label="Copy came from" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Chat duration</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-chat-duration">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-chat-duration" aria-label="Copy chat duration" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Groups</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-groups">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-groups" aria-label="Copy group" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Ticket</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-ticket">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-ticket" aria-label="Copy ticket" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>Agent</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-agent">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-agent" aria-label="Copy agent" hidden><i class="ti ti-copy"></i></button></dd>
                            </div>
                            <div class="lc-details-kv">
                                <dt>IP address</dt>
                                <dd class="lc-details-copyline"><span id="lc-details-ip">-</span><button type="button" class="lc-meta-copy" data-copy-target="lc-details-ip" aria-label="Copy IP address" hidden><i class="ti ti-copy"></i></button></dd>
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

                <div class="lc-details-acc is-open" id="lc-details-notes-acc">
                    <button type="button" class="lc-details-acc-head" id="lc-details-notes-toggle" aria-expanded="true" aria-controls="lc-details-notes-body">
                        <span class="lc-details-notes-title-wrap">
                            <i class="ti ti-notes" aria-hidden="true"></i> Internal Notes
                            <span class="lc-badge lc-badge-sm lc-notes-badge" id="lc-details-notes-count">0</span>
                        </span>
                        <i class="ti ti-chevron-up lc-details-acc-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="lc-details-acc-body" id="lc-details-notes-body">
                        <div class="lc-details-notes-list" id="lc-details-notes-list">
                            <p class="lc-details-notes-empty" id="lc-details-notes-empty">No internal notes yet</p>
                        </div>
                        <form class="lc-details-note-form" id="lc-details-note-form">
                            <textarea id="lc-details-note-input" rows="2" placeholder="Add internal note (agents only)..." maxlength="1000" required></textarea>
                            <div class="lc-details-note-actions">
                                <span class="lc-details-note-hint"><i class="ti ti-lock"></i> Hidden from customer</span>
                                <button type="submit" class="lc-btn lc-btn-primary lc-btn-sm" id="lc-details-note-submit">Add Note</button>
                            </div>
                        </form>
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

<div class="lc-canned-modal" id="lc-canned-create" hidden>
    <div class="lc-canned-modal-backdrop" id="lc-canned-create-backdrop"></div>
    <div class="lc-canned-modal-card" role="dialog" aria-modal="true" aria-labelledby="lc-canned-create-title">
        <div class="lc-canned-modal-head">
            <h3 id="lc-canned-create-title">Create canned response</h3>
            <button type="button" class="lc-canned-modal-x" id="lc-canned-create-close" aria-label="Close"><i class="ti ti-x"></i></button>
        </div>
        <div class="lc-canned-vis" role="tablist" aria-label="Who can use this response">
            <button type="button" class="lc-canned-vis-btn is-active" id="lc-canned-vis-shared" data-visibility="shared">
                <i class="ti ti-users"></i> Shared
            </button>
            <button type="button" class="lc-canned-vis-btn" id="lc-canned-vis-private" data-visibility="private">
                <i class="ti ti-user"></i> Private
            </button>
        </div>
        <p class="lc-canned-vis-hint" id="lc-canned-vis-hint">All group members will have access to this canned response</p>
        <label class="lc-canned-modal-label" for="lc-canned-create-message">Response text</label>
        <textarea id="lc-canned-create-message" rows="6" maxlength="2000" placeholder="Frequently used response..."></textarea>
        <p class="lc-canned-create-error" id="lc-canned-create-error" hidden>Enter your response.</p>
        <div class="lc-canned-shortcut-label">Shortcuts</div>
        <div class="lc-canned-shortcut-chips" id="lc-canned-shortcut-chips"></div>
        <input type="text" id="lc-canned-create-shortcut" maxlength="40" placeholder="#Type shortcut..." autocomplete="off">
        <p class="lc-canned-shortcut-hint">Hit enter to add a new one</p>
        <div class="lc-canned-modal-actions">
            <button type="button" class="lc-btn lc-btn-secondary" id="lc-canned-create-cancel">Cancel</button>
            <button type="button" class="lc-btn lc-btn-primary" id="lc-canned-create-save">Save</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/force-read-blue.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
