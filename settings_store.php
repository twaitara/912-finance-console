<?php
/* Shared store for user-editable settings that override config.php defaults.
   Saved as data/settings.json (writable, blocked from web by .htaccess).
   config.php is never modified. Supports numeric and string settings. */

function app_settings_path() { return __DIR__ . '/data/settings.json'; }

/* Schema: key => [type, default]. type 'num' or 'str'.
   fund/annual_rate default null because config.php supplies their base value. */
function app_settings_schema() {
    return [
        'fund'              => ['num', null],
        'annual_rate'       => ['num', null],
        'vat_rate'          => ['num', 0.16],
        'usd_rate'          => ['num', 128],
        'growth_multiple'   => ['num', 2],
        'inbox_days'        => ['num', 14],
        'sent_hide_days'    => ['num', 30],
        'business_name'     => ['str', 'Nine One Two Holdings'],
        'statement_subject' => ['str', 'Pending invoices and Statement'],
        'statement_footer'  => ['str', "Prepared and reconciled by the 912 Finance Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time."],
    ];
}

/* Numeric keys only — kept for any legacy callers. */
function app_settings_allowed() {
    $out = [];
    foreach (app_settings_schema() as $k => $d) { if ($d[0] === 'num') $out[] = $k; }
    return $out;
}

/* Sanitised overrides actually present in data/settings.json. */
function app_settings_overrides() {
    $f = app_settings_path();
    $out = [];
    if (is_file($f)) {
        $j = json_decode(@file_get_contents($f), true);
        if (is_array($j)) {
            foreach (app_settings_schema() as $k => $d) {
                if (!array_key_exists($k, $j)) continue;
                if ($d[0] === 'num') { if (is_numeric($j[$k])) $out[$k] = (float)$j[$k]; }
                else { $out[$k] = substr(trim((string)$j[$k]), 0, 2000); }
            }
        }
    }
    return $out;
}

/* Effective value for one key: override -> config.php -> schema default. */
function app_setting($cfg, $key) {
    $ov = app_settings_overrides();
    if (array_key_exists($key, $ov)) return $ov[$key];
    if (is_array($cfg) && array_key_exists($key, $cfg)) return $cfg[$key];
    $s = app_settings_schema();
    return isset($s[$key]) ? $s[$key][1] : null;
}

/* Full effective settings map (all schema keys resolved). */
function app_settings_effective($cfg) {
    $eff = [];
    foreach (app_settings_schema() as $k => $d) { $eff[$k] = app_setting($cfg, $k); }
    return $eff;
}
