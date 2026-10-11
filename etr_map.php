<?php
/* etr_map.php — token-protected: the set of invoice "core numbers" that already have
   an ETR scan in the WorkDrive folders, so the local reconciliation app can show
   "ETR present" per invoice. Reuses workdrive.php + the shared collect token; the
   heavy WorkDrive scan is cached 1h (data/etr_map_cache.json, web-blocked). */
require_once __DIR__ . '/errors.php';
header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/data';
$secret = is_file($dir . '/collect_secret.json') ? (string)(json_decode(@file_get_contents($dir . '/collect_secret.json'), true)['token'] ?? '') : '';
$tok = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_COLLECT_TOKEN'] ?? ''));
if ($secret === '' || !hash_equals($secret, $tok)) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'forbidden']); exit; }

$cacheF = $dir . '/etr_map_cache.json';
if (!isset($_GET['refresh']) && is_file($cacheF) && (time() - filemtime($cacheF) < 3600)) {
    $j = json_decode(@file_get_contents($cacheF), true);
    if (is_array($j)) { $j['cached'] = true; echo json_encode($j); exit; }
}

define('ETR_INTERNAL', 1);
require_once __DIR__ . '/workdrive.php';   // defines wd_cfg(), wd_filename_number_map()
try {
    $c = wd_cfg();
    $folderIds = $c['workdrive_folder_ids']
        ?? ['0mqdi73cabe780dcf49adb599e8e650cf893e',   // older invoices (<=3049)
            'gfyj5e8ce417a75ca49a7a9641d2931dbae25'];  // SCANNED COPIES (3050+)
    if (is_string($folderIds)) $folderIds = array_filter(array_map('trim', explode(',', $folderIds)));
    $wd = wd_filename_number_map($folderIds);
    $res = ['ok'=>true, 'nums'=>array_values(array_keys($wd['map'])), 'file_count'=>$wd['file_count'], 'at'=>date('c'), 'cached'=>false];
    @file_put_contents($cacheF, json_encode($res));
    echo json_encode($res);
} catch (\Throwable $e) { echo api_fail($e); }
