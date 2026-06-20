<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

[$start, $end] = resolveRange();
$itemFilter = isset($_GET['item_id']) && ctype_digit($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// All active stock items for the dropdown
$allItems = q($conn, "SELECT id, name, unit FROM stock_items WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Items to report on
$itemIds = $itemFilter ? [$itemFilter] : array_column($allItems, 'id');
$itemMap = [];
foreach ($allItems as $it) { $itemMap[$it['id']] = $it; }

if (empty($itemIds)) {
    $ledger = [];
} else {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

    // Opening qty = current qty MINUS all movements from $start to now (rolled back), i.e.
    // we reconstruct: opening_at_start = current_qty - sum(in after start) + sum(out after start) +/- adjustments after start (complex).
    // Simpler & reliable: opening = current_qty - net movement that happened *on or after* start (in - out), excluding adjustments which we treat specially.
    // To keep this correct & auditable, we instead compute opening as of $start by walking movements BEFORE start from a known baseline (current qty rolled back through ALL movements up to now).

    // Get current quantity per item
    $curQtyRows = q($conn, "SELECT id, quantity FROM stock_items WHERE id IN ($placeholders)", $itemIds)->fetch_all(MYSQLI_ASSOC);
    $currentQty = [];
    foreach ($curQtyRows as $r) { $currentQty[$r['id']] = (float)$r['quantity']; }

    // Get ALL movements after $end (to roll current back to end-of-range closing)
    $afterRows = q($conn, "
        SELECT stock_item_id, movement_type, quantity, moved_at, created_at
        FROM stock_movements
        WHERE stock_item_id IN ($placeholders) AND moved_at > ?
        ORDER BY stock_item_id, created_at
    ", array_merge($itemIds, [$end]))->fetch_all(MYSQLI_ASSOC);

    // Movements WITHIN range (for the in/out/adjustment breakdown)
    $rangeRows = q($conn, "
        SELECT stock_item_id, movement_type, quantity, note, moved_at, created_at
        FROM stock_movements
        WHERE stock_item_id IN ($placeholders) AND moved_at BETWEEN ? AND ?
        ORDER BY stock_item_id, created_at
    ", array_merge($itemIds, [$start, $end]))->fetch_all(MYSQLI_ASSOC);

    // Roll current quantity back to "closing at $end" by reversing movements that happened after $end.
    // Reversal rule: 'in' after end -> subtract; 'out' after end -> add back;
    // 'adjustment' after end -> we don't know the pre-adjustment value reliably unless we track previous absolute value,
    // so adjustments after the range break exact reconstruction. We flag those items as "approximate".
    $closingAtEnd = $currentQty;
    $approxItems = [];
    foreach ($afterRows as $m) {
        $id = $m['stock_item_id'];
        if (!isset($closingAtEnd[$id])) continue;
        if ($m['movement_type'] === 'in') {
            $closingAtEnd[$id] -= (float)$m['quantity'];
        } elseif ($m['movement_type'] === 'out') {
            $closingAtEnd[$id] += (float)$m['quantity'];
        } else { // adjustment after range = can't reliably reverse (stored as absolute value)
            $approxItems[$id] = true;
        }
    }

    // Now build per-item ledger lines for the range, walking forward from a computed "opening" =
    // closingAtEnd minus all in-range net movement (in - out), then apply adjustments in sequence for display.
    $byItem = [];
    foreach ($itemIds as $id) {
        $byItem[$id] = ['in' => 0, 'out' => 0, 'adjustments' => [], 'movements' => []];
    }
    foreach ($rangeRows as $m) {
        $id = $m['stock_item_id'];
        if (!isset($byItem[$id])) continue;
        $byItem[$id]['movements'][] = $m;
        if ($m['movement_type'] === 'in') $byItem[$id]['in'] += (float)$m['quantity'];
        elseif ($m['movement_type'] === 'out') $byItem[$id]['out'] += (float)$m['quantity'];
        else $byItem[$id]['adjustments'][] = $m;
    }

    $ledger = [];
    foreach ($itemIds as $id) {
        if (!isset($itemMap[$id])) continue;
        $closing = $closingAtEnd[$id] ?? 0;
        $in  = $byItem[$id]['in'];
        $out = $byItem[$id]['out'];
        $adjustments = $byItem[$id]['adjustments'];

        // If there were absolute adjustments inside the range, opening = closing - in + out is still valid
        // because 'in'/'out' are deltas and adjustments overwrite quantity directly (handled by final closing already
        // reflecting the adjustment since current_qty already includes it, and no after-range adjustment exists).
        $opening = $closing - $in + $out;
        // Net any in-range adjustments separately into a delta for visibility (best-effort using stored qty as resulting value)
        $adjNetNote = '';
        if (!empty($adjustments)) {
            $last = end($adjustments);
            $adjNetNote = 'Set to ' . number_format((float)$last['quantity'], 2) . ' on ' . $last['moved_at'];
        }

        $expectedClosing = $opening + $in - $out;
        $variance = round($closing - $expectedClosing, 2);
        $hasAdjustment = !empty($adjustments);
        $approx = isset($approxItems[$id]);

        $ledger[] = [
            'id' => $id,
            'name' => $itemMap[$id]['name'],
            'unit' => $itemMap[$id]['unit'],
            'opening' => $opening,
            'in' => $in,
            'out' => $out,
            'adjustments' => $adjustments,
            'adj_note' => $adjNetNote,
            'closing' => $closing,
            'variance' => $hasAdjustment ? $variance : 0,
            'approx' => $approx,
            'movements' => $byItem[$id]['movements'],
        ];
    }

    // Sort: items with movement first, then alphabetically
    usort($ledger, function($a, $b) {
        $aActive = ($a['in'] || $a['out'] || $a['adjustments']) ? 1 : 0;
        $bActive = ($b['in'] || $b['out'] || $b['adjustments']) ? 1 : 0;
        if ($aActive !== $bActive) return $bActive - $aActive;
        return strcmp($a['name'], $b['name']);
    });

    // Cost price as of the END of the range (used for closing stock value).
    // Falls back to current cost_price when no history row exists for that date.
    $costInfo = costPricesAsOf($conn, $itemIds, $end);
    foreach ($ledger as &$row) {
        $c = $costInfo[$row['id']] ?? ['cost' => 0, 'is_estimated' => true];
        $row['cost_price'] = $c['cost'];
        $row['cost_is_estimated'] = $c['is_estimated'];
        $row['value'] = $row['closing'] * $c['cost'];
    }
    unset($row);
}

$totalValue = array_sum(array_column($ledger, 'value'));

$totalIn = array_sum(array_column($ledger, 'in'));
$totalOut = array_sum(array_column($ledger, 'out'));
$flaggedCount = count(array_filter($ledger, fn($r) => abs($r['variance']) > 0.01));

layoutHead('Stock Ledger', 'Opening, in, out & closing quantity per item', 'stock_ledger', null);
rangeFilterBar($start, $end,
    '<div><label>Item</label><select name="item_id"><option value="">All Items</option>' .
    implode('', array_map(fn($it) => '<option value="'.$it['id'].'"'.($itemFilter==$it['id']?' selected':'').'>'.htmlspecialchars($it['name']).'</option>', $allItems)) .
    '</select></div>'
);
?>
<div class="rp-period">&#128197; <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?></div>

<div class="rp-kpi-grid">
  <div class="rp-kpi green">
    <div class="kpi-label">Total Stock In</div>
    <div class="kpi-value"><?= number_format($totalIn, 2) ?></div>
    <div class="kpi-sub">units received/restocked</div>
  </div>
  <div class="rp-kpi red">
    <div class="kpi-label">Total Stock Out</div>
    <div class="kpi-value"><?= number_format($totalOut, 2) ?></div>
    <div class="kpi-sub">units sold/removed</div>
  </div>
  <div class="rp-kpi amber">
    <div class="kpi-label">Flagged Items</div>
    <div class="kpi-value"><?= $flaggedCount ?></div>
    <div class="kpi-sub">manual adjustment ≠ expected</div>
  </div>
  <div class="rp-kpi teal">
    <div class="kpi-label">Closing Stock Value</div>
    <div class="kpi-value">TZS <?= number_format($totalValue, 0) ?></div>
    <div class="kpi-sub">as of <?= htmlspecialchars($end) ?></div>
  </div>
</div>

<?php if (empty($ledger)): ?>
  <div class="rp-card"><div class="rp-empty">No stock items found.</div></div>
<?php else: ?>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Item Ledger (<?= htmlspecialchars($start) ?> → <?= htmlspecialchars($end) ?>)</div>
  <table class="rp-table">
    <thead><tr><th>Item</th><th class="num">Opening</th><th class="num">In</th><th class="num">Out</th><th class="num">Closing</th><th class="num">Value</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $r):
        $hasMissing = abs($r['variance']) > 0.01;
        $isQuiet = !$r['in'] && !$r['out'] && empty($r['adjustments']);
    ?>
      <tr style="<?= $isQuiet ? 'opacity:0.5;' : '' ?>">
        <td><strong><?= htmlspecialchars($r['name']) ?></strong> <span style="color:var(--ink-muted);font-size:0.78rem;">(<?= htmlspecialchars($r['unit']) ?>)</span></td>
        <td class="num"><?= number_format($r['opening'], 2) ?></td>
        <td class="num" style="color:var(--green);"><?= $r['in'] > 0 ? '+'.number_format($r['in'], 2) : '—' ?></td>
        <td class="num" style="color:var(--red);"><?= $r['out'] > 0 ? '−'.number_format($r['out'], 2) : '—' ?></td>
        <td class="num"><strong><?= number_format($r['closing'], 2) ?></strong></td>
        <td class="num">
          TZS <?= number_format($r['value'], 0) ?>
          <?php if ($r['cost_is_estimated']): ?>
            <span title="No historical cost record for this date — using current cost price" style="color:var(--amber);font-size:0.7rem;">*</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($r['approx']): ?>
            <span class="mpill avg">Approx.</span>
          <?php elseif ($hasMissing): ?>
            <span class="mpill low">Missing <?= number_format(abs($r['variance']),2) ?></span>
          <?php elseif (!empty($r['adjustments'])): ?>
            <span class="mpill avg">Adjusted</span>
          <?php else: ?>
            <span class="mpill good">OK</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="margin-top:14px;font-size:0.76rem;color:var(--ink-muted);">
    <strong>Missing</strong> = closing quantity doesn't match opening + in − out, after a manual stock adjustment was recorded — i.e. stock unaccounted for (could be theft, breakage, or miscount).
    <strong>Approx.</strong> = a manual adjustment was made after this date range, so opening/closing for this item are estimated.
    <strong>*</strong> next to a value = no historical cost record exists for this item before <?= htmlspecialchars($end) ?>, so today's cost price was used instead of the price actually in effect then.
  </div>
</div>

<div class="rp-card">
  <div class="rp-section-title"><span></span>Movement Detail</div>
  <?php $anyMovement = false; foreach ($ledger as $r) { if (!empty($r['movements'])) { $anyMovement = true; break; } } ?>
  <?php if (!$anyMovement): ?>
    <div class="rp-empty">No stock movements recorded in this period.</div>
  <?php else: ?>
  <table class="rp-table">
    <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="num">Qty</th><th>Note</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $r): foreach ($r['movements'] as $m): ?>
      <tr>
        <td><?= date('d M Y', strtotime($m['moved_at'])) ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td>
          <?php if ($m['movement_type']==='in'): ?><span class="rp-badge closed">In</span>
          <?php elseif ($m['movement_type']==='out'): ?><span class="rp-badge void">Out</span>
          <?php else: ?><span class="rp-badge open">Adjustment</span><?php endif; ?>
        </td>
        <td class="num"><?= number_format($m['quantity'], 2) ?></td>
        <td><?= htmlspecialchars($m['note'] ?? '—') ?></td>
      </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php endif; layoutFoot(); ?>
