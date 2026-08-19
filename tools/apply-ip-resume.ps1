$ErrorActionPreference = 'Stop'
$root = 'C:\xampp\htdocs\livechat'

function Patch-File([string]$rel, [string]$old, [string]$new) {
    $path = Join-Path $root $rel
    $text = [IO.File]::ReadAllText($path)
    if ($text.IndexOf($old) -lt 0) {
        throw "Pattern not found in $rel"
    }
    $text = $text.Replace($old, $new)
    [IO.File]::WriteAllText($path, $text)
    Write-Output "patched $rel"
}

Patch-File 'includes\functions.php' "require_once __DIR__ . '/typing.php';`r`n" "require_once __DIR__ . '/typing.php';`r`nrequire_once __DIR__ . '/ip-resume.php';`r`n"

$oldEarly = @"
    if (!$visitor && `$settingsOnly) {
        lc_json([
            'success' => true,
            'settings' => `$brandSettings,
            'has_active_chat' => false,
            'conversation_id' => null,
            'visitor_identity_complete' => false,
            'agents_online' => `$onlineCount,
        ]);
    }
"@
$newEarly = @"
    if (!$visitor && `$settingsOnly) {
        `$ipThread = lc_find_conversation_by_ip(`$ip, `$brandId);
        lc_json([
            'success' => true,
            'settings' => `$brandSettings,
            'has_active_chat' => `$ipThread !== null,
            'conversation_id' => `$ipThread['id'] ?? null,
            'visitor_identity_complete' => false,
            'agents_online' => `$onlineCount,
        ]);
    }
"@
Patch-File 'api\widget-init.php' $oldEarly $newEarly

$oldMid = @"
    if (`$settingsOnly) {
        // Never auto-resume old chats for visitors (same IP / same device).
        // Session continue uses explicit resume_conversation_id from the client only.
        // Ticket resume is a separate API when ticket_resume_enabled.
        lc_json([
            'success' => true,
            'settings' => `$brandSettings,
            'has_active_chat' => false,
            'conversation_id' => null,
            'visitor_identity_complete' => `$identityComplete,
            'agents_online' => `$onlineCount,
        ]);
    }

    `$conv = null;
    // Explicit session / reconnect only — not IP and not silent find_resumable
    if (`$resumeConvId > 0) {
        `$stmt = `$db->prepare("SELECT id, status FROM conversations WHERE id = ? AND visitor_id = ? AND brand_id = ? AND status IN ('waiting','active') LIMIT 1");
        `$stmt->execute([`$resumeConvId, `$visitorId, `$brandId]);
        `$conv = `$stmt->fetch();
        if (!`$conv) {
            `$reopened = lc_reopen_recent_visitor_close(`$resumeConvId, `$visitorId);
            if (`$reopened) {
                `$conv = `$reopened;
            }
        }
    }

    if (!`$conv) {
"@
$newMid = @"
    `$ipThread = lc_find_conversation_by_ip(`$ip, `$brandId);

    if (`$settingsOnly) {
        lc_json([
            'success' => true,
            'settings' => `$brandSettings,
            'has_active_chat' => `$ipThread !== null,
            'conversation_id' => `$ipThread['id'] ?? null,
            'visitor_identity_complete' => `$identityComplete,
            'agents_online' => `$onlineCount,
        ]);
    }

    `$conv = null;
    if (`$resumeConvId > 0) {
        `$stmt = `$db->prepare("SELECT id, status, agent_id, visitor_id FROM conversations WHERE id = ? AND visitor_id = ? AND brand_id = ? AND status IN ('waiting','active') LIMIT 1");
        `$stmt->execute([`$resumeConvId, `$visitorId, `$brandId]);
        `$conv = `$stmt->fetch();
        if (!`$conv) {
            `$reopened = lc_reopen_recent_visitor_close(`$resumeConvId, `$visitorId);
            if (`$reopened) {
                `$conv = `$reopened;
            }
        }
    }

    if (!`$conv && `$ipThread) {
        `$conv = lc_resume_conversation_by_ip(`$ipThread, `$visitorId);
    }

    if (!`$conv) {
"@
Patch-File 'api\widget-init.php' $oldMid $newMid

$oldInbox = @"
            CASE c.status WHEN 'waiting' THEN 0 ELSE 1 END,
            COALESCE(c.last_message_at, c.started_at) DESC
        LIMIT 50
    ";
    `$stmt = `$db->prepare(`$sql);
    `$stmt->execute(array_merge([`$agentId], `$params));
    `$conversations = `$stmt->fetchAll();
    foreach (`$conversations as &`$row) {
        `$row['reference'] = `$row['ticket_reference'] ?: lc_conversation_reference((int) `$row['id'], `$row['started_at'] ?? null);
        unset(`$row['ticket_reference']);
    }
    unset(`$row);
"@
$newInbox = @"
            CASE c.status WHEN 'waiting' THEN 0 ELSE 1 END,
            COALESCE(c.last_message_at, c.started_at) DESC
        LIMIT 200
    ";
    `$stmt = `$db->prepare(`$sql);
    `$stmt->execute(array_merge([`$agentId], `$params));
    `$conversations = `$stmt->fetchAll();
    foreach (`$conversations as &`$row) {
        `$row['reference'] = `$row['ticket_reference'] ?: lc_conversation_reference((int) `$row['id'], `$row['started_at'] ?? null);
        unset(`$row['ticket_reference']);
    }
    unset(`$row);
    `$conversations = array_slice(lc_dedupe_inbox_conversations_by_ip(`$conversations), 0, 50);
"@
Patch-File 'api\agent-conversations.php' $oldInbox $newInbox

$oldJs = @"
     * Entry rules (enterprise):
     * - No auto-resume of old chats by IP / past device
     * - Session continue: sessionStorage conversation id only
     * - Ticket resume: separate form when setting ON (visitor + agent share history)
     * - Form ON → gate identity; Form OFF → direct chat
     */
    function routeVisitorEntry(data) {
        if (!data || !data.success) return;
        applyWidgetSettings(data);

        var identityOk = !!data.visitor_identity_complete;
        // Session-only reconnect — never use server has_active_chat for silent resume
        var resumeId = getStoredConversationId() || pendingResumeConvId || null;
"@
$newJs = @"
     * Entry rules:
     * - Same IP resumes the existing thread (server conversation_id)
     * - Session continue: sessionStorage conversation id
     * - Ticket resume: separate form when setting ON
     * - Form ON → gate identity; Form OFF → direct chat
     */
    function routeVisitorEntry(data) {
        if (!data || !data.success) return;
        applyWidgetSettings(data);

        var identityOk = !!data.visitor_identity_complete;
        var resumeId = getStoredConversationId() || pendingResumeConvId || data.conversation_id || null;
"@
Patch-File 'widget\embed.js' $oldJs $newJs

$oldSet = 'Returning visitors do <strong>not</strong> see old chats automatically — only via ticket resume when enabled below.'
$newSet = 'Same-IP visitors automatically continue the previous live chat. Ticket resume below is an extra option (ticket + email).'
Patch-File 'admin\settings.php' $oldSet $newSet

Write-Output 'done'
