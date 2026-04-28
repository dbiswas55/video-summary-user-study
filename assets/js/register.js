(function() {
  var form = document.getElementById('registerForm');
  if (!form) return;

  form.addEventListener('submit', function(e) {
    var pw = document.getElementById('regPassword').value;
    var cn = document.getElementById('regConfirm').value;
    if (pw.length < 4) {
      alert('Password must be at least 4 characters.');
      e.preventDefault();
      return;
    }
    if (!/[a-zA-Z]/.test(pw) || !/[0-9]/.test(pw)) {
      alert('Password must contain at least one letter and one number.');
      e.preventDefault();
      return;
    }
    if (pw !== cn) {
      alert('Passwords do not match.');
      e.preventDefault();
    }
  });
})();
