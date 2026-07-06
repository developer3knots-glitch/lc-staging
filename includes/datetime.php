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

    return lc_format_api_datetimes($conversation, [
        'started_at',
        'accepted_at',
        'closed_at',
        'last_message_at',
        'opened_at',
    ]);
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
        return '—';
    }

    $label = $mode === 'absolute'
        ? (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'))->format('d M Y, g:i A')
        : lc_time_ago($datetime);

    return '<span class="lc-time" data-timestamp="' . htmlspecialchars($iso, ENT_QUOTES, 'UTF-8') . '"'
        . ($mode !== 'relative' ? ' data-time-mode="' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') . '"' : '')
        . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}
