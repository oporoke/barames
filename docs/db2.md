Got it. I've studied the `barpos` database schema. Here's a quick mental map:

**Core tables:**
- `users` (admin/manager/cashier roles)
- `stock_items` → `stock_movements`, `purchase_orders` / `purchase_order_items`
- `sale_transactions` → `sale_items` → `transaction_payments`
- `sales` (older/legacy table, separate from the `sale_transactions` flow)
- `shifts`, `till_movements`
- `daily_cash_control`, `capital_injections`
- `expenses`, `categories`, `suppliers`
- `receipts`, `restaurant_tables`, `settings`, `audit_log`

**Key observations:**
- There are **two parallel sales systems** — the legacy `sales` table and the newer `sale_transactions` + `sale_items` + `transaction_payments` trio. The newer one is clearly the active system (AUTO_INCREMENT at 172 vs `sales` at 1).
- `expenses` and `sales` use no ENGINE (no `InnoDB`), so they lack FK enforcement.
- Business name is **Tretat Bar & Restaurant**.

What do you need to do with it?