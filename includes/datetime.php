<?php

/**
 * All database datetimes are stored and read as UTC.
 * APIs expose ISO-8601 (Z). Browsers format in the user's local timezone.
 */

function lc_to_iso8601($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    try {
        if ($value instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        } else {
            $str = trim((string) $value);
            if ($str === '') {
                return null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $str)) {
                $dt = new DateTimeImmutable($str);
                $dt = $dt->setTimezone(new DateTimeZone('UTC'));
            } else {
                $dt = new DateTimeImmutable($str, new DateTimeZone('UTC'));
            }
        }

        return $dt->format('Y-m-d\TH:i:s') . '.000Z';
    } catch (Throwable $e) {
        return null;
    }
}

function lc_format_api_datetimes(array $row, array $fields): array
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
            continue;
        }
        $iso = lc_to_iso8601($row[$field]);
        if ($iso !== null) {
            $row[$field] = $iso;
        }
    }

    return $row;
}

/**
 * @param array<int, array<string, mixed>> $messages
 * @return array<int, array<string, mixed>>
 */
function lc_format_api_messages(array $messages): array
{
    foreach ($messages as &$message) {
        if (!empty($message['created_at'])) {
            $iso = lc_to_iso8601($message['created_at']);
            if ($iso !== null) {
                $message['created_at'] = $iso;
            }
        }
    }
    unset($message);

    return $messages;
}

function lc_format_api_conversation(?array $conversation): ?array
{
    if (!$conversation) {
        return null;
    }

    $conversation = lc_format_api_datetimes($conversation, [
        'started_at',
        'accepted_at',
        'closed_at',
        'last_message_at',
        'visitor_last_seen',
        'visitor_visit_started_at',
        'visitor_left_site_at',
        'opened_at',
    ]);

    if (!empty($conversation['id'])) {
        $conversation['reference'] = lc_conversation_reference(
            (int) $conversation['id'],
            $conversation['started_at'] ?? null
        );
    }

    $displayIp = '';
    if (function_exists('lc_valid_public_ipv4')) {
        $displayIp = lc_valid_public_ipv4((string) ($conversation['visitor_ipv4'] ?? ''))
            ?: lc_valid_public_ipv4((string) ($conversation['visitor_ip'] ?? ''))
            ?: (function_exists('lc_ipv4_from_any') ? lc_ipv4_from_any((string) ($conversation['visitor_ip'] ?? '')) : null)
            ?: '';
    }
    if ($displayIp !== '') {
        $conversation['visitor_ipv4'] = $displayIp;
        $conversation['visitor_ip'] = $displayIp;
    }

    $ua = (string) ($conversation['visitor_user_agent'] ?? $conversation['user_agent'] ?? '');
    if (function_exists('lc_parse_user_agent')) {
        $conversation['visitor_device'] = lc_parse_user_agent($ua);
    }

    return $conversation;
}

function lc_time_ago(string $datetime): string
{
    $iso = lc_to_iso8601($datetime);
    if ($iso === null) {
        return '';
    }

    try {
        $dt = new DateTimeImmutable($iso);
        $diff = time() - $dt->getTimestamp();
    } catch (Throwable $e) {
        return '';
    }

    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        $min = (int) floor($diff / 60);
        return $min . ' min ago';
    }
    if ($diff < 86400) {
        $hr = (int) floor($diff / 3600);
        return $hr . ' hr ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ($days === 1 ? ' day ago' : ' days ago');
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('d M Y, g:i A');
}

function lc_time_span(?string $datetime, string $mode = 'relative'): string
{
    $iso = lc_to_iso8601($datetime);
    if ($iso === null) {
        return ' - ';
    }

    $label = $mode === 'absolute'
        ? lc_format_datetime_tz($datetime, lc_account_timezone(), 'd M Y, g:i A')
        : lc_time_ago($datetime);

    return '<span class="lc-time" data-timestamp="' . htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') . '"'
        . ($mode !== 'relative' ? ' data-time-mode="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '"' : '')
        . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}


/** @return array<string, string> */
function lc_timezone_options(): array
{
    return [
        'UTC' => 'UTC (Coordinated Universal Time)',
        'Asia/Karachi' => 'Pakistan (Karachi)',
        'Asia/Dubai' => 'UAE (Dubai)',
        'Asia/Kolkata' => 'India (Kolkata)',
        'Asia/Singapore' => 'Singapore',
        'Europe/London' => 'UK (London)',
        'Europe/Paris' => 'Central Europe (Paris)',
        'America/New_York' => 'US Eastern (New York)',
        'America/Chicago' => 'US Central (Chicago)',
        'America/Denver' => 'US Mountain (Denver)',
        'America/Los_Angeles' => 'US Pacific (Los Angeles)',
        'Australia/Sydney' => 'Australia (Sydney)',
    ];
}

function lc_validate_timezone(string $timezone): string
{
    $timezone = trim($timezone);
    if ($timezone === '') {
        return 'UTC';
    }
    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'UTC';
    }
}

function lc_account_timezone(?int $accountId = null): string
{
    if (($accountId === null || $accountId < 1) && function_exists('lc_current_account_id')) {
        $accountId = lc_current_account_id();
    }
    $tz = 'UTC';
    if ($accountId > 0 && function_exists('lc_account_setting')) {
        $tz = (string) lc_account_setting($accountId, 'admin_timezone', 'UTC');
    } elseif (function_exists('lc_get_setting')) {
        $tz = (string) lc_get_setting('admin_timezone', 'UTC');
    }
    return lc_validate_timezone($tz);
}

/**
 * @param array<string, mixed> $conv
 */
function lc_timezone_for_conversation(array $conv, ?array $agent = null): string
{
    if ($agent !== null && function_exists('lc_agent_account_id')) {
        $aid = lc_agent_account_id($agent);
        if ($aid > 0) {
            return lc_account_timezone($aid);
        }
    }
    $brandId = (int) ($conv['brand_id'] ?? 0);
    if ($brandId > 0) {
        static $brandAccounts = [];
        if (!array_key_exists($brandId, $brandAccounts)) {
            try {
                $stmt = lc_db()->prepare('SELECT account_id FROM brands WHERE id = ? LIMIT 1');
                $stmt->execute([$brandId]);
                $brandAccounts[$brandId] = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $brandAccounts[$brandId] = 0;
            }
        }
        if ($brandAccounts[$brandId] > 0) {
            return lc_account_timezone($brandAccounts[$brandId]);
        }
    }
    return lc_account_timezone(null);
}

function lc_format_datetime_tz($value, ?string $timezone = null, string $pattern = 'Y-m-d H:i'): string
{
    $iso = lc_to_iso8601($value);
    if ($iso === null) {
        return '';
    }
    $timezone = lc_validate_timezone($timezone ?? lc_account_timezone());
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone($timezone));
        $label = $dt->format('T');
        if ($label === '' || $label === 'GMT' || $label === 'UTC') {
            $label = $timezone;
        }
        return $dt->format($pattern) . ' ' . $label;
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function lc_timezone_short_label(string $timezone): string
{
    $timezone = lc_validate_timezone($timezone);
    try {
        $dt = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $label = $dt->format('T');
        if ($label === '' || $label === 'GMT') {
            return $timezone === 'UTC' ? 'UTC' : $timezone;
        }
        return $label;
    } catch (Throwable $e) {
        return $timezone;
    }
}

function lc_format_transcript_timestamp($value, ?string $timezone = null, bool $full = false): string
{
    $iso = lc_to_iso8601($value);
    if ($iso === null) {
        return '';
    }
    $timezone = lc_validate_timezone($timezone ?? lc_account_timezone());
    try {
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone(new DateTimeZone($timezone));
        if ($full) {
            return $dt->format('D, d M Y H:i:s') . ' ' . lc_timezone_short_label($timezone);
        }
        return $dt->format('H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
}