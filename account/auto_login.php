<?php
/**
 * Token-based one-click access.
 * URL format: account/auto_login.php?token=<login_token>
 *
 * Pre-issued accounts without passwords use this as their normal login path.
 * Accounts with passwords use this as a temporary password-reset access path.
 * Any token is revoked when the user saves a new password from Profile.
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
    setFlash('error', 'This access link is invalid or has been revoked.');
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$hasPassword = $user['password_hash'] !== '';
$isPreissuedPasswordless = $user['account_type'] === 'pre_issued' && !$hasPassword;

loginUser($user);

if ($isPreissuedPasswordless) {
    setFlash('success', 'Welcome, ' . $user['username'] . '!');
    header('Location: ' . baseUrl($user['is_admin'] ? 'admin/index.php' : 'dashboard.php'));
} else {
    header('Location: ' . baseUrl('account/profile.php'));
}
exit;
