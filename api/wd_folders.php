<?php
require_once __DIR__ . '/../errors.php';
/* api/wd_folders.php — browse WorkDrive folders (for the backup folder picker).
   Uses the app's own WorkDrive token.
   {action:'roots'}              -> known starting folders [{id,name}]
   {action:'list', folder_id}    -> { current:{id,name,parent_id}, folders:[{id,name}] } */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

define('ETR_INTERNAL', 1);
require __DIR__ . '/../workdrive.php';

try {
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;
    $action = $in['action'] ?? 'roots';

    if ($action === 'roots') {
        $roots = [];
        foreach (wd_default_folders() as $fid) {
            try { $info = wd_folder_info($fid); $roots[] = ['id'=>$info['id'], 'name'=>$info['name']]; }
            catch (Exception $e) { $roots[] = ['id'=>$fid, 'name'=>'(folder '.substr($fid,0,6).'…)']; }
        }
        echo json_encode(['ok'=>true, 'roots'=>$roots]);
        exit;
    }

    if ($action === 'list') {
        $fid = trim($in['folder_id'] ?? '');
        if ($fid === '') throw new Exception('No folder id.');
        $info = wd_folder_info($fid);
        $folders = wd_list_subfolders($fid);
        echo json_encode(['ok'=>true, 'current'=>$info, 'folders'=>$folders]);
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}
