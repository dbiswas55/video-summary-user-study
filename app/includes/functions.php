<?php
/**
 * Shared Helper Functions
 */

require_once __DIR__ . '/db.php';

function loadJsonConfig(string $filename): array {
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

function baseUrl(string $path = ''): string {
    $config = require __DIR__ . '/../config/config.php';
    return $config['base_url'] . ltrim($path, '/');
}

function assetUrl(string $path): string {
    $path = ltrim($path, '/');
    $fullPath = __DIR__ . '/../../' . $path;
    $version = file_exists($fullPath) ? filemtime($fullPath) : time();
    $separator = strpos($path, '?') === false ? '?' : '&';
    return baseUrl($path . $separator . 'v=' . $version);
}

function absoluteUrl(string $path = ''): string {
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

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getSubjects(): array {
    $pdo = getDb();
    return $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
}

function getCoursesBySubject(int $subject_id): array {
    $pdo = getDb();
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE subject_id = ? ORDER BY code');
    $stmt->execute([$subject_id]);
    return $stmt->fetchAll();
}

function getUserCourses(int $user_id): array {
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

function displayVideoName(string $filename): string {
    if ($filename === '') {
        return 'Video file not configured';
    }

    $core = preg_replace('/[^A-Za-z0-9]+[0-9]{4}\.mp4$/i', '', $filename);
    if ($core === $filename) {
        $core = pathinfo($filename, PATHINFO_FILENAME);
    }

    $core = preg_replace_callback('/[^A-Za-z0-9]+/', function (array $match): string {
        $symbols = $match[0];
        if (strlen($symbols) === 1) {
            return $symbols;
        }

        $without_underscores = str_replace('_', '', $symbols);
        return $without_underscores !== ''
            ? $without_underscores[0]
            : '_';
    }, $core);

    return trim($core);
}

/**
 * Server-side filesystem path to a video's resource folder (or a file within it).
 * Used by PHP to read transcript.vtt, summary files, scan slides/, etc.
 */
function getResourcePath(int $instructor_id, int $video_id, string $file = ''): string {
    return __DIR__ . "/../../resources/i{$instructor_id}/v{$video_id}/" . $file;
}

/**
 * Browser-accessible URL to a video's resource folder (or a file within it).
 * Used to build src= URLs for slide images.
 * Defaults to BASE_URL/resources/i{n}/v{n}/ when RESOURCES_URL is not set.
 */
function getResourceUrl(int $instructor_id, int $video_id, string $file = ''): string {
    $config = require __DIR__ . '/../config/config.php';
    $base = $config['resources_url']
        ? rtrim($config['resources_url'], '/')
        : rtrim($config['base_url'], '/') . '/resources';
    return "{$base}/i{$instructor_id}/v{$video_id}/" . $file;
}

/**
 * Browser-accessible URL to a file stored beside the video mp4.
 * Uses VIDEO_ROOT_URL if set; otherwise falls back to the resource URL root.
 */
function getVideoAssetUrl(int $instructor_id, int $video_id, string $filename): string {
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

function getVideoUrl(int $instructor_id, int $video_id, string $filename): string {
    return getVideoAssetUrl($instructor_id, $video_id, $filename);
}

function isValidUsername(string $username): bool {
    return preg_match('/^[a-zA-Z0-9_-]{3,50}$/', $username) === 1;
}

function setFlash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
