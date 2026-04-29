<?php
/**
 * Token-based one-click access.
 * URL format: account/auto_login.php?token=<login_token>
 *
 * Active one-click links stay valid until an admin regenerates or revokes them,
 * or the account is deactivated.
 */

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

startSessionIfNeeded();

$token = trim($_GET['token'] ?? '');

if ($token === '' || strlen($token) < 16) {
    setFlash('error', 'Invalid login link.');
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$pdo = getDb();
$stmt = $pdo->prepare('
    SELECT * FROM users
    WHERE login_token = ?
      AND is_active = TRUE
    LIMIT 1
');
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'This access link is invalid, has been revoked, or the account is inactive.');
    header('Location: ' . baseUrl('index.php'));
    exit;
}

loginUser($user);

setFlash('success', 'Welcome, ' . $user['username'] . '!');
header('Location: ' . baseUrl($user['is_admin'] ? 'admin/index.php' : 'dashboard.php'));
exit;
