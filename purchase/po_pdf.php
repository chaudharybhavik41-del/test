<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

require_login();
$pdo = db();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ http_response_code(400); echo "Missing id"; exit; }

// Reuse the same HTML as print view
ob_start();
$_GET['id'] = $id;
include __DIR__.'/po_print.php';
$html = ob_get_clean();

if (class_exists('\\Dompdf\\Dompdf')) {
  // If Dompdf is installed, stream a PDF
  $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
  $dompdf->loadHtml($html);
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();
  $dompdf->stream('PO-'.$id.'.pdf', ['Attachment' => true]);
  exit;
}

// Fallback: show the HTML with guidance
header('Content-Type: text/html; charset=utf-8'); ?>
<!doctype html>
<html><head><meta charset="utf-8"><title>PDF export</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="p-4">
  <div class="alert alert-info">
    PDF export requires Dompdf (PHP library). It seems not installed on this server.<br>
    Ask admin to run: <code>composer require dompdf/dompdf</code><br>
    Meanwhile, you can print to PDF from the browser here:
    <a class="btn btn-sm btn-outline-primary" target="_blank" href="/purchase/po_print.php?id=<?= (int)$id ?>">Open Print View</a>
  </div>
  <div class="border rounded p-3 bg-white"><?= $html ?></div>
</body></html>
