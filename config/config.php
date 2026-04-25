<?php
/**
 * Configuration File
 * ------------------
 * Loads settings from the project-root `.env` file.
 *
 * Do NOT commit `.env` to git. Use `.env.example` as a template.
 */

/**
 * Minimal .env loader (no Composer dependency).
 * Populates $_ENV and getenv().
 */
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip optional surrounding quotes
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

loadEnv(__DIR__ . '/../.env');

/** Helper: read env var with fallback */
function env($key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

return [
    // Database
    'db' => [
        'host'    => env('DB_HOST', 'localhost'),
        'port'    => (int) env('DB_PORT', 3306),
        'name'    => env('DB_NAME', 'userstudy_vds'),
        'user'    => env('DB_USER', 'root'),
        'pass'    => env('DB_PASS', ''),
        'socket'  => env('DB_SOCKET', ''),  // MAMP only
        'charset' => 'utf8mb4'
    ],

    // App
    'base_url'         => env('BASE_URL', '/userstudy2/'),
    'site_title'       => 'Video Detailed Summary - User Study',
    'session_lifetime' => (int) env('SESSION_LIFETIME', 3600),
    'debug'            => env('DEBUG', 'false') === 'true',

    // Paths
    'paths' => [
        'config'    => __DIR__,
        'resources' => __DIR__ . '/../resources',
        'fragments' => __DIR__ . '/../chapters/fragments'
    ],

    // Resources
    'video_root_url' => env('VIDEO_ROOT_URL', '')
];
