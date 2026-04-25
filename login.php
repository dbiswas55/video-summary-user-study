<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSessionIfNeeded();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('index.php'));
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error']      = 'Please enter both username and password.';
    $_SESSION['prefill_username'] = $username;
    header('Location: ' . baseUrl('index.php?tab=login'));
    exit;
}

$pdo = getDb();
$stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? AND is_active = TRUE');
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || $user['password_hash'] === '' || !password_verify($password, $user['password_hash'])) {
    $_SESSION['login_error']      = 'Invalid username or password.';
    $_SESSION['prefill_username'] = $username;
    header('Location: ' . baseUrl('index.php?tab=login'));
    exit;
}

loginUser($user);

if ($user['is_admin']) {
    header('Location: ' . baseUrl('admin/index.php'));
} else {
    header('Location: ' . baseUrl('dashboard.php'));
}
exit;
