<?php
/* api/backup.php — backs up the app's own MySQL data (the only copies that exist).
   Tables: deployments, audrey_marks, email_book, tasks (whichever exist) + data/settings.json.
   Actions (POST JSON):
     { action:'set_folder', folder_id:'...' }  -> save the WorkDrive backup folder id
     { action:'status' }                        -> returns saved folder id (masked-free)
     { action:'run' }                           -> build dump, upload to WorkDrive
   GET ?download=1                               -> stream the .sql to the browser (always works) */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../db.php';

function bk_folder_path(){ return __DIR__ . '/../data/backup_folder.txt'; }
function bk_get_folder(){ $f=bk_folder_path(); return is_file($f) ? trim(@file_get_contents($f)) : ''; }

function bk_build_dump(PDO $pdo){
    $tables = ['deployments','audrey_marks','email_book','tasks'];
    $out  = "-- 912 Finance Console backup\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($tables as $t) {
        // table exists?
        $chk = $pdo->prepare("SHOW TABLES LIKE ?");
        $chk->execute([$t]);
        if (!$chk->fetchColumn()) continue;
        // schema
        $cr = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $create = $cr['Create Table'] ?? '';
        $out .= "-- ----- $t -----\nDROP TABLE IF EXISTS `$t`;\n" . $create . ";\n\n";
        // rows
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $row) {
                $cols = array_map(fn($c)=>"`$c`", array_keys($row));
                $vals = array_map(function($v) use ($pdo){ return $v===null ? 'NULL' : $pdo->quote((string)$v); }, array_values($row));
                $out .= "INSERT INTO `$t` (".implode(',', $cols).") VALUES (".implode(',', $vals).");\n";
            }
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    // include settings.json as a comment block so it's captured too
    $sf = __DIR__ . '/../data/settings.json';
    if (is_file($sf)) $out .= "\n-- settings.json:\n-- " . str_replace("\n", "\n-- ", trim(@file_get_contents($sf))) . "\n";
    return $out;
}

try {
    $pdo = db();

    // direct download fallback (always works, no WorkDrive scope needed)
    if (isset($_GET['download']) && $_GET['download']=='1') {
        $dump = bk_build_dump($pdo);
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="Finance Console Backup '.date('Y-m-d').'.sql"');
        header('Content-Length: '.strlen($dump));
        echo $dump; exit;
    }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'run';

    if ($action === 'set_folder') {
        $fid = trim($in['folder_id'] ?? '');
        @file_put_contents(bk_folder_path(), $fid);
        echo json_encode(['ok'=>true, 'folder_id'=>$fid]); exit;
    }
    if ($action === 'status') {
        echo json_encode(['ok'=>true, 'folder_id'=>bk_get_folder()]); exit;
    }

    // action: run -> build + upload to WorkDrive
    // if a folder_id is supplied with the run, save it as the new remembered default
    if (isset($in['folder_id'])) {
        $fid = trim($in['folder_id']);
        if ($fid !== '') @file_put_contents(bk_folder_path(), $fid);
    }
    $folder = bk_get_folder();
    if ($folder === '') { echo json_encode(['ok'=>false, 'need_folder'=>true, 'error'=>'No WorkDrive backup folder set yet. Paste a folder ID and Save first.']); exit; }

    $dump = bk_build_dump($pdo);
    $name = 'Finance Console Backup ' . date('Y-m-d') . '.sql';

    if (!defined('ETR_INTERNAL')) define('ETR_INTERNAL', 1);
    require __DIR__ . '/../workdrive.php';
    try {
        $up = wd_upload_file($folder, $name, $dump, 'application/sql');
        echo json_encode(['ok'=>true, 'uploaded'=>true, 'name'=>$name, 'size'=>strlen($dump), 'id'=>$up['id'] ?? '']);
    } catch (Exception $e) {
        // upload failed (often: WorkDrive token lacks files.CREATE scope) — tell the user, offer download
        echo json_encode(['ok'=>false, 'uploaded'=>false, 'name'=>$name, 'size'=>strlen($dump),
            'error'=>$e->getMessage(),
            'hint'=>'If this mentions scope/permission, re-mint the WorkDrive token with WorkDrive.files.ALL and delete data/wd_token.json. You can still use “Download backup” below in the meantime.']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
