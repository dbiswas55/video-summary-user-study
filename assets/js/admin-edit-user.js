function copyLoginLink() {
  var el = document.getElementById('loginLinkInput');
  if (!el) return;

  el.select();
  el.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(el.value).then(function() {
    var btn = document.getElementById('copyLoginLinkBtn');
    if (!btn) return;
    var original = btn.textContent;
    btn.textContent = '✓ Copied';
    setTimeout(function() {
      btn.textContent = original;
    }, 1400);
  });
}
