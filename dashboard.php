<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';

requireLogin();

$pageTitle = 'Dashboard — User Study';
$pageStyles = ['assets/css/dashboard.css'];
$user = getCurrentUser();
$pdo  = getDb();

// Load subject
$subject = null;
if ($user['subject_id']) {
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $stmt->execute([$user['subject_id']]);
    $subject = $stmt->fetch();
}

// Load all chapters across enrolled courses, with this user's progress
$stmt = $pdo->prepare('
    SELECT
        c.id            AS course_id,
        c.code          AS course_code,
        c.name          AS course_name,
        v.video_id,
        v.video_filename,
        s.id            AS segment_id,
        s.chapter_num,
        s.title         AS chapter_title,
        COALESCE(usp.status, "not_started") AS progress
    FROM user_courses uc
    JOIN courses  c   ON c.id  = uc.course_id
    JOIN videos   v   ON v.course_id = c.id
    JOIN segments s   ON s.video_id  = v.id
    LEFT JOIN user_segment_progress usp
           ON usp.segment_id = s.id AND usp.user_id = ?
    WHERE uc.user_id = ?
    ORDER BY c.id, v.display_order, v.video_id, s.display_order, s.chapter_num
');
$stmt->execute([$user['id'], $user['id']]);
$rows = $stmt->fetchAll();

// Group rows by course, then video, then chapter.
$courses = [];
foreach ($rows as $r) {
    $cid = $r['course_id'];
    if (!isset($courses[$cid])) {
        $courses[$cid] = [
            'code' => $r['course_code'],
            'name' => $r['course_name'],
            'videos' => [],
        ];
    }

    $vid = $r['video_id'];
    if (!isset($courses[$cid]['videos'][$vid])) {
        $courses[$cid]['videos'][$vid] = [
            'video_id' => $r['video_id'],
            'video_filename' => $r['video_filename'],
            'chapters' => [],
        ];
    }
    $courses[$cid]['videos'][$vid]['chapters'][] = $r;
}

// Summary counts
$total_chapters   = count($rows);
$total_videos     = array_sum(array_map(fn($course) => count($course['videos']), $courses));
$completed_count  = count(array_filter($rows, fn($r) => $r['progress'] === 'completed'));

function displayVideoName($filename) {
    $filename = (string)$filename;
    if ($filename === '') {
        return 'Video file not configured';
    }

    $core = preg_replace('/[^A-Za-z0-9]+[0-9]{4}\.mp4$/i', '', $filename);
    if ($core === $filename) {
        $core = pathinfo($filename, PATHINFO_FILENAME);
    }

    $core = preg_replace_callback('/[^A-Za-z0-9]+/', function ($match) {
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

include __DIR__ . '/app/includes/header.php';
?>

<div class="db2-wrap">

  <!-- Welcome banner -->
  <div class="db2-welcome">
    <div class="db2-welcome-text">
      <h1>Welcome, <?= e($user['username']) ?></h1>
      <p>
        <?php if ($subject): ?>Subject area: <strong><?= e($subject['name']) ?></strong><?php endif; ?>
      </p>
    </div>
    <?php if ($total_chapters > 0): ?>
    <div class="db2-overall-progress">
      <div class="db2-progress-nums">
        <span class="db2-pnum-done"><?= $completed_count ?></span>
        <span class="db2-pnum-sep">/</span>
        <span class="db2-pnum-total"><?= $total_chapters ?></span>
      </div>
      <div class="db2-progress-label">
        chapters completed · <?= $total_videos ?> video<?= $total_videos !== 1 ? 's' : '' ?>
      </div>
      <div class="db2-progress-bar-wrap">
        <div class="db2-progress-bar" style="width:<?= $total_chapters > 0 ? round($completed_count / $total_chapters * 100) : 0 ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="db2-course-note">
    Please complete all chapters of the course you are rating.
  </div>

  <?php if (empty($courses)): ?>
    <div class="info-box dashboard-empty">
      <strong>No chapters assigned yet.</strong>
      <p>Your selected courses do not currently have study chapters. You can review your course selection from Profile.</p>
    </div>
  <?php endif; ?>

  <!-- Course sections -->
  <?php foreach ($courses as $course_index => $course): ?>
  <?php
    $videos    = $course['videos'];
    $chapters  = array_merge(...array_map(fn($video) => $video['chapters'], $videos));
    $c_total   = count($chapters);
    $c_done    = count(array_filter($chapters, fn($chapter) => $chapter['progress'] === 'completed'));
    $video_count = count($videos);
    $course_panel_id = 'course-panel-' . $course_index;
  ?>
  <section class="db2-course-section">

    <button class="db2-course-header db2-course-toggle"
            type="button"
            aria-expanded="true"
            aria-controls="<?= e($course_panel_id) ?>">
      <span class="db2-course-heading-main">
        <span class="db2-course-toggle-icon" aria-hidden="true">⌃</span>
        <span class="db2-course-meta">
          <span class="db2-course-code"><?= e($course['code']) ?></span>
          <span class="db2-course-name"><?= e($course['name']) ?></span>
        </span>
      </span>
      <span class="db2-course-progress-pill <?= $c_done === $c_total ? 'all-done' : '' ?>">
        <?= $c_done ?>/<?= $c_total ?> chapters · <?= $video_count ?> video<?= $video_count !== 1 ? 's' : '' ?>
      </span>
    </button>

    <div class="db2-video-list" id="<?= e($course_panel_id) ?>">
      <?php foreach ($videos as $video): ?>
      <?php
        $video_chapters = $video['chapters'];
        $v_total = count($video_chapters);
        $v_done = count(array_filter($video_chapters, fn($chapter) => $chapter['progress'] === 'completed'));
      ?>
      <section class="db2-video-group">
        <div class="db2-video-header">
          <div class="db2-video-meta">
            <h3 class="db2-video-title"><?= e(displayVideoName($video['video_filename'])) ?></h3>
          </div>
          <span class="db2-video-count <?= $v_done === $v_total ? 'all-done' : '' ?>">
            <?= $v_done ?>/<?= $v_total ?> chapters
          </span>
        </div>
        <div class="db2-chapter-list">
          <?php foreach ($video_chapters as $chapter): ?>
          <?php
            $status   = $chapter['progress'];
            $view_url = baseUrl('survey/viewer.php?vid=' . $chapter['video_id'] . '&chapter=' . $chapter['chapter_num']);
          ?>
          <div class="db2-chapter-row db2-chapter-<?= e($status) ?>">
            <div class="db2-chapter-meta">
              <span class="db2-chapter-num">Chapter <?= e($chapter['chapter_num']) ?></span>
              <?php if ($status === 'completed'): ?>
                <span class="db2-chapter-badge done">✓ Done</span>
              <?php elseif ($status === 'in_progress'): ?>
                <span class="db2-chapter-badge wip">In Progress</span>
              <?php else: ?>
                <span class="db2-chapter-badge new">Not started</span>
              <?php endif; ?>
            </div>
            <h4 class="db2-chapter-title"><?= e($chapter['chapter_title']) ?></h4>
            <a href="<?= e($view_url) ?>" class="db2-chapter-btn <?php
              if ($status === 'completed') echo 'btn-review';
              elseif ($status === 'in_progress') echo 'btn-continue';
              else echo 'btn-start';
            ?>">
              <?php if ($status === 'completed'): ?>Review Again<?php elseif ($status === 'in_progress'): ?>Continue<?php else: ?>Start Evaluation<?php endif; ?>
            </a>
          </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    </div>

  </section>
  <?php endforeach; ?>

</div>

<script>
document.querySelectorAll('.db2-course-header').forEach((button) => {
  button.addEventListener('click', () => {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    if (!panel) return;
    const expanded = button.getAttribute('aria-expanded') === 'true';
    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    panel.hidden = expanded;
  });
});
</script>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
