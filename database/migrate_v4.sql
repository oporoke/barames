USE barpos;

-- Enforce unique stock item names per category
ALTER TABLE stock_items
  ADD CONSTRAINT IF NOT EXISTS uq_stock_name_category UNIQUE (name, category_id);

-- Prevent stock from going below zero at DB level
ALTER TABLE stock_items
  ADD CONSTRAINT IF NOT EXISTS chk_stock_qty CHECK (quantity >= 0);

-- Enforce positive amounts
ALTER TABLE sales
  ADD CONSTRAINT IF NOT EXISTS chk_sale_amount CHECK (amount > 0);

ALTER TABLE expenses
  ADD CONSTRAINT IF NOT EXISTS chk_expense_amount CHECK (amount > 0);

-- Ensure sale_date and expense_date are not in the future (soft enforced via PHP)
-- Index for faster date range queries on reports
CREATE INDEX IF NOT EXISTS idx_sales_date      ON sales (sale_date);
CREATE INDEX IF NOT EXISTS idx_expenses_date   ON expenses (expense_date);
CREATE INDEX IF NOT EXISTS idx_stock_active    ON stock_items (is_active);
CREATE INDEX IF NOT EXISTS idx_movements_item  ON stock_movements (stock_item_id);
