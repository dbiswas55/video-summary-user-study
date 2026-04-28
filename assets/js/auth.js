function switchTab(name) {
  document.querySelectorAll('.tab').forEach(function(tab) {
    tab.classList.remove('active');
  });
  document.querySelectorAll('.tab-panel').forEach(function(panel) {
    panel.classList.remove('active');
  });

  var tab = document.querySelector('.tab[data-tab="' + name + '"]');
  var panel = document.getElementById(name + 'Panel');
  if (tab) tab.classList.add('active');
  if (panel) panel.classList.add('active');
  history.replaceState(null, '', '?tab=' + name);
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
  if (!form || !u || !e || !p || !hint) return;

  function updateHint() {
    var hu = u.value.trim() !== '';
    var he = e.value.trim() !== '';
    var hp = p.value !== '';
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
    var hu = u.value.trim() !== '';
    var he = e.value.trim() !== '';
    var hp = p.value !== '';
    var standardLogin = hp && (hu || he);
    var preissuedLogin = !hp && hu && he;
    if (!standardLogin && !preissuedLogin) {
      ev.preventDefault();
      hint.textContent = 'Enter a username or email plus password. Pre-issued accounts without a password may use username and email.';
    }
  });
})();
