<?php

/**
 * Account-level pre-chat form: heading, button, built-in fields, and custom questions.
 * Empty stored config keeps the original name + email + message form.
 */

function lc_prechat_clean_text(string $value, int $max): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function lc_prechat_builtin_defs(): array
{
    return [
        'name' => [
            'id' => 'name',
            'type' => 'name',
            'enabled' => true,
            'required' => true,
            'label' => 'Full name',
            'placeholder' => 'John Smith',
            'options' => [],
        ],
        'email' => [
            'id' => 'email',
            'type' => 'email',
            'enabled' => true,
            'required' => true,
            'label' => 'Work email',
            'placeholder' => 'you@company.com',
            'options' => [],
        ],
        'phone' => [
            'id' => 'phone',
            'type' => 'phone',
            'enabled' => false,
            'required' => false,
            'label' => 'Phone',
            'placeholder' => '+1 555 0100',
            'options' => [],
        ],
        'message' => [
            'id' => 'message',
            'type' => 'message',
            'enabled' => true,
            'required' => true,
            'label' => 'How can we help?',
            'placeholder' => 'Describe your question or request...',
            'options' => [],
        ],
    ];
}

function lc_prechat_form_defaults(): array
{
    return [
        'title' => 'Start a conversation',
        'subtitle' => 'Tell us a little about yourself - our team will take it from here.',
        'button' => 'Start conversation',
        'fields' => array_values(lc_prechat_builtin_defs()),
    ];
}

function lc_prechat_form_config(?int $accountId = null): array
{
    if ($accountId === null) {
        $accountId = function_exists('lc_current_account_id') ? lc_current_account_id() : 0;
    }
    $raw = '';
    if ($accountId > 0 && function_exists('lc_account_setting')) {
        $raw = (string) lc_account_setting($accountId, 'prechat_form_config', '');
    }
    if ($raw === '' && function_exists('lc_setting')) {
        $raw = (string) lc_setting('prechat_form_config', '');
    }

    return lc_normalize_prechat_form_config($raw);
}

function lc_normalize_prechat_form_config($raw): array
{
    $defaults = lc_prechat_form_defaults();
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return $defaults;
        }
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($raw)) {
        return $defaults;
    }

    $title = lc_prechat_clean_text((string) ($raw['title'] ?? ''), 80);
    $subtitle = lc_prechat_clean_text((string) ($raw['subtitle'] ?? ''), 180);
    $button = lc_prechat_clean_text((string) ($raw['button'] ?? ''), 40);
    $fieldsIn = $raw['fields'] ?? null;
    if (!is_array($fieldsIn)) {
        return $defaults;
    }

    $builtins = lc_prechat_builtin_defs();
    $customTypes = ['text' => true, 'textarea' => true, 'select' => true, 'checkbox' => true];
    $seen = [];
    $fields = [];
    $customCount = 0;

    foreach ($fieldsIn as $field) {
        if (!is_array($field) || count($fields) >= 12) {
            continue;
        }
        $id = strtolower(trim((string) ($field['id'] ?? '')));
        $type = strtolower(trim((string) ($field['type'] ?? '')));
        $isBuiltin = isset($builtins[$id]);
        if ($isBuiltin) {
            $type = $builtins[$id]['type'];
        } elseif (!isset($customTypes[$type]) || !preg_match('/^c[a-z0-9]{4,16}$/', $id)) {
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        if (!$isBuiltin) {
            $customCount++;
            if ($customCount > 8) {
                continue;
            }
        }

        $fallback = $isBuiltin ? $builtins[$id] : [
            'label' => 'Question',
            'placeholder' => '',
        ];
        $label = lc_prechat_clean_text((string) ($field['label'] ?? ''), 80);
        if ($label === '') {
            $label = (string) $fallback['label'];
        }
        $placeholder = $type === 'checkbox'
            ? ''
            : lc_prechat_clean_text((string) ($field['placeholder'] ?? ''), 120);
        if ($placeholder === '' && $type !== 'checkbox' && $type !== 'select') {
            $placeholder = (string) ($fallback['placeholder'] ?? '');
        }

        $options = [];
        if ($type === 'select') {
            $optRaw = $field['options'] ?? [];
            if (is_string($optRaw)) {
                $optRaw = preg_split('/\r\n|\r|\n/', $optRaw) ?: [];
            }
            if (is_array($optRaw)) {
                foreach ($optRaw as $opt) {
                    $opt = lc_prechat_clean_text((string) $opt, 60);
                    if ($opt === '' || in_array($opt, $options, true)) {
                        continue;
                    }
                    $options[] = $opt;
                    if (count($options) >= 20) {
                        break;
                    }
                }
            }
        }

        $enabled = !empty($field['enabled']) && ($type !== 'select' || $options !== []);
        $required = $enabled && !empty($field['required']);
        $seen[$id] = true;
        $fields[] = [
            'id' => $id,
            'type' => $type,
            'enabled' => $enabled,
            'required' => $required,
            'label' => $label,
            'placeholder' => $placeholder,
            'options' => $options,
        ];
    }

    foreach ($builtins as $id => $def) {
        if (!isset($seen[$id]) && count($fields) < 12) {
            $fields[] = $def;
        }
    }
    if ($fields === []) {
        return $defaults;
    }

    return [
        'title' => $title !== '' ? $title : $defaults['title'],
        'subtitle' => $subtitle,
        'button' => $button !== '' ? $button : $defaults['button'],
        'fields' => $fields,
    ];
}

function lc_prechat_field_value(array $input, array $field): string
{
    $id = (string) ($field['id'] ?? '');
    $bag = $input['prechat_fields'] ?? null;
    if (is_array($bag) && array_key_exists($id, $bag)) {
        return trim((string) $bag[$id]);
    }
    if ($id === 'name') {
        return trim((string) ($input['name'] ?? ''));
    }
    if ($id === 'email') {
        return trim((string) ($input['email'] ?? ''));
    }
    if ($id === 'phone') {
        return trim((string) ($input['phone'] ?? ''));
    }
    if ($id === 'message') {
        return trim((string) ($input['initial_message'] ?? $input['message'] ?? ''));
    }

    return '';
}

/**
 * @return array{ok:bool,error:string,name:string,email:string,phone:string,message:string,answers:array<int,array{label:string,value:string}>}
 */
function lc_prechat_validate_submission(array $config, array $input): array
{
    $fail = static function (string $error): array {
        return [
            'ok' => false,
            'error' => $error,
            'name' => '',
            'email' => '',
            'phone' => '',
            'message' => '',
            'answers' => [],
        ];
    };

    $name = '';
    $email = '';
    $phone = '';
    $message = '';
    $answers = [];
    $fields = is_array($config['fields'] ?? null) ? $config['fields'] : [];

    foreach ($fields as $field) {
        if (!is_array($field) || empty($field['enabled'])) {
            continue;
        }
        $type = (string) ($field['type'] ?? '');
        $label = (string) ($field['label'] ?? 'This field');
        $required = !empty($field['required']);
        $value = lc_prechat_field_value($input, $field);

        if ($type === 'name') {
            $value = lc_prechat_clean_text($value, 80);
            if ($required && $value === '') {
                return $fail($label . ' is required.');
            }
            if ($value !== '') {
                $name = $value;
            }
            continue;
        }
        if ($type === 'email') {
            $value = strtolower(lc_prechat_clean_text($value, 120));
            if ($value === '') {
                if ($required) {
                    return $fail($label . ' is required.');
                }
                continue;
            }
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $fail('Enter a valid email.');
            }
            $email = $value;
            continue;
        }
        if ($type === 'phone') {
            if ($value === '') {
                if ($required) {
                    return $fail($label . ' is required.');
                }
                continue;
            }
            $normalized = function_exists('lc_normalize_visitor_phone')
                ? lc_normalize_visitor_phone($value)
                : '';
            if ($normalized === '') {
                return $fail('Enter a valid phone number.');
            }
            $phone = $normalized;
            continue;
        }
        if ($type === 'message') {
            $value = trim(strip_tags($value));
            if (function_exists('mb_substr')) {
                $value = mb_substr($value, 0, 2000);
            } else {
                $value = substr($value, 0, 2000);
            }
            if ($required && $value === '') {
                return $fail($label . ' is required.');
            }
            if ($value !== '') {
                $message = $value;
            }
            continue;
        }
        if ($type === 'checkbox') {
            $on = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            if ($required && !$on) {
                return $fail($label . ' is required.');
            }
            if ($on) {
                $answers[] = ['label' => $label, 'value' => 'Yes'];
            }
            continue;
        }
        if ($type === 'select') {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            if ($value === '') {
                if ($required) {
                    return $fail($label . ' is required.');
                }
                continue;
            }
            if (!in_array($value, $options, true)) {
                return $fail('Choose a valid option for ' . $label . '.');
            }
            $answers[] = ['label' => $label, 'value' => $value];
            continue;
        }
        if ($type === 'text' || $type === 'textarea') {
            $limit = $type === 'textarea' ? 2000 : 500;
            $value = trim(strip_tags($value));
            if (function_exists('mb_substr')) {
                $value = mb_substr($value, 0, $limit);
            } else {
                $value = substr($value, 0, $limit);
            }
            if ($required && $value === '') {
                return $fail($label . ' is required.');
            }
            if ($value !== '') {
                $answers[] = ['label' => $label, 'value' => $value];
            }
        }
    }

    return [
        'ok' => true,
        'error' => '',
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'message' => $message,
        'answers' => $answers,
    ];
}

function lc_prechat_identity_ready(array $config, string $name, string $email, string $phone = ''): bool
{
    $name = trim($name);
    $email = trim($email);
    $phone = trim($phone);
    $needs = false;
    foreach (($config['fields'] ?? []) as $field) {
        if (!is_array($field) || empty($field['enabled']) || empty($field['required'])) {
            continue;
        }
        $type = (string) ($field['type'] ?? '');
        if ($type === 'name') {
            $needs = true;
            if ($name === '') {
                return false;
            }
        } elseif ($type === 'email') {
            $needs = true;
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
        } elseif ($type === 'phone') {
            $needs = true;
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (strlen($digits) < 7) {
                return false;
            }
        }
    }

    return $needs;
}

function lc_prechat_answers_note(array $answers): string
{
    $parts = [];
    foreach ($answers as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = lc_prechat_clean_text((string) ($row['label'] ?? ''), 80);
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($row['value'] ?? ''))) ?? '');
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 500);
        } else {
            $value = substr($value, 0, 500);
        }
        if ($label === '' || $value === '') {
            continue;
        }
        $parts[] = $label . ': ' . $value;
    }
    if ($parts === []) {
        return '';
    }
    $note = 'Pre-chat details — ' . implode(' · ', $parts);
    if (function_exists('mb_substr')) {
        return mb_substr($note, 0, 4000);
    }

    return substr($note, 0, 4000);
}
