<?php
/* api/settings.php — read/save user-editable settings (data/settings.json).
   GET  -> effective values (config defaults merged with saved overrides)
   POST -> save whitelisted numeric + string settings, returns effective values
   config.php is never modified. */

session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings_store.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Owner only.']); exit; }
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) $in = $_POST;

        $ov = app_settings_overrides();
        foreach (app_settings_schema() as $k => $d) {
            if (!array_key_exists($k, $in)) continue;
            if ($d[0] === 'num') {
                if (is_numeric($in[$k])) $ov[$k] = (float)$in[$k];
            } else {
                $ov[$k] = substr(trim((string)$in[$k]), 0, 2000);
            }
        }

        // sanity clamps
        if (isset($ov['fund']))            $ov['fund']            = max(0, $ov['fund']);
        if (isset($ov['annual_rate']))     $ov['annual_rate']     = min(1, max(0, $ov['annual_rate']));
        if (isset($ov['vat_rate']))        $ov['vat_rate']        = min(1, max(0, $ov['vat_rate']));
        if (isset($ov['usd_rate']))        $ov['usd_rate']        = min(100000, max(1, $ov['usd_rate']));
        if (isset($ov['growth_multiple'])) $ov['growth_multiple'] = min(100, max(1, $ov['growth_multiple']));
        if (isset($ov['inbox_days']))      $ov['inbox_days']      = (float) min(365, max(1, round($ov['inbox_days'])));
        if (isset($ov['sent_hide_days']))  $ov['sent_hide_days']  = (float) min(365, max(1, round($ov['sent_hide_days'])));

        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (@file_put_contents(app_settings_path(), json_encode($ov)) === false) {
            throw new Exception('Could not write settings file (check data/ is writable, 755).');
        }
    }

    echo json_encode(['ok' => true] + app_settings_effective($cfg));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
