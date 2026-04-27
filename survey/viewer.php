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
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
  background: #f0f0f5;
  color: #1d1d1f;
  font-size: 15px;
  line-height: 1.6;
}

/* ── Page header ── */
.page-header {
  background: #1d1d1f;
  color: #f5f5f7;
  padding: 14px 28px;
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 10px 20px;
}
.page-header h1  { font-size: 1.05rem; font-weight: 700; }
.page-header .subtitle { font-size: 0.82rem; color: #a1a1a6; flex: 1; }
.back-link { font-size: 0.82rem; color: #636366; white-space: nowrap; }
.back-link a { color: #a1a1a6; text-decoration: none; }
.back-link a:hover { color: #f5f5f7; }

/* ── Main layout ── */
.main { max-width: 1440px; margin: 0 auto; padding: 22px 20px; display: flex; flex-direction: column; gap: 20px; }

/* ── Section label ── */
.section-label {
  font-size: 0.74rem;
  font-weight: 700;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: #6e6e73;
  margin-bottom: 8px;
}

/* ── Media row ── */
.media-row { display: flex; gap: 16px; align-items: flex-start; }
.video-wrap { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; }

/* Video column heading — same height as transcript panel-header */
.video-wrap .section-label {
  display: flex;
  align-items: center;
  min-height: 42px;
  padding: 8px 0;
  margin-bottom: 0;
  border-bottom: 1px solid transparent;
}

.video-wrap video {
  width: 100%;
  border-radius: 10px;
  background: #000;
  box-shadow: 0 4px 20px rgba(0,0,0,.22);
  display: block;
}

/* ── Transcript panel ── */
.transcript-panel {
  flex: 0 0 420px;
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 2px 10px rgba(0,0,0,.08);
  display: flex;
  flex-direction: column;
}
.transcript-panel .panel-header {
  display: flex;
  align-items: center;
  min-height: 42px;
  padding: 8px 14px;
  border-bottom: 1px solid #e5e5ea;
  gap: 8px;
  flex-shrink: 0;
}
.panel-header .label { font-size: 0.82rem; font-weight: 700; flex: 1; color: #3c3c43; }
.toggle-btn {
  flex-shrink: 0;
  width: 28px; height: 28px;
  border-radius: 6px;
  border: 1px solid #c7c7cc;
  background: #f5f5f7;
  cursor: pointer;
  color: #3c3c43;
  font-size: 1rem;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s, border-color .15s;
  padding: 0;
}
.toggle-btn:hover { background: #e5e5ea; border-color: #aeaeb2; }
#transcript-body {
  overflow-y: auto;
  padding: 12px 14px;
  font-size: 0.8rem;
  line-height: 1.75;
  color: #3c3c43;
  white-space: pre-wrap;
}

/* ── Segment timeline ── */
.seg-timeline-wrap { margin-top: 10px; }
.seg-timeline {
  position: relative;
  height: 8px;
  background: #d1d1d6;
  border-radius: 4px;
  cursor: pointer;
}
.seg-range {
  position: absolute;
  height: 100%;
  background: #0071e3;
  opacity: .5;
  border-radius: 4px;
}
.seg-playhead {
  position: absolute;
  top: -3px;
  width: 3px;
  height: calc(100% + 6px);
  background: #ff3b30;
  border-radius: 2px;
  pointer-events: none;
}
.seg-labels {
  display: flex;
  justify-content: space-between;
  font-size: 0.72rem;
  color: #6e6e73;
  margin-top: 5px;
}
.seg-controls { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
.seg-play-btn {
  background: #0071e3;
  color: #fff;
  border: none;
  border-radius: 20px;
  padding: 7px 18px;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .15s;
}
.seg-play-btn:hover { background: #005bb5; }
.seg-play-btn.pausing { background: #ff9500; }
.seg-restrict-btn {
  font-size: 0.75rem;
  padding: 5px 13px;
  border-radius: 14px;
  border: 1.5px solid #c7c7cc;
  background: #f5f5f7;
  cursor: pointer;
  color: #3c3c43;
  transition: background .15s, border-color .15s, color .15s;
  white-space: nowrap;
}
.seg-restrict-btn.active { background: #0071e3; border-color: #0071e3; color: #fff; }
.seg-hint { font-size: 0.75rem; color: #6e6e73; }

/* ── Slide strip ── */
.slide-section {
  background: #fff;
  border-radius: 10px;
  padding: 16px 20px;
  box-shadow: 0 2px 10px rgba(0,0,0,.08);
}
.slide-strip {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  padding-bottom: 8px;
  margin-top: 8px;
  scroll-snap-type: x mandatory;
  scroll-behavior: smooth;
  scrollbar-width: thin;
  scrollbar-color: #c7c7cc #f5f5f7;
}
.slide-strip::-webkit-scrollbar { height: 6px; }
.slide-strip::-webkit-scrollbar-track { background: #f5f5f7; border-radius: 3px; }
.slide-strip::-webkit-scrollbar-thumb { background: #c7c7cc; border-radius: 3px; }
.slide-strip img {
  flex: 0 0 calc((100% - 10px) / 2.4);
  width: calc((100% - 10px) / 2.4);
  height: auto;
  border-radius: 6px;
  border: 2px solid #e5e5ea;
  background: #fafafa;
  object-fit: contain;
  scroll-snap-align: start;
  cursor: zoom-in;
  transition: border-color .15s, transform .15s;
}
.slide-strip img:hover { border-color: #0071e3; transform: scale(1.01); }
.slide-footer { margin-top: 8px; font-size: 0.79rem; color: #6e6e73; }

/* ── Lightbox ── */
.lightbox {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.86);
  z-index: 1000;
  align-items: center;
  justify-content: center;
}
.lightbox.open { display: flex; }
.lb-img-wrap { position: relative; display: flex; align-items: center; justify-content: center; max-width: 88vw; max-height: 90vh; }
.lightbox img { max-width: 88vw; max-height: 88vh; border-radius: 8px; box-shadow: 0 8px 40px rgba(0,0,0,.5); display: block; }
.lb-close {
  position: fixed; top: 18px; right: 22px;
  background: rgba(255,255,255,.15); border: none; color: #fff;
  font-size: 1.4rem; width: 38px; height: 38px; border-radius: 50%;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background .15s; z-index: 1001;
}
.lb-close:hover { background: rgba(255,255,255,.28); }
.lb-nav {
  position: fixed; top: 50%; transform: translateY(-50%);
  background: rgba(255,255,255,.15); border: none; color: #fff;
  font-size: 1.5rem; width: 48px; height: 64px; border-radius: 8px;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background .15s; z-index: 1001;
}
.lb-nav:hover { background: rgba(255,255,255,.28); }
.lb-nav:disabled { opacity: .2; cursor: default; }
.lb-prev { left: 14px; } .lb-next { right: 14px; }
.lb-counter { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); color: rgba(255,255,255,.7); font-size: 0.82rem; pointer-events: none; z-index: 1001; }

/* ── Summaries ── */
.summaries-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; align-items: start; }
.summary-card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.08); overflow: hidden; }
.summary-card .card-header { padding: 13px 18px 11px; border-bottom: 1px solid #e5e5ea; }
.summary-card .card-header h2 { font-size: 0.92rem; font-weight: 700; }
.summary-card .card-header p  { font-size: 0.75rem; color: #6e6e73; margin-top: 2px; }
.summary-body { padding: 16px 18px; }
.card-a .card-header { border-top: 3px solid #0071e3; }
.card-b .card-header { border-top: 3px solid #30d158; }

/* ── Markdown ── */
.md-content h1, .md-content h2, .md-content h3 { margin: 1em 0 .35em; font-weight: 700; line-height: 1.3; }
.md-content h1 { font-size: 1.1rem; }
.md-content h2 { font-size: 0.97rem; }
.md-content h3 { font-size: 0.9rem; color: #3c3c43; }
.md-content p  { margin-bottom: .65em; font-size: 0.88rem; }
.md-content ul, .md-content ol { padding-left: 1.3em; margin-bottom: .65em; font-size: 0.88rem; }
.md-content li { margin-bottom: .25em; }
.md-content strong { font-weight: 700; }
.md-content em { font-style: italic; color: #3c3c43; }
.md-content code { background: #f5f5f7; padding: 1px 5px; border-radius: 4px; font-size: 0.85em; font-family: "SF Mono","Fira Code",monospace; }
.md-content blockquote { border-left: 3px solid #d1d1d6; padding-left: 12px; color: #6e6e73; margin: .5em 0; font-size: 0.87rem; }
.md-content hr { border: none; border-top: 1px solid #e5e5ea; margin: 1em 0; }

/* ── Diff ── */
.view-toggle-row { display: flex; background: #e5e5ea; border-radius: 8px; padding: 3px; gap: 2px; }
.view-tab { font-size: 0.78rem; font-weight: 600; padding: 5px 14px; border: none; border-radius: 6px; background: transparent; color: #3c3c43; cursor: pointer; transition: background .15s, color .15s; white-space: nowrap; }
.view-tab.active { background: #fff; color: #0071e3; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
.diff-legend { display: flex; align-items: center; gap: 12px; font-size: 0.75rem; color: #6e6e73; }
.legend-swatch { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: 4px; vertical-align: middle; }
mark.diff-a { background: #fff3d0; border-radius: 2px; padding: 0 1px; color: inherit; font-style: normal; }
mark.diff-b { background: #d4f5d4; border-radius: 2px; padding: 0 1px; color: inherit; font-style: normal; }

/* ── Questionnaire cards ── */
.survey-container { display: flex; flex-direction: column; gap: 14px; }

.question-card {
  background: #fff; border-radius: 14px; border: 1px solid #e5e5ea;
  box-shadow: 0 2px 12px rgba(0,0,0,.06); overflow: hidden; transition: box-shadow .2s;
}
.question-card:hover { box-shadow: 0 4px 24px rgba(0,0,0,.1); }

.qc-header {
  padding: 16px 22px; border-bottom: 1px solid #e5e5ea;
  display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.qc-num {
  font-size: 0.9rem; font-weight: 700; color: #6e6e73; white-space: nowrap; flex-shrink: 0;
}
.qc-dim {
  font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
  color: #0071e3; background: #e8f0fb; padding: 4px 10px; border-radius: 100px; white-space: nowrap;
}
.qc-text {
  font-size: 0.95rem; font-weight: 500; line-height: 1.45; flex: 1; min-width: 180px;
}
.qc-scale-hint {
  font-size: 11px; color: #6e6e73; background: #f5f5f7; padding: 4px 10px;
  border-radius: 100px; white-space: nowrap; flex-shrink: 0;
}
.qc-scale-hint b { color: #1d1d1f; font-weight: 600; }

.qc-body { padding: 16px 22px; display: flex; flex-direction: column; gap: 12px; }

/* Familiarity choice buttons */
.choice-group { display: flex; gap: 10px; flex-wrap: wrap; }
.choice-btn {
  padding: 9px 20px; border-radius: 100px; border: 1.5px solid #e5e5ea;
  background: #f5f5f7; font-family: inherit; font-size: 0.88rem; font-weight: 500;
  color: #6e6e73; cursor: pointer; transition: all .15s;
}
.choice-btn:hover { border-color: #aeaeb2; color: #1d1d1f; background: #fff; }
.choice-btn.selected { background: #1d1d1f; border-color: #1d1d1f; color: #fff; font-weight: 600; }

/* Rating rows */
.rating-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.version-pill {
  font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
  padding: 5px 14px; border-radius: 100px; min-width: 90px; text-align: center; flex-shrink: 0;
}
.version-pill.a { background: #e8f0fb; color: #0071e3; }
.version-pill.b { background: #fdf0ec; color: #b5451b; }

.scale-buttons { display: flex; gap: 5px; flex: 1; justify-content: center; flex-wrap: wrap; }
.scale-btn {
  width: 38px; height: 38px; border-radius: 8px; border: 1.5px solid #e5e5ea;
  background: #f5f5f7; font-size: 0.85rem; font-weight: 500; color: #6e6e73;
  cursor: pointer; transition: all .15s; font-family: inherit;
  display: flex; align-items: center; justify-content: center;
}
.scale-btn:hover { border-color: #aeaeb2; color: #1d1d1f; background: #fff; transform: translateY(-1px); }
.scale-btn.selected-a { background: #0071e3; border-color: #0071e3; color: #fff; font-weight: 600; box-shadow: 0 3px 10px rgba(0,113,227,.3); }
.scale-btn.selected-b { background: #b5451b; border-color: #b5451b; color: #fff; font-weight: 600; box-shadow: 0 3px 10px rgba(181,69,27,.3); }

.rating-display {
  font-size: 0.78rem; color: #6e6e73; font-style: italic; min-width: 58px; text-align: right; flex-shrink: 0;
}
.rating-display.has-a { color: #0071e3; font-style: normal; font-weight: 600; }
.rating-display.has-b { color: #b5451b; font-style: normal; font-weight: 600; }

.qc-comment {
  width: 100%; min-height: 52px; border: 1.5px solid #e5e5ea; border-radius: 10px;
  padding: 9px 13px; font-family: inherit; font-size: 0.85rem; color: #1d1d1f;
  background: #f5f5f7; resize: vertical; outline: none; transition: border-color .2s; line-height: 1.5;
}
.qc-comment::placeholder { color: #b5b1aa; }
.qc-comment:focus { border-color: #0071e3; background: #fff; }

/* Submit area */
.survey-submit {
  display: flex; justify-content: flex-end; align-items: center; gap: 16px;
  padding: 16px 0 4px;
}
.survey-progress { font-size: 0.85rem; color: #6e6e73; }
.survey-submit-btn {
  background: #1d1d1f; color: #fff; border: none; border-radius: 10px;
  padding: 12px 28px; font-family: inherit; font-size: 0.92rem; font-weight: 600;
  cursor: pointer; transition: all .2s;
}
.survey-submit-btn:hover { background: #0071e3; transform: translateY(-1px); box-shadow: 0 6px 18px rgba(0,113,227,.25); }
.save-later-btn {
  background: transparent; color: #6e6e73; border: 1.5px solid #c7c7cc; border-radius: 10px;
  padding: 11px 22px; font-family: inherit; font-size: 0.88rem; font-weight: 500;
  cursor: pointer; transition: all .2s;
}
.save-later-btn:hover:not(:disabled) { border-color: #aeaeb2; color: #3c3c43; background: #f5f5f7; }
.save-later-btn:disabled { opacity: 0.38; cursor: not-allowed; }

/* ── Flash messages ── */
.flash { border-radius: 8px; padding: 12px 16px; font-size: 0.85rem; margin-bottom: 16px; }
.flash.success { background: #d4f5d4; color: #1a5c1a; }
.flash.error   { background: #fde8e8; color: #7c1414; }

/* ── Responsive ── */
@media (max-width: 960px) {
  .media-row { flex-direction: column; }
  .video-wrap { display: block; }
  .video-wrap .section-label { padding: 0; margin-bottom: 8px; border-bottom: none; display: block; }
  .transcript-panel { flex: unset; width: 100%; }
  #transcript-body { height: 280px !important; }
  .summaries-row { grid-template-columns: 1fr; }
}
</style>
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
    <div style="background:#1d1d1f;border-radius:10px;padding:40px;text-align:center;color:#6e6e73;">
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
    &nbsp;<span style="font-weight:400;color:#aeaeb2;">— scroll to browse &middot; click to zoom</span>
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
  <div style="display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
    <div class="section-label" style="margin-bottom:0;">Summary Comparison</div>
    <div class="view-toggle-row">
      <button class="view-tab active" id="tab-normal" onclick="setView('normal')">Normal</button>
      <button class="view-tab"        id="tab-diff"   onclick="setView('diff')">Diff View</button>
    </div>
    <div class="diff-legend" id="diff-legend" style="display:none;">
      <span><span class="legend-swatch" style="background:#fff3d0;border:1px solid #f5c842;"></span>Version A only</span>
      <span><span class="legend-swatch" style="background:#d4f5d4;border:1px solid #34c759;"></span>Version B only</span>
      <span style="color:#aeaeb2;">Common text has no highlight</span>
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
  <div class="section-label" style="margin-bottom:12px;">Evaluation Questions</div>

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
// ── Data injected from PHP ─────────────────────────────────────────────────────
const SLIDES             = <?= $js_slides ?>;
const TRANSCRIPT         = <?= $js_transcript ?>;
const SUMMARY_A          = <?= $js_summary_a ?>;
const SUMMARY_B          = <?= $js_summary_b ?>;
const SEG_START          = <?= $js_seg_start ?>;
const SEG_END            = <?= $js_seg_end ?>;
const PREV_FAMILIARITY   = <?= $js_prev_familiarity ?>;
const PREV_RATINGS       = <?= $js_prev_ratings ?>;
const PREV_COMMENTS      = <?= $js_prev_comments ?>;

// ── Transcript ────────────────────────────────────────────────────────────────
document.getElementById('transcript-body').textContent = TRANSCRIPT || '(no transcript)';

function syncTranscriptHeight() {
  const vid  = document.getElementById('chapter-video');
  const body = document.getElementById('transcript-body');
  if (!vid || !body) return;
  const h = vid.getBoundingClientRect().height;
  if (h > 60) body.style.height = h + 'px';
}

let transcriptVisible = true;
function toggleTranscript() {
  const body = document.getElementById('transcript-body');
  const btn  = document.getElementById('toggle-btn');
  transcriptVisible = !transcriptVisible;
  body.style.display = transcriptVisible ? '' : 'none';
  btn.innerHTML = transcriptVisible ? '&#8250;' : '&#8249;';
  btn.title     = transcriptVisible ? 'Hide transcript' : 'Show transcript';
}

// ── Video player ──────────────────────────────────────────────────────────────
const video    = document.getElementById('chapter-video');
const playhead = document.getElementById('seg-playhead');
const rangeEl  = document.getElementById('seg-range');
const timeEl   = document.getElementById('seg-current-time');
let segmentRestricted = true;

function fmtTime(s) {
  const m = Math.floor(s / 60), sec = Math.floor(s % 60);
  return String(m).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
}

if (video) {
  video.addEventListener('loadedmetadata', () => {
    const dur = video.duration;
    rangeEl.style.left  = (SEG_START / dur * 100).toFixed(2) + '%';
    rangeEl.style.width = ((SEG_END - SEG_START) / dur * 100).toFixed(2) + '%';
    video.currentTime = SEG_START;
    updatePlayhead();
  });
  video.addEventListener('timeupdate', () => {
    updatePlayhead();
    if (!segmentRestricted) return;
    if (!video.paused && video.currentTime >= SEG_END) {
      video.pause(); video.currentTime = SEG_END;
    } else if (!video.paused && video.currentTime < SEG_START) {
      video.currentTime = SEG_START;
    }
  });
  video.addEventListener('seeked', () => {
    if (!segmentRestricted) return;
    if (video.currentTime < SEG_START) video.currentTime = SEG_START;
    if (video.currentTime > SEG_END)   video.currentTime = SEG_END;
  });
  video.addEventListener('play',  () => { document.getElementById('seg-play-btn').textContent = '⏸ Pause'; document.getElementById('seg-play-btn').classList.add('pausing'); });
  video.addEventListener('pause', () => { document.getElementById('seg-play-btn').textContent = '▶ Play Segment'; document.getElementById('seg-play-btn').classList.remove('pausing'); });
  video.addEventListener('loadedmetadata', () => setTimeout(syncTranscriptHeight, 50));
}
window.addEventListener('resize', syncTranscriptHeight);
setTimeout(syncTranscriptHeight, 400);

function updatePlayhead() {
  if (!video) return;
  const dur = video.duration || 1;
  playhead.style.left = (video.currentTime / dur * 100).toFixed(2) + '%';
  timeEl.textContent  = fmtTime(video.currentTime);
}

function toggleRestrict() {
  segmentRestricted = !segmentRestricted;
  const btn  = document.getElementById('seg-restrict-btn');
  const hint = document.getElementById('seg-hint');
  if (segmentRestricted) {
    btn.classList.add('active'); btn.textContent = '■ Segment only';
    hint.textContent = 'Restricted to <?= e($time_label) ?>';
    if (video && (video.currentTime < SEG_START || video.currentTime > SEG_END)) {
      video.pause(); video.currentTime = SEG_START;
    }
  } else {
    btn.classList.remove('active'); btn.textContent = '□ Free play';
    hint.textContent = 'Full video unlocked';
  }
}

function jumpToSegment() {
  if (!video) return;
  if (!video.paused) { video.pause(); }
  else { video.currentTime = SEG_START; video.play().catch(() => {}); }
}

document.getElementById('seg-timeline').addEventListener('click', (e) => {
  if (!video) return;
  const rect = e.currentTarget.getBoundingClientRect();
  video.currentTime = (e.clientX - rect.left) / rect.width * (video.duration || 1);
});

// ── Slides ────────────────────────────────────────────────────────────────────
const stripEl = document.getElementById('slide-strip');
if (SLIDES.length > 0) {
  SLIDES.forEach((src, i) => {
    const img = document.createElement('img');
    img.src   = src;
    img.alt   = 'Slide ' + (i + 1);
    img.title = 'Slide ' + (i + 1) + ' — click to zoom';
    img.addEventListener('click', () => openLightbox(i));
    stripEl.appendChild(img);
  });
  document.getElementById('slide-footer').textContent =
    SLIDES.length + ' slide' + (SLIDES.length !== 1 ? 's' : '') +
    '  ·  scroll horizontally to browse  ·  click to zoom';
} else {
  document.getElementById('slide-section').innerHTML =
    '<p style="color:#6e6e73;padding:16px 20px;">No slide images found.</p>';
}

// ── Lightbox ──────────────────────────────────────────────────────────────────
let lbIndex = 0;
function openLightbox(i) { lbIndex = i; _lbRender(); document.getElementById('lightbox').classList.add('open'); }
function _lbRender() {
  document.getElementById('lightbox-img').src = SLIDES[lbIndex];
  document.getElementById('lightbox-img').alt = 'Slide ' + (lbIndex + 1);
  document.getElementById('lb-counter').textContent = (lbIndex + 1) + ' / ' + SLIDES.length;
  document.getElementById('lb-prev').disabled = (lbIndex === 0);
  document.getElementById('lb-next').disabled = (lbIndex === SLIDES.length - 1);
}
function lbStep(d) { lbIndex = Math.max(0, Math.min(SLIDES.length - 1, lbIndex + d)); _lbRender(); }
function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); document.getElementById('lightbox-img').src = ''; }
function lbBackdropClick(e) { if (e.target === document.getElementById('lightbox')) closeLightbox(); }
document.addEventListener('keydown', e => {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'Escape') closeLightbox();
  if (e.key === 'ArrowLeft')  lbStep(-1);
  if (e.key === 'ArrowRight') lbStep(1);
});

// ── Summary rendering ─────────────────────────────────────────────────────────
marked.setOptions({ breaks: true, gfm: true });
function renderNormal() {
  document.getElementById('summary-a').innerHTML = marked.parse(SUMMARY_A || '*(no content)*');
  document.getElementById('summary-b').innerHTML = marked.parse(SUMMARY_B || '*(no content)*');
}
function renderDiff() {
  const changes = Diff.diffWords(SUMMARY_A || '', SUMMARY_B || '');
  let mdA = '', mdB = '';
  changes.forEach(c => {
    if (!c.added)   mdA += c.removed ? '<mark class="diff-a">' + c.value + '</mark>' : c.value;
    if (!c.removed) mdB += c.added   ? '<mark class="diff-b">' + c.value + '</mark>' : c.value;
  });
  document.getElementById('summary-a').innerHTML = marked.parse(mdA || '*(no content)*');
  document.getElementById('summary-b').innerHTML = marked.parse(mdB || '*(no content)*');
}
function setView(mode) {
  document.getElementById('tab-normal').classList.toggle('active', mode === 'normal');
  document.getElementById('tab-diff').classList.toggle('active',   mode === 'diff');
  document.getElementById('diff-legend').style.display = mode === 'diff' ? '' : 'none';
  if (mode === 'diff') renderDiff(); else renderNormal();
}
renderNormal();

// ── Questionnaire ─────────────────────────────────────────────────────────────
const DIMS = ['faithfulness','completeness','coherence','usefulness'];
const ratings = {};
let familiaritySelected = false;

// Build 1–10 scale buttons for each dimension × version
DIMS.forEach(dim => {
  ['a','b'].forEach(ver => {
    const container = document.getElementById(dim + '-' + ver + '-btns');
    for (let i = 1; i <= 10; i++) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'scale-btn';
      btn.textContent = i;
      btn.addEventListener('click', () => selectRating(dim, ver, i, btn));
      container.appendChild(btn);
    }
  });
});

function selectRating(dim, ver, value, clickedBtn) {
  const key = dim + '-' + ver;
  ratings[key] = value;
  // Update button highlight
  const container = document.getElementById(dim + '-' + ver + '-btns');
  container.querySelectorAll('.scale-btn').forEach(b => {
    b.classList.remove('selected-a', 'selected-b');
    if (parseInt(b.textContent) === value) {
      b.classList.add(ver === 'a' ? 'selected-a' : 'selected-b');
    }
  });
  // Update display label
  const display = document.getElementById(dim + '-' + ver + '-display');
  display.textContent = value + '/10';
  display.className = 'rating-display has-' + ver;
  // Update hidden input
  document.getElementById(dim + '-' + ver + '-val').value = value;
  updateProgress();
}

function selectFamiliarity(btn) {
  document.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('familiarity-val').value = btn.dataset.v;
  familiaritySelected = true;
  updateProgress();
}

function updateProgress() {
  const answered = Object.keys(ratings).length + (familiaritySelected ? 1 : 0);
  document.getElementById('survey-progress').textContent =
    answered + ' of 9 questions answered';
  document.getElementById('save-later-btn').disabled = (answered === 0);
}

// ── Pre-fill from previous submission ────────────────────────────────────────
if (PREV_FAMILIARITY) {
  const btn = document.querySelector('.choice-btn[data-v="' + PREV_FAMILIARITY + '"]');
  if (btn) selectFamiliarity(btn);
}
DIMS.forEach(dim => {
  ['a', 'b'].forEach(ver => {
    const prevVal = (PREV_RATINGS[dim] || {})[ver.toUpperCase()];
    if (prevVal) {
      const container = document.getElementById(dim + '-' + ver + '-btns');
      const btn = [...container.querySelectorAll('.scale-btn')].find(b => parseInt(b.textContent) === prevVal);
      if (btn) selectRating(dim, ver, prevVal, btn);
    }
  });
  const prevText = PREV_COMMENTS[dim] || '';
  if (prevText) {
    const ta = document.querySelector('textarea[name="comment[' + dim + ']"]');
    if (ta) ta.value = prevText;
  }
});

function saveLater() {
  document.getElementById('form-action').value = 'save_later';
  document.getElementById('qs-form').submit();
}

document.getElementById('qs-form').addEventListener('submit', function(e) {
  if (document.getElementById('form-action').value === 'save_later') return;
  const missing = [];
  if (!familiaritySelected) missing.push('Q1 (Familiarity)');
  DIMS.forEach((d, i) => {
    if (!ratings[d + '-a']) missing.push('Q' + (i+2) + ' Version A');
    if (!ratings[d + '-b']) missing.push('Q' + (i+2) + ' Version B');
  });
  if (missing.length) {
    e.preventDefault();
    const prog = document.getElementById('survey-progress');
    prog.textContent = 'Please complete: ' + missing.slice(0,3).join(', ') + (missing.length > 3 ? '…' : '');
    prog.style.color = '#b91c1c';
    prog.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});
</script>

</body>
</html>
