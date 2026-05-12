<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$pageTitle = 'Admin Management — User Study';
$pageStyles = ['assets/css/admin.css', 'assets/css/admin-manage.css'];
$pdo = getDb();

$totalUsers    = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = FALSE')->fetchColumn();
$activeToday   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE() AND is_admin = FALSE")->fetchColumn();
$activeLast7Days = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = FALSE AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$unreadCount   = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = FALSE')->fetchColumn();
$totalMessages = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();

$users = $pdo->query('
    SELECT u.id, u.username, u.email, u.account_type, u.is_active, u.is_admin,
           u.created_at, u.last_login, s.name AS subject_name
    FROM users u
    LEFT JOIN subjects s ON s.id = u.subject_id
    ORDER BY u.created_at DESC
')->fetchAll();

$videoRows = $pdo->query('
    SELECT
      c.id AS course_id,
      c.code AS course_code,
      c.name AS course_name,
      v.video_id,
      v.video_filename,
      COUNT(seg.id) AS chapter_count
    FROM courses c
    JOIN videos v ON v.course_id = c.id
    LEFT JOIN segments seg ON seg.video_id = v.id
    GROUP BY c.id, c.code, c.name, v.id, v.video_id, v.video_filename, v.display_order
    ORDER BY c.code, v.display_order, v.video_id
')->fetchAll();

$courseVideos = [];
foreach ($videoRows as $videoRow) {
    $courseId = (int)$videoRow['course_id'];
    if (!isset($courseVideos[$courseId])) {
        $courseVideos[$courseId] = [
            'code' => $videoRow['course_code'],
            'name' => $videoRow['course_name'],
            'videos' => [],
        ];
    }

    $courseVideos[$courseId]['videos'][] = $videoRow;
}

include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-wrap">
  <div class="admin-topbar">
    <h1>Admin Management</h1>
    <div class="admin-topbar-actions">
      <a href="<?= baseUrl('admin/dashboard.php') ?>" class="btn btn-secondary btn-sm">Overview</a>
      <a href="<?= baseUrl('admin/messages.php') ?>" class="btn btn-secondary btn-sm">Messages</a>
    </div>
  </div>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-num"><?= $totalUsers ?></div>
      <div class="stat-label">Total Participants</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $activeToday ?></div>
      <div class="stat-label">Active Today</div>
    </div>
    <div class="stat-card">
      <div class="stat-num"><?= $activeLast7Days ?></div>
      <div class="stat-label">Active Last 7 Days</div>
    </div>
    <div class="stat-card <?= $unreadCount > 0 ? 'stat-alert' : '' ?>">
      <a href="<?= baseUrl('admin/messages.php') ?>" class="stat-link">
        <div class="stat-num"><?= $unreadCount ?><?= $unreadCount > 0 ? ' <span class="stat-dot">●</span>' : '' ?></div>
        <div class="stat-label">Unread Messages (<?= $totalMessages ?> total)</div>
      </a>
    </div>
  </div>

  <section class="admin-section">
    <div class="admin-section-header">
      <h2>Registered Users</h2>
      <a href="<?= baseUrl('admin/messages.php') ?>" class="btn btn-secondary btn-sm">
        View Messages<?= $unreadCount > 0 ? ' <span class="badge">' . $unreadCount . '</span>' : '' ?>
      </a>
    </div>

    <div class="table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Subject</th>
            <th>Type</th>
            <th>Active</th>
            <th>Admin</th>
            <th>Last Login</th>
            <th class="admin-edit-heading">Edit</th>
            <th class="admin-login-heading">Login As</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td class="cell-id"><?= e($u['id']) ?></td>
            <td><strong><?= e($u['username']) ?></strong></td>
            <td class="cell-muted"><?= e($u['email'] ?? '—') ?></td>
            <td class="cell-muted"><?= e($u['subject_name'] ?? '—') ?></td>
            <td><span class="type-badge"><?= e($u['account_type']) ?></span></td>
            <td><?= $u['is_active'] ? '<span class="status-yes">Yes</span>' : '<span class="status-no">No</span>' ?></td>
            <td><?= $u['is_admin'] ? '<span class="status-yes">Yes</span>' : '—' ?></td>
            <td class="cell-muted"><?= $u['last_login'] ? e($u['last_login']) : '<span class="status-no">Never</span>' ?></td>
            <td class="admin-edit-cell">
              <?php if (!$u['is_admin']): ?>
                <a href="<?= baseUrl('admin/edit_user.php?id=' . $u['id']) ?>" class="btn btn-secondary btn-sm">Edit</a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="admin-login-cell">
              <?php if (!$u['is_admin']): ?>
                <form method="POST" action="<?= e(baseUrl('admin/switch_user.php')) ?>" class="admin-inline-form" onsubmit="return confirm('This will end the current admin session and sign you in as <?= e($u['username']) ?>. Continue?');">
                  <input type="hidden" name="user_id" value="<?= e($u['id']) ?>">
                  <button type="submit" class="btn btn-secondary btn-sm">Login As</button>
                </form>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="admin-section admin-video-section">
    <div class="admin-section-header">
      <h2>Available Videos</h2>
      <span class="section-count"><?= count($videoRows) ?> video<?= count($videoRows) !== 1 ? 's' : '' ?> across <?= count($courseVideos) ?> course<?= count($courseVideos) !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (!$courseVideos): ?>
      <p class="cell-muted">No videos are available yet.</p>
    <?php else: ?>
      <div class="admin-course-groups">
        <?php foreach ($courseVideos as $courseId => $course): ?>
          <?php $coursePanelId = 'admin-course-panel-' . (int)$courseId; ?>
          <section class="admin-course-card">
            <button
              type="button"
              class="admin-course-card-header admin-course-toggle"
              aria-expanded="true"
              aria-controls="<?= e($coursePanelId) ?>"
              data-course-id="<?= (int)$courseId ?>"
            >
              <span class="admin-course-card-heading">
                <span class="admin-course-toggle-icon" aria-hidden="true">&#9662;</span>
                <span>
                  <span class="admin-course-code"><?= e($course['code']) ?></span>
                  <span class="admin-course-name"><?= e($course['name']) ?></span>
                </span>
              </span>
              <span class="admin-course-count"><?= count($course['videos']) ?> video<?= count($course['videos']) !== 1 ? 's' : '' ?></span>
            </button>

            <div class="admin-video-listing" id="<?= e($coursePanelId) ?>">
              <?php foreach ($course['videos'] as $video): ?>
                <?php $visualizeUrl = baseUrl('admin/visualize.php?vid=' . (int)$video['video_id']); ?>
                <article class="admin-video-item">
                  <div class="admin-video-meta">
                    <h4><?= e(displayVideoName($video['video_filename'])) ?></h4>
                    <p>
                      Video ID <?= e($video['video_id']) ?>
                      &nbsp;·&nbsp;
                      <?= (int)$video['chapter_count'] ?> chapter<?= (int)$video['chapter_count'] !== 1 ? 's' : '' ?>
                    </p>
                  </div>
                  <a href="<?= e($visualizeUrl) ?>" class="btn btn-secondary btn-sm">Open Analysis</a>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<script>
(() => {
  const storageKeyPrefix = 'admin-course-visibility:';
  const scrollStorageKey = 'admin-management-scroll';

  function saveScrollPosition() {
    sessionStorage.setItem(scrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
  }

  function restoreScrollPosition() {
    const stored = sessionStorage.getItem(scrollStorageKey);
    if (stored === null) return;
    const scrollY = Number(stored);
    if (!Number.isFinite(scrollY) || scrollY < 0) return;

    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        window.scrollTo({ top: scrollY, left: 0, behavior: 'auto' });
      });
    });
  }

  function applyExpandedState(button, expanded) {
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    const icon = button.querySelector('.admin-course-toggle-icon');
    if (!panel || !icon) return;
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    panel.hidden = !expanded;
    icon.innerHTML = expanded ? '&#9662;' : '&#9656;';
  }

  document.querySelectorAll('.admin-course-toggle').forEach((button) => {
    const courseId = button.dataset.courseId;
    const stored = sessionStorage.getItem(storageKeyPrefix + courseId);
    if (stored === 'collapsed') {
      applyExpandedState(button, false);
    }

    button.addEventListener('click', () => {
      const expanded = button.getAttribute('aria-expanded') === 'true';
      const nextExpanded = !expanded;
      applyExpandedState(button, nextExpanded);
      sessionStorage.setItem(
        storageKeyPrefix + courseId,
        nextExpanded ? 'expanded' : 'collapsed'
      );
    });
  });

  document.querySelectorAll('.admin-video-item a').forEach((link) => {
    link.addEventListener('click', saveScrollPosition);
  });

  window.addEventListener('scroll', saveScrollPosition, { passive: true });
  window.addEventListener('pagehide', saveScrollPosition);

  restoreScrollPosition();
})();
</script>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>