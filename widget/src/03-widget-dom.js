/* LC widget module: 03-widget-dom.js — buildWidget DOM, storage, maximize */
        injectStyles();
        var root = document.createElement('div');
        root.id = 'lc-widget-root';
        root.className = 'lcw-booting';
        root.innerHTML =
            '<div id="lc-teaser" class="lcw-teaser" style="display:none" role="button" tabindex="0">' +
                '<button type="button" class="lcw-teaser-close" id="lc-teaser-close" aria-label="Dismiss">&times;</button>' +
                '<p class="lcw-teaser-title" id="lc-teaser-title">Questions? We\'re here to help.</p>' +
                '<p class="lcw-teaser-text" id="lc-teaser-text">Chat with our team - we typically reply within a few minutes.</p>' +
            '</div>' +
            '<button type="button" id="lc-bubble" aria-label="Open chat">' +
                '<span id="lc-bubble-icon">' + ICON_CHAT + '</span>' +
                '<span id="lc-bubble-badge" style="display:none">1</span>' +
            '</button>' +
            '<div id="lc-panel" class="lcw-panel lcw-theme-dark" style="display:none">' +
                '<div id="lc-panel-toolbar" class="lcw-toolbar">' +
                    '<span class="lcw-toolbar-brand" id="lc-toolbar-brand">LiveChat</span>' +
                    '<div class="lcw-toolbar-actions">' +
                        '<div class="lcw-toolbar-menu-wrap" id="lc-toolbar-menu-wrap">' +
                            '<button type="button" id="lc-toolbar-menu" class="lcw-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                            '<div id="lc-toolbar-menu-pop" class="lcw-head-menu-pop lcw-toolbar-menu-pop" hidden role="menu">' +
                                '<button type="button" id="lc-toolbar-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                    '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                    '<span class="lcw-head-menu-label">Continue with ticket</span>' +
                                '</button>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-toolbar-minimize" class="lcw-icon-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-head" class="lcw-chat-head lcw-head-pro">' +
                    '<div class="lcw-head-menu-wrap" id="lc-head-menu-wrap">' +
                        '<button type="button" id="lc-head-menu" class="lcw-head-icon-btn" title="More options" aria-label="More options" aria-expanded="false" aria-haspopup="true">' + ICON_MENU + '</button>' +
                        '<div id="lc-head-menu-pop" class="lcw-head-menu-pop" hidden role="menu">' +
                            '<button type="button" id="lc-menu-resume-ticket" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_TICKET + '</span>' +
                                '<span class="lcw-head-menu-label">Resume with ticket</span>' +
                            '</button>' +
                            '<button type="button" id="lc-menu-send-transcript" class="lcw-head-menu-item" role="menuitem">' +
                                '<span class="lcw-head-menu-icon">' + ICON_MAIL + '</span>' +
                                '<span class="lcw-head-menu-label">Send transcript</span>' +
                            '</button>' +
                            '<div class="lcw-head-menu-item lcw-head-menu-sounds" role="none">' +
                                '<span class="lcw-head-menu-icon">' + ICON_SOUND + '</span>' +
                                '<span class="lcw-head-menu-label">Sounds</span>' +
                                '<label class="lcw-sound-toggle" aria-label="Notification sounds">' +
                                    '<input type="checkbox" id="lc-sound-toggle" checked>' +
                                    '<span class="lcw-sound-toggle-track"><span class="lcw-sound-toggle-thumb"></span></span>' +
                                '</label>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +                    '<div class="lcw-head-agent-pill" id="lc-head-agent-pill">' +
                        '<div id="lc-head-agent-avatar" class="lcw-head-avatar-wrap" style="display:none"></div>' +
                        '<div class="lcw-head-agent-fallback" id="lc-head-agent-fallback"><span class="lcw-online-dot"></span></div>' +
                        '<div class="lcw-head-agent-copy">' +
                            '<strong id="lc-panel-agent-name">Connecting...</strong>' +
                            '<span id="lc-panel-agent-role" class="lcw-head-agent-role">Support</span>' +
                        '</div>' +
                    '</div>' +
                    '<span id="lc-panel-ref" class="lcw-ref-badge lcw-ref-hidden" hidden>Ticket <code id="lc-panel-ref-code"></code></span>' +
                    '<small id="lc-panel-status" class="lcw-sr-only"><span class="lcw-online-dot"></span>Connecting...</small>' +
                    '<strong id="lc-panel-title" class="lcw-sr-only">LiveChat</strong>' +
                    '<div class="lcw-head-actions">' +
                        '<button type="button" id="lc-minimize-chat" class="lcw-head-btn" title="Minimize chat" aria-label="Minimize chat">' +
                            ICON_MINIMIZE +
                        '</button>' +
                        '<button type="button" id="lc-close-chat" class="lcw-head-btn lcw-head-btn-close" title="End chat" aria-label="End chat">' +
                            ICON_CLOSE +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-waiting-bar" class="lcw-waiting-bar">' +
                    '<span class="lcw-typing-dots"><span></span><span></span><span></span></span>' +
                    '<span id="lc-waiting-text">Connecting to live agent...</span>' +
                '</div>' +
                '<div id="lc-connecting-card" class="lcw-connecting-card" hidden>' +
                    '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                    '<div class="lcw-connecting-copy">' +
                        '<strong>Connecting to live agent</strong>' +
                        '<span>Please wait a moment...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-prechat" class="lcw-prechat" style="display:none">' +
                    '<div id="lc-prechat-status" class="lcw-status-card" style="display:none" hidden>' +
                        '<div class="lcw-status-icon" id="lc-prechat-status-icon">' +
                            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                        '</div>' +
                        '<div>' +
                            '<p id="lc-prechat-status-text">We\'re here to help. Leave a message and we\'ll get back to you.</p>' +
                            '<span class="lcw-response-hint" id="lc-response-hint"></span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-form-card" id="lc-prechat-start-panel">' +
                        '<div class="lcw-form-card-head">' +
                            '<p class="lcw-away-kicker" id="lc-away-kicker"><span class="lcw-away-kicker-dot" aria-hidden="true"></span>Away</p>' +
                            '<h3 class="lcw-form-title">Start a conversation</h3>' +
                            '<p class="lcw-form-subtitle">Tell us a little about yourself - our team will take it from here.</p>' +
                        '</div>' +
                        '<form id="lc-prechat-form" class="lcw-prechat-form" autocomplete="off" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                            '<div id="lc-prechat-fields"></div>' +
                            '<div class="lcw-hp-wrap" aria-hidden="true">' +
                                '<label for="lc-prechat-honeypot">Leave blank</label>' +
                                '<input type="text" id="lc-prechat-honeypot" class="lcw-hp" name="lc_hp_field" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                            '</div>' +
                            '<div id="lc-prechat-error" class="lcw-prechat-error" style="display:none"></div>' +
                            '<button type="submit" class="lcw-prechat-btn" id="lc-prechat-submit">' +
                                '<span class="lcw-btn-spinner"></span>' +
                                '<span class="lcw-btn-label">Start conversation</span>' +
                            '</button>' +
                        '</form>' +
                    '</div>' +
                    '<div id="lc-away-thanks" class="lcw-away-thanks" hidden>' +
                        '<div class="lcw-away-thanks-icon" aria-hidden="true">' +
                            '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' +
                        '</div>' +
                        '<p class="lcw-away-kicker lcw-away-kicker-static"><span class="lcw-away-kicker-dot" aria-hidden="true"></span>Message received</p>' +
                        '<h3 class="lcw-form-title">Thank you</h3>' +
                        '<p id="lc-away-thanks-text">We have your message. A member of the team will reply when we are back online.</p>' +
                    '</div>' +
                    '<div class="lcw-ticket-resume-wrap" id="lc-ticket-resume-wrap">' +
                        '<div id="lc-ticket-resume-panel" class="lcw-ticket-resume-panel" hidden>' +
                            '<div class="lcw-form-card lcw-ticket-resume-card">' +
                                '<div class="lcw-form-card-head">' +
                                    '<h3 class="lcw-form-title">Continue with ticket</h3>' +
                                    '<p class="lcw-form-subtitle">Enter your ticket reference and the same email you used before.</p>' +
                                '</div>' +
                                '<form id="lc-ticket-resume-form" class="lcw-prechat-form">' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-ref">Ticket reference <span>*</span></label>' +
                                        '<input type="text" id="lc-ticket-ref" required placeholder="LC-20260707-000019" autocomplete="off" autocapitalize="characters">' +
                                    '</div>' +
                                    '<div class="lcw-field">' +
                                        '<label for="lc-ticket-email">Email <span>*</span></label>' +
                                        '<input type="email" id="lc-ticket-email" required placeholder="you@company.com" autocomplete="email">' +
                                    '</div>' +
                                    '<input type="text" id="lc-ticket-honeypot" class="lcw-hp" name="lc_hp_ticket" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                                    '<div id="lc-ticket-resume-error" class="lcw-prechat-error" style="display:none"></div>' +
                                    '<button type="submit" class="lcw-prechat-btn" id="lc-ticket-resume-submit">' +
                                        '<span class="lcw-btn-spinner"></span>' +
                                        '<span class="lcw-btn-label">Continue conversation</span>' +
                                    '</button>' +
                                    '<button type="button" class="lcw-ticket-resume-back" id="lc-ticket-resume-back">Back to new conversation</button>' +
                                '</form>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="lcw-powered">' + ICON_LOCK + ' Secure &amp; confidential</div>' +
                '</div>' +
                '<div id="lc-panel-messages" class="lcw-messages">' +
                    '<div id="lc-chat-loading" class="lcw-chat-loading" hidden>' +
                        '<div class="lcw-connecting-spinner" aria-hidden="true"></div>' +
                        '<div class="lcw-connecting-copy">' +
                            '<strong id="lc-chat-loading-title">Loading chat...</strong>' +
                            '<span id="lc-chat-loading-sub">Waiting for chat to load</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-idle-intro" class="lcw-idle-intro" hidden>' +
                    '<div class="lcw-idle-agent-card">' +
                        '<div class="lcw-idle-avatars" id="lc-idle-avatars" aria-hidden="true"></div>' +
                        '<strong id="lc-idle-agent-name">Support</strong>' +
                        '<span id="lc-idle-agent-role" class="lcw-idle-role">We\'re here to help</span>' +
                    '</div>' +
                    '<div class="lcw-idle-msg">' +
                        '<div id="lc-idle-msg-avatar" class="lcw-idle-msg-avatar" aria-hidden="true"></div>' +
                        '<div class="lcw-idle-bubble" id="lc-idle-bubble-text"></div>' +
                    '</div>' +
                    '<button type="button" id="lc-lets-chat" class="lcw-lets-chat">Let\'s chat</button>' +
                '</div>' +
                '<div id="lc-typing-indicator" class="lcw-typing-indicator" hidden aria-live="polite">' +
                    '<div class="lcw-typing-row">' +
                        '<div class="lcw-typing-bubble" aria-hidden="true"><span></span><span></span><span></span></div>' +
                        '<span class="lcw-typing-label" id="lc-typing-label">Agent is typing...</span>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-ended" class="lcw-ended" style="display:none">' +
                    '<div class="lcw-ended-hero">' +
                        '<div class="lcw-ended-icon lcw-ended-wave" aria-hidden="true">' +
                            '<span class="lcw-wave-icon">' + ICON_WAVE + '</span>' +
                        '</div>' +
                        '<p class="lcw-ended-title">Thanks for chatting!</p>' +
                        '<p class="lcw-ended-text">It was great talking with you.<br>We\'re here whenever you need us again.</p>' +
                        '<p class="lcw-ended-note">This conversation has been closed.</p>' +
                        '<p class="lcw-ended-ref" id="lc-ended-ref" hidden>Your ticket reference: <code id="lc-ended-ref-code"> - </code></p>' +
                    '</div>' +
                    '<div class="lcw-ended-actions">' +
                        '<button type="button" id="lc-resume-ticket">Resume chat</button>' +
                        '<div class="lcw-email-export" id="lc-email-export">' +
                            '<button type="button" id="lc-email-transcript-toggle" class="lcw-ended-secondary">Email me transcript</button>' +
                            '<div id="lc-email-transcript-panel" class="lcw-email-export-panel" hidden>' +
                                '<label class="lcw-email-export-label" for="lc-email-transcript-input">Send this chat to</label>' +
                                '<input type="email" id="lc-email-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                                '<button type="button" id="lc-email-transcript-send" class="lcw-prechat-btn lcw-email-export-send">Send to email</button>' +
                                '<p id="lc-email-transcript-status" class="lcw-email-export-status" hidden></p>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" id="lc-new-chat">Start a new chat</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-panel-compose" class="lcw-compose lcw-compose-pro">' +
                    '<input type="text" id="lc-honeypot" class="lcw-hp" name="lc_hp_compose" value="" tabindex="-1" autocomplete="new-password" readonly aria-hidden="true" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">' +
                    '<div id="lc-attach-preview" class="lcw-attach-preview" hidden></div>' +
                    '<div id="lc-emoji-picker" class="lcw-emoji-picker" hidden role="listbox" aria-label="Emoji"></div>' +
                    '<div class="lcw-compose-row">' +
                        '<div class="lcw-compose-inner lcw-compose-pill">' +
                            '<button type="button" id="lc-attach-file" class="lcw-compose-plus" title="Attach file" aria-label="Attach file">' + ICON_PLUS + '</button>' +
                            '<textarea id="lc-input" rows="1" placeholder="Write a message..."></textarea>' +
                            '<button type="button" id="lc-emoji-btn" class="lcw-compose-emoji" title="Emoji" aria-label="Insert emoji" aria-expanded="false">' + ICON_EMOJI + '</button>' +
                        '</div>' +
                        '<button type="button" id="lc-send" aria-label="Send">' + ICON_SEND_UP + '</button>' +
                    '</div>' +
                    '<input type="file" id="lc-file-input" accept="image/*,.jpg,.jpeg,.png,.gif,.webp,.pdf,application/pdf" hidden>' +
                '</div>' +
                '<div id="lc-transcript-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-confirm-text">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-transcript-confirm">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-confirm-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-transcript-confirm-text" id="lc-transcript-confirm-text"></p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-transcript-confirm-gotit">Got it</button>' +
                    '</div>' +
                '</div>' +                '<div id="lc-transcript-sheet" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-transcript-sheet-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide lcw-transcript-sheet">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-transcript-sheet-close" aria-label="Close">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true">' + ICON_MAIL + '</div>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-transcript-sheet-title">Send transcript</p>' +
                        '<p class="lcw-sheet-text">We will email a copy of this chat when the conversation ends.</p>' +
                        '<label class="lcw-email-export-label" for="lc-menu-transcript-input">Email address</label>' +
                        '<input type="email" id="lc-menu-transcript-input" class="lcw-email-export-input" placeholder="you@company.com" autocomplete="email">' +
                        '<button type="button" id="lc-menu-transcript-send" class="lcw-sheet-btn lcw-sheet-btn-light">Request transcript</button>' +
                        '<p id="lc-menu-transcript-status" class="lcw-email-export-status" hidden></p>' +
                    '</div>' +
                '</div>' +                '<div id="lc-close-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-close-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-close-confirm-dismiss" aria-label="Keep chatting">&times;</button>' +
                        '<div class="lcw-sheet-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></div>' +
                        '<p class="lcw-sheet-title" id="lc-close-confirm-title">Do you really want to close the chat?</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-close" id="lc-close-confirm-yes">Close the chat</button>' +
                    '</div>' +
                '</div>' +
                '<div id="lc-minimize-confirm" class="lcw-sheet-overlay" hidden aria-modal="true" role="dialog" aria-labelledby="lc-minimize-confirm-title">' +
                    '<div class="lcw-sheet lcw-sheet-dark lcw-sheet-wide">' +
                        '<button type="button" class="lcw-sheet-x" id="lc-minimize-confirm-x" aria-label="Close dialog">&times;</button>' +
                        '<p class="lcw-sheet-title lcw-sheet-title-lg" id="lc-minimize-confirm-title">Keep chatting in this window?</p>' +
                        '<p class="lcw-sheet-text" id="lc-minimize-confirm-text">Your conversation stays open while you switch tabs or browse the site.</p>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-light" id="lc-minimize-confirm-keep">Keep chatting here</button>' +
                        '<button type="button" class="lcw-sheet-btn lcw-sheet-btn-dark" id="lc-minimize-confirm-close">Close window</button>' +
                    '</div>' +
                '</div>' +            '</div>';
        document.body.appendChild(root);
        bindMobileChatViewport();

        var msgScrollBox = document.getElementById('lc-panel-messages');
        if (msgScrollBox) {
            msgScrollBox.addEventListener('scroll', onMessagesScroll, { passive: true });
            msgScrollBox.addEventListener('click', function (e) {
                var link = e.target && e.target.closest ? e.target.closest('a.lc-msg-link') : null;
                if (!link) return;
                e.stopPropagation();
            });
        }
        root.addEventListener('wheel', function (e) {
            if (!isOpen) return;
            var panel = document.getElementById('lc-panel');
            if (!panel || panel.style.display === 'none') return;
            if (!root.contains(e.target)) return;
            var reactPicker = e.target.closest ? e.target.closest('#lcw-react-picker') : null;
            if (reactPicker) {
                var reactGrid = reactPicker.querySelector('.lc-emoji-grid');
                if (reactGrid) reactGrid.scrollTop += e.deltaY;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var emojiScroller = e.target.closest ? e.target.closest('.lc-emoji-grid, #lc-emoji-picker') : null;
            if (emojiScroller) {
                emojiScroller.scrollTop += e.deltaY;
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            var box = document.getElementById('lc-panel-messages');
            if (box && (box === e.target || box.contains(e.target))) {
                box.scrollTop += e.deltaY;
            }
            e.preventDefault();
            e.stopPropagation();
        }, { passive: false, capture: true });

        document.getElementById('lc-bubble').addEventListener('click', togglePanel);
        document.getElementById('lc-toolbar-minimize').addEventListener('click', requestMinimize);
        document.getElementById('lc-minimize-chat').addEventListener('click', requestMinimize);
        document.getElementById('lc-close-chat').addEventListener('click', showCloseConfirm);
        bindConfirmSheets();
        bindHeadMenu();
        bindToolbarMenu();
        bindTranscriptEmailInputs();
        var letsChatBtn = document.getElementById('lc-lets-chat');
        if (letsChatBtn) {
            letsChatBtn.addEventListener('click', function () {
                // Browser requires a user gesture before Notification.requestPermission()
                requestVisitorPushPermission(true);
                beginChatFromIdle();
            });
        }
        document.getElementById('lc-new-chat').addEventListener('click', startNewChat);
        document.getElementById('lc-resume-ticket').addEventListener('click', resumeFromEnded);
        var emailToggle = document.getElementById('lc-email-transcript-toggle');
        var emailSendBtn = document.getElementById('lc-email-transcript-send');
        if (emailToggle) emailToggle.addEventListener('click', function () { toggleEmailTranscriptPanel('ended'); });
        if (emailSendBtn) emailSendBtn.addEventListener('click', function () { sendChatTranscriptEmail(null, 'ended'); });
        document.getElementById('lc-ticket-resume-back').addEventListener('click', function () { hideTicketResumePanel(true); });
        document.getElementById('lc-ticket-resume-form').addEventListener('submit', submitTicketResumeForm);
        document.getElementById('lc-send').addEventListener('click', sendMessage);
        var msgBox = document.getElementById('lc-panel-messages');
        if (msgBox) {
            msgBox.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.lcw-msg-retry') : null;
                if (!btn || !msgBox.contains(btn)) return;
                var wrap = btn.closest('[data-msg-id]');
                if (wrap) retryVisitorMessage(wrap);
            });
        }
        bindEmojiPicker();
        document.getElementById('lc-prechat-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submitPrechatForm();
        });
        disablePrechatAutofill();

        var inputEl = document.getElementById('lc-input');
        function scheduleComposeActivity() {
            // Mobile soft keyboards often commit textarea.value AFTER the input event.
            // Reading synchronously can miss the draft → agent never sees "typing".
            setTimeout(function () {
                autoResizeTextarea(inputEl);
                onVisitorComposeActivity();
            }, 0);
        }
        inputEl.addEventListener('input', scheduleComposeActivity);
        inputEl.addEventListener('keyup', scheduleComposeActivity);
        inputEl.addEventListener('compositionupdate', scheduleComposeActivity);
        inputEl.addEventListener('compositionend', scheduleComposeActivity);
        inputEl.addEventListener('change', scheduleComposeActivity);
        inputEl.addEventListener('keydown', function (e) {
            if (e.isComposing || e.keyCode === 229) return; // IME composing on mobile
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
        });
        inputEl.addEventListener('paste', function (e) {
            setTimeout(onVisitorComposeActivity, 0);
            var items = e.clipboardData && e.clipboardData.items;
            if (!items || !conversationId) return;
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image') !== -1) {
                    var pasted = items[i].getAsFile();
                    if (pasted) {
                        e.preventDefault();
                        queueVisitorFile(pasted);
                    }
                    break;
                }
            }
        });

        var attachBtn = document.getElementById('lc-attach-file');
        var fileInput = document.getElementById('lc-file-input');
        if (attachBtn && fileInput) {
            attachBtn.addEventListener('click', function () {
                if (!conversationId) {
                    if (prechatFormEnabled && !visitorHasKnownIdentity()) {
                        showPrechatForm(true);
                        showPrechatError('Please start a conversation before sending files.');
                    } else {
                        showIdleChatReady();
                        appendMessage({ sender_type: 'system', message: 'Write a message first to start the chat, then you can attach files.' });
                    }
                    return;
                }
                fileInput.click();
            });
            fileInput.addEventListener('change', function () {
                var batch = fileInput.files && fileInput.files.length
                    ? Array.prototype.slice.call(fileInput.files)
                    : [];
                fileInput.value = '';
                if (batch.length) queueVisitorFiles(batch);
            });
            if (window.LCChatFiles) {
                window.LCChatFiles.applyFileInput(fileInput);
            }
        }

        document.getElementById('lc-teaser').addEventListener('click', function (e) {
            if (e.target.id === 'lc-teaser-close') return;
            hideTeaser(true);
            requestVisitorPushPermission(true);
            if (!isOpen) togglePanel();
        });
        document.getElementById('lc-teaser-close').addEventListener('click', function (e) {
            e.stopPropagation();
            hideTeaser(true);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                if (isConfirmOpen()) {
                    hideCloseConfirm();
                    hideMinimizeConfirm();
                    return;
                }
                requestMinimize();
            }
        });

        // Widget stays hidden (lcw-booting) until ready. Resume: until transcript paints.
        openPanelInstantIdle();
        setTimeout(function () {
            widgetStylesReady = true;
            widgetEntryReady = true;
            tryRevealWidget();
        }, waitingForResumeTranscript ? 0 : 1200);
        // Absolute cap — never leave the site without a visible widget (stuck resume).
        setTimeout(function () {
            if (widgetRevealed) return;
            if (waitingForResumeTranscript || bootHoldForChat) {
                hideIdleIntro();
                if (conversationId || pendingResumeConvId || (parseInt(getStoredConversationId(), 10) || 0)) {
                    showChatView();
                }
            }
            forceRevealWidget();
        }, 4000);
        if (!waitingForResumeTranscript) {
            scheduleWelcomeEngagement();
        }
        loadWidgetSettings();
        startSettingsSync();

        // Chat stays open across same-site page navigations
        window.addEventListener('pagehide', function (e) {
            if (e.persisted) return;
            if (isReloadNavigation()) {
                clearReloadNavigation();
            }
        });
    }

    /** sessionStorage = survives page refresh, cleared when tab closes */
    function chatStore() {
        return window.sessionStorage;
    }

    function persistConversationId(id) {
        var store = chatStore();
        if (id) {
            store.setItem(CONV_STORAGE_KEY, String(id));
        } else {
            store.removeItem(CONV_STORAGE_KEY);
            store.removeItem(LAST_MSG_KEY);
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function persistPanelOpen(open) {
        var store = chatStore();
        if (open) {
            store.setItem(PANEL_OPEN_KEY, '1');
        } else {
            store.removeItem(PANEL_OPEN_KEY);
        }
    }

    function getStoredPanelOpen() {
        return chatStore().getItem(PANEL_OPEN_KEY) === '1';
    }

    /** Active/resumed chats must open maximized after refresh. */
    function shouldRestoreMaximizedChat() {
        var stored = parseInt(getStoredConversationId(), 10) || 0;
        var live = parseInt(conversationId, 10) || 0;
        var pending = parseInt(pendingResumeConvId, 10) || 0;
        return stored > 0 || live > 0 || pending > 0;
    }

    function ensureChatMaximized(opts) {
        opts = opts || {};
        if (isOpen) {
            var panel = document.getElementById('lc-panel');
            if (panel && panel.style.display === 'none') {
                panel.style.setProperty('display', 'flex', 'important');
            }
            return;
        }
        openPanel({
            silenceFlow: opts.silenceFlow !== false,
            focusComposeOnOpen: !!opts.focusComposeOnOpen
        });
    }

    function persistLastMsgId(id) {
        id = parseInt(id, 10) || 0;
        // Guard against bogus WS-only ids (e.g. test payloads) poisoning the cursor
        if (id > 0 && id < 100000000) {
            chatStore().setItem(LAST_MSG_KEY, String(id));
        }
    }

    function advanceLastMsgId(id) {
        id = parseInt(id, 10) || 0;
        if (id < 1 || id >= 100000000) return;
        if (lastMsgId > 0 && id > lastMsgId + 1) {
            scheduleGapFill();
            forceMessageCatchup();
            // Still advance after paint paths call this — holes filled by gap-fill
        }
        if (id > lastMsgId) {
            lastMsgId = id;
            persistLastMsgId(lastMsgId);
        }
    }

    var gapFillTimer = null;
    var gapFillInFlight = false;
    function scheduleGapFill() {
        if (!conversationId) return;
        if (gapFillTimer) clearTimeout(gapFillTimer);
        gapFillTimer = setTimeout(function () {
            gapFillTimer = null;
            runGapFill();
        }, 120);
    }

    function runGapFill() {
        if (!conversationId || gapFillInFlight) return;
        if (blockHttpMessageSync()) return;
        if (isVisitorWsLive() && isVisitorWsDeliveryProven() && !window.__lcForcePollOnce) {
            return;
        }
        gapFillInFlight = true;
        window.__lcForcePollOnce = true;
        pollMessages(true, function () {
            window.__lcForcePollOnce = false;
            gapFillInFlight = false;
        });
    }

    function getStoredLastMsgId() {
        var stored = chatStore().getItem(LAST_MSG_KEY);
        return stored ? parseInt(stored, 10) : 0;
    }

    function persistChatToken(token) {
        var store = chatStore();
        if (token) {
            store.setItem(TOKEN_STORAGE_KEY, token);
        } else {
            store.removeItem(TOKEN_STORAGE_KEY);
        }
    }

    function getStoredChatToken() {
        return chatStore().getItem(TOKEN_STORAGE_KEY) || '';
    }

    function getStoredConversationId() {
        var stored = chatStore().getItem(CONV_STORAGE_KEY);
        return stored ? parseInt(stored, 10) : null;
    }

    function handleInitFailure(errorMsg) {
        if (isWidgetIpBlockedError(errorMsg)) {
            hideWidgetForBlockedIp();
            return;
        }
        conversationId = null;
        chatToken = '';
        lastMsgId = 0;
        pendingResumeConvId = null;
        waitingForResumeTranscript = false;
        bootHoldForChat = false;
        bootHistorySettled = true;
        persistConversationId(null);
        persistChatToken('');
        setWaitingState(false);
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        if (prechatFormEnabled) {
            showPrechatForm(true);
            if (errorMsg && errorMsg !== 'Pre-chat form required') {
                showPrechatError(errorMsg);
            }
            markWidgetViewReady();
            return;
        }
        showChatView();
        updateStatus('Could not connect', false);
        if (errorMsg) {
            appendMessage({ sender_type: 'system', message: errorMsg });
        }
        markWidgetViewReady();
    }

    function isWidgetIpBlockedError(msgOrData) {
        if (msgOrData && typeof msgOrData === 'object') {
            if (msgOrData.blocked || msgOrData.ip_blocked) return true;
            msgOrData = msgOrData.error || '';
        }
        var msg = String(msgOrData || '');
        return /access restricted/i.test(msg) || /ip.*(block|banned)/i.test(msg);
    }

    function hideWidgetForBlockedIp() {
        try {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (typeof settingsSyncTimer !== 'undefined' && settingsSyncTimer) {
                clearInterval(settingsSyncTimer);
                settingsSyncTimer = null;
            }
            conversationId = null;
            chatToken = '';
            persistConversationId(null);
            persistChatToken('');
            var root = document.getElementById('lc-root');
            if (root && root.parentNode) {
                root.parentNode.removeChild(root);
            }
            var bubble = document.getElementById('lc-bubble');
            if (bubble && bubble.parentNode) {
                bubble.parentNode.removeChild(bubble);
            }
        } catch (eHide) {}
    }

    function readHoneypotField(id) {
        var el = document.getElementById(id);
        if (!el) return '';
        // Autofill often fills honeypots; only treat as bot if a real user typed there.
        if (el.getAttribute('data-lc-user-typed') !== '1') {
            el.value = '';
            return '';
        }
        return (el.value || '').trim();
    }

    function clearHoneypotFields() {
        ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            el.value = '';
            el.removeAttribute('data-lc-user-typed');
            try { el.setAttribute('readonly', 'readonly'); } catch (eRo) {}
        });
    }

    function hardenHoneypotFields() {
        ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el || el.getAttribute('data-lc-hp-ready') === '1') return;
            el.setAttribute('data-lc-hp-ready', '1');
            el.value = '';
            el.setAttribute('readonly', 'readonly');
            el.addEventListener('focus', function () {
                // Real bots sometimes focus; humans almost never. Keep empty on focus.
                try { el.blur(); } catch (eBlur) {}
            });
            el.addEventListener('input', function () {
                el.setAttribute('data-lc-user-typed', '1');
            });
            el.addEventListener('change', function () {
                // Delayed autofill — wipe unless we already saw a genuine input event.
                if (el.getAttribute('data-lc-user-typed') !== '1') {
                    el.value = '';
                }
            });
        });
        // Autofill is async — wipe only autofilled honeypots (never mark as user-typed).
        [50, 300, 1000, 2500].forEach(function (ms) {
            setTimeout(function () {
                ['lc-prechat-honeypot', 'lc-ticket-honeypot', 'lc-honeypot'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (!el || el.getAttribute('data-lc-user-typed') === '1') return;
                    el.value = '';
                });
            }, ms);
        });
    }

    function disablePrechatAutofill() {
        var form = document.getElementById('lc-prechat-form');
        if (form) {
            form.setAttribute('autocomplete', 'off');
            form.setAttribute('data-lpignore', 'true');
            form.setAttribute('data-1p-ignore', 'true');
        }
        var prechatHost = document.getElementById('lc-prechat-fields');
        if (prechatHost) {
            prechatHost.querySelectorAll('input, textarea, select').forEach(function (el) {
                el.setAttribute('autocomplete', 'off');
                el.setAttribute('data-lpignore', 'true');
                el.setAttribute('data-1p-ignore', 'true');
                el.setAttribute('data-bwignore', 'true');
                el.setAttribute('data-form-type', 'other');
            });
        }
        hardenHoneypotFields();
    }

    function showTicketResumeError(msg) {
        var el = document.getElementById('lc-ticket-resume-error');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.style.display = 'block';
        } else {
            el.textContent = '';
            el.style.display = 'none';
        }
    }

    function showTicketResumePanel() {
        if (!ticketResumeEnabled) {
            return;
        }
        clearHoneypotFields();
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        if (startPanel) startPanel.style.display = 'none';
        if (ticketPanel) ticketPanel.hidden = false;
        var directStart = document.getElementById('lc-direct-chat-start');
        if (directStart) directStart.style.display = 'none';
        var refEl = document.getElementById('lc-ticket-ref');
        if (refEl) {
            setTimeout(function () { refEl.focus(); }, 30);
        }
        var emailEl = document.getElementById('lc-ticket-email');
        if (emailEl) {
            emailEl.value = storedVisitorEmail() || emailEl.value || '';
        }
        showTicketResumeError('');
    }

    function hideTicketResumePanel(cancelOnly) {
        var startPanel = document.getElementById('lc-prechat-start-panel');
        var ticketPanel = document.getElementById('lc-ticket-resume-panel');
        var prechat = document.getElementById('lc-panel-prechat');
        if (ticketPanel) ticketPanel.hidden = true;
        showTicketResumeError('');
        if (!prechatFormEnabled) {
            if (cancelOnly) {
                if (prechat) prechat.style.display = 'none';
                showIdleChatReady();
            }
            return;
        }
        if (startPanel) startPanel.style.display = '';
    }

    function openTicketResumeEntry() {
        if (!ticketResumeEnabled) {
            return;
        }
        hideHeadMenu();
        hideToolbarMenu();
        hideIdleIntro();
        showPrechatForm(true, true);
        if (!prechatFormEnabled) {
            var startPanel = document.getElementById('lc-prechat-start-panel');
            if (startPanel) startPanel.style.display = 'none';
            var directStart = document.getElementById('lc-direct-chat-start');
            if (directStart) directStart.style.display = 'none';
            var statusCard = document.getElementById('lc-prechat-status');
            if (statusCard) {
                statusCard.hidden = true;
                statusCard.style.display = 'none';
            }
        }
        showTicketResumePanel();
    }

    function updateEndedResumeButton() {
        var resumeBtn = document.getElementById('lc-resume-ticket');
        if (!resumeBtn) return;
        if (conversationId) {
            resumeBtn.textContent = 'Resume chat';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else if (ticketResumeEnabled) {
            resumeBtn.textContent = 'Continue with ticket';
            resumeBtn.hidden = false;
            resumeBtn.disabled = false;
        } else {
            resumeBtn.hidden = true;
        }
    }

    function resumeFromEnded() {
        if (conversationId) {
            resumeSameChat();
            return;
        }
        openTicketResumeFromEnded();
    }

    function showResumeComposer() {
