<?php

/**
 * ISO 3166-1 alpha-2 country list for geo blocking settings.
 *
 * @return array<string, string> code => name
 */
function lc_country_list(): array
{
    return [
        'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra', 'AO' => 'Angola',
        'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan',
        'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BY' => 'Belarus', 'BE' => 'Belgium', 'BZ' => 'Belize',
        'BJ' => 'Benin', 'BT' => 'Bhutan', 'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana',
        'BR' => 'Brazil', 'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
        'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada', 'CV' => 'Cape Verde', 'CF' => 'Central African Republic',
        'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia', 'CG' => 'Congo',
        'CR' => 'Costa Rica', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus', 'CZ' => 'Czech Republic',
        'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt',
        'SV' => 'El Salvador', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FI' => 'Finland', 'FR' => 'France',
        'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
        'GR' => 'Greece', 'GT' => 'Guatemala', 'GN' => 'Guinea', 'HT' => 'Haiti', 'HN' => 'Honduras',
        'HK' => 'Hong Kong', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India', 'ID' => 'Indonesia',
        'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy',
        'JM' => 'Jamaica', 'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
        'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia', 'LB' => 'Lebanon',
        'LY' => 'Libya', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MO' => 'Macau', 'MK' => 'North Macedonia',
        'MG' => 'Madagascar', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali', 'MT' => 'Malta',
        'MX' => 'Mexico', 'MD' => 'Moldova', 'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco',
        'MZ' => 'Mozambique', 'MM' => 'Myanmar', 'NA' => 'Namibia', 'NP' => 'Nepal', 'NL' => 'Netherlands',
        'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger', 'NG' => 'Nigeria', 'NO' => 'Norway',
        'OM' => 'Oman', 'PK' => 'Pakistan', 'PS' => 'Palestine', 'PA' => 'Panama', 'PG' => 'Papua New Guinea',
        'PY' => 'Paraguay', 'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
        'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda', 'SA' => 'Saudi Arabia',
        'SN' => 'Senegal', 'RS' => 'Serbia', 'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia',
        'SO' => 'Somalia', 'ZA' => 'South Africa', 'KR' => 'South Korea', 'ES' => 'Spain', 'LK' => 'Sri Lanka',
        'SD' => 'Sudan', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan',
        'TJ' => 'Tajikistan', 'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey',
        'TM' => 'Turkmenistan', 'UG' => 'Uganda', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom', 'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
        'VE' => 'Venezuela', 'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    ];
}

function lc_country_name(string $code): string
{
    $code = strtoupper(trim($code));
    $list = lc_country_list();

    return $list[$code] ?? $code;
}

/** Resolve ISO alpha-2 from "Pakistan" or "Pakistan (PK)". */
function lc_country_code_from_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }
    if (preg_match('/\(([A-Z]{2})\)\s*$/', $label, $m)) {
        return $m[1];
    }
    if (preg_match('/^[A-Z]{2}$/', $label)) {
        return $label;
    }
    $needle = strtolower($label);
    foreach (lc_country_list() as $code => $name) {
        if (strtolower($name) === $needle) {
            return $code;
        }
    }
    return '';
}
