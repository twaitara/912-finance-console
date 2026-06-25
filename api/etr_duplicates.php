<?php
/* api/etr_duplicates.php — find files in the scanned WorkDrive folder(s) that share the
   same invoice number (i.e. an invoice number appears on more than one file). 24h cache. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../workdrive.php';
$c = require __DIR__ . '/../config.php';
@set_time_limit(120);
$force = !empty($_GET['refresh']);
try {
    $cacheDir = __DIR__ . '/../data'; if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/etr_dups_v2.json';
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        $out = json_decode(file_get_contents($cacheFile), true);
        if (is_array($out)) { $out['cached'] = true; echo json_encode($out); exit; }
    }
    $folderIds = $c['workdrive_folder_ids']
        ?? ['0mqdi73cabe780dcf49adb599e8e650cf893e',   // older invoices (<=3049)
            'gfyj5e8ce417a75ca49a7a9641d2931dbae25'];  // SCANNED COPIES (3050+)
    if (is_string($folderIds)) $folderIds = array_filter(array_map('trim', explode(',', $folderIds)));

    // Is this file from 2026? Check its WorkDrive date, else the filename.
    $isYear = function($f, $year) {
        foreach (['created', 'modified'] as $k) {
            $v = (string)($f[$k] ?? '');
            if ($v === '') continue;
            if (ctype_digit($v) && strlen($v) >= 13) { if (date('Y', (int)substr($v, 0, 10)) === (string)$year) return true; continue; }
            if (strpos($v, (string)$year) !== false) return true;   // ISO date contains the year
        }
        return (strpos((string)($f['name'] ?? ''), (string)$year) !== false);
    };

    $groups = []; $fileCount = 0; $scanned2026 = 0;
    foreach ((array)$folderIds as $fid) {
        $fid = trim($fid); if ($fid === '') continue;
        foreach (wd_list_folder_files($fid) as $f) {
            $fileCount++;
            if (!$isYear($f, 2026)) continue;                       // only 2026 files
            $scanned2026++;
            // only files carrying an INV-#### number (anywhere in the name)
            if (preg_match('/INV[-_ ]?0*(\d{2,7})/i', $f['name'], $m)) {
                $core = $m[1];
                if (!isset($groups[$core])) $groups[$core] = [];
                $groups[$core][] = ['name'=>$f['name'], 'id'=>$f['id'], 'link'=>$f['link']];
            }
        }
    }
    $dups = [];
    foreach ($groups as $num => $files) {
        // drop exact same file (same id) so a single file listed twice isn't a "duplicate"
        $seen = []; $u = [];
        foreach ($files as $f) { $k = $f['id'] !== '' ? $f['id'] : $f['name']; if (isset($seen[$k])) continue; $seen[$k]=1; $u[]=$f; }
        if (count($u) > 1) $dups[] = ['number'=>'INV-'.$num, 'count'=>count($u), 'files'=>$u];
    }
    usort($dups, function($a,$b){ $d = $b['count'] <=> $a['count']; return $d !== 0 ? $d : ((int)preg_replace('/\D/','',$a['number']) <=> (int)preg_replace('/\D/','',$b['number'])); });

    $out = ['ok'=>true, 'duplicates'=>$dups, 'groups'=>count($dups), 'filesScanned'=>$fileCount, 'scanned2026'=>$scanned2026, 'generatedAt'=>date('c'), 'cached'=>false];
    @file_put_contents($cacheFile, json_encode($out));
    echo json_encode($out);
} catch (Exception $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
