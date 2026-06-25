<?php
/** Returns recent expenses from Zoho Books, so the cost (COGS) can be picked instead of typed. */
header('Content-Type: application/json');
require __DIR__ . '/../zoho.php';

try {
    $all = [];
    $page = 1;
    do {
        [$data, $code] = zoho_api('GET', 'expenses', null, [
            'per_page'    => 200,
            'page'        => $page,
            'sort_column' => 'date',
            'sort_order'  => 'D',
        ]);
        if ($code >= 400) throw new Exception($data['message'] ?? 'Zoho error');
        foreach (($data['expenses'] ?? []) as $e) {
            $all[] = [
                'expenseId' => $e['expense_id'],
                'account'   => $e['account_name'] ?? '',
                'vendor'    => $e['vendor_name'] ?? '',
                'ref'       => $e['reference_number'] ?? '',
                'date'      => $e['date'] ?? '',
                // total_without_tax = the cost net of VAT (what should feed margin)
                'amount'    => (float)($e['total_without_tax'] ?? $e['total'] ?? 0),
                'desc'      => $e['description'] ?? '',
            ];
        }
        $more = $data['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($more && $page < 6);

    echo json_encode(['ok' => true, 'expenses' => $all, 'count' => count($all)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
