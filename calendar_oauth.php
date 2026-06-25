<?php
/* calendar_oauth.php — per-employee Zoho Calendar connect + DIRECT event write.
   Self-contained: reuses your Zoho client_id/client_secret/accounts_url from config.php,
   stores each employee's own refresh token (after they connect once), and writes events
   straight into THAT employee's calendar. Does not depend on zoho.php. */
require_once __DIR__ . '/db.php';

function cal_cfg(){ static $c=null; if($c===null){ $c=require __DIR__.'/config.php'; } return $c; }
function cal_accounts_url(){ $c=cal_cfg(); return rtrim($c['accounts_url'] ?? 'https://accounts.zoho.com', '/'); }
function cal_api_host(){
    $host = parse_url(cal_accounts_url(), PHP_URL_HOST) ?: 'accounts.zoho.com';
    $cal  = preg_replace('/^accounts\./', 'calendar.', $host);
    return 'https://' . $cal . '/api/v1';
}
function cal_redirect_uri(){
    $c = cal_cfg();
    if (!empty($c['calendar_redirect_uri'])) return $c['calendar_redirect_uri'];
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'nineonetwo.online';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/connect_calendar.php'), '/\\');
    if (basename($dir) === 'api') $dir = dirname($dir);           // safety if called from /api
    return $scheme . '://' . $host . $dir . '/connect_calendar.php';
}
function cal_state($email){ return hash_hmac('sha256', strtolower($email), (string)(cal_cfg()['app_password'] ?? 'x912')); }

function cal_table(){
    db()->exec("CREATE TABLE IF NOT EXISTS cal_tokens (
        user_email VARCHAR(190) PRIMARY KEY,
        refresh_token TEXT,
        connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function cal_save_token($email,$refresh){ cal_table(); $st=db()->prepare("REPLACE INTO cal_tokens (user_email,refresh_token,connected_at) VALUES (?,?,NOW())"); $st->execute([strtolower($email),$refresh]); }
function cal_forget($email){ cal_table(); $st=db()->prepare("DELETE FROM cal_tokens WHERE user_email=?"); $st->execute([strtolower($email)]); }
function cal_get_refresh($email){ cal_table(); $st=db()->prepare("SELECT refresh_token FROM cal_tokens WHERE user_email=?"); $st->execute([strtolower($email)]); $r=$st->fetch(PDO::FETCH_ASSOC); return $r?$r['refresh_token']:null; }
function cal_is_connected($email){ return $email!=='' && cal_get_refresh($email)!==null; }
function cal_connected_set(){
    cal_table(); $out=[];
    foreach(db()->query("SELECT user_email FROM cal_tokens")->fetchAll(PDO::FETCH_COLUMN) as $e){ $out[strtolower($e)]=true; }
    return $out;
}

function cal_http($method,$url,$headers=[],$body=null,$form=false){
    $ch=curl_init($url);
    $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers];
    if($body!==null){ $opt[CURLOPT_POSTFIELDS]=$form?http_build_query($body):(is_string($body)?$body:json_encode($body)); }
    curl_setopt_array($ch,$opt);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=json_decode((string)$resp,true);
    return [$j!==null?$j:$resp,$code];
}
function cal_authorize_url($email){
    $c=cal_cfg();
    $params=http_build_query([
        'response_type'=>'code',
        'client_id'=>$c['client_id'] ?? '',
        'scope'=>'ZohoCalendar.calendar.READ,ZohoCalendar.event.ALL',
        'redirect_uri'=>cal_redirect_uri(),
        'access_type'=>'offline',
        'prompt'=>'consent',
        'state'=>cal_state($email),
    ]);
    return cal_accounts_url().'/oauth/v2/auth?'.$params;
}
function cal_exchange_code($code){
    $c=cal_cfg();
    return cal_http('POST', cal_accounts_url().'/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'], [
        'grant_type'=>'authorization_code','client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'',
        'redirect_uri'=>cal_redirect_uri(),'code'=>$code,
    ], true);
}
function cal_access_token($email){
    static $cache=[]; $k=strtolower($email); if(isset($cache[$k])) return $cache[$k];
    $rt=cal_get_refresh($email); if(!$rt) throw new Exception('This person has not connected their Zoho Calendar yet.');
    $c=cal_cfg();
    [$j,$hc]=cal_http('POST', cal_accounts_url().'/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'], [
        'grant_type'=>'refresh_token','client_id'=>$c['client_id']??'','client_secret'=>$c['client_secret']??'','refresh_token'=>$rt,
    ], true);
    if($hc>=400 || empty($j['access_token'])) throw new Exception('Calendar token refresh failed ('.($j['error']??('HTTP '.$hc)).').');
    $cache[$k]=$j['access_token']; return $cache[$k];
}
function cal_api($email,$method,$path,$body=null){
    $tok=cal_access_token($email);
    $h=['Authorization: Zoho-oauthtoken '.$tok];
    if($body!==null) $h[]='Content-Type: application/json';
    return cal_http($method, cal_api_host().$path, $h, $body);
}
function cal_primary_calendar($email){
    static $cache=[]; $k=strtolower($email); if(isset($cache[$k])) return $cache[$k];
    [$j,$hc]=cal_api($email,'GET','/calendars');
    if($hc>=400) throw new Exception('Could not read calendars (HTTP '.$hc.').');
    $cals=$j['calendars'] ?? [];
    $uid=null;
    foreach($cals as $cal){ if(!empty($cal['isdefault']) || !empty($cal['is_default'])){ $uid=$cal['uid']??$cal['calendar_uid']??null; break; } }
    if(!$uid && $cals){ $uid=$cals[0]['uid']??$cals[0]['calendar_uid']??null; }
    if(!$uid) throw new Exception('No calendar found for this account.');
    $cache[$k]=$uid; return $uid;
}
/* create an event directly in the employee's own default calendar.
   $startLocal = 'Ymd\THis' (local clock time). Returns [resp,$httpcode]. */
function cal_create_event($email,$title,$desc,$startLocal,$durMin){
    $caluid=cal_primary_calendar($email);
    $dtStart=DateTime::createFromFormat('Ymd\THis',$startLocal) ?: new DateTime();
    $dtEnd=clone $dtStart; $dtEnd->modify('+'.max(5,(int)$durMin).' minutes');
    $eventdata=[
        'title'=>$title,
        'description'=>$desc,
        'dateandtime'=>[
            'timezone'=>'Africa/Nairobi',
            'start'=>$dtStart->format('Ymd\THisO'),
            'end'  =>$dtEnd->format('Ymd\THisO'),
        ],
        'reminders'=>[['action'=>'popup','minutes'=>-30]],
    ];
    $path='/calendars/'.rawurlencode($caluid).'/events?eventdata='.rawurlencode(json_encode($eventdata));
    return cal_api($email,'POST',$path,null);
}
