<?php
require_once __DIR__ . '/../errors.php';
/** Lists invoice custom fields so you can confirm the exact api_name
 *  for "Funded by Working Capital" to put in config.php. Admin-only diagnostic. */
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['auth']) || empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admins only.']); exit; }
require __DIR__ . '/../zoho.php';

try {
    // Inspect a recent invoice's custom fields (most reliable way to read api_name + label).
    [$list, $c1] = zoho_api('GET', 'invoices', null, ['per_page' => 1, 'sort_column' => 'date']);
    $fields = [];
    if (!empty($list['invoices'][0]['invoice_id'])) {
        [$one, $c2] = zoho_api('GET', 'invoices/' . $list['invoices'][0]['invoice_id']);
        foreach (($one['invoice']['custom_fields'] ?? []) as $f) {
            $fields[] = ['label' => $f['label'] ?? '', 'api_name' => $f['api_name'] ?? '', 'value' => $f['value'] ?? ''];
        }
    }
    echo json_encode(['ok' => true, 'custom_fields' => $fields,
        'hint' => 'Copy the api_name whose label is "Funded by Working Capital" into config.php → wc_custom_field'], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo api_fail($e);
}
