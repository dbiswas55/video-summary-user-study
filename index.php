<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

$pageTitle = 'Video Summary Evaluation';
$pageStyles = ['assets/css/auth.css'];
$pageScripts = [
    'assets/js/common.js',
    'assets/js/auth.js',
];
$activeTab = $_GET['tab'] ?? 'login';

startSessionIfNeeded();
$loginError = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);
$prefillUsername = $_SESSION['prefill_username'] ?? '';
unset($_SESSION['prefill_username']);

include __DIR__ . '/app/includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-header">
      <h1>Video Detailed Summary</h1>
      <p class="subtitle">Comparative Evaluation Study · VideoPoints</p>
    </div>

    <div class="tabs">
      <button class="tab <?= $activeTab === 'login' ? 'active' : '' ?>" data-tab="login" onclick="switchTab('login')">Sign In</button>
      <button class="tab <?= $activeTab === 'register' ? 'active' : '' ?>" data-tab="register" onclick="switchTab('register')">Register</button>
    </div>

    <div id="loginPanel" class="tab-panel <?= $activeTab === 'login' ? 'active' : '' ?>">
      <?php if ($loginError): ?>
        <div class="alert alert-error"><?= e($loginError) ?></div>
      <?php endif; ?>
      <p class="auth-hint auth-hint-spaced">
        Sign in with your username or email and password.
        Pre-issued accounts without a password may use username and email, or a one-click access link.
      </p>
      <form action="<?= baseUrl('account/login.php') ?>" method="POST" class="auth-form" id="loginForm">
        <label>
          <span>Username <span class="field-optional">(or email below)</span></span>
          <input type="text" name="username" id="loginUsername" value="<?= e($prefillUsername) ?>" autocomplete="username">
        </label>
        <label>
          <span>Email <span class="field-optional">(or username above)</span></span>
          <input type="email" name="email" id="loginEmail" autocomplete="email">
        </label>
        <label>
          <span>Password <span class="field-optional">(required for regular sign-in)</span></span>
          <div class="pw-wrap">
            <input type="password" name="password" id="loginPassword" autocomplete="current-password">
            <button type="button" class="pw-toggle" onclick="togglePw('loginPassword',this)" tabindex="-1">Show</button>
          </div>
        </label>
        <p class="auth-hint login-hint" id="loginHint"></p>
        <button type="submit" class="btn btn-primary">Sign In</button>
      </form>
      <p class="auth-hint">Don't have an account? <a href="?tab=register" onclick="event.preventDefault(); switchTab('register')">Register here</a></p>
      <p class="auth-hint">
        <a href="#" onclick="event.preventDefault(); toggleResetForm()">Need a one-click access link?</a>
      </p>
      <form action="<?= baseUrl('account/forgot_password.php') ?>" method="POST" class="auth-form reset-form" id="resetForm">
        <label>
          <span>One-Click Access</span>
          <input type="email" name="reset_email" autocomplete="email" placeholder="Enter your account email">
          <small>We can send a sign-in link if this email is on your account.</small>
        </label>
        <button type="submit" class="btn btn-secondary">Send Access Link</button>
      </form>
    </div>

    <div id="registerPanel" class="tab-panel <?= $activeTab === 'register' ? 'active' : '' ?>">
      <p class="auth-hint">To register, you will be guided through account setup and course selection.</p>
      <a href="<?= baseUrl('account/register.php') ?>" class="btn btn-primary btn-block">Begin Registration</a>
      <p class="auth-hint">Already registered? <a href="?tab=login" onclick="event.preventDefault(); switchTab('login')">Sign in here</a></p>
    </div>
  </div>
</div>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
