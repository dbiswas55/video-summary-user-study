<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';

requireLogin();

$pageTitle = 'Dashboard — User Study';
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

  <?php if (empty($courses)): ?>
    <div class="info-box" style="margin-top:8px;">
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

<style>
/* ── Dashboard v2 ────────────────────────────────────────────────────────── */
.db2-wrap { display: flex; flex-direction: column; gap: 28px; }

.db2-welcome {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 28px 32px; box-shadow: var(--shadow);
  display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap;
}
.db2-welcome-text h1 { font-size: 1.7rem; margin-bottom: 4px; }
.db2-welcome-text p  { font-size: 0.9rem; color: var(--muted); }

.db2-overall-progress { text-align: right; min-width: 160px; }
.db2-progress-nums { display: flex; align-items: baseline; justify-content: flex-end; gap: 2px; }
.db2-pnum-done  { font-size: 2.2rem; font-weight: 700; color: var(--accent); line-height: 1; }
.db2-pnum-sep   { font-size: 1.4rem; color: var(--muted); }
.db2-pnum-total { font-size: 1.4rem; font-weight: 500; color: var(--muted); }
.db2-progress-label { font-size: 0.78rem; color: var(--muted); margin: 2px 0 8px; }
.db2-progress-bar-wrap {
  height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; width: 160px;
}
.db2-progress-bar {
  height: 100%; background: var(--accent); border-radius: 3px; transition: width 0.4s ease;
  min-width: 4px;
}

.db2-course-section {
  background: var(--card); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 24px 28px; box-shadow: var(--shadow);
}
.db2-course-header {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}
.db2-course-meta { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.db2-course-code {
  font-size: 0.75rem; font-weight: 700; letter-spacing: 0.07em; text-transform: uppercase;
  color: var(--accent); background: var(--accent-light); padding: 3px 10px; border-radius: 12px;
}
.db2-course-name { font-size: 1.1rem; font-weight: 600; }
.db2-course-progress-pill {
  font-size: 0.8rem; font-weight: 600; color: var(--muted);
  background: var(--bg); border: 1px solid var(--border);
  padding: 4px 14px; border-radius: 20px; white-space: nowrap;
}
.db2-course-progress-pill.all-done { background: var(--success-light); color: var(--success); border-color: transparent; }

.db2-segment-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px;
}

.db2-seg-card {
  border: 1.5px solid var(--border); border-radius: var(--radius-sm);
  padding: 18px 20px; background: var(--bg);
  display: flex; flex-direction: column; gap: 8px; transition: box-shadow 0.15s;
}
.db2-seg-card:hover { box-shadow: var(--shadow); }
.db2-seg-completed { border-color: #86efac; background: #f0fdf4; }
.db2-seg-in_progress { border-color: #fbbf24; background: #fffbeb; }
.db2-seg-not_started { background: var(--card); }

.db2-seg-top { display: flex; align-items: center; justify-content: space-between; }
.db2-seg-chap { font-size: 0.75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
.db2-seg-badge { font-size: 0.72rem; font-weight: 700; padding: 2px 10px; border-radius: 12px; }
.db2-seg-badge.done { background: #dcfce7; color: #15803d; }
.db2-seg-badge.wip  { background: #fef3c7; color: #92400e; }
.db2-seg-badge.new  { background: var(--bg); color: var(--muted); border: 1px solid var(--border); }

.db2-seg-title { font-size: 0.92rem; font-weight: 600; line-height: 1.4; flex: 1; }

.db2-seg-btn {
  display: block; text-align: center; padding: 9px 0; border-radius: var(--radius-sm);
  font-size: 0.86rem; font-weight: 600; text-decoration: none; margin-top: 4px;
  transition: all 0.15s;
}
.db2-seg-btn.btn-start {
  background: var(--text); color: #fff;
}
.db2-seg-btn.btn-start:hover { background: var(--accent); text-decoration: none; transform: translateY(-1px); }
.db2-seg-btn.btn-review {
  background: transparent; color: var(--success); border: 1.5px solid #86efac;
}
.db2-seg-btn.btn-review:hover { background: #f0fdf4; text-decoration: none; }

@media (max-width: 640px) {
  .db2-welcome { flex-direction: column; gap: 16px; }
  .db2-overall-progress { text-align: left; }
  .db2-progress-bar-wrap { width: 100%; }
  .db2-course-section { padding: 18px 16px; }
  .db2-segment-grid { grid-template-columns: 1fr; }
}
</style>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
