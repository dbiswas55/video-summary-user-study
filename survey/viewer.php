<?php
/**
 * Segment Viewer
 * --------------
 * Dynamically renders the comparative study page for a single video segment.
 *
 * Route: /survey/viewer.php?vid={video_id}&chapter={chapter_num}
 *   video_id = the real integer video ID (e.g. 9230)
 *
 * Reads from:
 *   DB      — video + segment + course metadata
 *   Disk    — transcript.vtt beside the video file
 *   Disk    — chapterN/transcript_summary.txt, chapterN/multimodal_summary.txt
 *   Disk    — chapterN/slides/ directory listing
 */

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/auth.php';

// ── Authentication ────────────────────────────────────────────────────────────
requireLogin();

$pdo = getDb();
$study = loadJsonConfig('study.json');
$raw_dimensions = $study['dimensions'] ?? [];
$study_dimensions = [];
foreach ($raw_dimensions as $dim) {
    $id = (string)($dim['id'] ?? '');
    if (!preg_match('/^[a-z_]+$/', $id)) {
        continue;
    }
    $study_dimensions[] = [
        'id' => $id,
        'label' => (string)($dim['label'] ?? $id),
        'question' => (string)($dim['question'] ?? ''),
    ];
}
if (!$study_dimensions) {
    http_response_code(500);
    die('Survey dimensions are not configured.');
}

$raw_familiarity_options = $study['familiarity_options'] ?? [];
$familiarity_options = [];
foreach ($raw_familiarity_options as $option) {
    $id = (string)($option['id'] ?? '');
    if (!preg_match('/^[a-z_]+$/', $id)) {
        continue;
    }
    $familiarity_options[] = [
        'id' => $id,
        'label' => (string)($option['label'] ?? $id),
    ];
}
if (!$familiarity_options) {
    http_response_code(500);
    die('Familiarity options are not configured.');
}

$rating_scale = $study['rating_scale'] ?? [];
$scale_min = (int)($rating_scale['min'] ?? 1);
$scale_max = (int)($rating_scale['max'] ?? 10);
if ($scale_min < 1 || $scale_max > 10 || $scale_min >= $scale_max) {
    $scale_min = 1;
    $scale_max = 10;
}
$scale_hint = sprintf(
    '%s: %d = %s %s %d = %s',
    $rating_scale['scale_label'] ?? 'Scale',
    $scale_min,
    $rating_scale['low_label'] ?? 'Poor',
    $rating_scale['connector'] ?? '→',
    $scale_max,
    $rating_scale['high_label'] ?? 'Excellent'
);
$question_total = 1 + count($study_dimensions);
$visual_question_defaults = [
    'selection_quality' => [
        'label' => 'Selection Quality',
        'question' => 'Rate the overall quality of the selected visual objects for this video chapter.',
    ],
    'include_important' => [
        'label' => 'Include Important',
        'question' => 'Select any unselected visual objects that are important and should be included.',
    ],
    'exclude_unimportant' => [
        'label' => 'Exclude Unimportant',
        'question' => 'Unselect any selected visual objects that are unimportant and should be excluded.',
    ],
];
$visual_questions_config = is_array($study['visual_questions'] ?? null) ? $study['visual_questions'] : [];
$visual_questions = [];
foreach ($visual_question_defaults as $id => $defaults) {
    $config = is_array($visual_questions_config[$id] ?? null) ? $visual_questions_config[$id] : [];
    $visual_questions[$id] = [
        'label' => (string)($config['label'] ?? $defaults['label']),
        'question' => (string)($config['question'] ?? $defaults['question']),
    ];
}

// ── Resolve video from ?vid= ──────────────────────────────────────────────────
$raw_vid = $_GET['vid'] ?? '';
if (!ctype_digit((string)$raw_vid)) {
    http_response_code(400);
    die('Missing or invalid video ID.');
}
$req_video_id = (int)$raw_vid;
$raw_chapter = $_GET['chapter'] ?? null;
$req_chapter_num = null;
if ($raw_chapter !== null && $raw_chapter !== '') {
    if (!ctype_digit((string)$raw_chapter)) {
        http_response_code(400);
        die('Missing or invalid chapter number.');
    }
    $req_chapter_num = (int)$raw_chapter;
}

// ── Load video + segment + course ────────────────────────────────────────────
$sql = '
    SELECT
        v.id            AS internal_id,
        v.video_id,
        v.instructor_id,
        v.video_filename,
        v.course_id,
        c.name          AS course_name,
        c.code          AS course_code,
        s.id            AS segment_id,
        s.chapter_num,
        s.title         AS segment_title,
        s.start_s,
        s.end_s,
        s.duration_s,
        s.slide_range_start,
        s.slide_range_end,
        s.summary_a_file,
        s.summary_b_file,
        s.version_assignment
    FROM videos v
    JOIN courses c  ON c.id = v.course_id
    JOIN segments s ON s.video_id = v.id
    WHERE v.video_id = ?
';
$params = [$req_video_id];
if ($req_chapter_num !== null) {
    $sql .= ' AND s.chapter_num = ?';
    $params[] = $req_chapter_num;
}
$sql .= ' ORDER BY s.display_order, s.chapter_num LIMIT 1';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    die('Video not found.');
}

$instructor_id = (int)$row['instructor_id'];
$video_id      = (int)$row['video_id'];
$chapter_num   = (int)$row['chapter_num'];
$chapter_dir   = 'chapter' . $chapter_num;

// ── Read text files from disk ─────────────────────────────────────────────────
function readResource($instructor_id, $video_id, $filename) {
    $path = getResourcePath($instructor_id, $video_id, $filename);
    return file_exists($path) ? file_get_contents($path) : '';
}

function readChapterResource($instructor_id, $video_id, $chapter_dir, $filename) {
    $text = readResource($instructor_id, $video_id, $filename);
    if ($text !== '' || strpos($filename, '/') !== false) {
        return $text;
    }
    return readResource($instructor_id, $video_id, $chapter_dir . '/' . basename($filename));
}

function vttTimeToSeconds($time) {
    $time = str_replace(',', '.', trim($time));
    $parts = array_map('floatval', explode(':', $time));
    if (count($parts) === 3) {
        return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
    }
    if (count($parts) === 2) {
        return ($parts[0] * 60) + $parts[1];
    }
    return (float)$time;
}

function vttEndTime($vtt) {
    $max_end = 0.0;
    if (preg_match_all('/-->\s*([0-9:.]+)/', $vtt, $matches)) {
        foreach ($matches[1] as $end_time) {
            $max_end = max($max_end, vttTimeToSeconds($end_time));
        }
    }
    return $max_end;
}

$transcript_vtt = readResource($instructor_id, $video_id, 'transcript.vtt');
$summary_a_text = readChapterResource($instructor_id, $video_id, $chapter_dir, $row['summary_a_file']);
$summary_b_text = readChapterResource($instructor_id, $video_id, $chapter_dir, $row['summary_b_file']);

$metadata_path = getResourcePath($instructor_id, $video_id, $chapter_dir . '/metadata.json');
$chapter_meta = file_exists($metadata_path)
    ? json_decode(file_get_contents($metadata_path), true)
    : [];
$visual_meta = is_array($chapter_meta['visual_objects'] ?? null) ? $chapter_meta['visual_objects'] : [];

function buildVisualObjects($instructor_id, $video_id, $chapter_dir, $files, $prefix) {
    $items = [];
    foreach (array_values($files ?? []) as $i => $file) {
        $label = $prefix . ($i + 1);
        $items[] = [
            'label' => $label,
            'file' => (string)$file,
            'url' => getResourceUrl($instructor_id, $video_id, $chapter_dir . '/visual_objects/' . $file),
        ];
    }
    return $items;
}

$selected_visuals = buildVisualObjects(
    $instructor_id,
    $video_id,
    $chapter_dir,
    $visual_meta['selected'] ?? [],
    'S'
);
$unselected_visuals = buildVisualObjects(
    $instructor_id,
    $video_id,
    $chapter_dir,
    $visual_meta['unselected'] ?? [],
    'U'
);

if ((float)$row['end_s'] <= (float)$row['start_s']) {
    $inferred_end = vttEndTime($transcript_vtt);
    if ($inferred_end > (float)$row['start_s']) {
        $row['end_s'] = $inferred_end;
        $row['duration_s'] = $inferred_end - (float)$row['start_s'];
    }
}

// ── Scan slides/ directory ────────────────────────────────────────────────────
$slides_dir   = getResourcePath($instructor_id, $video_id, $chapter_dir . '/slides');
$slide_files  = [];
if (is_dir($slides_dir)) {
    $all = scandir($slides_dir);
    foreach ($all as $f) {
        if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $f)) {
            $slide_files[] = $f;
        }
    }
    sort($slide_files);
}

// Build browser-accessible slide URLs
$slide_urls = array_map(
    fn($f) => getResourceUrl($instructor_id, $video_id, $chapter_dir . '/slides/' . $f),
    $slide_files
);

// ── Video URL ─────────────────────────────────────────────────────────────────
$video_url = $row['video_filename']
    ? getVideoUrl($instructor_id, $video_id, $row['video_filename'])
    : '';
$transcript_vtt_path = getResourcePath($instructor_id, $video_id, 'transcript.vtt');
$transcript_vtt_url = file_exists($transcript_vtt_path)
    ? getVideoAssetUrl($instructor_id, $video_id, 'transcript.vtt')
    : '';

// ── Load previous responses for pre-fill ─────────────────────────────────────
$current_user_id = (int)$_SESSION['user_id'];
$current_seg_id  = (int)$row['segment_id'];

$stmt_fam = $pdo->prepare('SELECT answer FROM responses_familiarity WHERE user_id = ? AND segment_id = ?');
$stmt_fam->execute([$current_user_id, $current_seg_id]);
$prev_familiarity = $stmt_fam->fetchColumn() ?: '';

$stmt_rat = $pdo->prepare('SELECT dimension, version, rating FROM responses_ratings WHERE user_id = ? AND segment_id = ?');
$stmt_rat->execute([$current_user_id, $current_seg_id]);
$prev_ratings = [];
foreach ($stmt_rat->fetchAll() as $r) {
    $prev_ratings[$r['dimension']][$r['version']] = (int)$r['rating'];
}

$stmt_com = $pdo->prepare('SELECT dimension, comment_text FROM responses_comments WHERE user_id = ? AND segment_id = ?');
$stmt_com->execute([$current_user_id, $current_seg_id]);
$prev_comments = [];
foreach ($stmt_com->fetchAll() as $c) {
    $prev_comments[$c['dimension']] = $c['comment_text'];
}

$prev_selection_quality_rating = '';
$prev_include_important = [];
$prev_exclude_unimportant = [];
$prev_include_important_none = false;
$prev_exclude_unimportant_none = false;
try {
    $stmt_vis = $pdo->prepare('
        SELECT *
        FROM responses_visual_objects
        WHERE user_id = ? AND segment_id = ?
    ');
    $stmt_vis->execute([$current_user_id, $current_seg_id]);
    $prev_vis = $stmt_vis->fetch();
    if ($prev_vis) {
        $prev_selection_quality_rating = (string)($prev_vis['selection_quality_rating'] ?? '');
        $prev_include_important = json_decode(($prev_vis['include_important_labels'] ?? '') ?: '[]', true) ?: [];
        $prev_exclude_unimportant = json_decode(($prev_vis['exclude_unimportant_labels'] ?? '') ?: '[]', true) ?: [];
        $prev_include_important_none = !empty($prev_vis['include_important_none'] ?? 0);
        $prev_exclude_unimportant_none = !empty($prev_vis['exclude_unimportant_none'] ?? 0);
    }
} catch (Throwable $e) {
    // Older databases may not have the visual-object response table yet.
}

// ── JS-safe data ──────────────────────────────────────────────────────────────
function jsStr($s) {
    return json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}
function jsFloat($v) { return number_format((float)$v, 4, '.', ''); }

$js_slides           = json_encode($slide_urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$js_transcript_vtt   = jsStr($transcript_vtt);
$js_transcript_url   = jsStr($transcript_vtt_url);
$js_summary_a        = jsStr($summary_a_text);
$js_summary_b        = jsStr($summary_b_text);
$js_seg_start        = jsFloat($row['start_s']);
$js_seg_end          = jsFloat($row['end_s']);
$js_video_url        = jsStr($video_url);
$js_prev_familiarity = jsStr($prev_familiarity);
$js_prev_ratings     = json_encode($prev_ratings, JSON_UNESCAPED_UNICODE);
$js_prev_comments    = json_encode($prev_comments, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_selected_visuals = json_encode($selected_visuals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_unselected_visuals = json_encode($unselected_visuals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_prev_selection_quality_rating = jsStr($prev_selection_quality_rating);
$js_prev_include_important = json_encode($prev_include_important, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_prev_exclude_unimportant = json_encode($prev_exclude_unimportant, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_prev_include_important_none = $prev_include_important_none ? 'true' : 'false';
$js_prev_exclude_unimportant_none = $prev_exclude_unimportant_none ? 'true' : 'false';
$js_dimensions       = json_encode($study_dimensions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
$js_rating_scale     = json_encode([
    'min' => $scale_min,
    'max' => $scale_max,
], JSON_UNESCAPED_UNICODE);
$js_question_total   = (int)$question_total;

// ── Format helper ─────────────────────────────────────────────────────────────
function fmtTime($secs) {
    $s = (int)round($secs);
    return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}
$time_label = fmtTime($row['start_s']) . ' – ' . fmtTime($row['end_s']);
$dur_label  = fmtTime($row['duration_s']);
$slide_count = count($slide_files);
$slide_range = $row['slide_range_start'] . '–' . $row['slide_range_end'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chapter <?= e($row['chapter_num']) ?>: <?= e($row['segment_title']) ?></title>
<script src="https://cdn.jsdelivr.net/npm/marked@9/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff@5/dist/diff.min.js"></script>
<link rel="stylesheet" href="<?= assetUrl('assets/css/survey-viewer.css') ?>">
</head>
<body>

<header class="page-header">
  <h1>Chapter <?= e($row['chapter_num']) ?>: <?= e($row['segment_title']) ?></h1>
  <span class="subtitle">
    <?= e($row['course_code']) ?> &nbsp;·&nbsp;
    <?= e($time_label) ?> &nbsp;(<?= e($dur_label) ?>)
  </span>
  <span class="back-link"><a href="<?= e(baseUrl()) ?>">&larr; Back</a></span>
</header>

<main class="main">

<?php $flash = getFlash(); if ($flash): ?>
  <div class="flash <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>

<!-- ━━ 1. Video + Transcript ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="media-row">

  <div class="video-wrap">
    <div class="section-label">Lecture Video</div>
    <?php if ($video_url): ?>
    <video id="chapter-video" controls preload="metadata">
      <source src="<?= e($video_url) ?>" type="video/mp4">
      <?php if ($transcript_vtt_url): ?>
      <track kind="captions" src="<?= e($transcript_vtt_url) ?>" srclang="en" label="English">
      <?php endif; ?>
      Your browser does not support HTML5 video.
    </video>
    <?php else: ?>
    <div class="video-missing">
      Video file not configured.
    </div>
    <?php endif; ?>
    <div class="seg-timeline-wrap">
      <div class="seg-timeline" id="seg-timeline">
        <div class="seg-range"    id="seg-range"></div>
        <div class="seg-playhead" id="seg-playhead"></div>
      </div>
      <div class="seg-labels">
        <span id="seg-current-time">--:--</span>
        <span>Segment &nbsp;<?= e($time_label) ?> &nbsp;(<?= e($dur_label) ?>)</span>
      </div>
    </div>
    <div class="seg-controls">
      <button class="seg-play-btn" id="seg-play-btn" onclick="jumpToSegment()">&#9654; Play </button>
      <button class="seg-restrict-btn active" id="seg-restrict-btn" onclick="toggleRestrict()">&#x25A0; Single Chapter Only</button>
      <span class="seg-hint" id="seg-hint">Playback limited to <?= e($time_label) ?></span>
    </div>
    <div class="seg-notice" id="seg-notice" role="status" aria-live="polite" hidden>
      Playback is limited to this chapter. Turn off Single Chapter Only to play the full video.
    </div>
  </div>

  <div class="transcript-panel">
    <div class="panel-header">
      <span class="label">Transcript</span>
      <button class="toggle-btn" id="toggle-btn" onclick="toggleTranscript()" title="Hide transcript">&#8250;</button>
    </div>
    <div id="transcript-body">Loading&hellip;</div>
  </div>

</div>

<!-- ━━ 2. Slides ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<section class="slide-section" id="slide-section">
  <div class="section-label">
    Slides
    &nbsp;&middot;&nbsp; <?= $slide_count ?> Video Frames (Slide<?= $slide_count !== 1 ? 's' : '' ?>)
  </div>  
  <div class="slide-strip" id="slide-strip"></div>
  <div class="slide-footer" id="slide-footer"></div>
</section>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="lbBackdropClick(event)">
  <button class="lb-close" onclick="closeLightbox()" title="Close (Esc)">&#x2715;</button>
  <button class="lb-nav lb-prev" id="lb-prev" onclick="lbStep(-1)" title="Previous">&#8592;</button>
  <div class="lb-img-wrap"><img id="lightbox-img" src="" alt=""></div>
  <button class="lb-nav lb-next" id="lb-next" onclick="lbStep(1)"  title="Next">&#8594;</button>
  <div class="lb-counter" id="lb-counter"></div>
</div>

<!-- ━━ 3. Summary comparison ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<section class="comparison-section">
  <div class="comparison-toolbar">
    <button type="button" class="summary-collapse-btn" id="summary-collapse-btn" onclick="toggleSummaryComparison()" aria-expanded="true" aria-controls="summaries-row" title="Collapse summary comparison">
      <span id="summary-collapse-icon">&#9662;</span>
      <span class="section-label compact-label">Summary Comparison</span>
    </button>
    <div class="view-toggle-row">
      <button class="view-tab active" id="tab-normal" onclick="setView('normal')">Normal</button>
      <button class="view-tab"        id="tab-diff"   onclick="setView('diff')">Diff View</button>
    </div>
    <div class="diff-legend" id="diff-legend" hidden>
      <span><span class="legend-swatch legend-a"></span>Version A only</span>
      <span><span class="legend-swatch legend-b"></span>Version B only</span>
      <span class="legend-muted">Common text has no highlight</span>
    </div>
  </div>
  <div class="summaries-row" id="summaries-row">
    <div class="summary-card card-a">
      <div class="card-header">
        <h2>Version A</h2>
      </div>
      <div class="summary-body"><div class="md-content" id="summary-a"></div></div>
    </div>
    <div class="summary-card card-b">
      <div class="card-header">
        <h2>Version B</h2>
      </div>
      <div class="summary-body"><div class="md-content" id="summary-b"></div></div>
    </div>
  </div>
</section>

<!-- ━━ 4. Questionnaire ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<section class="questions-section">
  <div class="study-step-tabs" aria-label="Study parts">
    <button type="button" class="study-step-tab active" id="step-tab-text" onclick="showStudyStep('text')" aria-pressed="true">
      <span class="study-step-label">
        <span class="study-step-num">Part 1</span>
        <span>Text summary evaluation</span>
      </span>
      <span class="study-step-status" id="step-status-text" data-status="not_started">Not started · 0/<?= e($question_total) ?></span>
    </button>
    <button type="button" class="study-step-tab" id="step-tab-visual" onclick="showStudyStep('visual')" aria-pressed="false">
      <span class="study-step-label">
        <span class="study-step-num">Part 2</span>
        <span>Visual object selection</span>
      </span>
      <span class="study-step-status" id="step-status-visual" data-status="not_started">Not started · 0/3</span>
    </button>
  </div>

  <form id="qs-form" method="POST" action="<?= e(baseUrl('survey/submit.php')) ?>">
    <input type="hidden" name="segment_id" value="<?= e($row['segment_id']) ?>">
    <input type="hidden" name="visual_selection_quality" id="visual-selection-quality-val">
    <input type="hidden" name="visual_include_important_none" id="visual-include-important-none-val" value="0">
    <input type="hidden" name="visual_exclude_unimportant_none" id="visual-exclude-unimportant-none-val" value="0">

    <div class="survey-container study-step-panel" id="step-panel-text">
      <div class="section-label questions-label">Part 1 · Text Summary Evaluation</div>

      <!-- Q1: Familiarity -->
      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q1</span>
          <span class="qc-dim">Background</span>
          <span class="qc-text"><?= e($study['familiarity_question'] ?? 'How familiar are you with the topic covered in this video segment?') ?></span>
        </div>
        <div class="qc-body">
          <div class="choice-group">
            <?php foreach ($familiarity_options as $option): ?>
            <button type="button" class="choice-btn"
                    data-v="<?= e($option['id']) ?>"
                    onclick="selectFamiliarity(this)">
              <?= e($option['label']) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="familiarity" id="familiarity-val">
        </div>
      </div>

      <!-- Q2–Q5: One card per rating dimension -->
      <?php
      foreach ($study_dimensions as $i => $dim):
        $qn = $i + 2;
        $did = $dim['id'];
      ?>
      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q<?= $qn ?></span>
          <span class="qc-dim"><?= e($dim['label']) ?></span>
          <span class="qc-text"><?= e($dim['question']) ?></span>
          <span class="qc-scale-hint"><?= e($scale_hint) ?></span>
        </div>
        <div class="qc-body">
          <!-- Version A -->
          <div class="rating-row">
            <span class="version-pill a">Version A</span>
            <div class="scale-buttons" id="<?= e($did) ?>-a-btns"></div>
            <span class="rating-display" id="<?= e($did) ?>-a-display">not rated</span>
            <input type="hidden" name="rating[<?= e($did) ?>][A]" id="<?= e($did) ?>-a-val">
          </div>
          <!-- Version B -->
          <div class="rating-row">
            <span class="version-pill b">Version B</span>
            <div class="scale-buttons" id="<?= e($did) ?>-b-btns"></div>
            <span class="rating-display" id="<?= e($did) ?>-b-display">not rated</span>
            <input type="hidden" name="rating[<?= e($did) ?>][B]" id="<?= e($did) ?>-b-val">
          </div>
          <!-- Optional comment -->
          <textarea class="qc-comment" name="comment[<?= e($did) ?>]"
            placeholder="Optional comment on your <?= e(strtolower($dim['label'])) ?> ratings…"
            rows="2"></textarea>
        </div>
      </div>
      <?php endforeach; ?>

    </div><!-- #step-panel-text -->

    <div class="survey-container study-step-panel" id="step-panel-visual" hidden>
      <div class="visual-step-heading">
        <div class="section-label questions-label">Part 2 · Visual Object Selection</div>
        <label class="visual-size-control" for="visual-columns-slider">
          <span>Objects per row</span>
          <input type="range" id="visual-columns-slider" min="1" max="8" value="5" step="1">
          <output id="visual-columns-output" for="visual-columns-slider">5</output>
        </label>
      </div>

      <div class="visual-study-layout">
        <section class="visual-group selected">
          <div class="visual-group-header">
            <h2>Selected Visual Objects</h2>
            <span><?= count($selected_visuals) ?> item<?= count($selected_visuals) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="visual-object-grid">
            <?php if ($selected_visuals): ?>
              <?php foreach ($selected_visuals as $obj): ?>
              <figure class="visual-object-card">
                <div class="visual-object-image-wrap">
                  <img src="<?= e($obj['url']) ?>" alt="<?= e($obj['label']) ?>">
                </div>
                <figcaption>
                  <?= e($obj['label']) ?>
                  <span class="visual-label-mark selected" aria-hidden="true">&#10003;</span>
                </figcaption>
              </figure>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="visual-empty">No selected visual objects found.</p>
            <?php endif; ?>
          </div>
        </section>

        <section class="visual-group unselected">
          <div class="visual-group-header">
            <h2>Unselected Visual Objects</h2>
            <span><?= count($unselected_visuals) ?> item<?= count($unselected_visuals) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="visual-object-grid">
            <?php if ($unselected_visuals): ?>
              <?php foreach ($unselected_visuals as $obj): ?>
              <figure class="visual-object-card">
                <div class="visual-object-image-wrap">
                  <img src="<?= e($obj['url']) ?>" alt="<?= e($obj['label']) ?>">
                </div>
                <figcaption>
                  <?= e($obj['label']) ?>
                  <span class="visual-label-mark unselected" aria-hidden="true">&#10005;</span>
                </figcaption>
              </figure>
              <?php endforeach; ?>
            <?php else: ?>
              <p class="visual-empty">No unselected visual objects found.</p>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q1</span>
          <span class="qc-dim"><?= e($visual_questions['selection_quality']['label']) ?></span>
          <span class="qc-text"><?= e($visual_questions['selection_quality']['question']) ?></span>
          <span class="qc-scale-hint"><?= e($scale_hint) ?></span>
        </div>
        <div class="qc-body">
          <div class="rating-row visual-rating-row">
            <div class="scale-buttons" id="visual-rating-btns"></div>
            <span class="rating-display" id="visual-rating-display">not rated</span>
          </div>
        </div>
      </div>

      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q2</span>
          <span class="qc-dim"><?= e($visual_questions['include_important']['label']) ?></span>
          <span class="qc-text"><?= e($visual_questions['include_important']['question']) ?></span>
        </div>
        <div class="qc-body">
          <div class="visual-choice-group" id="visual-missing-options">
            <?php if ($unselected_visuals): ?>
              <?php foreach ($unselected_visuals as $obj): ?>
              <button type="button" class="visual-choice-btn include-important-choice" data-label="<?= e($obj['label']) ?>" data-target="include_important">
                <?= e($obj['label']) ?>
              </button>
              <?php endforeach; ?>
              <button type="button" class="visual-choice-btn none-choice" data-target="include_important_none">None</button>
            <?php else: ?>
              <button type="button" class="visual-choice-btn none-choice" data-target="include_important_none">None</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q3</span>
          <span class="qc-dim"><?= e($visual_questions['exclude_unimportant']['label']) ?></span>
          <span class="qc-text"><?= e($visual_questions['exclude_unimportant']['question']) ?></span>
        </div>
        <div class="qc-body">
          <div class="visual-choice-group" id="visual-unwanted-options">
            <?php if ($selected_visuals): ?>
              <?php foreach ($selected_visuals as $obj): ?>
              <button type="button" class="visual-choice-btn exclude-unimportant-choice selected" data-label="<?= e($obj['label']) ?>" data-target="exclude_unimportant">
                <?= e($obj['label']) ?>
              </button>
              <?php endforeach; ?>
              <button type="button" class="visual-choice-btn none-choice" data-target="exclude_unimportant_none">None</button>
            <?php else: ?>
              <button type="button" class="visual-choice-btn none-choice" data-target="exclude_unimportant_none">None</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div><!-- #step-panel-visual -->

    <input type="hidden" name="action" id="form-action" value="submit">
    <div class="survey-submit">
      <span class="survey-progress" id="survey-progress">Part 1: 0/<?= e($question_total) ?> required · Part 2: 0/3 required · Total: 0/<?= e($question_total + 3) ?></span>
      <button type="button" class="save-later-btn" id="save-later-btn" onclick="saveLater()" disabled>Save &amp; Finish Later</button>
      <button type="button" class="step-back-btn" id="step-back-btn" onclick="showStudyStep('text')" hidden>Back to Part 1</button>
      <button type="button" class="survey-submit-btn" id="step-next-btn" onclick="showStudyStep('visual')">Next: Visual Objects →</button>
      <button type="submit" class="survey-submit-btn" id="submit-btn" hidden>Submit Ratings →</button>
    </div>
  </form>
</section>

</main>

<script>
window.SURVEY_VIEWER_DATA = {
  slides: <?= $js_slides ?>,
  transcriptVtt: <?= $js_transcript_vtt ?>,
  transcriptUrl: <?= $js_transcript_url ?>,
  summaryA: <?= $js_summary_a ?>,
  summaryB: <?= $js_summary_b ?>,
  segStart: <?= $js_seg_start ?>,
  segEnd: <?= $js_seg_end ?>,
  prevFamiliarity: <?= $js_prev_familiarity ?>,
  prevRatings: <?= $js_prev_ratings ?>,
  prevComments: <?= $js_prev_comments ?>,
  selectedVisuals: <?= $js_selected_visuals ?>,
  unselectedVisuals: <?= $js_unselected_visuals ?>,
  prevSelectionQualityRating: <?= $js_prev_selection_quality_rating ?>,
  prevIncludeImportant: <?= $js_prev_include_important ?>,
  prevExcludeUnimportant: <?= $js_prev_exclude_unimportant ?>,
  prevIncludeImportantNone: <?= $js_prev_include_important_none ?>,
  prevExcludeUnimportantNone: <?= $js_prev_exclude_unimportant_none ?>,
  dimensions: <?= $js_dimensions ?>,
  ratingScale: <?= $js_rating_scale ?>,
  questionTotal: <?= $js_question_total ?>,
  timeLabel: <?= jsStr($time_label) ?>
};
</script>
<script src="<?= assetUrl('assets/js/survey-viewer.js') ?>"></script>

</body>
</html>
