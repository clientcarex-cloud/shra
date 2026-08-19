<?php
/**
 * QR image endpoint.  qr.php?d=<payload>&s=<px>&f=svg|png&e=L|M|Q|H
 * Also accepts shortcuts: ?inv=<token> (pay link) or ?self=1 (kiosk link).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/qrcode.php';

$data = (string) get('d', '');
if (get('inv'))  $data = base_url('pay.php?t=' . rawurlencode((string) get('inv')));
if (get('self')) $data = base_url('self.php');
if ($data === '') { http_response_code(400); exit('missing payload'); }

$size = max(80, min(1200, gint('s', 300)));
$ecl  = strtoupper(gstr('e', 'M'));
if (!in_array($ecl, ['L', 'M', 'Q', 'H'], true)) $ecl = 'M';

try {
    if (gstr('f', 'svg') === 'png') {
        $png = QRCode::png($data, max(2, (int) round($size / 40)), $ecl);
        if ($png !== null) {
            header('Content-Type: image/png');
            header('Cache-Control: private, max-age=600');
            echo $png;
            exit;
        }
    }
    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=600');
    echo QRCode::svg($data, $size, $ecl);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'QR error: ' . $e->getMessage();
}
