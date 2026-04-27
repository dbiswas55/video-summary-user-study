<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . baseUrl('dashboard.php'));
    exit;
}

$pageTitle = 'Video Summary Evaluation';
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
      <button class="tab <?= $activeTab === 'login' ? 'active' : '' ?>" onclick="switchTab('login')">Sign In</button>
      <button class="tab <?= $activeTab === 'register' ? 'active' : '' ?>" onclick="switchTab('register')">Register</button>
    </div>

    <div id="loginPanel" class="tab-panel <?= $activeTab === 'login' ? 'active' : '' ?>">
      <?php if ($loginError): ?>
        <div class="alert alert-error"><?= e($loginError) ?></div>
      <?php endif; ?>
      <p class="auth-hint" style="margin-bottom:14px;">
        Sign in with your username or email and password.
        Pre-issued accounts without a password may use username and email.
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
        <p class="auth-hint" id="loginHint" style="min-height:1.4em;"></p>
        <button type="submit" class="btn btn-primary">Sign In</button>
      </form>
      <p class="auth-hint">Don't have an account? <a href="?tab=register" onclick="event.preventDefault(); switchTab('register')">Register here</a></p>
      <p class="auth-hint">
        <a href="#" onclick="event.preventDefault(); toggleResetForm()">Forgot password?</a>
      </p>
      <form action="<?= baseUrl('account/forgot_password.php') ?>" method="POST" class="auth-form reset-form" id="resetForm">
        <label>
          <span>Password Help</span>
          <input type="email" name="reset_email" autocomplete="email" placeholder="Enter your account email">
          <small>We can send a one-click access link if this email is on your account.</small>
        </label>
        <button type="submit" class="btn btn-secondary">Send Access Link</button>
      </form>
    </div>

    <div id="registerPanel" class="tab-panel <?= $activeTab === 'register' ? 'active' : '' ?>">
      <p class="auth-hint">To register, you will be guided through a brief consent form, account setup, and course selection.</p>
      <a href="<?= baseUrl('account/register.php') ?>" class="btn btn-primary btn-block">Begin Registration</a>
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

function togglePw(id, btn) {
  var el = document.getElementById(id);
  if (el.type === 'password') { el.type = 'text'; btn.textContent = 'Hide'; }
  else                        { el.type = 'password'; btn.textContent = 'Show'; }
}

function toggleResetForm() {
  var form = document.getElementById('resetForm');
  if (!form) return;
  form.classList.toggle('open');
  if (form.classList.contains('open')) {
    var input = form.querySelector('input[name="reset_email"]');
    if (input) input.focus();
  }
}

(function() {
  var u = document.getElementById('loginUsername');
  var e = document.getElementById('loginEmail');
  var p = document.getElementById('loginPassword');
  var hint = document.getElementById('loginHint');
  var form = document.getElementById('loginForm');
  if (!form) return;

  function updateHint() {
    var hu = u.value.trim() !== '', he = e.value.trim() !== '', hp = p.value !== '';
    var count = (hu ? 1 : 0) + (he ? 1 : 0) + (hp ? 1 : 0);
    if (count === 0) { hint.textContent = ''; return; }
    if (hp && (hu || he)) { hint.textContent = ''; return; }
    if (!hp && hu && he) {
      hint.textContent = 'Passwordless sign-in is only for pre-issued accounts without a password.';
      return;
    }
    hint.textContent = 'Enter a username or email plus password.';
  }
  [u, e, p].forEach(function(el) { el.addEventListener('input', updateHint); });

  form.addEventListener('submit', function(ev) {
    var hu = u.value.trim() !== '', he = e.value.trim() !== '', hp = p.value !== '';
    var standardLogin = hp && (hu || he);
    var preissuedLogin = !hp && hu && he;
    if (!standardLogin && !preissuedLogin) {
      ev.preventDefault();
      hint.textContent = 'Enter a username or email plus password. Pre-issued accounts without a password may use username and email.';
    }
  });
})();
</script>

<?php include __DIR__ . '/app/includes/footer.php'; ?>
