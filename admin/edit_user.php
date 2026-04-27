<?php
/**
 * Admin: Edit a single user.
 *  - Change subject + courses
 *  - Generate / revoke one-click login / password reset links
 */

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireAdmin();

function generateLoginToken() {
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

$pdo = getDb();
$user_id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'User not found.');
    header('Location: ' . baseUrl('admin/index.php'));
    exit;
}

if ($user['is_admin']) {
    setFlash('error', 'Admin accounts are not editable from this page.');
    header('Location: ' . baseUrl('admin/index.php'));
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_courses') {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $course_ids = array_map('intval', $_POST['course_ids'] ?? []);

        $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ?');
        $stmt->execute([$subject_id]);
        if (!$stmt->fetch()) {
            setFlash('error', 'Invalid subject.');
        } else {
            $valid_ids = [];
            if (!empty($course_ids)) {
                $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE id IN ($placeholders) AND subject_id = ?");
                $stmt->execute(array_merge($course_ids, [$subject_id]));
                $valid_ids = array_column($stmt->fetchAll(), 'id');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE users SET subject_id = ? WHERE id = ?')
                    ->execute([$subject_id, $user_id]);
                $pdo->prepare('DELETE FROM user_courses WHERE user_id = ?')
                    ->execute([$user_id]);
                if ($valid_ids) {
                    $stmt = $pdo->prepare('INSERT INTO user_courses (user_id, course_id) VALUES (?, ?)');
                    foreach ($valid_ids as $cid) {
                        $stmt->execute([$user_id, $cid]);
                    }
                }
                $pdo->commit();
                setFlash('success', 'Subject and course assignments updated.');
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('error', 'Update failed: ' . $e->getMessage());
            }
        }
        header('Location: ?id=' . $user_id);
        exit;
    }

    if ($action === 'regenerate_token') {
        $token = generateLoginToken();
        $pdo->prepare('UPDATE users SET login_token = ? WHERE id = ?')
            ->execute([$token, $user_id]);
        setFlash('success', 'New one-click access link generated.');
        header('Location: ?id=' . $user_id);
        exit;
    }

    if ($action === 'revoke_token') {
        $pdo->prepare('UPDATE users SET login_token = NULL WHERE id = ?')
            ->execute([$user_id]);
        setFlash('success', 'One-click access link revoked.');
        header('Location: ?id=' . $user_id);
        exit;
    }
}

// ── Reload data ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$subjects     = getSubjects();
$courses      = $user['subject_id'] ? getCoursesBySubject($user['subject_id']) : [];
$enrolled_ids = array_column(getUserCourses($user_id), 'id');

$auto_login_url = $user['login_token']
    ? absoluteUrl('account/auto_login.php?token=' . $user['login_token'])
    : '';

$pageTitle = 'Edit User: ' . $user['username'];
include __DIR__ . '/../app/includes/header.php';
?>

<div class="admin-wrap">
  <div class="admin-topbar">
    <h1>Edit User</h1>
    <a href="<?= baseUrl('admin/index.php') ?>" class="btn btn-secondary btn-sm">← Back to Users</a>
  </div>

  <?php $flash = getFlash(); if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
  <?php endif; ?>

  <section class="admin-section">
    <h2><?= e($user['username']) ?></h2>
    <table class="admin-table">
      <tbody>
        <tr><th style="width:160px;">User ID</th><td><?= e($user['id']) ?></td></tr>
        <tr><th>Username</th><td><strong><?= e($user['username']) ?></strong></td></tr>
        <tr><th>Email</th><td><?= e($user['email'] ?? '—') ?></td></tr>
        <tr><th>Account Type</th><td><span class="type-badge"><?= e($user['account_type']) ?></span></td></tr>
        <tr><th>Active</th><td><?= $user['is_active'] ? '<span class="status-yes">Yes</span>' : '<span class="status-no">No</span>' ?></td></tr>
        <tr><th>Created</th><td class="cell-muted"><?= e($user['created_at']) ?></td></tr>
        <tr><th>Last Login</th><td class="cell-muted"><?= $user['last_login'] ? e($user['last_login']) : '<span class="status-no">Never</span>' ?></td></tr>
      </tbody>
    </table>
  </section>

  <section class="admin-section">
    <h2>Subject &amp; Courses</h2>
    <p class="muted-meta">Changing the subject clears the existing course selection.</p>

    <form method="POST">
      <input type="hidden" name="action" value="update_courses">

      <label style="display:block; margin-bottom:14px;">
        <span style="font-weight:600; display:block; margin-bottom:6px;">Subject</span>
        <select name="subject_id" onchange="this.form.submit()" style="padding:8px 12px; border-radius:6px; border:1px solid #c7c7cc; min-width:280px;">
          <option value="0">— No subject —</option>
          <?php foreach ($subjects as $sub): ?>
            <option value="<?= e($sub['id']) ?>" <?= $user['subject_id'] == $sub['id'] ? 'selected' : '' ?>>
              <?= e($sub['code']) ?> — <?= e($sub['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php if ($user['subject_id'] && $courses): ?>
        <div style="font-weight:600; margin-bottom:8px;">Enrolled Courses</div>
        <div class="course-list" style="margin-bottom:14px;">
          <?php foreach ($courses as $c): ?>
            <label class="course-option">
              <input type="checkbox" name="course_ids[]" value="<?= e($c['id']) ?>"
                     <?= in_array($c['id'], $enrolled_ids) ? 'checked' : '' ?>>
              <div class="course-info">
                <span class="course-code"><?= e($c['code']) ?></span>
                <span class="course-name"><?= e($c['name']) ?></span>
                <?php if (!empty($c['instructor'])): ?>
                  <span class="course-instructor"><?= e($c['instructor']) ?></span>
                <?php endif; ?>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
      <?php elseif ($user['subject_id']): ?>
        <p class="muted-meta">No courses available in this subject.</p>
      <?php else: ?>
        <p class="muted-meta">Pick a subject above to assign courses.</p>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary">Save Courses</button>
    </form>
  </section>

  <section class="admin-section">
    <h2>One-Click Access Link</h2>
    <p class="muted-meta">
      Share this link only with the account owner. For self-registered users, it opens
      Profile and asks them to change their password. The link stops working after they
      save a new password. Regenerating the link revokes the previous one.
    </p>

    <?php if ($auto_login_url): ?>
      <div style="display:flex; gap:8px; align-items:center; margin:12px 0;">
        <input type="text" id="loginLinkInput" value="<?= e($auto_login_url) ?>" readonly
               style="flex:1; padding:8px 12px; border:1px solid #c7c7cc; border-radius:6px; font-family:monospace; font-size:0.85rem; background:#f5f5f7;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="copyLoginLink()">Copy</button>
      </div>
      <script>
      function copyLoginLink() {
        const el = document.getElementById('loginLinkInput');
        el.select(); el.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(el.value).then(() => {
          const btn = event.target;
          const orig = btn.textContent;
          btn.textContent = '✓ Copied';
          setTimeout(() => btn.textContent = orig, 1400);
        });
      }
      </script>
    <?php else: ?>
      <p class="muted-meta">No login link has been generated yet.</p>
    <?php endif; ?>

    <div style="display:flex; gap:8px;">
      <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="regenerate_token">
        <button type="submit" class="btn btn-primary btn-sm">
          <?= $auto_login_url ? 'Regenerate Link' : 'Generate Link' ?>
        </button>
      </form>
      <?php if ($auto_login_url): ?>
      <form method="POST" style="display:inline;"
            onsubmit="return confirm('Revoke the current one-click access link? The user will no longer be able to use it.');">
        <input type="hidden" name="action" value="revoke_token">
        <button type="submit" class="btn btn-secondary btn-sm">Revoke Link</button>
      </form>
      <?php endif; ?>
    </div>
  </section>

</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
