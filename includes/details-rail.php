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
    return $css;
}
