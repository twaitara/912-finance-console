<?php
require_once __DIR__ . '/errors.php';
/* ============================================================================
   Self-hosted PWA assets — served straight from THIS file via ?pwa=… so that a
   partial deploy (uploading only index.php) can never leave the manifest,
   service worker or icons missing. Public: no session/auth required.
   ============================================================================ */
if (isset($_GET['pwa'])) {
    $pwa = $_GET['pwa'];
    if ($pwa === 'manifest') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name'             => '912 Finance Console',
            'short_name'       => '912',
            'description'      => 'Waitara Holdings Group — finance console',
            'start_url'        => 'index.php',
            'scope'            => './',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0F1B2D',
            'theme_color'      => '#15202B',
            'icons' => [
                ['src' => 'index.php?pwa=icon&s=192',        'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => 'index.php?pwa=icon&s=512',        'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => 'index.php?pwa=icon&s=512&mask=1', 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($pwa === 'icon') {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=604800');
        if (!empty($_GET['mask'])) {   // maskable: full-bleed orange, logo inside the safe zone
            echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" fill="#F56F00"/><text x="256" y="322" font-family="Arial,Helvetica,sans-serif" font-size="168" font-weight="700" fill="#fff" text-anchor="middle">912</text></svg>';
        } else {
            echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#F56F00"/><text x="256" y="332" font-family="Arial,Helvetica,sans-serif" font-size="210" font-weight="700" fill="#fff" text-anchor="middle">912</text></svg>';
        }
        exit;
    }
    if ($pwa === 'sw') {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Service-Worker-Allowed: ./');
        echo <<<'JS'
/* 912 Console service worker (served from index.php?pwa=sw). Install-only: never
   caches index.php or /api, so auth + live data stay fresh. */
var OFFLINE='<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><style>body{font-family:Inter,system-ui,sans-serif;background:#0F1B2D;color:#E7EDF5;display:grid;place-items:center;height:100vh;margin:0;text-align:center}b{color:#F56F00}</style><div><h2><b>912</b> Console</h2><p>You appear to be offline. Reconnect and reopen the app.</p></div>';
self.addEventListener('install',function(e){self.skipWaiting();});
self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});
self.addEventListener('fetch',function(e){var r=e.request;if(r.mode==='navigate'){e.respondWith(fetch(r).catch(function(){return new Response(OFFLINE,{headers:{'Content-Type':'text/html'}});}));}});
JS;
        exit;
    }
    http_response_code(404);
    exit;
}

/* ============================================================================
   Zoho Books webhook receiver — served straight from THIS file via
   ?hook=zoho&k=SECRET so a partial deploy (only index.php) can never leave the
   endpoint missing. Public: no session/auth. On ANY change event (payment,
   invoice, estimate, expense) it clears the Zoho-derived caches so the very next
   page / report / "Ask your books" pull rebuilds from live Zoho instantly.
   Set  $config['webhook_secret'] = '…'  in config.php on the server to enable.
   ============================================================================ */
if (isset($_GET['hook']) && $_GET['hook'] === 'zoho') {
    header('Content-Type: application/json; charset=utf-8');
    $whCfg  = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
    $secret = is_array($whCfg) ? trim((string)($whCfg['webhook_secret'] ?? '')) : '';
    $given  = (string)($_GET['k'] ?? ($_SERVER['HTTP_X_912_KEY'] ?? ''));
    if ($secret === '') { http_response_code(503); echo json_encode(['ok'=>false, 'error'=>'webhook not configured']); exit; }
    if (!hash_equals($secret, $given)) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'bad key']); exit; }

    // Clear every Zoho-derived cache so the next read pulls fresh. (Auth tokens,
    // FX, settings and append-caches are deliberately left untouched.)
    $dir = __DIR__ . '/data';
    $patterns = [
        'ask_context_v2.json', 'audrey_unpaid_v4.json', 'email_clients_v6.json',
        'etr_dups_v2.json', 'etr_v4_*.json', 'quotestatus_v3_*.json',
        'invstatus_v2_*.json', 'report_v6_*.json', 'ben_invoices_v*.json',
    ];
    $cleared = 0;
    foreach ($patterns as $pat) { foreach (glob($dir . '/' . $pat) ?: [] as $f) { if (@unlink($f)) $cleared++; } }

    // Best-effort audit trail (self-rotating at ~200 KB).
    $raw = (string)file_get_contents('php://input');
    $j   = json_decode($raw, true);
    $evt = is_array($j) ? (string)($j['event_type'] ?? ($j['eventType'] ?? '')) : '';
    $logF = $dir . '/webhook_log.txt';
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        $line = date('c') . "  cleared=$cleared  evt=" . substr($evt, 0, 60) . "  " . substr(preg_replace('/\s+/', ' ', $raw), 0, 300) . "\n";
        $flags = (is_file($logF) && filesize($logF) < 200000) ? (FILE_APPEND | LOCK_EX) : LOCK_EX;
        @file_put_contents($logF, $line, $flags);
    }
    echo json_encode(['ok'=>true, 'cleared'=>$cleared]);
    exit;
}

/* ============================================================================
   BEN PORTAL — served straight from THIS file via ?portal=ben so it works even
   when only index.php is deployed (no separate ben.php upload needed). Private,
   login-protected view of ALL 2025–2026 invoices for the Fabrimetal / CIMMETAL /
   SteelRwa group. Own session (BENPORTAL). Credentials set by the admin in
   Settings → Ben Portal access, stored hashed in data/ben_auth.json.
   ============================================================================ */
require_once __DIR__ . '/portal_ben.php';
session_start();
require_once __DIR__ . '/csrf.php'; csrf_guard();
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/settings_store.php';
require_once __DIR__ . '/users_store.php';
$cfg = array_merge($cfg, app_settings_effective($cfg));   // user settings (with defaults) override config

// ---- Persistent "stay signed in" on mobile (remember-me cookie). Functions are defined inline
//      here (not a separate file) so a partial deploy of only index.php still works. ----
if (!function_exists('remember_lookup')) {
    function _remember_file() { $d = __DIR__ . '/data'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d . '/remember_tokens.json'; }
    function _remember_load() { $f = _remember_file(); if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) return $j; } return []; }
    function _remember_save($a) { @file_put_contents(_remember_file(), json_encode($a)); }
    function remember_issue($data, $days = 90) {
        $token = bin2hex(random_bytes(32)); $h = hash('sha256', $token); $now = time(); $all = _remember_load();
        foreach ($all as $k => $v) { if (($v['exp'] ?? 0) < $now) unset($all[$k]); }
        $all[$h] = ['user'=>(string)($data['user']??''),'email'=>(string)($data['email']??''),'is_admin'=>(int)($data['is_admin']??0)?1:0,'tabs'=>(string)($data['tabs']??''),'exp'=>$now + $days*86400];
        _remember_save($all); return $token;
    }
    function remember_lookup($token) {
        if (!$token) return null; $h = hash('sha256', $token); $all = _remember_load(); $v = $all[$h] ?? null;
        if (!$v) return null; if (($v['exp'] ?? 0) < time()) { unset($all[$h]); _remember_save($all); return null; } return $v;
    }
    function remember_forget($token) {
        if (!$token) return; $h = hash('sha256', $token); $all = _remember_load();
        if (isset($all[$h])) { unset($all[$h]); _remember_save($all); }
    }
}
$RMB_OK = function_exists('remember_lookup');
$IS_MOBILE  = !empty($_SERVER['HTTP_USER_AGENT']) && preg_match('/Mobile|Android|iPhone|iPad|iPod|Windows Phone/i', (string)$_SERVER['HTTP_USER_AGENT']);
$RMB_SECURE = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

if ($RMB_OK && empty($_SESSION['auth']) && !empty($_COOKIE['rmb912'])) {
    try {
        $rv = remember_lookup($_COOKIE['rmb912']);
        if ($rv) {
            $_SESSION['auth']     = true;
            $_SESSION['user']     = $rv['user'];
            $_SESSION['email']    = $rv['email'] ?? '';
            $_SESSION['is_admin'] = (int)($rv['is_admin'] ?? 0) ? 1 : 0;
            $_SESSION['tabs']     = $rv['tabs'] ?? '';
        }
    } catch (\Throwable $e) { /* ignore — never block login */ }
}

/* Admin-only: Zoho-webhook status JSON for the Settings panel. Inline (not a
   separate api/ file) so a partial deploy of only index.php still serves it. */
if (isset($_GET['whstatus'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
    $configured = trim((string)($cfg['webhook_secret'] ?? '')) !== '';
    $logF = __DIR__ . '/data/webhook_log.txt';
    $lastAt = null; $lastEvt = ''; $today = 0; $total = 0; $recent = [];
    if (is_file($logF)) {
        $lines = @file($logF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $total = count($lines);
        $todayStr = date('Y-m-d');
        foreach ($lines as $ln) { if (strpos($ln, $todayStr) === 0) $today++; }
        $tail = array_slice($lines, -6);
        foreach (array_reverse($tail) as $ln) { $recent[] = $ln; }
        if ($tail) {
            $last = $tail[count($tail) - 1];
            $lastAt = substr($last, 0, 25);
            if (preg_match('/evt=([^ ]*)/', $last, $m)) $lastEvt = $m[1];
        }
    }
    echo json_encode(['ok'=>true, 'configured'=>$configured, 'lastAt'=>$lastAt, 'lastEvt'=>$lastEvt, 'today'=>$today, 'total'=>$total, 'recent'=>$recent]);
    exit;
}

/* Admin-only: read/set the BEN PORTAL credentials (stored hashed in
   data/ben_auth.json). Inline so a partial deploy of only index.php serves it. */
if (isset($_GET['bencreds'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
    $bf  = __DIR__ . '/data/ben_auth.json';
    $curB = is_file($bf) ? (json_decode(@file_get_contents($bf), true) ?: []) : [];
    $inB = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($inB['action'] ?? '') === 'save') {
        $u = trim((string)($inB['user'] ?? ''));
        $p = (string)($inB['pass'] ?? '');
        if ($u === '') { echo json_encode(['ok'=>false, 'error'=>'Username is required.']); exit; }
        $hash = (string)($curB['hash'] ?? '');
        if ($p !== '') { if (strlen($p) < 4) { echo json_encode(['ok'=>false, 'error'=>'Password must be at least 4 characters.']); exit; } $hash = password_hash($p, PASSWORD_DEFAULT); }
        if ($hash === '') { echo json_encode(['ok'=>false, 'error'=>'Set a password for the first time.']); exit; }
        $d = __DIR__ . '/data'; if (!is_dir($d)) @mkdir($d, 0775, true);
        @file_put_contents($bf, json_encode(['user'=>$u, 'hash'=>$hash, 'updated'=>date('c')]));
        echo json_encode(['ok'=>true, 'configured'=>true, 'user'=>$u]); exit;
    }
    echo json_encode(['ok'=>true, 'configured'=>(!empty($curB['user']) && !empty($curB['hash'])), 'user'=>(string)($curB['user'] ?? '')]);
    exit;
}

/* Admin-only: list Ben-portal invoices and edit their descriptions. Overrides
   are stored in data/ben_desc_overrides.json and applied live at portal render
   (no cache clear needed). */
if (isset($_GET['bendesc'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
    require_once __DIR__ . '/zoho.php';
    $bdDir = __DIR__ . '/data'; $bdFile = $bdDir . '/ben_desc_overrides.json';
    $inD = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($inD['action'] ?? '') === 'save') {
        $num = trim((string)($inD['number'] ?? ''));
        if ($num === '') { echo json_encode(['ok'=>false, 'error'=>'Missing invoice number.']); exit; }
        $label = trim((string)($inD['label'] ?? ''));
        $ov = bp_load_overrides($bdDir);
        if ($label === '') unset($ov[$num]); else $ov[$num] = mb_substr($label, 0, 200);
        if (!is_dir($bdDir)) @mkdir($bdDir, 0775, true);
        @file_put_contents($bdFile, json_encode($ov));
        echo json_encode(['ok'=>true, 'number'=>$num, 'label'=>$label]); exit;
    }
    try { $data = bp_build(false); } catch (\Throwable $e) { http_response_code(500); echo api_fail($e); exit; }
    $ov = bp_load_overrides($bdDir);
    $list = [];
    foreach (($data['years'] ?? []) as $y => $yd) {
        foreach (($yd['companies'] ?? []) as $c) {
            foreach (($c['invoices'] ?? []) as $iv) {
                $num = (string)($iv['number'] ?? '');
                $list[] = ['id'=>(string)($iv['id'] ?? ''), 'number'=>$num, 'company'=>(string)$c['name'], 'year'=>(string)$y, 'date'=>(string)($iv['date'] ?? ''), 'total'=>(float)($iv['total'] ?? 0), 'currency'=>(string)($iv['currency'] ?? ''), 'auto'=>(string)($iv['autoDesc'] ?? ''), 'override'=>($num !== '' && isset($ov[$num])) ? (string)$ov[$num] : ''];
            }
        }
    }
    usort($list, function ($a, $b) { return strcasecmp($a['company'], $b['company']) ?: strcmp($b['date'], $a['date']); });
    echo json_encode(['ok'=>true, 'invoices'=>$list, 'count'=>count($list)]);
    exit;
}

/* Admin-only: toggle whether Ben can preview invoice PDFs. Stored in
   data/ben_prefs.json. */
if (isset($_GET['benpref'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
    $pfDir = __DIR__ . '/data'; $pfFile = $pfDir . '/ben_prefs.json';
    $inP = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($inP['action'] ?? '') === 'save') {
        $cur = is_file($pfFile) ? (json_decode(@file_get_contents($pfFile), true) ?: []) : [];
        if (array_key_exists('preview', $inP))  $cur['preview']  = !empty($inP['preview']);
        if (array_key_exists('disabled', $inP)) $cur['disabled'] = !empty($inP['disabled']);
        if (!is_dir($pfDir)) @mkdir($pfDir, 0775, true);
        @file_put_contents($pfFile, json_encode($cur));
    }
    $p = bp_prefs($pfDir);
    echo json_encode(['ok'=>true, 'preview'=>$p['preview'], 'disabled'=>$p['disabled']]);
    exit;
}

/* Admin-only: the log of questions Ben has asked the "Ask AI" assistant. */
if (isset($_GET['benailog'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false, 'error'=>'Admins only.']); exit; }
    $logFile = __DIR__ . '/data/ben_ai_log.json';
    $log = is_file($logFile) ? (json_decode(@file_get_contents($logFile), true) ?: []) : [];
    echo json_encode(['ok'=>true, 'log'=>array_reverse($log), 'count'=>count($log)]);
    exit;
}

/* Admin-only: stream any invoice PDF (for the admin-side preview in the Ben
   descriptions editor). */
if (isset($_GET['invpdf'])) {
    if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo 'Admins only.'; exit; }
    require_once __DIR__ . '/zoho.php';
    $pid = preg_replace('/[^0-9]/', '', (string)$_GET['invpdf']);
    if ($pid === '') { http_response_code(404); echo 'Not found.'; exit; }
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

// --- login gate (master password = admin; or a per-user account) ---
$justLoggedIn = false;
if (isset($_POST['app_password'])) {
    $pw = $_POST['app_password'];
    $un = trim($_POST['app_user'] ?? '');
    if (hash_equals($cfg['app_password'], $pw)) {
        $_SESSION['auth'] = true; $_SESSION['user'] = 'admin'; $_SESSION['is_admin'] = 1; $_SESSION['tabs'] = '*';
        $_SESSION['email'] = $un;                                   // remember who typed in the master login
        $justLoggedIn = true;
        // stamp the typed identity so master-password logins are traceable in the Activity log
        try { require_once __DIR__ . '/db.php'; require_once __DIR__ . '/activity_store.php'; activity_log(db(), 'admin', 'logged in', 'master' . ($un !== '' ? (' as ' . $un) : ' (no id entered)')); } catch (Exception $e) {}
    } else {
        $row = false;
        try { require_once __DIR__ . '/db.php'; $row = user_authenticate(db(), $un, $pw); } catch (Exception $e) { $row = false; }
        if ($row) {
            $_SESSION['auth'] = true;
            $_SESSION['user'] = $row['username'];
            $_SESSION['email'] = $row['email'] ?? '';
            $_SESSION['is_admin'] = (int)$row['is_admin'] ? 1 : 0;
            $_SESSION['tabs'] = (int)$row['is_admin'] ? '*' : (string)($row['tabs'] ?? '');
            $justLoggedIn = true;
            try { require_once __DIR__ . '/activity_store.php'; activity_log(db(), $row['username'], 'logged in', (int)$row['is_admin'] ? 'admin' : 'staff'); } catch (Exception $e) {}
        } else {
            $loginError = 'Wrong username or password.';
        }
    }
    // On a fresh mobile login, mint a 90-day remember token so the app stays signed in.
    if ($RMB_OK && $justLoggedIn && $IS_MOBILE && function_exists('remember_issue')) {
        try {
            $tok = remember_issue([
                'user'     => $_SESSION['user'],
                'email'    => $_SESSION['email'] ?? '',
                'is_admin' => $_SESSION['is_admin'],
                'tabs'     => $_SESSION['tabs'],
            ], 90);
            setcookie('rmb912', $tok, ['expires'=>time()+90*86400, 'path'=>'/', 'secure'=>$RMB_SECURE, 'httponly'=>true, 'samesite'=>'Lax']);
        } catch (\Throwable $e) { /* ignore */ }
    }
}
if (isset($_GET['logout'])) {
    if ($RMB_OK && !empty($_COOKIE['rmb912']) && function_exists('remember_forget')) {
        try { remember_forget($_COOKIE['rmb912']); } catch (\Throwable $e) {}
        setcookie('rmb912', '', ['expires'=>time()-3600, 'path'=>'/', 'secure'=>$RMB_SECURE, 'httponly'=>true, 'samesite'=>'Lax']);
    }
    session_destroy(); header('Location: index.php'); exit;
}

if (empty($_SESSION['auth'])):
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Poppins,Arial,sans-serif' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link rel="manifest" href="index.php?pwa=manifest">
<meta name="theme-color" content="#15202B">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="912 Console">
<link rel="apple-touch-icon" href="index.php?pwa=icon&s=180">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{font-family:Poppins,system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;color:#15202B;
       background:radial-gradient(1100px 700px at 18% -10%,#22344A 0%,transparent 55%),
                  radial-gradient(900px 600px at 110% 120%,rgba(245,111,0,.22) 0%,transparent 55%),
                  linear-gradient(135deg,#1B2A3A 0%,#15202B 55%,#0E1822 100%);
       overflow:hidden}
  /* soft floating glow accents */
  body::before,body::after{content:"";position:fixed;border-radius:50%;filter:blur(8px);pointer-events:none;z-index:0}
  body::before{width:260px;height:260px;left:-70px;top:-60px;background:radial-gradient(circle,rgba(245,111,0,.20),transparent 68%)}
  body::after{width:340px;height:340px;right:-110px;bottom:-120px;background:radial-gradient(circle,rgba(35,80,197,.20),transparent 68%)}
  .card{position:relative;z-index:1;background:rgba(255,255,255,.96);padding:34px 30px 30px;border-radius:22px;width:320px;text-align:center;
        box-shadow:0 30px 70px rgba(8,16,24,.55),0 2px 0 rgba(255,255,255,.5) inset;border:1px solid rgba(255,255,255,.5);
        animation:rise .5s cubic-bezier(.22,.61,.36,1) both}
  @keyframes rise{from{opacity:0;transform:translateY(16px) scale(.985)}to{opacity:1;transform:none}}
  .b{width:54px;height:54px;border-radius:16px;background:linear-gradient(140deg,#FF8A1E,#F56F00 65%);color:#fff;display:grid;place-items:center;
     font-weight:800;font-size:18px;margin:0 auto 16px;box-shadow:0 12px 26px rgba(245,111,0,.42),0 0 0 6px rgba(245,111,0,.10)}
  .ttl{font-weight:700;font-size:16px;letter-spacing:.2px}
  .sub{font-size:11px;color:#64748B;margin:4px 0 20px;letter-spacing:.6px;text-transform:uppercase}
  .fld{position:relative;margin-bottom:12px}
  input{width:100%;padding:13px 14px;border:1px solid #E6EAF0;border-radius:12px;font-size:14px;font-family:inherit;background:#FBFCFE;
        transition:border-color .15s,box-shadow .15s,background .15s}
  input:focus{outline:none;border-color:#F56F00;background:#fff;box-shadow:0 0 0 4px rgba(245,111,0,.15)}
  input::placeholder{color:#94A3B8}
  button{width:100%;padding:13px;background:linear-gradient(140deg,#FF8A1E,#F56F00 70%);color:#fff;border:none;border-radius:12px;font-weight:700;font-size:14px;
         font-family:inherit;cursor:pointer;margin-top:4px;box-shadow:0 12px 24px rgba(245,111,0,.34);transition:transform .12s,box-shadow .2s,filter .15s}
  button:hover{filter:brightness(1.04);box-shadow:0 16px 30px rgba(245,111,0,.42)}
  button:active{transform:scale(.98)}
  .err{color:#D64933;background:#FDECEA;font-size:12px;font-weight:600;margin-bottom:12px;padding:9px 11px;border-radius:10px}
  .foot{margin-top:18px;font-size:10.5px;color:#94A3B8;letter-spacing:.4px}
  @media (prefers-reduced-motion:reduce){*{animation:none!important}}
</style></head>
<body><form class="card" method="post" autocomplete="off">
  <div class="b">912</div>
  <div class="ttl">WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</div>
  <div class="sub">Live from Zoho Books</div>
  <?php if(!empty($loginError)) echo '<div class="err">'.$loginError.'</div>'; ?>
  <div class="fld"><input type="text" name="app_user" placeholder="Username or email"></div>
  <div class="fld"><input type="password" name="app_password" placeholder="Password" autofocus></div>
  <button type="submit">Open console →</button>
  <div class="foot">Secure access · Waitara Holdings</div>
</form></body></html>
<?php exit; endif; ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WAITARA HOLDINGS GROUP OF COMPANIES CONSOLE</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%23F56F00'/%3E%3Ctext x='32' y='45' font-family='Poppins,Arial,sans-serif' font-size='29' font-weight='700' fill='white' text-anchor='middle'%3E912%3C/text%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="manifest" href="index.php?pwa=manifest">
<meta name="theme-color" content="#15202B">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="912 Console">
<link rel="apple-touch-icon" href="index.php?pwa=icon&s=180">
<script>(function(){try{if(localStorage.getItem('theme912')==='dark')document.documentElement.classList.add('dark');}catch(e){}})();</script>
<link rel="stylesheet" href="app.css?v=<?php echo @filemtime(__DIR__.'/app.css'); ?>"></head>
<body>
<div class="wrap">
  <header>
    <div style="display:flex;align-items:center;gap:10px">
      <div class="b">912</div>
      <div class="livepill"><span class="livedot"></span>LIVE FROM ZOHO BOOKS</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <button id="installBtn" onclick="installClick()" title="Install this app" aria-label="Install app" style="display:none">⬇ Get app</button>
      <button id="themeBtn" onclick="toggleTheme()" title="Toggle dark mode" aria-label="Toggle dark mode">🌙</button>
      <button id="mobMenuBtn" onclick="openMobileNav()" aria-label="Open menu">☰</button>
      <a class="logout" href="?logout=1">Sign out</a>
    </div>
  </header>

  <div class="fundbar">
    <div class="row" style="margin-bottom:8px"><span class="muted">Fund deployed</span><span class="muted" id="pctOut">0%</span></div>
    <div class="meter"><div id="meterFill" style="width:0%"></div></div>
    <div class="row" style="margin-top:10px">
      <div><div class="lab">Out now</div><div style="font-weight:700;color:var(--orange)" id="exposure">—</div></div>
      <div style="text-align:right"><div class="lab">Available</div><div style="font-weight:700;color:var(--blue)" id="available">—</div></div>
    </div>
  </div>

  <div class="tabs">
    <button class="active" data-tab="dash">🏠 Dashboard</button>
    <button data-tab="projects">📋 Projects</button>
    <button data-tab="recv">💰 Receivables</button>

    <div class="navgroup">
      <button class="grp">💰 Working Capital <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="deploy">🚀 Deploy</button>
        <button data-tab="ledger">📒 Ledger</button>
        <button data-tab="loans">💸 Loans</button>
        <button data-tab="growth">📈 Growth</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">📊 Accounts Efficiency <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="report">💹 Profit Report</button>
        <button data-tab="etr">🔍 ETR Check</button>
        <button data-tab="invrep">🧾 Invoices</button>
        <button data-tab="quotes">📋 Quotes</button>
        <button data-tab="payments">💳 Record Payment</button>
        <button data-tab="bulkpay">⚡ Bulk Mark Paid</button>
        <button data-tab="stmtbuild">📑 Statement Builder</button>
        <button data-tab="latepay">⏰ Late Payers</button>
        <button data-tab="bulkexp">🧾 Log Expenses</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">🗂️ Quotes / Invoices <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="qlist">📋 Quotes</button>
        <button data-tab="ivlist">🧾 Invoices</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">✏️ Create <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="newquote">📝 New Quote</button>
        <button data-tab="myquotes">📂 My Quotes</button>
        <button data-tab="jobcards">🛠️ Job Cards</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">✅ Tasks <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="todo">☑️ To-Do</button>
        <button data-ext="tasks_board.php">📌 Task Board</button>
      </div>
    </div>

    <div class="navgroup">
      <button class="grp">👥 Clients <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="emails">✉️ Emails</button>
        <button data-ext="audrey.php">📊 Audrey Reports</button>
      </div>
    </div>

    <button data-tab="ask">🤖 Ask your books</button>

    <button data-tab="portals">🔐 Portals</button>

    <div class="navgroup">
      <button class="grp">⚙️ Settings <span class="car">▾</span></button>
      <div class="submenu">
        <button data-tab="settings">🔧 App Settings</button>
        <button data-tab="users">👤 Users</button>
        <button data-tab="clientaccess">🔗 Client Access</button>
        <button data-tab="activity">📋 Activity Log</button>
        <button data-ext="api/manual.php">📖 Technician Manual</button>
      </div>
    </div>

    <button id="globalRefreshBtn" onclick="tabRefresh()" title="Refresh current page"><span class="ricon">↻</span> Refresh</button>
  </div>

  <div class="pane" id="pane"></div>
</div>

<!-- ── "add to home screen" help sheet (steps injected per platform) ── -->
<div id="iosHelp" onclick="if(event.target===this)iosHelpClose()">
  <div class="sheet">
    <h3>📲 Install 912 Console</h3>
    <p>Add the app to your Home Screen so it opens full-screen and stays signed in.</p>
    <div id="installSteps"></div>
    <button class="btn" style="margin-top:14px" onclick="iosHelpClose()">Got it</button>
  </div>
</div>

<!-- ── Mobile slide-in nav drawer ── -->
<div id="mobOverlay" onclick="closeMobileNav()"></div>
<nav id="mobDrawer">
  <div id="mobDrawerHead">
    <div style="display:flex;align-items:center;gap:9px">
      <div class="b" style="width:28px;height:28px;font-size:12px;border-radius:7px">912</div>
      <span style="color:#CBD6E3;font-weight:700;font-size:13px">Console</span>
    </div>
    <button onclick="closeMobileNav()" aria-label="Close">✕</button>
  </div>
  <div id="mobDrawerBody">
    <button class="mob-item" data-tab="dash">🏠 Dashboard</button>
    <button class="mob-item" data-tab="projects">📋 Projects</button>
    <button class="mob-item" data-tab="recv">💰 Receivables</button>

    <div class="mob-sect">💰 Working Capital</div>
    <button class="mob-item mob-sub" data-tab="deploy">🚀 Deploy</button>
    <button class="mob-item mob-sub" data-tab="ledger">📒 Ledger</button>
    <button class="mob-item mob-sub" data-tab="loans">💸 Loans</button>
    <button class="mob-item mob-sub" data-tab="growth">📈 Growth</button>

    <div class="mob-sect">📊 Accounts Efficiency</div>
    <button class="mob-item mob-sub" data-tab="report">💹 Profit Report</button>
    <button class="mob-item mob-sub" data-tab="etr">🔍 ETR Check</button>
    <button class="mob-item mob-sub" data-tab="invrep">🧾 Invoices</button>
    <button class="mob-item mob-sub" data-tab="quotes">📋 Quotes</button>
    <button class="mob-item mob-sub" data-tab="payments">💳 Record Payment</button>
    <button class="mob-item mob-sub" data-tab="bulkpay">⚡ Bulk Mark Paid</button>
    <button class="mob-item mob-sub" data-tab="stmtbuild">📑 Statement Builder</button>
    <button class="mob-item mob-sub" data-tab="latepay">⏰ Late Payers</button>
    <button class="mob-item mob-sub" data-tab="bulkexp">🧾 Log Expenses</button>

    <div class="mob-sect">🗂️ Quotes / Invoices</div>
    <button class="mob-item mob-sub" data-tab="qlist">📋 Quotes Browser</button>
    <button class="mob-item mob-sub" data-tab="ivlist">🧾 Invoice Browser</button>

    <div class="mob-sect">✏️ Create</div>
    <button class="mob-item mob-sub" data-tab="newquote">📝 New Quote</button>
    <button class="mob-item mob-sub" data-tab="myquotes">📂 My Quotes</button>
    <button class="mob-item mob-sub" data-tab="jobcards">🛠️ Job Cards</button>

    <div class="mob-sect">✅ Tasks</div>
    <button class="mob-item mob-sub" data-tab="todo">☑️ To-Do</button>
    <button class="mob-item mob-sub" data-ext="tasks_board.php">📌 Task Board</button>

    <div class="mob-sect">👥 Clients</div>
    <button class="mob-item mob-sub" data-tab="emails">✉️ Emails</button>
    <button class="mob-item mob-sub" data-ext="audrey.php">📊 Audrey Reports</button>

    <button class="mob-item" data-tab="ask">🤖 Ask your books</button>
    <button class="mob-item" data-tab="portals">🔐 Portals</button>

    <div class="mob-sect">⚙️ Settings</div>
    <button class="mob-item mob-sub" data-tab="settings">🔧 App Settings</button>
    <button class="mob-item mob-sub" data-tab="users">👤 Users</button>
    <button class="mob-item mob-sub" data-tab="clientaccess">🔗 Client Access</button>
    <button class="mob-item mob-sub" data-tab="activity">📋 Activity Log</button>

    <div style="padding:20px 18px 8px">
      <a href="?logout=1" style="display:flex;align-items:center;gap:8px;padding:11px 14px;background:rgba(239,68,68,.12);color:#F87171;font-weight:600;font-size:13px;text-decoration:none;border-radius:10px">🚪 Sign out</a>
    </div>
  </div>
</nav>

<div id="qbModal" class="qbmodal" onclick="if(event.target===this)qbClose()">
  <div class="qbm-card">
    <div class="qbm-head"><b id="qbModalTitle">New quote</b>
      <button class="qbm-x" onclick="qbClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="qbModalBody"></div>
  </div>
</div>

<div id="pwModal" class="qbmodal" onclick="if(event.target===this)pwClose()">
  <div class="qbm-card" style="max-width:420px">
    <div class="qbm-head"><b>Change password</b>
      <button class="qbm-x" onclick="pwClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body">
      <label>Current password</label>
      <input type="password" id="pwCur" autocomplete="current-password" placeholder="Your current password">
      <label>New password</label>
      <input type="password" id="pwNew" autocomplete="new-password" placeholder="At least 6 characters">
      <label>Confirm new password</label>
      <input type="password" id="pwNew2" autocomplete="new-password" placeholder="Re-type the new password">
      <div id="pwMsg" style="margin-bottom:10px"></div>
      <button class="btn" id="pwBtn" onclick="pwSave()">Update password</button>
    </div>
  </div>
</div>

<div id="taskModal" class="qbmodal" onclick="if(event.target===this)tmClose()">
  <div class="qbm-card" style="max-width:540px">
    <div class="qbm-head"><b>New task</b>
      <button class="qbm-x" onclick="tmClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="taskModalBody"></div>
  </div>
</div>

<div id="jcModal" class="qbmodal" onclick="if(event.target===this)jcClose()">
  <div class="qbm-card" style="max-width:900px">
    <div class="qbm-head"><b id="jcModalTitle">Capture costs</b>
      <button class="qbm-x" onclick="jcClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="jcModalBody"></div>
  </div>
</div>

<div id="ppModal" class="qbmodal" onclick="if(event.target===this)ppClose()">
  <div class="qbm-card" style="max-width:560px">
    <div class="qbm-head"><b id="ppModalTitle">Client payments</b>
      <button class="qbm-x" onclick="ppClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="ppModalBody"></div>
  </div>
</div>

<div id="paModal" class="qbmodal" onclick="if(event.target===this)paClose()">
  <div class="qbm-card" style="max-width:480px">
    <div class="qbm-head"><b id="paModalTitle">Assign viewers</b>
      <button class="qbm-x" onclick="paClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="paModalBody"></div>
  </div>
</div>

<div id="qvModal" class="qbmodal" onclick="if(event.target===this)qvClose()">
  <div class="qbm-card" style="max-width:640px">
    <div class="qbm-head"><b id="qvModalTitle">Quote preview</b>
      <button class="qbm-x" onclick="qvClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="qvModalBody"></div>
  </div>
</div>

<div id="docModal" class="qbmodal" onclick="if(event.target===this)docClose()">
  <div class="qbm-card" style="max-width:900px;width:96%;height:92vh;display:flex;flex-direction:column;overflow:hidden">
    <div class="qbm-head"><b id="docModalTitle">Document</b>
      <span style="display:flex;gap:8px;align-items:center">
        <button class="btn" style="width:auto;padding:6px 13px;font-size:12px" onclick="docPrint()">⤓ Print / Save PDF</button>
        <button class="qbm-x" onclick="docClose()" aria-label="Close" title="Close">✕</button>
      </span></div>
    <iframe id="docFrame" style="flex:1;width:100%;border:0;background:#fff" title="Document"></iframe>
  </div>
</div>

<div id="benAiModal" class="qbmodal" onclick="if(event.target===this)benAiClose()">
  <div class="qbm-card" style="max-width:680px">
    <div class="qbm-head"><b>💬 Ask AI — Ben's questions</b>
      <button class="qbm-x" onclick="benAiClose()" aria-label="Close" title="Close">✕</button></div>
    <div class="qbm-body" id="benAiBody"></div>
  </div>
</div>

<script>
const CFG = <?php echo json_encode([
  'fund'=>$cfg['fund'],'rate'=>$cfg['annual_rate'],'cur'=>$cfg['currency'],'vat'=>$cfg['vat_rate'] ?? 0.16,
  'usd'=>$cfg['usd_rate'] ?? 128,'growth'=>$cfg['growth_multiple'] ?? 2,
  'inboxDays'=>$cfg['inbox_days'] ?? 14,'sentDays'=>$cfg['sent_hide_days'] ?? 30,
  'biz'=>$cfg['business_name'] ?? 'Nine One Two Holdings',
  'stmtSubject'=>$cfg['statement_subject'] ?? 'Pending invoices and Statement',
  'stmtFooter'=>$cfg['statement_footer'] ?? '',
  'org'=>$cfg['organization_id'] ?? ''
]); ?>;
const ME = <?php echo json_encode([
  'user'  => $_SESSION['user'] ?? 'admin',
  'email' => $_SESSION['email'] ?? '',
  'admin' => !empty($_SESSION['is_admin']),
  'tabs'  => (($_SESSION['tabs'] ?? '*') === '*') ? '*' : array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['tabs'] ?? '')))))
]); ?>;
const ALLTABS = <?php echo json_encode(users_all_tabs()); ?>;
</script>
<script src="app.js?v=<?php echo @filemtime(__DIR__.'/app.js'); ?>"></script>
<div style="text-align:center;padding:18px 12px 22px;border-top:1px solid #E6EAF0;margin-top:24px;line-height:1.7">
  <div style="font-size:11.5px;color:#64748B">This system is designed for <b>912 Holdings</b>, Zone Fibre Limited, Waitara Holdings Limited, Smart Zone Fibre Limited &amp; Global IT Limited</div>
  <div style="font-size:11px;color:#F56F00;margin-top:5px">&#9888; If you are a staff member of any of the companies listed here and can see information of a company you are not associated with, report immediately to <b>Njuguna Waitara — +254 722 974 970</b> at a reward of <b>10,000 KES</b></div>
</div>
</body></html>
