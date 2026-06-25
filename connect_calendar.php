<?php
/* connect_calendar.php — one-time "Connect my Zoho Calendar" for the logged-in employee.
   They must be logged into the app (so we know whose calendar) and have an email set.
   Start:  connect_calendar.php?start=1   → sends them to Zoho consent
   Return: Zoho redirects back here with ?code → we store THEIR refresh token. */
session_start();
require __DIR__ . '/calendar_oauth.php';

function cc_page($title,$bodyHtml){
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<title>'.$title.'</title><style>body{font-family:Poppins,Arial,sans-serif;background:#F7F8FB;color:#15202B;margin:0;padding:40px 16px}'
     . '.box{max-width:460px;margin:0 auto;background:#fff;border:1px solid #E6EAF0;border-radius:16px;padding:28px;box-shadow:0 12px 44px rgba(20,32,43,.10)}'
     . 'h1{font-size:18px;margin:0 0 10px}p{font-size:14px;line-height:1.5;color:#334155}'
     . 'a.btn{display:inline-block;background:#F56F00;color:#fff;text-decoration:none;padding:11px 18px;border-radius:10px;font-weight:600;margin-top:8px}'
     . 'a.sec{background:#fff;color:#2350C5;border:1px solid #2350C5}</style></head><body><div class="box">'.$bodyHtml.'</div></body></html>';
}

if (empty($_SESSION['auth'])) {
    cc_page('Sign in first', '<h1>Please sign in first</h1><p>Open the app, log in with your username, then come back and tap “Connect my Zoho Calendar”.</p><p><a class="btn" href="index.php">Go to the app</a></p>');
    exit;
}
$email = $_SESSION['email'] ?? '';
if ($email === '') {
    cc_page('No email on your login', '<h1>Your login has no email yet</h1><p>Ask the admin to set your email under <b>Settings → Users</b> (it must match your Zoho email). Then come back and connect.</p><p><a class="btn" href="index.php">Back to the app</a></p>');
    exit;
}

// returning from Zoho consent
if (isset($_GET['code'])) {
    $state = $_GET['state'] ?? '';
    if (!hash_equals(cal_state($email), $state)) {
        cc_page('Could not verify', '<h1>Security check failed</h1><p>The link didn’t verify. Please try connecting again.</p><p><a class="btn" href="connect_calendar.php?start=1">Try again</a></p>');
        exit;
    }
    [$j, $hc] = cal_exchange_code($_GET['code']);
    if ($hc < 400 && !empty($j['refresh_token'])) {
        cal_save_token($email, $j['refresh_token']);
        cc_page('Connected', '<h1>✅ Calendar connected</h1><p>Your Zoho Calendar is now linked. Tasks assigned to you in the app can be added straight to your calendar. You can close this tab.</p><p><a class="btn" href="index.php">Back to the app</a></p>');
    } else {
        $err = htmlspecialchars($j['error'] ?? ('HTTP ' . $hc), ENT_QUOTES);
        cc_page('Could not connect', '<h1>❌ Could not connect</h1><p>Zoho said: <b>' . $err . '</b></p><p>If this says <code>invalid_client</code> or <code>redirect_uri</code>, the admin needs to add this page as an authorised redirect URL and add the Calendar scope in the Zoho API console.</p><p><a class="btn" href="connect_calendar.php?start=1">Try again</a></p>');
    }
    exit;
}

// start the connect
if (isset($_GET['start'])) {
    header('Location: ' . cal_authorize_url($email));
    exit;
}

// landing
$connected = cal_is_connected($email);
$body = '<h1>Connect your Zoho Calendar</h1>'
      . '<p>Signed in as <b>' . htmlspecialchars($email, ENT_QUOTES) . '</b>.</p>'
      . ($connected
          ? '<p>✅ Already connected. Re-connect only if you changed Zoho accounts.</p><p><a class="btn" href="connect_calendar.php?start=1">Re-connect</a> &nbsp; <a class="btn sec" href="connect_calendar.php?disconnect=1">Disconnect</a></p>'
          : '<p>Tap below and approve once. After that, tasks assigned to you can be written directly to your calendar.</p><p><a class="btn" href="connect_calendar.php?start=1">Connect my Zoho Calendar</a></p>');
if (isset($_GET['disconnect'])) { cal_forget($email); $body = '<h1>Disconnected</h1><p>Your calendar link was removed.</p><p><a class="btn" href="connect_calendar.php">Back</a></p>'; }
cc_page('Connect Zoho Calendar', $body);
