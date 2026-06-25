<?php
/* api/email_clients.php — list active Zoho Books customers (id, name, email). Cached 6h. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }

require __DIR__ . '/../zoho.php';
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../settings_store.php';
$__cfg = require __DIR__ . '/../config.php';
$SENT_DAYS = (int) app_setting($__cfg, 'sent_hide_days'); if ($SENT_DAYS < 1) $SENT_DAYS = 30;
@set_time_limit(60);

/* annotate clients marked "sent" within the last 2 weeks (struck-through, sorted to bottom in UI) */
function ec_apply_sent($clients){
    try {
        $pdo = db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS email_sent_marks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(64) NOT NULL UNIQUE,
            marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $days = 14; // show the red strikethrough for two weeks, then it clears
        $cut = date("Y-m-d H:i:s", time() - $days*24*3600);
        $st = $pdo->prepare("SELECT customer_id, marked_at FROM email_sent_marks WHERE marked_at >= ?");
        $st->execute([$cut]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $map[(string)$r['customer_id']] = $r['marked_at']; }
        foreach ($clients as &$cl) {
            $cid = (string)($cl['id'] ?? '');
            if ($cid !== '' && isset($map[$cid])) { $cl['sent'] = true; $cl['sent_at'] = $map[$cid]; }
            else { $cl['sent'] = false; }
        }
        unset($cl);
    } catch (Exception $e) { /* non-fatal */ }
    return $clients;
}

try {
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/email_clients_v4.json';
    $force = isset($_GET['refresh']) && $_GET['refresh'] == '1';

    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 21600)) {
        $clients = json_decode(file_get_contents($cacheFile), true);
        echo json_encode(['ok'=>true, 'clients'=>ec_apply_sent($clients), 'cached'=>true]);
        exit;
    }

    $clients = []; $page = 1;
    do {
        [$d, $code] = zoho_api('GET', 'contacts', null, [
            'contact_type' => 'customer',
            'per_page'     => 200,
            'page'         => $page,
            'sort_column'  => 'contact_name',
        ]);
        if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (contacts)');
        foreach (($d['contacts'] ?? []) as $ct) {
            $clients[] = [
                'id'    => $ct['contact_id'] ?? '',
                'name'  => $ct['contact_name'] ?? ($ct['company_name'] ?? ''),
                'email' => $ct['email'] ?? '',
            ];
        }
        $more = $d['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page <= 25);

    // which customers currently have unpaid invoices (balance > 0), with totals
    $unpaidAgg = [];
    try {
        $p = 1;
        do {
            [$iv, $ic] = zoho_api('GET', 'invoices', null, [
                'filter_by' => 'Status.Unpaid',
                'per_page'  => 200,
                'page'      => $p,
            ]);
            if ($ic >= 400) break;
            foreach (($iv['invoices'] ?? []) as $inv) {
                $bal = (float)($inv['balance'] ?? 0);
                $id  = $inv['customer_id'] ?? '';
                if ($bal > 0 && $id !== '') {
                    // convert foreign-currency balances to KES using the invoice's own rate
                    $cur  = $inv['currency_code'] ?? 'KES';
                    $rate = (float)($inv['exchange_rate'] ?? 1); if ($rate <= 0) $rate = 1;
                    $balKes = (strtoupper($cur) !== 'KES') ? $bal * $rate : $bal;
                    if (!isset($unpaidAgg[$id])) $unpaidAgg[$id] = ['count'=>0, 'total'=>0.0];
                    $unpaidAgg[$id]['count']++;
                    $unpaidAgg[$id]['total'] += $balKes;
                }
            }
            $more = $iv['page_context']['has_more_page'] ?? false;
            $p++;
        } while ($more && $p <= 40);
    } catch (Exception $e) { /* non-fatal: just skip the unpaid ordering */ }

    foreach ($clients as &$cl) {
        $a = $unpaidAgg[$cl['id']] ?? null;
        $cl['unpaid']      = $a ? true : false;
        $cl['unpaidCount'] = $a['count'] ?? 0;
        $cl['unpaidTotal'] = $a['total'] ?? 0;
    }
    unset($cl);

    // clients WITH unpaid invoices first (largest due first), then the rest alphabetically
    usort($clients, function($a,$b){
        if ($a['unpaid'] !== $b['unpaid']) return $a['unpaid'] ? -1 : 1;
        if ($a['unpaid'] && $b['unpaid']) return $b['unpaidTotal'] <=> $a['unpaidTotal'];
        return strcasecmp($a['name'], $b['name']);
    });
    @file_put_contents($cacheFile, json_encode($clients));
    echo json_encode(['ok'=>true, 'clients'=>ec_apply_sent($clients), 'cached'=>false]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
