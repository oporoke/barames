</div><!-- /container -->

<footer>
  <p>Bar POS System &copy; <?= date('Y') ?> &mdash; All rights reserved</p>
</footer>

<script>
// â”€â”€ CONFIRM MODAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function showConfirm(title, message, onConfirm) {
  document.getElementById('confirmTitle').textContent   = title;
  document.getElementById('confirmMessage').textContent = message;
  document.getElementById('confirmBtn').onclick = function() {
    closeConfirm();
    onConfirm();
  };
  document.getElementById('confirmModal').classList.add('active');
}
function closeConfirm() {
  document.getElementById('confirmModal').classList.remove('active');
}
document.getElementById('confirmModal').addEventListener('click', function(e) {
  if (e.target === this) closeConfirm();
});

// â”€â”€ FORM SUBMIT LOADING STATE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('form').forEach(function(form) {
  form.addEventListener('submit', function(e) {
    var btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    // Don't lock filter forms (GET forms)
    if (form.method.toLowerCase() === 'get') return;
    btn.classList.add('loading');
    btn.disabled = true;
    // Safety re-enable after 5s
    setTimeout(function() {
      btn.classList.remove('loading');
      btn.disabled = false;
    }, 5000);
  });
});

// â”€â”€ LIVE TABLE SEARCH â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function initSearch(inputId, tableId) {
  var input = document.getElementById(inputId);
  var table = document.getElementById(tableId);
  if (!input || !table) return;
  input.addEventListener('input', function() {
    var q = this.value.toLowerCase();
    var rows = table.querySelectorAll('tbody tr');
    var visible = 0;
    rows.forEach(function(row) {
      var match = row.textContent.toLowerCase().includes(q);
      row.style.display = match ? '' : 'none';
      if (match) visible++;
    });
    var empty = table.querySelector('.search-empty');
    if (!empty) {
      empty = document.createElement('tr');
      empty.className = 'search-empty';
      empty.innerHTML = '<td colspan="99" style="text-align:center;color:#888;padding:20px;">No results found.</td>';
      table.querySelector('tbody').appendChild(empty);
    }
    empty.style.display = visible === 0 ? '' : 'none';
  });
}

// â”€â”€ AUTO-DISMISS ALERTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.alert').forEach(function(alert) {
  setTimeout(function() {
    alert.style.transition = 'opacity .5s';
    alert.style.opacity = '0';
    setTimeout(function() { alert.remove(); }, 500);
  }, 4000);
});

// â”€â”€ CLOSE NAV ON LINK CLICK (mobile) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.querySelectorAll('.nav-links a').forEach(function(a) {
  a.addEventListener('click', function() {
    document.querySelector('.nav-links').classList.remove('open');
  });
});
</script>
</body>
</html>
