<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();
ini_set('display_errors', '0');

$eobjResponseMode = 'json';
$eobjReturnTo = '';

// ── Helpers ───────────────────────────────────────────────────────────────────
function eobjDebugLog(string $message): void {
    error_log('[edit_objects] ' . $message);
}

function eobjRedirectWithStatus(string $status, string $message = ''): void {
    global $eobjReturnTo;

    $target = $eobjReturnTo;
    if ($target === '') {
        $target = baseUrl('admin/visualize.php');
    }

    $parts = parse_url($target);
    $query = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query['save_status'] = $status;
    if ($message !== '') {
        $query['save_message'] = $message;
    } else {
        unset($query['save_message']);
    }

    $path = $parts['path'] ?? $target;
    $redirectUrl = $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
    if (!empty($parts['fragment'])) {
        $redirectUrl .= '#' . $parts['fragment'];
    }

    header('Location: ' . $redirectUrl, true, 303);
    exit;
}

function eobjSendJson(array $payload, int $statusCode = 200): void {
    global $eobjResponseMode;

    if ($eobjResponseMode === 'redirect') {
        eobjRedirectWithStatus(
            !empty($payload['ok']) ? 'success' : 'error',
            isset($payload['error']) ? (string)$payload['error'] : ''
        );
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        eobjDebugLog('json_encode failed: ' . json_last_error_msg());
        $fallback = json_encode([
            'ok' => false,
            'error' => 'JSON encoding failed: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        eobjDebugLog('sending fallback JSON with length=' . ($fallback === false ? 0 : strlen($fallback)) . ' status=' . $statusCode);
        echo $fallback !== false ? $fallback : '{"ok":false,"error":"JSON encoding failed"}';
        exit;
    }

    eobjDebugLog('sending JSON with length=' . strlen($json) . ' status=' . $statusCode . ' ok=' . (isset($payload['ok']) && $payload['ok'] ? 'true' : 'false'));
    echo $json;
    exit;
}

function eobjError(string $msg, int $code = 400): void {
    eobjSendJson(['ok' => false, 'error' => $msg], $code);
}

function eobjOk(array $extra = []): void {
    eobjSendJson(array_merge(['ok' => true], $extra));
}

register_shutdown_function(static function (): void {
    global $eobjResponseMode;

    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'] ?? null, $fatalTypes, true)) {
        return;
    }

    if ($eobjResponseMode === 'redirect') {
        eobjDebugLog('shutdown handler redirecting fatal response: ' . ($error['message'] ?? 'unknown'));
        eobjRedirectWithStatus('error', 'Fatal server error: ' . ($error['message'] ?? 'Unknown error'));
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $json = json_encode([
        'ok' => false,
        'error' => 'Fatal server error: ' . ($error['message'] ?? 'Unknown error'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    eobjDebugLog('shutdown handler emitting fatal response: ' . ($error['message'] ?? 'unknown'));
    echo $json !== false ? $json : '{"ok":false,"error":"Fatal server error"}';
});

function eobjWriteJson(string $path, $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $path);
}

function eobjBuildAllCrops(int $instructorId, int $videoId, string $chapterDir, array $metadata): array {
    $dir = getResourcePath($instructorId, $videoId, $chapterDir . '/visual_objects');
    $diskFiles = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $f)) {
                $diskFiles[] = $f;
            }
        }
        sort($diskFiles);
    }

    $selected   = $metadata['visual_objects']['selected']   ?? [];
    $unselected = $metadata['visual_objects']['unselected'] ?? [];
    $result  = [];
    $tracked = [];

    foreach ($selected as $fn) {
        if (in_array($fn, $diskFiles, true)) {
            $result[]      = ['filename' => (string)$fn, 'url' => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn), 'selected' => true];
            $tracked[$fn]  = true;
        }
    }
    foreach ($unselected as $fn) {
        if (in_array($fn, $diskFiles, true)) {
            $result[]      = ['filename' => (string)$fn, 'url' => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn), 'selected' => false];
            $tracked[$fn]  = true;
        }
    }
    foreach ($diskFiles as $fn) {
        if (!isset($tracked[$fn])) {
            $result[] = ['filename' => (string)$fn, 'url' => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn), 'selected' => false];
        }
    }
    return $result;
}

function eobjLoadImageGd(string $path) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            return @imagecreatefromjpeg($path);
        case 'png':
            return @imagecreatefrompng($path);
        case 'webp':
            return @imagecreatefromwebp($path);
        default:
            return false;
    }
}

// ── Parse request ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    eobjError('POST required', 405);
}

eobjDebugLog('request start action=' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));

$payload = null;
if (isset($_POST['payload'])) {
    $payload = json_decode((string)$_POST['payload'], true);
    $eobjResponseMode = ($_POST['response_mode'] ?? 'json') === 'redirect' ? 'redirect' : 'json';
    $eobjReturnTo = (string)($_POST['return_to'] ?? '');
} else {
    $body = file_get_contents('php://input');
    $payload = json_decode($body, true);
    $eobjResponseMode = (($payload['response_mode'] ?? 'json') === 'redirect') ? 'redirect' : 'json';
    $eobjReturnTo = (string)($payload['return_to'] ?? '');
}

if (!is_array($payload)) {
    eobjError('Invalid JSON body');
}

$action  = (string)($payload['action'] ?? '');
$vid     = $payload['vid']     ?? null;
$chapter = $payload['chapter'] ?? null;
$slideMetaIn = is_array($payload['slideMeta'] ?? null) ? $payload['slideMeta'] : [];

if (!ctype_digit((string)$vid) || !ctype_digit((string)$chapter)) {
    eobjError('Invalid vid or chapter');
}
$videoId    = (int)$vid;
$chapterNum = (int)$chapter;
$chapterDir = 'chapter' . $chapterNum;

// ── Resolve instructor_id ─────────────────────────────────────────────────────
$pdo  = getDb();
$stmt = $pdo->prepare('SELECT instructor_id FROM videos WHERE video_id = ? LIMIT 1');
$stmt->execute([$videoId]);
$vRow = $stmt->fetch();
if (!$vRow) eobjError('Video not found', 404);
$instructorId = (int)$vRow['instructor_id'];

$metadataPath  = getResourcePath($instructorId, $videoId, $chapterDir . '/metadata.json');
$detectionPath = getResourcePath($instructorId, $videoId, $chapterDir . '/detection_data.json');
$visualObjDir  = getResourcePath($instructorId, $videoId, $chapterDir . '/visual_objects');
$slidesDir     = getResourcePath($instructorId, $videoId, $chapterDir . '/slides');

$metadata      = file_exists($metadataPath)  ? (json_decode(file_get_contents($metadataPath),  true) ?? []) : [];
$detectionData = file_exists($detectionPath) ? (json_decode(file_get_contents($detectionPath), true) ?? []) : [];

// ── Action: save_bboxes ───────────────────────────────────────────────────────
if ($action === 'save_bboxes') {
    $detectionsIn = $payload['detections'] ?? null;
    if (!is_array($detectionsIn)) eobjError('Missing detections');

    // Build old selected/unselected lookup for status preservation
    $oldSelectedSet   = array_flip($metadata['visual_objects']['selected']   ?? []);
    $oldUnselectedSet = array_flip($metadata['visual_objects']['unselected'] ?? []);

    $newSelected   = [];
    $newUnselected = [];

    if (!is_dir($visualObjDir)) {
        mkdir($visualObjDir, 0755, true);
    }

    $knownSlides = $metadata['slides'] ?? array_keys($detectionData);

    foreach ($knownSlides as $slideName) {
        // Validate slide name (no path traversal)
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', (string)$slideName)) continue;

        $pattern = $visualObjDir . '/' . $slideName . '_*.jpg';

        // ── Step 1: Delete all existing crops for this slide ─────────────────
        foreach (glob($pattern) as $existingCrop) {
            unlink($existingCrop);
        }

        $bboxList = $detectionsIn[$slideName] ?? [];

        if (empty($bboxList)) {
            // No bboxes left for this slide — clear detection entry
            unset($detectionData[$slideName]);
            continue;
        }

        // ── Step 2: Load slide image ─────────────────────────────────────────
        $slideFilePath = $slidesDir . '/' . $slideName;
        if (!file_exists($slideFilePath)) continue;

        $imgSize = @getimagesize($slideFilePath);
        if (!$imgSize) continue;
        [$actualW, $actualH] = $imgSize;

        $detEntry = $detectionData[$slideName] ?? [];
        $inferW   = (int)($detEntry['inference_width']  ?? 1280);
        $inferH   = (int)($detEntry['inference_height'] ?? 720);
        $coordMeta = is_array($slideMetaIn[$slideName] ?? null) ? $slideMetaIn[$slideName] : [];
        $coordW    = (int)($coordMeta['coordW'] ?? $inferW);
        $coordH    = (int)($coordMeta['coordH'] ?? $inferH);
        if ($coordW <= 0) {
            $coordW = $inferW > 0 ? $inferW : $actualW;
        }
        if ($coordH <= 0) {
            $coordH = $inferH > 0 ? $inferH : $actualH;
        }

        $scaleX   = $coordW > 0 ? ($actualW / $coordW) : 1.0;
        $scaleY   = $coordH > 0 ? ($actualH / $coordH) : 1.0;

        // ── Step 3: Sort bboxes by x1+y1 (distance from top-left) ───────────
        $bboxes = array_values((array)$bboxList);
        usort($bboxes, static function (array $a, array $b): int {
            $da = ($a['bbox_xyxy'][0] ?? 0) + ($a['bbox_xyxy'][1] ?? 0);
            $db = ($b['bbox_xyxy'][0] ?? 0) + ($b['bbox_xyxy'][1] ?? 0);
            return $da <=> $db;
        });

        // ── Step 4: Load source image ─────────────────────────────────────────
        $srcImg = eobjLoadImageGd($slideFilePath);
        if (!$srcImg) continue;

        $newDetections = [];
        $serial        = 1;

        foreach ($bboxes as $bboxItem) {
            $bboxRaw = $bboxItem['bbox_xyxy'] ?? null;
            if (!is_array($bboxRaw) || count($bboxRaw) < 4) continue;

            [$x1, $y1, $x2, $y2] = array_map('intval', $bboxRaw);

            // Clamp to the coordinate space used by the editor for this slide.
            $x1 = max(0, min($coordW, $x1));
            $y1 = max(0, min($coordH, $y1));
            $x2 = max(0, min($coordW, $x2));
            $y2 = max(0, min($coordH, $y2));
            if ($x2 <= $x1 || $y2 <= $y1) continue;

            // Scale to actual image coords
            $ax1   = max(0, (int)round($x1 * $scaleX));
            $ay1   = max(0, (int)round($y1 * $scaleY));
            $ax2   = min($actualW, (int)round($x2 * $scaleX));
            $ay2   = min($actualH, (int)round($y2 * $scaleY));
            $cropW = $ax2 - $ax1;
            $cropH = $ay2 - $ay1;
            if ($cropW < 2 || $cropH < 2) continue;

            // ── Step 5: Crop and save ─────────────────────────────────────────
            $newFilename = $slideName . '_' . $serial . '.jpg';
            $outputPath  = $visualObjDir . '/' . $newFilename;

            $dstImg = imagecreatetruecolor($cropW, $cropH);
            imagecopy($dstImg, $srcImg, 0, 0, $ax1, $ay1, $cropW, $cropH);
            imagejpeg($dstImg, $outputPath, 92);
            imagedestroy($dstImg);

            // ── Step 6: Preserve selected/unselected status ───────────────────
            $origFilename = $bboxItem['original_filename'] ?? null;
            if ($origFilename !== null && isset($oldSelectedSet[$origFilename])) {
                $newSelected[] = $newFilename;
            } else {
                $newUnselected[] = $newFilename;
            }

            $newDetections[] = [
                'output_filename' => $newFilename,
                'bbox_xyxy'       => [$x1, $y1, $x2, $y2],
                'confidence'      => isset($bboxItem['confidence']) ? round((float)$bboxItem['confidence'], 4) : null,
            ];
            $serial++;
        }

        imagedestroy($srcImg);

        // Update detection_data entry
        $detectionData[$slideName] = [
            'inference_width'  => $coordW,
            'inference_height' => $coordH,
            'detections'       => $newDetections,
        ];
    }

    // ── Write detection_data.json ─────────────────────────────────────────────
    if (!eobjWriteJson($detectionPath, $detectionData)) {
        eobjError('Failed to write detection_data.json', 500);
    }

    // ── Rebuild and write metadata.json ───────────────────────────────────────
    $metadata['visual_objects']['selected']   = array_values(array_unique($newSelected));
    $metadata['visual_objects']['unselected'] = array_values(array_unique($newUnselected));
    if (!eobjWriteJson($metadataPath, $metadata)) {
        eobjError('Failed to write metadata.json', 500);
    }

    // ── Build response: updated slides + allCrops ─────────────────────────────
    $responseSlides = [];
    foreach ($knownSlides as $slideName) {
        $entry = $detectionData[$slideName] ?? ['inference_width' => 1280, 'inference_height' => 720, 'detections' => []];
        $responseSlides[] = [
            'name'       => (string)$slideName,
            'inferenceW' => (int)($entry['inference_width']  ?? 1280),
            'inferenceH' => (int)($entry['inference_height'] ?? 720),
            'coordW'     => (int)($entry['inference_width']  ?? 1280),
            'coordH'     => (int)($entry['inference_height'] ?? 720),
            'detections' => array_map(static function (array $d): array {
                return [
                    'original_filename' => (string)($d['output_filename'] ?? ''),
                    'bbox_xyxy'         => array_map('intval', (array)($d['bbox_xyxy'] ?? [])),
                    'confidence'        => isset($d['confidence']) ? round((float)$d['confidence'], 4) : null,
                ];
            }, $entry['detections'] ?? []),
        ];
    }

    $allCrops = eobjBuildAllCrops($instructorId, $videoId, $chapterDir, $metadata);
    eobjOk(['slides' => $responseSlides, 'allCrops' => $allCrops]);
}

// ── Action: save_selection ────────────────────────────────────────────────────
if ($action === 'save_selection') {
    $selectedIn   = $payload['selected']   ?? null;
    $unselectedIn = $payload['unselected'] ?? null;

    if (!is_array($selectedIn) || !is_array($unselectedIn)) {
        eobjError('Missing selected or unselected arrays');
    }

    // Sanitize filenames
    $cleanSelected   = [];
    $cleanUnselected = [];
    foreach ($selectedIn as $fn) {
        if (is_string($fn) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $fn)) {
            $cleanSelected[] = $fn;
        }
    }
    foreach ($unselectedIn as $fn) {
        if (is_string($fn) && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $fn)) {
            $cleanUnselected[] = $fn;
        }
    }

    $metadata['visual_objects']['selected']   = $cleanSelected;
    $metadata['visual_objects']['unselected'] = $cleanUnselected;

    if (!eobjWriteJson($metadataPath, $metadata)) {
        eobjError('Failed to write metadata.json', 500);
    }

    $allCrops = eobjBuildAllCrops($instructorId, $videoId, $chapterDir, $metadata);
    eobjOk(['allCrops' => $allCrops]);
}

eobjError('Unknown action');
