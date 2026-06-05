-- ============================================================
--  Bar & Restaurant POS - Database Schema
-- ============================================================
CREATE DATABASE IF NOT EXISTS barpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barpos;

-- Categories (Drinks / Kitchen)
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('drinks','kitchen','other') NOT NULL DEFAULT 'other',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO categories (name, type) VALUES ('Drinks', 'drinks'), ('Kitchen', 'kitchen');

-- Stock Items
CREATE TABLE IF NOT EXISTS stock_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    unit VARCHAR(50) DEFAULT 'pcs',
    quantity DECIMAL(10,2) DEFAULT 0.00,
    low_stock_threshold DECIMAL(10,2) DEFAULT 5.00,
    cost_price DECIMAL(10,2) DEFAULT 0.00,
    selling_price DECIMAL(10,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Stock Movements (in/out log)
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stock_item_id INT NOT NULL,
    movement_type ENUM('in','out','adjustment') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    note TEXT,
    moved_at DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
);

-- Sales
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    description VARCHAR(255),
    amount DECIMAL(10,2) NOT NULL,
    sale_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);

-- Expenses
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    expense_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
);
