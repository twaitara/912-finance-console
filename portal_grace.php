<?php
/* portal_grace.php — GRACE collections portal. Login-protected tracker of ALL
   unpaid invoices for chasing payment: customer, phone (tap-to-call + WhatsApp),
   amount, days overdue, and a per-customer status + notes she fills in.
   Included by index.php; served at ?portal=grace (and the /grace shortcut).
   Credentials + disable switch are managed by the admin in the Portals tab. */

if (!function_exists('gp_build')) {
    function gp_prefs($dir) { $f = $dir . '/grace_prefs.json'; $j = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; return ['disabled' => !empty($j['disabled'])]; }
    function gp_phones_load($dir) { $f = $dir . '/grace_phones.json'; return is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; }
    function gp_notes_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS grace_notes (
            zoho_customer_id VARCHAR(64) PRIMARY KEY,
            customer_name VARCHAR(190) DEFAULT '',
            status VARCHAR(20) DEFAULT '',
            note TEXT NULL,
            promised_date DATE NULL,
            phone VARCHAR(40) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* migrate older tables that predate the phone column */
        try { $pdo->exec("ALTER TABLE grace_notes ADD COLUMN phone VARCHAR(40) DEFAULT ''"); } catch (\Throwable $e) { /* already exists */ }
    }
    /* Fetch one invoice's line items from Zoho and distil a short "what it's for"
       summary. Returned struct feeds both the inline summary and the detail popup. */
    function gp_inv_fetch_raw($id) {
        try {
            [$d, $c] = zoho_api('GET', 'invoices/' . rawurlencode($id));
            if ($c >= 400 || empty($d['invoice'])) return null;
            $iv = $d['invoice'];
            $items = [];
            foreach (($iv['line_items'] ?? []) as $li) {
                $items[] = [
                    'name' => (string)($li['name'] ?? ''), 'desc' => (string)($li['description'] ?? ''),
                    'qty' => (float)($li['quantity'] ?? 0), 'rate' => (float)($li['rate'] ?? 0), 'amt' => (float)($li['item_total'] ?? 0),
                ];
            }
            $names = [];
            foreach ($items as $it) { $t = trim($it['name'] !== '' ? $it['name'] : $it['desc']); if ($t !== '') $names[] = $t; }
            $summary = '';
            if ($names) { $summary = $names[0]; if (count($names) > 1) $summary .= '  +' . (count($names) - 1) . ' more'; }
            elseif (trim((string)($iv['notes'] ?? '')) !== '') { $summary = trim((string)$iv['notes']); }
            $summary = mb_substr($summary, 0, 90);
            return [
                'id' => (string)$id, 'number' => (string)($iv['invoice_number'] ?? ''), 'customer' => (string)($iv['customer_name'] ?? ''),
                'date' => substr((string)($iv['date'] ?? ''), 0, 10), 'due' => substr((string)($iv['due_date'] ?? ''), 0, 10),
                'status' => (string)($iv['status'] ?? ''), 'currency' => strtoupper((string)($iv['currency_code'] ?? '')),
                'total' => (float)($iv['total'] ?? 0), 'balance' => (float)($iv['balance'] ?? 0),
                'notes' => (string)($iv['notes'] ?? ''), 'items' => $items, 'summary' => $summary,
            ];
        } catch (\Throwable $e) { return null; }
    }
    /* All unpaid invoices grouped by customer, with cached phone + overlaid notes. */
    function gp_build($force) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/grace_unpaid_v3.json';
        if (!$force && is_file($cache) && (time() - filemtime($cache) < 900)) { $j = json_decode(file_get_contents($cache), true); if (is_array($j)) { $j['cached'] = true; return $j; } }
        $cfg = zoho_config();

        // 1) all unpaid invoices
        $rows = []; $page = 1;
        do {
            [$d, $c] = zoho_api('GET', 'invoices', null, ['filter_by'=>'Status.Unpaid', 'per_page'=>200, 'page'=>$page, 'sort_column'=>'due_date']);
            if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
            foreach (($d['invoices'] ?? []) as $inv) {
                $bal = (float)($inv['balance'] ?? 0); if ($bal <= 0) continue;
                if (($inv['status'] ?? '') === 'void') continue;
                $rows[] = [
                    'id'       => (string)($inv['invoice_id'] ?? ''),
                    'cid'      => (string)($inv['customer_id'] ?? ''),
                    'customer' => (string)($inv['customer_name'] ?? ''),
                    'number'   => (string)($inv['invoice_number'] ?? ''),
                    'date'     => substr((string)($inv['date'] ?? ''), 0, 10),
                    'due'      => substr((string)($inv['due_date'] ?? ''), 0, 10),
                    'balance'  => $bal,
                    'currency' => strtoupper((string)($inv['currency_code'] ?? ($cfg['currency'] ?? 'KES'))),
                ];
            }
            $more = $d['page_context']['has_more_page'] ?? false; $page++;
        } while ($more && $page <= 40);

        // 2) group by customer
        $today = date('Y-m-d');
        $byCust = [];
        foreach ($rows as $r) {
            $key = $r['cid'] !== '' ? $r['cid'] : ('name:' . mb_strtolower($r['customer']));
            if (!isset($byCust[$key])) $byCust[$key] = ['cid'=>$r['cid'], 'customer'=>$r['customer'], 'invoices'=>[], 'totalByCur'=>[], 'sortTotal'=>0, 'maxOverdue'=>0];
            $days = ($r['due'] !== '') ? (int)floor((strtotime($today) - strtotime($r['due'])) / 86400) : 0; if ($days < 0) $days = 0;
            $r['overdue'] = $days;
            $g = &$byCust[$key];
            $g['invoices'][] = $r;
            $g['totalByCur'][$r['currency']] = ($g['totalByCur'][$r['currency']] ?? 0) + $r['balance'];
            $g['sortTotal'] += $r['balance'];
            if ($days > $g['maxOverdue']) $g['maxOverdue'] = $days;
            unset($g);
        }

        // 3) phones (cached persistently; fetch uncached, capped per load)
        $phones = gp_phones_load($dir); $dirty = false; $fetched = 0; $CAP = 60;
        foreach ($byCust as &$g) {
            $id = $g['cid'];
            if ($id === '') { $g['phone'] = ''; $g['mobile'] = ''; $g['email'] = ''; continue; }
            if (isset($phones[$id])) { $g['phone'] = (string)($phones[$id]['phone'] ?? ''); $g['mobile'] = (string)($phones[$id]['mobile'] ?? ''); $g['email'] = (string)($phones[$id]['email'] ?? ''); continue; }
            if ($fetched >= $CAP) { $g['phone'] = ''; $g['mobile'] = ''; $g['email'] = ''; continue; }
            $ph = ''; $mob = ''; $em = '';
            try {
                [$cd, $cc] = zoho_api('GET', 'contacts/' . rawurlencode($id));
                if ($cc < 400 && !empty($cd['contact'])) {
                    $ct = $cd['contact'];
                    $ph = trim((string)($ct['phone'] ?? '')); $mob = trim((string)($ct['mobile'] ?? '')); $em = trim((string)($ct['email'] ?? ''));
                    if ($ph === '' && $mob === '') { foreach (($ct['contact_persons'] ?? []) as $cp) { $ph = trim((string)($cp['phone'] ?? '')); $mob = trim((string)($cp['mobile'] ?? '')); if ($em === '') $em = trim((string)($cp['email'] ?? '')); if ($ph !== '' || $mob !== '') break; } }
                    $cc2 = true;   // success — safe to cache
                } else { $cc2 = false; }
            } catch (Exception $e) { $cc2 = false; }
            if (!empty($cc2)) { $phones[$id] = ['phone'=>$ph, 'mobile'=>$mob, 'email'=>$em, 'name'=>$g['customer']]; $dirty = true; }  // only cache on success, so failures retry
            $fetched++;
            $g['phone'] = $ph; $g['mobile'] = $mob; $g['email'] = $em;
        }
        unset($g);
        if ($dirty) @file_put_contents($dir . '/grace_phones.json', json_encode($phones));

        // 3.5) invoice "what it's for" summaries (cached persistently; fetch uncached, capped per load)
        $invMap = is_file($dir . '/grace_inv.json') ? (json_decode(@file_get_contents($dir . '/grace_inv.json'), true) ?: []) : [];
        $invDirty = false; $ifetched = 0; $ICAP = 40;
        foreach ($byCust as &$g) {
            foreach ($g['invoices'] as &$iv) {
                $iid = $iv['id'];
                if ($iid === '') { $iv['about'] = ''; continue; }
                if (isset($invMap[$iid])) { $iv['about'] = (string)($invMap[$iid]['summary'] ?? ''); continue; }
                if ($ifetched >= $ICAP) { $iv['about'] = ''; continue; }
                $det = gp_inv_fetch_raw($iid); $ifetched++;
                if ($det) { $invMap[$iid] = $det; $invDirty = true; $iv['about'] = (string)($det['summary'] ?? ''); }
                else { $iv['about'] = ''; }
            }
        }
        unset($g, $iv);
        if ($invDirty) @file_put_contents($dir . '/grace_inv.json', json_encode($invMap));

        // 4) notes overlay (incl. Grace's own saved phone, which wins over Zoho)
        $notes = [];
        try { $pdo = db(); gp_notes_table($pdo); foreach ($pdo->query("SELECT zoho_customer_id,status,note,promised_date,phone FROM grace_notes")->fetchAll(PDO::FETCH_ASSOC) as $n) { $notes[(string)$n['zoho_customer_id']] = $n; } } catch (Exception $e) { /* no notes yet */ }

        // 5) compose
        $out = []; $totalByCur = []; $invCount = 0;
        foreach ($byCust as $g) {
            usort($g['invoices'], fn($a, $b) => strcmp($a['due'], $b['due']));   // most overdue first
            $n = ($g['cid'] !== '' && isset($notes[$g['cid']])) ? $notes[$g['cid']] : null;
            $savedPhone = $n ? trim((string)($n['phone'] ?? '')) : '';
            $out[] = [
                'cid'=>$g['cid'], 'customer'=>$g['customer'],
                'phone'=>$savedPhone !== '' ? $savedPhone : $g['phone'], 'mobile'=>$g['mobile'], 'email'=>$g['email'] ?? '',
                'savedPhone'=>$savedPhone, 'hasZohoPhone'=>($g['phone'] !== '' || $g['mobile'] !== ''),
                'totalByCur'=>$g['totalByCur'], 'sortTotal'=>$g['sortTotal'], 'count'=>count($g['invoices']),
                'maxOverdue'=>$g['maxOverdue'], 'invoices'=>$g['invoices'],
                'status'=>$n['status'] ?? '', 'note'=>$n['note'] ?? '', 'promised'=>$n['promised_date'] ?? '',
            ];
            foreach ($g['totalByCur'] as $cur => $amt) $totalByCur[$cur] = ($totalByCur[$cur] ?? 0) + $amt;
            $invCount += count($g['invoices']);
        }
        usort($out, fn($a, $b) => $b['sortTotal'] <=> $a['sortTotal']);   // biggest owed first
        $res = ['ok'=>true, 'asOf'=>date('c'), 'cached'=>false, 'customers'=>$out, 'totalByCur'=>$totalByCur, 'custCount'=>count($out), 'invCount'=>$invCount];
        @file_put_contents($cache, json_encode($res));
        return $res;
    }
    /* Overlay the shared "ready to collect" flags (set from the reconciliation app via
       collect.php) onto a built dataset. Applied per-request (outside the 15-min cache)
       so Grace sees a new flag as soon as she refreshes. */
    function gp_apply_collect($res) {
        $ready = [];
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_flags (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', ready TINYINT(1) DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($pdo->query("SELECT invoice_id FROM collect_flags WHERE ready=1")->fetchAll(PDO::FETCH_COLUMN) as $v) $ready[(string)$v] = true;
        } catch (\Throwable $e) { return $res; }
        $totalReady = 0;
        foreach ($res['customers'] as &$c) {
            $rc = 0;
            foreach ($c['invoices'] as &$iv) { $r = isset($ready[(string)($iv['id'] ?? '')]); $iv['ready'] = $r; if ($r) $rc++; }
            unset($iv);
            $c['readyCount'] = $rc; $totalReady += $rc;
        }
        unset($c);
        $res['readyTotal'] = $totalReady;
        return $res;
    }
}

if (isset($_GET['portal']) && $_GET['portal'] === 'grace') {
    session_name('GRACEPORTAL');
    session_start();
    require_once __DIR__ . '/csrf.php'; csrf_guard();
    require_once __DIR__ . '/errors.php';
    require_once __DIR__ . '/zoho.php';
    require_once __DIR__ . '/db.php';
    @set_time_limit(120);
    $gDir = __DIR__ . '/data';

    $gAuthFile = $gDir . '/grace_auth.json';
    /* Accounts: new format {staff:{user,hash}, owner:{user,hash}}; legacy {user,hash} => staff. */
    $gAccounts = [];
    if (is_file($gAuthFile)) {
        $j = json_decode(@file_get_contents($gAuthFile), true);
        if (is_array($j)) {
            foreach (['staff', 'owner'] as $slot) { if (!empty($j[$slot]['user']) && !empty($j[$slot]['hash'])) $gAccounts[] = ['user'=>$j[$slot]['user'], 'hash'=>$j[$slot]['hash'], 'role'=>$slot]; }
            if (!$gAccounts && !empty($j['user']) && !empty($j['hash'])) $gAccounts[] = ['user'=>$j['user'], 'hash'=>$j['hash'], 'role'=>'staff'];
        }
    }
    $gDisabled = !empty(gp_prefs($gDir)['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=grace'); exit; }

    $gErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grace_login'])) {
        $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
        $ok = false; $role = '';
        if (!$gDisabled) { foreach ($gAccounts as $a) { if (hash_equals(strtolower($a['user']), strtolower($u)) && password_verify($p, $a['hash'])) { $ok = true; $role = $a['role']; break; } } }
        if ($ok) { session_regenerate_id(true); $_SESSION['grace_auth'] = true; $_SESSION['grace_user'] = $u; $_SESSION['grace_role'] = $role; header('Location: index.php?portal=grace'); exit; }
        $gErr = $gDisabled ? 'This portal is currently disabled.' : 'Wrong username or password.';
    }
    $gAuthed = !$gDisabled && !empty($_SESSION['grace_auth']);

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try { echo json_encode(gp_apply_collect(gp_build(isset($_GET['refresh'])))); }
        catch (\Throwable $e) { http_response_code(500); echo api_fail($e); }
        exit;
    }

    if (isset($_GET['save'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $cid = trim((string)($in['customer_id'] ?? '')); if ($cid === '') { echo json_encode(['ok'=>false, 'error'=>'No customer id.']); exit; }
            $name = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 190);
            $status = in_array(($in['status'] ?? ''), ['Called', 'Promised', 'No answer', 'Paid', ''], true) ? (string)($in['status'] ?? '') : '';
            $note = mb_substr((string)($in['note'] ?? ''), 0, 3000);
            $pd = trim((string)($in['promised_date'] ?? '')); $pd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) ? $pd : null;
            $phone = mb_substr(trim((string)($in['phone'] ?? '')), 0, 40);
            $pdo = db(); gp_notes_table($pdo);
            $pdo->prepare("INSERT INTO grace_notes (zoho_customer_id,customer_name,status,note,promised_date,phone) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE customer_name=VALUES(customer_name),status=VALUES(status),note=VALUES(note),promised_date=VALUES(promised_date),phone=VALUES(phone)")
                ->execute([$cid, $name, $status, $note, $pd, $phone]);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    /* Invoice detail as JSON for the "what it's for" popup (line items, not a PDF). */
    if (isset($_GET['inv'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        $iid = preg_replace('/[^0-9]/', '', (string)$_GET['inv']);
        if ($iid === '') { echo json_encode(['ok'=>false, 'error'=>'Invoice not found.']); exit; }
        try {
            $f = $gDir . '/grace_inv.json';
            $map = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : [];
            if (isset($map[$iid])) { echo json_encode(['ok'=>true, 'invoice'=>$map[$iid]]); exit; }
            $det = gp_inv_fetch_raw($iid);
            if (!$det) { http_response_code(502); echo json_encode(['ok'=>false, 'error'=>'Could not load this invoice.']); exit; }
            $map[$iid] = $det; @file_put_contents($f, json_encode($map));
            echo json_encode(['ok'=>true, 'invoice'=>$det]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    $gEsc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>GRACE — COLLECTIONS · WAITARA HOLDINGS GROUP</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#1B1A17;--mute:#6E6A63;--line:#D7D3CB;--hair:#E6E2DB;--bg:#E8E6E1;--card:#FFFFFF;--soft:#F5F3EF;
    --brand:#D95E07;--red:#BE2A17;--amber:#9A6700;--green:#1D7A3A;--mail:#1F4FD0;
    --red-bg:#F6E5E1;--amber-bg:#F4EEDC;--green-bg:#E3EEE6;--blue-bg:#E6ECF9;
    --mono:'IBM Plex Mono',ui-monospace,Menlo,Consolas,monospace;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'IBM Plex Sans',system-ui,'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13.5px;-webkit-font-smoothing:antialiased}
  .muted{color:var(--mute)}
  .mono{font-family:var(--mono);font-variant-numeric:tabular-nums}
  /* header */
  .top{background:#1B1A17;color:#fff;padding:0 16px;height:52px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50;border-bottom:2px solid var(--brand)}
  .b{width:30px;height:30px;border-radius:3px;background:var(--brand);display:grid;place-items:center;font-weight:700;font-size:12px;color:#fff;flex:0 0 auto;font-family:var(--mono)}
  .top h1{font-size:14px;margin:0;font-weight:600;letter-spacing:.3px;line-height:1.1;text-transform:uppercase}
  .top .sub{font-size:9.5px;color:#9A948B;letter-spacing:.8px;margin-top:1px;font-weight:500}
  .top .sp{margin-left:auto;display:flex;gap:8px}
  .tbtn{border:1px solid #3A3833;color:#E7E3DC;background:transparent;border-radius:3px;padding:7px 12px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap;letter-spacing:.3px}
  .tbtn:hover{background:#2A2824}
  .wrap{width:100%;max-width:none;margin:0;padding:18px clamp(14px,3.2vw,52px) 48px}
  /* ledger summary panel */
  .hero{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:16px 18px;display:flex;align-items:flex-end;gap:28px;flex-wrap:wrap}
  .hero .hl{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--mute)}
  .hero .ht{font-family:var(--mono);font-weight:600;font-size:27px;line-height:1.05;margin-top:5px;color:var(--ink)}
  .hero .hs{font-size:12px;margin-top:6px;color:var(--mute)}
  .hero .hr{margin-left:auto;display:flex;gap:0}
  .stat{padding:2px 22px;border-left:1px solid var(--hair)}
  .stat:first-child{border-left:0;padding-left:0}
  .stat .n{font-family:var(--mono);font-size:22px;font-weight:600;line-height:1}
  .stat .l{font-size:9.5px;color:var(--mute);font-weight:500;margin-top:5px;text-transform:uppercase;letter-spacing:.5px}
  .stat.alert .n{color:var(--red)}
  .stats{display:none}
  /* toolbar */
  .tool{position:sticky;top:52px;z-index:20;background:var(--bg);padding:14px 0 10px;margin-top:4px;border-bottom:1px solid var(--line)}
  .srch{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:14px;background:var(--card)}
  .srch:focus{outline:none;border-color:var(--brand);box-shadow:inset 0 0 0 1px var(--brand)}
  .chips{display:flex;gap:6px;margin-top:9px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .chips::-webkit-scrollbar{display:none}
  .chip{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:3px;padding:7px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;flex:0 0 auto}
  .chip .c{color:var(--mute);font-weight:600;margin-left:5px;font-family:var(--mono)}
  .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}.chip.on .c{color:#B9B3A9}
  .chip.on.red{background:var(--red);border-color:var(--red)} .chip.on.amber{background:var(--amber);border-color:var(--amber)} .chip.on.green{background:var(--green);border-color:var(--green)}
  .sortrow{display:flex;gap:8px;align-items:center;margin-top:9px;font-size:11px;color:var(--mute);text-transform:uppercase;letter-spacing:.5px}
  .seg{display:inline-flex;background:var(--card);border:1px solid var(--line);border-radius:3px;overflow:hidden}
  .seg button{border:0;background:transparent;padding:7px 12px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;color:var(--mute);border-left:1px solid var(--line)}
  .seg button:first-child{border-left:0}
  .seg button.on{background:var(--ink);color:#fff}
  /* debtor row-card */
  .cx{background:var(--card);border:1px solid var(--line);padding:13px 15px;margin-bottom:-1px}
  .cx.paid{background:var(--soft);opacity:.72}
  .cx .r1{display:flex;align-items:flex-start;gap:10px}
  .cx .nm{font-size:15px;font-weight:600;line-height:1.2;flex:1;min-width:0}
  .cx .spill{flex:0 0 auto;font-size:10px;font-weight:600;padding:3px 8px;border-radius:2px;text-transform:uppercase;letter-spacing:.4px}
  .spill.Called{background:var(--blue-bg);color:var(--mail)} .spill.Promised{background:var(--amber-bg);color:var(--amber)}
  .spill.No{background:var(--red-bg);color:var(--red)} .spill.Paid{background:var(--green-bg);color:var(--green)}
  .cx .r2{display:flex;align-items:baseline;gap:10px;margin-top:8px;flex-wrap:wrap}
  .cx .amt{font-family:var(--mono);font-size:19px;font-weight:600;letter-spacing:-.2px}
  .pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:3px 8px;border-radius:2px}
  .pill.red{background:var(--red-bg);color:var(--red)} .pill.amber{background:var(--amber-bg);color:var(--amber)} .pill.green{background:var(--green-bg);color:var(--green)}
  .pill.ready{background:#0F7A34;color:#fff}
  .cx.isready{border-left:3px solid #0F7A34}
  .rtag{flex:0 0 auto;font-size:9.5px;font-weight:800;color:#0F7A34;background:var(--green-bg);border:1px solid #BFE0CB;border-radius:3px;padding:1px 6px;text-transform:uppercase;letter-spacing:.04em}
  .invlink{margin-left:auto;background:none;border:0;color:var(--mail);font-weight:600;font-size:12px;cursor:pointer;font-family:inherit;padding:3px 2px;white-space:nowrap}
  .acts{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
  .act{flex:1;min-width:120px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:3px;padding:11px 14px;font-size:13.5px;font-weight:600;text-decoration:none;border:0;cursor:pointer;font-family:inherit;letter-spacing:.3px}
  .act.call{background:var(--green);color:#fff}
  .act.wa{background:#0F7A34;color:#fff}
  .act.mail{background:var(--card);border:1px solid var(--line);color:var(--mail)}
  .act.edit{flex:0 0 auto;min-width:0;background:var(--card);border:1px solid var(--line);color:var(--mute);padding:11px 14px}
  .phrow{display:flex;gap:8px;margin-top:12px}
  .phrow input{flex:1;padding:10px 12px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:14px;background:var(--soft)}
  .phrow input:focus{outline:none;border-color:var(--brand)}
  .phrow .save{flex:0 0 auto;background:var(--brand);color:#fff;border:0;border-radius:3px;padding:0 18px;font-weight:600;font-size:13px;cursor:pointer;font-family:inherit;letter-spacing:.3px}
  .telno{font-family:var(--mono);font-size:12px;color:var(--mute);margin-top:7px}
  .schips{display:flex;gap:6px;margin-top:12px;flex-wrap:wrap}
  .sc{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:3px;padding:8px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;flex:1;min-width:64px}
  .sc.on{color:#fff}
  .sc.on[data-s="Called"]{background:var(--mail);border-color:var(--mail)}
  .sc.on[data-s="Promised"]{background:var(--amber);border-color:var(--amber)}
  .sc.on[data-s="No answer"]{background:var(--red);border-color:var(--red)}
  .sc.on[data-s="Paid"]{background:var(--green);border-color:var(--green)}
  .nt{width:100%;margin-top:10px;min-height:40px;padding:10px 12px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:13.5px;resize:vertical;background:var(--soft)}
  .nt:focus{outline:none;border-color:var(--brand)}
  .dt{margin-top:10px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--mute);font-weight:500}
  .dt input{padding:9px 11px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:13.5px;background:var(--soft)}
  .dt input:focus{outline:none;border-color:var(--brand)}
  .invbox{margin-top:11px;border-top:1px solid var(--hair);padding-top:9px}
  .inv{padding:7px 0;border-bottom:1px solid var(--hair);font-size:12.5px}
  .inv:last-child{border-bottom:0}
  .invtop{display:flex;align-items:center;gap:8px}
  .inv .num{font-family:var(--mono);font-weight:500;flex:0 0 auto}
  .inv .dd{color:var(--mute);font-size:11.5px}
  .inv .ia{margin-left:auto;font-family:var(--mono);font-weight:500;white-space:nowrap}
  .iabout{color:var(--mute);font-size:11.5px;margin-top:4px;line-height:1.35}
  .ivv{flex:0 0 auto;margin-left:10px;color:var(--mail);font-weight:600;font-size:11.5px;background:var(--card);cursor:pointer;border:1px solid var(--line);border-radius:3px;padding:4px 10px;font-family:inherit}
  .ivv:hover{background:var(--blue-bg)}
  /* invoice detail popup */
  .modal{position:fixed;inset:0;background:rgba(20,16,12,.55);display:none;z-index:200;overflow:auto;padding:16px}
  .modal.open{display:block}
  .mcard{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);max-width:640px;margin:36px auto}
  .mhead{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--card)}
  .mhead .mt{font-family:var(--mono);font-weight:600;font-size:15px}
  .mx{margin-left:auto;background:var(--card);border:1px solid var(--line);border-radius:3px;width:30px;height:30px;cursor:pointer;font-size:16px;font-family:inherit;color:var(--mute);line-height:1}
  .mbody{padding:16px 18px}
  .mmeta{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:var(--mute);margin-bottom:14px}
  .mtbl{width:100%;border-collapse:collapse;font-size:12.5px}
  .mtbl th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--mute);padding:6px 8px;border-bottom:1px solid var(--line)}
  .mtbl td{padding:8px 8px;border-bottom:1px solid var(--hair);vertical-align:top}
  .mtbl td.r{text-align:right;font-family:var(--mono);white-space:nowrap}
  .mtot{display:flex;justify-content:space-between;margin-top:12px;font-weight:600;font-size:14px}
  .mtot .mono{font-family:var(--mono)}
  .saveddot{font-size:11px;color:var(--green);font-weight:600;margin-left:6px;opacity:0;transition:opacity .2s}
  .saveddot.show{opacity:1}
  .bar{position:fixed;top:0;left:0;height:2px;background:var(--brand);width:0;transition:width .2s,opacity .3s;opacity:0;z-index:999}
  .pgfoot{text-align:center;padding:22px 12px 28px}.pgfoot .cn{font-size:11.5px;color:var(--mute)}.pgfoot .sc2{font-size:10px;color:var(--mute);margin-top:4px;letter-spacing:1px;text-transform:uppercase}
  .empty{background:var(--card);border:1px solid var(--line);padding:26px 16px;text-align:center;color:var(--mute);font-weight:500}
  /* login */
  .login{max-width:360px;margin:9vh auto 0;padding:0 18px}
  .lcard{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:28px 26px}
  .login .lb{width:40px;height:40px;border-radius:3px;background:var(--brand);display:grid;place-items:center;font-weight:700;color:#fff;font-size:14px;margin-bottom:16px;font-family:var(--mono)}
  .login h2{margin:0 0 4px;font-size:19px;font-weight:600}
  .login .in{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:14.5px;margin-top:11px;background:var(--soft)}
  .login .in:focus{outline:none;border-color:var(--brand)}
  .login .go{width:100%;margin-top:16px;border:0;background:var(--brand);color:#fff;padding:13px;border-radius:3px;font-weight:600;font-size:14.5px;cursor:pointer;font-family:inherit;letter-spacing:.4px}
  .login .err{background:var(--red-bg);color:#9B2016;border-radius:3px;padding:10px 12px;font-size:12.5px;margin-top:12px}
  /* list frame + full-width columns on computer */
  #gList{border:1px solid var(--line);border-bottom:0;background:var(--card)}
  @media(min-width:900px){ #gList{display:grid;grid-template-columns:repeat(auto-fill,minmax(420px,1fr));border:0;background:transparent} .cx{border:1px solid var(--line);margin:0 -1px -1px 0} }
  @media(max-width:520px){ .hero{gap:16px} .hero .hr{margin-left:0;width:100%} .stat{padding:2px 16px} .hero .ht{font-size:24px} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>Collections</h1><div class="sub">WAITARA HOLDINGS GROUP</div></div>
  <?php if ($gAuthed): ?>
  <div class="sp"><button class="tbtn" onclick="load(true)">Refresh</button><a class="tbtn" href="index.php?portal=grace&logout=1">Sign out</a></div>
  <?php endif; ?>
</div>
<?php if (!$gAuthed): ?>
<div class="login">
  <div class="lcard">
    <div class="lb">912</div>
    <h2>Welcome</h2>
    <div class="muted" style="font-size:13.5px">Sign in to chase today's unpaid invoices.</div>
    <?php if ($gDisabled): ?>
      <div class="err">This portal is currently disabled. Please contact the administrator.</div>
    <?php elseif (empty($gAccounts)): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a username and password (Settings → Portals).</div>
    <?php else: ?>
    <form method="post" action="index.php?portal=grace" autocomplete="off">
      <input type="hidden" name="grace_login" value="1">
      <input class="in" type="text" name="u" placeholder="Username" autocapitalize="none" autocorrect="off" required>
      <input class="in" type="password" name="p" placeholder="Password" required>
      <?php if ($gErr): ?><div class="err"><?= $gEsc($gErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in →</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc2">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap" id="app"><div class="empty">Loading today's unpaid invoices…</div></div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Collections</div><div class="sc2">CONFIDENTIAL</div></div>
<div id="invModal" class="modal" onclick="if(event.target===this)closeInv()">
  <div class="mcard">
    <div class="mhead"><span class="mt" id="invTitle">Invoice</span><button class="mx" onclick="closeInv()">×</button></div>
    <div class="mbody" id="invBody"></div>
  </div>
</div>
<script>
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const att=s=>esc(s).replace(/'/g,'&#39;');
const jsq=s=>String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
const fmtC=(cur,n)=>(cur||'')+' '+Math.round(n||0).toLocaleString('en-US');
const fmtMap=m=>{const k=Object.keys(m||{});if(!k.length)return '—';return k.sort().map(c=>fmtC(c,m[c])).join('  ·  ');};
let DATA=null, Q='', SORT='overdue', FILTER='all', EXP={}, EDITPH={};
function bar(s){const b=document.getElementById('bar');if(!b)return;if(s){b.style.opacity='1';b.style.width='80%';}else{b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);}}
async function load(refresh){bar(true);try{const r=await fetch('index.php?portal=grace&data=1'+(refresh?'&refresh=1':''),{credentials:'same-origin'});DATA=await r.json();}catch(e){DATA={ok:false,error:String(e)};}bar(false);render();}
/* KE number → full international digits for wa.me */
function waNum(ph){let s=(ph||'').replace(/[^0-9]/g,'');if(!s)return '';if(s.startsWith('254'))return s;if(s.startsWith('0'))return '254'+s.slice(1);if(s.length===9&&(s[0]==='7'||s[0]==='1'))return '254'+s;return s;}
function telNum(ph){return (ph||'').replace(/[^0-9+]/g,'');}
function ovdClass(d){return d>=30?'red':(d>0?'amber':'green');}
function cust(cid){return (DATA.customers||[]).find(x=>x.cid===cid);}
let saveT={};
function persist(cid,dot){
  const c=cust(cid);if(!c)return;
  clearTimeout(saveT[cid]);
  saveT[cid]=setTimeout(async()=>{
    try{const r=await fetch('index.php?portal=grace&save=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({customer_id:cid,customer_name:c.customer,status:c.status||'',note:c.note||'',promised_date:c.promised||'',phone:c.phone||''})});
      const j=await r.json();if(j.ok&&dot){const b=document.getElementById(dot);if(b){b.classList.add('show');setTimeout(()=>b.classList.remove('show'),1400);}}}catch(e){}
  },450);
}
function onNote(cid,v,dot){const c=cust(cid);if(c){c.note=v;persist(cid,dot);}}
function onPromised(cid,v,dot){const c=cust(cid);if(c){c.promised=v;persist(cid,dot);}}
function tapStatus(cid,v){const c=cust(cid);if(!c)return;c.status=(c.status===v?'':v);persist(cid);renderList();}
function toggleInv(cid){EXP[cid]=!EXP[cid];renderList();}
function openPhone(cid){EDITPH[cid]=true;renderList();const i=document.getElementById('ph_'+cid);if(i){i.focus();}}
function savePhone(cid){const i=document.getElementById('ph_'+cid);if(!i)return;const c=cust(cid);if(!c)return;c.phone=i.value.trim();EDITPH[cid]=false;persist(cid);renderList();}
function setSort(s){SORT=s;renderList();}
function setFilter(f){FILTER=f;renderList();}
function closeInv(){const m=document.getElementById('invModal');if(m)m.classList.remove('open');}
async function openInv(id){
  const m=document.getElementById('invModal'),b=document.getElementById('invBody'),t=document.getElementById('invTitle');
  if(!m)return; m.classList.add('open'); t.textContent='Invoice'; b.innerHTML='<div class="muted" style="padding:8px">Loading invoice…</div>';
  try{
    const r=await fetch('index.php?portal=grace&inv='+encodeURIComponent(id),{credentials:'same-origin'});
    const j=await r.json();
    if(!j||!j.ok){ b.innerHTML='<div style="padding:8px;color:var(--red)">'+esc((j&&j.error)||'Could not load this invoice.')+'</div>'; return; }
    const iv=j.invoice; t.textContent=iv.number||'Invoice';
    const rows=(iv.items||[]).map(it=>`<tr>
        <td><div style="color:var(--ink);font-weight:600">${esc(it.name||it.desc||'—')}</div>${(it.name&&it.desc)?`<div class="muted" style="font-size:11px;margin-top:2px">${esc(it.desc)}</div>`:''}</td>
        <td class="r">${it.qty?(+it.qty).toLocaleString('en-US'):''}</td>
        <td class="r">${it.rate?fmtC(iv.currency,it.rate):''}</td>
        <td class="r">${fmtC(iv.currency,it.amt)}</td></tr>`).join('');
    b.innerHTML=`
      <div class="mmeta"><span><b style="color:var(--ink)">${esc(iv.customer||'')}</b></span><span>Date: ${esc(iv.date||'—')}</span><span>Due: ${esc(iv.due||'—')}</span>${iv.status?`<span>Status: ${esc(iv.status)}</span>`:''}</div>
      ${rows?`<table class="mtbl"><thead><tr><th>Item / description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>${rows}</tbody></table>`:'<div class="muted">No line items recorded on this invoice.</div>'}
      <div class="mtot"><span>Invoice total</span><span class="mono">${fmtC(iv.currency,iv.total)}</span></div>
      <div class="mtot" style="color:var(--red)"><span>Balance due</span><span class="mono">${fmtC(iv.currency,iv.balance)}</span></div>
      ${iv.notes?`<div class="muted" style="margin-top:14px;font-size:12px"><b style="color:var(--ink)">Notes:</b> ${esc(iv.notes)}</div>`:''}`;
  }catch(e){ b.innerHTML='<div style="padding:8px;color:var(--red)">Error: '+esc(String(e))+'</div>'; }
}
function matchFilter(c){
  if(FILTER==='urgent')return (c.maxOverdue||0)>=30;
  if(FILTER==='todo')return !c.status && c.status!=='Paid';
  if(FILTER==='promised')return c.status==='Promised';
  if(FILTER==='paid')return c.status==='Paid';
  if(FILTER==='nophone')return !(c.phone);
  if(FILTER==='ready')return (c.readyCount||0)>0;
  return true;
}
function render(){
  const app=document.getElementById('app');if(!DATA)return;
  if(DATA.ok===false){app.innerHTML='<div class="empty" style="color:var(--red)">Couldn\'t load: '+esc(DATA.error||'failed')+'</div>';return;}
  const cs=DATA.customers||[];
  const nUrgent=cs.filter(c=>(c.maxOverdue||0)>=30).length;
  const nDone=cs.filter(c=>c.status==='Paid').length;
  const nContacted=cs.filter(c=>c.status&&c.status!=='Paid').length;
  const toChase=cs.length-nDone;
  app.innerHTML=`
    <div class="hero">
      <div>
        <div class="hl">Total outstanding</div>
        <div class="ht">${esc(fmtMap(DATA.totalByCur))}</div>
        <div class="hs">${toChase} customer${toChase===1?'':'s'} to chase · ${DATA.invCount||0} unpaid invoices</div>
      </div>
      <div class="hr">
        <div class="stat alert"><div class="n">${nUrgent}</div><div class="l">30+ days late</div></div>
        <div class="stat"><div class="n">${nContacted}</div><div class="l">Followed up</div></div>
        <div class="stat"><div class="n">${nDone}</div><div class="l">Paid</div></div>
      </div>
    </div>
    <div class="tool">
      <input class="srch" type="text" placeholder="Search a customer…" value="${att(Q)}" oninput="Q=this.value.toLowerCase();renderList()">
      <div class="chips" id="fchips"></div>
      <div class="sortrow">Sort:
        <div class="seg">
          <button data-k="overdue" class="${SORT==='overdue'?'on':''}" onclick="setSort('overdue')">Most overdue</button>
          <button data-k="owed" class="${SORT==='owed'?'on':''}" onclick="setSort('owed')">Biggest amount</button>
        </div>
      </div>
    </div>
    <div id="gList"></div>
    <div class="muted" style="font-size:11.5px;text-align:center;margin-top:14px">Updated ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'} · your notes are private to this portal and don't change Zoho</div>`;
  renderList();
}
function renderChips(){
  const box=document.getElementById('fchips');if(!box||!DATA)return;
  const cs=DATA.customers||[];
  const defs=[
    {k:'all',label:'All',cls:'',n:cs.length},
    {k:'ready',label:'✅ Ready to collect',cls:'green',n:cs.filter(c=>(c.readyCount||0)>0).length},
    {k:'urgent',label:'30+ days late',cls:'red',n:cs.filter(c=>(c.maxOverdue||0)>=30).length},
    {k:'todo',label:'To contact',cls:'',n:cs.filter(c=>!c.status).length},
    {k:'promised',label:'Promised',cls:'amber',n:cs.filter(c=>c.status==='Promised').length},
    {k:'nophone',label:'No number',cls:'',n:cs.filter(c=>!c.phone).length},
    {k:'paid',label:'Paid',cls:'green',n:cs.filter(c=>c.status==='Paid').length},
  ];
  box.innerHTML=defs.map(d=>`<button class="chip ${FILTER===d.k?'on '+d.cls:''}" onclick="setFilter('${d.k}')">${d.label}<span class="c">${d.n}</span></button>`).join('');
}
function renderList(){
  renderChips();
  const box=document.getElementById('gList');if(!box||!DATA)return;
  let list=(DATA.customers||[]).slice();
  if(SORT==='owed')list.sort((a,b)=>(b.sortTotal||0)-(a.sortTotal||0));
  else list.sort((a,b)=>(b.maxOverdue||0)-(a.maxOverdue||0)||(b.sortTotal||0)-(a.sortTotal||0));
  if(Q)list=list.filter(c=>((c.customer||'')+' '+(c.phone||'')).toLowerCase().indexOf(Q)>=0);
  list=list.filter(matchFilter);
  if(!list.length){box.innerHTML='<div class="empty">Nothing here 🎉 — try a different filter.</div>';return;}
  box.innerHTML=list.map(renderCard).join('');
}
function renderCard(c){
  const cid=c.cid, dot='d_'+cid;
  const ov=c.maxOverdue||0, oc=ovdClass(ov);
  const daysPill=ov>0?`<span class="pill ${oc}">${ov} days late</span>`:`<span class="pill green">On time</span>`;
  const spill=c.status?`<span class="spill ${c.status==='No answer'?'No':c.status}">${esc(c.status)}</span>`:'';
  const hasPhone=!!(c.phone);
  const tel=telNum(c.phone), wa=waNum(c.phone);
  let contact;
  if(EDITPH[cid]||(!hasPhone)){
    contact=`<div class="phrow">
      <input id="ph_${att(cid)}" type="tel" inputmode="tel" placeholder="Add phone e.g. 0712 345678" value="${att(c.phone||'')}" onkeydown="if(event.key==='Enter')savePhone('${jsq(cid)}')">
      <button class="save" onclick="savePhone('${jsq(cid)}')">Save</button>
    </div>`;
  } else {
    contact=`<div class="acts">
      <a class="act call" href="tel:${att(tel)}">Call</a>
      ${wa?`<a class="act wa" href="https://wa.me/${att(wa)}" target="_blank" rel="noopener">WhatsApp</a>`:''}
      <button class="act edit" title="Edit number" onclick="openPhone('${jsq(cid)}')">Edit</button>
    </div><div class="telno">${esc(c.phone)}</div>`;
  }
  const mailBtn=(!hasPhone && c.email)?`<a class="act mail" style="margin-top:9px;flex:1" href="mailto:${att(c.email)}">Email ${esc(c.email)}</a>`:'';
  const STAT=['Called','Promised','No answer','Paid'];
  const schips=STAT.map(s=>`<button class="sc ${c.status===s?'on':''}" data-s="${s}" onclick="tapStatus('${jsq(cid)}','${s}')">${s}</button>`).join('');
  const promised=(c.status==='Promised')?`<div class="dt">Promised to pay by <input type="date" value="${att(c.promised||'')}" onchange="onPromised('${jsq(cid)}',this.value,'${dot}')"></div>`:'';
  const invBtn=`<button class="invlink" onclick="toggleInv('${jsq(cid)}')">${c.count} invoice${c.count===1?'':'s'} ${EXP[cid]?'▴':'▾'}</button>`;
  const invBox=EXP[cid]?`<div class="invbox">${c.invoices.map(iv=>`<div class="inv">
      <div class="invtop"><span class="num">${esc(iv.number)}</span>${iv.ready?`<span class="rtag">● Ready</span>`:''}<span class="dd">due ${esc(iv.due||'—')}${iv.overdue>0?' · '+iv.overdue+'d late':''}</span><span class="ia">${esc(fmtC(iv.currency,iv.balance))}</span>${iv.id?`<button class="ivv" onclick="openInv('${jsq(iv.id)}')">Details</button>`:''}</div>
      ${iv.about?`<div class="iabout">${esc(iv.about)}</div>`:''}
    </div>`).join('')}</div>`:'';
  const readyPill=(c.readyCount||0)>0?`<span class="pill ready">● ${c.readyCount} ready to collect</span>`:'';
  return `<div class="cx ${c.status==='Paid'?'paid':''} ${(c.readyCount||0)>0?'isready':''}">
    <div class="r1"><div class="nm">${esc(c.customer||'(unnamed)')}</div>${readyPill}${spill}</div>
    <div class="r2"><div class="amt">${esc(fmtMap(c.totalByCur))}</div>${daysPill}${invBtn}</div>
    ${invBox}
    ${contact}
    ${mailBtn}
    <div class="schips">${schips}</div>
    ${promised}
    <textarea class="nt" placeholder="Notes — what they said, when to call back…" oninput="onNote('${jsq(cid)}',this.value,'${dot}')">${esc(c.note||'')}</textarea>
    <span id="${dot}" class="saveddot">saved ✓</span>
  </div>`;
}
load(false);
</script>
<?php endif; ?>
</body></html>
    <?php
    exit;
}
