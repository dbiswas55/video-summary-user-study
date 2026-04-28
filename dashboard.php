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

// Load all segments across enrolled courses, with this user's progress
$stmt = $pdo->prepare('
    SELECT
        c.id            AS course_id,
        c.code          AS course_code,
        c.name          AS course_name,
        v.video_id,
        s.id            AS segment_id,
        s.chapter_num,
        s.title         AS segment_title,
        COALESCE(usp.status, "not_started") AS progress
    FROM user_courses uc
    JOIN courses  c   ON c.id  = uc.course_id
    JOIN videos   v   ON v.course_id = c.id
    JOIN segments s   ON s.video_id  = v.id
    LEFT JOIN user_segment_progress usp
           ON usp.segment_id = s.id AND usp.user_id = ?
    WHERE uc.user_id = ?
    ORDER BY c.id, v.display_order, s.display_order
');
$stmt->execute([$user['id'], $user['id']]);
$rows = $stmt->fetchAll();

// Group rows by course
$courses = [];
foreach ($rows as $r) {
    $cid = $r['course_id'];
    if (!isset($courses[$cid])) {
        $courses[$cid] = [
            'code' => $r['course_code'],
            'name' => $r['course_name'],
            'segments' => [],
        ];
    }
    $courses[$cid]['segments'][] = $r;
}

// Summary counts
$total_segments   = count($rows);
$completed_count  = count(array_filter($rows, fn($r) => $r['progress'] === 'completed'));

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
    <?php if ($total_segments > 0): ?>
    <div class="db2-overall-progress">
      <div class="db2-progress-nums">
        <span class="db2-pnum-done"><?= $completed_count ?></span>
        <span class="db2-pnum-sep">/</span>
        <span class="db2-pnum-total"><?= $total_segments ?></span>
      </div>
      <div class="db2-progress-label">segments completed</div>
      <div class="db2-progress-bar-wrap">
        <div class="db2-progress-bar" style="width:<?= $total_segments > 0 ? round($completed_count / $total_segments * 100) : 0 ?>%"></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="db2-course-note">
    Please complete all segments of the course you are rating.
  </div>

  <?php if (empty($courses)): ?>
    <div class="info-box dashboard-empty">
      <strong>No segments assigned yet.</strong>
      <p>Your selected courses do not currently have study segments. You can review your course selection from Profile.</p>
    </div>
  <?php endif; ?>

  <!-- Course sections -->
  <?php foreach ($courses as $course): ?>
  <?php
    $segs      = $course['segments'];
    $c_total   = count($segs);
    $c_done    = count(array_filter($segs, fn($s) => $s['progress'] === 'completed'));
  ?>
  <section class="db2-course-section">

    <div class="db2-course-header">
      <div class="db2-course-meta">
        <span class="db2-course-code"><?= e($course['code']) ?></span>
        <h2 class="db2-course-name"><?= e($course['name']) ?></h2>
      </div>
      <div class="db2-course-progress-pill <?= $c_done === $c_total ? 'all-done' : '' ?>">
        <?= $c_done ?>/<?= $c_total ?> done
      </div>
    </div>

    <div class="db2-segment-grid">
      <?php foreach ($segs as $seg): ?>
      <?php
        $status   = $seg['progress'];
        $view_url = baseUrl('survey/viewer.php?vid=' . $seg['video_id']);
      ?>
      <div class="db2-seg-card db2-seg-<?= e($status) ?>">
        <div class="db2-seg-top">
          <span class="db2-seg-chap">Chapter <?= e($seg['chapter_num']) ?></span>
          <?php if ($status === 'completed'): ?>
            <span class="db2-seg-badge done">✓ Done</span>
          <?php elseif ($status === 'in_progress'): ?>
            <span class="db2-seg-badge wip">In Progress</span>
          <?php else: ?>
            <span class="db2-seg-badge new">Not started</span>
          <?php endif; ?>
        </div>
        <h3 class="db2-seg-title"><?= e($seg['segment_title']) ?></h3>
        <a href="<?= e($view_url) ?>" class="db2-seg-btn <?= $status === 'completed' ? 'btn-review' : 'btn-start' ?>">
          <?php if ($status === 'completed'): ?>Review Again<?php elseif ($status === 'in_progress'): ?>Continue<?php else: ?>Start Evaluation<?php endif; ?>
        </a>
      </div>
      <?php endforeach; ?>
    </div>

  </section>
  <?php endforeach; ?>

</div>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
