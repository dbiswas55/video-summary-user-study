<?php
/**
 * Segment Viewer
 * --------------
 * Dynamically renders the comparative study page for a single video segment.
 *
 * Route: /survey/viewer.php?vid={video_id}
 *   video_id = the real integer video ID (e.g. 9230)
 *
 * Reads from:
 *   DB      — video + segment + course metadata
 *   Disk    — transcript.txt, transcript_summary.txt, multimodal_summary.txt
 *   Disk    — slides/ directory listing
 */

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/auth.php';

// ── Authentication ────────────────────────────────────────────────────────────
requireLogin();

$pdo = getDb();

// ── Resolve video from ?vid= ──────────────────────────────────────────────────
$raw_vid = $_GET['vid'] ?? '';
if (!ctype_digit((string)$raw_vid)) {
    http_response_code(400);
    die('Missing or invalid video ID.');
}
$req_video_id = (int)$raw_vid;

// ── Load video + segment + course ────────────────────────────────────────────
$stmt = $pdo->prepare('
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
    LIMIT 1
');
$stmt->execute([$req_video_id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    die('Video not found.');
}

$instructor_id = (int)$row['instructor_id'];
$video_id      = (int)$row['video_id'];

// ── Read text files from disk ─────────────────────────────────────────────────
function readResource($instructor_id, $video_id, $filename) {
    $path = getResourcePath($instructor_id, $video_id, $filename);
    return file_exists($path) ? file_get_contents($path) : '';
}

$transcript     = readResource($instructor_id, $video_id, 'transcript.txt');
$summary_a_text = readResource($instructor_id, $video_id, $row['summary_a_file']);
$summary_b_text = readResource($instructor_id, $video_id, $row['summary_b_file']);

// ── Scan slides/ directory ────────────────────────────────────────────────────
$slides_dir   = getResourcePath($instructor_id, $video_id, 'slides');
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
    fn($f) => getResourceUrl($instructor_id, $video_id, 'slides/' . $f),
    $slide_files
);

// ── Video URL ─────────────────────────────────────────────────────────────────
$video_url = $row['video_filename']
    ? getVideoUrl($instructor_id, $video_id, $row['video_filename'])
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

// ── JS-safe data ──────────────────────────────────────────────────────────────
function jsStr($s) {
    return json_encode((string)$s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
}
function jsFloat($v) { return number_format((float)$v, 4, '.', ''); }

$js_slides           = json_encode($slide_urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$js_transcript       = jsStr($transcript);
$js_summary_a        = jsStr($summary_a_text);
$js_summary_b        = jsStr($summary_b_text);
$js_seg_start        = jsFloat($row['start_s']);
$js_seg_end          = jsFloat($row['end_s']);
$js_video_url        = jsStr($video_url);
$js_prev_familiarity = jsStr($prev_familiarity);
$js_prev_ratings     = json_encode($prev_ratings, JSON_UNESCAPED_UNICODE);
$js_prev_comments    = json_encode($prev_comments, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

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
      <button class="seg-play-btn" id="seg-play-btn" onclick="jumpToSegment()">&#9654; Play Segment</button>
      <button class="seg-restrict-btn active" id="seg-restrict-btn" onclick="toggleRestrict()">&#x25A0; Segment only</button>
      <span class="seg-hint" id="seg-hint">Restricted to <?= e($time_label) ?></span>
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
    Slides &nbsp;&middot;&nbsp; indices <?= e($slide_range) ?>
    &nbsp;&middot;&nbsp; <?= $slide_count ?> image<?= $slide_count !== 1 ? 's' : '' ?>
    &nbsp;<span class="slide-help">— scroll to browse &middot; click to zoom</span>
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
<div>
  <div class="comparison-toolbar">
    <div class="section-label compact-label">Summary Comparison</div>
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
  <div class="summaries-row">
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
</div>

<!-- ━━ 4. Questionnaire ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div>
  <div class="section-label questions-label">Evaluation Questions</div>

  <form id="qs-form" method="POST" action="<?= e(baseUrl('survey/submit.php')) ?>">
    <input type="hidden" name="segment_id" value="<?= e($row['segment_id']) ?>">

    <div class="survey-container">

      <!-- Q1: Familiarity -->
      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q1</span>
          <span class="qc-dim">Background</span>
          <span class="qc-text">How familiar are you with the topic covered in this video segment?</span>
        </div>
        <div class="qc-body">
          <div class="choice-group">
            <?php foreach ([
              'not_familiar'  => 'Not familiar at all',
              'somewhat'      => 'Somewhat familiar',
              'familiar'      => 'Familiar',
              'very_familiar' => 'Very familiar',
            ] as $val => $label): ?>
            <button type="button" class="choice-btn"
                    data-v="<?= e($val) ?>"
                    onclick="selectFamiliarity(this)">
              <?= e($label) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="familiarity" id="familiarity-val">
        </div>
      </div>

      <!-- Q2–Q5: One card per rating dimension -->
      <?php
      $dims = [
        ['id'=>'faithfulness','label'=>'Faithfulness',
         'q'=>'How well does this summary reflect what was actually covered in the video segment?'],
        ['id'=>'completeness','label'=>'Completeness',
         'q'=>'How completely does this summary capture the key concepts from the video segment?'],
        ['id'=>'coherence',   'label'=>'Coherence',
         'q'=>'How well does this summary present its ideas in a clear, logical, and organized way?'],
        ['id'=>'usefulness',  'label'=>'Usefulness',
         'q'=>'How useful is this summary as review material for this video segment?'],
      ];
      foreach ($dims as $i => $dim):
        $qn = $i + 2;
        $did = $dim['id'];
      ?>
      <div class="question-card">
        <div class="qc-header">
          <span class="qc-num">Q<?= $qn ?></span>
          <span class="qc-dim"><?= e($dim['label']) ?></span>
          <span class="qc-text"><?= e($dim['q']) ?></span>
          <span class="qc-scale-hint"><b>1</b> not at all &middot; <b>10</b> extremely</span>
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

    </div><!-- .survey-container -->

    <input type="hidden" name="action" id="form-action" value="submit">
    <div class="survey-submit">
      <span class="survey-progress" id="survey-progress">0 of 9 questions answered</span>
      <button type="button" class="save-later-btn" id="save-later-btn" onclick="saveLater()" disabled>Save &amp; Finish Later</button>
      <button type="submit" class="survey-submit-btn" id="submit-btn">Submit Ratings →</button>
    </div>
  </form>
</div>

</main>

<script>
window.SURVEY_VIEWER_DATA = {
  slides: <?= $js_slides ?>,
  transcript: <?= $js_transcript ?>,
  summaryA: <?= $js_summary_a ?>,
  summaryB: <?= $js_summary_b ?>,
  segStart: <?= $js_seg_start ?>,
  segEnd: <?= $js_seg_end ?>,
  prevFamiliarity: <?= $js_prev_familiarity ?>,
  prevRatings: <?= $js_prev_ratings ?>,
  prevComments: <?= $js_prev_comments ?>,
  timeLabel: <?= jsStr($time_label) ?>
};
</script>
<script src="<?= assetUrl('assets/js/survey-viewer.js') ?>"></script>

</body>
</html>
