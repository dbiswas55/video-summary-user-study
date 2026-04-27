<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

$pageTitle = 'Admin Dashboard — User Study';
$pdo = getDb();

$totalUsers    = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = FALSE')->fetchColumn();
$activeToday   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE()")->fetchColumn();
$unreadCount   = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages WHERE is_read = FALSE')->fetchColumn();
$totalMessages = (int)$pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();

$users = $pdo->query('
    SELECT u.id, u.username, u.email, u.account_type, u.is_active, u.is_admin,
           u.created_at, u.last_login, s.name AS subject_name
    FROM users u
    LEFT JOIN subjects s ON s.id = u.subject_id
    ORDER BY u.created_at DESC
')->fetchAll();

include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-wrap">
  <div class="admin-topbar">
    <h1>Admin Dashboard</h1>
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
            <th></th>
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
            <td>
              <?php if (!$u['is_admin']): ?>
                <a href="<?= baseUrl('admin/edit_user.php?id=' . $u['id']) ?>" class="btn btn-secondary btn-sm">Edit</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
