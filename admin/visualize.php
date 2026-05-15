<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$rawVid = $_GET['vid'] ?? '';
if (!ctype_digit((string)$rawVid)) {
    http_response_code(400);
    die('Missing or invalid video ID.');
}

$requestedVideoId = (int)$rawVid;
$pdo = getDb();

$stmt = $pdo->prepare('
    SELECT
        v.id AS internal_id,
        v.video_id,
        v.instructor_id,
        v.video_filename,
        v.course_id,
        c.code AS course_code,
        c.name AS course_name,
        s.id AS segment_id,
        s.chapter_num,
        s.title AS segment_title,
        s.start_s,
        s.end_s,
        s.duration_s,
        s.slide_range_start,
        s.slide_range_end
    FROM videos v
    JOIN courses c ON c.id = v.course_id
    LEFT JOIN segments s ON s.video_id = v.id
    WHERE v.video_id = ?
    ORDER BY s.display_order, s.chapter_num
');
$stmt->execute([$requestedVideoId]);
$rows = $stmt->fetchAll();

if (!$rows) {
    http_response_code(404);
    die('Video not found.');
}

$videoRow = $rows[0];
$instructorId = (int)$videoRow['instructor_id'];
$videoId = (int)$videoRow['video_id'];

function visualizeReadResource(int $instructorId, int $videoId, string $filename): string {
    $path = getResourcePath($instructorId, $videoId, $filename);
    return file_exists($path) ? file_get_contents($path) : '';
}

function visualizeVttTimeToSeconds(string $time): float {
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

  function visualizeVttEndTime(string $vtt): float {
    $maxEnd = 0.0;
    if (preg_match_all('/-->\s*([0-9:.]+)/', $vtt, $matches)) {
        foreach ($matches[1] as $endTime) {
            $maxEnd = max($maxEnd, visualizeVttTimeToSeconds($endTime));
        }
    }
    return $maxEnd;
}

  function visualizeFmtTime(float $secs): string {
    $seconds = (int)round($secs);
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

  function visualizeBuildVisualObjects(int $instructorId, int $videoId, string $chapterDir, ?array $files, string $prefix): array {
    $items = [];
    $sortedFiles = array_values($files ?? []);
    sort($sortedFiles, SORT_STRING);
    foreach ($sortedFiles as $index => $file) {
        $items[] = [
            'label' => $prefix . ($index + 1),
            'file' => (string)$file,
            'url' => getResourceUrl($instructorId, $videoId, $chapterDir . '/visual_objects/' . $file),
        ];
    }
    return $items;
}

function visualizeJsStr($value): string {
    return json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?: '""';
}

$transcriptVtt = visualizeReadResource($instructorId, $videoId, 'transcript.vtt');
$transcriptVttPath = getResourcePath($instructorId, $videoId, 'transcript.vtt');
$transcriptVttUrl = file_exists($transcriptVttPath)
    ? getVideoAssetUrl($instructorId, $videoId, 'transcript.vtt')
    : '';
$videoUrl = $videoRow['video_filename']
    ? getVideoUrl($instructorId, $videoId, $videoRow['video_filename'])
    : '';
$videoDuration = visualizeVttEndTime($transcriptVtt);

$chapters = [];
foreach ($rows as $row) {
    if (!$row['segment_id']) {
        continue;
    }

    $chapterNum = (int)$row['chapter_num'];
    $chapterDir = 'chapter' . $chapterNum;
    $metadataPath = getResourcePath($instructorId, $videoId, $chapterDir . '/metadata.json');
    $chapterMeta = file_exists($metadataPath)
        ? json_decode(file_get_contents($metadataPath), true)
        : [];
    $visualMeta = is_array($chapterMeta['visual_objects'] ?? null) ? $chapterMeta['visual_objects'] : [];

    $slidesDir = getResourcePath($instructorId, $videoId, $chapterDir . '/slides');
    $slideFiles = [];
    if (is_dir($slidesDir)) {
        foreach (scandir($slidesDir) as $file) {
            if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $file)) {
                $slideFiles[] = $file;
            }
        }
        sort($slideFiles);
    }

    $startSeconds = (float)$row['start_s'];
    $endSeconds = (float)$row['end_s'];
    if ($endSeconds <= $startSeconds && $videoDuration > $startSeconds) {
        $endSeconds = $videoDuration;
    }
    $durationSeconds = (float)$row['duration_s'];
    if ($durationSeconds <= 0 && $endSeconds > $startSeconds) {
        $durationSeconds = $endSeconds - $startSeconds;
    }

    $chapters[] = [
        'segment_id' => (int)$row['segment_id'],
        'chapter_num' => $chapterNum,
        'title' => (string)$row['segment_title'],
        'start_s' => $startSeconds,
        'end_s' => $endSeconds,
        'duration_s' => $durationSeconds,
        'time_label' => visualizeFmtTime($startSeconds) . ' - ' . visualizeFmtTime($endSeconds),
        'duration_label' => visualizeFmtTime($durationSeconds),
        'slide_range' => $row['slide_range_start'] !== null && $row['slide_range_end'] !== null
            ? ((string)$row['slide_range_start'] . '–' . (string)$row['slide_range_end'])
            : '',
        'slide_urls' => array_map(
          fn(string $file): string => getResourceUrl($instructorId, $videoId, $chapterDir . '/slides/' . $file),
            $slideFiles
        ),
        'selected_visuals' => visualizeBuildVisualObjects($instructorId, $videoId, $chapterDir, $visualMeta['selected'] ?? [], 'S'),
        'unselected_visuals' => visualizeBuildVisualObjects($instructorId, $videoId, $chapterDir, $visualMeta['unselected'] ?? [], 'U'),
    ];
}

$pageTitle = 'Video Analysis — Admin';
$pageStyles = ['assets/css/admin-visualize.css'];
$pageScripts = ['assets/js/admin-visualize.js'];
$pageMainClass = 'site-main-admin-visualize';

$chapterCount = count($chapters);
$videoLabel = displayVideoName($videoRow['video_filename']);

include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-visualize-page">
  <header class="admin-visualize-header">
    <div>
      <div class="admin-visualize-kicker">Admin Video Analysis</div>
      <h1><?= e($videoLabel) ?></h1>
      <p>
        <?= e($videoRow['course_code']) ?>
        &nbsp;·&nbsp;
        <?= e($videoRow['course_name']) ?>
        &nbsp;·&nbsp;
        Video ID <?= e($videoId) ?>
      </p>
    </div>
    <div class="admin-visualize-actions">
      <span class="admin-visualize-pill"><?= $chapterCount ?> chapter<?= $chapterCount !== 1 ? 's' : '' ?></span>
      <a href="<?= e(baseUrl('admin/manage.php')) ?>" class="btn btn-secondary btn-sm">Back to Admin</a>
    </div>
  </header>

  <section class="admin-visualize-media-card">
    <div class="media-row">
      <div class="video-wrap">
        <div class="section-label">Lecture Video</div>
        <?php if ($videoUrl): ?>
          <video id="chapter-video" controls preload="metadata">
            <source src="<?= e($videoUrl) ?>" type="video/mp4">
            <?php if ($transcriptVttUrl): ?>
              <track kind="captions" src="<?= e($transcriptVttUrl) ?>" srclang="en" label="English">
            <?php endif; ?>
            Your browser does not support HTML5 video.
          </video>
        <?php else: ?>
          <div class="video-missing">Video file not configured.</div>
        <?php endif; ?>
        <div class="video-meta-bar">
          <span id="video-current-time">00:00</span>
          <span><?= e($chapterCount) ?> chapter<?= $chapterCount !== 1 ? 's' : '' ?> available for analysis</span>
        </div>
      </div>

      <div class="transcript-panel">
        <div class="panel-header">
          <span class="label">Transcript</span>
          <button class="toggle-btn" id="toggle-btn" type="button" title="Hide transcript">&#8250;</button>
        </div>
        <div id="transcript-body">Loading&hellip;</div>
      </div>
    </div>
  </section>

  <?php if (!$chapters): ?>
    <section class="admin-empty-state">
      <p>No chapter rows were found for this video.</p>
    </section>
  <?php else: ?>
    <div class="admin-chapter-stack">
      <?php foreach ($chapters as $chapter): ?>
        <?php
        $chapterPanelId = 'chapter-panel-' . $chapter['segment_id'];
        $chapterButtonId = 'chapter-toggle-' . $chapter['segment_id'];
        ?>
        <section class="admin-chapter-card" id="chapter-<?= e($chapter['segment_id']) ?>">
          <div class="admin-chapter-toolbar">
            <button
              type="button"
              class="summary-collapse-btn admin-chapter-toggle"
              id="<?= e($chapterButtonId) ?>"
              aria-expanded="true"
              aria-controls="<?= e($chapterPanelId) ?>"
            >
              <span class="admin-chapter-toggle-icon" aria-hidden="true">&#9662;</span>
              <span class="admin-chapter-heading">
                <span class="admin-chapter-title">Chapter <?= e($chapter['chapter_num']) ?>: <?= e($chapter['title']) ?></span>
                <span class="admin-chapter-subtitle"><?= e($chapter['time_label']) ?> &nbsp;·&nbsp; <?= e($chapter['duration_label']) ?></span>
              </span>
            </button>
            <button
              type="button"
              class="admin-play-chapter-btn"
              data-start="<?= e(number_format($chapter['start_s'], 4, '.', '')) ?>"
              data-end="<?= e(number_format($chapter['end_s'], 4, '.', '')) ?>"
            >
              Play Chapter
            </button>
              <a
                href="<?= e(baseUrl('admin/edit_objects.php?vid=' . $videoId . '&chapter=' . $chapter['chapter_num'])) ?>"
                class="admin-edit-objects-btn"
              >Edit Objects</a>
          </div>

          <div class="admin-chapter-panel" id="<?= e($chapterPanelId) ?>">
            <section class="slide-section">
              <div class="section-label">
                Video Frames (Slides)
                &nbsp;&middot;&nbsp; <?= count($chapter['slide_urls']) ?> frame<?= count($chapter['slide_urls']) !== 1 ? 's' : '' ?>
                <?php if ($chapter['slide_range'] !== ''): ?>
                  &nbsp;&middot;&nbsp; Slides <?= e($chapter['slide_range']) ?>
                <?php endif; ?>
              </div>
              <?php if ($chapter['slide_urls']): ?>
                <div class="slide-strip chapter-slide-strip" data-gallery='<?= e(json_encode($chapter['slide_urls'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'></div>
                <div class="slide-footer">Scroll horizontally to browse &middot; Click to zoom</div>
              <?php else: ?>
                <p class="admin-empty-copy">No slide images found for this chapter.</p>
              <?php endif; ?>
            </section>

            <div class="visual-study-layout admin-visual-layout">
              <section class="visual-group selected">
                <div class="visual-group-header">
                  <h2>Selected Visual Objects</h2>
                  <span><?= count($chapter['selected_visuals']) ?> item<?= count($chapter['selected_visuals']) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="visual-object-grid">
                  <?php if ($chapter['selected_visuals']): ?>
                    <?php foreach ($chapter['selected_visuals'] as $obj): ?>
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
                  <h2>Not Selected Visual Objects</h2>
                  <span><?= count($chapter['unselected_visuals']) ?> item<?= count($chapter['unselected_visuals']) !== 1 ? 's' : '' ?></span>
                </div>
                <div class="visual-object-grid">
                  <?php if ($chapter['unselected_visuals']): ?>
                    <?php foreach ($chapter['unselected_visuals'] as $obj): ?>
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
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="lightbox" id="lightbox">
  <button class="lb-close" type="button" id="lb-close" title="Close (Esc)">&#x2715;</button>
  <button class="lb-nav lb-prev" type="button" id="lb-prev" title="Previous">&#8592;</button>
  <div class="lb-img-wrap"><img id="lightbox-img" src="" alt=""></div>
  <button class="lb-nav lb-next" type="button" id="lb-next" title="Next">&#8594;</button>
  <div class="lb-counter" id="lb-counter"></div>
</div>

<script>
window.ADMIN_VISUALIZE_DATA = {
  transcriptVtt: <?= visualizeJsStr($transcriptVtt) ?>,
  transcriptUrl: <?= visualizeJsStr($transcriptVttUrl) ?>
};
</script>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>