<?php
/**
 * Authentication Helpers
 */

require_once __DIR__ . '/db.php';

function startSessionIfNeeded() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    startSessionIfNeeded();
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    startSessionIfNeeded();
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function requireLogin() {
    startSessionIfNeeded();
    if (!isLoggedIn()) {
        $config = require __DIR__ . '/../config/config.php';
        header('Location: ' . $config['base_url'] . 'index.php');
        exit;
    }

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !(bool)$user['is_active']) {
        logoutUser();
        $config = require __DIR__ . '/../config/config.php';
        header('Location: ' . $config['base_url'] . 'index.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $config = require __DIR__ . '/../config/config.php';
        header('Location: ' . $config['base_url'] . 'dashboard.php');
        exit;
    }
}

function getCurrentUser() {
    startSessionIfNeeded();
    if (!isLoggedIn()) return null;

    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT id, username, subject_id, account_type, is_admin FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function loginUser($user) {
    startSessionIfNeeded();
    session_regenerate_id(true);

    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['is_admin']   = (bool)$user['is_admin'];
    $_SESSION['login_time'] = time();

    $pdo = getDb();
    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);
}

function logoutUser() {
    startSessionIfNeeded();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
