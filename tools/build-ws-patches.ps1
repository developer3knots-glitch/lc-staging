$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\livechat'
Set-Location $root

function Patch-Text([string]$text, [scriptblock]$edit) {
    return & $edit $text
}

function Write-Patch([string]$rel, [string]$text) {
    $out = Join-Path $root ('patches/' + ($rel -replace '/', '__'))
    $dir = Split-Path $out -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [System.IO.File]::WriteAllText($out, $text, [System.Text.UTF8Encoding]::new($false))
    Write-Host "Wrote patch $out"
}

function Read-Live([string]$rel) {
    return [System.IO.File]::ReadAllText((Join-Path $root $rel))
}

# includes/functions.php
Write-Patch 'includes/functions.php' (Patch-Text (Read-Live 'includes/functions.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/typing\.php';\r?\n", "require_once __DIR__ . '/typing.php';`nrequire_once __DIR__ . '/realtime.php';`n"
    }
    return $t
})

# includes/config.example.php
Write-Patch 'includes/config.example.php' (Patch-Text (Read-Live 'includes/config.example.php') {
    param($t)
    if ($t -notmatch 'LC_REALTIME_MODE') {
        $block = @"

// Realtime Option A: poll = HTTP (default). ws = VPS WebSocket server.
define('LC_REALTIME_MODE', 'poll');
define('LC_REALTIME_WS_URL', 'wss://realtime.yourdomain.com');
define('LC_REALTIME_PUBLISH_URL', 'http://127.0.0.1:6001/publish');
define('LC_REALTIME_SECRET', 'change-me-long-random-secret');

"@
        $t = $t -replace "define\('LC_POLL_INTERVAL', 1500\);\r?\n\r?\n", "define('LC_POLL_INTERVAL', 1500);`n$block"
    }
    return $t
})

# api/agent-send.php
Write-Patch 'api/agent-send.php' (Patch-Text (Read-Live 'api/agent-send.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/\.\./includes/auth\.php';\r?\n", "require_once __DIR__ . '/../includes/auth.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n"
    }
    if ($t -notmatch 'lc_realtime_publish_message') {
        $t = $t -replace "    lc_touch_conversation\(\`$conversationId\);\r?\n\r?\n    lc_json\(\['success' => true, 'message_id' => \(int\) lc_db\(\)->lastInsertId\(\)\]\);", @"
    lc_touch_conversation(`$conversationId);

    `$messageId = (int) lc_db()->lastInsertId();
    lc_realtime_publish_message(`$conversationId, `$messageId);

    lc_json(['success' => true, 'message_id' => `$messageId]);
"@
    }
    return $t
})

# api/visitor-send.php
Write-Patch 'api/visitor-send.php' (Patch-Text (Read-Live 'api/visitor-send.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/\.\./includes/functions\.php';\r?\n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n"
    }
    if ($t -notmatch 'lc_realtime_publish_message') {
        $t = $t -replace "    \}\r?\n\r?\n    lc_json\(\['success' => true, 'message_id' => `$messageId\]\);", @"
    }

    lc_realtime_publish_message(`$conversationId, `$messageId);

    lc_json(['success' => true, 'message_id' => `$messageId]);
"@
    }
    return $t
})

# api/agent-close.php
Write-Patch 'api/agent-close.php' (Patch-Text (Read-Live 'api/agent-close.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/\.\./includes/auth\.php';\r?\n", "require_once __DIR__ . '/../includes/auth.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n"
    }
    if ($t -notmatch 'lc_realtime_publish_conversation_event') {
        $t = $t -replace "    lc_add_system_message\(\`$conversationId, 'Chat closed by agent\.'\);\r?\n    lc_json\(\['success' => true\]\);", @"
    lc_add_system_message(`$conversationId, 'Chat closed by agent.');
    lc_realtime_publish_conversation_event(`$conversationId, 'conversation.updated', ['status' => 'closed']);
    lc_json(['success' => true]);
"@
    }
    return $t
})

# api/typing.php
Write-Patch 'api/typing.php' (Patch-Text (Read-Live 'api/typing.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/\.\./includes/functions\.php';\r?\n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n"
    }
    if ($t -notmatch 'lc_realtime_publish_typing') {
        $t = $t -replace "        lc_set_typing\(\`$conversationId, 'visitor', `$isTyping\);\r?\n        lc_json\(\['success' => true, 'typing' => `$isTyping\]\);", @"
        lc_set_typing(`$conversationId, 'visitor', `$isTyping);
        lc_realtime_publish_typing(`$conversationId, 'visitor', `$isTyping);
        lc_json(['success' => true, 'typing' => `$isTyping]);
"@
        $t = $t -replace "    lc_set_typing\(\`$conversationId, 'agent', `$isTyping\);\r?\n    lc_json\(\['success' => true, 'typing' => `$isTyping\]\);", @"
    lc_set_typing(`$conversationId, 'agent', `$isTyping);
    lc_realtime_publish_typing(`$conversationId, 'agent', `$isTyping, [
        'name' => `$agent['name'] ?? '',
    ]);
    lc_json(['success' => true, 'typing' => `$isTyping]);
"@
    }
    return $t
})

# api/widget-init.php
Write-Patch 'api/widget-init.php' (Patch-Text (Read-Live 'api/widget-init.php') {
    param($t)
    if ($t -notmatch 'realtime\.php') {
        $t = $t -replace "require_once __DIR__ \. '/\.\./includes/functions\.php';\r?\n", "require_once __DIR__ . '/../includes/functions.php';`nrequire_once __DIR__ . '/../includes/realtime.php';`n"
    }
    if ($t -notmatch 'lc_realtime_client_config') {
        $t = $t -replace "('agents_online' => `$onlineCount,\r?\n        \]\);)", "`$1".Replace("'agents_online' => `$onlineCount,", "'agents_online' => `$onlineCount,`n            'realtime' => lc_realtime_client_config(`$brandId),")
        # simpler sequential replaces
        $t = $t.Replace("'agents_online' => `$onlineCount,`r`n        ]);", "'agents_online' => `$onlineCount,`r`n            'realtime' => lc_realtime_client_config(`$brandId),`r`n        ]);")
        $t = $t.Replace("'agents_online' => `$onlineCount,`n        ]);", "'agents_online' => `$onlineCount,`n            'realtime' => lc_realtime_client_config(`$brandId),`n        ]);")
        $t = $t.Replace("'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`r`n    ]);", "'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`r`n        'realtime' => lc_realtime_client_config(`$brandId),`r`n    ]);")
        $t = $t.Replace("'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`n    ]);", "'ticket_resume_enabled' => lc_ticket_resume_enabled(`$brandAccountId > 0 ? `$brandAccountId : null),`n        'realtime' => lc_realtime_client_config(`$brandId),`n    ]);")
    }
    return $t
})

# admin footer
Write-Patch 'admin/includes/footer.php' (Patch-Text (Read-Live 'admin/includes/footer.php') {
    param($t)
    if ($t -notmatch 'LC_REALTIME') {
        $t = $t.Replace("window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;`r`n</script>", "window.LC_ATTACHMENT_LIMITS = <?= json_encode(lc_attachment_public_config()) ?>;`r`nwindow.LC_REALTIME = <?= json_encode(function_exists('lc_realtime_client_config') ? lc_realtime_client_config() : ['mode' => 'poll', 'wsUrl' => '', 'backupPollMs' => 15000]) ?>;`r`n</script>")
        $t = $t.Replace("<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=2`"></script>", "<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-notify-sound.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/lc-realtime.js?v=1`"></script>`r`n<script src=`"<?= LC_BASE_URL ?>/assets/js/admin-core.js?v=2`"></script>")
    }
    return $t
})

# admin-chat.js - use here-string block from minimal script
$adminChat = Read-Live 'assets/js/admin-chat.js'
if ($adminChat -notmatch 'realtimeClient') {
    $adminChat = $adminChat.Replace('    var listTimer = null;`r`n    var cannedLoaded', '    var listTimer = null;`r`n    var realtimeClient = null;`r`n    var cannedLoaded')
    $adminChat = $adminChat.Replace('    var listTimer = null;`n    var cannedLoaded', '    var listTimer = null;`n    var realtimeClient = null;`n    var cannedLoaded')
}
if ($adminChat -notmatch 'initAgentRealtime') {
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
    $adminChat = $adminChat.Replace("    loadConversations();`r`n    listTimer = setInterval(loadConversations, pollMs);`r`n    pollTimer = setInterval(pollMessages, pollMs);", $block.TrimStart())
    $adminChat = $adminChat.Replace("    loadConversations();`n    listTimer = setInterval(loadConversations, pollMs);`n    pollTimer = setInterval(pollMessages, pollMs);", $block.TrimStart())
}
Write-Patch 'assets/js/admin-chat.js' $adminChat

# widget embed
$embed = Read-Live 'widget/embed.js'
if ($embed -notmatch 'realtimeConfig') {
    $embed = $embed.Replace('    var pollTimer = null;`r`n    var pollMs = 1500;', '    var pollTimer = null;`r`n    var realtimeClient = null;`r`n    var realtimeConfig = { mode: ''poll'', wsUrl: '''', backupPollMs: 15000 };`r`n    var pollMs = 1500;')
    $embed = $embed.Replace('    var pollTimer = null;`n    var pollMs = 1500;', '    var pollTimer = null;`n    var realtimeClient = null;`n    var realtimeConfig = { mode: ''poll'', wsUrl: '''', backupPollMs: 15000 };`n    var pollMs = 1500;')
}
if ($embed -notmatch 'initVisitorRealtime') {
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
    $embed = $embed.Replace('    function applyWidgetSettings(data) {', ($rtBlock + '    function applyWidgetSettings(data) {'))
    $embed = $embed.Replace('        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`r`n        var name = settings.site_name', "        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`r`n        if (data.realtime) {`r`n            realtimeConfig = data.realtime;`r`n        }`r`n        loadRealtimeScript(function () {`r`n            if (conversationId) initVisitorRealtime();`r`n        });`r`n        var name = settings.site_name")
    $embed = $embed.Replace('        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`n        var name = settings.site_name', "        applyTicketFeatureFlags(settings.ticket_display_enabled, settings.ticket_resume_enabled);`n        if (data.realtime) {`n            realtimeConfig = data.realtime;`n        }`n        loadRealtimeScript(function () {`n            if (conversationId) initVisitorRealtime();`n        });`n        var name = settings.site_name")
    $embed = $embed.Replace("        pollMessages(true);`r`n        if (pollTimer) clearInterval(pollTimer);`r`n        pollTimer = setInterval(pollMessages, pollMs);", "        pollMessages(true);`r`n        resetVisitorPollTimers(false);`r`n        loadRealtimeScript(function () { initVisitorRealtime(); });")
    $embed = $embed.Replace("        pollMessages(true);`n        if (pollTimer) clearInterval(pollTimer);`n        pollTimer = setInterval(pollMessages, pollMs);", "        pollMessages(true);`n        resetVisitorPollTimers(false);`n        loadRealtimeScript(function () { initVisitorRealtime(); });")
}
Write-Patch 'widget/embed.js' $embed

# copy standalone new files into patches too
Copy-Item (Join-Path $root 'includes/realtime.php') (Join-Path $root 'patches/includes__realtime.php') -Force
Copy-Item (Join-Path $root 'assets/js/lc-realtime.js') (Join-Path $root 'patches/assets__js__lc-realtime.js') -Force
Copy-Item (Join-Path $root 'api/realtime-token.php') (Join-Path $root 'patches/api__realtime-token.php') -Force

Write-Host 'Patch files rebuilt from current live code.'
