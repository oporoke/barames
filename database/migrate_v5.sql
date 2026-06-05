USE barpos;

-- Add SKU/barcode to stock items
ALTER TABLE stock_items
  ADD COLUMN IF NOT EXISTS sku VARCHAR(100) NULL UNIQUE AFTER name,
  ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) NULL AFTER sku,
  ADD COLUMN IF NOT EXISTS supplier_id INT NULL AFTER barcode;

-- Suppliers table
CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100),
    phone VARCHAR(30),
    email VARCHAR(100),
    address TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Purchase orders
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    status ENUM('pending','received','partial','cancelled') DEFAULT 'pending',
    total_amount DECIMAL(10,2) DEFAULT 0,
    notes TEXT,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Purchase order line items
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    stock_item_id INT NOT NULL,
    quantity_ordered DECIMAL(10,2) NOT NULL,
    quantity_received DECIMAL(10,2) DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL,
    total_cost DECIMAL(10,2) GENERATED ALWAYS AS (quantity_ordered * unit_cost) STORED,
    FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
);

-- Link sales to stock items for auto-deduction
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS stock_item_id INT NULL AFTER category_id,
  ADD FOREIGN KEY IF NOT EXISTS fk_sales_stock (stock_item_id) REFERENCES stock_items(id) ON DELETE SET NULL;

-- Add supplier FK to stock_items now that suppliers table exists
ALTER TABLE stock_items
  ADD CONSTRAINT IF NOT EXISTS fk_stock_supplier
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;

-- Indexes
CREATE INDEX IF NOT EXISTS idx_po_supplier ON purchase_orders(supplier_id);
CREATE INDEX IF NOT EXISTS idx_po_date     ON purchase_orders(order_date);
CREATE INDEX IF NOT EXISTS idx_poi_order   ON purchase_order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_stock_sku   ON stock_items(sku);
