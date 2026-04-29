<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

requireLogin();

$pdo = getDb();
$stmt = $pdo->prepare('SELECT id, username, email, password_hash, subject_id, login_token FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Set / change password without requiring the current password ─────────
    if ($action === 'update_password') {
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 4) {
            $errors[] = 'Password must be at least 4 characters.';
        } elseif (!preg_match('/[a-zA-Z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $errors[] = 'Password must contain at least one letter and one number.';
        } elseif ($new !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$hash, $user['id']]);
            $success = 'Password saved. You can now sign in with your new password.';
            $user['password_hash'] = $hash;
        }

    // ── Set email when the account does not have one yet ─────────────────────
    } elseif ($action === 'set_email') {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!empty($user['email'])) {
            $errors[] = 'Email is already set for this account.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch()) {
                $errors[] = 'That email address is already registered.';
            } else {
                $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')
                    ->execute([$email, $user['id']]);
                $success = 'Email address saved.';
                $user['email'] = $email;
            }
        }

    // ── Change enrolled courses within the user's current subject ────────────
    } elseif ($action === 'update_courses') {
        if (empty($user['subject_id'])) {
            $errors[] = 'No subject is assigned to your account.';
        } else {
            $course_ids = $_POST['course_ids'] ?? [];
            if (!is_array($course_ids) || empty($course_ids)) {
                $errors[] = 'Please select at least one course.';
            } else {
                $course_ids = array_values(array_unique(array_map('intval', $course_ids)));
                $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
                $stmt = $pdo->prepare("SELECT id FROM courses WHERE id IN ($placeholders) AND subject_id = ?");
                $stmt->execute(array_merge($course_ids, [$user['subject_id']]));
                $valid_ids = array_column($stmt->fetchAll(), 'id');

                if (count($valid_ids) !== count($course_ids)) {
                    $errors[] = 'Selected courses are invalid for your subject.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM user_courses WHERE user_id = ?')
                            ->execute([$user['id']]);
                        $stmt = $pdo->prepare('INSERT INTO user_courses (user_id, course_id) VALUES (?, ?)');
                        foreach ($valid_ids as $cid) {
                            $stmt->execute([$user['id'], $cid]);
                        }
                        $pdo->commit();
                        $success = 'Course selection updated.';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $errors[] = 'Could not update courses. Please try again.';
                    }
                }
            }
        }
    }
}

$hasPassword = $user['password_hash'] !== '';
$subject = null;
$courses = [];
$enrolled_ids = [];

if (!empty($user['subject_id'])) {
    $stmt = $pdo->prepare('SELECT id, code, name FROM subjects WHERE id = ?');
    $stmt->execute([$user['subject_id']]);
    $subject = $stmt->fetch();
    $courses = getCoursesBySubject($user['subject_id']);
    $enrolled_ids = array_column(getUserCourses($user['id']), 'id');
}

$pageTitle   = 'My Profile — User Study';
$pageStyles = ['assets/css/profile.css'];
$pageScripts = ['assets/js/common.js'];
include __DIR__ . '/../app/includes/header.php';
?>

<div class="profile-wrap">
  <h1 class="profile-title">My Profile</h1>

  <?php if ($errors): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>
  <?php if (!$hasPassword): ?>
    <div class="alert flash-info">
      You can add a password below if you also want to sign in with a username or email and password.
    </div>
  <?php endif; ?>

  <!-- Account info -->
  <section class="profile-section">
    <h2>Account Information</h2>
    <table class="profile-table">
      <tr><th>Username</th><td><?= e($user['username']) ?></td></tr>
      <tr><th>Email</th><td><?= e($user['email'] ?? '—') ?></td></tr>
      <tr><th>Password</th><td><?= $hasPassword ? '<span class="status-yes">Set</span>' : '<span class="status-no">Not set</span>' ?></td></tr>
      <tr><th>Subject Area</th><td><?= $subject ? e($subject['name']) : '—' ?></td></tr>
    </table>
  </section>

  <?php if (empty($user['email'])): ?>
  <section class="profile-section">
    <h2>Set Email Address</h2>
    <form method="POST" class="auth-form profile-form">
      <input type="hidden" name="action" value="set_email">
      <label>
        <span>Email <span class="required-mark">*</span></span>
        <input type="email" name="email" required maxlength="255" autocomplete="email" placeholder="your@email.com">
        <small>Can be used for sign-in and account recovery.</small>
      </label>
      <button type="submit" class="btn btn-primary">Save Email</button>
    </form>
  </section>
  <?php endif; ?>

  <section class="profile-section">
    <h2><?= $hasPassword ? 'Change Password' : 'Set a Password' ?></h2>
    <form method="POST" class="auth-form profile-form">
      <input type="hidden" name="action" value="update_password">
      <label>
        <span>New Password <span class="required-mark">*</span></span>
        <div class="pw-wrap">
          <input type="password" name="new_password" id="newPassword" required minlength="4" autocomplete="new-password">
          <button type="button" class="pw-toggle" onclick="togglePw('newPassword',this)" tabindex="-1">Show</button>
        </div>
        <small>At least 4 characters · must include one letter and one number</small>
      </label>
      <label>
        <span>Confirm New Password <span class="required-mark">*</span></span>
        <div class="pw-wrap">
          <input type="password" name="confirm_password" id="confirmPassword" required minlength="4" autocomplete="new-password">
          <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword',this)" tabindex="-1">Show</button>
        </div>
      </label>
      <button type="submit" class="btn btn-primary"><?= $hasPassword ? 'Change Password' : 'Set Password' ?></button>
    </form>
  </section>

  <section class="profile-section">
    <h2>Courses</h2>
    <?php if ($subject && $courses): ?>
      <p class="muted-meta">Subject: <strong><?= e($subject['name']) ?></strong></p>
      <form method="POST" class="auth-form">
        <input type="hidden" name="action" value="update_courses">
        <div class="course-list compact-course-list">
          <?php foreach ($courses as $course): ?>
            <label class="course-option">
              <input type="checkbox" name="course_ids[]" value="<?= e($course['id']) ?>"
                     <?= in_array($course['id'], $enrolled_ids) ? 'checked' : '' ?>>
              <div class="course-info">
                <span class="course-code"><?= e($course['code']) ?></span>
                <span class="course-name"><?= e($course['name']) ?></span>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary">Save Courses</button>
      </form>
    <?php elseif ($subject): ?>
      <p class="muted-meta">No courses are available for your subject yet.</p>
    <?php else: ?>
      <p class="muted-meta">No subject is assigned to your account.</p>
    <?php endif; ?>
  </section>

</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
