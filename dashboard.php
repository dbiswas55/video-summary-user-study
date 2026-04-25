<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$pageTitle = 'Dashboard — User Study';
$user = getCurrentUser();

$pdo = getDb();
if ($user['subject_id']) {
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
    $stmt->execute([$user['subject_id']]);
    $subject = $stmt->fetch();
} else {
    $subject = null;
}

$courses = getUserCourses($user['id']);

include __DIR__ . '/includes/header.php';
?>

<div class="dashboard">
  <div class="welcome-banner">
    <h1>Welcome, <?= e($user['username']) ?> 👋</h1>
    <p class="muted-meta">
      Account type: <?= e($user['account_type']) ?>
      <?php if ($subject): ?>
        · Subject: <strong><?= e($subject['name']) ?></strong>
      <?php endif; ?>
    </p>
  </div>

  <section class="dashboard-section">
    <h2>Your Selected Courses</h2>
    <?php if (empty($courses)): ?>
      <p class="muted-meta">No courses selected yet.</p>
    <?php else: ?>
      <div class="course-grid">
        <?php foreach ($courses as $course): ?>
          <div class="course-card">
            <span class="course-code"><?= e($course['code']) ?></span>
            <span class="course-name"><?= e($course['name']) ?></span>
            <span class="course-instructor"><?= e($course['instructor']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="dashboard-section">
    <div class="info-box">
      <strong>✓ Phase 1 Complete</strong>
      <p>Authentication is working. The full dashboard with videos and segments will be built in Phase 2.</p>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
