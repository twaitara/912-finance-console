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
    /* All unpaid invoices grouped by customer, with cached phone + overlaid notes. */
    function gp_build($force) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/grace_unpaid_v1.json';
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
    $gCreds = null;
    if (is_file($gAuthFile)) { $j = json_decode(@file_get_contents($gAuthFile), true); if (is_array($j) && !empty($j['user']) && !empty($j['hash'])) $gCreds = ['user'=>$j['user'], 'hash'=>$j['hash']]; }
    $gDisabled = !empty(gp_prefs($gDir)['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=grace'); exit; }

    $gErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grace_login'])) {
        $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
        $ok = false;
        if ($gCreds && !$gDisabled) $ok = hash_equals(strtolower($gCreds['user']), strtolower($u)) && password_verify($p, $gCreds['hash']);
        if ($ok) { session_regenerate_id(true); $_SESSION['grace_auth'] = true; $_SESSION['grace_user'] = $u; header('Location: index.php?portal=grace'); exit; }
        $gErr = $gDisabled ? 'This portal is currently disabled.' : 'Wrong username or password.';
    }
    $gAuthed = !$gDisabled && !empty($_SESSION['grace_auth']);

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try { echo json_encode(gp_build(isset($_GET['refresh']))); }
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
            // if the cached dataset exists, patch this customer so the change shows on next load without a full rebuild
            echo json_encode(['ok'=>true]);
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
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --ink:#241C15;--mute:#8C8178;--line:#ECE4DB;--bg:#F5F1EC;--card:#FFFFFF;
    --brand:#F56F00;--brand2:#FF8B29;--brandink:#5A2A00;
    --call:#17A34A;--wa:#1EBE5D;--mail:#2563EB;
    --red:#E23D28;--amber:#E08A00;--green:#17A34A;
    --red-bg:#FCEBE8;--amber-bg:#FDF3E2;--green-bg:#E7F6EC;--blue-bg:#EAF1FE;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'Plus Jakarta Sans',system-ui,'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:14px;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
  .muted{color:var(--mute)}
  /* header */
  .top{background:linear-gradient(120deg,#2A2018,#191310);color:#fff;padding:13px 16px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:50;box-shadow:0 4px 20px rgba(0,0,0,.18)}
  .b{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;font-weight:800;font-size:13px;color:#fff;flex:0 0 auto;box-shadow:0 4px 12px rgba(245,111,0,.4)}
  .top h1{font-size:15px;margin:0;font-weight:800;letter-spacing:.2px;line-height:1.1}
  .top .sub{font-size:10.5px;color:#C7B8A8;letter-spacing:.3px;margin-top:2px;font-weight:500}
  .top .sp{margin-left:auto;display:flex;gap:8px}
  .tbtn{border:1px solid rgba(255,255,255,.18);color:#fff;background:rgba(255,255,255,.08);border-radius:10px;padding:8px 13px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap}
  .tbtn:hover{background:rgba(255,255,255,.18)}
  .wrap{max-width:860px;margin:0 auto;padding:16px 14px 40px}
  /* hero */
  .hero{background:linear-gradient(130deg,var(--brand),var(--brand2));color:#fff;border-radius:20px;padding:18px 20px;box-shadow:0 10px 30px rgba(245,111,0,.28);position:relative;overflow:hidden}
  .hero:before{content:"";position:absolute;right:-40px;top:-40px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.12)}
  .hero .hl{font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;opacity:.85}
  .hero .ht{font-size:30px;font-weight:800;line-height:1.05;margin-top:4px;letter-spacing:-.5px}
  .hero .hs{font-size:12.5px;margin-top:8px;opacity:.95;font-weight:500}
  .stats{display:flex;gap:9px;margin:12px 0 4px;flex-wrap:wrap}
  .stat{flex:1;min-width:92px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:11px 13px}
  .stat .n{font-size:20px;font-weight:800;line-height:1}
  .stat .l{font-size:10.5px;color:var(--mute);font-weight:600;margin-top:4px}
  .stat.alert .n{color:var(--red)}
  /* toolbar */
  .tool{position:sticky;top:64px;z-index:20;background:var(--bg);padding:12px 0 8px;margin-top:6px}
  .srch{width:100%;padding:13px 15px;border:1.5px solid var(--line);border-radius:13px;font-family:inherit;font-size:15px;background:var(--card)}
  .srch:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(245,111,0,.12)}
  .chips{display:flex;gap:7px;margin-top:9px;overflow-x:auto;padding-bottom:3px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .chips::-webkit-scrollbar{display:none}
  .chip{border:1.5px solid var(--line);background:var(--card);color:var(--ink);border-radius:30px;padding:8px 14px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;flex:0 0 auto;transition:.12s}
  .chip .c{opacity:.6;font-weight:700;margin-left:4px}
  .chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
  .chip.on.red{background:var(--red);border-color:var(--red)} .chip.on.amber{background:var(--amber);border-color:var(--amber)} .chip.on.green{background:var(--green);border-color:var(--green)}
  .sortrow{display:flex;gap:8px;align-items:center;margin-top:9px;font-size:12px;color:var(--mute)}
  .seg{display:inline-flex;background:var(--card);border:1.5px solid var(--line);border-radius:11px;overflow:hidden}
  .seg button{border:0;background:transparent;padding:8px 13px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--mute)}
  .seg button.on{background:var(--ink);color:#fff}
  /* call card */
  .cx{background:var(--card);border:1px solid var(--line);border-left:5px solid var(--line);border-radius:16px;padding:14px 15px;margin-bottom:12px;box-shadow:0 2px 8px rgba(40,30,20,.04)}
  .cx.paid{opacity:.62}
  .cx .r1{display:flex;align-items:flex-start;gap:10px}
  .cx .nm{font-size:16.5px;font-weight:800;line-height:1.15;flex:1;min-width:0}
  .cx .spill{flex:0 0 auto;font-size:11px;font-weight:800;padding:4px 10px;border-radius:30px}
  .spill.Called{background:var(--blue-bg);color:var(--mail)} .spill.Promised{background:var(--amber-bg);color:var(--amber)}
  .spill.No{background:var(--red-bg);color:var(--red)} .spill.Paid{background:var(--green-bg);color:var(--green)}
  .cx .r2{display:flex;align-items:baseline;gap:10px;margin-top:9px;flex-wrap:wrap}
  .cx .amt{font-size:23px;font-weight:800;letter-spacing:-.4px;font-variant-numeric:tabular-nums}
  .cx .amt .sub{font-size:12px;font-weight:700;color:var(--mute);margin-left:6px}
  .pill{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:30px}
  .pill.red{background:var(--red-bg);color:var(--red)} .pill.amber{background:var(--amber-bg);color:var(--amber)} .pill.green{background:var(--green-bg);color:var(--green)}
  .invlink{margin-left:auto;background:none;border:0;color:var(--mute);font-weight:700;font-size:12px;cursor:pointer;font-family:inherit;padding:4px 2px;white-space:nowrap}
  .acts{display:flex;gap:9px;margin-top:13px;flex-wrap:wrap}
  .act{flex:1;min-width:120px;display:inline-flex;align-items:center;justify-content:center;gap:7px;border-radius:13px;padding:13px 14px;font-size:14.5px;font-weight:800;text-decoration:none;border:0;cursor:pointer;font-family:inherit}
  .act.call{background:var(--call);color:#fff;box-shadow:0 4px 12px rgba(23,163,74,.28)}
  .act.wa{background:var(--wa);color:#fff;box-shadow:0 4px 12px rgba(30,190,93,.28)}
  .act.mail{background:var(--blue-bg);color:var(--mail)}
  .act.ghost{background:#F3EEE8;color:var(--ink);font-size:13.5px}
  .act.edit{flex:0 0 auto;min-width:0;background:#F3EEE8;color:var(--mute);padding:13px 15px}
  .phrow{display:flex;gap:8px;margin-top:13px}
  .phrow input{flex:1;padding:12px 14px;border:1.5px solid var(--line);border-radius:12px;font-family:inherit;font-size:15px;background:#FCFAF8}
  .phrow input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(245,111,0,.12)}
  .phrow .save{flex:0 0 auto;background:var(--brand);color:#fff;border:0;border-radius:12px;padding:0 18px;font-weight:800;font-size:14px;cursor:pointer;font-family:inherit}
  .schips{display:flex;gap:7px;margin-top:13px;flex-wrap:wrap}
  .sc{border:1.5px solid var(--line);background:var(--card);color:var(--ink);border-radius:11px;padding:9px 13px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;flex:1;min-width:64px}
  .sc.on{color:#fff}
  .sc.on[data-s="Called"]{background:var(--mail);border-color:var(--mail)}
  .sc.on[data-s="Promised"]{background:var(--amber);border-color:var(--amber)}
  .sc.on[data-s="No answer"]{background:var(--red);border-color:var(--red)}
  .sc.on[data-s="Paid"]{background:var(--green);border-color:var(--green)}
  .nt{width:100%;margin-top:11px;min-height:42px;padding:11px 13px;border:1.5px solid var(--line);border-radius:12px;font-family:inherit;font-size:14px;resize:vertical;background:#FCFAF8}
  .nt:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(245,111,0,.12)}
  .dt{margin-top:11px;display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--mute);font-weight:600}
  .dt input{padding:10px 12px;border:1.5px solid var(--line);border-radius:11px;font-family:inherit;font-size:14px;background:#FCFAF8}
  .dt input:focus{outline:none;border-color:var(--brand)}
  .invbox{margin-top:12px;border-top:1px dashed var(--line);padding-top:10px}
  .inv{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #F4EEE7;font-size:13px}
  .inv:last-child{border-bottom:0}
  .inv .num{font-weight:700;flex:0 0 auto}
  .inv .dd{color:var(--mute);font-size:11.5px}
  .inv .ia{margin-left:auto;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap}
  .saveddot{font-size:11px;color:var(--green);font-weight:800;margin-left:6px;opacity:0;transition:opacity .2s}
  .saveddot.show{opacity:1}
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--brand);width:0;transition:width .2s,opacity .3s;opacity:0;z-index:999}
  .pgfoot{text-align:center;padding:20px 12px 28px}.pgfoot .cn{font-size:12px;color:var(--mute);font-weight:600}.pgfoot .sc2{font-size:11px;color:var(--brand);margin-top:4px;font-weight:700;letter-spacing:.5px}
  .empty{background:var(--card);border:1px dashed var(--line);border-radius:16px;padding:28px 16px;text-align:center;color:var(--mute);font-weight:600}
  /* login */
  .login{max-width:380px;margin:8vh auto 0;padding:0 18px}
  .lcard{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:26px 24px;box-shadow:0 10px 30px rgba(40,30,20,.08)}
  .login .lb{width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;font-weight:800;color:#fff;font-size:16px;margin-bottom:14px;box-shadow:0 6px 16px rgba(245,111,0,.35)}
  .login h2{margin:0 0 4px;font-size:20px;font-weight:800}
  .login .in{width:100%;padding:13px 15px;border:1.5px solid var(--line);border-radius:12px;font-family:inherit;font-size:15px;margin-top:12px;background:#FCFAF8}
  .login .in:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 4px rgba(245,111,0,.12)}
  .login .go{width:100%;margin-top:16px;border:0;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;padding:14px;border-radius:12px;font-weight:800;font-size:15px;cursor:pointer;font-family:inherit;box-shadow:0 6px 16px rgba(245,111,0,.3)}
  .login .err{background:var(--red-bg);color:#B42318;border-radius:11px;padding:11px 13px;font-size:13px;margin-top:12px;font-weight:600}
  @media(max-width:520px){ .hero .ht{font-size:26px} .cx .amt{font-size:21px} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>Collections</h1><div class="sub">WAITARA HOLDINGS GROUP</div></div>
  <?php if ($gAuthed): ?>
  <div class="sp"><button class="tbtn" onclick="load(true)">⟳ Refresh</button><a class="tbtn" href="index.php?portal=grace&logout=1">Sign out</a></div>
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
    <?php elseif (!$gCreds): ?>
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
function matchFilter(c){
  if(FILTER==='urgent')return (c.maxOverdue||0)>=30;
  if(FILTER==='todo')return !c.status && c.status!=='Paid';
  if(FILTER==='promised')return c.status==='Promised';
  if(FILTER==='paid')return c.status==='Paid';
  if(FILTER==='nophone')return !(c.phone);
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
      <div class="hl">Total outstanding</div>
      <div class="ht">${esc(fmtMap(DATA.totalByCur))}</div>
      <div class="hs">${toChase} customer${toChase===1?'':'s'} to chase · ${DATA.invCount||0} unpaid invoices</div>
    </div>
    <div class="stats">
      <div class="stat alert"><div class="n">${nUrgent}</div><div class="l">30+ days late</div></div>
      <div class="stat"><div class="n">${nContacted}</div><div class="l">Followed up</div></div>
      <div class="stat"><div class="n">${nDone}</div><div class="l">Marked paid</div></div>
    </div>
    <div class="tool">
      <input class="srch" type="text" placeholder="🔍  Search a customer…" value="${att(Q)}" oninput="Q=this.value.toLowerCase();renderList()">
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
    {k:'urgent',label:'🔴 30+ days',cls:'red',n:cs.filter(c=>(c.maxOverdue||0)>=30).length},
    {k:'todo',label:'To contact',cls:'',n:cs.filter(c=>!c.status).length},
    {k:'promised',label:'⏳ Promised',cls:'amber',n:cs.filter(c=>c.status==='Promised').length},
    {k:'nophone',label:'☎️ No number',cls:'',n:cs.filter(c=>!c.phone).length},
    {k:'paid',label:'✅ Paid',cls:'green',n:cs.filter(c=>c.status==='Paid').length},
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
      <a class="act call" href="tel:${att(tel)}">📞 Call</a>
      ${wa?`<a class="act wa" href="https://wa.me/${att(wa)}" target="_blank" rel="noopener">💬 WhatsApp</a>`:''}
      <button class="act edit" title="Edit number" onclick="openPhone('${jsq(cid)}')">✏️</button>
    </div><div class="muted" style="font-size:11.5px;margin-top:6px">☎️ ${esc(c.phone)}</div>`;
  }
  const mailBtn=(!hasPhone && c.email)?`<a class="act mail" style="margin-top:9px" href="mailto:${att(c.email)}">✉️ Email ${esc(c.email)}</a>`:'';
  const STAT=['Called','Promised','No answer','Paid'];
  const schips=STAT.map(s=>`<button class="sc ${c.status===s?'on':''}" data-s="${s}" onclick="tapStatus('${jsq(cid)}','${s}')">${s}</button>`).join('');
  const promised=(c.status==='Promised')?`<div class="dt">📅 Promised to pay by <input type="date" value="${att(c.promised||'')}" onchange="onPromised('${jsq(cid)}',this.value,'${dot}')"></div>`:'';
  const invBtn=`<button class="invlink" onclick="toggleInv('${jsq(cid)}')">${c.count} invoice${c.count===1?'':'s'} ${EXP[cid]?'▴':'▾'}</button>`;
  const invBox=EXP[cid]?`<div class="invbox">${c.invoices.map(iv=>`<div class="inv"><span class="num">${esc(iv.number)}</span><span class="dd">due ${esc(iv.due||'—')}${iv.overdue>0?' · '+iv.overdue+'d':''}</span><span class="ia">${esc(fmtC(iv.currency,iv.balance))}</span></div>`).join('')}</div>`:'';
  return `<div class="cx ${c.status==='Paid'?'paid':''}" style="border-left-color:var(--${oc})">
    <div class="r1"><div class="nm">${esc(c.customer||'(unnamed)')}</div>${spill}</div>
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
