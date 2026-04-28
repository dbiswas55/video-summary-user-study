function togglePw(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  if (el.type === 'password') {
    el.type = 'text';
    btn.textContent = 'Hide';
  } else {
    el.type = 'password';
    btn.textContent = 'Show';
  }
}
