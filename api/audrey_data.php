<?php
require_once __DIR__ . '/../errors.php';
/* api/audrey_data.php  (PUBLIC — no password)
   Returns unpaid invoices for the Dunhill client list, grouped by client,
   merged with Audrey's own paid/unpaid marks (MySQL). POST or GET. */
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../zoho.php';
require __DIR__ . '/../db.php';
require __DIR__ . '/audrey_clients.php';
@set_time_limit(120);

function aud_norm($s){ return audrey_norm($s); }

try {
    $c = zoho_config();
    $set = audrey_client_set();

    // ensure marks table
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS audrey_marks (
        invoice_number VARCHAR(64) PRIMARY KEY,
        status VARCHAR(16) NOT NULL DEFAULT 'unpaid',
        note TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    try { $pdo->exec("ALTER TABLE audrey_marks ADD COLUMN note TEXT NULL"); } catch (Exception $e) {}

    // ---- cache the Zoho fetch for 15 min (marks applied fresh below) ----
    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/audrey_unpaid_v4.json';
    $force = isset($_GET['refresh']) || (isset($_POST['refresh']));
    $invoices = null;
    if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile) < 900)) {
        $invoices = json_decode(file_get_contents($cacheFile), true);
    }
    if (!is_array($invoices)) {
        $invoices = []; $page = 1;
        do {
            [$d, $code] = zoho_api('GET', 'invoices', null, [
                'filter_by'   => 'Status.Unpaid',
                'per_page'    => 200,
                'page'        => $page,
                'sort_column' => 'date',
            ]);
            if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error');
            foreach (($d['invoices'] ?? []) as $inv) {
                if ((float)($inv['balance'] ?? 0) <= 0) continue;
                if (($inv['status'] ?? '') === 'void') continue;
                $nm = aud_norm($inv['customer_name'] ?? '');
                if (!isset($set[$nm])) continue;
                $invoices[] = [
                    'number'   => $inv['invoice_number'] ?? '',
                    'client'   => $inv['customer_name'] ?? '',
                    'date'     => substr($inv['date'] ?? '', 0, 10),
                    'dueDate'  => substr($inv['due_date'] ?? '', 0, 10),
                    'balance'  => (float)($inv['balance'] ?? 0),
                    'currency' => $inv['currency_code'] ?? $c['currency'],
                ];
            }
            $more = $d['page_context']['has_more_page'] ?? false;
            $page++;
        } while ($more && $page <= 40);

        @file_put_contents($cacheFile, json_encode($invoices));
    }

    // marks
    $marks = []; $notes = [];
    foreach ($pdo->query("SELECT invoice_number, status, note FROM audrey_marks")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $marks[$m['invoice_number']] = $m['status'];
        $notes[$m['invoice_number']] = $m['note'] ?? '';
    }

    // group by client — START from the FULL Dunhill list so clients with
    // zero unpaid invoices still appear (with an empty list).
    $groups = [];
    foreach (audrey_client_list() as $canon) $groups[$canon] = [];
    $totalDue = 0; $paidMarked = 0;
    foreach ($invoices as $iv) {
        $nm = aud_norm($iv['client']);
        if (!isset($set[$nm])) continue;            // ignore any client no longer on the list
        $iv['audrey'] = $marks[$iv['number']] ?? 'unpaid';
        $iv['note']   = $notes[$iv['number']] ?? '';
        if ($iv['audrey'] === 'paid') $paidMarked++; else $totalDue += $iv['balance'];
        $canon = $set[$nm];
        $groups[$canon][] = $iv;
    }
    uksort($groups, fn($a,$b)=>strcasecmp($a,$b));
    $out = [];
    foreach ($groups as $client => $list) {
        usort($list, fn($a,$b)=>strcmp($a['date'],$b['date']));
        $sub = 0; foreach ($list as $x) if ($x['audrey'] !== 'paid') $sub += $x['balance'];
        $out[] = ['name'=>$client, 'invoices'=>$list, 'subtotalDue'=>$sub, 'unpaidCount'=>count($list)];
    }

    echo json_encode([
        'ok'         => true,
        'clients'    => $out,
        'totalDue'   => $totalDue,
        'paidMarked' => $paidMarked,
        'count'      => count($invoices),
        'currency'   => $c['currency'],
        'asOf'       => date('c'),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}
