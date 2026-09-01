$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\livechat'
Set-Location $root

function Set-FileText([string]$path, [string]$content) {
    $dir = Split-Path $path -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    try { attrib -R $path 2>$null | Out-Null } catch { }
    [System.IO.File]::WriteAllText($path, $content, [System.Text.UTF8Encoding]::new($false))
}

function Edit-File([string]$rel, [scriptblock]$edit) {
    $path = Join-Path $root $rel
    $text = [System.IO.File]::ReadAllText($path)
    $newText = & $edit $text
    if ($newText -ne $text) {
        Set-FileText $path $newText
        Write-Host "Updated $rel"
    } else {
        Write-Host "No change $rel"
    }
}

# includes/functions.php
Edit-File 'includes/functions.php' {
    param($t)
    if ($t -notmatch "realtime\.php") {
        $t = $t.Replace("require_once __DIR__ . '/typing.php';`r`n", "require_once __DIR__ . '/typing.php';`r`nrequire_once __DIR__ . '/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/typing.php';`n", "require_once __DIR__ . '/typing.php';`nrequire_once __DIR__ . '/realtime.php';`n")
    }
    return $t
}

# includes/config.example.php
Edit-File 'includes/config.example.php' {
    param($t)
    if ($t -notmatch 'LC_REALTIME_MODE') {
        $block = @"

// Realtime Option A: poll = HTTP (default). ws = VPS WebSocket server.
define('LC_REALTIME_MODE', 'poll');
define('LC_REALTIME_WS_URL', 'wss://realtime.yourdomain.com');
define('LC_REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish');
define('LC_REALTIME_SECRET', 'change-me-long-random-secret');

"@
        $t = $t.Replace("define('LC_POLL_INTERVAL', 1500);`r`n`r`n", "define('LC_POLL_INTERVAL', 1500);`r`n$block")
        $t = $t.Replace("define('LC_POLL_INTERVAL', 1500);`n`n", "define('LC_POLL_INTERVAL', 1500);`n$block")
    }
    return $t
}

# api/agent-send.php
Edit-File 'api/agent-send.php' {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t.Replace("require_once __DIR__ . '/../includes/auth.php';`r`n", "require_once __DIR__ . '/../includes/auth.php';`r`nrequire_once __DIR__ . '/../includes/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/../includes/auth.php';`n", "require_once __DIR__ . '/../includes/auth.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n")
    }
    if ($t -notmatch 'lc_realtime_publish_message') {
        $t = $t.Replace(
            "    lc_touch_conversation(`$conversationId);`r`n`r`n    lc_json(['success' => true, 'message_id' => (int) lc_db()->lastInsertId()]);",
            "    lc_touch_conversation(`$conversationId);`r`n`r`n    `$messageId = (int) lc_db()->lastInsertId();`r`n    lc_realtime_publish_message(`$conversationId, `$messageId);`r`n`r`n    lc_json(['success' => true, 'message_id' => `$messageId]);"
        )
        $t = $t.Replace(
            "    lc_touch_conversation(`$conversationId);`n`n    lc_json(['success' => true, 'message_id' => (int) lc_db()->lastInsertId()]);",
            "    lc_touch_conversation(`$conversationId);`n`n    `$messageId = (int) lc_db()->lastInsertId();`n    lc_realtime_publish_message(`$conversationId, `$messageId);`n`n    lc_json(['success' => true, 'message_id' => `$messageId]);"
        )
    }
    return $t
}

# api/visitor-send.php
Edit-File 'api/visitor-send.php' {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`r`n", "require_once __DIR__ . '/../includes/functions.php';`r`nrequire_once __DIR__ . '/../includes/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n")
    }
    if ($t -notmatch 'lc_realtime_publish_message') {
        $t = $t.Replace(
            "    }`r`n`r`n    lc_json(['success' => true, 'message_id' => `$messageId]);",
            "    }`r`n`r`n    lc_realtime_publish_message(`$conversationId, `$messageId);`r`n`r`n    lc_json(['success' => true, 'message_id' => `$messageId]);"
        )
        $t = $t.Replace(
            "    }`n`n    lc_json(['success' => true, 'message_id' => `$messageId]);",
            "    }`n`n    lc_realtime_publish_message(`$conversationId, `$messageId);`n`n    lc_json(['success' => true, 'message_id' => `$messageId]);"
        )
    }
    return $t
}

# api/agent-close.php
Edit-File 'api/agent-close.php' {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t.Replace("require_once __DIR__ . '/../includes/auth.php';`r`n", "require_once __DIR__ . '/../includes/auth.php';`r`nrequire_once __DIR__ . '/../includes/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/../includes/auth.php';`n", "require_once __DIR__ . '/../includes/auth.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n")
    }
    if ($t -notmatch 'lc_realtime_publish_conversation_event') {
        $t = $t.Replace(
            "    lc_add_system_message(`$conversationId, 'Chat closed by agent.');`r`n    lc_json(['success' => true]);",
            "    lc_add_system_message(`$conversationId, 'Chat closed by agent.');`r`n    lc_realtime_publish_conversation_event(`$conversationId, 'conversation.updated', ['status' => 'closed']);`r`n    lc_json(['success' => true]);"
        )
        $t = $t.Replace(
            "    lc_add_system_message(`$conversationId, 'Chat closed by agent.');`n    lc_json(['success' => true]);",
            "    lc_add_system_message(`$conversationId, 'Chat closed by agent.');`n    lc_realtime_publish_conversation_event(`$conversationId, 'conversation.updated', ['status' => 'closed']);`n    lc_json(['success' => true]);"
        )
    }
    return $t
}

# api/typing.php
Edit-File 'api/typing.php' {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`r`n", "require_once __DIR__ . '/../includes/functions.php';`r`nrequire_once __DIR__ . '/../includes/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n")
    }
    if ($t -notmatch 'lc_realtime_publish_typing') {
        $t = $t.Replace(
            "        lc_set_typing(`$conversationId, 'visitor', `$isTyping);`r`n        lc_json(['success' => true, 'typing' => `$isTyping]);",
            "        lc_set_typing(`$conversationId, 'visitor', `$isTyping);`r`n        lc_realtime_publish_typing(`$conversationId, 'visitor', `$isTyping);`r`n        lc_json(['success' => true, 'typing' => `$isTyping]);"
        )
        $t = $t.Replace(
            "    lc_set_typing(`$conversationId, 'agent', `$isTyping);`r`n    lc_json(['success' => true, 'typing' => `$isTyping]);",
            "    lc_set_typing(`$conversationId, 'agent', `$isTyping);`r`n    lc_realtime_publish_typing(`$conversationId, 'agent', `$isTyping, [`r`n        'name' => `$agent['name'] ?? '',`r`n    ]);`r`n    lc_json(['success' => true, 'typing' => `$isTyping]);"
        )
    }
    return $t
}

# api/widget-init.php
Edit-File 'api/widget-init.php' {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`r`n", "require_once __DIR__ . '/../includes/functions.php';`r`nrequire_once __DIR__ . '/../includes/realtime.php';`r`n")
        $t = $t.Replace("require_once __DIR__ . '/../includes/functions.php';`n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n")
    }
    if ($t -notmatch 'lc_realtime_client_config') {
        $t = $t.Replace("'agents_online' => `$onlineCount,`r`n        ]);", "'agents_online' => `$onlineCount,`r`n            'realtime' => lc_realtime_client_config(`$brandId),`r`n        ]);")
        $t = $t.Replace("'agents_online' => `$onlineCount,`n        ]);", "'agents_online' => `$onlineCount,`n            'realtime' => lc_realtime_client_config(`$brandId),`n        ]);")
        $t = $t.Replace("'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`r`n    ]);", "'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`r`n        'realtime' => lc_realtime_client_config(`$brandId),`r`n    ]);")
        $t = $t.Replace("'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`n    ]);", "'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`n        'realtime' => lc_realtime_client_config(`$brandId),`n    ]);")
    }
    return $t
}

# admin footer
Edit-File 'admin/includes/footer.php' {
    param($t)
    if ($t -notmatch 'LC_REALTIME') {
        $t = $t.Replace(
            "window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;`r`n</script>",
            "window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;`r`nwindow.LC_REALTIME = <?= json_encode(function_exists('lc_realtime_client_config') ? lc_realtime_client_config() : ['mode' => 'poll', 'wsUrl' => '', 'backupPollMs' => 15000]) ?>;`r`n</script>"
        )
        $t = $t.Replace(
            "<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=2`"></script>",
            "<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-realtime.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=2`"></script>"
        )
    }
    return $t
}

Write-Host 'Minimal PHP/footer patches done. JS files need separate step.'

# admin-chat.js
Edit-File 'assets/js/admin-chat.js' {
    param($t)
    if ($t -notmatch 'realtimeClient') {
        $t = $t.Replace('    var listTimer = null;`r`n    var cannedLoaded', '    var listTimer = null;`r`n    var realtimeClient = null;`r`n    var cannedLoaded')
        $t = $t.Replace('    var listTimer = null;`n    var cannedLoaded', '    var listTimer = null;`n    var realtimeClient = null;`n    var cannedLoaded')
    }
    if ($t -notmatch 'initAgentRealtime') {
        $block = @'

    function resetPollTimers(wsActive) {
        var cfg = window.LC_REALTIME || {};
        var interval = wsActive ? (parseInt(cfg.backupPollMs, 10) || Math.max(pollMs * 10, 15000)) : pollMs;
        if (pollTimer) clearInterval(pollTimer);
        if (listTimer) clearInterval(listTimer);
        pollTimer = setInterval(pollMessages, interval);
        listTimer = setInterval(loadConversations, interval);
    }

    function initAgentRealtime() {
        var cfg = window.LC_REALTIME || {};
        if (cfg.mode !== 'ws' || !cfg.wsUrl || !window.LCRealtime) return;

        fetch(base + '/api/realtime-token.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.token) return;
                if (realtimeClient && realtimeClient.disconnect) {
                    realtimeClient.disconnect();
                }
                realtimeClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: cfg.wsUrl,
                    token: data.token,
                    fallbackPoll: function () {
                        pollMessages();
                        loadConversations();
                    }
                });
                realtimeClient.on('connected', function () {
                    resetPollTimers(true);
                });
                realtimeClient.on('disconnected', function () {
                    resetPollTimers(false);
                });
                realtimeClient.on('message.new', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (selectedId && cid === selectedId) {
                        pollMessages();
                    }
                    loadConversations();
                });
                realtimeClient.on('inbox.changed', function () {
                    loadConversations();
                });
                realtimeClient.on('typing', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (!selectedId || cid !== selectedId) return;
                    if ((payload.role || '') === 'visitor') {
                        setPeerTypingVisible(!!payload.typing, '', payload.preview || '');
                    }
                });
                realtimeClient.on('conversation.updated', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (selectedId && cid === selectedId) {
                        pollMessages();
                    }
                    loadConversations();
                });
                realtimeClient.connect();
            })
            .catch(function () { /* poll fallback stays active */ });
    }

    loadConversations();
    resetPollTimers(false);
    initAgentRealtime();
'@
        $t = $t.Replace("    loadConversations();`r`n    listTimer = setInterval(loadConversations, pollMs);`r`n    pollTimer = setInterval(pollMessages, pollMs);", $block.TrimStart())
        $t = $t.Replace("    loadConversations();`n    listTimer = setInterval(loadConversations, pollMs);`n    pollTimer = setInterval(pollMessages, pollMs);", $block.TrimStart())
    }
    return $t
}

# widget/embed.js
Edit-File 'widget/embed.js' {
    param($t)
    if ($t -notmatch 'realtimeConfig') {
        $t = $t.Replace('    var pollTimer = null;`r`n    var pollMs = 1500;', '    var pollTimer = null;`r`n    var realtimeClient = null;`r`n    var realtimeConfig = { mode: ''poll'', wsUrl: '''', backupPollMs: 15000 };`r`n    var pollMs = 1500;')
        $t = $t.Replace('    var pollTimer = null;`n    var pollMs = 1500;', '    var pollTimer = null;`n    var realtimeClient = null;`n    var realtimeConfig = { mode: ''poll'', wsUrl: '''', backupPollMs: 15000 };`n    var pollMs = 1500;')
    }
    if ($t -notmatch 'initVisitorRealtime') {
        $rtBlock = @'

    function resetVisitorPollTimers(wsActive) {
        var interval = wsActive ? (parseInt(realtimeConfig.backupPollMs, 10) || Math.max(pollMs * 10, 15000)) : pollMs;
        if (pollTimer) clearInterval(pollTimer);
        if (conversationId) {
            pollTimer = setInterval(pollMessages, interval);
        }
    }

    function initVisitorRealtime() {
        if (!conversationId || realtimeConfig.mode !== 'ws' || !realtimeConfig.wsUrl) return;
        if (!window.LCRealtime) return;

        fetch(siteUrl + '/api/realtime-token.php?visitor_uid=' + encodeURIComponent(visitorUid) +
            '&conversation_id=' + encodeURIComponent(conversationId), { credentials: 'omit' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.success || !data.token) return;
                if (realtimeClient && realtimeClient.disconnect) realtimeClient.disconnect();
                realtimeClient = window.LCRealtime.create({
                    mode: 'ws',
                    wsUrl: realtimeConfig.wsUrl,
                    token: data.token,
                    fallbackPoll: function () { pollMessages(false); }
                });
                realtimeClient.on('connected', function () { resetVisitorPollTimers(true); });
                realtimeClient.on('disconnected', function () { resetVisitorPollTimers(false); });
                realtimeClient.on('message.new', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (cid === conversationId) pollMessages(false);
                });
                realtimeClient.on('typing', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (cid !== conversationId) return;
                    if ((payload.role || '') === 'agent') {
                        setPeerTypingVisible(!!payload.typing, payload.name || '', payload.preview || '');
                    }
                });
                realtimeClient.on('conversation.updated', function (payload) {
                    var cid = parseInt(payload && payload.conversation_id, 10) || 0;
                    if (cid === conversationId) pollMessages(false);
                });
                realtimeClient.connect();
            })
            .catch(function () { /* poll stays */ });
    }

    function loadRealtimeScript(done) {
        if (window.LCRealtime) {
            if (done) done();
            return;
        }
        var existing = document.querySelector('script[data-lc-realtime]');
        if (existing) {
            existing.addEventListener('load', function () { if (done) done(); });
            return;
        }
        var s = document.createElement('script');
        s.src = siteUrl + '/assets/js/lc-realtime.js?v=1';
        s.async = true;
        s.setAttribute('data-lc-realtime', '1');
        s.onload = function () { if (done) done(); };
        document.head.appendChild(s);
    }

'@
        $t = $t.Replace('    function applyWidgetSettings(data) {', ($rtBlock + '    function applyWidgetSettings(data) {'))
        $t = $t.Replace('        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`r`n        var name = settings.site_name', "        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`r`n        if (data.realtime) {`r`n            realtimeConfig = data.realtime;`r`n        }`r`n        loadRealtimeScript(function () {`r`n            if (conversationId) initVisitorRealtime();`r`n        });`r`n        var name = settings.site_name")
        $t = $t.Replace('        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`n        var name = settings.site_name', "        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`n        if (data.realtime) {`n            realtimeConfig = data.realtime;`n        }`n        loadRealtimeScript(function () {`n            if (conversationId) initVisitorRealtime();`n        });`n        var name = settings.site_name")
        $t = $t.Replace("        pollMessages(true);`r`n        if (pollTimer) clearInterval(pollTimer);`r`n        pollTimer = setInterval(pollMessages, pollMs);", "        pollMessages(true);`r`n        resetVisitorPollTimers(false);`r`n        loadRealtimeScript(function () { initVisitorRealtime(); });")
        $t = $t.Replace("        pollMessages(true);`n        if (pollTimer) clearInterval(pollTimer);`n        pollTimer = setInterval(pollMessages, pollMs);", "        pollMessages(true);`n        resetVisitorPollTimers(false);`n        loadRealtimeScript(function () { initVisitorRealtime(); });")
    }
    return $t
}

Write-Host 'All websocket-b minimal patches applied.'
