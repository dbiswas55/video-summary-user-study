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

function assetUrl($path) {
    $path = ltrim($path, '/');
    $fullPath = __DIR__ . '/../../' . $path;
    $version = file_exists($fullPath) ? filemtime($fullPath) : time();
    $separator = strpos($path, '?') === false ? '?' : '&';
    return baseUrl($path . $separator . 'v=' . $version);
}

function absoluteUrl($path = '') {
    $config = require __DIR__ . '/../config/config.php';
    $appUrl = trim($config['app_url'] ?? '');

    if ($appUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $appUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($config['base_url'], '/');
    }

    if ($appUrl === '') {
        return baseUrl($path);
    }

    return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
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

/**
 * Server-side filesystem path to a video's resource folder (or a file within it).
 * Used by PHP to read transcript.txt, summary files, scan slides/, etc.
 */
function getResourcePath($instructor_id, $video_id, $file = '') {
    return __DIR__ . "/../../resources/i{$instructor_id}/v{$video_id}/" . $file;
}

/**
 * Browser-accessible URL to a video's resource folder (or a file within it).
 * Used to build src= URLs for slide images.
 * Defaults to BASE_URL/resources/i{n}/v{n}/ when RESOURCES_URL is not set.
 */
function getResourceUrl($instructor_id, $video_id, $file = '') {
    $config = require __DIR__ . '/../config/config.php';
    $base = $config['resources_url']
        ? rtrim($config['resources_url'], '/')
        : rtrim($config['base_url'], '/') . '/resources';
    return "{$base}/i{$instructor_id}/v{$video_id}/" . $file;
}

/**
 * Browser-accessible URL to the video mp4 file.
 * Uses VIDEO_ROOT_URL if set; otherwise falls back to the resource URL root.
 */
function getVideoUrl($instructor_id, $video_id, $filename) {
    $config = require __DIR__ . '/../config/config.php';
    $base = $config['video_root_url']
        ? rtrim($config['video_root_url'], '/')
        : (
            $config['resources_url']
                ? rtrim($config['resources_url'], '/')
                : rtrim($config['base_url'], '/') . '/resources'
          );
    return "{$base}/i{$instructor_id}/v{$video_id}/{$filename}";
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
