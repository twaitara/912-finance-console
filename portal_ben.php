<?php
/* Ben Portal data helpers — global so BOTH ?portal=ben and the admin ?bendesc
   editor can use them. Auto-labels invoices, and admins can override any label
   (stored in data/ben_desc_overrides.json, applied live at render). */
if (!function_exists('bp_build')) {
    function bp_companies() {
        return ['Fabri Metal Congo SARL', 'CIMMETAL Burkina', 'SteelRwa Industries Ltd', 'Fabrimetal Senegal Sebikotane', 'Fabrimetal Burundi', 'Fabrimetal Angola', 'IMAFER Mali', 'Fabrimetal Ghana Ltd.', 'Fabrimetal Benin', 'MMD', 'Fabrimetal Senegal SINDIA'];
    }
    function bp_key($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }
    function bp_desc_summary($lineItems) {
        $uniq = []; $seen = [];
        foreach (($lineItems ?? []) as $li) {
            $n = trim((string)($li['name'] ?? '')); if ($n === '') $n = trim((string)($li['description'] ?? ''));
            $n = preg_replace('/\s+/', ' ', $n); if ($n === '') continue;
            $k = strtolower($n); if (isset($seen[$k])) continue; $seen[$k] = 1; $uniq[] = $n;
        }
        if (!$uniq) return '';
        $shown = array_slice($uniq, 0, 3); $more = count($uniq) - count($shown);
        $s = implode(', ', $shown); if ($more > 0) $s .= ' +' . $more . ' more';
        if (mb_strlen($s) > 120) $s = mb_substr($s, 0, 118) . '…';
        return $s;
    }
    /* specific, human-authored overrides only (used for subjects too) */
    function bp_label_specific($s) {
        $t = strtolower((string)$s);
        if ($t === '') return (string)$s;
        if (strpos($t, 'cloud hosting') !== false) return 'Temporary SAP Server Hosting (Main Server was Down)';
        if (strpos($t, 'managed sap hosting') !== false) return 'Managed SAP Hosting Environment';
        if (strpos($t, 'winsvrstdcore') !== false || (strpos($t, 'setup fee') !== false && strpos($t, 'server') !== false)) return 'Server Setup Fee';
        return (string)$s;
    }
    /* full auto-label for line-item text (specific overrides + generic bucket) */
    function bp_label($s) {
        $t = strtolower((string)$s);
        if ($t === '') return '';
        $sp = bp_label_specific($s); if ($sp !== (string)$s) return $sp;
        $svc = ['intrusion', 'prevention', 'duo security', 'firewall', 'antivirus', 'endpoint', 'sql', 'server', 'software', 'licen', 'subscription', 'hosting', 'backup', 'cyber', 'vpn', 'monitoring', 'services offered', 'it support', 'maintenance', 'support', 'office 365', 'microsoft 365', 'ssl certificate', 'domain'];
        foreach ($svc as $kw) { if (strpos($t, $kw) !== false) return 'Support Services'; }
        return (string)$s;
    }
    function bp_load_overrides($dir) { $f = $dir . '/ben_desc_overrides.json'; return is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; }
    function bp_prefs($dir) { $f = $dir . '/ben_prefs.json'; $j = is_file($f) ? (json_decode(@file_get_contents($f), true) ?: []) : []; return ['preview' => array_key_exists('preview', $j) ? (bool)$j['preview'] : true, 'disabled' => !empty($j['disabled'])]; }
    /* Compact text of Ben's own invoices for the Ask AI assistant. */
    function bp_ai_context($data) {
        $lines = [];
        foreach (['2025', '2026'] as $y) {
            $yd = $data['years'][$y] ?? null; if (!$yd) continue;
            $lines[] = "=== YEAR $y ===";
            foreach (($yd['companies'] ?? []) as $c) {
                $lines[] = $c['name'] . ' (' . $c['count'] . ' invoices):';
                foreach ($c['invoices'] as $iv) {
                    $lines[] = '  - ' . $iv['number'] . ' | ' . $iv['date'] . ' | ' . (($iv['desc'] ?? '') !== '' ? $iv['desc'] : '(no description)') . ' | ' . number_format((float)($iv['total'] ?? 0)) . ' ' . ($iv['currency'] ?? '') . ' | ' . ($iv['status'] ?? '');
                }
            }
        }
        $txt = implode("\n", $lines);
        return mb_strlen($txt) > 60000 ? mb_substr($txt, 0, 60000) : $txt;
    }
    /* Call Claude, scoped strictly to the invoice data. */
    function bp_ask($key, $context, $question, $history) {
        $sys = "You are \"Ask AI\", a concise, professional assistant for a CIO reviewing his company's invoices in a secure client portal. Answer ONLY from the invoice data below, which covers his group's 2025 and 2026 invoices. If a question falls outside this data, politely say you can only help with these invoices. Keep answers short, clear and businesslike; you may use **bold** and simple lists.\n\nINVOICE DATA:\n" . $context;
        $messages = [];
        foreach (array_slice((array)$history, -8) as $m) {
            $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $t = trim((string)($m['content'] ?? '')); if ($t !== '') $messages[] = ['role'=>$role, 'content'=>mb_substr($t, 0, 2000)];
        }
        $messages[] = ['role'=>'user', 'content'=>$question];
        $payload = ['model'=>'claude-haiku-4-5', 'max_tokens'=>1024, 'system'=>$sys, 'messages'=>$messages];
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01']]);
        $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($err) throw new Exception('request failed');
        $d = json_decode($res, true);
        if ($code >= 400) throw new Exception($d['error']['message'] ?? 'ai error');
        $txt = ''; foreach (($d['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $txt .= $b['text']; }
        $txt = trim($txt); return $txt !== '' ? $txt : 'No answer.';
    }
    function bp_apply_overrides(&$data, $ov) {
        if (empty($data['years'])) return;
        foreach ($data['years'] as $y => &$yd) {
            foreach ($yd['companies'] as &$c) {
                foreach ($c['invoices'] as &$iv) {
                    $n = (string)($iv['number'] ?? '');
                    $o = ($n !== '' && isset($ov[$n])) ? trim((string)$ov[$n]) : '';
                    $iv['desc'] = ($o !== '') ? $o : (string)($iv['autoDesc'] ?? ($iv['desc'] ?? ''));
                }
                unset($iv);
            }
            unset($c);
        }
        unset($yd);
    }
    function bp_build($force) {
        $dir = __DIR__ . '/data'; if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $cache = $dir . '/ben_invoices_v6.json';
        if (!$force && is_file($cache) && (time() - filemtime($cache) < 900)) { $j = json_decode(file_get_contents($cache), true); if (is_array($j)) { $j['cached'] = true; return $j; } }
        $cfg = zoho_config(); $companies = bp_companies(); $map = [];
        foreach ($companies as $c) $map[bp_key($c)] = $c;
        $rows = []; $page = 1; $stop = false;
        do {
            [$d, $code] = zoho_api('GET', 'invoices', null, ['per_page'=>200, 'page'=>$page, 'sort_column'=>'date', 'sort_order'=>'D']);
            if ($code >= 400) throw new Exception($d['message'] ?? 'Zoho error (invoices)');
            foreach (($d['invoices'] ?? []) as $inv) {
                $date = substr((string)($inv['date'] ?? ''), 0, 10); $yr = substr($date, 0, 4);
                if ($yr !== '' && $yr < '2025') { $stop = true; continue; }
                if ($yr !== '2025' && $yr !== '2026') continue;
                $st = (string)($inv['status'] ?? ''); if ($st === 'void' || $st === 'draft') continue;
                $k = bp_key($inv['customer_name'] ?? ''); if (!isset($map[$k])) continue;
                $rows[] = ['id'=>(string)($inv['invoice_id'] ?? ''), 'company'=>$map[$k], 'number'=>(string)($inv['invoice_number'] ?? ''), 'date'=>$date, 'year'=>$yr, 'status'=>$st, 'total'=>(float)($inv['total'] ?? 0), 'balance'=>(float)($inv['balance'] ?? 0), 'currency'=>strtoupper((string)($inv['currency_code'] ?? ($cfg['currency'] ?? 'KES')))];
            }
            $more = $d['page_context']['has_more_page'] ?? false; $page++;
        } while ($more && !$stop && $page <= 80);

        // "What it's for": prefer the invoice Subject if present, else summarise the
        // line items. Cached per id as {d: text, s: 1 if from subject}.
        $descFile = $dir . '/ben_desc_cache_v2.json';
        $descCache = is_file($descFile) ? (json_decode(@file_get_contents($descFile), true) ?: []) : [];
        $descDirty = false; $fetched = 0; $CAP = 250;
        foreach ($rows as &$r) {
            $id = $r['id']; $d = ''; $isSub = false;
            if ($id !== '' && isset($descCache[$id]) && is_array($descCache[$id])) { $d = (string)($descCache[$id]['d'] ?? ''); $isSub = !empty($descCache[$id]['s']); }
            elseif ($id !== '' && $fetched < $CAP) {
                try {
                    [$dv, $dc] = zoho_api('GET', 'invoices/' . rawurlencode($id), null, []);
                    if ($dc < 400 && !empty($dv['invoice'])) {
                        $inv = $dv['invoice'];
                        $subject = trim((string)($inv['subject'] ?? ''));
                        if ($subject === '') { foreach (($inv['custom_fields'] ?? []) as $cf) { if (stripos((string)($cf['label'] ?? ''), 'subject') !== false) { $subject = trim((string)($cf['value'] ?? '')); if ($subject !== '') break; } } }
                        if ($subject !== '') { $isSub = true; $d = (mb_strlen($subject) > 140) ? (mb_substr($subject, 0, 138) . '…') : $subject; }
                        else { $isSub = false; $d = bp_desc_summary($inv['line_items'] ?? []); }
                    }
                } catch (Exception $e) { $d = ''; }
                $descCache[$id] = ['d'=>$d, 's'=>$isSub ? 1 : 0]; $descDirty = true; $fetched++;
            }
            $r['autoDesc'] = $isSub ? bp_label_specific($d) : bp_label($d);
            $r['desc'] = $r['autoDesc'];
        }
        unset($r);
        if ($descDirty) @file_put_contents($descFile, json_encode($descCache));

        $years = [];
        foreach (['2025', '2026'] as $y) $years[$y] = ['count'=>0, 'invoicedByCur'=>[], 'outstandingByCur'=>[], 'companies'=>[]];
        $byYC = [];
        foreach ($rows as $r) {
            $y = $r['year']; $cn = $r['company']; $cur = $r['currency'];
            if (!isset($byYC[$y][$cn])) $byYC[$y][$cn] = ['name'=>$cn, 'invoices'=>[], 'invoicedByCur'=>[], 'outstandingByCur'=>[], 'count'=>0];
            $b = &$byYC[$y][$cn];
            $b['invoices'][] = $r; $b['count']++;
            $b['invoicedByCur'][$cur] = ($b['invoicedByCur'][$cur] ?? 0) + $r['total'];
            $b['outstandingByCur'][$cur] = ($b['outstandingByCur'][$cur] ?? 0) + $r['balance'];
            unset($b);
            $years[$y]['count']++;
            $years[$y]['invoicedByCur'][$cur] = ($years[$y]['invoicedByCur'][$cur] ?? 0) + $r['total'];
            $years[$y]['outstandingByCur'][$cur] = ($years[$y]['outstandingByCur'][$cur] ?? 0) + $r['balance'];
        }
        foreach (['2025', '2026'] as $y) {
            $cos = array_values($byYC[$y] ?? []);
            foreach ($cos as &$c) { usort($c['invoices'], fn($a, $b) => strcmp($b['date'], $a['date'])); } unset($c);
            usort($cos, function ($a, $b) { if ($b['count'] !== $a['count']) return $b['count'] - $a['count']; return strcasecmp($a['name'], $b['name']); });
            $years[$y]['companies'] = $cos;
        }
        $out = ['ok'=>true, 'asOf'=>date('c'), 'cached'=>false, 'years'=>$years];
        @file_put_contents($cache, json_encode($out));
        return $out;
    }
}
if (isset($_GET['portal']) && $_GET['portal'] === 'ben') {
    session_name('BENPORTAL');
    session_start();
    require_once __DIR__ . '/csrf.php'; csrf_guard();
    require_once __DIR__ . '/zoho.php';        // zoho_config(), zoho_api()
    @set_time_limit(120);
    $bpCfg    = zoho_config();
    $bpAuthFile = __DIR__ . '/data/ben_auth.json';

    $bpCreds = null;
    if (is_file($bpAuthFile)) { $j = json_decode(@file_get_contents($bpAuthFile), true); if (is_array($j) && !empty($j['user']) && !empty($j['hash'])) $bpCreds = ['user'=>$j['user'], 'hash'=>$j['hash'], 'mode'=>'hash']; }
    if (!$bpCreds) { $u = trim((string)($bpCfg['ben_user'] ?? '')); $p = (string)($bpCfg['ben_pass'] ?? ''); if ($u !== '' && $p !== '') $bpCreds = ['user'=>$u, 'pass'=>$p, 'mode'=>'plain']; }
    $bpPrefs = bp_prefs(__DIR__ . '/data'); $bpDisabled = !empty($bpPrefs['disabled']);

    if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?portal=ben'); exit; }

    $bpErr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ben_login'])) {
        $u = trim((string)($_POST['u'] ?? '')); $p = (string)($_POST['p'] ?? '');
        $ok = false;
        if ($bpCreds && !$bpDisabled) {
            if ($bpCreds['mode'] === 'hash') $ok = hash_equals(strtolower($bpCreds['user']), strtolower($u)) && password_verify($p, $bpCreds['hash']);
            else                             $ok = hash_equals($bpCreds['user'], $u) && hash_equals($bpCreds['pass'], $p);
        }
        if ($ok) { session_regenerate_id(true); $_SESSION['ben_auth'] = true; $_SESSION['ben_user'] = $u; header('Location: index.php?portal=ben'); exit; }
        $bpErr = $bpDisabled ? 'This portal is currently disabled.' : 'Wrong username or password.';
    }
    $bpAuthed = !$bpDisabled && !empty($_SESSION['ben_auth']);   // disabling instantly cuts active sessions too
    $bpPreviewOn = $bpPrefs['preview'];

    if (isset($_GET['data'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$bpAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        try { $bpData = bp_build(isset($_GET['refresh'])); bp_apply_overrides($bpData, bp_load_overrides(__DIR__ . '/data')); echo json_encode($bpData); }
        catch (\Throwable $e) { http_response_code(500); echo api_fail($e); }
        exit;
    }

    // Invoice PDF preview — streamed from Zoho via our token. Only serves an
    // invoice that belongs to Ben's allowed companies/years (no enumeration).
    if (isset($_GET['pdf'])) {
        if (!$bpAuthed) { http_response_code(403); echo 'Not signed in.'; exit; }
        if (!$bpPreviewOn) { http_response_code(403); echo 'Preview unavailable — please check your browser plugins.'; exit; }
        $pid = preg_replace('/[^0-9]/', '', (string)$_GET['pdf']);
        $allowed = false;
        if ($pid !== '') {
            try { $bpData = bp_build(false); } catch (\Throwable $e) { http_response_code(500); echo 'Error.'; exit; }
            foreach (($bpData['years'] ?? []) as $yd) { foreach ($yd['companies'] as $c) { foreach ($c['invoices'] as $iv) { if ((string)($iv['id'] ?? '') === $pid) { $allowed = true; break 3; } } } }
        }
        if (!$allowed) { http_response_code(404); echo 'Not found.'; exit; }
        $cfg = zoho_config();
        try { $token = zoho_access_token(); } catch (\Throwable $e) { http_response_code(502); echo 'Auth error.'; exit; }
        $url = $cfg['api_domain'] . '/books/v3/invoices/' . rawurlencode($pid) . '?' . http_build_query(['organization_id'=>$cfg['organization_id'], 'accept'=>'pdf']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>45, CURLOPT_HTTPHEADER=>['Authorization: Zoho-oauthtoken ' . $token, 'Accept: application/pdf']]);
        $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $code >= 400 || substr((string)$body, 0, 4) !== '%PDF') { http_response_code(502); echo 'Preview unavailable — please check your browser plugins.'; exit; }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="invoice-' . $pid . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        echo $body; exit;
    }

    // Ask AI — answers scoped strictly to Ben's own invoices.
    if (isset($_GET['ai'])) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$bpAuthed) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Not signed in.']); exit; }
        $key = trim((string)($bpCfg['anthropic_api_key'] ?? ''));
        if ($key === '') { echo json_encode(['ok'=>false, 'error'=>'The assistant isn’t set up yet.']); exit; }
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $q = trim((string)($in['question'] ?? ''));
        $history = is_array($in['history'] ?? null) ? $in['history'] : [];
        if ($q === '') { echo json_encode(['ok'=>false, 'error'=>'Ask a question.']); exit; }
        if (mb_strlen($q) > 500) $q = mb_substr($q, 0, 500);
        // Daily question cap (shared across all Ben sessions) to keep costs predictable.
        $AI_DAILY_CAP = 30;
        $usageFile = __DIR__ . '/data/ben_ai_usage.json';
        $today = date('Y-m-d');
        $u = is_file($usageFile) ? (json_decode(@file_get_contents($usageFile), true) ?: []) : [];
        $cnt = (($u['day'] ?? '') === $today) ? (int)($u['count'] ?? 0) : 0;
        if ($cnt >= $AI_DAILY_CAP) { echo json_encode(['ok'=>false, 'error'=>'You’ve reached today’s question limit. Please try again tomorrow.']); exit; }
        $resp = null; $logAns = '';
        try {
            $data = bp_build(false); bp_apply_overrides($data, bp_load_overrides(__DIR__ . '/data'));
            @file_put_contents($usageFile, json_encode(['day'=>$today, 'count'=>$cnt + 1]));   // count this API call
            $ans = bp_ask($key, bp_ai_context($data), $q, $history);
            $resp = ['ok'=>true, 'answer'=>$ans]; $logAns = $ans;
        } catch (\Throwable $e) { $resp = ['ok'=>false, 'error'=>'The assistant is unavailable right now.']; $logAns = '(unavailable)'; }
        // record the question + answer for the admin to review
        $logFile = __DIR__ . '/data/ben_ai_log.json';
        $log = is_file($logFile) ? (json_decode(@file_get_contents($logFile), true) ?: []) : [];
        $log[] = ['at'=>date('c'), 'user'=>(string)($_SESSION['ben_user'] ?? 'ben'), 'q'=>$q, 'a'=>mb_substr((string)$logAns, 0, 2000)];
        if (count($log) > 500) $log = array_slice($log, -500);
        @file_put_contents($logFile, json_encode($log));
        echo json_encode($resp); exit;
    }

    $bpEsc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BEN PORTAL — WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Arial' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>(function(){try{if(localStorage.getItem('benTheme')==='light')document.documentElement.classList.add('light');}catch(e){}})();</script>
<style>
  :root{
    --orange:#F56F00;--blue:#5B8DEF;--purple:#8B6EF3;--good:#34D399;--bad:#F87171;
    --bg:#0E1826;--card:#16212F;--ink:#E7EDF5;--mute:#93A4B8;--line:#273444;--hair:#1F2C3B;
    --th:#1B2735;--hover:#1B2A3B;--foot:#1B2735;--cohead1:#1A2634;--cohead2:#16212F;--inpbg:#111C29;
  }
  html.light{
    --blue:#2350C5;--purple:#7C3AED;--good:#16A34A;--bad:#D64933;
    --bg:#F4F6FA;--card:#fff;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--hair:#EEF1F5;
    --th:#F8FAFC;--hover:#FFF8F3;--foot:#F4F7FB;--cohead1:#FAFBFE;--cohead2:#F3F6FB;--inpbg:#fff;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:'Inter','Segoe UI',system-ui,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:13px;-webkit-font-smoothing:antialiased;transition:background .2s,color .2s}
  .top{background:linear-gradient(135deg,#1B2A3A,#101a27);color:#fff;padding:10px 14px;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:50;box-shadow:0 2px 10px rgba(0,0,0,.3)}
  .b{width:28px;height:28px;border-radius:7px;background:var(--orange);display:grid;place-items:center;font-weight:800;font-size:11px;color:#fff;flex:0 0 auto}
  .top h1{font-size:12px;margin:0;font-weight:700;letter-spacing:.4px}
  .top .sub{font-size:9.5px;color:#9AA7B8;letter-spacing:.4px}
  .top .sp{margin-left:auto;display:flex;gap:8px;align-items:center}
  .tbtn{border:1px solid rgba(255,255,255,.2);color:#fff;background:rgba(255,255,255,.1);border-radius:8px;padding:6px 11px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;transition:background .15s;display:inline-flex;align-items:center;gap:6px}
  .tbtn:hover{background:rgba(255,255,255,.18)}
  .tbtn.ico{padding:7px}
  .wrap{max-width:1120px;margin:0 auto;padding:12px 12px 24px}
  .layout{display:flex;gap:14px;align-items:flex-start}
  .main{flex:1;min-width:0}
  .side{flex:0 0 300px;position:sticky;top:60px}
  .chrow{margin-bottom:9px}
  .chrow .cl{display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px}
  .chrow .cl b{font-weight:600}.chrow .cl span{font-variant-numeric:tabular-nums;color:var(--mute)}
  .chbar{height:8px;background:var(--hair);border-radius:6px;overflow:hidden}
  .chbar > i{display:block;height:100%;background:linear-gradient(90deg,var(--orange),#ffa657);border-radius:6px}
  @media(max-width:820px){ .layout{flex-direction:column} .side{position:static;flex-basis:auto;width:100%} }
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px}
  .muted{color:var(--mute);font-size:11px}
  .lab{font-size:9.5px;color:var(--mute);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
  .val{font-weight:700;font-size:15px;margin-top:2px;line-height:1.25}
  .sum{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
  .sum .card{flex:1;min-width:150px;padding:10px 13px}
  .co{margin-bottom:10px;overflow:hidden}
  .cohead{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 13px;background:linear-gradient(180deg,var(--cohead1),var(--cohead2));border-bottom:1px solid var(--line)}
  .cohead .nm{font-weight:700;font-size:13px}
  .cohead .cnt{font-size:10px;font-weight:700;color:#fff;background:var(--purple);border-radius:20px;padding:1px 8px}
  .cohead .mtot{margin-left:auto;text-align:right;font-size:11px}
  .cohead .mtot .o{color:var(--bad);font-weight:700}.cohead .mtot .i{color:var(--ink);font-weight:600}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:7px 10px;border-bottom:1px solid var(--hair);text-align:left}
  th{font-size:9px;text-transform:uppercase;color:var(--mute);letter-spacing:.3px;background:var(--th);font-weight:700}
  td.amt,th.amt{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
  tbody tr:hover{background:var(--hover)}
  tfoot td{font-weight:700;border-top:2px solid var(--line);background:var(--foot)}
  .pill{display:inline-block;font-size:9.5px;font-weight:700;padding:2px 8px;border-radius:20px;text-transform:capitalize}
  .pill.paid{background:rgba(52,211,153,.16);color:#34D399}.pill.overdue{background:rgba(248,113,113,.16);color:#F87171}
  .pill.sent,.pill.unpaid{background:rgba(91,141,239,.18);color:#7FA9F5}.pill.partially_paid{background:rgba(245,158,11,.16);color:#F59E0B}.pill.other{background:rgba(148,164,184,.16);color:var(--mute)}
  .empty td{color:var(--mute);font-style:italic}
  .bar{position:fixed;top:0;left:0;height:3px;background:var(--orange);width:0;transition:width .2s,opacity .3s;opacity:0;box-shadow:0 0 8px var(--orange);z-index:999}
  .pgfoot{text-align:center;padding:16px 12px 20px;line-height:1.7}
  .pgfoot .cn{font-size:11.5px;color:var(--mute)}.pgfoot .sc{font-size:11px;color:var(--orange);margin-top:4px}
  .login{max-width:360px;margin:9vh auto 0;padding:0 16px}
  .login .card{padding:22px}.login h2{margin:0 0 4px;font-size:17px}
  .login .in{width:100%;padding:11px 13px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:14px;margin-top:12px;background:var(--inpbg);color:var(--ink)}
  .login .in:focus{outline:none;border-color:var(--orange);box-shadow:0 0 0 3px rgba(245,111,0,.15)}
  .login .go{width:100%;margin-top:14px;border:0;background:var(--orange);color:#fff;padding:12px;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
  .login .go:hover{filter:brightness(1.05)}
  .login .err{background:rgba(248,113,113,.14);color:#F87171;border-radius:8px;padding:9px 11px;font-size:12px;margin-top:12px}
  .ytabs{display:flex;gap:6px;align-items:center;margin:2px 0 12px;flex-wrap:wrap}
  .ytab{border:1px solid var(--line);background:var(--card);border-radius:10px;padding:8px 18px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--mute);display:flex;align-items:center;gap:8px;transition:all .12s}
  .ytab:hover{border-color:var(--orange)}
  .ytab.on{background:var(--orange);color:#fff;border-color:var(--orange)}
  .ytab .yc{font-size:10px;font-weight:700;background:rgba(128,128,128,.20);border-radius:20px;padding:1px 8px}
  .ytab.on .yc{background:rgba(255,255,255,.25)}
  .prbtn{margin-left:auto;border:1px solid var(--line);background:var(--card);border-radius:10px;padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;color:var(--ink);display:inline-flex;align-items:center;gap:6px}
  .prbtn:hover{background:var(--hover)}
  .yhead{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap;margin:0 2px 12px;font-size:13.5px}
  .yhead .yt{font-weight:600;color:var(--ink)}
  td.desc{color:var(--ink);max-width:360px;font-size:11.5px}
  .pvlink{color:var(--blue);cursor:pointer;font-weight:600;text-decoration:underline;text-decoration-style:dotted}
  .pvlink:hover{color:var(--orange)}
  .pvmodal{position:fixed;inset:0;z-index:200;background:rgba(4,10,18,.72);display:none;align-items:center;justify-content:center;padding:18px}
  .pvmodal.open{display:flex}
  .pvcard{background:var(--card);width:100%;max-width:860px;height:90vh;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.6)}
  .pvhead{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 15px;border-bottom:1px solid var(--line);background:var(--cohead1)}
  .pvhead b{font-size:13px}
  .pvx{width:32px;height:32px;border-radius:9px;border:1px solid var(--line);background:var(--card);cursor:pointer;font-size:15px;color:var(--mute);display:grid;place-items:center}
  .pvx:hover{background:rgba(248,113,113,.14);color:var(--bad)}
  .fab{position:fixed;right:18px;bottom:18px;z-index:150;display:inline-flex;align-items:center;gap:8px;background:var(--orange);color:#fff;border:0;border-radius:30px;padding:12px 18px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;box-shadow:0 10px 28px rgba(245,111,0,.45)}
  .fab:hover{filter:brightness(1.06)}
  .aiwrap{position:fixed;right:18px;bottom:78px;z-index:151;width:372px;max-width:calc(100vw - 24px);height:520px;max-height:calc(100vh - 120px);background:var(--card);border:1px solid var(--line);border-radius:16px;box-shadow:0 24px 60px rgba(0,0,0,.5);display:none;flex-direction:column;overflow:hidden}
  .aiwrap.open{display:flex}
  .aihead{display:flex;align-items:center;gap:9px;padding:12px 14px;border-bottom:1px solid var(--line);background:linear-gradient(135deg,var(--cohead1),var(--cohead2))}
  .aihead .t{font-weight:700;font-size:13px}
  .aihead .x{margin-left:auto;cursor:pointer;color:var(--mute);background:transparent;border:0;display:grid;place-items:center;padding:2px}
  .aibody{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:10px}
  .aimsg{max-width:86%;padding:9px 12px;border-radius:13px;font-size:12.5px;line-height:1.5}
  .aimsg.u{align-self:flex-end;background:var(--orange);color:#fff;border-bottom-right-radius:4px}
  .aimsg.a{align-self:flex-start;background:var(--th);color:var(--ink);border:1px solid var(--line);border-bottom-left-radius:4px}
  .aifoot{padding:10px;border-top:1px solid var(--line);display:flex;gap:8px;align-items:flex-end}
  .aifoot textarea{flex:1;resize:none;max-height:90px;border:1px solid var(--line);border-radius:11px;padding:9px 11px;font-family:inherit;font-size:12.5px;background:var(--inpbg);color:var(--ink)}
  .aifoot textarea:focus{outline:none;border-color:var(--orange)}
  .aifoot .snd{border:0;background:var(--orange);color:#fff;border-radius:11px;height:38px;width:42px;cursor:pointer;display:grid;place-items:center;flex:0 0 auto}
  .aiempty{color:var(--mute);font-size:12px;text-align:center;margin:auto;padding:16px}
  .aichip{display:inline-block;margin:3px;border:1px solid var(--line);border-radius:16px;padding:5px 10px;font-size:11px;cursor:pointer;color:var(--ink);background:var(--card)}
  .aichip:hover{border-color:var(--orange)}
  @media print{ .top,.ytabs,.pgfoot,.bar,.pvmodal,.fab,.aiwrap{display:none!important} html{--bg:#fff;--card:#fff;--ink:#15202B;--mute:#64748B;--line:#E6EAF0;--hair:#EEF1F5;--th:#F8FAFC;--hover:#fff;--foot:#F4F7FB} body{font-size:12px} .card{break-inside:avoid;box-shadow:none} .wrap{max-width:none;padding:0} }
  @media(max-width:560px){ .sum .card{min-width:120px} .cohead .mtot{width:100%;text-align:left;margin-left:0} td.desc{max-width:none} .aiwrap{right:8px;left:8px;width:auto} }
</style></head>
<body>
<div id="bar" class="bar"></div>
<div class="top">
  <div class="b">912</div>
  <div><h1>BEN PORTAL</h1><div class="sub">FABRIMETAL GROUP · 2025–2026 INVOICES</div></div>
  <?php if ($bpAuthed): ?>
  <div class="sp">
    <button class="tbtn ico" id="themeBtn" onclick="toggleTheme()" title="Toggle theme"></button>
    <button class="tbtn" onclick="load(true)" title="Refresh"><span data-ic="refresh" data-s="15"></span> Refresh</button>
    <a class="tbtn" href="index.php?portal=ben&logout=1" title="Sign out"><span data-ic="logout" data-s="15"></span> Sign out</a>
  </div>
  <?php endif; ?>
</div>
<?php if (!$bpAuthed): ?>
<div class="login">
  <div class="card">
    <h2>Ben Portal</h2>
    <div class="muted">Sign in to view the group's 2025–2026 invoices.</div>
    <?php if ($bpDisabled): ?>
      <div class="err">This portal is currently disabled. Please contact the administrator.</div>
    <?php elseif (!$bpCreds): ?>
      <div class="err">This portal isn't set up yet. The administrator needs to set a username and password in the console (Settings → Ben Portal access).</div>
    <?php else: ?>
    <form method="post" action="index.php?portal=ben" autocomplete="off">
      <input type="hidden" name="ben_login" value="1">
      <input class="in" type="text" name="u" placeholder="Username" autocapitalize="none" autocorrect="off" required>
      <input class="in" type="password" name="p" placeholder="Password" required>
      <?php if ($bpErr): ?><div class="err"><?= $bpEsc($bpErr) ?></div><?php endif; ?>
      <button class="go" type="submit">Sign in</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies</div><div class="sc">SECURE PORTAL</div></div>
</div>
<?php else: ?>
<div class="wrap layout">
  <div class="main" id="app"><div class="card muted" style="padding:14px">Loading invoices…</div></div>
  <aside class="side" id="side"></aside>
</div>
<div id="pvModal" class="pvmodal" onclick="if(event.target===this)closePreview()">
  <div class="pvcard">
    <div class="pvhead">
      <b id="pvTitle">Invoice</b>
      <span style="display:flex;gap:8px;align-items:center">
        <a id="pvOpen" href="#" target="_blank" rel="noopener" class="tbtn" style="color:var(--ink);border-color:var(--line);background:var(--card)">Open ↗</a>
        <button class="pvx" onclick="closePreview()">✕</button>
      </span>
    </div>
    <iframe id="pvFrame" src="about:blank" title="Invoice preview" style="flex:1;border:0;width:100%;background:#525659"></iframe>
  </div>
</div>
<button class="fab" onclick="aiToggle()"><span data-ic="spark" data-s="18"></span> Ask AI</button>
<div class="aiwrap" id="aiWrap">
  <div class="aihead"><span data-ic="spark" data-s="16" style="color:var(--orange)"></span><span class="t">Ask AI</span><button class="x" onclick="aiToggle()"><span data-ic="close" data-s="16"></span></button></div>
  <div class="aibody" id="aiBody"></div>
  <div class="aifoot">
    <textarea id="aiInput" rows="1" placeholder="Ask about your invoices…" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();aiSend();}"></textarea>
    <button class="snd" onclick="aiSend()"><span data-ic="send" data-s="18"></span></button>
  </div>
</div>
<div class="pgfoot"><div class="cn">Waitara Holdings Group of Companies · Ben Portal</div><div class="sc">CONFIDENTIAL</div></div>
<script>
const esc = s => String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtC = (cur,n) => Math.round(n||0).toLocaleString('en-US');
const fmtMap = m => { const k=Object.keys(m||{}); if(!k.length) return '—'; return k.sort().map(c=>fmtC(c,m[c])).join('  ·  '); };
const PREVIEW = <?php echo $bpPreviewOn ? 'true' : 'false'; ?>;
let DATA=null, YEAR=null;
/* ---- line icons (Lucide-style) ---- */
const IC={
 refresh:'<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
 printer:'<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
 logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
 sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
 moon:'<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
 spark:'<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
 send:'<path d="M22 2 11 13"/><path d="M22 2 15 22 11 13 2 9z"/>',
 close:'<path d="M18 6 6 18M6 6l12 12"/>'
};
function icon(n,s){ s=s||16; return `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px">${IC[n]||''}</svg>`; }
function fillIcons(root){ (root||document).querySelectorAll('[data-ic]').forEach(el=>{ el.innerHTML=icon(el.getAttribute('data-ic'), +el.getAttribute('data-s')||16); }); }
function updateThemeIcon(){ const b=document.getElementById('themeBtn'); if(b){ const light=document.documentElement.classList.contains('light'); b.innerHTML=icon(light?'moon':'sun',16); b.title=light?'Switch to dark':'Switch to light'; } }
function toggleTheme(){ const light=document.documentElement.classList.toggle('light'); try{localStorage.setItem('benTheme', light?'light':'dark');}catch(e){} updateThemeIcon(); }
/* ---- Ask AI ---- */
let AICHAT={busy:false,msgs:[]};
const AISUG=['Which invoices are still open?','How much was billed in 2026?','Summarise Fabrimetal Ghana','What was the Mali server invoice about?'];
function aiToggle(){ const w=document.getElementById('aiWrap'); const open=w.classList.toggle('open'); if(open){ aiRender(); setTimeout(()=>{const t=document.getElementById('aiInput'); if(t)t.focus();},60); } }
function aiFmt(t){ return esc(t).replace(/\*\*(.+?)\*\*/g,'<b>$1</b>').replace(/\n/g,'<br>'); }
function aiPick(q){ const t=document.getElementById('aiInput'); if(t) t.value=q; aiSend(); }
function aiRender(){
  const b=document.getElementById('aiBody'); if(!b) return;
  if(!AICHAT.msgs.length && !AICHAT.busy){ b.innerHTML=`<div class="aiempty">${icon('spark',26)}<div style="margin-top:8px;font-weight:600;color:var(--ink)">Ask about your invoices</div><div style="margin-top:10px">${AISUG.map(s=>`<span class="aichip" onclick="aiPick('${s.replace(/'/g,"\\'")}')">${esc(s)}</span>`).join('')}</div></div>`; return; }
  b.innerHTML=AICHAT.msgs.map(m=>`<div class="aimsg ${m.role==='user'?'u':'a'}">${m.role==='user'?esc(m.content):aiFmt(m.content)}</div>`).join('')+(AICHAT.busy?`<div class="aimsg a">…</div>`:'');
  b.scrollTop=b.scrollHeight;
}
async function aiSend(){
  const t=document.getElementById('aiInput'); const q=(t.value||'').trim(); if(!q||AICHAT.busy) return;
  AICHAT.msgs.push({role:'user',content:q}); t.value=''; AICHAT.busy=true; aiRender();
  const history=AICHAT.msgs.slice(0,-1).map(m=>({role:m.role,content:m.content}));
  try{ const r=await fetch('index.php?portal=ben&ai=1',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q,history})}); const j=await r.json(); AICHAT.busy=false; AICHAT.msgs.push({role:'assistant',content:(j&&j.ok)?j.answer:((j&&j.error)||'Sorry, something went wrong.')}); }
  catch(e){ AICHAT.busy=false; AICHAT.msgs.push({role:'assistant',content:'Request failed. Please try again.'}); }
  aiRender();
}
function bar(s){ const b=document.getElementById('bar'); if(!b)return; if(s){b.style.opacity='1';b.style.width='80%';}else{b.style.width='100%';setTimeout(()=>{b.style.opacity='0';b.style.width='0';},300);} }
async function load(refresh){ bar(true); try{ const r=await fetch('index.php?portal=ben&data=1'+(refresh?'&refresh=1':''),{credentials:'same-origin'}); DATA=await r.json(); }catch(e){ DATA={ok:false,error:String(e)}; } if(!YEAR&&DATA&&DATA.years){ const y=DATA.years; YEAR=((y['2026']||{}).count>0)?'2026':(((y['2025']||{}).count>0)?'2025':'2026'); } bar(false); render(); }
function pillClass(s){ s=(s||'').toLowerCase(); return ['paid','overdue','sent','unpaid','partially_paid'].includes(s)?s:'other'; }
function anyOut(m){ return Object.values(m||{}).some(v=>v>0.5); }
const COUNTRY={'Fabri Metal Congo SARL':'DR Congo','CIMMETAL Burkina':'Burkina Faso','SteelRwa Industries Ltd':'Rwanda','Fabrimetal Senegal Sebikotane':'Senegal','Fabrimetal Burundi':'Burundi','Fabrimetal Angola':'Angola','IMAFER Mali':'Mali','Fabrimetal Ghana Ltd.':'Ghana','Fabrimetal Benin':'Benin','MMD':'MMD','Fabrimetal Senegal SINDIA':'Senegal'};
function countryOf(n){ return COUNTRY[n]||n; }
function renderChart(){
  const side=document.getElementById('side'); if(!side) return;
  const yd=((DATA&&DATA.years)||{})[YEAR];
  if(!yd||!(yd.companies||[]).length){ side.innerHTML=''; return; }
  const tot={};
  yd.companies.forEach(c=>{ let sum=0; Object.values(c.invoicedByCur||{}).forEach(v=>sum+=v); const k=countryOf(c.name); tot[k]=(tot[k]||0)+sum; });
  const rows=Object.entries(tot).sort((a,b)=>b[1]-a[1]); const max=rows[0][1]||1;
  const bars=rows.map(([k,v])=>`<div class="chrow"><div class="cl"><b>${esc(k)}</b><span>${Math.round(v).toLocaleString('en-US')}</span></div><div class="chbar"><i style="width:${Math.max(3,Math.round(v/max*100))}%"></i></div></div>`).join('');
  side.innerHTML=`<div class="card" style="padding:14px"><div style="font-weight:700;font-size:12.5px;margin-bottom:12px">Spend by country · ${YEAR}</div>${bars}</div>`;
}
function openPreview(id,number){
  if(!id) return;
  const url='index.php?portal=ben&pdf='+encodeURIComponent(id);
  document.getElementById('pvTitle').textContent='Invoice '+(number||'');
  document.getElementById('pvOpen').href=url;
  document.getElementById('pvFrame').src=url;
  document.getElementById('pvModal').classList.add('open');
}
function closePreview(){ document.getElementById('pvModal').classList.remove('open'); document.getElementById('pvFrame').src='about:blank'; }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closePreview(); });
function setYear(y){ YEAR=y; render(); window.scrollTo(0,0); }
function printYear(){ window.print(); }
function render(){
  const app=document.getElementById('app'); if(!DATA) return;
  if(DATA.ok===false){ app.innerHTML='<div class="card" style="color:var(--bad);padding:14px">Error: '+esc(DATA.error||'failed')+'</div>'; renderChart(); return; }
  const years=DATA.years||{};
  if(!YEAR) YEAR=((years['2026']||{}).count>0)?'2026':'2025';
  const yd=years[YEAR]||{count:0,companies:[],invoicedByCur:{},outstandingByCur:{}};
  const tab=y=>`<button class="ytab ${YEAR===y?'on':''}" onclick="setYear('${y}')">${y}<span class="yc">${(years[y]||{}).count||0}</span></button>`;
  let html=`<div class="ytabs">${tab('2025')}${tab('2026')}<button class="prbtn" onclick="printYear()">${icon('printer',14)} Print ${YEAR}</button></div>`;
  const cos=yd.companies||[];
  html+=`<div class="yhead">
    <div><b style="font-size:15px">${YEAR}</b> · ${yd.count||0} invoice${yd.count===1?'':'s'} across ${cos.length} compan${cos.length===1?'y':'ies'}</div>
    <div class="yt">Billed in ${YEAR}: ${esc(fmtMap(yd.invoicedByCur))}${anyOut(yd.outstandingByCur)?` &nbsp;·&nbsp; <span class="muted">Open: ${esc(fmtMap(yd.outstandingByCur))}</span>`:''}</div>
  </div>`;
  if(!cos.length){ html+='<div class="card muted" style="padding:16px">No invoices for '+YEAR+'.</div>'; app.innerHTML=html; renderChart(); return; }
  cos.forEach(c=>{
    const rows=c.invoices.map(iv=>`<tr>
        <td>${PREVIEW?`<span class="pvlink" onclick="openPreview('${esc(iv.id)}','${esc(iv.number)}')">${esc(iv.number)}</span>`:esc(iv.number)}</td>
        <td>${esc(iv.date||'')}</td>
        <td class="desc">${esc(iv.desc||'—')}</td>
        <td><span class="pill ${pillClass(iv.status)}">${esc((iv.status||'').replace(/_/g,' '))}</span></td>
        <td class="amt">${esc(fmtC(iv.currency,iv.total))}</td></tr>`).join('');
    html += `<div class="card co">
        <div class="cohead"><span class="nm">${esc(c.name)}</span><span class="cnt">${c.count}</span>
          <span class="mtot"><span class="i">${esc(fmtMap(c.invoicedByCur))}</span></span></div>
        <div style="overflow-x:auto"><table>
          <thead><tr><th>Invoice #</th><th>Date</th><th>What it's for</th><th>Status</th><th class="amt">Amount</th></tr></thead>
          <tbody>${rows}</tbody>
          <tfoot><tr><td colspan="4">Total · ${c.count} invoice${c.count===1?'':'s'}</td><td class="amt">${esc(fmtMap(c.invoicedByCur))}</td></tr></tfoot>
        </table></div></div>`;
  });
  html+=`<div class="muted" style="margin:10px 2px">Updated ${DATA.asOf?new Date(DATA.asOf).toLocaleString('en-GB'):'now'}.</div>`;
  app.innerHTML=html;
  renderChart();
}
fillIcons(); updateThemeIcon();
load(false);
</script>
<?php endif; ?>
</body></html>
    <?php
    exit;
}

/* harden the session cookie before it is created (Secure only over HTTPS so HTTP isn't locked out) */
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_strict_mode', '1');
    @session_set_cookie_params([
        'lifetime'  => 0,
        'path'      => '/',
        'httponly'  => true,
        'samesite'  => 'Lax',
        'secure'    => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}
