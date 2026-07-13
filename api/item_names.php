<?php
/* api/item_names.php — shared cache of quote line-item names for autocomplete.
   GET  ?action=list        → { ok, names:[...] }  (all users)
   POST { action:'add', names:[...] } → merge new names (case-insensitive dedupe). */
session_start();
require_once __DIR__ . '/../csrf.php'; csrf_guard();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in']); exit; }

$dir  = __DIR__ . '/../data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
$file = $dir . '/item_names.json';
$load = function() use ($file) {
    if (is_file($file)) { $j = json_decode(@file_get_contents($file), true); if (is_array($j)) return $j; }
    return [];
};

$in     = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$action = $in['action'] ?? ($_GET['action'] ?? 'list');

if ($action === 'add') {
    $names = is_array($in['names'] ?? null) ? $in['names'] : [];
    $existing = $load();
    $seen = [];
    foreach ($existing as $n) { $seen[mb_strtolower(trim((string)$n))] = true; }
    $added = 0;
    foreach ($names as $raw) {
        $n = trim((string)$raw);
        if ($n === '') continue;
        if (mb_strlen($n) > 120) $n = mb_substr($n, 0, 120);
        $k = mb_strtolower($n);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $existing[] = $n;
        $added++;
    }
    if ($added) {
        if (count($existing) > 3000) $existing = array_slice($existing, -3000);   // keep it bounded
        @file_put_contents($file, json_encode(array_values($existing)));
    }
    echo json_encode(['ok'=>true, 'added'=>$added, 'count'=>count($existing)]);
    exit;
}

$names = $load();
usort($names, fn($a, $b) => strcasecmp((string)$a, (string)$b));
echo json_encode(['ok'=>true, 'names'=>array_values($names)]);
