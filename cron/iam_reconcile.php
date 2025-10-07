<?php
/** PATH: /cron/iam_reconcile.php */
declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';
require_once __DIR__ . '/../public_html/includes/lib_iam_provisioning.php';

$pdo = db();
$ids = $pdo->query("SELECT id FROM employees WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $eid) {
  try { iam_commit_provision($pdo, (int)$eid, 0); }
  catch (Throwable $e) { error_log('[iam_reconcile] employee ' . $eid . ': ' . $e->getMessage()); }
}
echo "Reconcile complete at " . date('c') . PHP_EOL;
