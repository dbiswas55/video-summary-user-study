<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

$pageTitle = 'Welcome — User Study';
$activeTab = $_GET['tab'] ?? 'login';

startSessionIfNeeded();
$loginError = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
$prefillUsername = $_SESSION['prefill_username'] ?? '';
unset($_SESSION['prefill_username']);

include __DIR__ . '/includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-header">
      <h1>Video Detailed Summary</h1>
      <p class="subtitle">Comparative Evaluation Study — <?= e($study['semester']) ?></p>
    </div>

    <div class="tabs">
      <button class="tab <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
      <button class="tab <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Register</button>
    </div>

    <div id="loginPanel" class="tab-panel <?= $activeTab === 'login' ? 'active' : '' ?>">
      <?php if ($loginError): ?>
        <div class="alert alert-error"><?= e($loginError) ?></div>
      <?php endif; ?>
      <form action="<?= baseUrl('login.php') ?>" method="POST" class="auth-form">
        <label>
          <span>Username</span>
          <input type="text" name="username" value="<?= e($prefillUsername) ?>" required autofocus>
        </label>
        <label>
          <span>Password</span>
          <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary">Sign In</button>
      </form>
      <p class="auth-hint">Don't have an account? <a href="?tab=register" onclick="event.preventDefault(); switchTab('register')">Register here</a></p>
    </div>

    <div id="registerPanel" class="tab-panel <?= $activeTab === 'register' ? 'active' : '' ?>">
      <p class="auth-hint">To register, you will be guided through a brief consent form, account setup, and course selection.</p>
      <a href="<?= baseUrl('register.php') ?>" class="btn btn-primary btn-block">Begin Registration</a>
      <p class="auth-hint">Already registered? <a href="?tab=login" onclick="event.preventDefault(); switchTab('login')">Sign in here</a></p>
    </div>
  </div>
</div>

<script>
function switchTab(name) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelector(`.tab[onclick*="${name}"]`).classList.add('active');
  document.getElementById(name + 'Panel').classList.add('active');
  history.replaceState(null, '', '?tab=' + name);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
