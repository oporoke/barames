<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../includes/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/auth.php';
require_once '../../includes/settings.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    verifyCsrf();
    $fields = [
        'business_name','business_address','business_phone','business_email',
        'currency','currency_symbol','timezone','low_stock_notify',
        'session_timeout','receipt_footer'
    ];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            saveSetting($conn, $f, $_POST[$f]);
        }
    }
    auditLog($conn, 'settings_update', 'System settings updated');
    $_SESSION['flash'] = ['msg' => '&#10003; Settings saved.', 'type' => 'success'];
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}

$s = getAllSettings($conn);

// Database stats
$statsQueries = [
    'Total Sales Records'    => "SELECT COUNT(*) AS c FROM sales",
    'Total Expense Records'  => "SELECT COUNT(*) AS c FROM expenses",
    'Total Stock Items'      => "SELECT COUNT(*) AS c FROM stock_items WHERE is_active=1",
    'Total Users'            => "SELECT COUNT(*) AS c FROM users WHERE is_active=1",
    'Audit Log Entries'      => "SELECT COUNT(*) AS c FROM audit_log",
    'Purchase Orders'        => "SELECT COUNT(*) AS c FROM purchase_orders",
];
$stats = [];
foreach ($statsQueries as $label => $q) {
    $stats[$label] = $conn->query($q)->fetch_assoc()['c'];
}

require_once '../../includes/header.php';
?>
<h1 class="page-title">&#9881; System Settings</h1>

<?php if(!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
<div class="alert alert-<?= $f['type'] ?>"><?= $f['msg'] ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;flex-wrap:wrap;">

<div>
<!-- BUSINESS SETTINGS -->
<div class="card">
  <h3>Business Information</h3>
  <form method="POST" action="">
    <?= csrfField() ?>
    <input type="hidden" name="save_settings" value="1">
    <div class="form-grid">
      <div class="form-group"><label>Business Name</label>
        <input type="text" name="business_name" value="<?= htmlspecialchars($s['business_name']??'') ?>">
      </div>
      <div class="form-group"><label>Phone</label>
        <input type="text" name="business_phone" value="<?= htmlspecialchars($s['business_phone']??'') ?>">
      </div>
      <div class="form-group"><label>Email</label>
        <input type="email" name="business_email" value="<?= htmlspecialchars($s['business_email']??'') ?>">
      </div>
      <div class="form-group"><label>Address</label>
        <input type="text" name="business_address" value="<?= htmlspecialchars($s['business_address']??'') ?>">
      </div>
      <div class="form-group"><label>Currency Code</label>
        <input type="text" name="currency" value="<?= htmlspecialchars($s['currency']??'TZS') ?>" maxlength="10">
      </div>
      <div class="form-group"><label>Currency Symbol</label>
        <input type="text" name="currency_symbol" value="<?= htmlspecialchars($s['currency_symbol']??'TZS') ?>" maxlength="10">
      </div>
      <div class="form-group"><label>Timezone</label>
        <select name="timezone">
          <?php
          $tzs = ['Africa/Dar_es_Salaam','Africa/Nairobi','Africa/Kampala','Africa/Kigali','Africa/Lusaka','Africa/Harare','UTC'];
          foreach ($tzs as $tz): ?>
          <option value="<?= $tz ?>" <?= ($s['timezone']??'')===$tz?'selected':'' ?>><?= $tz ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Session Timeout (seconds)</label>
        <input type="number" name="session_timeout" value="<?= htmlspecialchars($s['session_timeout']??'3600') ?>" min="300">
        <span class="form-hint">3600 = 1 hour, 1800 = 30 min</span>
      </div>
      <div class="form-group" style="grid-column:1/-1;"><label>Receipt Footer Message</label>
        <input type="text" name="receipt_footer" value="<?= htmlspecialchars($s['receipt_footer']??'Thank you!') ?>">
      </div>
    </div>
    <br>
    <button type="submit" class="btn btn-primary"><span class="btn-text">&#10003; Save Settings</span><span class="spinner"></span></button>
  </form>
</div>

<!-- DATABASE BACKUP -->
<div class="card">
  <h3>Database Backup</h3>
  <p style="color:var(--muted);font-size:.9rem;margin-bottom:16px;">
    Downloads a full SQL backup of all tables and data. Store it somewhere safe.
  </p>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a href="backup.php" class="btn btn-success">&#128190; Download Backup Now</a>
    <a href="../audit/" class="btn btn-secondary">&#128203; View Audit Log</a>
  </div>
  <div style="margin-top:16px;padding:12px;background:#f8f9fa;border-radius:6px;font-size:.85rem;color:var(--muted);">
    <strong>Backup includes:</strong> all sales, expenses, stock, users, settings, audit log, purchase orders, suppliers.
  </div>
</div>
</div>

<!-- SYSTEM STATS SIDEBAR -->
<div>
<div class="card">
  <h3>Database Statistics</h3>
  <div style="display:flex;flex-direction:column;gap:10px;">
    <?php foreach ($stats as $label => $count): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
      <span style="font-size:.875rem;color:var(--muted);"><?= $label ?></span>
      <strong><?= number_format($count) ?></strong>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <h3>System Information</h3>
  <div style="font-size:.85rem;display:flex;flex-direction:column;gap:8px;">
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
      <span style="color:var(--muted);">PHP Version</span><strong><?= phpversion() ?></strong>
    </div>
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
      <span style="color:var(--muted);">MySQL Version</span><strong><?= $conn->server_info ?></strong>
    </div>
    <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
      <span style="color:var(--muted);">Server Time</span><strong><?= date('d M Y H:i') ?></strong>
    </div>
    <div style="display:flex;justify-content:space-between;">
      <span style="color:var(--muted);">App Version</span><strong>1.0.0</strong>
    </div>
  </div>
</div>
</div>

</div>
<?php require_once '../../includes/footer.php'; ?>
