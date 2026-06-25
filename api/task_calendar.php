<?php
/* api/task_calendar.php — email a calendar invite for a task to its (ticked) assignees.
   Uses the app's Zoho Mail token to SEND. Each email has a one-click "Add to calendar"
   (Google) link + a signed .ics link that opens in Zoho/Outlook/Apple calendars.
   Body JSON: { id, date:'YYYY-MM-DD', time:'HH:MM', durationMins, recipients:[{name,email}] } */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../mail.php';
require_once __DIR__ . '/../calendar_oauth.php';
$cfg = require __DIR__ . '/../config.php';
@set_time_limit(90);

try {
    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id   = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No task.');
    $date = preg_replace('/[^0-9\-]/', '', (string)($in['date'] ?? ''));
    $time = preg_replace('/[^0-9:]/', '', (string)($in['time'] ?? '09:00'));
    $dur  = (int)($in['durationMins'] ?? 60); if ($dur < 5) $dur = 60;
    if ($date === '') throw new Exception('Pick a date.');
    if ($time === '') $time = '09:00';

    // recipients: from body, else all task assignees
    $pdo = db();
    $tst = $pdo->prepare("SELECT title, subject, notes FROM tasks WHERE id=?"); $tst->execute([$id]);
    $task = $tst->fetch(PDO::FETCH_ASSOC); if (!$task) throw new Exception('Task not found.');

    $recips = [];
    foreach ((array)($in['recipients'] ?? []) as $r) {
        $em = trim((string)($r['email'] ?? '')); $nm = trim((string)($r['name'] ?? ''));
        if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $recips[$em] = $nm ?: $em;
    }
    if (!$recips) {
        $ast = $pdo->prepare("SELECT name, email FROM task_assignees WHERE task_id=?"); $ast->execute([$id]);
        foreach ($ast->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $em = trim((string)$a['email']);
            if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) $recips[$em] = $a['name'] ?: $em;
        }
    }
    if (!$recips) throw new Exception('No assignee with a valid email to invite. Tick someone (and make sure they have an email in Users).');

    // compact local datetime for calendars
    $startCompact = preg_replace('/[^0-9]/', '', $date) . 'T' . str_pad(preg_replace('/[^0-9]/', '', $time), 4, '0', STR_PAD_RIGHT) . '00';
    // normalise to Ymd\THis
    $dtStart = DateTime::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if (!$dtStart) throw new Exception('Invalid date/time.');
    $startC = $dtStart->format('Ymd\THis');
    $dtEnd = clone $dtStart; $dtEnd->modify('+' . $dur . ' minutes'); $endC = $dtEnd->format('Ymd\THis');

    // signed .ics link (works without app login)
    $key = (string)($cfg['app_password'] ?? 'x912');
    $kSig = hash_hmac('sha256', $id . '|' . $startC . '|' . $dur, $key);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'nineonetwo.online';
    $appRoot = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/WORKINGCAPITAL/api/x')), '/\\');
    $icsUrl = $appRoot . '/api/task_ics.php?id=' . $id . '&start=' . $startC . '&dur=' . $dur . '&k=' . $kSig;

    $title = $task['title'] ?: 'Task';
    $descParts = [];
    if (!empty($task['subject'])) $descParts[] = $task['subject'];
    if (!empty($task['notes']))   $descParts[] = $task['notes'];
    $desc = implode("\n", $descParts);

    $gcal = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
          . '&text=' . rawurlencode($title)
          . '&dates=' . $startC . '/' . $endC
          . '&details=' . rawurlencode($desc);

    $prettyWhen = $dtStart->format('D, d M Y H:i') . ' – ' . $dtEnd->format('H:i');
    $esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $acct = mail_primary_account();

    $sent = 0; $direct = 0; $fails = []; $needMail = [];
    // 1) employees who connected their calendar → write the event straight in
    $connected = cal_connected_set();
    foreach ($recips as $email => $name) {
        if (isset($connected[strtolower($email)])) {
            try {
                [$resp, $code] = cal_create_event($email, $title, $desc, $startC, $dur);
                if ($code < 400) { $direct++; continue; }
                $needMail[$email] = $name; // direct write failed → fall back to invite
            } catch (Exception $e) { $needMail[$email] = $name; }
        } else {
            $needMail[$email] = $name; // not connected → invite by email
        }
    }

    // 2) everyone else → emailed calendar invite (needs the Mail account)
    if ($needMail && empty($acct['accountId'])) throw new Exception('Could not resolve your Mail account id for the invite fallback.');
    foreach ($needMail as $email => $name) {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#15202B;max-width:560px">'
              . '<p>Hi ' . $esc($name) . ',</p>'
              . '<p>You have a scheduled task:</p>'
              . '<div style="border:1px solid #E6EAF0;border-radius:10px;padding:14px 16px;margin:10px 0">'
              . '<div style="font-size:16px;font-weight:700;margin-bottom:4px">' . $esc($title) . '</div>'
              . ($task['subject'] ? '<div style="color:#F56F00;font-weight:600;margin-bottom:6px">' . $esc($task['subject']) . '</div>' : '')
              . '<div style="margin-bottom:8px"><b>When:</b> ' . $esc($prettyWhen) . '</div>'
              . ($task['notes'] ? '<div style="color:#475569;white-space:pre-wrap">' . $esc($task['notes']) . '</div>' : '')
              . '</div>'
              . '<p style="margin:14px 0 6px"><b>Add it to your calendar:</b></p>'
              . '<p style="margin:0 0 8px"><a href="' . $esc($icsUrl) . '" style="display:inline-block;background:#2350C5;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600">Add to my calendar (.ics)</a>'
              . ' &nbsp; <a href="' . $esc($gcal) . '" style="display:inline-block;background:#F56F00;color:#fff;text-decoration:none;padding:9px 16px;border-radius:8px;font-weight:600">Add to Google Calendar</a></p>'
              . '<p style="color:#94A3B8;font-size:12px;margin-top:16px">Sent from Waitara Holdings Group of Companies Console.</p>'
              . '</div>';

        $payload = [
            'mode'        => 'sending',
            'fromAddress' => $acct['from'],
            'toAddress'   => $email,
            'subject'     => 'Task: ' . $title . ' — ' . $dtStart->format('d M Y H:i'),
            'content'     => $html,
            'mailFormat'  => 'html',
        ];
        [$resp, $code] = mail_api('POST', '/api/accounts/' . rawurlencode($acct['accountId']) . '/messages', $payload);
        if ($code >= 400) { $fails[] = $email . ' (' . ($resp['data']['moreInfo'] ?? ($resp['status']['description'] ?? ('HTTP ' . $code))) . ')'; }
        else { $sent++; }
    }

    echo json_encode(['ok'=>true, 'direct'=>$direct, 'sent'=>$sent, 'fails'=>$fails, 'when'=>$prettyWhen]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
