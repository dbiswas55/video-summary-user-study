<?php
/**
 * Database Connection Test
 * ------------------------
 * Visit this in your browser to verify PHP can connect to MySQL.
 * Reports DB connection, table presence, and seed data counts.
 *
 * REMOVE OR PASSWORD-PROTECT THIS FILE BEFORE GOING TO PRODUCTION.
 */

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/functions.php';

$config = require __DIR__ . '/../app/config/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DB Connection Test</title>
<style>
  body { font-family: -apple-system, sans-serif; max-width: 720px; margin: 40px auto; padding: 20px; background: #f5f2ee; color: #1a1a1a; }
  h1 { font-size: 1.4rem; margin-bottom: 20px; }
  .card { background: #fff; padding: 20px 24px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  .ok { color: #15803d; }
  .err { color: #b91c1c; }
  .muted { color: #6b6b6b; font-size: 0.88rem; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; }
  th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #e0dbd4; font-size: 0.9rem; }
  th { background: #f5f2ee; font-weight: 600; }
  pre { background: #f5f2ee; padding: 10px 14px; border-radius: 6px; font-size: 0.82rem; overflow-x: auto; }
  .warn { background: #fef3c7; color: #92400e; padding: 12px 16px; border-radius: 8px; margin-top: 16px; font-size: 0.9rem; }
</style>
</head>
<body>

<h1>🔌 Database Connection Test</h1>

<div class="card">
  <h3>1. Configuration</h3>
  <table>
    <tr><th>Host</th><td><?= e($config['db']['host']) ?></td></tr>
    <tr><th>Port</th><td><?= e($config['db']['port']) ?></td></tr>
    <tr><th>Database</th><td><?= e($config['db']['name']) ?></td></tr>
    <tr><th>User</th><td><?= e($config['db']['user']) ?></td></tr>
    <tr><th>Socket</th><td><?= e($config['db']['socket'] ?: '(none — using TCP)') ?></td></tr>
    <tr><th>Base URL</th><td><?= e($config['base_url']) ?></td></tr>
    <tr><th>Debug Mode</th><td><?= $config['debug'] ? 'ON' : 'OFF' ?></td></tr>
  </table>
</div>

<?php
// 2. Connection
$connOk = false;
$connError = null;
try {
    $pdo = getDb();
    $connOk = true;
} catch (Throwable $e) {
    $connError = $e->getMessage();
}
?>
<div class="card">
  <h3>2. Connection</h3>
  <?php if ($connOk): ?>
    <p class="ok">✓ Connected successfully.</p>
  <?php else: ?>
    <p class="err">✗ Connection failed.</p>
    <pre><?= e($connError) ?></pre>
  <?php endif; ?>
</div>

<?php if ($connOk): ?>

<?php
// 3. Tables
$expectedTables = [
    'subjects', 'courses', 'videos', 'segments',
    'users', 'user_courses', 'user_segment_progress',
    'responses_familiarity', 'responses_ratings', 'responses_comments',
    'responses_visual_objects'
];
$existingTables = array_column(
    $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM),
    0
);
?>
<div class="card">
  <h3>3. Tables</h3>
  <table>
    <tr><th>Table</th><th>Status</th><th>Rows</th></tr>
    <?php foreach ($expectedTables as $tbl): ?>
      <?php
        $exists = in_array($tbl, $existingTables);
        $count = '—';
        if ($exists) {
            try {
                $count = $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            } catch (Throwable $e) { $count = 'err'; }
        }
      ?>
      <tr>
        <td><code><?= e($tbl) ?></code></td>
        <td><?= $exists ? '<span class="ok">✓ exists</span>' : '<span class="err">✗ missing</span>' ?></td>
        <td><?= e($count) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php
// 4. Seed Users
try {
    $users = $pdo->query("SELECT username, account_type, is_admin, password_hash FROM users ORDER BY id")->fetchAll();
} catch (Throwable $e) { $users = []; }
?>
<div class="card">
  <h3>4. Users</h3>
  <?php if (empty($users)): ?>
    <p class="muted">No users found. Set <code>operation = "setup"</code> in <code>scripts/db.py</code>, then run <code>python scripts/db.py</code>.</p>
  <?php else: ?>
    <table>
      <tr><th>Username</th><th>Type</th><th>Admin</th><th>Password set?</th></tr>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><code><?= e($u['username']) ?></code></td>
          <td><?= e($u['account_type']) ?></td>
          <td><?= $u['is_admin'] ? '✓' : '—' ?></td>
          <td><?= !empty($u['password_hash']) ? '<span class="ok">✓ yes</span>' : '<span class="err">✗ set operation = &quot;default-users&quot; in db.py</span>' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php
// 5. JSON configs
$jsonFiles = ['consent.json', 'study.json', 'resources.json'];
?>
<div class="card">
  <h3>5. JSON Config Files</h3>
  <table>
    <tr><th>File</th><th>Status</th></tr>
    <?php foreach ($jsonFiles as $file): ?>
      <?php
        $ok = false; $err = null;
        try { loadJsonConfig($file); $ok = true; }
        catch (Throwable $e) { $err = $e->getMessage(); }
      ?>
      <tr>
        <td><code><?= e($file) ?></code></td>
        <td><?= $ok ? '<span class="ok">✓ valid</span>' : '<span class="err">✗ ' . e($err) . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php endif; ?>

<div class="warn">
  ⚠️ <strong>Security note:</strong> This file exposes config details. Delete <code>tests/db_test.php</code> or restrict access before going to production.
</div>

<p class="muted" style="text-align:center; margin-top: 20px;">
  <a href="<?= e($config['base_url']) ?>">← Back to landing page</a>
</p>

</body>
</html>
