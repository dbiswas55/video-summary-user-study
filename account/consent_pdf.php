<?php
require_once __DIR__ . '/../app/includes/functions.php';

$consent = loadJsonConfig('consent.json');
$filename = basename($consent['pdf']['filename'] ?? '');
$path = __DIR__ . '/../app/config/' . $filename;

if ($filename === '' || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
    http_response_code(404);
    echo 'Consent form PDF not found.';
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
