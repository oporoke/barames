USE barpos;

-- Add payment method to sales
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS payment_method ENUM('cash','mobile_money','card','other') NOT NULL DEFAULT 'cash' AFTER amount,
  ADD COLUMN IF NOT EXISTS quantity DECIMAL(10,2) NOT NULL DEFAULT 1 AFTER description,
  ADD COLUMN IF NOT EXISTS unit_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantity;

-- Update existing sales so unit_price = amount where it is 0
UPDATE sales SET unit_price = amount, quantity = 1 WHERE unit_price = 0;

-- Add receipt table
CREATE TABLE IF NOT EXISTS receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_method ENUM('cash','mobile_money','card','other') DEFAULT 'cash',
    sale_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Link sales to receipts
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS receipt_id INT NULL AFTER id,
  ADD FOREIGN KEY IF NOT EXISTS (receipt_id) REFERENCES receipts(id);
