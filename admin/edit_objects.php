<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

function eobjImageSize(string $path): ?array {
  if (!is_file($path)) {
    return null;
  }

  $size = @getimagesize($path);
  if (!$size) {
    return null;
  }

  return [
    'width' => (int)$size[0],
    'height' => (int)$size[1],
  ];
}

function eobjCropLooksLikeOriginalSpace(array $bbox, ?array $cropSize): bool {
  if (count($bbox) < 4 || !$cropSize) {
    return false;
  }

  $bboxW = (int)$bbox[2] - (int)$bbox[0];
  $bboxH = (int)$bbox[3] - (int)$bbox[1];
  if ($bboxW <= 0 || $bboxH <= 0) {
    return false;
  }

  $widthDiff = (int)$cropSize['width'] - $bboxW;
  $heightDiff = (int)$cropSize['height'] - $bboxH;

  return $widthDiff >= 0
    && $heightDiff >= 0
    && $widthDiff <= 20
    && $heightDiff <= 20;
}

function eobjNormalizeLegacyBbox(array $bbox, ?array $cropSize, ?array $slideSize): array {
  if (!eobjCropLooksLikeOriginalSpace($bbox, $cropSize)) {
    return $bbox;
  }

  $widthDiff = (int)$cropSize['width'] - ((int)$bbox[2] - (int)$bbox[0]);
  $heightDiff = (int)$cropSize['height'] - ((int)$bbox[3] - (int)$bbox[1]);

  $bbox[0] -= (int)floor($widthDiff / 2);
  $bbox[1] -= (int)floor($heightDiff / 2);
  $bbox[2] += (int)ceil($widthDiff / 2);
  $bbox[3] += (int)ceil($heightDiff / 2);

  if ($slideSize) {
    $bbox[0] = max(0, min((int)$slideSize['width'], (int)$bbox[0]));
    $bbox[1] = max(0, min((int)$slideSize['height'], (int)$bbox[1]));
    $bbox[2] = max(0, min((int)$slideSize['width'], (int)$bbox[2]));
    $bbox[3] = max(0, min((int)$slideSize['height'], (int)$bbox[3]));
  }

  return array_map('intval', $bbox);
}

function eobjShouldUseOriginalCoords(array $slideEntry, ?array $slideSize, string $visualObjectsDir, int $inferW, int $inferH): bool {
  if (!$slideSize) {
    return false;
  }

  foreach ($slideEntry['detections'] ?? [] as $det) {
    $bbox = array_map('intval', (array)($det['bbox_xyxy'] ?? []));
    if (count($bbox) < 4) {
      continue;
    }

    if ($bbox[2] > $inferW || $bbox[3] > $inferH) {
      return true;
    }

    $outputFilename = (string)($det['output_filename'] ?? '');
    if ($outputFilename === '') {
      continue;
    }

    $cropSize = eobjImageSize($visualObjectsDir . '/' . $outputFilename);
    if (eobjCropLooksLikeOriginalSpace($bbox, $cropSize)) {
      return true;
    }
  }

  return false;
}

// ── Validate params ───────────────────────────────────────────────────────────
$rawVid     = $_GET['vid']     ?? '';
$rawChapter = $_GET['chapter'] ?? '';
$rawSlide   = $_GET['slide']   ?? '';
if (!ctype_digit((string)$rawVid) || !ctype_digit((string)$rawChapter)) {
    http_response_code(400);
    die('Missing or invalid parameters.');
}
$requestedVideoId   = (int)$rawVid;
$requestedChapterNum = (int)$rawChapter;
$requestedSlideIndex = ctype_digit((string)$rawSlide) ? (int)$rawSlide : 0;

$pdo = getDb();

// ── DB query ──────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('
    SELECT
        v.id            AS internal_id,
        v.video_id,
        v.instructor_id,
        v.video_filename,
        c.code          AS course_code,
        c.name          AS course_name,
        s.id            AS segment_id,
        s.chapter_num,
        s.title         AS segment_title
    FROM videos v
    JOIN courses c ON c.id = v.course_id
    JOIN segments s ON s.video_id = v.id
    WHERE v.video_id = ? AND s.chapter_num = ?
    LIMIT 1
');
$stmt->execute([$requestedVideoId, $requestedChapterNum]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    die('Chapter not found.');
}

$instructorId = (int)$row['instructor_id'];
$videoId      = (int)$row['video_id'];
$chapterNum   = (int)$row['chapter_num'];
$chapterDir   = 'chapter' . $chapterNum;

// ── Read JSON files ───────────────────────────────────────────────────────────
$metadataPath  = getResourcePath($instructorId, $videoId, $chapterDir . '/metadata.json');
$detectionPath = getResourcePath($instructorId, $videoId, $chapterDir . '/detection_data.json');

$metadata      = file_exists($metadataPath)  ? (json_decode(file_get_contents($metadataPath),  true) ?? []) : [];
$detectionData = file_exists($detectionPath) ? (json_decode(file_get_contents($detectionPath), true) ?? []) : [];

$selectedFilenames   = $metadata['visual_objects']['selected']   ?? [];
$unselectedFilenames = $metadata['visual_objects']['unselected'] ?? [];
sort($selectedFilenames, SORT_STRING);
sort($unselectedFilenames, SORT_STRING);
$visualObjectsDir    = getResourcePath($instructorId, $videoId, $chapterDir . '/visual_objects');

// Use metadata slides list as the authoritative order; fall back to detection keys
$slidesList = $metadata['slides'] ?? array_keys($detectionData);

// ── Build slides for the JS editor ───────────────────────────────────────────
$slides = [];
foreach ($slidesList as $slideName) {
    $slideEntry = $detectionData[$slideName] ?? [];
    $inferW = (int)($slideEntry['inference_width']  ?? 1280);
    $inferH = (int)($slideEntry['inference_height'] ?? 720);
  $slidePath = getResourcePath($instructorId, $videoId, $chapterDir . '/slides/' . $slideName);
  $slideSize = eobjImageSize($slidePath);
  $actualW = (int)($slideSize['width'] ?? 0);
  $actualH = (int)($slideSize['height'] ?? 0);
  $useOriginalCoords = eobjShouldUseOriginalCoords($slideEntry, $slideSize, $visualObjectsDir, $inferW, $inferH);
  $coordW = $useOriginalCoords && $actualW > 0 ? $actualW : $inferW;
  $coordH = $useOriginalCoords && $actualH > 0 ? $actualH : $inferH;

    $detections = [];
    foreach ($slideEntry['detections'] ?? [] as $det) {
    $bbox = array_map('intval', (array)($det['bbox_xyxy'] ?? []));
    if ($useOriginalCoords) {
      $cropPath = $visualObjectsDir . '/' . (string)($det['output_filename'] ?? '');
      $bbox = eobjNormalizeLegacyBbox($bbox, eobjImageSize($cropPath), $slideSize);
    }

        $detections[] = [
            'original_filename' => (string)($det['output_filename'] ?? ''),
      'bbox_xyxy'         => $bbox,
            'confidence'        => isset($det['confidence']) ? round((float)$det['confidence'], 4) : null,
        ];
    }

    $slides[] = [
        'name'       => (string)$slideName,
        'url'        => getResourceUrl($instructorId, $videoId, $chapterDir . '/slides/' . $slideName),
        'inferenceW' => $inferW,
        'inferenceH' => $inferH,
    'coordW'     => $coordW,
    'coordH'     => $coordH,
    'actualW'    => $actualW,
    'actualH'    => $actualH,
        'detections' => $detections,
    ];
}

// ── Build allCrops for selection panel ────────────────────────────────────────
$allCropFiles = [];
if (is_dir($visualObjectsDir)) {
    foreach (scandir($visualObjectsDir) as $f) {
        if (preg_match('/\.(jpg|jpeg|png|webp)$/i', $f)) {
            $allCropFiles[] = $f;
        }
    }
    sort($allCropFiles);
}

// Build allCrops in order: selected first, then unselected, then any untracked
$allCrops = [];
$trackedInAllCrops = [];

foreach ($selectedFilenames as $fn) {
    if (in_array($fn, $allCropFiles, true)) {
        $allCrops[] = [
            'filename' => (string)$fn,
            'url'      => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn),
            'selected' => true,
        ];
        $trackedInAllCrops[$fn] = true;
    }
}
foreach ($unselectedFilenames as $fn) {
    if (in_array($fn, $allCropFiles, true)) {
        $allCrops[] = [
            'filename' => (string)$fn,
            'url'      => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn),
            'selected' => false,
        ];
        $trackedInAllCrops[$fn] = true;
    }
}
foreach ($allCropFiles as $fn) {
    if (!isset($trackedInAllCrops[$fn])) {
        $allCrops[] = [
            'filename' => (string)$fn,
            'url'      => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $fn),
            'selected' => false,
        ];
    }
}

  $initialSlideIndex = 0;
  if ($slides) {
    $maxSlideIndex = count($slides) - 1;
    $initialSlideIndex = max(0, min($maxSlideIndex, $requestedSlideIndex));
  }

// ── Page setup ────────────────────────────────────────────────────────────────
$videoLabel = displayVideoName((string)($row['video_filename'] ?? ''));
$saveStatus = (string)($_GET['save_status'] ?? '');
$saveMessage = (string)($_GET['save_message'] ?? '');
$returnUrl = baseUrl('admin/edit_objects.php?vid=' . $videoId . '&chapter=' . $chapterNum);
$pageTitle     = 'Edit Objects — Chapter ' . $chapterNum . ' — Admin';
$pageStyles    = ['assets/css/admin-edit-objects.css'];
$pageScripts   = ['assets/js/admin-edit-objects.js'];
$pageMainClass = 'site-main-edit-objects';

include __DIR__ . '/../app/includes/header.php';
?>

<div class="eobj-page">

  <?php if ($saveStatus !== ''): ?>
    <div class="eobj-flash <?= $saveStatus === 'success' ? 'eobj-flash-success' : 'eobj-flash-error' ?>">
      <?= e($saveMessage !== '' ? $saveMessage : ($saveStatus === 'success' ? 'Changes saved.' : 'Save failed.')) ?>
    </div>
  <?php endif; ?>

  <header class="eobj-header">
    <div class="eobj-header-info">
      <div class="eobj-kicker">Admin · Edit Visual Objects</div>
      <h1><?= e($videoLabel) ?> — Chapter <?= e($chapterNum) ?></h1>
      <p>
        <?= e($row['course_code']) ?>
        &nbsp;·&nbsp;
        <?= e($row['segment_title']) ?>
        &nbsp;·&nbsp;
        Video ID <?= e($videoId) ?>
      </p>
    </div>
    <div class="eobj-header-actions">
      <a href="<?= e(baseUrl('admin/visualize.php?vid=' . $videoId)) ?>" class="btn btn-secondary btn-sm">
        &larr; Back to Visualize
      </a>
    </div>
  </header>

  <?php if (!$slides): ?>
    <div class="eobj-empty-state">
      <p>No slides found for this chapter. Ensure <code>metadata.json</code> and <code>detection_data.json</code> are present.</p>
    </div>

  <?php else: ?>

  <!-- ── Two-column editor layout ── -->
  <div class="eobj-layout">

    <!-- Left: slide thumbnail list -->
    <aside class="eobj-slide-list" id="eobj-slide-list">
      <div class="eobj-slide-list-header">
        Slides <span class="eobj-slide-list-count">(<?= count($slides) ?>)</span>
      </div>
      <!-- Thumbnails injected by JS -->
    </aside>

    <!-- Right: bbox editor -->
    <div class="eobj-editor-col">
      <div class="eobj-editor-toolbar">
        <div class="eobj-toolbar-left">
          <span class="eobj-toolbar-slide-name" id="eobj-slide-name">&mdash;</span>
          <span class="eobj-bbox-count" id="eobj-bbox-count"></span>
        </div>
        <div class="eobj-toolbar-right">
          <span class="eobj-toolbar-hint">Drag image to draw &middot; Drag box to move &middot; Drag handles to resize</span>
          <span class="eobj-status" id="eobj-save-status"></span>
          <button type="button" class="btn btn-primary btn-sm" id="eobj-save-btn">
            Save Bounding Boxes
          </button>
        </div>
      </div>

      <div class="eobj-canvas-wrap" id="eobj-canvas-wrap">
        <img id="eobj-slide-img" src="" alt="Slide">
      </div>
    </div>

  </div><!-- /.eobj-layout -->

  <!-- ── Selection panel ── -->
  <div class="eobj-selection-section" id="eobj-selection-section">
    <div class="eobj-selection-header">
      <div>
        <h2>Visual Object Selection</h2>
        <p class="eobj-sel-desc">Click a badge to toggle selected / unselected, then save.</p>
      </div>
      <div class="eobj-sel-actions">
        <span class="eobj-status" id="eobj-sel-status"></span>
        <button type="button" class="btn btn-primary btn-sm" id="eobj-sel-save-btn">
          Save Selection
        </button>
      </div>
    </div>

    <div class="eobj-crop-grid" id="eobj-crop-grid">
      <!-- Crop cards injected by JS -->
    </div>
  </div>

  <?php endif; ?>

</div><!-- /.eobj-page -->

<script>
window.EDITOR_DATA = <?= json_encode([
    'vid'      => $videoId,
    'chapter'  => $chapterNum,
    'saveUrl'  => baseUrl('admin/save_objects_ajax.php'),
  'returnUrl' => $returnUrl,
  'initialSlideIndex' => $initialSlideIndex,
    'slides'   => $slides,
    'allCrops' => $allCrops,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
