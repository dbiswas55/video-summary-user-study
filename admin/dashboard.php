<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$pageTitle = 'Admin Dashboard — User Study';
$pageStyles = ['assets/css/admin.css', 'assets/css/admin-dashboard.css'];
$pdo = getDb();

$study = loadJsonConfig('study.json');
$familiarityLabels = [];
foreach (($study['familiarity_options'] ?? []) as $option) {
    $id = (string)($option['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $familiarityLabels[$id] = (string)($option['label'] ?? $id);
}
if (!$familiarityLabels) {
    $familiarityLabels = [
        'not_familiar' => 'Not Familiar',
        'somewhat' => 'Somewhat Familiar',
        'familiar' => 'Familiar',
        'very_familiar' => 'Very Familiar',
    ];
}

$activeLast7Days = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = FALSE AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$neverLoggedIn = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = FALSE AND last_login IS NULL')->fetchColumn();

$assignedChapters = (int)$pdo->query('
    SELECT COUNT(*)
    FROM user_courses uc
    JOIN users u ON u.id = uc.user_id
    JOIN videos v ON v.course_id = uc.course_id
    JOIN segments s ON s.video_id = v.id
    WHERE u.is_admin = FALSE
')->fetchColumn();

$completedChapters = (int)$pdo->query('
  SELECT COUNT(*)
  FROM user_segment_progress usp
  JOIN users u ON u.id = usp.user_id
  WHERE u.is_admin = FALSE AND usp.status = "completed"
')->fetchColumn();

$inProgressChapters = (int)$pdo->query('
  SELECT COUNT(*)
  FROM user_segment_progress usp
  JOIN users u ON u.id = usp.user_id
  WHERE u.is_admin = FALSE AND usp.status = "in_progress"
')->fetchColumn();

$participantsWithCompleted = (int)$pdo->query('
  SELECT COUNT(DISTINCT usp.user_id)
  FROM user_segment_progress usp
  JOIN users u ON u.id = usp.user_id
  WHERE u.is_admin = FALSE AND usp.status = "completed"
')->fetchColumn();

$participantsWithNoProgress = (int)$pdo->query('
  SELECT COUNT(DISTINCT uc.user_id)
  FROM user_courses uc
  JOIN users u ON u.id = uc.user_id
  LEFT JOIN user_segment_progress usp
    ON usp.user_id = uc.user_id AND usp.status IN ("in_progress", "completed")
  WHERE u.is_admin = FALSE
    AND usp.user_id IS NULL
')->fetchColumn();

$completionRate = $assignedChapters > 0
    ? round(($completedChapters / $assignedChapters) * 100, 1)
    : 0.0;

$courseRows = $pdo->query('
    SELECT
        c.id,
        c.code,
        c.name,
        (
            SELECT COUNT(*)
            FROM user_courses uc
            JOIN users u ON u.id = uc.user_id
            WHERE uc.course_id = c.id AND u.is_admin = FALSE
        ) AS enrolled_users,
        (
            SELECT COUNT(*)
            FROM videos v
            WHERE v.course_id = c.id
        ) AS video_count,
        (
            SELECT COUNT(*)
            FROM segments s
            JOIN videos v ON v.id = s.video_id
            WHERE v.course_id = c.id
        ) AS chapter_count,
        (
            SELECT COUNT(*)
            FROM user_courses uc
            JOIN users u ON u.id = uc.user_id
            JOIN videos v ON v.course_id = uc.course_id
            JOIN segments s ON s.video_id = v.id
            WHERE uc.course_id = c.id AND u.is_admin = FALSE
        ) AS assigned_count,
        (
            SELECT COUNT(*)
            FROM user_segment_progress usp
            JOIN users u ON u.id = usp.user_id
            JOIN segments s ON s.id = usp.segment_id
            JOIN videos v ON v.id = s.video_id
            WHERE v.course_id = c.id AND u.is_admin = FALSE AND usp.status = "completed"
        ) AS completed_count,
        (
            SELECT COUNT(*)
            FROM user_segment_progress usp
            JOIN users u ON u.id = usp.user_id
            JOIN segments s ON s.id = usp.segment_id
            JOIN videos v ON v.id = s.video_id
            WHERE v.course_id = c.id AND u.is_admin = FALSE AND usp.status = "in_progress"
        ) AS in_progress_count
    FROM courses c
    ORDER BY c.code
')->fetchAll();

$ratingsByVersion = ['A' => null, 'B' => null];
$ratingStmt = $pdo->query('
    SELECT version, AVG(rating) AS avg_rating
    FROM responses_ratings
    GROUP BY version
');
foreach ($ratingStmt->fetchAll() as $row) {
    $ratingsByVersion[$row['version']] = $row['avg_rating'] !== null
        ? round((float)$row['avg_rating'], 2)
        : null;
}

$visualQualityAverage = $pdo->query('
    SELECT AVG(selection_quality_rating)
    FROM responses_visual_objects
    WHERE selection_quality_rating IS NOT NULL
')->fetchColumn();
$visualQualityAverage = $visualQualityAverage !== null
    ? round((float)$visualQualityAverage, 2)
    : null;

$visualCoverageCount = (int)$pdo->query('
    SELECT COUNT(*)
    FROM responses_visual_objects rvo
    JOIN user_segment_progress usp
      ON usp.user_id = rvo.user_id AND usp.segment_id = rvo.segment_id
    JOIN users u ON u.id = rvo.user_id
    WHERE u.is_admin = FALSE AND usp.status = "completed"
')->fetchColumn();
$visualCoverage = $completedChapters > 0
    ? round(($visualCoverageCount / $completedChapters) * 100, 1)
    : 0.0;

$familiarityCounts = array_fill_keys(array_keys($familiarityLabels), 0);
$familiarityStmt = $pdo->query('
    SELECT answer, COUNT(*) AS total
    FROM responses_familiarity
    GROUP BY answer
');
foreach ($familiarityStmt->fetchAll() as $row) {
    $answer = (string)$row['answer'];
    if (array_key_exists($answer, $familiarityCounts)) {
        $familiarityCounts[$answer] = (int)$row['total'];
    }
}
$totalFamiliarityResponses = array_sum($familiarityCounts);

include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-wrap">
  <div class="admin-topbar">
    <h1>Admin Dashboard</h1>
    <div class="admin-topbar-actions">
      <a href="<?= baseUrl('admin/manage.php') ?>" class="btn btn-secondary btn-sm">Open Management</a>
      <a href="<?= baseUrl('admin/messages.php') ?>" class="btn btn-secondary btn-sm">Messages</a>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-num"><?= $assignedChapters ?></div>
      <div class="stat-label">Assigned Chapters</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $completedChapters ?></div>
      <div class="stat-label">Completed Chapters</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $inProgressChapters ?></div>
      <div class="stat-label">Chapters In Progress</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= e(number_format($completionRate, 1)) ?>%</div>
      <div class="stat-label">Overall Completion</div>
    </div>
  </div>

  <section class="admin-section">
    <div class="admin-section-header">
      <h2>Course Progress</h2>
      <span class="section-count"><?= count($courseRows) ?> course<?= count($courseRows) !== 1 ? 's' : '' ?></span>
    </div>

    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Course</th>
            <th>Enrolled</th>
            <th>Videos</th>
            <th>Chapters</th>
            <th>Assigned</th>
            <th>Completed</th>
            <th>In Progress</th>
            <th>Completion</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($courseRows as $course): ?>
            <?php
              $courseAssigned = (int)$course['assigned_count'];
              $courseCompleted = (int)$course['completed_count'];
              $coursePercent = $courseAssigned > 0 ? round(($courseCompleted / $courseAssigned) * 100) : 0;
            ?>
            <tr>
              <td>
                <strong><?= e($course['code']) ?></strong><br>
                <span class="cell-muted"><?= e($course['name']) ?></span>
              </td>
              <td><?= e($course['enrolled_users']) ?></td>
              <td><?= e($course['video_count']) ?></td>
              <td><?= e($course['chapter_count']) ?></td>
              <td><?= e($courseAssigned) ?></td>
              <td><?= e($courseCompleted) ?></td>
              <td><?= e($course['in_progress_count']) ?></td>
              <td class="admin-progress-cell">
                <div class="admin-progress-summary">
                  <span><?= e($courseCompleted) ?>/<?= e($courseAssigned) ?></span>
                  <strong><?= e($coursePercent) ?>%</strong>
                </div>
                <div class="admin-progress-track">
                  <div class="admin-progress-bar" style="width: <?= $coursePercent ?>%"></div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-section">
    <div class="admin-section-header">
      <h2>Participant Engagement</h2>
      <span class="section-count">Activity summary</span>
    </div>

    <div class="admin-overview-grid">
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Never Logged In</div>
        <div class="admin-overview-value value-warning"><?= $neverLoggedIn ?></div>
        <div class="admin-overview-meta">Participants who have not signed in yet.</div>
      </article>
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">No Progress Yet</div>
        <div class="admin-overview-value value-warning"><?= $participantsWithNoProgress ?></div>
        <div class="admin-overview-meta">Assigned participants with zero started chapters.</div>
      </article>
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Completed At Least One</div>
        <div class="admin-overview-value value-success"><?= $participantsWithCompleted ?></div>
        <div class="admin-overview-meta">Participants who have finished one or more chapters.</div>
      </article>
    </div>
  </section>

  <section class="admin-section">
    <div class="admin-section-header">
      <h2>Response Quality</h2>
      <span class="section-count">Study data health</span>
    </div>

    <div class="admin-overview-grid">
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Average Rating A</div>
        <div class="admin-overview-value value-accent"><?= $ratingsByVersion['A'] !== null ? e(number_format($ratingsByVersion['A'], 2)) : '—' ?></div>
        <div class="admin-overview-meta">Average participant score for Version A.</div>
      </article>
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Average Rating B</div>
        <div class="admin-overview-value value-accent"><?= $ratingsByVersion['B'] !== null ? e(number_format($ratingsByVersion['B'], 2)) : '—' ?></div>
        <div class="admin-overview-meta">Average participant score for Version B.</div>
      </article>
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Visual Quality</div>
        <div class="admin-overview-value value-success"><?= $visualQualityAverage !== null ? e(number_format($visualQualityAverage, 2)) : '—' ?></div>
        <div class="admin-overview-meta">Average visual selection quality rating.</div>
      </article>
      <article class="admin-overview-card">
        <div class="admin-overview-kicker">Visual Response Coverage</div>
        <div class="admin-overview-value value-accent"><?= e(number_format($visualCoverage, 1)) ?>%</div>
        <div class="admin-overview-meta">Completed chapters with visual-object responses recorded.</div>
      </article>
    </div>

    <div class="admin-section-header" style="margin-top: 22px;">
      <h2>Familiarity Distribution</h2>
      <span class="section-count"><?= $totalFamiliarityResponses ?> response<?= $totalFamiliarityResponses !== 1 ? 's' : '' ?></span>
    </div>
    <div class="admin-familiarity-list">
      <?php foreach ($familiarityLabels as $id => $label): ?>
        <?php
          $count = (int)$familiarityCounts[$id];
          $share = $totalFamiliarityResponses > 0 ? round(($count / $totalFamiliarityResponses) * 100, 1) : 0;
        ?>
        <article class="admin-familiarity-item">
          <strong><?= e($label) ?></strong>
          <div class="admin-familiarity-count"><?= $count ?></div>
          <div class="admin-overview-meta"><?= e(number_format($share, 1)) ?>% of familiarity responses</div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="admin-section">
    <div class="admin-section-header">
      <h2>Admin Shortcuts</h2>
      <span class="section-count">Common tasks</span>
    </div>

    <div class="admin-shortcut-grid">
      <a href="<?= baseUrl('admin/manage.php') ?>" class="admin-shortcut-card">
        <div class="admin-shortcut-title">User Management</div>
        <div class="admin-shortcut-copy">Review registered users, edit course assignments, change passwords, and switch into a participant session.</div>
      </a>
      <a href="<?= baseUrl('admin/messages.php') ?>" class="admin-shortcut-card">
        <div class="admin-shortcut-title">Messages</div>
        <div class="admin-shortcut-copy">Read contact messages, track unread support requests, and clear out resolved items.</div>
      </a>
      <a href="<?= baseUrl('admin/manage.php') ?>" class="admin-shortcut-card">
        <div class="admin-shortcut-title">Video Analysis</div>
        <div class="admin-shortcut-copy">Open the management workspace to review available videos and launch the chapter visualization page.</div>
      </a>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>