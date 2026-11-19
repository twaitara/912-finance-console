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
            email VARCHAR(190) DEFAULT '',
            contact_name VARCHAR(190) DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        /* migrate older tables that predate these columns */
        try { $pdo->exec("ALTER TABLE grace_notes ADD COLUMN phone VARCHAR(40) DEFAULT ''"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE grace_notes ADD COLUMN email VARCHAR(190) DEFAULT ''"); } catch (\Throwable $e) {}
        try { $pdo->exec("ALTER TABLE grace_notes ADD COLUMN contact_name VARCHAR(190) DEFAULT ''"); } catch (\Throwable $e) {}
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
                    'tax' => (string)($li['tax_name'] ?? ''), 'taxpc' => (float)($li['tax_percentage'] ?? 0),
                ];
            }
            $taxes = [];
            foreach (($iv['taxes'] ?? []) as $tx) { $taxes[] = ['name'=>(string)($tx['tax_name'] ?? ''), 'amount'=>(float)($tx['tax_amount'] ?? 0)]; }
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
                'sub_total' => (float)($iv['sub_total'] ?? 0), 'tax_total' => (float)($iv['tax_total'] ?? 0),
                'discount' => (float)($iv['discount_total'] ?? ($iv['discount'] ?? 0)),
                'shipping' => (float)($iv['shipping_charge'] ?? 0), 'adjustment' => (float)($iv['adjustment'] ?? 0),
                'inclusive' => !empty($iv['is_inclusive_tax']), 'taxes' => $taxes,
                'total' => (float)($iv['total'] ?? 0), 'balance' => (float)($iv['balance'] ?? 0),
                'notes' => (string)($iv['notes'] ?? ''), 'items' => $items, 'summary' => $summary,
            ];
        } catch (\Throwable $e) { return null; }
    }
    /* All unpaid invoices grouped by customer, with cached phone + overlaid notes. */
    function gp_build($force) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/grace_unpaid_v6.json';   // v6: every invoice carries 'bcy' (balance in KES at Zoho's rate)
        if (!$force && is_file($cache) && (time() - filemtime($cache) < 900)) { $j = json_decode(file_get_contents($cache), true); if (is_array($j)) { $j['cached'] = true; return $j; } }
        $cfg = zoho_config();

        // 1) all unpaid invoices
        $rows = []; $page = 1;
        do {
            [$d, $c] = zoho_api('GET', 'invoices', null, ['filter_by'=>'Status.Unpaid', 'per_page'=>200, 'page'=>$page, 'sort_column'=>'due_date']);
            if ($c >= 400) {
                // a brief Zoho refusal shouldn't blank Grace's page: serve the last good snapshot
                if (is_file($cache) && is_array($j = json_decode(@file_get_contents($cache), true))) {
                    $j['cached'] = true; $j['stale'] = true; $j['stale_at'] = date('c', filemtime($cache));
                    return $j;
                }
                throw new Exception($d['message'] ?? 'Zoho error (invoices)');
            }
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
                    'bcy'      => $bal * (((float)($inv['exchange_rate'] ?? 0)) > 0 ? (float)$inv['exchange_rate'] : 1),   // KES value at the invoice's Zoho rate
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
        try { $pdo = db(); gp_notes_table($pdo); foreach ($pdo->query("SELECT zoho_customer_id,status,note,promised_date,phone,email,contact_name FROM grace_notes")->fetchAll(PDO::FETCH_ASSOC) as $n) { $notes[(string)$n['zoho_customer_id']] = $n; } } catch (Exception $e) { /* no notes yet */ }

        // 5) compose
        $out = []; $totalByCur = []; $invCount = 0;
        foreach ($byCust as $g) {
            usort($g['invoices'], fn($a, $b) => strcmp($a['due'], $b['due']));   // most overdue first
            $n = ($g['cid'] !== '' && isset($notes[$g['cid']])) ? $notes[$g['cid']] : null;
            $savedPhone = $n ? trim((string)($n['phone'] ?? '')) : '';
            $savedEmail = $n ? trim((string)($n['email'] ?? '')) : '';
            $contactName = $n ? trim((string)($n['contact_name'] ?? '')) : '';
            $out[] = [
                'cid'=>$g['cid'], 'customer'=>$g['customer'], 'contactName'=>$contactName,
                'phone'=>$savedPhone !== '' ? $savedPhone : $g['phone'], 'mobile'=>$g['mobile'],
                'email'=>$savedEmail !== '' ? $savedEmail : ($g['email'] ?? ''),
                'savedPhone'=>$savedPhone, 'savedEmail'=>$savedEmail, 'hasZohoPhone'=>($g['phone'] !== '' || $g['mobile'] !== ''),
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
        $ready = []; $share = []; $confirm = [];
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_flags (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', ready TINYINT(1) DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS grace_share (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_confirm (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', confirmed_by VARCHAR(80) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($pdo->query("SELECT invoice_id FROM collect_flags WHERE ready=1")->fetchAll(PDO::FETCH_COLUMN) as $v) $ready[(string)$v] = true;
            foreach ($pdo->query("SELECT invoice_id FROM grace_share")->fetchAll(PDO::FETCH_COLUMN) as $v) $share[(string)$v] = true;
            foreach ($pdo->query("SELECT invoice_id FROM collect_confirm")->fetchAll(PDO::FETCH_COLUMN) as $v) $confirm[(string)$v] = true;
        } catch (\Throwable $e) { /* leave sets empty -> KES-only, no ready */ }
        /* sent-out ticks + per-invoice ETR (fiscalised) pushed from the office PC + statement-draft log */
        $sent = []; $etr = []; $etrAt = ''; $stmt = []; $picked = []; $hide = []; $hideClient = []; $hideOk = false;
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS grace_hide (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($pdo->query("SELECT invoice_id FROM grace_hide")->fetchAll(PDO::FETCH_COLUMN) as $v) $hide[(string)$v] = true;
            $pdo->exec("CREATE TABLE IF NOT EXISTS grace_hide_client (customer_key VARCHAR(190) PRIMARY KEY, customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($pdo->query("SELECT customer_key FROM grace_hide_client")->fetchAll(PDO::FETCH_COLUMN) as $v) $hideClient[(string)$v] = true;
            $hideOk = true;
        } catch (\Throwable $e) { /* handled below: fail closed */ }
        if (!$hideOk) throw new Exception('Could not check which invoices are hidden — please try again.');
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_sent (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_etr (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS bridge_meta (k VARCHAR(64) PRIMARY KEY, v VARCHAR(255) DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            gp_stmt_table($pdo);
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_picked (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach ($pdo->query("SELECT invoice_id FROM collect_picked")->fetchAll(PDO::FETCH_COLUMN) as $v) $picked[(string)$v] = true;
            foreach ($pdo->query("SELECT invoice_id FROM collect_sent")->fetchAll(PDO::FETCH_COLUMN) as $v) $sent[(string)$v] = true;
            foreach ($pdo->query("SELECT invoice_id FROM invoice_etr")->fetchAll(PDO::FETCH_COLUMN) as $v) $etr[(string)$v] = true;
            $etrAt = (string)($pdo->query("SELECT v FROM bridge_meta WHERE k='etr_synced_at'")->fetchColumn() ?: '');
            foreach ($pdo->query("SELECT customer_id, MAX(created_at) AS at FROM grace_statements GROUP BY customer_id")->fetchAll(PDO::FETCH_ASSOC) as $r) $stmt[(string)$r['customer_id']] = (string)$r['at'];
        } catch (\Throwable $e) { /* tags simply absent */ }

        /* Rebuild per-request so USD visibility (share) and ready flags are always current:
           KES invoices always shown; USD shown only when the invoice is shared to Grace. */
        $out = []; $gTotalByCur = []; $invCount = 0; $totalReady = 0; $amtByCur = [];
        /* company-wide total in KES: EVERY unpaid invoice (USD converted at its Zoho rate, plus any
           hidden ones) — Grace sees this one figure, but never the USD/hidden invoices themselves */
        $grandKES = 0; $grandN = 0; $fxN = 0; $fxKES = 0;
        foreach ($res['customers'] as $c0) { foreach ($c0['invoices'] as $iv0) {
            $v = (float)($iv0['bcy'] ?? $iv0['balance'] ?? 0); $grandKES += $v; $grandN++;
            if (strtoupper((string)($iv0['currency'] ?? 'KES')) !== 'KES') { $fxN++; $fxKES += $v; }
        } }
        $res['grandKES'] = round($grandKES, 2); $res['grandCount'] = $grandN; $res['fxCount'] = $fxN; $res['fxKES'] = round($fxKES, 2);
        foreach ($res['customers'] as $c) {
            if (isset($hideClient[mb_strtolower(trim((string)($c['customer'] ?? '')))])) continue;   // whole client hidden by the owner
            $inv = []; $tbc = []; $sortTotal = 0; $maxOv = 0; $rc = 0; $rbc = []; $fc = 0; $sc = 0;
            foreach ($c['invoices'] as $iv) {
                $cur = strtoupper((string)($iv['currency'] ?? 'KES'));
                if ($cur !== 'KES') continue;                               // only KES is ever shown to Grace
                if (isset($hide[(string)($iv['id'] ?? '')])) continue;        // hidden by the owner
                $bal = (float)($iv['balance'] ?? 0);
                unset($iv['bcy']);
                $iv['ready'] = isset($ready[(string)($iv['id'] ?? '')]);
                $iv['confirmed'] = isset($confirm[(string)($iv['id'] ?? '')]);
                $iv['picked'] = isset($picked[(string)($iv['id'] ?? '')]);
                $iv['sent'] = isset($sent[(string)($iv['id'] ?? '')]);
                $iv['fiscalised'] = isset($etr[(string)($iv['id'] ?? '')]);
                if ($iv['fiscalised']) $fc++;
                if ($iv['sent']) $sc++;
                if ($iv['ready']) { $rc++; $rbc[$cur] = ($rbc[$cur] ?? 0) + $bal; $amtByCur[$cur] = ($amtByCur[$cur] ?? 0) + $bal; }
                $tbc[$cur] = ($tbc[$cur] ?? 0) + $bal; $sortTotal += $bal;
                $ov = (int)($iv['overdue'] ?? 0); if ($ov > $maxOv) $maxOv = $ov;
                $inv[] = $iv;
            }
            if (!$inv) continue;   // customer has no visible invoices
            $c['invoices'] = $inv; $c['totalByCur'] = $tbc; $c['sortTotal'] = $sortTotal;
            $c['count'] = count($inv); $c['maxOverdue'] = $maxOv; $c['readyCount'] = $rc; $c['readyByCur'] = $rbc;
            $c['fiscalCount'] = $fc; $c['sentCount'] = $sc; $c['stmtAt'] = $stmt[(string)($c['cid'] ?? '')] ?? '';
            foreach ($tbc as $k => $v) $gTotalByCur[$k] = ($gTotalByCur[$k] ?? 0) + $v;
            $invCount += count($inv); $totalReady += $rc;
            $out[] = $c;
        }
        usort($out, fn($a, $b) => $b['sortTotal'] <=> $a['sortTotal']);
        $res['customers'] = $out; $res['totalByCur'] = $gTotalByCur; $res['custCount'] = count($out); $res['invCount'] = $invCount;
        $res['readyTotal'] = $totalReady; $res['readyAmountByCur'] = $amtByCur;
        $res['etrAsOf'] = $etrAt;
        return $res;
    }

    function gp_stmt_table($pdo) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS grace_statements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id VARCHAR(64) NOT NULL,
            customer_name VARCHAR(190) DEFAULT '',
            to_email VARCHAR(190) DEFAULT '',
            total DECIMAL(14,2) DEFAULT 0,
            created_by VARCHAR(80) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cust (customer_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /* Statement of account for ONE client, built server-side from the same data Grace sees
       (so it can never include an invoice she isn't allowed to see). Returns null if unknown. */
    function gp_statement($cid, $note = '') {
        $res = gp_apply_collect(gp_build(false));
        $c = null;
        foreach ($res['customers'] as $x) { if ((string)$x['cid'] === (string)$cid) { $c = $x; break; } }
        if (!$c) return null;
        $cfg = require __DIR__ . '/config.php';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $fmt = fn($n) => number_format((float)$n, 2);
        $name = (string)$c['customer'];
        $who  = trim((string)($c['contactName'] ?? ''));
        $note = trim((string)$note);
        $intro = $note !== '' ? $note
            : ('Dear ' . ($who !== '' ? $who : 'Sir/Madam') . ",\n\nPlease find below your statement of account showing the invoices pending payment as at " . date('j F Y') . ". Kindly arrange settlement, and let us know once payment is ready so we can arrange collection.\n\nThank you.");
        $td = 'padding:9px 12px;border-bottom:1px solid #ECEFF3;';
        $th = 'padding:10px 12px;font-size:11px;letter-spacing:.4px;text-transform:uppercase;';
        $rows = ''; $tot = []; $ovd = []; $i = 0;
        foreach ($c['invoices'] as $iv) {
            $cur = (string)($iv['currency'] ?: 'KES'); $bal = (float)$iv['balance']; $late = (int)($iv['overdue'] ?? 0);
            $tot[$cur] = ($tot[$cur] ?? 0) + $bal;
            if ($late > 0) $ovd[$cur] = ($ovd[$cur] ?? 0) + $bal;
            $rows .= '<tr style="background:' . ($i++ % 2 ? '#FAFBFD' : '#FFFFFF') . '">'
                . '<td style="' . $td . 'color:#15202B">' . $e($iv['number']) . (!empty($iv['about']) ? '<div style="color:#94A3B8;font-size:10.5px;margin-top:2px">' . $e($iv['about']) . '</div>' : '') . '</td>'
                . '<td style="' . $td . 'color:#475569">' . $e($iv['date'] ?? '') . '</td>'
                . '<td style="' . $td . 'color:#475569">' . $e($iv['due'] ?? '') . ($late > 0 ? '<div style="color:#D64933;font-size:10.5px;font-weight:600">' . $late . ' days overdue</div>' : '') . '</td>'
                . '<td style="' . $td . 'color:' . (!empty($iv['fiscalised']) ? '#16A34A' : '#94A3B8') . ';font-weight:600">' . (!empty($iv['fiscalised']) ? 'Yes' : 'Pending') . '</td>'
                . '<td style="' . $td . 'text-align:right;color:#15202B;font-weight:600">' . $e($cur) . ' ' . $fmt($bal) . '</td></tr>';
        }
        ksort($tot);
        $join = function ($m) use ($fmt, $e) { $p = []; foreach ($m as $k => $v) $p[] = $e($k) . ' ' . $fmt($v); return implode(' &nbsp;·&nbsp; ', $p); };
        $foot = '<tr style="background:#15202B;color:#fff"><td colspan="4" style="padding:11px 12px;text-align:right;font-weight:700;letter-spacing:.3px">TOTAL OUTSTANDING</td>'
              . '<td style="padding:11px 12px;text-align:right;font-weight:700;color:#F8B26A;white-space:nowrap">' . $join($tot) . '</td></tr>';
        if ($ovd) { ksort($ovd); $foot = '<tr style="background:#FDF1EE"><td colspan="4" style="padding:9px 12px;text-align:right;font-weight:600;color:#D64933">Of which overdue</td>'
              . '<td style="padding:9px 12px;text-align:right;font-weight:700;color:#D64933;white-space:nowrap">' . $join($ovd) . '</td></tr>' . $foot; }
        $footer = (string)($cfg['statement_footer'] ?? '');
        if ($footer === '') $footer = "Prepared and reconciled by the Waitara Holdings Group of Companies Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time.";
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#15202B;max-width:680px">'
            . '<div style="background:#15202B;border-radius:10px 10px 0 0;padding:18px 22px;color:#fff"><table width="100%" style="border-collapse:collapse"><tr>'
            . '<td style="vertical-align:middle"><span style="display:inline-block;background:#F56F00;color:#fff;font-weight:700;font-size:15px;padding:7px 10px;border-radius:8px;letter-spacing:.5px">912</span></td>'
            . '<td style="vertical-align:middle;text-align:right"><div style="font-size:16px;font-weight:700;letter-spacing:.5px">STATEMENT OF ACCOUNT</div><div style="font-size:12px;color:#AEB9C7">' . $e($name) . '</div></td></tr></table></div>'
            . '<div style="border:1px solid #E6EAF0;border-top:0;border-radius:0 0 10px 10px;padding:22px">'
            . '<p style="margin:0 0 16px;line-height:1.55">' . nl2br($e($intro)) . '</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;margin:0 0 16px;border:1px solid #E6EAF0"><thead><tr style="background:#F56F00;color:#fff">'
            . '<th style="' . $th . 'text-align:left">Invoice</th><th style="' . $th . 'text-align:left">Date</th><th style="' . $th . 'text-align:left">Due</th><th style="' . $th . 'text-align:left">ETR</th><th style="' . $th . 'text-align:right">Balance</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table>'
            . '<p style="margin:0;color:#64748B;font-size:12px;line-height:1.5">For any queries on this statement, please reply to this email.</p>'
            . '<p style="margin:14px 0 0;padding-top:12px;border-top:1px solid #EEF1F5;color:#AEB9C7;font-size:10px;line-height:1.5;font-style:italic">' . $e($footer) . '</p></div></div>';
        return [
            'cid' => (string)$c['cid'], 'customer' => $name, 'to' => (string)($c['email'] ?? ''),
            'subject' => ((string)($cfg['statement_subject'] ?? 'Pending invoices and Statement')) . ' — ' . $name,
            'html' => $html, 'intro' => $intro, 'count' => count($c['invoices']), 'total' => array_sum($tot),
        ];
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
    /* Accounts: {staff:{user,hash,pin_hash}, owner:{...}}; legacy {user,hash} => staff.
       PIN login is the default (like the other portals); username+password still works as a fallback. */
    $gAccounts = [];
    if (is_file($gAuthFile)) {
        $j = json_decode(@file_get_contents($gAuthFile), true);
        if (is_array($j)) {
            foreach (['staff', 'owner'] as $slot) {
                $hasPwd = !empty($j[$slot]['user']) && !empty($j[$slot]['hash']);
                $hasPin = !empty($j[$slot]['pin_hash']);
                if ($hasPwd || $hasPin) $gAccounts[] = ['user'=>(string)($j[$slot]['user'] ?? ''), 'hash'=>(string)($j[$slot]['hash'] ?? ''), 'pin_hash'=>(string)($j[$slot]['pin_hash'] ?? ''), 'role'=>$slot];
            }
            if (!$gAccounts && !empty($j['user']) && !empty($j['hash'])) $gAccounts[] = ['user'=>$j['user'], 'hash'=>$j['hash'], 'pin_hash'=>(string)($j['pin_hash'] ?? ''), 'role'=>'staff'];
        }
    }
    $gHasPin = false; $gHasPwd = false;
    foreach ($gAccounts as $a) { if ($a['pin_hash'] !== '') $gHasPin = true; if ($a['user'] !== '' && $a['hash'] !== '') $gHasPwd = true; }
    $gDisabled = !empty(gp_prefs($gDir)['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=grace'); exit; }

    $gErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grace_pin'])) {
        $pin = preg_replace('/\D/', '', (string)($_POST['pin'] ?? ''));
        $ok = false; $role = ''; $who = '';
        if (!$gDisabled && preg_match('/^\d{4,6}$/', $pin)) { foreach ($gAccounts as $a) { if ($a['pin_hash'] !== '' && password_verify($pin, $a['pin_hash'])) { $ok = true; $role = $a['role']; $who = $a['user'] ?: $a['role']; break; } } }
        if ($ok) { session_regenerate_id(true); $_SESSION['grace_auth'] = true; $_SESSION['grace_user'] = $who; $_SESSION['grace_role'] = $role; header('Location: index.php?portal=grace'); exit; }
        $gErr = $gDisabled ? 'This portal is currently disabled.' : 'Wrong PIN.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grace_login'])) {
        $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
        $ok = false; $role = '';
        if (!$gDisabled) { foreach ($gAccounts as $a) { if ($a['user'] !== '' && $a['hash'] !== '' && hash_equals(strtolower($a['user']), strtolower($u)) && password_verify($p, $a['hash'])) { $ok = true; $role = $a['role']; break; } } }
        if ($ok) { session_regenerate_id(true); $_SESSION['grace_auth'] = true; $_SESSION['grace_user'] = $u; $_SESSION['grace_role'] = $role; header('Location: index.php?portal=grace'); exit; }
        $gErr = $gDisabled ? 'This portal is currently disabled.' : 'Wrong username or password.';
    }
    $gAuthed = !$gDisabled && !empty($_SESSION['grace_auth']);

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            $out = gp_apply_collect(gp_build(isset($_GET['refresh'])));
            require_once __DIR__ . '/mail.php';
            $out['mailConnected'] = mail_is_connected('grace');
            echo json_encode($out);
        }
        catch (\Throwable $e) { http_response_code(500); echo api_fail($e); }
        exit;
    }

    /* Statement of account: preview (?statement) and save-as-draft in Grace's OWN mailbox (?stmtdraft).
       Both POST {cid, to, note}. The HTML is always rebuilt here — never taken from the browser — and
       nothing is ever sent: the draft waits in her Drafts for her to review and send. */
    if (isset($_GET['statement']) || isset($_GET['stmtdraft'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false, 'error'=>'POST only.']); exit; }
        try {
            $in  = json_decode(file_get_contents('php://input'), true) ?: [];
            $cid = trim((string)($in['cid'] ?? ''));
            $st  = $cid !== '' ? gp_statement($cid, mb_substr((string)($in['note'] ?? ''), 0, 2000)) : null;
            if (!$st) { echo json_encode(['ok'=>false, 'error'=>'Client not found (or no unpaid invoices).']); exit; }
            require_once __DIR__ . '/mail.php';
            $connected = mail_is_connected('grace');
            if (isset($_GET['statement'])) {
                echo json_encode(['ok'=>true, 'connected'=>$connected, 'customer'=>$st['customer'], 'to'=>$st['to'], 'subject'=>$st['subject'], 'html'=>$st['html'], 'intro'=>$st['intro'], 'count'=>$st['count']]);
                exit;
            }
            if (!$connected) { echo json_encode(['ok'=>false, 'error'=>'Connect your mailbox first.', 'connect'=>true]); exit; }
            $to = trim((string)($in['to'] ?? '')); if ($to === '') $to = $st['to'];
            $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $to))));
            foreach ($list as $addr) { if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false, 'error'=>'"' . $addr . '" is not a valid email address.']); exit; } }
            if (!$list) { echo json_encode(['ok'=>false, 'error'=>'Add the client\'s email address first.']); exit; }
            $acct = mail_primary_account('grace');
            [$d, $code] = mail_api('POST', '/api/accounts/' . rawurlencode($acct['accountId']) . '/messages', [
                'mode' => 'draft', 'fromAddress' => $acct['from'], 'toAddress' => implode(',', $list),
                'subject' => $st['subject'], 'content' => $st['html'], 'mailFormat' => 'html',
            ], 'grace');
            if ($code >= 400) { echo json_encode(['ok'=>false, 'error'=>'Zoho Mail did not save the draft (' . $code . '): ' . (string)($d['data']['moreInfo'] ?? ($d['status']['description'] ?? 'unknown error'))]); exit; }
            $pdo = db(); gp_stmt_table($pdo);
            $pdo->prepare("INSERT INTO grace_statements (customer_id,customer_name,to_email,total,created_by) VALUES (?,?,?,?,?)")
                ->execute([$st['cid'], mb_substr($st['customer'], 0, 190), mb_substr(implode(',', $list), 0, 190), round((float)$st['total'], 2), mb_substr((string)($_SESSION['grace_user'] ?? ''), 0, 80)]);
            echo json_encode(['ok'=>true, 'from'=>$acct['from'], 'to'=>implode(', ', $list), 'at'=>date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) { echo api_fail($e); }
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
            $email = mb_substr(trim((string)($in['email'] ?? '')), 0, 190);
            $contact = mb_substr(trim((string)($in['contact_name'] ?? '')), 0, 190);
            $pdo = db(); gp_notes_table($pdo);
            $pdo->prepare("INSERT INTO grace_notes (zoho_customer_id,customer_name,status,note,promised_date,phone,email,contact_name) VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE customer_name=VALUES(customer_name),status=VALUES(status),note=VALUES(note),promised_date=VALUES(promised_date),phone=VALUES(phone),email=VALUES(email),contact_name=VALUES(contact_name)")
                ->execute([$cid, $name, $status, $note, $pd, $phone, $email, $contact]);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    /* Grace confirms the client has agreed we may collect this invoice (or clears it).
       Writes to collect_confirm, which the owner's local app reads via collect.php. */
    if (isset($_GET['confirm'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $iid = trim((string)($in['invoice_id'] ?? '')); if ($iid === '') { echo json_encode(['ok'=>false, 'error'=>'No invoice id.']); exit; }
            $num = mb_substr((string)($in['invoice_number'] ?? ''), 0, 64);
            $name = mb_substr((string)($in['customer_name'] ?? ''), 0, 190);
            $on = !empty($in['confirmed']);
            $by = mb_substr((string)($_SESSION['grace_user'] ?? ''), 0, 80);
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS collect_confirm (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', confirmed_by VARCHAR(80) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if ($on) {
                $pdo->prepare("INSERT INTO collect_confirm (invoice_id,invoice_number,customer_name,confirmed_by) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE invoice_number=VALUES(invoice_number),customer_name=VALUES(customer_name),confirmed_by=VALUES(confirmed_by)")
                    ->execute([$iid, $num, $name, $by]);
            } else {
                $pdo->prepare("DELETE FROM collect_confirm WHERE invoice_id=?")->execute([$iid]);
            }
            echo json_encode(['ok'=>true, 'invoice_id'=>$iid, 'confirmed'=>$on]);
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
            if (isset($map[$iid]) && array_key_exists('tax_total', $map[$iid])) { echo json_encode(['ok'=>true, 'invoice'=>$map[$iid]]); exit; }
            $det = gp_inv_fetch_raw($iid);
            if (!$det) { http_response_code(502); echo json_encode(['ok'=>false, 'error'=>'Could not load this invoice.']); exit; }
            $map[$iid] = $det; @file_put_contents($f, json_encode($map));
            echo json_encode(['ok'=>true, 'invoice'=>$det]);
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    /* ================= ZOHO SYSTEM tab: real Zoho invoices, quotes and customer statements =================
       Same visibility rules as the collections list: KES only, and never a client or invoice the owner
       has hidden from Grace. Everything is read-only except "Save statement to my Drafts". */
    if (!function_exists('gz_hidden')) {
        function gz_hidden() {
            $pdo = db();   // fail closed: if this throws, the caller returns an error, never unfiltered data
            $pdo->exec("CREATE TABLE IF NOT EXISTS grace_hide (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS grace_hide_client (customer_key VARCHAR(190) PRIMARY KEY, customer_name VARCHAR(190) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $inv = []; $cli = [];
            foreach ($pdo->query("SELECT invoice_id FROM grace_hide")->fetchAll(PDO::FETCH_COLUMN) as $v) $inv[(string)$v] = true;
            foreach ($pdo->query("SELECT customer_key FROM grace_hide_client")->fetchAll(PDO::FETCH_COLUMN) as $v) $cli[(string)$v] = true;
            return [$inv, $cli];
        }
        function gz_client_hidden($name, $cli) { return isset($cli[mb_strtolower(trim((string)$name))]); }
        function gz_allowed($cur, $customer, $id, $hideInv, $hideCli) {
            if (strtoupper((string)($cur ?: 'KES')) !== 'KES') return false;
            if (gz_client_hidden($customer, $hideCli)) return false;
            if ($id !== '' && isset($hideInv[(string)$id])) return false;
            return true;
        }
        function gz_detail($kind, $id) {
            $path = ($kind === 'quote' ? 'estimates/' : 'invoices/') . rawurlencode($id);
            [$d, $c] = zoho_api('GET', $path);
            $doc = $d[$kind === 'quote' ? 'estimate' : 'invoice'] ?? null;
            if ($c >= 400 || !$doc) return null;
            $items = [];
            foreach (($doc['line_items'] ?? []) as $li) {
                $items[] = ['name'=>(string)($li['name'] ?? ''), 'desc'=>(string)($li['description'] ?? ''), 'qty'=>(float)($li['quantity'] ?? 0),
                    'rate'=>(float)($li['rate'] ?? 0), 'amt'=>(float)($li['item_total'] ?? 0), 'tax'=>(string)($li['tax_name'] ?? ''), 'taxpc'=>(float)($li['tax_percentage'] ?? 0)];
            }
            $taxes = [];
            foreach (($doc['taxes'] ?? []) as $tx) $taxes[] = ['name'=>(string)($tx['tax_name'] ?? ''), 'amount'=>(float)($tx['tax_amount'] ?? 0)];
            return [
                'kind'=>$kind, 'id'=>(string)$id, 'customer_id'=>(string)($doc['customer_id'] ?? ''),
                'number'=>(string)($doc[$kind === 'quote' ? 'estimate_number' : 'invoice_number'] ?? ''),
                'customer'=>(string)($doc['customer_name'] ?? ''), 'date'=>substr((string)($doc['date'] ?? ''), 0, 10),
                'due'=>substr((string)($doc[$kind === 'quote' ? 'expiry_date' : 'due_date'] ?? ''), 0, 10),
                'status'=>(string)($doc['status'] ?? ''), 'currency'=>strtoupper((string)($doc['currency_code'] ?? 'KES')),
                'reference'=>(string)($doc['reference_number'] ?? ''), 'subject'=>(string)($doc['subject'] ?? ''),
                'sub_total'=>(float)($doc['sub_total'] ?? 0), 'tax_total'=>(float)($doc['tax_total'] ?? 0),
                'discount'=>(float)($doc['discount_total'] ?? ($doc['discount'] ?? 0)), 'shipping'=>(float)($doc['shipping_charge'] ?? 0),
                'adjustment'=>(float)($doc['adjustment'] ?? 0), 'inclusive'=>!empty($doc['is_inclusive_tax']), 'taxes'=>$taxes,
                'total'=>(float)($doc['total'] ?? 0), 'balance'=>($kind === 'quote' ? null : (float)($doc['balance'] ?? 0)),
                'notes'=>(string)($doc['notes'] ?? ''), 'items'=>$items,
            ];
        }
        /* All invoices for one customer in a date range (or every unpaid one), KES only, hidden removed. */
        function gz_statement_data($cid, $from, $to, $mode, $hideInv, $hideCli) {
            [$cd, $cc] = zoho_api('GET', 'contacts/' . rawurlencode($cid));
            $ct = $cd['contact'] ?? null;
            if ($cc >= 400 || !$ct) throw new Exception('Customer not found in Zoho.');
            $name = (string)($ct['contact_name'] ?? '');
            if (gz_client_hidden($name, $hideCli)) throw new Exception('This customer is not available.');
            $rows = []; $page = 1;
            do {
                $q = ['customer_id'=>$cid, 'per_page'=>200, 'page'=>$page, 'sort_column'=>'date', 'sort_order'=>'A'];
                if ($mode === 'unpaid') $q['filter_by'] = 'Status.Unpaid';
                else { if ($from !== '') $q['date_start'] = $from; if ($to !== '') $q['date_end'] = $to; }
                [$d, $c] = zoho_api('GET', 'invoices', null, $q);
                if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
                foreach (($d['invoices'] ?? []) as $iv) {
                    if (in_array((string)($iv['status'] ?? ''), ['void', 'draft'], true)) continue;
                    if (!gz_allowed($iv['currency_code'] ?? 'KES', $name, (string)($iv['invoice_id'] ?? ''), $hideInv, $hideCli)) continue;
                    $rows[] = ['id'=>(string)$iv['invoice_id'], 'number'=>(string)($iv['invoice_number'] ?? ''), 'date'=>substr((string)($iv['date'] ?? ''), 0, 10),
                        'due'=>substr((string)($iv['due_date'] ?? ''), 0, 10), 'status'=>(string)($iv['status'] ?? ''),
                        'total'=>(float)($iv['total'] ?? 0), 'balance'=>(float)($iv['balance'] ?? 0)];
                }
                $more = !empty($d['page_context']['has_more_page']); $page++;
            } while ($more && $page <= 10);
            $email = trim((string)($ct['email'] ?? ''));
            foreach (($ct['contact_persons'] ?? []) as $p) { if (!empty($p['is_primary_contact']) && !empty($p['email'])) { $email = (string)$p['email']; break; } }
            return ['cid'=>(string)$cid, 'customer'=>$name, 'email'=>$email, 'rows'=>$rows];
        }
        function gz_statement_html($st, $from, $to, $mode, $note) {
            $cfg = require __DIR__ . '/config.php';
            $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
            $f = fn($n) => number_format((float)$n, 2);
            $today = date('Y-m-d'); $tT = 0; $tP = 0; $tB = 0; $tO = 0; $body = ''; $i = 0;
            $td = 'padding:8px 10px;border-bottom:1px solid #ECEFF3;'; $th = 'padding:9px 10px;font-size:10.5px;letter-spacing:.4px;text-transform:uppercase;';
            foreach ($st['rows'] as $r) {
                $paid = max(0, $r['total'] - $r['balance']); $late = ($r['balance'] > 0 && $r['due'] !== '' && $r['due'] < $today);
                $tT += $r['total']; $tP += $paid; $tB += $r['balance']; if ($late) $tO += $r['balance'];
                $body .= '<tr style="background:' . ($i++ % 2 ? '#FAFBFD' : '#FFFFFF') . '"><td style="' . $td . '">' . $e($r['number']) . '</td><td style="' . $td . 'color:#475569">' . $e($r['date']) . '</td>'
                    . '<td style="' . $td . 'color:' . ($late ? '#D64933' : '#475569') . '">' . $e($r['due']) . '</td><td style="' . $td . 'text-align:right">' . $f($r['total']) . '</td>'
                    . '<td style="' . $td . 'text-align:right;color:#16A34A">' . $f($paid) . '</td><td style="' . $td . 'text-align:right;font-weight:600">' . $f($r['balance']) . '</td></tr>';
            }
            if ($body === '') $body = '<tr><td colspan="6" style="padding:16px;text-align:center;color:#94A3B8">No invoices in this period.</td></tr>';
            $period = $mode === 'unpaid' ? ('All unpaid invoices as at ' . date('j F Y')) : ('Invoices dated ' . ($from !== '' ? date('j M Y', strtotime($from)) : 'the start') . ' to ' . ($to !== '' ? date('j M Y', strtotime($to)) : date('j M Y')));
            $intro = trim((string)$note) !== '' ? (string)$note : "Dear Sir/Madam,\n\nPlease find below your statement of account. Kindly review it and arrange settlement of the outstanding balance.\n\nThank you.";
            $footer = (string)($cfg['statement_footer'] ?? '');
            if ($footer === '') $footer = "Prepared and reconciled by the Waitara Holdings Group of Companies Console — Nine One Two Holdings' intelligent finance engine, delivering precise, automated account statements in real time.";
            return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:13.5px;color:#15202B;max-width:720px">'
                . '<div style="background:#15202B;border-radius:10px 10px 0 0;padding:18px 22px;color:#fff"><table width="100%" style="border-collapse:collapse"><tr>'
                . '<td style="vertical-align:middle"><span style="display:inline-block;background:#F56F00;color:#fff;font-weight:700;font-size:15px;padding:7px 10px;border-radius:8px">912</span></td>'
                . '<td style="vertical-align:middle;text-align:right"><div style="font-size:16px;font-weight:700;letter-spacing:.5px">STATEMENT OF ACCOUNT</div><div style="font-size:12px;color:#AEB9C7">' . $e($st['customer']) . '</div><div style="font-size:11px;color:#AEB9C7;margin-top:2px">' . $e($period) . '</div></td></tr></table></div>'
                . '<div style="border:1px solid #E6EAF0;border-top:0;border-radius:0 0 10px 10px;padding:20px 22px">'
                . '<p style="margin:0 0 16px;line-height:1.55">' . nl2br($e($intro)) . '</p>'
                . '<table style="border-collapse:collapse;width:100%;font-size:12.5px;border:1px solid #E6EAF0"><thead><tr style="background:#F56F00;color:#fff">'
                . '<th style="' . $th . 'text-align:left">Invoice</th><th style="' . $th . 'text-align:left">Date</th><th style="' . $th . 'text-align:left">Due</th><th style="' . $th . 'text-align:right">Amount</th><th style="' . $th . 'text-align:right">Paid</th><th style="' . $th . 'text-align:right">Balance</th></tr></thead>'
                . '<tbody>' . $body . '</tbody><tfoot>'
                . '<tr style="background:#F5F3EF;font-weight:700"><td colspan="3" style="padding:9px 10px;text-align:right">Totals (KES)</td><td style="padding:9px 10px;text-align:right">' . $f($tT) . '</td><td style="padding:9px 10px;text-align:right;color:#16A34A">' . $f($tP) . '</td><td style="padding:9px 10px;text-align:right">' . $f($tB) . '</td></tr>'
                . ($tO > 0 ? '<tr style="background:#FDF1EE"><td colspan="5" style="padding:9px 10px;text-align:right;color:#D64933;font-weight:600">Of which overdue</td><td style="padding:9px 10px;text-align:right;color:#D64933;font-weight:700">KES ' . $f($tO) . '</td></tr>' : '')
                . '<tr style="background:#15202B;color:#fff"><td colspan="5" style="padding:11px 10px;text-align:right;font-weight:700">TOTAL OUTSTANDING</td><td style="padding:11px 10px;text-align:right;font-weight:700;color:#F8B26A;white-space:nowrap">KES ' . $f($tB) . '</td></tr></tfoot></table>'
                . '<p style="margin:14px 0 0;color:#64748B;font-size:12px">For any queries on this statement, please reply to this email.</p>'
                . '<p style="margin:12px 0 0;padding-top:10px;border-top:1px solid #EEF1F5;color:#AEB9C7;font-size:10px;font-style:italic">' . $e($footer) . '</p></div></div>';
        }
    }

    if (isset($_GET['zlist']) || isset($_GET['zdoc']) || isset($_GET['zcust']) || isset($_GET['zstmt'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$gAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try {
            [$hideInv, $hideCli] = gz_hidden();

            if (isset($_GET['zlist'])) {                          // list invoices or quotes
                $kind = $_GET['zlist'] === 'quotes' ? 'quote' : 'invoice';
                $page = max(1, min(200, (int)($_GET['page'] ?? 1)));
                $q = ['per_page'=>50, 'page'=>$page, 'sort_column'=>'date', 'sort_order'=>'D'];
                $s = trim((string)($_GET['q'] ?? '')); if ($s !== '') $q['search_text'] = mb_substr($s, 0, 100);
                $cid = preg_replace('/[^0-9]/', '', (string)($_GET['cid'] ?? '')); if ($cid !== '') $q['customer_id'] = $cid;
                $stf = (string)($_GET['status'] ?? '');
                $inv = ['unpaid'=>'Status.Unpaid', 'overdue'=>'Status.OverDue', 'paid'=>'Status.Paid', 'partially_paid'=>'Status.PartiallyPaid', 'sent'=>'Status.Sent'];
                $quo = ['draft'=>'Status.Draft', 'sent'=>'Status.Sent', 'approved'=>'Status.Approved', 'accepted'=>'Status.Accepted', 'invoiced'=>'Status.Invoiced', 'declined'=>'Status.Declined', 'expired'=>'Status.Expired'];
                $map = $kind === 'quote' ? $quo : $inv;
                if (isset($map[$stf])) $q['filter_by'] = $map[$stf];
                $etrF = $kind === 'invoice' ? (string)($_GET['etr'] ?? '') : '';
                if ($etrF !== 'yes' && $etrF !== 'no') $etrF = '';
                $etr = [];
                if ($kind === 'invoice') {   // fiscalised = ETR scan found by the office PC (pushed every 5 min)
                    try { $pe = db(); $pe->exec("CREATE TABLE IF NOT EXISTS invoice_etr (invoice_id VARCHAR(64) PRIMARY KEY, invoice_number VARCHAR(64) DEFAULT '', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                        foreach ($pe->query("SELECT invoice_id FROM invoice_etr")->fetchAll(PDO::FETCH_COLUMN) as $v) $etr[(string)$v] = true; } catch (\Throwable $e) {}
                }
                $shape = function ($r) use ($kind, $etr) {
                    $id = (string)($r[$kind === 'quote' ? 'estimate_id' : 'invoice_id'] ?? '');
                    return ['id'=>$id, 'number'=>(string)($r[$kind === 'quote' ? 'estimate_number' : 'invoice_number'] ?? ''),
                        'customer'=>(string)($r['customer_name'] ?? ''), 'cid'=>(string)($r['customer_id'] ?? ''),
                        'date'=>substr((string)($r['date'] ?? ''), 0, 10), 'due'=>substr((string)($r[$kind === 'quote' ? 'expiry_date' : 'due_date'] ?? ''), 0, 10),
                        'status'=>(string)($r['status'] ?? ''), 'total'=>(float)($r['total'] ?? 0), 'balance'=>$kind === 'quote' ? null : (float)($r['balance'] ?? 0),
                        'reference'=>(string)($r['reference_number'] ?? ''), 'fiscalised'=>isset($etr[$id]), 'cur'=>(string)($r['currency_code'] ?? 'KES')];
                };
                $keep = function ($x) use ($kind, $hideInv, $hideCli) { return gz_allowed($x['cur'], $x['customer'], $kind === 'quote' ? '' : $x['id'], $hideInv, $hideCli); };
                if ($etrF === '') {                       // normal: one Zoho page per portal page
                    [$d, $c] = zoho_api('GET', $kind === 'quote' ? 'estimates' : 'invoices', null, $q);
                    if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error');
                    $out = array_values(array_filter(array_map($shape, $d[$kind === 'quote' ? 'estimates' : 'invoices'] ?? []), $keep));
                    $more = !empty($d['page_context']['has_more_page']);
                } else {                                  // ETR filter: fetch the whole (filtered) set, then page it here
                    $all = []; $zp = 1;
                    do {
                        $q['per_page'] = 200; $q['page'] = $zp;
                        [$d, $c] = zoho_api('GET', 'invoices', null, $q);
                        if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error');
                        foreach (($d['invoices'] ?? []) as $r) { $x = $shape($r); if ($keep($x) && ($x['fiscalised'] === ($etrF === 'yes'))) $all[] = $x; }
                        $zmore = !empty($d['page_context']['has_more_page']); $zp++;
                    } while ($zmore && $zp <= 10);
                    $out = array_slice($all, ($page - 1) * 50, 50);
                    $more = count($all) > $page * 50;
                }
                foreach ($out as &$o) unset($o['cur']); unset($o);
                echo json_encode(['ok'=>true, 'kind'=>$kind, 'page'=>$page, 'more'=>$more, 'items'=>$out]); exit;
            }

            if (isset($_GET['zdoc'])) {                           // one invoice or quote
                $kind = $_GET['zdoc'] === 'quote' ? 'quote' : 'invoice';
                $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
                $doc = $id !== '' ? gz_detail($kind, $id) : null;
                if (!$doc || !gz_allowed($doc['currency'], $doc['customer'], $kind === 'quote' ? '' : $id, $hideInv, $hideCli)) { echo json_encode(['ok'=>false, 'error'=>'This document is not available.']); exit; }
                echo json_encode(['ok'=>true, 'doc'=>$doc]); exit;
            }

            if (isset($_GET['zcust'])) {                          // customer search for statements
                $q = ['contact_type'=>'customer', 'per_page'=>25, 'sort_column'=>'contact_name'];
                $s = trim((string)$_GET['zcust']); if ($s !== '') $q['contact_name_contains'] = mb_substr($s, 0, 100);   // Zoho ignores search_text on contacts
                [$d, $c] = zoho_api('GET', 'contacts', null, $q);
                if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho error');
                $out = [];
                foreach (($d['contacts'] ?? []) as $r) {
                    if (gz_client_hidden($r['contact_name'] ?? '', $hideCli)) continue;
                    if (strtoupper((string)($r['currency_code'] ?? 'KES')) !== 'KES') continue;
                    $out[] = ['cid'=>(string)$r['contact_id'], 'name'=>(string)($r['contact_name'] ?? ''), 'outstanding'=>(float)($r['outstanding_receivable_amount'] ?? 0)];
                }
                echo json_encode(['ok'=>true, 'customers'=>$out]); exit;
            }

            if (isset($_GET['zstmt'])) {                          // statement preview / save as draft
                $in = ($_SERVER['REQUEST_METHOD'] === 'POST') ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
                $cid  = preg_replace('/[^0-9]/', '', (string)($in['cid'] ?? ''));
                $mode = ($in['mode'] ?? '') === 'period' ? 'period' : 'unpaid';
                $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['from'] ?? '')) ? (string)$in['from'] : '';
                $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['to'] ?? '')) ? (string)$in['to'] : '';
                $note = mb_substr((string)($in['note'] ?? ''), 0, 2000);
                if ($cid === '') { echo json_encode(['ok'=>false, 'error'=>'Pick a customer first.']); exit; }
                $st = gz_statement_data($cid, $from, $to, $mode, $hideInv, $hideCli);
                $html = gz_statement_html($st, $from, $to, $mode, $note);
                $bal = array_sum(array_column($st['rows'], 'balance'));
                if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($in['draft'])) {
                    require_once __DIR__ . '/mail.php';
                    echo json_encode(['ok'=>true, 'customer'=>$st['customer'], 'email'=>$st['email'], 'count'=>count($st['rows']), 'balance'=>$bal, 'html'=>$html, 'connected'=>mail_is_connected('grace')]); exit;
                }
                require_once __DIR__ . '/mail.php';
                if (!mail_is_connected('grace')) { echo json_encode(['ok'=>false, 'error'=>'Connect your mailbox first.']); exit; }
                $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($in['to_email'] ?? '')))));
                if (!$list) { echo json_encode(['ok'=>false, 'error'=>'Add the customer email address first.']); exit; }
                foreach ($list as $addr) if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false, 'error'=>'"' . $addr . '" is not a valid email address.']); exit; }
                $cfg = require __DIR__ . '/config.php';
                $acct = mail_primary_account('grace');
                [$md, $mc] = mail_api('POST', '/api/accounts/' . rawurlencode($acct['accountId']) . '/messages', [
                    'mode'=>'draft', 'fromAddress'=>$acct['from'], 'toAddress'=>implode(',', $list),
                    'subject'=>((string)($cfg['statement_subject'] ?? 'Statement of account')) . ' — ' . $st['customer'], 'content'=>$html, 'mailFormat'=>'html'], 'grace');
                if ($mc >= 400) { echo json_encode(['ok'=>false, 'error'=>'Zoho Mail did not save the draft (' . $mc . ').']); exit; }
                $pdo = db(); gp_stmt_table($pdo);
                $pdo->prepare("INSERT INTO grace_statements (customer_id,customer_name,to_email,total,created_by) VALUES (?,?,?,?,?)")
                    ->execute([$cid, mb_substr($st['customer'], 0, 190), mb_substr(implode(',', $list), 0, 190), round($bal, 2), mb_substr((string)($_SESSION['grace_user'] ?? ''), 0, 80)]);
                echo json_encode(['ok'=>true, 'from'=>$acct['from'], 'to'=>implode(', ', $list)]); exit;
            }
        } catch (\Throwable $e) { echo api_fail($e); }
        exit;
    }

    if (isset($_GET['zpdf'])) {                                   // the real Zoho PDF of an invoice or quote
        if (!$gAuthed) { http_response_code(403); echo 'Not signed in.'; exit; }
        try {
            [$hideInv, $hideCli] = gz_hidden();
            $kind = $_GET['zpdf'] === 'quote' ? 'quote' : 'invoice';
            $id = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? ''));
            $doc = $id !== '' ? gz_detail($kind, $id) : null;
            if (!$doc || !gz_allowed($doc['currency'], $doc['customer'], $kind === 'quote' ? '' : $id, $hideInv, $hideCli)) { http_response_code(404); echo 'This document is not available.'; exit; }
            $c = zoho_config();
            $url = $c['api_domain'] . '/books/v3/' . ($kind === 'quote' ? 'estimates/' : 'invoices/') . $id . '?' . http_build_query(['organization_id'=>$c['organization_id'], 'accept'=>'pdf']);
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>45, CURLOPT_HTTPHEADER=>['Authorization: Zoho-oauthtoken ' . zoho_access_token(), 'Accept: application/pdf']]);
            $pdf = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($code >= 400 || !is_string($pdf) || strncmp($pdf, '%PDF', 4) !== 0) { http_response_code(502); echo 'Zoho did not return the PDF. Please try again.'; exit; }
            $fn = preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['number'] ?: $kind) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $fn . '"');
            header('Cache-Control: private, no-store');
            echo $pdf;
        } catch (\Throwable $e) { http_response_code(500); echo 'Could not load the PDF.'; }
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
  .tool{position:sticky;top:93px;z-index:20;background:var(--bg);padding:14px 0 10px;margin-top:4px;border-bottom:1px solid var(--line)}
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
  .pill.arr{background:var(--blue-bg);color:var(--mail);border:1px solid #BccCF0}
  .hfig{display:flex;gap:28px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid var(--hair)}
  .hfig .hv{font-family:var(--mono);font-weight:600;font-size:18px;margin-top:3px}
  .hfig .hn{font-size:11.5px;color:var(--mute);margin-top:2px}
  .mtabs{display:flex;gap:0;background:#24221E;padding:0 clamp(14px,3.2vw,52px);position:sticky;top:52px;z-index:45}
  .mtab{background:transparent;border:0;border-bottom:3px solid transparent;color:#B9B3A9;padding:11px 18px;font-family:inherit;font-size:12.5px;font-weight:700;letter-spacing:.6px;cursor:pointer;text-transform:uppercase}
  .mtab.on{color:#fff;border-bottom-color:var(--brand)} .mtab:hover{color:#fff}
  .zhead{display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap;background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:14px 18px;margin-bottom:12px}
  .zhead .seg2{margin-left:auto}
  .zh1{font-family:var(--mono);font-weight:600;font-size:22px;margin-top:4px}
  .zhead .hl,.zchosen .hl{font-size:10px;font-weight:600;letter-spacing:1px;text-transform:uppercase;color:var(--mute)}
  .ztool{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .zfwrap{position:relative} .zfres{position:absolute;left:0;right:0;top:100%;z-index:30;background:var(--card);border:1px solid var(--line);padding:4px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
  .zfchip{display:inline-flex;align-items:center;gap:8px;background:#FBEFE6;border:1px solid var(--brand);color:var(--ink);font-weight:700;font-size:12.5px;text-transform:uppercase;padding:0 6px 0 12px;border-radius:3px}
  .zfchip button{border:0;background:transparent;cursor:pointer;font-size:14px;color:var(--brand);padding:6px}
  .zsel{padding:10px 12px;border:1px solid var(--line);border-radius:3px;font-family:inherit;font-size:13.5px;background:var(--card)}
  .ztbl .zrow{cursor:pointer} .ztbl .zrow:hover td{background:var(--soft)}
  .ztbl .num{font-family:var(--mono);font-weight:600} .ztbl .zcust{font-weight:600;text-transform:uppercase;font-size:12.5px} .ztbl .dd{color:var(--mute);white-space:nowrap}
  .zpager{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:12px}
  .zpager .gbtn:disabled{opacity:.45;cursor:default}
  .zcard{background:var(--card);border:1px solid var(--line);padding:16px 18px}
  .zcres{display:flex;flex-direction:column;gap:4px;margin-top:6px;max-height:320px;overflow:auto}
  .zcopt{display:flex;justify-content:space-between;gap:10px;text-align:left;border:1px solid var(--line);background:var(--soft);padding:10px 12px;border-radius:3px;cursor:pointer;font-family:inherit;font-size:13px;text-transform:uppercase}
  .zcopt:hover{border-color:var(--brand)}
  .zchosen{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:10px;border-bottom:1px solid var(--hair)}
  .zstopts{display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-top:12px;font-size:13.5px}
  .zradio{display:flex;gap:6px;align-items:center;cursor:pointer}
  .zdates input{padding:7px 9px;border:1px solid var(--line);border-radius:3px;font-family:inherit;background:var(--soft)}
  .zprevbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:14px;padding:10px 14px;background:var(--card);border:1px solid var(--line)}
  .zmail{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0}
  @media(max-width:640px){ .ztbl thead{display:none} .ztbl,.ztbl tbody{display:block} .ztbl tr.zrow{display:grid;grid-template-columns:1fr auto;gap:3px 10px;padding:10px 14px;border-bottom:1px solid var(--hair)} .ztbl tr.zrow td{border:0;padding:0} .zhead .seg2{margin-left:0} }
  .itag{font-size:9.5px;font-weight:800;border-radius:3px;padding:1px 5px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;border:1px solid var(--line);color:#8a857c;background:#F5F3EF;margin-right:3px}
  .itag.etr{color:#0F7A34;background:var(--green-bg);border-color:#BFE0CB}
  .itag.pkd{color:#fff;background:#0F7A34;border-color:#0F7A34}
  .itag.snt{color:var(--mail);background:var(--blue-bg);border-color:#BccCF0}
  .stmprev{border:1px solid var(--line);background:#fff;padding:12px;max-height:46vh;overflow:auto}
  .stmwarn{background:#FFF6E5;border:1px solid #F0D9A8;color:#7a5200;padding:10px 12px;font-size:13px;margin-bottom:6px}
  .stmwarn a{color:#D95E07;font-weight:700}
  .stmd{font-size:11px;color:var(--mail);white-space:nowrap}
  .rtag2{font-size:9.5px;font-weight:800;color:#0F7A34;background:var(--green-bg);border:1px solid #BFE0CB;border-radius:3px;padding:1px 5px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
  .readybar{margin-top:10px;background:var(--green-bg);border:1px solid #BFE0CB;border-left:3px solid #0F7A34;color:#0F5A28;padding:11px 14px;font-size:13.5px;font-weight:600}
  .arrbar{margin-top:8px;background:var(--blue-bg);border:1px solid #BccCF0;border-left:3px solid var(--mail);color:#163B9E;padding:11px 14px;font-size:13.5px;font-weight:600}
  .cx.isready{border-left:3px solid #0F7A34}
  .rtag{flex:0 0 auto;font-size:9.5px;font-weight:800;color:#0F7A34;background:var(--green-bg);border:1px solid #BFE0CB;border-radius:3px;padding:1px 6px;text-transform:uppercase;letter-spacing:.04em}
  .ctag{flex:0 0 auto;font-size:9.5px;font-weight:800;color:#1F4FD0;background:var(--blue-bg);border:1px solid #BcCcF0;border-radius:3px;padding:1px 6px;text-transform:uppercase;letter-spacing:.04em}
  .invact{margin-top:7px}
  .okc{font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;border:1px solid var(--line);background:var(--card);color:var(--mail);border-radius:3px;padding:7px 12px}
  .okc.on{background:#0F7A34;border-color:#0F7A34;color:#fff}
  .okc:disabled{opacity:.6;cursor:default}
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
  /* collections table (grouped by client) */
  .gtbl{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);font-size:13px}
  .gtbl thead th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--mute);font-weight:700;padding:10px 14px;background:var(--soft);border-bottom:1px solid var(--line);white-space:nowrap}
  .gtbl th.r,.gtbl td.r{text-align:right}
  .gtbl td{padding:10px 14px;border-bottom:1px solid var(--hair);vertical-align:middle}
  .gtbl tr.grow{cursor:pointer}
  .gtbl tr.grow td{background:var(--soft);color:var(--ink);border-top:2px solid var(--line);border-bottom:1px solid var(--line);padding:11px 14px}
  .gtbl tr.grow:hover td{background:#F0EDE7}
  .gtbl tr.grow.open td{background:#FBEFE6;border-bottom-color:var(--brand)}
  .ghwrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .ghwrap .gcar{font-size:11px;color:var(--brand);width:12px;flex:0 0 auto}
  .ghwrap .gname{font-size:14px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--ink)}
  .na{font-size:10px;font-weight:800;border-radius:3px;padding:2px 7px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
  .na.hot{background:#BE2A17;color:#fff} .na.warn{background:#F4EEDC;color:#9A6700;border:1px solid #E6D9A8} .na.cool{background:#2A2824;color:#C9C4BB;border:1px solid #3A3833}
  /* progress / pipeline strip */
  .prog{background:var(--card);border:1px solid var(--line);border-left:3px solid var(--brand);padding:11px 14px;margin-top:10px}
  .progbar{height:8px;background:var(--soft);border-radius:999px;overflow:hidden}
  .progbar span{display:block;height:100%;background:linear-gradient(90deg,#D95E07,#1D7A3A);transition:width .4s}
  .proglab{font-size:12px;color:var(--mute);margin-top:8px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .proglab b{color:var(--ink)}
  .pk{cursor:pointer;font-weight:600;padding:2px 8px;border-radius:3px;border:1px solid var(--line);background:var(--soft)}
  .pk.todo{color:var(--ink)} .pk.prom{color:var(--amber)} .pk.rdy{color:#0F7A34} .pk.paid{color:var(--green)}
  .pk:hover{border-color:var(--brand)}
  /* message templates */
  .msgrow{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-top:10px}
  .msglbl{font-size:11px;font-weight:700;color:var(--mute);text-transform:uppercase;letter-spacing:.4px}
  .msgb{font-size:12px;font-weight:600;font-family:inherit;text-decoration:none;cursor:pointer;border-radius:3px;padding:7px 12px;border:1px solid var(--line);background:var(--card);color:var(--ink)}
  .msgb.wa{background:#0F7A34;border-color:#0F7A34;color:#fff}
  .msgb.copy{color:var(--mail)}
  /* toast */
  .toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(14px);background:var(--ink);color:#fff;padding:11px 18px;border-radius:4px;font-size:13px;font-weight:600;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:300;box-shadow:0 6px 24px rgba(0,0,0,.25)}
  .toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
  /* open-loop banner */
  .loopbar{margin-top:10px;background:var(--amber-bg);border:1px solid #E6D9A8;border-left:3px solid var(--amber);color:#7A5200;padding:11px 14px;font-size:13.5px;font-weight:600;cursor:pointer}
  .loopbar:hover{background:#F0E7CC}
  /* mode toggle */
  .modebar{display:flex;margin:14px 0 12px}
  .seg2{display:inline-flex;border:1px solid var(--line);border-radius:4px;overflow:hidden;background:var(--card)}
  .seg2 button{border:0;background:transparent;padding:9px 16px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;color:var(--mute);border-left:1px solid var(--line)}
  .seg2 button:first-child{border-left:0}
  .seg2 button.on{background:var(--brand);color:#fff}
  /* FOCUS card */
  .focuscard{background:var(--card);border:1px solid var(--line);border-top:3px solid var(--brand);padding:20px;max-width:560px;margin:0 auto}
  .fprog{margin-bottom:14px}
  .fpbar{height:7px;background:var(--soft);border-radius:999px;overflow:hidden}
  .fpbar span{display:block;height:100%;background:linear-gradient(90deg,#D95E07,#1D7A3A);transition:width .4s}
  .fplab{font-size:11.5px;color:var(--mute);margin-top:6px;font-weight:600}
  .fname{font-size:22px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;line-height:1.15}
  .fsub{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
  .famt{font-family:var(--mono);font-size:30px;font-weight:700;margin-top:14px;color:var(--ink)}
  .famtl{font-family:'IBM Plex Sans',sans-serif;font-size:12px;font-weight:500;color:var(--mute);margin-left:8px}
  .finvs{margin:12px 0 4px;border-top:1px solid var(--hair);border-bottom:1px solid var(--hair);padding:6px 0}
  .finv{display:flex;gap:10px;align-items:baseline;padding:4px 0;font-size:12.5px}
  .finv .num{font-family:var(--mono);font-weight:600;flex:0 0 auto}
  .finv .fia{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .finv .amt{font-family:var(--mono);font-weight:600;white-space:nowrap}
  .frow1{display:flex;gap:10px;margin-top:16px}
  .fbtn{flex:1;min-height:52px;display:flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:4px;font-size:16px;font-weight:700;cursor:pointer;font-family:inherit;text-decoration:none}
  .fbtn.call{background:var(--green);color:#fff} .fbtn.wa{background:#0F7A34;color:#fff} .fbtn.addc{background:var(--soft);border:1px dashed var(--line);color:var(--mute)}
  .foutlabel{font-size:11px;font-weight:700;color:var(--mute);text-transform:uppercase;letter-spacing:.5px;margin:18px 0 8px}
  .frow2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .fout{min-height:46px;border:1px solid var(--line);background:var(--card);border-radius:4px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit}
  .fout.promised{color:var(--amber)} .fout.noans{color:var(--mute)} .fout.ready{color:#0F7A34;border-color:#BFE0CB;background:var(--green-bg)} .fout.paid{color:var(--green)}
  .fout:hover{border-color:var(--brand)}
  .frow3{display:flex;gap:8px;margin-top:10px}
  .fskip{flex:1;min-height:40px;background:transparent;border:0;color:var(--mute);font-weight:600;cursor:pointer;font-family:inherit;font-size:13px}
  .fdetail{background:transparent;border:1px solid var(--line);border-radius:4px;padding:0 14px;color:var(--mail);font-weight:600;cursor:pointer;font-family:inherit;font-size:12.5px}
  .fdate input{padding:11px 13px;border:1px solid var(--line);border-radius:4px;font-family:inherit;font-size:15px;background:var(--soft)}
  .focusdone{background:var(--card);border:1px solid var(--line);padding:40px 20px;text-align:center;max-width:560px;margin:0 auto}
  .focusdone .fdbig{font-size:22px;font-weight:700}
  /* contact modal inputs */
  .ctclbl{display:block;font-size:11px;font-weight:700;color:var(--mute);text-transform:uppercase;letter-spacing:.4px;margin:12px 0 5px}
  .ctcin{width:100%;padding:12px 13px;border:1px solid var(--line);border-radius:4px;font-family:inherit;font-size:15px;background:var(--soft)}
  .ctcin:focus{outline:none;border-color:var(--brand)}
  .contactline{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;margin-bottom:4px}
  @media(max-width:560px){ .frow1{flex-direction:column} .famt{font-size:26px} .fname{font-size:19px} }
  .ghwrap .gcount{font-family:var(--mono);font-size:10.5px;color:var(--mute);background:var(--card);border:1px solid var(--line);border-radius:3px;padding:1px 6px;white-space:nowrap}
  .gcontact{margin:5px 0 0 20px;font-size:11.5px;color:var(--mute);display:flex;gap:7px;flex-wrap:wrap;align-items:baseline}
  .gcontact .cn{font-weight:700;color:var(--ink)} .gcontact .cp{font-family:var(--mono)} .gcontact .ce{color:var(--mail)}
  .gcontact .csep{color:var(--line)}
  .ghwrap .gtot{font-family:var(--mono);font-weight:700;font-size:14px;margin-left:4px;color:var(--ink)}
  .ghwrap .gacts{margin-left:auto;display:flex;gap:6px;align-items:center}
  .gbtn{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:3px;padding:6px 11px;font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;white-space:nowrap}
  .gbtn:hover{border-color:var(--brand)}
  .gbtn.call{background:var(--green);border-color:var(--green);color:#fff} .gbtn.wa{background:#0F7A34;border-color:#0F7A34;color:#fff}
  .na.cool{background:var(--soft);color:var(--mute);border:1px solid var(--line)}
  .girow:hover{background:var(--soft)}
  .girow .num{font-family:var(--mono);font-weight:600}
  .girow .about{color:var(--mute);max-width:320px}
  .girow .dd{color:var(--mute);white-space:nowrap} .girow .od{color:var(--red);font-weight:600}
  .girow .amt{font-weight:600;white-space:nowrap}
  .otag{font-size:9.5px;font-weight:800;color:var(--amber);background:var(--amber-bg);border:1px solid #E6D9A8;border-radius:3px;padding:1px 5px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap}
  .rc{border:1px solid var(--line);background:var(--card);color:var(--mail);border-radius:3px;padding:7px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap}
  .rc.on{background:#0F7A34;border-color:#0F7A34;color:#fff}
  .rc:disabled{opacity:.6;cursor:default}
  .ivv{color:var(--mail);font-weight:600;font-size:11.5px;background:var(--card);cursor:pointer;border:1px solid var(--line);border-radius:3px;padding:5px 11px;font-family:inherit}
  .ivv:hover{background:var(--blue-bg)}
  .gpanel td{background:var(--soft);padding:14px}
  @media(max-width:640px){
    .gtbl,.gtbl tbody{display:block}
    .gtbl thead{display:none}
    .gtbl tr.grow,.gtbl tr.gpanel{display:block}
    .gtbl tr.grow td,.gtbl tr.gpanel td{display:block;width:100%}
    .gtbl tr.girow{display:grid;grid-template-columns:1fr auto;gap:4px 10px;padding:10px 14px}
    .gtbl tr.girow td{display:block;border:0;padding:0}
    .gtbl td.about{grid-column:1 / -1;max-width:none}
    .gtbl td[data-l="Due"]{grid-column:1 / -1}
    .gtbl td[data-l="Ready to collect"]{grid-column:1}
    .gtbl td[data-l="Details"]{grid-column:2;text-align:right}
    .gtbl td.amt{text-align:left}
  }
  @media(max-width:520px){ .hero{gap:16px} .hero .hr{margin-left:0;width:100%} .stat{padding:2px 16px} .hero .ht{font-size:24px} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>Collections</h1><div class="sub">WAITARA HOLDINGS GROUP</div></div>
  <?php if ($gAuthed): ?>
  <div class="sp"><button class="tbtn" onclick="topRefresh()">Refresh</button><a class="tbtn" href="index.php?portal=grace&logout=1">Sign out</a></div>
  <?php endif; ?>
</div>
<?php if ($gAuthed): ?>
<div class="mtabs"><button class="mtab on" data-m="coll" onclick="setMain('coll')">Collections</button><button class="mtab" data-m="zoho" onclick="setMain('zoho')">ZOHO SYSTEM</button></div>
<?php endif; ?>
<?php if (!$gAuthed): ?>
<div class="login">
  <div class="lcard">
    <div class="lb">912</div>
    <h2>Welcome</h2>
    <div class="muted" style="font-size:13.5px">Sign in to chase today's unpaid invoices.</div>
    <?php if ($gDisabled): ?>
      <div class="err">This portal is currently disabled. Please contact the administrator.</div>
    <?php elseif (empty($gAccounts)): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a PIN (Settings → Portals).</div>
    <?php else: ?>
    <?php if ($gHasPin): ?>
    <form id="gpinForm" method="post" action="index.php?portal=grace" autocomplete="off">
      <input type="hidden" name="grace_pin" value="1">
      <input class="in" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="PIN" autocomplete="off" style="text-align:center;letter-spacing:.5em;font-family:var(--mono)" required autofocus>
      <?php if ($gErr): ?><div class="err"><?= $gEsc($gErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in →</button>
    </form>
    <?php if ($gHasPwd): ?><div style="text-align:center;margin-top:12px"><a href="#" onclick="document.getElementById('gpinForm').style.display='none';document.getElementById('gpwForm').style.display='block';this.parentNode.style.display='none';return false" style="color:var(--mute);font-size:12px">Use username &amp; password instead</a></div><?php endif; ?>
    <?php endif; ?>
    <?php if ($gHasPwd): ?>
    <form id="gpwForm" method="post" action="index.php?portal=grace" autocomplete="off" style="<?= $gHasPin ? 'display:none' : '' ?>">
      <input type="hidden" name="grace_login" value="1">
      <input class="in" type="text" name="u" placeholder="Username" autocapitalize="none" autocorrect="off" required>
      <input class="in" type="password" name="p" placeholder="Password" required>
      <?php if ($gErr && !$gHasPin): ?><div class="err"><?= $gEsc($gErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in →</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc2">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap" id="app"><div class="empty">Loading today's unpaid invoices…</div></div>
<div class="wrap" id="zapp" style="display:none"></div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Collections</div><div class="sc2">CONFIDENTIAL</div></div>
<div id="invModal" class="modal" onclick="if(event.target===this)closeInv()">
  <div class="mcard">
    <div class="mhead"><span class="mt" id="invTitle">Invoice</span><button class="mx" onclick="closeInv()">×</button></div>
    <div class="mbody" id="invBody"></div>
  </div>
</div>
<div id="ctcModal" class="modal" onclick="if(event.target===this)closeContact()">
  <div class="mcard" style="max-width:460px">
    <div class="mhead"><span class="mt" id="ctcTitle">Contact</span><button class="mx" onclick="closeContact()">×</button></div>
    <div class="mbody">
      <input type="hidden" id="ctcCid">
      <label class="ctclbl">Contact name</label>
      <input class="ctcin" id="ctcName" type="text" placeholder="Who to ask for">
      <label class="ctclbl">Telephone</label>
      <input class="ctcin" id="ctcPhone" type="tel" inputmode="tel" placeholder="e.g. 0712 345678">
      <label class="ctclbl">Email</label>
      <input class="ctcin" id="ctcEmail" type="email" inputmode="email" placeholder="name@company.co.ke">
      <div style="display:flex;gap:8px;margin-top:16px">
        <button class="go" style="margin-top:0" onclick="saveContact()">Save contact</button>
        <button class="gbtn" style="padding:0 16px" onclick="closeContact()">Cancel</button>
      </div>
      <div class="muted" style="font-size:11.5px;margin-top:10px">Saved here and always used for Call, WhatsApp and Email. Doesn't change Zoho.</div>
    </div>
  </div>
</div>
<div id="stmModal" class="modal" onclick="if(event.target===this)closeStmt()">
  <div class="mcard" style="max-width:760px">
    <div class="mhead"><span class="mt" id="stmTitle">Statement</span><button class="mx" onclick="closeStmt()">×</button></div>
    <div class="mbody">
      <input type="hidden" id="stmCid">
      <div id="stmConn"></div>
      <label class="ctclbl">Send to (client email)</label>
      <input class="ctcin" id="stmTo" type="text" inputmode="email" placeholder="name@company.co.ke">
      <label class="ctclbl">Message at the top <span class="muted" style="font-weight:400">(optional — leave as is for the standard wording)</span></label>
      <textarea class="nt" id="stmNote" style="min-height:92px" onchange="stmtPreview()"></textarea>
      <div class="muted" style="font-size:11.5px;margin:12px 0 6px" id="stmSubj"></div>
      <div id="stmPrev" class="stmprev"></div>
      <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
        <button class="go" style="margin-top:0;flex:1" id="stmGo" onclick="stmtDraft()">Save to my Drafts</button>
        <button class="gbtn" style="padding:0 16px" onclick="closeStmt()">Close</button>
      </div>
      <div class="muted" style="font-size:11.5px;margin-top:10px">Nothing is sent from here. The statement is saved in your Zoho Mail Drafts — open it there, check it and press Send.</div>
    </div>
  </div>
</div>
<script>
const esc=s=>String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const att=s=>esc(s).replace(/'/g,'&#39;');
const jsq=s=>String(s==null?'':s).replace(/\\/g,'\\\\').replace(/'/g,"\\'");
const fmtC=(cur,n)=>(cur||'')+' '+Math.round(n||0).toLocaleString('en-US');
const fmtMap=m=>{const k=Object.keys(m||{});if(!k.length)return '—';return k.sort().map(c=>fmtC(c,m[c])).join('  ·  ');};
let DATA=null, Q='', SORT='priority', FILTER='all', EXP={}, EDITPH={}, VIEWMODE='list', WORKED={};
// Two stages:
//  ownerReady (iv.ready, set by owner on the local app) = invoice cleared the process → Grace may contact & chase.
//  arranged  (iv.confirmed, set by Grace here)          = customer confirmed → office must send someone to collect.
function ownerReady(iv){ return !!(iv&&iv.ready); }
function arranged(iv){ return !!(iv&&iv.confirmed); }
function cOwnerReady(c){ return (c.invoices||[]).some(ownerReady); }
function cArranged(c){ return (c.invoices||[]).some(arranged); }
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
      body:JSON.stringify({customer_id:cid,customer_name:c.customer,status:c.status||'',note:c.note||'',promised_date:c.promised||'',phone:c.phone||'',email:c.email||'',contact_name:c.contactName||''})});
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
async function confirmToggle(id, number, customer, btn){
  let iv=null; (DATA.customers||[]).some(c=>{ const f=(c.invoices||[]).find(x=>String(x.id)===String(id)); if(f){iv=f;return true;} return false; });
  const now=!(iv&&iv.confirmed);
  btn.disabled=true; const old=btn.textContent; btn.textContent='…';
  try{
    const r=await fetch('index.php?portal=grace&confirm=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({invoice_id:id,invoice_number:number,customer_name:customer,confirmed:now})});
    const j=await r.json();
    if(j&&j.ok){ if(iv) iv.confirmed=now; renderList(); }
    else { btn.disabled=false; btn.textContent=old; alert('Could not save: '+((j&&j.error)||'failed')); }
  }catch(e){ btn.disabled=false; btn.textContent=old; alert('Error: '+e); }
}
function closeInv(){const m=document.getElementById('invModal');if(m)m.classList.remove('open');}
function openContact(cid){
  const c=cust(cid); if(!c)return;
  document.getElementById('ctcCid').value=cid;
  document.getElementById('ctcTitle').textContent=(c.customer||'Contact');
  document.getElementById('ctcName').value=c.contactName||'';
  document.getElementById('ctcPhone').value=c.phone||'';
  document.getElementById('ctcEmail').value=c.email||'';
  document.getElementById('ctcModal').classList.add('open');
  setTimeout(()=>{const n=document.getElementById('ctcName');if(n)n.focus();},50);
}
function closeContact(){const m=document.getElementById('ctcModal');if(m)m.classList.remove('open');}
/* ---- status tags (set by the office) + statement drafts ---- */
function fisc(iv){ return !!(iv&&iv.fiscalised); }
function wasSent(iv){ return !!(iv&&iv.sent); }
function invTags(iv){ return (ownerReady(iv)?'<span class="rtag2" title="Cleared by the office — ready to collect">● ready</span> ':'')
  +(fisc(iv)?'<span class="itag etr" title="Fiscalised — ETR receipt is on file">ETR</span>':'<span class="itag" title="Not fiscalised yet">no ETR</span>')
  +(wasSent(iv)?'<span class="itag snt" title="Invoice has been sent out to the client">sent</span>':'')
  +(iv&&iv.picked?'<span class="itag pkd" title="The office has already picked the cheque for this invoice">✓ cheque picked</span>':''); }
function shortDate(s){ if(!s)return ''; const d=new Date(String(s).replace(' ','T')); return isNaN(d)?'':d.toLocaleDateString('en-GB',{day:'numeric',month:'short'}); }
function closeStmt(){const m=document.getElementById('stmModal');if(m)m.classList.remove('open');}
async function stmtPost(route,body){ const r=await fetch('index.php?portal=grace&'+route+'=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}); return r.json(); }
function openStmt(cid){
  const c=cust(cid); if(!c)return;
  document.getElementById('stmCid').value=cid;
  document.getElementById('stmTitle').textContent='Statement — '+(c.customer||'');
  document.getElementById('stmTo').value=c.email||'';
  document.getElementById('stmNote').value='';
  document.getElementById('stmSubj').textContent='';
  document.getElementById('stmPrev').innerHTML='<div class="muted">Building the statement…</div>';
  document.getElementById('stmModal').classList.add('open');
  stmtPreview(true);
}
async function stmtPreview(first){
  const cid=document.getElementById('stmCid').value, note=first?'':document.getElementById('stmNote').value;
  const prev=document.getElementById('stmPrev'), go=document.getElementById('stmGo'), conn=document.getElementById('stmConn');
  try{
    const j=await stmtPost('statement',{cid,note});
    if(!j||!j.ok){ prev.innerHTML='<div style="color:var(--red)">'+esc((j&&j.error)||'Could not build the statement.')+'</div>'; return; }
    prev.innerHTML=j.html;   /* built by our own server from escaped data */
    document.getElementById('stmSubj').textContent='Subject: '+j.subject+' · '+j.count+' invoice'+(j.count===1?'':'s');
    if(first) document.getElementById('stmNote').value=j.intro||'';
    DATA.mailConnected=!!j.connected;
    conn.innerHTML=j.connected?'':'<div class="stmwarn">Your mailbox isn\'t connected yet, so drafts can\'t be saved. <a href="connect_mail.php">Connect my mailbox</a> — one time only.</div>';
    go.disabled=!j.connected; go.textContent='Save to my Drafts';
  }catch(e){ prev.innerHTML='<div style="color:var(--red)">'+esc(String(e))+'</div>'; }
}
async function stmtDraft(){
  const cid=document.getElementById('stmCid').value, c=cust(cid), go=document.getElementById('stmGo');
  const to=document.getElementById('stmTo').value.trim(), note=document.getElementById('stmNote').value;
  if(!to){ alert('Add the client\'s email address first.'); return; }
  go.disabled=true; go.textContent='Saving…';
  try{
    const j=await stmtPost('stmtdraft',{cid,to,note});
    if(j&&j.ok){
      if(c){ c.stmtAt=j.at; if(!c.email){ c.email=to.split(/[,;\s]+/)[0]; persist(cid); } }
      closeStmt(); renderList(); toast('Saved in your Drafts ('+j.from+') — open Zoho Mail to send');
    } else { go.disabled=false; go.textContent='Save to my Drafts'; alert((j&&j.error)||'Could not save the draft.'); }
  }catch(e){ go.disabled=false; go.textContent='Save to my Drafts'; alert('Error: '+e); }
}
function saveContact(){
  const cid=document.getElementById('ctcCid').value; const c=cust(cid); if(!c)return;
  c.contactName=document.getElementById('ctcName').value.trim();
  c.phone=document.getElementById('ctcPhone').value.trim();
  c.email=document.getElementById('ctcEmail').value.trim();
  persist(cid);
  closeContact(); toast('Contact saved'); renderList();
}
function invDetailHTML(iv){
  const cur=iv.currency||'';
  const rows=(iv.items||[]).map(it=>`<tr>
      <td><div style="color:var(--ink);font-weight:600">${esc(it.name||it.desc||'—')}</div>${(it.name&&it.desc)?`<div class="muted" style="font-size:11px;margin-top:2px">${esc(it.desc)}</div>`:''}</td>
      <td class="r">${it.qty?(+it.qty).toLocaleString('en-US'):''}</td>
      <td class="r">${it.rate?fmtC(cur,it.rate):''}</td>
      <td class="r">${it.tax?esc(it.tax):(it.taxpc?(+it.taxpc)+'%':'—')}</td>
      <td class="r">${fmtC(cur,it.amt)}</td></tr>`).join('');
  const line=(lbl,val,extra)=>`<div class="mtot" style="font-weight:500;font-size:13px;${extra||''}"><span class="muted">${lbl}</span><span class="mono">${val}</span></div>`;
  let breakdown='';
  if(iv.sub_total||iv.tax_total||(iv.taxes&&iv.taxes.length)){
    breakdown+=line('Subtotal'+(iv.inclusive?' (tax inclusive)':''), fmtC(cur,iv.sub_total));
    if(+iv.discount>0) breakdown+=line('Discount', '− '+fmtC(cur,iv.discount));
    if(iv.taxes&&iv.taxes.length) iv.taxes.forEach(tx=>{ breakdown+=line(esc(tx.name||'Tax'), fmtC(cur,tx.amount)); });
    else if(+iv.tax_total>0) breakdown+=line('Tax (VAT)', fmtC(cur,iv.tax_total));
    if(+iv.shipping>0) breakdown+=line('Shipping', fmtC(cur,iv.shipping));
    if(+iv.adjustment) breakdown+=line('Adjustment', fmtC(cur,iv.adjustment));
  }
  return `
    <div class="mmeta"><span><b style="color:var(--ink);text-transform:uppercase">${esc(iv.customer||'')}</b></span><span>Date: ${esc(iv.date||'—')}</span><span>Due: ${esc(iv.due||'—')}</span>${iv.status?`<span>Status: ${esc(iv.status)}</span>`:''}</div>
    ${rows?`<table class="mtbl"><thead><tr><th>Item / description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Tax</th><th class="r">Amount</th></tr></thead><tbody>${rows}</tbody></table>`:'<div class="muted">No line items recorded on this invoice.</div>'}
    <div style="margin-top:12px;border-top:1px solid var(--hair);padding-top:10px">${breakdown}</div>
    <div class="mtot"><span>Invoice total</span><span class="mono">${fmtC(cur,iv.total)}</span></div>
    ${(iv.balance!=null)?`<div class="mtot" style="color:var(--red)"><span>Balance due</span><span class="mono">${fmtC(cur,iv.balance)}</span></div>`:''}
    ${iv.notes?`<div class="muted" style="margin-top:14px;font-size:12px"><b style="color:var(--ink)">Notes:</b> ${esc(iv.notes)}</div>`:''}`;
}
async function openInv(id){
  const m=document.getElementById('invModal'),b=document.getElementById('invBody'),t=document.getElementById('invTitle');
  if(!m)return; m.classList.add('open'); t.textContent='Invoice'; b.innerHTML='<div class="muted" style="padding:8px">Loading invoice…</div>';
  try{
    const r=await fetch('index.php?portal=grace&inv='+encodeURIComponent(id),{credentials:'same-origin'});
    const j=await r.json();
    if(!j||!j.ok){ b.innerHTML='<div style="padding:8px;color:var(--red)">'+esc((j&&j.error)||'Could not load this invoice.')+'</div>'; return; }
    const iv=j.invoice; t.textContent=iv.number||'Invoice';
    b.innerHTML=invDetailHTML(iv);
  }catch(e){ b.innerHTML='<div style="padding:8px;color:var(--red)">Error: '+esc(String(e))+'</div>'; }
}
function matchFilter(c){
  if(FILTER==='urgent')return (c.maxOverdue||0)>=30;
  if(FILTER==='todo')return !c.status && c.status!=='Paid';
  if(FILTER==='promised')return c.status==='Promised';
  if(FILTER==='paid')return c.status==='Paid';
  if(FILTER==='nophone')return !(c.phone);
  if(FILTER==='ready')return cOwnerReady(c);
  if(FILTER==='arranged')return cArranged(c);
  if(FILTER==='fisc')return (c.invoices||[]).some(fisc);
  if(FILTER==='nofisc')return (c.invoices||[]).some(iv=>!fisc(iv));
  if(FILTER==='callback')return callbackDue(c);
  return true;
}
function readyTotals(){ const by={}; let n=0; (DATA.customers||[]).forEach(c=>(c.invoices||[]).forEach(iv=>{ if(ownerReady(iv)){ const cu=(iv.currency||'KES'); by[cu]=(by[cu]||0)+(+iv.balance||0); n++; } })); return {by,n}; }
function arrangedTotals(){ const by={}; let n=0; (DATA.customers||[]).forEach(c=>(c.invoices||[]).forEach(iv=>{ if(arranged(iv)){ const cu=(iv.currency||'KES'); by[cu]=(by[cu]||0)+(+iv.balance||0); n++; } })); return {by,n}; }
function todayISO(){ const d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
/* Priority score — where her next call has the biggest expected payoff.
   amount owed × how-overdue, boosted when never contacted or the client already said
   the money is ready, and when a promise has lapsed; future promises are pushed down. */
function cScore(c){
  if(c.status==='Paid') return -1;
  let w=1+Math.min(+c.maxOverdue||0,120)/30;         // older debt = more urgent (up to ~5×)
  if(!c.status) w*=1.25;                               // never contacted → act
  if(c.status==='No answer') w*=1.15;
  if(c.status==='Promised'){ const p=c.promised||''; w*=(p && p<todayISO())?1.4:0.5; }  // lapsed promise up, future down
  if(cArranged(c)) w*=0.3;              // collection already arranged — her part is done
  else if(cOwnerReady(c)) w*=1.7;       // cleared by office → call now to arrange
  return (+c.sortTotal||0)*w;
}
function nextAction(c){
  if(cArranged(c)) return {t:'📦 Collection arranged',cls:'cool'};
  if(cOwnerReady(c)) return {t:'💰 Ready — contact & arrange collection',cls:'hot'};
  if(c.status==='Paid') return null;
  if(c.status==='Promised'){ const p=c.promised||''; if(p && p<todayISO()) return {t:'⚠ Promise overdue — call',cls:'hot'}; if(p) return {t:'Promised '+p,cls:'cool'}; return {t:'Promised — set a date',cls:'warn'}; }
  if(c.status==='No answer') return {t:'Try again',cls:'warn'};
  if(c.status==='Called') return {t:'Follow up',cls:'warn'};
  return {t:'Not contacted — call',cls:'warn'};
}
function callbackDue(c){ return c.status==='Promised' && c.promised && c.promised<=todayISO(); }
function invNums(c){ const ns=(c.invoices||[]).map(iv=>iv.number).filter(Boolean); let s=ns.slice(0,6).join(', '); if(ns.length>6) s+=' +'+(ns.length-6)+' more'; return s; }
/* Ready-to-use WhatsApp messages — one tap sends a clear, polite, professional ask
   (reduces her effort and keeps the wording consistent, which lifts response rates). */
function waMsg(c, kind){
  const name=((c.contactName||c.customer||'').replace(/\s+/g,' ').trim());
  const amt=fmtMap(c.totalByCur), nums=invNums(c);
  if(kind==='followup'){ const by=(c.status==='Promised'&&c.promised)?(', as we agreed, by '+c.promised):''; return 'Good day '+name+', following up on the outstanding balance of '+amt+' ('+nums+')'+by+'. Could you kindly let me know the payment status? Thank you — Grace, Waitara Holdings.'; }
  if(kind==='collect')  return 'Good day '+name+', thank you. Kindly confirm the payment / cheque of '+amt+' is ready so we can arrange collection. Thank you — Grace, Waitara Holdings.';
  return 'Good day '+name+', this is Grace from Waitara Holdings. A kind reminder regarding your outstanding balance of '+amt+' ('+nums+'). Kindly advise when payment will be ready or when we may collect. Thank you.';
}
function waLink(c, kind){ return 'https://wa.me/'+encodeURIComponent(waNum(c.phone))+'?text='+encodeURIComponent(waMsg(c,kind)); }
function copyMsg(cid, kind){ const c=cust(cid); if(!c)return; const t=waMsg(c,kind); if(navigator.clipboard){navigator.clipboard.writeText(t).then(()=>toast('Message copied — paste into SMS/WhatsApp')).catch(()=>toast('Copy failed'));} }
let _toastT=null;
function toast(msg){ let e=document.getElementById('toast'); if(!e){ e=document.createElement('div'); e.id='toast'; e.className='toast'; document.body.appendChild(e); } e.textContent=msg; e.classList.add('show'); clearTimeout(_toastT); _toastT=setTimeout(()=>e.classList.remove('show'),1800); }
function renderProgress(cs){
  const n=cs.length||1;
  const paid=cs.filter(c=>c.status==='Paid').length;
  const ready=cs.filter(cOwnerReady).length;
  const promised=cs.filter(c=>c.status==='Promised').length;
  const todo=cs.filter(c=>!c.status).length;
  const pct=Math.round((n-todo)/n*100);
  return `<div class="prog">
    <div class="progbar"><span style="width:${pct}%"></span></div>
    <div class="proglab"><b>${pct}%</b> worked today-list ·
      <span class="pk todo" onclick="setFilter('todo')">${todo} to contact</span>
      <span class="pk prom" onclick="setFilter('promised')">${promised} promised</span>
      <span class="pk rdy" onclick="setFilter('ready')">${ready} ready to collect</span>
      ${paid?`<span class="pk paid" onclick="setFilter('paid')">${paid} paid</span>`:''}
    </div>
  </div>`;
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
        <div class="ht">${esc(fmtC('KES',DATA.grandKES!=null?DATA.grandKES:((DATA.totalByCur||{}).KES||0)))}</div>
        ${DATA.fxCount>0?`<div class="hs">All unpaid invoices in KES — includes ${esc(fmtC('KES',DATA.fxKES))} from ${DATA.fxCount} USD invoice${DATA.fxCount===1?'':'s'} (converted) handled by the office</div>`:''}
        ${(()=>{const rt=readyTotals();return `<div class="hfig">
          <div><div class="hl">Your collection potential</div><div class="hv">${esc(fmtC('KES',(DATA.totalByCur||{}).KES||0))}</div><div class="hn">${DATA.invCount||0} KES invoice${(DATA.invCount||0)===1?'':'s'} on your list</div></div>
          <div><div class="hl">Ready to collect now</div><div class="hv" style="color:#0F7A34">${esc(fmtC('KES',rt.by.KES||0))}</div><div class="hn">${rt.n} invoice${rt.n===1?'':'s'} cleared by the office</div></div>
        </div>`;})()}
        <div class="hs">${toChase} customer${toChase===1?'':'s'} to chase · ${DATA.invCount||0} unpaid invoices</div>
      </div>
      <div class="hr">
        <div class="stat alert"><div class="n">${nUrgent}</div><div class="l">30+ days late</div></div>
        <div class="stat"><div class="n">${nContacted}</div><div class="l">Followed up</div></div>
        <div class="stat"><div class="n">${nDone}</div><div class="l">Paid</div></div>
      </div>
    </div>
    ${(()=>{const cb=cs.filter(callbackDue).length;return cb>0?`<div class="loopbar" onclick="focusCallbacks()">⏰ <b>${cb}</b> promise${cb===1?'':'s'} due or overdue — tap to start here →</div>`:'';})()}
    ${(()=>{const rt=readyTotals();return rt.n>0?`<div class="readybar">✅ Ready to collect (cleared by office) — <b>${esc(fmtMap(rt.by))}</b> across ${rt.n} invoice${rt.n===1?'':'s'} to contact</div>`:'';})()}
    ${(()=>{const at=arrangedTotals();return at.n>0?`<div class="arrbar">📦 Collection arranged — office to send someone for <b>${esc(fmtMap(at.by))}</b> across ${at.n} invoice${at.n===1?'':'s'}</div>`:'';})()}
    ${renderProgress(cs)}
    <div class="tool">
      <input class="srch" type="text" placeholder="Search a customer…" value="${att(Q)}" oninput="Q=this.value.toLowerCase();renderList()">
      <div class="chips" id="fchips"></div>
      <div class="sortrow">Sort:
        <div class="seg">
          <button data-k="priority" class="${SORT==='priority'?'on':''}" onclick="setSort('priority')">🎯 Best to call first</button>
          <button data-k="overdue" class="${SORT==='overdue'?'on':''}" onclick="setSort('overdue')">Most overdue</button>
          <button data-k="owed" class="${SORT==='owed'?'on':''}" onclick="setSort('owed')">Biggest amount</button>
        </div>
      </div>
    </div>
    <div id="gList"></div>
    <div class="muted" style="font-size:11.5px;text-align:center;margin-top:14px">Updated ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'} · ${DATA.etrAsOf?'ETR / fiscalised status as of '+new Date(DATA.etrAsOf).toLocaleString('en-GB'):'ETR status not received from the office yet'} · your notes are private to this portal and don't change Zoho</div>`;
  renderList();
}
function renderChips(){
  const box=document.getElementById('fchips');if(!box||!DATA)return;
  const cs=DATA.customers||[];
  const defs=[
    {k:'all',label:'All',cls:'',n:cs.length},
    {k:'callback',label:'📞 Call back today',cls:'red',n:cs.filter(callbackDue).length},
    {k:'ready',label:'✅ Ready to collect',cls:'green',n:cs.filter(cOwnerReady).length},
    {k:'arranged',label:'📦 Collection arranged',cls:'green',n:cs.filter(cArranged).length},
    {k:'fisc',label:'Fiscalised (ETR)',cls:'green',n:cs.filter(c=>(c.invoices||[]).some(fisc)).length},
    {k:'nofisc',label:'Not fiscalised',cls:'',n:cs.filter(c=>(c.invoices||[]).some(iv=>!fisc(iv))).length},
    {k:'urgent',label:'30+ days late',cls:'red',n:cs.filter(c=>(c.maxOverdue||0)>=30).length},
    {k:'todo',label:'To contact',cls:'',n:cs.filter(c=>!c.status).length},
    {k:'promised',label:'Promised',cls:'amber',n:cs.filter(c=>c.status==='Promised').length},
    {k:'nophone',label:'No number',cls:'',n:cs.filter(c=>!c.phone).length},
    {k:'paid',label:'Paid',cls:'green',n:cs.filter(c=>c.status==='Paid').length},
  ];
  box.innerHTML=defs.map(d=>`<button class="chip ${FILTER===d.k?'on '+d.cls:''}" onclick="setFilter('${d.k}')">${d.label}<span class="c">${d.n}</span></button>`).join('');
}
function buildList(){
  let list=(DATA.customers||[]).slice();
  if(SORT==='owed')list.sort((a,b)=>(b.sortTotal||0)-(a.sortTotal||0));
  else if(SORT==='overdue')list.sort((a,b)=>(b.maxOverdue||0)-(a.maxOverdue||0)||(b.sortTotal||0)-(a.sortTotal||0));
  else list.sort((a,b)=>cScore(b)-cScore(a));   // priority (default)
  if(Q)list=list.filter(c=>((c.customer||'')+' '+(c.phone||'')+' '+(c.contactName||'')).toLowerCase().indexOf(Q)>=0);
  return list.filter(matchFilter);
}
function setMode(m){VIEWMODE=m;renderList();}
function renderList(){
  renderChips();
  const box=document.getElementById('gList');if(!box||!DATA)return;
  const list=buildList();
  const toggle=`<div class="modebar"><div class="seg2">
    <button class="${VIEWMODE==='focus'?'on':''}" onclick="setMode('focus')">🎯 Focus — one at a time</button>
    <button class="${VIEWMODE==='list'?'on':''}" onclick="setMode('list')">☰ Full list</button>
  </div></div>`;
  if(VIEWMODE==='focus'){ box.innerHTML=toggle+renderFocus(list); return; }
  if(!list.length){box.innerHTML=toggle+'<div class="empty">Nothing here 🎉 — try a different filter.</div>';return;}
  box.innerHTML=toggle+`<table class="gtbl">
    <thead><tr><th>Invoice</th><th>What it's for</th><th>Due</th><th class="r">Amount</th><th class="r">Collection</th><th class="r">Details</th></tr></thead>
    <tbody>${list.map(renderGroup).join('')}</tbody>
  </table>`;
}
function renderFocus(list){
  const total=list.length;
  const worked=list.filter(c=>WORKED[c.cid]).length;
  const next=list.find(c=>!WORKED[c.cid]);
  if(total===0) return '<div class="empty">Nothing here 🎉 — try a different filter.</div>';
  if(!next) return `<div class="focusdone"><div class="fdbig">✅ All ${total} worked</div><div class="muted" style="margin-top:6px">You've been through every client in this view.</div><button class="go" style="max-width:220px;margin:14px auto 0" onclick="WORKED={};renderList()">Go through again</button></div>`;
  const c=next, na=nextAction(c), ov=c.maxOverdue||0, oc=ovdClass(ov);
  const tel=telNum(c.phone), wa=waNum(c.phone), amt=fmtMap(c.totalByCur);
  const callBtn=c.phone?`<a class="fbtn call" href="tel:${att(tel)}">📞 Call</a>`:`<button class="fbtn addc" onclick="openContact('${jsq(c.cid)}')">+ Add contact first</button>`;
  const waBtn=(c.phone&&wa)?`<a class="fbtn wa" href="${att(waLink(c,'reminder'))}" target="_blank" rel="noopener">WhatsApp</a>`:'';
  const invlist=(c.invoices||[]).slice(0,4).map(iv=>`<div class="finv"><span class="num">${esc(iv.number)}</span><span class="fia about">${invTags(iv)} ${iv.about?esc(iv.about):''}</span><span class="amt mono">${esc(fmtC(iv.currency,iv.balance))}</span></div>`).join('');
  const more=(c.invoices||[]).length>4?`<div class="muted" style="font-size:11.5px;padding:4px 0">+${c.invoices.length-4} more invoice(s)</div>`:'';
  const prog=`<div class="fprog"><div class="fpbar"><span style="width:${Math.round(worked/total*100)}%"></span></div><div class="fplab">${worked} of ${total} worked</div></div>`;
  let actions;
  if(c.status==='Promised'){
    actions=`<div class="foutlabel">When did they promise to pay?</div>
      <div class="fdate"><input type="date" value="${att(c.promised||'')}" onchange="onPromised('${jsq(c.cid)}',this.value,'')"></div>
      <button class="go" style="margin-top:12px" onclick="focusAdvance('${jsq(c.cid)}')">Save & next client →</button>`;
  } else {
    actions=`<div class="foutlabel">What happened on this call?</div>
      <div class="frow2">
        <button class="fout promised" onclick="focusOutcome('${jsq(c.cid)}','Promised')">Promised to pay</button>
        <button class="fout noans" onclick="focusOutcome('${jsq(c.cid)}','No answer')">No answer</button>
        <button class="fout ready" onclick="focusReady('${jsq(c.cid)}')">📦 Arrange collection</button>
        <button class="fout paid" onclick="focusOutcome('${jsq(c.cid)}','Paid')">Paid</button>
      </div>
      <div class="frow3">
        <button class="fskip" onclick="focusSkip('${jsq(c.cid)}')">Skip →</button>
        <button class="fdetail" onclick="openContact('${jsq(c.cid)}')">Edit contact</button>
        <button class="fdetail" onclick="openStmt('${jsq(c.cid)}')">✉ Email statement</button>
      </div>${c.stmtAt?`<div class="stmd" style="margin-top:6px">Statement drafted ${esc(shortDate(c.stmtAt))}</div>`:''}`;
  }
  return `<div class="focuscard">
    ${prog}
    <div class="fname">${esc(c.customer||'(unnamed)')}</div>
    <div class="fsub">${na?`<span class="na ${na.cls}">${esc(na.t)}</span>`:''}<span class="pill ${ov>0?oc:'green'}">${ov>0?ov+' days late':'On time'}</span>${c.contactName?`<span class="muted">Ask for ${esc(c.contactName)}</span>`:''}${c.phone?`<span class="muted mono">${esc(c.phone)}</span>`:''}</div>
    <div class="famt">${esc(amt)}<span class="famtl">owed · ${c.count} invoice${c.count===1?'':'s'}</span></div>
    <div class="finvs">${invlist}${more}</div>
    <div class="frow1">${callBtn}${waBtn}</div>
    ${actions}
  </div>`;
}
function focusAdvance(cid){ WORKED[cid]=true; renderList(); }
function focusSkip(cid){ WORKED[cid]=true; renderList(); }
function focusOutcome(cid,status){ const c=cust(cid); if(!c)return; c.status=status; persist(cid); if(status==='Promised'){ renderList(); return; } WORKED[cid]=true; renderList(); }
async function focusReady(cid){
  const c=cust(cid); if(!c)return;
  const invs=(c.invoices||[]).filter(iv=>iv.id && !iv.confirmed);
  for(const iv of invs){
    try{ const r=await fetch('index.php?portal=grace&confirm=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({invoice_id:iv.id,invoice_number:iv.number,customer_name:c.customer,confirmed:true})}); const j=await r.json(); if(j&&j.ok) iv.confirmed=true; }catch(e){}
  }
  if(!c.status) { c.status='Called'; persist(cid); }
  WORKED[cid]=true; renderList(); toast('Collection arranged — office will send someone');
}
function focusCallbacks(){ FILTER='callback'; VIEWMODE='focus'; WORKED={}; renderList(); }
function renderGroup(c){
  const cid=c.cid, dot='d_'+cid;
  const ov=c.maxOverdue||0, oc=ovdClass(ov);
  const daysPill=ov>0?`<span class="pill ${oc}">${ov} days late</span>`:`<span class="pill green">On time</span>`;
  const spill=c.status?`<span class="spill ${c.status==='No answer'?'No':c.status}">${esc(c.status)}</span>`:'';
  const tel=telNum(c.phone), wa=waNum(c.phone);
  const callbtns=c.phone
    ? `<a class="gbtn call" href="tel:${att(tel)}">Call</a>${wa?`<a class="gbtn wa" href="${att(waLink(c,'reminder'))}" target="_blank" rel="noopener" title="Opens WhatsApp with a ready reminder message">WhatsApp</a>`:''}<button class="gbtn" onclick="openContact('${jsq(cid)}')">Edit contact</button>`
    : `<button class="gbtn addc" onclick="openContact('${jsq(cid)}')">+ Add contact</button>`;
  const nReady=(c.invoices||[]).filter(ownerReady).length;
  const nArr=(c.invoices||[]).filter(arranged).length;
  const na=nextAction(c);
  const open=!!EXP[cid];
  const head=`<tr class="grow ${open?'open':''}" onclick="toggleInv('${jsq(cid)}')">
    <td colspan="6">
      <div class="ghwrap">
        <span class="gcar">${open?'▾':'▸'}</span>
        <span class="gname">${esc(c.customer||'(unnamed)')}</span>
        <span class="gcount">${c.count} inv</span>
        ${na?`<span class="na ${na.cls}">${esc(na.t)}</span>`:''}
        ${spill}${daysPill}
        ${(c.invoices||[]).filter(iv=>iv.picked).length>0?`<span class="itag pkd">✓ ${(c.invoices||[]).filter(iv=>iv.picked).length} cheque picked</span>`:''}${nReady>0?`<span class="pill ready">● ${nReady} ready to collect</span>`:''}${nArr>0?`<span class="pill arr">📦 ${nArr} collection arranged</span>`:''}
        <span class="itag ${c.fiscalCount>0?'etr':''}" title="Invoices with an ETR receipt on file">ETR ${c.fiscalCount||0}/${c.count}</span>${c.stmtAt?`<span class="stmd">✉ statement drafted ${esc(shortDate(c.stmtAt))}</span>`:''}
        <span class="gtot">${esc(fmtMap(c.totalByCur))}</span>
        <span class="gacts" onclick="event.stopPropagation()">${callbtns}</span>
      </div>
      ${(()=>{const d=[c.contactName,c.phone,c.email].filter(Boolean);return d.length?`<div class="gcontact">${d.map((x,i)=>`<span class="${i===0?'cn':(i===1?'cp':'ce')}">${esc(x)}</span>`).join('<span class="csep">·</span>')}</div>`:'';})()}
    </td></tr>`;
  const rows=open?(c.invoices||[]).map(iv=>`<tr class="girow">
    <td data-l="Invoice"><span class="num">${esc(iv.number)}</span></td>
    <td data-l="What it's for" class="about">${iv.about?esc(iv.about):'<span class="muted">—</span>'}</td>
    <td data-l="Due" class="mono dd">${esc(iv.due||'—')}${iv.overdue>0?` <span class="od">${iv.overdue}d late</span>`:''}</td>
    <td data-l="Amount" class="r mono amt">${esc(fmtC(iv.currency,iv.balance))}</td>
    <td data-l="Collection" class="r">${invTags(iv)}${iv.id?`<button class="rc ${arranged(iv)?'on':''}" onclick="confirmToggle('${jsq(iv.id)}','${jsq(iv.number)}','${jsq(c.customer)}',this)" title="Mark when the customer confirms — the office will send someone to collect">${arranged(iv)?'✓ Collection arranged':'Arrange collection'}</button>`:''}</td>
    <td data-l="Details" class="r">${iv.id?`<button class="ivv" onclick="openInv('${jsq(iv.id)}')">Details</button>`:''}</td>
  </tr>`).join(''):'';
  let panel='';
  if(open){
    const STAT=['Called','Promised','No answer','Paid'];
    const schips=STAT.map(s=>`<button class="sc ${c.status===s?'on':''}" data-s="${s}" onclick="tapStatus('${jsq(cid)}','${s}')">${s}</button>`).join('');
    const promised=(c.status==='Promised')?`<div class="dt">Promised to pay by <input type="date" value="${att(c.promised||'')}" onchange="onPromised('${jsq(cid)}',this.value,'${dot}')"></div>`:'';
    const cline=[c.contactName?esc(c.contactName):'', c.phone?esc(c.phone):'', c.email?esc(c.email):''].filter(Boolean).join(' · ');
    const phone=`<div class="contactline">${cline?`<span>${cline}</span>`:'<span class="muted">No contact saved</span>'} <button class="gbtn" style="padding:5px 11px" onclick="openContact('${jsq(cid)}')">${cline?'Edit contact':'+ Add contact'}</button></div>`;
    const waBtn=(kind,label)=> c.phone&&waNum(c.phone)?`<a class="msgb wa" href="${att(waLink(c,kind))}" target="_blank" rel="noopener">${label}</a>`:'';
    const msgs=`<div class="msgrow">
      <span class="msglbl">Send:</span>
      ${waBtn('reminder','Reminder')}${waBtn('followup','Follow-up')}${waBtn('collect','Confirm collection')}
      <button class="msgb copy" onclick="copyMsg('${jsq(cid)}','reminder')">Copy reminder</button>
      <button class="msgb copy" onclick="openStmt('${jsq(cid)}')">✉ Email statement</button>
    </div>`;
    panel=`<tr class="gpanel"><td colspan="6">
      ${phone}
      ${msgs}
      <div class="schips">${schips}</div>
      ${promised}
      <textarea class="nt" placeholder="Notes — what they said, when to call back…" oninput="onNote('${jsq(cid)}',this.value,'${dot}')">${esc(c.note||'')}</textarea>
      <span id="${dot}" class="saveddot">saved ✓</span>
    </td></tr>`;
  }
  return head+rows+panel;
}
/* ================= ZOHO SYSTEM tab ================= */
let MODE='coll';
const Z={sub:'invoices', invoices:{q:'',status:'unpaid',etr:'',cid:'',cname:'',page:1,items:null,more:false,err:''}, quotes:{q:'',status:'',etr:'',cid:'',cname:'',page:1,items:null,more:false,err:''},
  st:{cust:null, results:[], mode:'unpaid', from:'', to:'', note:'', prev:null, busy:false, err:''}};
function setMain(m){ MODE=m; try{ localStorage.setItem('grace_main',m); }catch(e){}
  document.getElementById('app').style.display=(m==='coll')?'':'none';
  document.getElementById('zapp').style.display=(m==='zoho')?'':'none';
  document.querySelectorAll('.mtab').forEach(b=>b.classList.toggle('on', b.dataset.m===m));
  if(m==='zoho'){ zRender(); const t=Z[Z.sub]; if(Z.sub!=='statements' && !t.items) zLoad(); } }
function topRefresh(){ if(MODE==='zoho'){ if(Z.sub!=='statements'){ Z[Z.sub].items=null; zLoad(); } else zRender(); } else load(true); }
function zSub(s){ Z.sub=s; zRender(); if(s!=='statements' && !Z[s].items) zLoad(); }
const ZSTAT={paid:'green',accepted:'green',approved:'green',invoiced:'green',overdue:'red',declined:'red',expired:'red',partially_paid:'amber',unpaid:'amber',sent:'',draft:'',viewed:''};
function zPill(s){ s=String(s||''); return `<span class="pill ${ZSTAT[s]||''}" style="text-transform:capitalize">${esc(s.replace(/_/g,' ')||'—')}</span>`; }
async function zLoad(){
  const k=Z.sub, t=Z[k]; t.err=''; bar(true);
  try{ const qs='&zlist='+(k==='quotes'?'quotes':'invoices')+'&page='+t.page+'&q='+encodeURIComponent(t.q)+'&status='+encodeURIComponent(t.status)+'&etr='+encodeURIComponent(t.etr)+'&cid='+encodeURIComponent(t.cid);
    const j=await fetch('index.php?portal=grace'+qs,{credentials:'same-origin'}).then(r=>r.json());
    if(j&&j.ok){ t.items=j.items; t.more=j.more; } else { t.items=[]; t.err=(j&&j.error)||'Could not load from Zoho.'; } }
  catch(e){ t.items=[]; t.err=String(e); }
  bar(false); if(Z.sub===k) zRender();
}
let _zqT=null;
function zSearch(v){ const t=Z[Z.sub]; t.q=v; t.page=1; clearTimeout(_zqT); _zqT=setTimeout(()=>{ t.items=null; zRender(); zLoad(); },450); }
function zEtr(v){ const t=Z[Z.sub]; t.etr=v; t.page=1; t.items=null; zRender(); zLoad(); }
let _zfT=null;
function zCustFilter(v){ clearTimeout(_zfT); _zfT=setTimeout(async()=>{ const box=document.getElementById('zfres'); if(!box) return;
  if(v.trim().length<2){ box.innerHTML=''; box.style.display='none'; return; }
  try{ const j=await fetch('index.php?portal=grace&zcust='+encodeURIComponent(v.trim()),{credentials:'same-origin'}).then(r=>r.json());
    Z.fres=(j&&j.ok)?j.customers:[]; box.style.display='';
    box.innerHTML=Z.fres.length?Z.fres.map((c,i)=>`<button class="zcopt" onclick="zCustSet(${i})"><b>${esc(c.name)}</b></button>`).join(''):'<div class="muted" style="padding:8px">No matching customer.</div>';
  }catch(e){} },300); }
function zCustSet(i){ const t=Z[Z.sub], c=i<0?null:(Z.fres||[])[i]; t.cid=c?c.cid:''; t.cname=c?c.name:''; t.page=1; t.items=null; zRender(); zLoad(); }
function zFilterExtras(t,isQ){
  const etr=isQ?'':`<select class="zsel" onchange="zEtr(this.value)"><option value=""${t.etr===''?' selected':''}>ETR: all</option><option value="yes"${t.etr==='yes'?' selected':''}>ETR on file</option><option value="no"${t.etr==='no'?' selected':''}>No ETR</option></select>`;
  const cust=t.cid?`<span class="zfchip">${esc(t.cname)} <button onclick="zCustSet(-1)" title="Show all customers">✕</button></span>`
    :`<span class="zfwrap"><input class="srch" style="width:230px" type="text" placeholder="Filter by customer…" oninput="zCustFilter(this.value)"><span id="zfres" class="zcres zfres" style="display:none"></span></span>`;
  return cust+etr;
}
function zStatus(v){ const t=Z[Z.sub]; t.status=v; t.page=1; t.items=null; zRender(); zLoad(); }
function zPage(d){ const t=Z[Z.sub]; t.page=Math.max(1,t.page+d); t.items=null; zRender(); zLoad(); window.scrollTo(0,0); }
function zPdf(kind,id){ window.open('index.php?portal=grace&zpdf='+kind+'&id='+encodeURIComponent(id),'_blank'); }
async function zView(kind,id){
  const m=document.getElementById('invModal'),b=document.getElementById('invBody'),t=document.getElementById('invTitle');
  m.classList.add('open'); t.textContent=kind==='quote'?'Quote':'Invoice'; b.innerHTML='<div class="muted" style="padding:8px">Loading from Zoho…</div>';
  try{ const j=await fetch('index.php?portal=grace&zdoc='+kind+'&id='+encodeURIComponent(id),{credentials:'same-origin'}).then(r=>r.json());
    if(!j||!j.ok){ b.innerHTML='<div style="padding:8px;color:var(--red)">'+esc((j&&j.error)||'Could not load.')+'</div>'; return; }
    const d=j.doc; t.textContent=d.number||t.textContent;
    let h=invDetailHTML(d);
    if(kind==='quote') h=h.replace('Invoice total','Quote total').replace('Due: ','Valid until: ');
    b.innerHTML=`<div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">${zPill(d.status)}${d.reference?`<span class="muted" style="font-size:12px">Ref: ${esc(d.reference)}</span>`:''}${d.subject?`<span class="muted" style="font-size:12px">${esc(d.subject)}</span>`:''}<button class="gbtn" style="margin-left:auto" onclick="zPdf('${kind}','${jsq(d.id)}')">⤓ Open PDF</button></div>`+h;
  }catch(e){ b.innerHTML='<div style="padding:8px;color:var(--red)">Error: '+esc(String(e))+'</div>'; }
}
function zListHTML(k){
  const t=Z[k], isQ=(k==='quotes');
  const opts=isQ?[['','All quotes'],['draft','Draft'],['sent','Sent'],['approved','Approved'],['accepted','Accepted'],['invoiced','Invoiced'],['declined','Declined'],['expired','Expired']]
               :[['','All invoices'],['unpaid','Unpaid'],['overdue','Overdue'],['partially_paid','Partially paid'],['paid','Paid']];
  const tools=`<div class="ztool"><input class="srch" style="flex:1;min-width:200px" type="text" placeholder="Search ${isQ?'quote':'invoice'} number or customer…" value="${att(t.q)}" oninput="zSearch(this.value)">
    <select class="zsel" onchange="zStatus(this.value)">${opts.map(o=>`<option value="${o[0]}"${t.status===o[0]?' selected':''}>${o[1]}</option>`).join('')}</select>
    ${zFilterExtras(t,isQ)}</div>`;
  if(t.items===null) return tools+'<div class="empty">Loading from Zoho…</div>';
  if(t.err) return tools+`<div class="empty" style="color:var(--red)">${esc(t.err)}</div>`;
  if(!t.items.length) return tools+`<div class="empty">No ${isQ?'quotes':'invoices'} found${t.q?' for “'+esc(t.q)+'”':''}.</div>`;
  const rows=t.items.map(r=>`<tr class="zrow" onclick="zView('${isQ?'quote':'invoice'}','${jsq(r.id)}')">
      <td data-l="${isQ?'Quote':'Invoice'}"><span class="num">${esc(r.number)}</span></td>
      <td data-l="Customer" class="zcust">${esc(r.customer)}</td>
      <td data-l="Date" class="mono dd">${esc(r.date||'—')}</td>
      <td data-l="${isQ?'Valid until':'Due'}" class="mono dd">${esc(r.due||'—')}</td>
      <td data-l="Status">${zPill(r.status)}${(!isQ && r.balance>0)?(r.fiscalised?' <span class="itag etr" title="Fiscalised — ETR receipt is on file">ETR</span>':' <span class="itag" title="Not fiscalised yet">no ETR</span>'):''}</td>
      <td data-l="Total" class="r mono">${esc(fmtC('KES',r.total))}</td>
      ${isQ?'':`<td data-l="Balance" class="r mono" style="font-weight:600;${r.balance>0?'color:var(--red)':''}">${esc(fmtC('KES',r.balance))}</td>`}
      <td class="r" onclick="event.stopPropagation()"><button class="ivv" onclick="zView('${isQ?'quote':'invoice'}','${jsq(r.id)}')">View</button> <button class="ivv" onclick="zPdf('${isQ?'quote':'invoice'}','${jsq(r.id)}')">PDF</button> ${r.cid?`<button class="ivv" style="color:var(--brand)" title="Statement PDF for ${att(r.customer)}" onclick="zStmtFor('${jsq(r.cid)}','${jsq(r.customer)}')">Statement</button>`:''}</td></tr>`).join('');
  return tools+`<table class="gtbl ztbl"><thead><tr><th>${isQ?'Quote':'Invoice'}</th><th>Customer</th><th>Date</th><th>${isQ?'Valid until':'Due'}</th><th>Status</th><th class="r">Total</th>${isQ?'':'<th class="r">Balance</th>'}<th></th></tr></thead><tbody>${rows}</tbody></table>
    <div class="zpager"><button class="gbtn" ${t.page<=1?'disabled':''} onclick="zPage(-1)">← Previous</button><span class="muted">Page ${t.page}</span><button class="gbtn" ${t.more?'':'disabled'} onclick="zPage(1)">Next →</button></div>`;
}
/* ---- customer statements ---- */
let _zcT=null;
function zCustSearch(v){ clearTimeout(_zcT); _zcT=setTimeout(async()=>{ const box=document.getElementById('zcres'); if(!box) return;
  if(v.trim().length<2){ box.innerHTML=''; return; } box.innerHTML='<div class="muted" style="padding:8px">Searching…</div>';
  try{ const j=await fetch('index.php?portal=grace&zcust='+encodeURIComponent(v.trim()),{credentials:'same-origin'}).then(r=>r.json());
    Z.st.results=(j&&j.ok)?j.customers:[];
    box.innerHTML=Z.st.results.length?Z.st.results.map((c,i)=>`<button class="zcopt" onclick="zPickCust(${i})"><b>${esc(c.name)}</b><span class="muted mono">${c.outstanding>0?esc(fmtC('KES',c.outstanding))+' owed':'nothing owed'}</span></button>`).join(''):'<div class="muted" style="padding:8px">No matching customer.</div>';
  }catch(e){ box.innerHTML='<div style="padding:8px;color:var(--red)">'+esc(String(e))+'</div>'; } },350); }
function zPickCust(i){ Z.st.cust=Z.st.results[i]; Z.st.prev=null; zRender(); }
function zStField(k,v){ Z.st[k]=v; if(k==='mode') zRender(); }
function zStBody(extra){ return Object.assign({cid:Z.st.cust&&Z.st.cust.cid, mode:Z.st.mode, from:Z.st.from, to:Z.st.to, note:Z.st.note}, extra||{}); }
async function zStGenerate(){
  if(!Z.st.cust){ alert('Pick a customer first.'); return; }
  Z.st.busy=true; Z.st.err=''; zRender();
  try{ const j=await fetch('index.php?portal=grace&zstmt=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(zStBody())}).then(r=>r.json());
    if(j&&j.ok){ Z.st.prev=j; Z.st.toEmail=Z.st.toEmail||j.email||''; } else Z.st.err=(j&&j.error)||'Could not build the statement.'; }
  catch(e){ Z.st.err=String(e); }
  Z.st.busy=false; zRender();
}
async function zStmtFor(cid,name){
  const w=window.open('','_blank');   // open now (inside the click) so pop-up blockers allow it
  if(w){ w.document.write('<p style="font-family:Arial;padding:30px;color:#555">Building the statement for '+esc(name)+'…</p>'); }
  Z.sub='statements'; Z.st.cust={cid:cid,name:name,outstanding:0}; Z.st.mode='unpaid'; Z.st.from=''; Z.st.to=''; Z.st.note=''; Z.st.prev=null; Z.st.toEmail='';
  await zStGenerate(); window.scrollTo(0,0);
  if(!w) return;
  if(!Z.st.prev){ w.document.body.innerHTML='<p style="font-family:Arial;padding:30px;color:#B00">'+esc(Z.st.err||'Could not build the statement.')+'</p>'; return; }
  w.document.open(); w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Statement — '+esc(Z.st.prev.customer)+'</title><style>body{margin:24px;background:#fff}.bar{font-family:Arial;margin:0 0 16px}.bar button{background:#D95E07;color:#fff;border:0;padding:9px 16px;border-radius:4px;font-weight:700;cursor:pointer}@media print{.bar{display:none}body{margin:0}}</style></head><body><div class="bar"><button onclick="window.print()">Save as PDF / Print</button></div>'+Z.st.prev.html+'<scr'+'ipt>setTimeout(function(){window.print()},400)</scr'+'ipt></body></html>'); w.document.close();
}
function zStPrint(){ if(!Z.st.prev) return; const w=window.open('','_blank'); if(!w){ alert('Allow pop-ups for this site to print.'); return; }
  w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>Statement — '+esc(Z.st.prev.customer)+'</title><style>body{margin:24px;background:#fff}@media print{body{margin:0}}</style></head><body>'+Z.st.prev.html+'<scr'+'ipt>setTimeout(function(){window.print()},300)</scr'+'ipt></body></html>'); w.document.close(); }
async function zStDraft(btn){
  const to=(document.getElementById('zstTo')||{}).value||''; Z.st.toEmail=to;
  if(!to.trim()){ alert('Add the customer email address first.'); return; }
  btn.disabled=true; btn.textContent='Saving…';
  try{ const j=await fetch('index.php?portal=grace&zstmt=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(zStBody({draft:true,to_email:to}))}).then(r=>r.json());
    if(j&&j.ok){ toast('Saved in your Drafts ('+j.from+') — open Zoho Mail to send'); }
    else alert((j&&j.error)||'Could not save the draft.'); }
  catch(e){ alert('Error: '+e); }
  btn.disabled=false; btn.textContent='Save to my Drafts';
}
function zStmtHTML(){
  const s=Z.st;
  const pick=s.cust?`<div class="zchosen"><div><div class="hl">Customer</div><b style="font-size:15px;text-transform:uppercase">${esc(s.cust.name)}</b> <span class="muted mono" style="margin-left:6px">${s.cust.outstanding>0?esc(fmtC('KES',s.cust.outstanding))+' owed':''}</span></div><button class="gbtn" onclick="Z.st.cust=null;Z.st.prev=null;zRender()">Change</button></div>`
    :`<label class="ctclbl">Customer</label><input class="ctcin" type="text" placeholder="Type at least 2 letters of the customer name…" oninput="zCustSearch(this.value)" autofocus><div id="zcres" class="zcres"></div>`;
  const opts=s.cust?`<div class="zstopts">
      <label class="zradio"><input type="radio" name="zm" ${s.mode==='unpaid'?'checked':''} onchange="zStField('mode','unpaid')"> All unpaid invoices</label>
      <label class="zradio"><input type="radio" name="zm" ${s.mode==='period'?'checked':''} onchange="zStField('mode','period')"> Invoices in a period</label>
      ${s.mode==='period'?`<span class="zdates">From <input type="date" value="${att(s.from)}" onchange="zStField('from',this.value)"> to <input type="date" value="${att(s.to)}" onchange="zStField('to',this.value)"></span>`:''}
    </div>
    <label class="ctclbl">Message at the top <span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
    <textarea class="nt" style="min-height:70px" oninput="Z.st.note=this.value" placeholder="Leave empty for the standard wording">${esc(s.note)}</textarea>
    <button class="go" style="margin-top:12px;max-width:260px" ${s.busy?'disabled':''} onclick="zStGenerate()">${s.busy?'Building…':'Generate statement'}</button>
    ${s.err?`<div style="color:var(--red);margin-top:10px">${esc(s.err)}</div>`:''}`:'';
  const prev=s.prev?`<div class="zprevbar"><b>${s.prev.count} invoice${s.prev.count===1?'':'s'}</b> · balance <b class="mono">${esc(fmtC('KES',s.prev.balance))}</b>
      <button class="gbtn" style="margin-left:auto" onclick="zStPrint()">🖨 Print / Save PDF</button></div>
    <div class="zmail">${s.prev.connected?`<input class="ctcin" id="zstTo" type="text" placeholder="customer@company.co.ke" value="${att(s.toEmail||'')}" style="flex:1;min-width:220px"><button class="go" style="margin:0;width:auto;padding:0 18px" onclick="zStDraft(this)">Save to my Drafts</button>`
      :`<div class="stmwarn" style="margin:0;flex:1">To email this statement, <a href="connect_mail.php">connect your mailbox</a> once. You can still print it now.</div>`}</div>
    <div class="stmprev" style="max-height:none">${s.prev.html}</div>`:'';
  return `<div class="zcard">${pick}${opts}</div>${prev}`;
}
function zRender(){
  const z=document.getElementById('zapp'); if(!z) return;
  const subs=[['invoices','Invoices'],['quotes','Quotes'],['statements','Customer statements']];
  z.innerHTML=`<div class="zhead"><div><div class="hl">Zoho Books — live</div><div class="zh1">ZOHO SYSTEM</div></div>
      <div class="seg2">${subs.map(s=>`<button class="${Z.sub===s[0]?'on':''}" onclick="zSub('${s[0]}')">${s[1]}</button>`).join('')}</div></div>
    ${Z.sub==='statements'?zStmtHTML():zListHTML(Z.sub)}
    <div class="muted" style="font-size:11.5px;text-align:center;margin-top:14px">Read live from Zoho Books · KES only · nothing here changes Zoho</div>`;
}
load(false);
try{ if(localStorage.getItem('grace_main')==='zoho') setMain('zoho'); }catch(e){}
</script>
<?php endif; ?>
</body></html>
    <?php
    exit;
}
