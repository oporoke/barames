<?php
define('APPDIR', 'barpos');
session_start();
require_once '../../../includes/db.php';
require_once '../../../includes/functions.php';
require_once '../../../includes/auth.php';
require_once '_layout.php';
requireRole('admin', 'manager');

$report = $_GET['report'] ?? '';
[$start, $end] = resolveRange();

$allowed = ['daily_profit','sales_by_item','profit_by_item','sales_by_time','staff_performance','void_audit','tax_report','supplier_spend','expense_report'];
if (!in_array($report, $allowed)) { http_response_code(400); die('Invalid report.'); }

$filename = "BarPOS_{$report}_{$start}_to_{$end}.xls";
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1'><tr><td colspan='6' style='font-weight:bold;font-size:14pt;background:#1a1a2e;color:white;'>BAR POS — " . htmlspecialchars(strtoupper(str_replace('_',' ',$report))) . "</td></tr>";
echo "<tr><td colspan='6'>Period: " . htmlspecialchars($start) . " to " . htmlspecialchars($end) . "</td></tr><tr><td colspan='6'></td></tr>";

switch ($report) {
    case 'sales_by_item':
        $rows = q($conn, "SELECT si.description AS item, c.name AS category, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue
                           FROM sale_items si JOIN sale_transactions st ON st.id=si.transaction_id LEFT JOIN categories c ON c.id=si.category_id
                           WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ? GROUP BY si.description, c.name ORDER BY revenue DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Item</th><th>Category</th><th>Qty Sold</th><th>Revenue</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['item'])."</td><td>".htmlspecialchars($r['category']??'')."</td><td>{$r['qty']}</td><td>{$r['revenue']}</td></tr>";
        break;

    case 'profit_by_item':
        $rows = q($conn, "SELECT si.description AS item, SUM(si.quantity) AS qty, SUM(si.line_total) AS revenue,
                           SUM(si.quantity*COALESCE(sk.cost_price,0)) AS cost
                           FROM sale_items si JOIN sale_transactions st ON st.id=si.transaction_id LEFT JOIN stock_items sk ON sk.id=si.stock_item_id
                           WHERE st.type='sale' AND st.sale_date BETWEEN ? AND ? GROUP BY si.description ORDER BY revenue DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Item</th><th>Qty</th><th>Revenue</th><th>Cost</th><th>Profit</th></tr>";
        foreach ($rows as $r) { $p=$r['revenue']-$r['cost']; echo "<tr><td>".htmlspecialchars($r['item'])."</td><td>{$r['qty']}</td><td>{$r['revenue']}</td><td>{$r['cost']}</td><td>{$p}</td></tr>"; }
        break;

    case 'sales_by_time':
        $rows = q($conn, "SELECT sale_date, HOUR(created_at) AS hr, COUNT(*) AS txns, SUM(total) AS revenue FROM sale_transactions
                           WHERE type='sale' AND sale_date BETWEEN ? AND ? GROUP BY sale_date, HOUR(created_at) ORDER BY sale_date, hr", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Hour</th><th>Txns</th><th>Revenue</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['sale_date']}</td><td>{$r['hr']}:00</td><td>{$r['txns']}</td><td>{$r['revenue']}</td></tr>";
        break;

    case 'staff_performance':
        $rows = q($conn, "SELECT u.name, u.role, SUM(CASE WHEN st.type='sale' THEN st.total ELSE 0 END) AS sales,
                           COUNT(CASE WHEN st.type='void' THEN 1 END) AS voids, SUM(CASE WHEN st.type='sale' THEN st.discount ELSE 0 END) AS discounts
                           FROM users u LEFT JOIN sale_transactions st ON st.cashier_id=u.id AND st.sale_date BETWEEN ? AND ?
                           WHERE u.role IN ('cashier','manager','admin') GROUP BY u.id, u.name, u.role ORDER BY sales DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Staff</th><th>Role</th><th>Sales</th><th>Voids</th><th>Discounts</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['name'])."</td><td>{$r['role']}</td><td>{$r['sales']}</td><td>{$r['voids']}</td><td>{$r['discounts']}</td></tr>";
        break;

    case 'void_audit':
        $rows = q($conn, "SELECT st.created_at, st.type, u.name AS cashier, st.total, st.discount, st.note
                           FROM sale_transactions st LEFT JOIN users u ON u.id=st.cashier_id
                           WHERE st.sale_date BETWEEN ? AND ? AND (st.type IN ('void','refund') OR st.discount>0)
                           ORDER BY st.created_at DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Type</th><th>Cashier</th><th>Amount</th><th>Discount</th><th>Note</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['created_at']}</td><td>{$r['type']}</td><td>".htmlspecialchars($r['cashier']??'')."</td><td>{$r['total']}</td><td>{$r['discount']}</td><td>".htmlspecialchars($r['note']??'')."</td></tr>";
        break;

    case 'tax_report':
        $rows = q($conn, "SELECT sale_date, SUM(subtotal) AS subtotal, SUM(tax_amount) AS tax, SUM(total) AS total
                           FROM sale_transactions WHERE type='sale' AND sale_date BETWEEN ? AND ? GROUP BY sale_date ORDER BY sale_date", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Subtotal</th><th>Tax</th><th>Total</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['sale_date']}</td><td>{$r['subtotal']}</td><td>{$r['tax']}</td><td>{$r['total']}</td></tr>";
        break;

    case 'supplier_spend':
        $rows = q($conn, "SELECT s.name, COUNT(DISTINCT po.id) AS po_count, COALESCE(SUM(poi.total_cost),0) AS spend
                           FROM suppliers s LEFT JOIN purchase_orders po ON po.supplier_id=s.id AND po.order_date BETWEEN ? AND ?
                           LEFT JOIN purchase_order_items poi ON poi.order_id=po.id
                           WHERE s.is_active=1 GROUP BY s.id, s.name ORDER BY spend DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Supplier</th><th>POs</th><th>Spend</th></tr>";
        foreach ($rows as $r) echo "<tr><td>".htmlspecialchars($r['name'])."</td><td>{$r['po_count']}</td><td>{$r['spend']}</td></tr>";
        break;
    case 'expense_report':
        $rows = q($conn, "SELECT e.expense_date, c.name AS category, e.description, e.amount, e.is_duplicate_flag
                           FROM expenses e JOIN categories c ON c.id=e.category_id
                           WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC", [$start,$end])->fetch_all(MYSQLI_ASSOC);
        echo "<tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th>Flagged</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['expense_date']}</td><td>".htmlspecialchars($r['category'])."</td><td>".htmlspecialchars($r['description'])."</td><td>{$r['amount']}</td><td>".($r['is_duplicate_flag']?'Yes':'').'</td></tr>';
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
        echo "<tr><th>Date</th><th>Kitchen</th><th>Bar</th><th>Gross Sales</th><th>COGS</th><th>Gross Profit</th><th>Expenses</th><th>Net Profit</th><th>Cumulative</th></tr>";
        $cum = 0;
        foreach ($rows as $r) {
            $gp = $r['gross_sales'] - $r['cogs'];
            $exp = $expByDate[$r['sale_date']] ?? 0;
            $np = $gp - $exp;
            $cum += $np;
            echo "<tr><td>{$r['sale_date']}</td><td>{$r['kitchen']}</td><td>{$r['bar']}</td><td>{$r['gross_sales']}</td><td>{$r['cogs']}</td><td>{$gp}</td><td>{$exp}</td><td>{$np}</td><td>{$cum}</td></tr>";
        }
        break;
}

echo "</table></body></html>";
