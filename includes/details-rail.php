<?php
/**
 * Customer details sidebar. One account-level order, used on every admin rail.
 */

function lc_details_rail_sections(): array
{
    return [
        'pages' => ['Visited pages', 'ti-route', 'Pages they opened'],
        'profile' => ['Customer', 'ti-user', 'Name, location, and email chat'],
        'contact' => ['Contact', 'ti-address-book', 'Name, email, and phone'],
        'map' => ['Map', 'ti-map', 'Location map'],
        'info' => ['Additional info', 'ti-list-details', 'Referrer, ticket, device, IP'],
        'notes' => ['Internal notes', 'ti-notes', 'Notes only agents can see'],
    ];
}

/**
 * @param list<string> $posted
 * @return list<string>
 */
function lc_details_rail_normalize(array $posted): array
{
    $known = array_keys(lc_details_rail_sections());
    $out = [];
    foreach ($posted as $key) {
        $key = (string) $key;
        if (in_array($key, $known, true) && !in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    foreach ($known as $key) {
        if (!in_array($key, $out, true)) {
            $out[] = $key;
        }
    }
    return $out;
}

function lc_details_rail_order(int $accountId): array
{
    $raw = function_exists('lc_account_setting')
        ? lc_account_setting($accountId, 'details_rail_order', '')
        : '';
    $saved = json_decode((string) $raw, true);
    if (!is_array($saved)) {
        $saved = [];
    }
    return lc_details_rail_normalize($saved);
}

function lc_details_rail_order_style(int $accountId): string
{
    $css = '.lc-rail-order{display:flex;flex-direction:column}'
        . '.lc-rail-order>[data-rail-section],.lc-rail-order>.lc-hx-archive-details-title,.lc-rail-order>.lc-hx-archive-note,.lc-rail-order>.lc-page-journey{flex:0 0 auto}'
        . '.lc-rail-order>.lc-hx-archive-details-title{order:0}'
        . '.lc-rail-order>.lc-hx-archive-note,.lc-rail-order>.lc-page-journey{order:90}';
    foreach (lc_details_rail_order($accountId) as $i => $key) {
        $css .= '.lc-rail-order>[data-rail-section="' . $key . '"]{order:' . ($i + 1) . '}';
    }
    $css .= lc_details_rail_hide_style($accountId);
    return $css;
}

/**
 * Fields an agent can show or hide inside each sidebar block.
 *
 * @return array<string, array<string, string>>
 */
function lc_details_rail_field_groups(): array
{
    return [
        'pages' => [
            'title' => 'Page name',
            'url' => 'URL',
            'time' => 'Time',
        ],
        'profile' => [
            'location' => 'Location',
            'local_time' => 'Local time and IP',
            'email_chat' => 'Email chat button',
        ],
        'contact' => [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
        ],
        'info' => [
            'status' => 'Status',
            'returning' => 'Returning visitor',
            'chats' => 'Chats',
            'last_seen' => 'Last seen',
            'came_from' => 'Came from',
            'gclid' => 'GCLID',
            'chat_duration' => 'Chat duration',
            'time_on_site' => 'Time on site',
            'current_page' => 'Current page',
            'chat_page' => 'Chat page',
            'clicks' => 'Clicks',
            'groups' => 'Groups',
            'ticket' => 'Ticket',
            'agent' => 'Agent',
            'device' => 'Device',
            'ip' => 'IP address',
            'date' => 'Date',
        ],
    ];
}

/**
 * @return array<string, array<string, bool>>
 */
function lc_details_rail_visibility(int $accountId): array
{
    $groups = lc_details_rail_field_groups();
    $raw = function_exists('lc_account_setting')
        ? lc_account_setting($accountId, 'details_rail_fields', '')
        : '';
    $saved = json_decode((string) $raw, true);
    if (!is_array($saved)) {
        $saved = [];
    }
    $out = [];
    foreach ($groups as $section => $fields) {
        $sectionSaved = (isset($saved[$section]) && is_array($saved[$section])) ? $saved[$section] : [];
        $out[$section] = [];
        foreach ($fields as $key => $label) {
            if (!array_key_exists($key, $sectionSaved)) {
                $out[$section][$key] = true;
                continue;
            }
            $val = $sectionSaved[$key];
            $out[$section][$key] = $val === true || $val === 1 || $val === '1';
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $posted
 * @return array<string, array<string, bool>>
 */
function lc_details_rail_visibility_from_post(array $posted, bool $reset): array
{
    $out = [];
    foreach (lc_details_rail_field_groups() as $section => $fields) {
        $sectionPosted = (isset($posted[$section]) && is_array($posted[$section])) ? $posted[$section] : [];
        $out[$section] = [];
        foreach ($fields as $key => $label) {
            $out[$section][$key] = $reset || !empty($sectionPosted[$key]);
        }
    }
    return $out;
}

function lc_details_rail_hide_style(int $accountId): string
{
    $vis = lc_details_rail_visibility($accountId);
    $css = '';
    $pages = [
        'title' => '.lc-details-page-title,.lc-details-page-title-row>.lc-meta-copy',
        'url' => '.lc-details-page-link',
        'time' => '.lc-details-page-time',
    ];
    foreach ($vis as $section => $fields) {
        foreach ($fields as $key => $on) {
            if ($on) {
                continue;
            }
            if ($section === 'pages' && isset($pages[$key])) {
                $css .= $pages[$key] . '{display:none!important}';
                continue;
            }
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                continue;
            }
            $css .= '[data-rail-field="' . $key . '"]{display:none!important}';
        }
    }
    return $css;
}
