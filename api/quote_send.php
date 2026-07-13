<?php
require_once __DIR__ . '/../errors.php';
/* api/quote_send.php — email an APPROVED quote straight to the customer via Zoho Books
   (Zoho attaches the PDF and, if the customer portal is on, a view/accept link).
   POST JSON: {id}
   Allowed once the quote is approved/sent/accepted. Owner or the quote's creator only. */
session_start();
header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['auth'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not signed in.']); exit; }
require __DIR__ . '/../db.php';
require __DIR__ . '/../zoho.php';
@set_time_limit(90);

try {
    $pdo = db();
    $me    = $_SESSION['user'] ?? '';
    $admin = !empty($_SESSION['is_admin']);

    $in = json_decode(file_get_contents('php://input'), true); if (!is_array($in)) $in = $_POST;
    $id = (int)($in['id'] ?? 0); if ($id <= 0) throw new Exception('No quote.');

    $st = $pdo->prepare("SELECT * FROM quotes WHERE id=?"); $st->execute([$id]);
    $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) throw new Exception('Quote not found.');
    if (!$admin && $q['created_by'] !== $me) throw new Exception('Not your quote.');
    if (empty($q['zoho_estimate_id'])) throw new Exception('This quote is not in Zoho yet.');
    if (!in_array($q['status'], ['approved', 'sent', 'accepted'], true)) {
        throw new Exception('Only an approved quote can be sent to the customer.');
    }

    $estId = $q['zoho_estimate_id'];

    // find the customer's email (estimate first, then the contact record)
    $email = '';
    [$est, $ec] = zoho_api('GET', 'estimates/' . rawurlencode($estId));
    $contactId = '';
    if ($ec < 400 && !empty($est['estimate'])) {
        $email     = trim((string)($est['estimate']['email'] ?? ''));
        $contactId = (string)($est['estimate']['customer_id'] ?? '');
    }
    if ($email === '' && $contactId !== '') {
        [$ct, $cc] = zoho_api('GET', 'contacts/' . rawurlencode($contactId));
        if ($cc < 400 && !empty($ct['contact'])) {
            $email = trim((string)($ct['contact']['email'] ?? ''));
            if ($email === '') {
                foreach (($ct['contact']['contact_persons'] ?? []) as $p) {
                    if (!empty($p['email'])) { $email = trim((string)$p['email']); break; }
                }
            }
        }
    }
    if ($email === '') throw new Exception('This customer has no email in Zoho — add one on their Zoho contact, then resend.');

    $body = ['to_mail_ids' => [$email]];
    [$d, $c] = zoho_api('POST', 'estimates/' . rawurlencode($estId) . '/email', $body);
    if ($c >= 400) throw new Exception($d['message'] ?? 'Zoho could not send the estimate.');

    // Zoho moves it to "sent" after emailing
    $pdo->prepare("UPDATE quotes SET status='sent', last_synced_at=NOW() WHERE id=?")->execute([$id]);

    require_once __DIR__ . '/../activity_store.php';
    activity_log($pdo, $me, 'sent quote to customer', ($q['zoho_estimate_number'] ?: ('#'.$id)) . ' → ' . $email);
    echo json_encode(['ok'=>true, 'email'=>$email, 'number'=>$q['zoho_estimate_number']]);
} catch (Exception $e) {
    echo api_fail($e);
}
