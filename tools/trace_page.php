<?php
// PATH: /public_html/tools/_trace_page.php
header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);
function step($n,$msg){ echo "[$n] $msg\n"; @ob_flush(); @flush(); }

step(0,'start');
require_once __DIR__ . '/../includes/db.php'; step(1,'db.php loaded');
try { $pdo = db(); $pdo->query('SELECT 1'); step(2,'db() OK'); } catch (Throwable $e) { echo "[2X] DB FAIL: ".$e->getMessage()."\n"; exit; }
require_once __DIR__ . '/../includes/rbac.php'; step(3,'rbac.php loaded');

$target = $_GET['file'] ?? '';
if (!$target) { echo "Use ?file=identity/users_list.php\n"; exit; }
$full = realpath(__DIR__.'/../'.$target);
if (!$full || !is_file($full)) { echo "Target not found: $target\n"; exit; }

step(6,'include '.$target);
include $full;
step(7,'included OK');
