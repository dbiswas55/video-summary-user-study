<?php
/**
 * Shared Helper Functions
 */

require_once __DIR__ . '/db.php';

function loadJsonConfig($filename) {
    static $cache = [];
    if (isset($cache[$filename])) return $cache[$filename];

    $path = __DIR__ . "/../config/{$filename}";
    if (!file_exists($path)) {
        throw new Exception("Config file not found: {$filename}");
    }
    $data = json_decode(file_get_contents($path), true);
    if ($data === null) {
        throw new Exception("Invalid JSON in {$filename}: " . json_last_error_msg());
    }
    $cache[$filename] = $data;
    return $data;
}

function baseUrl($path = '') {
    $config = require __DIR__ . '/../config/config.php';
    return $config['base_url'] . ltrim($path, '/');
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getSubjects() {
    $pdo = getDb();
    return $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
}

function getCoursesBySubject($subject_id) {
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE subject_id = ? ORDER BY code');
    $stmt->execute([$subject_id]);
    return $stmt->fetchAll();
}

function getUserCourses($user_id) {
    $pdo = getDb();
    $stmt = $pdo->prepare('
        SELECT c.* FROM courses c
        JOIN user_courses uc ON c.id = uc.course_id
        WHERE uc.user_id = ?
        ORDER BY c.code
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function getResourcePath($instructor_id, $video_id, $file = '') {
    return __DIR__ . "/../resources/{$instructor_id}/{$video_id}/" . $file;
}

function getVideoUrl($instructor_id, $video_id, $filename) {
    $config = require __DIR__ . '/../config/config.php';
    return rtrim($config['video_root_url'], '/') . "/{$instructor_id}/{$video_id}/{$filename}";
}

function isValidUsername($username) {
    return preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username) === 1;
}

function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
