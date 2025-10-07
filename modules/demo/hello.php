<?php
declare(strict_types=1);
$title = "Items";
$items = ["apple","banana","cherry"];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-3">
  <div class="container">
    <h1 class="h4"><?= htmlspecialchars($title) ?></h1>
    <ul>
      <?php foreach ($items as $it): ?>
        <li><?= htmlspecialchars($it) ?></li>
      <?php endforeach; ?>
    </ul>
    <button class="btn btn-outline-secondary">Click me</button>
  </div>
</body>
</html>