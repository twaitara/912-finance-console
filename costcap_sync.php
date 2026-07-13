<?php
/* costcap_sync.php — reconcile a job's captured cost rows with Zoho Books expenses.

   Pure/testable: the Zoho calls are injected via $post = function($method,$path,$body=null){...}
   returning [decoded, httpCode] (same shape as zoho_api()). This lets the endpoint pass the
   real zoho_api and tests pass a mock.

   For each submitted line (by index) it diffs the line's cost rows against what is stored:
     - new row (no uid, or uid unknown)        -> POST /expenses            -> store expense_id
     - existing row whose amount/desc/category changed -> PUT /expenses/{id}
     - existing row removed from the line       -> DELETE /expenses/{id}
     - row zeroed to <= 0 that had an expense    -> DELETE /expenses/{id}
   All expenses use reference_number = invoice number so the Profit Report attributes them.

   Returns [items, warnings]. Non-fatal Zoho failures are collected as warnings so the local
   save still persists whatever succeeded (rows that got an expense_id won't be re-created). */

/* --- shared booking config: which Zoho expense account captured costs book to --- */
if (!function_exists('costcap_config')) {
    function costcap_config() {
        $f = __DIR__ . '/data/costcap_config.json';
        if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) {
            return ['account_id'=>(string)($j['account_id']??''), 'paid_through_account_id'=>(string)($j['paid_through_account_id']??'')];
        } }
        return ['account_id'=>'', 'paid_through_account_id'=>''];
    }
}
if (!function_exists('costcap_config_save')) {
    function costcap_config_save($account_id, $paid) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $c = ['account_id'=>(string)$account_id, 'paid_through_account_id'=>(string)$paid];
        @file_put_contents($dir . '/costcap_config.json', json_encode($c));
        return $c;
    }
}

if (!function_exists('costcap_reconcile')) {
    function costcap_reconcile(array $items, array $lines, array $conf, string $invNo, string $today, callable $post) {
        $acc     = (string)($conf['account_id'] ?? '');
        $paid    = (string)($conf['paid_through_account_id'] ?? '');
        $canPost = ($acc !== '' && $invNo !== '');
        $warnings = [];
        $cats = ['parts','labour','consumables','subcontract','other'];

        foreach ($lines as $ln) {
            $idx = (int)($ln['index'] ?? -1);
            if ($idx < 0 || !isset($items[$idx])) continue;
            $lineName = (string)($items[$idx]['name'] ?? 'Item');

            // existing rows for this line, keyed by uid (holds current expense_id + signature)
            $oldByUid = [];
            foreach ((array)($items[$idx]['cost_rows'] ?? []) as $or) {
                if (!empty($or['uid'])) $oldByUid[(string)$or['uid']] = $or;
            }

            $newRows = []; $keptUids = [];
            foreach ((array)($ln['cost_rows'] ?? []) as $r) {
                $desc = trim((string)($r['description'] ?? ''));
                $qty  = (float)($r['qty'] ?? 0); if ($qty <= 0) $qty = 1;
                $unit = (float)($r['unit_cost'] ?? 0);
                if ($desc === '' && $unit <= 0) continue;                 // empty row — drop
                $cat  = strtolower(trim((string)($r['category'] ?? 'other')));
                if (!in_array($cat, $cats, true)) $cat = 'other';
                $amount = round($qty * max(0, $unit), 2);

                $uid = trim((string)($r['uid'] ?? ''));
                $old = ($uid !== '' && isset($oldByUid[$uid])) ? $oldByUid[$uid] : null;
                $expenseId = $old ? (string)($old['expense_id'] ?? '') : '';
                if ($uid === '') $uid = uniqid('cr', true);
                $keptUids[$uid] = true;

                $descFull = substr($lineName . ' — ' . ucfirst($cat) . ($desc !== '' ? (': ' . $desc) : ''), 0, 500);

                if ($canPost && $amount > 0) {
                    // skip a needless PUT when nothing Zoho cares about changed
                    $unchanged = $old && $expenseId !== ''
                        && abs((float)($old['amount'] ?? -1) - $amount) < 0.005
                        && (string)($old['category'] ?? '') === $cat
                        && (string)($old['description'] ?? '') === substr($desc, 0, 190);

                    if (!$unchanged) {
                        $body = ['account_id'=>$acc, 'date'=>$today, 'amount'=>$amount,
                                 'reference_number'=>$invNo, 'description'=>$descFull, 'is_inclusive_tax'=>false];
                        if ($paid !== '') $body['paid_through_account_id'] = $paid;
                        if ($expenseId !== '') {
                            [$d, $c] = $post('PUT', 'expenses/' . $expenseId, $body);
                            if ($c >= 400) $warnings[] = 'Could not update expense for "' . $descFull . '" in Zoho: ' . ($d['message'] ?? ('HTTP ' . $c));
                        } else {
                            [$d, $c] = $post('POST', 'expenses', $body);
                            if ($c < 400 && !empty($d['expense']['expense_id'])) $expenseId = (string)$d['expense']['expense_id'];
                            else $warnings[] = 'Could not post expense for "' . $descFull . '" to Zoho: ' . ($d['message'] ?? ('HTTP ' . $c));
                        }
                    }
                } elseif ($canPost && $amount <= 0 && $expenseId !== '') {
                    $post('DELETE', 'expenses/' . $expenseId, null);   // row zeroed out — remove expense
                    $expenseId = '';
                }

                $rowOut = ['uid'=>$uid, 'category'=>$cat, 'description'=>substr($desc, 0, 190),
                           'qty'=>$qty, 'unit_cost'=>max(0, $unit), 'amount'=>$amount];
                if ($expenseId !== '') $rowOut['expense_id'] = $expenseId;
                $newRows[] = $rowOut;
            }

            // delete Zoho expenses for rows removed from THIS line
            if ($canPost) {
                foreach ($oldByUid as $uid => $or) {
                    if (empty($keptUids[$uid]) && !empty($or['expense_id'])) {
                        [$d, $c] = $post('DELETE', 'expenses/' . $or['expense_id'], null);
                        if ($c >= 400) $warnings[] = 'Could not delete a removed expense in Zoho.';
                    }
                }
            }

            $items[$idx]['cost_rows'] = $newRows;
        }

        return [$items, $warnings];
    }
}
