<?php
/**
 * Sends a one-click password reset access link when the submitted email
 * belongs to an active non-admin account. The response is intentionally
 * generic so account existence is not exposed.
 */

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/mailer.php';

startSessionIfNeeded();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . baseUrl('index.php?tab=login'));
    exit;
}

$email = strtolower(trim($_POST['reset_email'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['login_error'] = 'Please enter a valid email address for password help.';
    header('Location: ' . baseUrl('index.php?tab=login'));
    exit;
}

$genericMessage = 'If that email is linked to an active account, a password access link has been sent.';

if (isMailConfigured()) {
    $pdo = getDb();
    $stmt = $pdo->prepare('
        SELECT id, username, email
        FROM users
        WHERE email = ?
          AND is_active = TRUE
          AND is_admin = FALSE
        LIMIT 1
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $pdo->prepare('UPDATE users SET login_token = ? WHERE id = ?')
            ->execute([$token, $user['id']]);

        $link = absoluteUrl('account/auto_login.php?token=' . $token);
        $body = "Hello {$user['username']},\n\n"
              . "Use this one-click access link to sign in and change your password:\n\n"
              . $link . "\n\n"
              . "After you save a new password from Profile, this link will stop working.\n\n"
              . "If you did not request this, you can ignore this email.\n";

        sendAppEmail($user['email'], 'Password access link for VideoPoints User Study', $body);
    }
}

setFlash('info', $genericMessage);
header('Location: ' . baseUrl('index.php?tab=login'));
exit;
