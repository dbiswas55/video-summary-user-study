<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('admin/manage.php'));
    exit;
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    setFlash('error', 'Invalid user selection.');
    header('Location: ' . baseUrl('admin/manage.php'));
    exit;
}

$pdo = getDb();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . baseUrl('admin/manage.php'));
    exit;
}

if (!empty($user['is_admin'])) {
    setFlash('error', 'Admin accounts cannot be opened as participant sessions.');
    header('Location: ' . baseUrl('admin/manage.php'));
    exit;
}

if (empty($user['is_active'])) {
    setFlash('error', 'Inactive users cannot be opened.');
    header('Location: ' . baseUrl('admin/manage.php'));
    exit;
}

loginUser($user);
setFlash('success', 'Now signed in as ' . $user['username'] . '.');
header('Location: ' . baseUrl('dashboard.php'));
exit;