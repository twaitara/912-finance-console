<?php
/** Fund loans + a registry of named money pools ("funds").
 *  Lend from a chosen fund to the business or a person; log repayments.
 *  Each fund tracks its own balance; outstanding loans reduce that fund's available.
 *  The PRIMARY fund mirrors the working-capital fund value from Settings.
 *  Stored in MySQL, not Zoho. Principal only (no interest). */
header('Content-Type: application/json');
require __DIR__ . '/../db.php';
require_once __DIR__ . '/../settings_store.php';
$cfg = require __DIR__ . '/../config.php';
$FUND_SETTING = (float) app_setting($cfg, 'fund');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$pdo = db();

function loans_tables(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS funds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL DEFAULT '',
        balance DECIMAL(14,2) NOT NULL DEFAULT 0,
        is_primary TINYINT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fund_loans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fund_id INT NOT NULL DEFAULT 0,
        borrower_type VARCHAR(20) NOT NULL DEFAULT 'person',
        borrower_name VARCHAR(190) NOT NULL DEFAULT '',
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        repaid DECIMAL(14,2) NOT NULL DEFAULT 0,
        loan_date DATE NULL,
        expected_date DATE NULL,
        note VARCHAR(500) DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'Open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // add fund_id to pre-existing loan tables
    try { $pdo->exec("ALTER TABLE fund_loans ADD COLUMN fund_id INT NOT NULL DEFAULT 0 AFTER id"); } catch (Exception $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS fund_loan_repayments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        loan_id INT NOT NULL,
        amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        repaid_date DATE NULL,
        note VARCHAR(300) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function loans_primary_id(PDO $pdo) {
    $row = $pdo->query("SELECT id FROM funds WHERE is_primary=1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['id'];
    // seed primary fund the first time
    $pdo->prepare("INSERT INTO funds (name,balance,is_primary) VALUES (?,?,1)")->execute(['Nine One Two Funds', 0]);
    $pid = (int)$pdo->lastInsertId();
    // attach any orphan loans to the primary fund
    $pdo->prepare("UPDATE fund_loans SET fund_id=? WHERE fund_id=0 OR fund_id IS NULL")->execute([$pid]);
    return $pid;
}

function loans_payload(PDO $pdo, $fundSetting) {
    $pid = loans_primary_id($pdo);

    $funds = $pdo->query("SELECT * FROM funds ORDER BY is_primary DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // outstanding per fund
    $out = $pdo->query("SELECT fund_id, SUM(amount-repaid) AS outstanding, COUNT(*) AS n
                        FROM fund_loans WHERE status<>'Repaid' GROUP BY fund_id")->fetchAll(PDO::FETCH_ASSOC);
    $byFund = [];
    foreach ($out as $o) { $byFund[(int)$o['fund_id']] = ['outstanding'=>(float)$o['outstanding'], 'n'=>(int)$o['n']]; }

    $fundList = [];
    foreach ($funds as $f) {
        $id = (int)$f['id'];
        $isP = (int)$f['is_primary'] === 1;
        $bal = $isP ? (float)$fundSetting : (float)$f['balance']; // primary mirrors Settings fund
        $o = $byFund[$id]['outstanding'] ?? 0;
        $fundList[] = [
            'id'         => $id,
            'name'       => $f['name'],
            'is_primary' => $isP ? 1 : 0,
            'balance'    => $bal,
            'outstanding'=> $o,
            'open_loans' => $byFund[$id]['n'] ?? 0,
            // NOTE: for the primary fund, bridge exposure is subtracted in the browser
            'available'  => $bal - $o,
        ];
    }

    $loans = $pdo->query("SELECT * FROM fund_loans ORDER BY (status='Repaid') ASC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $reps  = $pdo->query("SELECT * FROM fund_loan_repayments ORDER BY repaid_date ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $repByLoan = [];
    foreach ($reps as $r) { $repByLoan[(int)$r['loan_id']][] = $r; }
    $nameById = []; foreach ($fundList as $f) { $nameById[$f['id']] = $f['name']; }

    $lent = 0; $repaid = 0; $outstanding = 0;
    foreach ($loans as &$l) {
        $l['amount'] = (float)$l['amount'];
        $l['repaid'] = (float)$l['repaid'];
        $l['outstanding'] = max(0, $l['amount'] - $l['repaid']);
        $l['fund_id'] = (int)$l['fund_id'];
        $l['fund_name'] = $nameById[$l['fund_id']] ?? '—';
        $l['repayments'] = $repByLoan[(int)$l['id']] ?? [];
        $lent += $l['amount']; $repaid += $l['repaid']; $outstanding += $l['outstanding'];
    }
    unset($l);

    return ['ok'=>true, 'funds'=>$fundList, 'primary_id'=>$pid, 'loans'=>$loans,
            'totals'=>['lent'=>$lent,'repaid'=>$repaid,'outstanding'=>$outstanding]];
}

try {
    loans_tables($pdo);

    if ($method === 'GET') { echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit; }

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = $_POST;

    // ---- Funds registry ----
    if ($action === 'fund_add') {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new Exception('Fund name is required.');
        $bal = (float)($in['balance'] ?? 0);
        $pdo->prepare("INSERT INTO funds (name,balance,is_primary) VALUES (?,?,0)")->execute([$name,$bal]);
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }
    if ($action === 'fund_update') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No fund.');
        $f = $pdo->prepare("SELECT * FROM funds WHERE id=?"); $f->execute([$id]); $f = $f->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new Exception('Fund not found.');
        $name = array_key_exists('name',$in) ? trim((string)$in['name']) : $f['name'];
        if ($name === '') $name = $f['name'];
        // primary balance is governed by Settings; only allow balance edit for non-primary
        if ((int)$f['is_primary'] === 1) {
            $pdo->prepare("UPDATE funds SET name=? WHERE id=?")->execute([$name,$id]);
        } else {
            $bal = array_key_exists('balance',$in) ? (float)$in['balance'] : (float)$f['balance'];
            $pdo->prepare("UPDATE funds SET name=?, balance=? WHERE id=?")->execute([$name,$bal,$id]);
        }
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }
    if ($action === 'fund_delete') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No fund.');
        $f = $pdo->prepare("SELECT * FROM funds WHERE id=?"); $f->execute([$id]); $f = $f->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new Exception('Fund not found.');
        if ((int)$f['is_primary'] === 1) throw new Exception('The primary fund cannot be deleted.');
        $n = $pdo->prepare("SELECT COUNT(*) FROM fund_loans WHERE fund_id=?"); $n->execute([$id]);
        if ((int)$n->fetchColumn() > 0) throw new Exception('This fund has loans — delete or move them first.');
        $pdo->prepare("DELETE FROM funds WHERE id=?")->execute([$id]);
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }

    // ---- Loans ----
    if ($action === 'create') {
        $pid = loans_primary_id($pdo);
        $fundId = (int)($in['fund_id'] ?? 0); if (!$fundId) $fundId = $pid;
        $exists = $pdo->prepare("SELECT COUNT(*) FROM funds WHERE id=?"); $exists->execute([$fundId]);
        if ((int)$exists->fetchColumn() === 0) $fundId = $pid;
        $type = ($in['borrower_type'] ?? 'person') === 'business' ? 'business' : 'person';
        $name = trim((string)($in['borrower_name'] ?? ''));
        if ($type === 'business' && $name === '') $name = 'The business';
        if ($name === '') throw new Exception('Borrower name is required.');
        $amount = (float)($in['amount'] ?? 0);
        if ($amount <= 0) throw new Exception('Amount must be greater than zero.');
        $loanDate = ($in['loan_date'] ?? '') ?: date('Y-m-d');
        $expected = ($in['expected_date'] ?? '') ?: null;
        $note = substr(trim((string)($in['note'] ?? '')), 0, 500);
        $st = $pdo->prepare("INSERT INTO fund_loans (fund_id,borrower_type,borrower_name,amount,repaid,loan_date,expected_date,note,status) VALUES (?,?,?,?,0,?,?,?, 'Open')");
        $st->execute([$fundId,$type,$name,$amount,$loanDate,$expected,$note]);
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }
    if ($action === 'repay') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No loan.');
        $amt = (float)($in['amount'] ?? 0); if ($amt <= 0) throw new Exception('Repayment must be greater than zero.');
        $date = ($in['repaid_date'] ?? '') ?: date('Y-m-d');
        $note = substr(trim((string)($in['note'] ?? '')), 0, 300);
        $loan = $pdo->prepare("SELECT * FROM fund_loans WHERE id=?"); $loan->execute([$id]); $loan = $loan->fetch(PDO::FETCH_ASSOC);
        if (!$loan) throw new Exception('Loan not found.');
        $outstanding = (float)$loan['amount'] - (float)$loan['repaid'];
        if ($amt > $outstanding + 0.01) $amt = $outstanding;
        $pdo->prepare("INSERT INTO fund_loan_repayments (loan_id,amount,repaid_date,note) VALUES (?,?,?,?)")->execute([$id,$amt,$date,$note]);
        $newRepaid = (float)$loan['repaid'] + $amt;
        $status = ($newRepaid >= (float)$loan['amount'] - 0.01) ? 'Repaid' : 'Open';
        $pdo->prepare("UPDATE fund_loans SET repaid=?, status=? WHERE id=?")->execute([$newRepaid,$status,$id]);
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }
    if ($action === 'delete') {
        $id = (int)($in['id'] ?? 0); if (!$id) throw new Exception('No loan.');
        $pdo->prepare("DELETE FROM fund_loan_repayments WHERE loan_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM fund_loans WHERE id=?")->execute([$id]);
        echo json_encode(loans_payload($pdo, $FUND_SETTING)); exit;
    }

    throw new Exception('Unknown action.');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
