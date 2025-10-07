<?php
/**
 * PATH: /public_html/tools/seed_permissions.php
 * MODULE: rbac
 * PURPOSE: Seed baseline permissions, Admin role, and admin user
 * REQUIRES: /includes/db.php
 * RUN: open once in browser or CLI; safe to re-run
 */

declare(strict_types=1);

// --- Make this page 100% standalone, no layout/JS ---
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
while (ob_get_level()) { @ob_end_clean(); } // kill any buffering early

// require ONLY db(), nothing from UI
require_once __DIR__ . '/../includes/db.php';

echo "Seeding permissions...\n";

try {
    $pdo = db();
    $pdo->beginTransaction();

    // --- define permissions (code => module); name mirrors code ---
    $perms = [
        // identity
        'users.read'         => 'users',
        'users.create'       => 'users',
        'users.update'       => 'users',
        'users.delete'       => 'users',

        'roles.read'         => 'roles',
        'roles.update'       => 'roles',

        'permissions.read'   => 'permissions',
        'permissions.update' => 'permissions',

        // org
        'departments.read'   => 'departments',
        'departments.create' => 'departments',
        'departments.update' => 'departments',
        'departments.delete' => 'departments',

        'locations.read'     => 'locations',
        'locations.create'   => 'locations',
        'locations.update'   => 'locations',
        'locations.delete'   => 'locations',

        // foundations you already have
        'uom.manage'                 => 'uom',
        'material.taxonomy.manage'   => 'material',
        'items.read'                 => 'items',
        'items.create'               => 'items',
        'items.update'               => 'items',
        'items.delete'               => 'items',
        'parties.read'               => 'parties',
        'parties.create'             => 'parties',
        'parties.update'             => 'parties',
        'parties.delete'             => 'parties',
    ];

    // Ensure columns exist (patch older schemas): permissions.key/name -> code/module/name
    // Safe on MariaDB: ignore errors if already applied.
    try { $pdo->exec("ALTER TABLE permissions CHANGE `key` `code` VARCHAR(150) NOT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE permissions ADD COLUMN `module` VARCHAR(80) NOT NULL DEFAULT 'misc' AFTER `code`"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE permissions MODIFY `name` VARCHAR(191) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX uq_permissions_code ON permissions(code)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_perm_module ON permissions(module)"); } catch (Throwable $e) {}

    // Insert permissions idempotently
    $ins = $pdo->prepare("INSERT IGNORE INTO permissions(code, module, name) VALUES(?,?,?)");
    $added = 0;
    foreach ($perms as $code => $module) {
        $ins->execute([$code, $module, $code]);
        $added += (int)$ins->rowCount();
    }
    echo "Permissions added: $added\n";

    // Ensure Admin role
    $pdo->exec("INSERT IGNORE INTO roles(name, active) VALUES('Admin', 1)");
    echo "Admin role ensured.\n";

    // Grant all perms to Admin
    $pdo->exec("
        INSERT IGNORE INTO role_permissions(role_id, permission_id)
        SELECT r.id, p.id
        FROM roles r
        JOIN permissions p
        WHERE r.name='Admin'
    ");
    echo "Admin granted all permissions.\n";

    // Ensure admin user + map to Admin
    $hash = password_hash('Admin@123', PASSWORD_DEFAULT); // change later
    $pdo->exec("
        INSERT IGNORE INTO users(username, name, email, password, status)
        VALUES('admin','System Admin','admin@example.com', ".$pdo->quote($hash).", 'active')
    ");
    $pdo->exec("
        INSERT IGNORE INTO user_roles(user_id, role_id)
        SELECT u.id, r.id
        FROM users u, roles r
        WHERE u.username='admin' AND r.name='Admin'
    ");
    echo "Admin user ensured (username: admin / default password: Admin@123).\n";

    $pdo->commit();
    echo "DONE.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    echo "ERROR: ".$e->getMessage()."\n";
}

// Stop here so no layout/JS is appended by accident.
exit;
