<?php
/** Type-ahead endpoint for the customer pickers. */
require_once __DIR__ . '/inc/bootstrap.php';
require_login();
header('Content-Type: application/json');

$q = gstr('q');
if (strlen($q) < 2) { echo '[]'; exit; }
$like = '%' . $q . '%';
$rows = all('SELECT id, code, first_name, last_name, phone FROM customers
             WHERE status="active" AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR code LIKE ?)
             ORDER BY first_name LIMIT 12', [$like, $like, $like, $like]);

$out = [];
foreach ($rows as $r) {
    $name = trim($r['first_name'] . ' ' . $r['last_name']);
    $out[] = [
        'id'    => (int)$r['id'],
        'name'  => $name,
        'code'  => $r['code'],
        'phone' => $r['phone'],
        'label' => $name . ' (' . $r['phone'] . ')',
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
