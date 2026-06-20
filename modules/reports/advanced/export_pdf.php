<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');
require_once $_SERVER['DOCUMENT_ROOT'] . '/barpos/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$report = $_GET['report'] ?? '';
[$start, $end] = resolveRange();
$allowed = ['daily_profit','sales_by_item','profit_by_item','sales_by_time','staff_performance','void_audit','tax_report','supplier_spend','expense_report'];
if (!in_array($report, $allowed)) { http_response_code(400); die('Invalid report.'); }

ob_start();
?>
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color:#0d0d14; }
  h1 { font-size: 16px; margin-bottom: 2px; }
  .sub { color:#8888a0; font-size:10px; margin-bottom:14px; }
  table { width:100%; border-collapse: collapse; }
  th { background:#1a1a2e; color:#fff; text-align:left; padding:6px 8px; font-size:9px; text-transform:uppercase; }
  td { padding:5px 8px; border-bottom:1px solid #e5e7eb; }
  tr:nth-child(even) td { background:#f7f7fb; }
</style>
<h1>BarPOS — <?= htmlspecialchars(strtoupper(str_replace('_',' ',$report))) ?></h1>
<div class="sub">Period: <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?> &nbsp;|&nbsp; Generated <?= date('d M Y H:i') ?></div>
<table>
<?php
switch ($report) {
    case 'sales_by_item':
        $rows = q($conn, "SELECT si.description AS item, c.name AS category, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue
                           FROM sale_items si JOIN sale_transactions st ON st.id=si.transaction_id LEFT JOIN categories c ON c.id=si.category_id
                           WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ? GROUP BY si.description, c.name ORDER BY revenue DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Item</th><th>Category</th><th>Qty Sold</th><th>Revenue (TZS)</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['item'])."</td><td>".htmlspecialchars($r['category']??'')."</td><td>".number_format($r['qty'],2)."</td><td>".number_format($r['revenue'],0)."</td></tr>";
        break;

    case 'profit_by_item':
        $rows = q($conn, "SELECT si.description AS item, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue,
                           SUM(si.quantity*COALESCE(sk.cost_price,0)) AS cost
                           FROM sale_items si JOIN sale_transactions st ON st.id=si.transaction_id LEFT JOIN stock_items sk ON sk.id=si.stock_item_id
                           WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ? GROUP BY si.description ORDER BY revenue DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Item</th><th>Qty</th><th>Revenue</th><th>Cost</th><th>Profit</th></tr>";
        foreach ($rows as $r) { $p=$r['revenue']-$r['cost']; echo "<tr><td>".htmlspecialchars($r['item'])."</td><td>".number_format($r['qty'],2)."</td><td>".number_format($r['revenue'],0)."</td><td>".number_format($r['cost'],0)."</td><td>".number_format($p,0)."</td></tr>"; }
        break;

    case 'sales_by_time':
        $rows = q($conn, "SELECT sale_date, HOUR(created_at) AS hr, COUNT(*) AS txns, SUM(total) AS revenue FROM sale_transactions
                           WHERE type='sale' AND sale_date BETWEEN ? AND ? GROUP BY sale_date, HOUR(created_at) ORDER BY sale_date, hr", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Hour</th><th>Txns</th><th>Revenue</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['sale_date']}</td><td>{$r['hr']}:00</td><td>{$r['txns']}</td><td>".number_format($r['revenue'],0)."</td></tr>";
        break;

    case 'staff_performance':
        $rows = q($conn, "SELECT u.name, u.role, SUM(CASE WHEN st.type='sale' THEN st.total ELSE 0 END) AS sales,
                           COUNT(CASE WHEN st.type='void' THEN 1 END) AS voids, SUM(CASE WHEN st.type='sale' THEN st.discount ELSE 0 END) AS discounts
                           FROM users u LEFT JOIN sale_transactions st ON st.cashier_id=u.id AND st.sale_date BETWEEN ? AND ?
                           WHERE u.role IN ('cashier','manager','admin') GROUP BY u.id, u.name, u.role ORDER BY sales DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Staff</th><th>Role</th><th>Sales</th><th>Voids</th><th>Discounts</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['name'])."</td><td>{$r['role']}</td><td>".number_format($r['sales'],0)."</td><td>{$r['voids']}</td><td>".number_format($r['discounts'],0)."</td></tr>";
        break;

    case 'void_audit':
        $rows = q($conn, "SELECT st.created_at, st.type, u.name AS cashier, st.total, st.discount, st.note
                           FROM sale_transactions st LEFT JOIN users u ON u.id=st.cashier_id
                           WHERE st.sale_date BETWEEN ? AND ? AND (st.type IN ('void','refund') OR st.discount>0)
                           ORDER BY st.created_at DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Type</th><th>Cashier</th><th>Amount</th><th>Discount</th><th>Note</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".date('d M H:i',strtotime($r['created_at']))."</td><td>{$r['type']}</td><td>".htmlspecialchars($r['cashier']??'')."</td><td>".number_format($r['total'],0)."</td><td>".number_format($r['discount'],0)."</td><td>".htmlspecialchars($r['note']??'')."</td></tr>";
        break;

    case 'tax_report':
        $rows = q($conn, "SELECT sale_date, SUM(subtotal) AS subtotal, SUM(tax_amount) AS tax, SUM(total) AS total
                           FROM sale_transactions WHERE type='sale' AND sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Subtotal</th><th>Tax</th><th>Total</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['sale_date']}</td><td>".number_format($r['subtotal'],0)."</td><td>".number_format($r['tax'],0)."</td><td>".number_format($r['total'],0)."</td></tr>";
        break;

    case 'supplier_spend':
        $rows = q($conn, "SELECT s.name, COUNT(DISTINCT po.id) AS po_count, COALESCE(SUM(poi.total_cost),0) AS spend
                           FROM suppliers s LEFT JOIN purchase_orders po ON po.supplier_id=s.id AND po.order_date BETWEEN ? AND ?
                           LEFT JOIN purchase_order_items poi ON poi.order_id=po.id
                           WHERE s.is_active=1 GROUP BY s.id, s.name ORDER BY spend DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Supplier</th><th>POs</th><th>Spend</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['name'])."</td><td>{$r['po_count']}</td><td>".number_format($r['spend'],0)."</td></tr>";
        break;
    case 'expense_report':
        $rows = q($conn, "SELECT e.expense_date, c.name AS category, e.description, e.amount
                           FROM expenses e JOIN categories c ON c.id=e.category_id
                           WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['expense_date']}</td><td>".htmlspecialchars($r['category'])."</td><td>".htmlspecialchars($r['description'])."</td><td>".number_format($r['amount'],0)."</td></tr>";
        break;

    case 'daily_profit':
        $rows = q($conn, "
            SELECT st.sale_date,
                SUM(CASE WHEN c.type='kitchen' THEN si.line_total ELSE 0 END) AS kitchen,
                SUM(CASE WHEN c.type='drinks' THEN si.line_total ELSE 0 END) AS bar,
                SUM(si.line_total) AS gross_sales,
                SUM(si.quantity * COALESCE(sk.cost_price,0)) AS cogs
            FROM sale_transactions st JOIN sale_items si ON si.transaction_id=st.id
            LEFT JOIN categories c ON c.id=si.category_id LEFT JOIN stock_items sk ON sk.id=si.stock_item_id
            WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ? GROUP BY st.sale_date ORDER BY st.sale_date", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        $expByDate = [];
        $er = q($conn, "SELECT expense_date, SUM(amount) AS total FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY expense_date", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        foreach ($er as $e) $expByDate[$e['expense_date']] = $e['total'];
        echo "<tr><th>Date</th><th>Kitchen</th><th>Bar</th><th>Gross</th><th>COGS</th><th>Gross Profit</th><th>Expenses</th><th>Net Profit</th><th>Cumulative</th></tr>";
        $cum = 0;
        foreach ($rows as $r) {
            $gp = $r['gross_sales'] - $r['cogs'];
            $exp = $expByDate[$r['sale_date']] ?? 0;
            $np = $gp - $exp;
            $cum += $np;
            echo "<tr><td>{$r['sale_date']}</td><td>".number_format($r['kitchen'],0)."</td><td>".number_format($r['bar'],0)."</td><td>".number_format($r['gross_sales'],0)."</td><td>".number_format($r['cogs'],0)."</td><td>".number_format($gp,0)."</td><td>".number_format($exp,0)."</td><td>".number_format($np,0)."</td><td>".number_format($cum,0)."</td></tr>";
        }
        break;
}
?>
</table>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = "BarPOS_{$report}_{$start}_to_{$end}.pdf";
$dompdf->stream($filename, ['Attachment' => false]);
