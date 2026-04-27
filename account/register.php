<?php
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/functions.php';

startSessionIfNeeded();

if (isLoggedIn()) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

$pageTitle = 'Register — User Study';
$consent  = loadJsonConfig('consent.json');
$subjects = getSubjects();
$errors = [];
$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);

$reg = $_SESSION['registration'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($step === 1) {
        if (empty($_POST['consent_agreed'])) {
            $errors[] = 'You must agree to the consent form to participate.';
        } else {
            $reg['consent_agreed']    = true;
            $reg['consent_version']   = $consent['version'];
            $reg['consent_timestamp'] = date('Y-m-d H:i:s');
            $_SESSION['registration'] = $reg;
            header('Location: ?step=2');
            exit;
        }

    } elseif ($step === 2) {
        $username = trim($_POST['username'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (!isValidUsername($username)) {
            $errors[] = 'Username must be 3-50 characters: letters, numbers, underscore, dash.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address, or leave it blank.';
        }
        if (strlen($password) < 4) {
            $errors[] = 'Password must be at least 4 characters.';
        } elseif (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one letter and one number.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $stmt = getDb()->prepare('
                SELECT id, username, email
                FROM users
                WHERE username = ? OR (? <> "" AND email = ?)
                LIMIT 1
            ');
            $stmt->execute([$username, $email, $email]);
            $existing = $stmt->fetch();
            if ($existing && $existing['username'] === $username) {
                $errors[] = 'That username is already taken. Please choose another.';
            } elseif ($existing) {
                $errors[] = 'That email is already registered. Try signing in instead.';
            } else {
                $reg['username']      = $username;
                $reg['email']         = $email !== '' ? $email : null;
                $reg['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                $_SESSION['registration'] = $reg;
                header('Location: ?step=3');
                exit;
            }
        }

    } elseif ($step === 3) {
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $stmt = getDb()->prepare('SELECT id FROM subjects WHERE id = ?');
        $stmt->execute([$subject_id]);
        if (!$stmt->fetch()) {
            $errors[] = 'Please select a valid subject.';
        } elseif (empty($_POST['subject_confirmed'])) {
            $errors[] = 'Please confirm that your subject area cannot be changed later.';
        } else {
            $reg['subject_id'] = $subject_id;
            $_SESSION['registration'] = $reg;
            header('Location: ?step=4');
            exit;
        }

    } elseif ($step === 4) {
        $course_ids = $_POST['course_ids'] ?? [];
        if (!is_array($course_ids) || empty($course_ids)) {
            $errors[] = 'Please select at least one course.';
        } else {
            $course_ids = array_map('intval', $course_ids);
            $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
            $stmt = getDb()->prepare("SELECT id FROM courses WHERE id IN ($placeholders) AND subject_id = ?");
            $stmt->execute(array_merge($course_ids, [$reg['subject_id']]));
            $valid_ids = array_column($stmt->fetchAll(), 'id');

            if (empty($valid_ids)) {
                $errors[] = 'Selected courses are invalid.';
            } else {
                $pdo = getDb();
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('
                        INSERT INTO users (username, email, password_hash, subject_id, account_type, consent_given, consent_version, consent_timestamp)
                        VALUES (?, ?, ?, ?, "self_registered", TRUE, ?, ?)
                    ');
                    $stmt->execute([
                        $reg['username'],
                        $reg['email'] ?? null,
                        $reg['password_hash'],
                        $reg['subject_id'],
                        $reg['consent_version'],
                        $reg['consent_timestamp']
                    ]);
                    $user_id = $pdo->lastInsertId();

                    $stmt = $pdo->prepare('INSERT INTO user_courses (user_id, course_id) VALUES (?, ?)');
                    foreach ($valid_ids as $cid) {
                        $stmt->execute([$user_id, $cid]);
                    }

                    $pdo->commit();

                    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                    $stmt->execute([$user_id]);
                    $newUser = $stmt->fetch();
                    loginUser($newUser);

                    unset($_SESSION['registration']);
                    setFlash('success', 'Welcome! Your account has been created.');
                    header('Location: ' . baseUrl('dashboard.php'));
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = 'Registration failed: ' . $e->getMessage();
                }
            }
        }
    }
}

if ($step >= 2 && empty($reg['consent_agreed'])) { header('Location: ?step=1'); exit; }
if ($step >= 3 && empty($reg['username']))       { header('Location: ?step=2'); exit; }
if ($step >= 4 && empty($reg['subject_id']))     { header('Location: ?step=3'); exit; }

include __DIR__ . '/../app/includes/header.php';
?>

<div class="register-wrapper">
  <div class="register-card">

    <div class="step-indicator">
      <?php foreach ([1=>'Consent', 2=>'Account', 3=>'Subject', 4=>'Courses'] as $s => $label): ?>
        <div class="step <?= $step === $s ? 'active' : ($step > $s ? 'done' : '') ?>">
          <span class="step-num"><?= $s ?></span>
          <span class="step-label"><?= $label ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
      <h2><?= e($consent['title']) ?></h2>
      <p class="muted-meta">Study: <?= e($consent['study_name']) ?> · Version <?= e($consent['version']) ?></p>

      <div class="consent-content">
        <?php foreach ($consent['sections'] as $sec): ?>
          <h3><?= e($sec['heading']) ?></h3>
          <p><?= e($sec['text']) ?></p>
        <?php endforeach; ?>
      </div>

      <form method="POST" class="auth-form">
        <input type="hidden" name="step" value="1">
        <label class="checkbox-row">
          <input type="checkbox" name="consent_agreed" required>
          <span><?= e($consent['agreement_label']) ?></span>
        </label>
        <div class="form-actions">
          <a href="<?= baseUrl('index.php') ?>" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Continue →</button>
        </div>
      </form>

    <?php elseif ($step === 2): ?>
      <h2>Create Your Account</h2>

      <form method="POST" class="auth-form" id="registerForm">
        <input type="hidden" name="step" value="2">
        <label>
          <span>Username <span class="required-mark">*</span></span>
          <input type="text" name="username" value="<?= e($_POST['username'] ?? $reg['username'] ?? '') ?>" required minlength="3" maxlength="50" pattern="[a-zA-Z0-9_-]+">
          <small>3–50 chars · letters, numbers, _ -</small>
        </label>
        <label>
          <span>Email <span class="field-optional">(optional)</span></span>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? $reg['email'] ?? '') ?>" maxlength="255" placeholder="your@email.com">
          <small>Can be used for sign-in and account recovery.</small>
        </label>
        <label>
          <span>Password <span class="required-mark">*</span></span>
          <input type="password" name="password" id="regPassword" required minlength="4">
          <small>At least 4 characters · must include one letter and one number</small>
        </label>
        <label>
          <span>Confirm Password <span class="required-mark">*</span></span>
          <input type="password" name="password_confirm" id="regConfirm" required minlength="4">
        </label>

        <div class="form-actions">
          <a href="?step=1" class="btn btn-secondary">← Back</a>
          <button type="submit" class="btn btn-primary">Continue →</button>
        </div>
      </form>
      <script>
      document.getElementById('registerForm').addEventListener('submit', function(e) {
        var pw = document.getElementById('regPassword').value;
        var cn = document.getElementById('regConfirm').value;
        if (pw.length < 4) { alert('Password must be at least 4 characters.'); e.preventDefault(); return; }
        if (!/[a-zA-Z]/.test(pw) || !/[0-9]/.test(pw)) { alert('Password must contain at least one letter and one number.'); e.preventDefault(); return; }
        if (pw !== cn) { alert('Passwords do not match.'); e.preventDefault(); }
      });
      </script>

    <?php elseif ($step === 3): ?>
      <h2>Select Your Subject Area</h2>
      <p class="muted-meta">Choose one subject. This will determine which courses you can select next.</p>

      <form method="POST" class="auth-form">
        <input type="hidden" name="step" value="3">
        <div class="subject-grid">
          <?php foreach ($subjects as $sub): ?>
            <label class="subject-option">
              <input type="radio" name="subject_id" value="<?= e($sub['id']) ?>" required>
              <div class="subject-card">
                <span class="subject-code"><?= e($sub['code']) ?></span>
                <span class="subject-name"><?= e($sub['name']) ?></span>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <label class="checkbox-row">
          <input type="checkbox" name="subject_confirmed" required>
          <span>I understand that my subject area cannot be changed later.</span>
        </label>
        <div class="form-actions">
          <a href="?step=2" class="btn btn-secondary">← Back</a>
          <button type="submit" class="btn btn-primary">Continue →</button>
        </div>
      </form>

    <?php elseif ($step === 4): ?>
      <?php $courses = getCoursesBySubject($reg['subject_id']); ?>
      <h2>Select Your Courses</h2>
      <p class="muted-meta">Select all the courses you are taking or have taken in this subject.</p>

      <form method="POST" class="auth-form">
        <input type="hidden" name="step" value="4">
        <div class="course-list compact-course-list">
          <?php foreach ($courses as $course): ?>
            <label class="course-option">
              <input type="checkbox" name="course_ids[]" value="<?= e($course['id']) ?>">
              <div class="course-info">
                <span class="course-code"><?= e($course['code']) ?></span>
                <span class="course-name"><?= e($course['name']) ?></span>
              </div>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="form-actions">
          <a href="?step=3" class="btn btn-secondary">← Back</a>
          <button type="submit" class="btn btn-primary">Complete Registration ✓</button>
        </div>
      </form>

    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../app/includes/footer.php'; ?>
