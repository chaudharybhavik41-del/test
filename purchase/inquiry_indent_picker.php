<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  require_login();
  $pdo = db();
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

  $q = trim($_GET['q'] ?? '');

  // Only raised/approved, and NOT already referenced in inquiry_items
  $where = "WHERE i.status IN ('raised','approved')
            AND NOT EXISTS (
              SELECT 1 FROM inquiry_items qii
              WHERE qii.indent_id = i.id
            )";
  $args = [];

  if ($q !== '') {
    $where .= " AND i.indent_no LIKE CONCAT('%', ?, '%')";
    $args[] = $q;
  }

  $sql = "SELECT i.id, i.indent_no, i.status, p.name AS project_name
          FROM indents i
          LEFT JOIN projects p ON p.id=i.project_id
          $where
          ORDER BY i.id DESC
          LIMIT 50";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
