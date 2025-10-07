<?php
declare(strict_types=1);
/** QUICK SCHEMA EXPORTER: exports CREATE statements only (no data) */
require_once __DIR__ . '/../includes/db.php'; // adjust if needed

$ROOT = dirname(__DIR__, 1); // /public_html/dev
$PUB  = dirname($ROOT);      // /public_html
$OUT  = $PUB . '/dev/share';
@mkdir($OUT, 0775, true);

$pdo = db();
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
$views  = $pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_NUM);
$fname  = $OUT . '/schema_' . date('Ymd_His') . '.sql';

$fh = fopen($fname, 'w');
fwrite($fh, "-- Schema export ".date('c')."\nSET FOREIGN_KEY_CHECKS=0;\n\n");

foreach ($tables as $t) {
  $name = $t[0];
  $row = $pdo->query("SHOW CREATE TABLE `{$name}`")->fetch(PDO::FETCH_ASSOC);
  fwrite($fh, "DROP TABLE IF EXISTS `{$name}`;\n".$row['Create Table'].";\n\n");
}
foreach ($views as $v) {
  $name = $v[0];
  $row = $pdo->query("SHOW CREATE VIEW `{$name}`")->fetch(PDO::FETCH_ASSOC);
  fwrite($fh, "DROP VIEW IF EXISTS `{$name}`;\n".$row['Create View'].";\n\n");
}

fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fh);

header('Content-Type: text/plain');
echo "Wrote: /dev/share/".basename($fname)."\n";