<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_permission('project.project.manage');

$pdo = db();
$partyId = (int)($_GET['party_id'] ?? 0);
if ($partyId <= 0) { header('Content-Type: application/json'); echo '[]'; exit; }

$st = $pdo->prepare("SELECT id, name, phone, email FROM party_contacts WHERE party_id = ? ORDER BY name");
$st->execute([$partyId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: application/json');
echo json_encode($rows);