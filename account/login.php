<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

startSessionIfNeeded();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$username = trim($_POST['username'] ?? '');
$email    = strtolower(trim($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

$hasUsername = $username !== '';
$hasEmail    = $email !== '';
$hasPassword = $password !== '';

$pdo  = getDb();
$user = null;
$err  = '';

if ($hasPassword && ($hasUsername || $hasEmail)) {
    // Path A: (username OR email) + password
    $id   = $hasUsername ? $username : $email;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = TRUE LIMIT 1');
    $stmt->execute([$id, strtolower($id)]);
    $row = $stmt->fetch();
    if ($row && $row['password_hash'] !== '' && password_verify($password, $row['password_hash'])) {
        $user = $row;
    } else {
        $err = 'Invalid credentials.';
    }

} elseif ($hasUsername && $hasEmail && !$hasPassword) {
    // Path B: username + email, no password — only for accounts without a password set
    $stmt = $pdo->prepare('
        SELECT * FROM users
        WHERE username = ?
          AND email = ?
          AND account_type = "pre_issued"
          AND password_hash = ""
          AND is_active = TRUE
        LIMIT 1
    ');
    $stmt->execute([$username, $email]);
    $row = $stmt->fetch();
    if (!$row) {
        $err = 'No passwordless pre-issued account found matching that username and email.';
    } else {
        $user = $row;
    }

} else {
    $err = 'Please enter a username or email plus password. Pre-issued accounts without a password may use username and email.';
}

if (!$user) {
    $_SESSION['login_error']      = $err;
    $_SESSION['prefill_username'] = $hasUsername ? $username : $email;
    header('Location: ' . baseUrl('index.php?tab=login'));
    exit;
}

loginUser($user);
header('Location: ' . baseUrl($user['is_admin'] ? 'admin/index.php' : 'dashboard.php'));
exit;
