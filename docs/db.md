# BarPOS Database Documentation

**Database Name:** `barpos`  
**Business:** Tretat Bar & Restaurant, Geza, Kigamboni, Dar es Salaam  
**Currency:** TZS (Tanzanian Shilling)  
**Engine:** MariaDB 10.4.32 | Charset: utf8mb4_unicode_ci  
**Exported:** 2026-06-09 via phpMyAdmin 5.2.1  

---

## Overview

This is a Point-of-Sale (POS) system database for a bar and restaurant. It covers:
- **Sales & Transactions** – recording sales, items sold, payments
- **Stock & Inventory** – tracking products, movements, purchase orders
- **Finance** – expenses, cash control, capital injections, till movements
- **Operations** – shifts, user access, audit logging
- **Settings** – system configuration

---

## Table Index

| # | Table | Purpose |
|---|-------|---------|
| 1 | `users` | System user accounts and roles |
| 2 | `categories` | Product/expense categories |
| 3 | `stock_items` | Products/items for sale or stock |
| 4 | `suppliers` | Supplier directory |
| 5 | `purchase_orders` | Stock purchase orders |
| 6 | `purchase_order_items` | Line items of each purchase order |
| 7 | `stock_movements` | Stock in/out/adjustment history |
| 8 | `sale_transactions` | Master record for each sale transaction |
| 9 | `sale_items` | Line items of each transaction |
| 10 | `transaction_payments` | Payment records per transaction (supports split payment) |
| 11 | `sales` | Legacy/alternative sales table |
| 12 | `receipts` | Receipt records |
| 13 | `restaurant_tables` | Physical table management |
| 14 | `shifts` | Cashier shift tracking |
| 15 | `till_movements` | Cash-in/cash-out records within a shift |
| 16 | `expenses` | Business expense tracking |
| 17 | `daily_cash_control` | Daily opening/closing cash reconciliation |
| 18 | `capital_injections` | Owner/investor capital top-ups |
| 19 | `audit_log` | Full activity log of system actions |
| 20 | `settings` | System configuration key-value pairs |

---

## Table Details

---

### 1. `users`
Stores all system user accounts.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `name` | varchar(100) | Full display name |
| `username` | varchar(50) | Login username (unique) |
| `password` | varchar(255) | Bcrypt hashed password |
| `role` | enum | `admin`, `manager`, `cashier` |
| `is_active` | tinyint(1) | 1 = active, 0 = disabled |
| `last_login` | datetime | Last successful login timestamp |
| `created_at` | timestamp | Account creation time |

**Current users:** Admin (admin), Aziz (cashier), Nich/man (manager)

---

### 2. `categories`
Groups products and expenses into types.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `name` | varchar(100) | Category label |
| `type` | enum | `drinks`, `kitchen`, `staff`, `other`, `stock` |
| `created_at` | timestamp | Record creation time |

**Current categories:** Drinks, Kitchen, Staff, Other, Stock

---

### 3. `stock_items`
The product catalogue — every item that can be sold or tracked.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `category_id` | int(11) FK → categories | Product category |
| `name` | varchar(150) | Product name |
| `sku` | varchar(100) | SKU / barcode string |
| `barcode` | varchar(100) | Additional barcode field |
| `supplier_id` | int(11) FK → suppliers | Default supplier |
| `unit` | varchar(50) | Unit of measure (e.g. pcs, bottles) |
| `quantity` | decimal(10,2) | Current stock on hand |
| `low_stock_threshold` | decimal(10,2) | Triggers low-stock alert |
| `cost_price` | decimal(10,2) | Purchase/cost price |
| `selling_price` | decimal(10,2) | Retail selling price |
| `is_active` | tinyint(1) | 1 = active listing |
| `created_at` | timestamp | Date added |
| `updated_at` | timestamp | Last modified (auto-updated) |

**Examples of stock items:** Castle Lite, Serengeti Lager, Kilimanjaro Water, Konyagi, Fanta, Cocacola, Kitimoto 1KG, Ugali, Ndizi, Altar Wine 750ML, Cups, etc.

---

### 4. `suppliers`
Directory of product suppliers.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `name` | varchar(150) | Supplier name |
| `contact_person` | varchar(100) | Primary contact name |
| `phone` | varchar(30) | Phone number |
| `email` | varchar(100) | Email address |
| `address` | text | Physical address |
| `is_active` | tinyint(1) | 1 = active |
| `created_at` | timestamp | Date added |

**Current supplier:** KIGAMBONI (contact: Mrs. Chef, Geza)

---

### 5. `purchase_orders`
Tracks stock replenishment orders placed to suppliers.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `supplier_id` | int(11) FK → suppliers | Who the order was placed with |
| `order_date` | date | Date of order |
| `status` | enum | `pending`, `received`, `partial`, `cancelled` |
| `total_amount` | decimal(10,2) | Total order value in TZS |
| `notes` | text | Free-text notes |
| `created_by` | int(11) FK → users | User who created the PO |
| `created_at` | timestamp | Creation time |

**Note:** 3 purchase orders on record, all received on 2026-06-08 from KIGAMBONI supplier, totalling ~TZS 436,500.

---

### 6. `purchase_order_items`
Line items for each purchase order.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `order_id` | int(11) FK → purchase_orders | Parent order |
| `stock_item_id` | int(11) FK → stock_items | Item ordered |
| `quantity_ordered` | decimal(10,2) | How many ordered |
| `quantity_received` | decimal(10,2) | How many actually received |
| `unit_cost` | decimal(10,2) | Cost per unit |
| `total_cost` | decimal(10,2) GENERATED | `quantity_ordered × unit_cost` (computed, stored) |

---

### 7. `stock_movements`
Audit trail of every inventory change.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `stock_item_id` | int(11) FK → stock_items | Affected item |
| `movement_type` | enum | `in`, `out`, `adjustment` |
| `quantity` | decimal(10,2) | Quantity moved |
| `note` | text | Reference (e.g. "Txn #124") |
| `moved_at` | date | Date of movement |
| `created_at` | timestamp | Record creation time |

---

### 8. `sale_transactions`
Master header record for each point-of-sale transaction. This is the primary modern sales table.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(10 UNSIGNED) PK | Auto-increment. Transaction number. |
| `cashier_id` | int(11) FK → users | Staff who processed the sale |
| `shift_id` | int(11 UNSIGNED) FK → shifts | Shift this sale belongs to |
| `type` | enum | `sale`, `void`, `refund` |
| `table_id` | int(11 UNSIGNED) FK → restaurant_tables | Table if dine-in |
| `tab_status` | enum | `open`, `closed`, `voided` |
| `ref_transaction_id` | int(10 UNSIGNED) | Reference to original txn for voids/refunds |
| `payment_method` | varchar(30) | Default: `cash` |
| `payment_reference` | varchar(100) | Mobile money or card reference |
| `subtotal` | decimal(12,2) | Pre-discount/tax total |
| `discount` | decimal(12,2) | Discount amount |
| `tax_rate` | decimal(5,2) | Tax % applied |
| `tax_amount` | decimal(12,2) | Tax amount in TZS |
| `total` | decimal(12,2) | Final amount charged |
| `note` | varchar(255) | Optional note |
| `sale_date` | date | Date of sale |
| `created_at` | datetime | Record creation timestamp |

---

### 9. `sale_items`
Individual product lines within each transaction.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(10 UNSIGNED) PK | Auto-increment |
| `transaction_id` | int(10 UNSIGNED) FK → sale_transactions | Parent transaction |
| `category_id` | int(11) FK → categories | Item category |
| `stock_item_id` | int(11) FK → stock_items | Product sold (nullable for manual entries) |
| `description` | varchar(255) | Product name at time of sale |
| `quantity` | decimal(10,3) | Quantity sold |
| `unit_price` | decimal(12,2) | Price per unit |
| `line_total` | decimal(12,2) | `quantity × unit_price` |
| `created_at` | datetime | Record creation time |

---

### 10. `transaction_payments`
Supports **split payments** — one transaction can have multiple payment records.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(10 UNSIGNED) PK | Auto-increment |
| `transaction_id` | int(10 UNSIGNED) FK → sale_transactions | Parent transaction |
| `method` | enum | `cash`, `mobile_money`, `card`, `other` |
| `amount` | decimal(12,2) | Amount paid via this method |
| `reference` | varchar(100) | Mobile/card reference number |
| `created_at` | datetime | Payment record time |

---

### 11. `sales` *(Legacy)*
An older sales recording format, still populated. Differs from `sale_transactions` in structure — appears to be from a previous version of the system. Contains sales data going back to April 2026.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `receipt_id` | int(11) FK → receipts | Linked receipt (nullable) |
| `category_id` | int(11) FK → categories | Product category |
| `stock_item_id` | int(11) | Stock item (nullable) |
| `product_id` | int(11) | Product reference (nullable) |
| `qty` | decimal(10,2) | Quantity sold |
| `unit_price` | decimal(10,2) | Price per unit |
| `total` | decimal(10,2) | Computed total (some 0.00 entries) |
| `description` | varchar(255) | Manual description |
| `quantity` | decimal(10,2) | Duplicate quantity field |
| `amount` | decimal(10,2) | Sale amount |
| `payment_method` | enum | `cash`, `mobile_money`, `card`, `other` |
| `sale_date` | date | Date of sale |
| `is_duplicate_flag` | tinyint(1) | Flags potential duplicate records |
| `created_at` | timestamp | Creation time |

---

### 12. `receipts`
Stores receipt metadata (separate from transaction records).

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `receipt_number` | varchar(30) | Unique receipt number |
| `total_amount` | decimal(10,2) | Receipt total |
| `payment_method` | enum | `cash`, `mobile_money`, `card`, `other` |
| `sale_date` | date | Date of receipt |
| `created_at` | timestamp | Creation time |

**Note:** Table exists but has no data currently.

---

### 13. `restaurant_tables`
Physical seating table management.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11 UNSIGNED) PK | Auto-increment |
| `name` | varchar(50) | Table name/label (e.g. "Table 1") |
| `capacity` | tinyint UNSIGNED | Number of seats |
| `section` | varchar(50) | Area (e.g. indoor, outdoor, VIP) |
| `is_active` | tinyint(1) | 1 = in service |
| `created_at` | timestamp | Date added |

**Note:** Table exists but has no data currently (tables not yet configured).

---

### 14. `shifts`
Tracks cashier work shifts from open to close.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11 UNSIGNED) PK | Auto-increment |
| `cashier_id` | int(11) FK → users | Cashier for this shift |
| `opening_float` | decimal(12,2) | Cash placed in till at start of shift |
| `closing_balance` | decimal(12,2) | Actual cash counted at close |
| `expected_cash` | decimal(12,2) | Expected cash based on sales |
| `variance` | decimal(12,2) | Difference (closing − expected) |
| `notes` | text | Shift notes |
| `opened_at` | datetime | Shift start time |
| `closed_at` | datetime | Shift end time (null if still open) |
| `status` | enum | `open`, `closed` |
| `created_at` | timestamp | Record creation time |

---

### 15. `till_movements`
Records manual cash movements in/out of the till during a shift.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11 UNSIGNED) PK | Auto-increment |
| `shift_id` | int(11 UNSIGNED) FK → shifts | Shift this movement belongs to |
| `cashier_id` | int(11) FK → users | Cashier who performed it |
| `type` | enum | `cash_in`, `cash_out` |
| `amount` | decimal(12,2) | Amount moved |
| `reason` | varchar(255) | Reason for the movement |
| `created_at` | datetime | Timestamp |

---

### 16. `expenses`
Tracks all business expenses by category.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `category_id` | int(11) FK → categories | Expense category |
| `description` | varchar(255) | What the expense was for |
| `amount` | decimal(10,2) | Amount in TZS |
| `expense_date` | date | Date incurred |
| `is_duplicate_flag` | tinyint(1) | Flags potential duplicate entries |
| `created_at` | timestamp | Creation time |

**Sample expenses:** Staff food, Power Tokens, Viungo, Ndizi, Viazi, Mafuta, Gas refill (TZS 120,000), Stock refill (TZS 436,500)

---

### 17. `daily_cash_control`
End-of-day cash reconciliation report for each business day.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(10 UNSIGNED) PK | Auto-increment |
| `report_date` | date | The business day |
| `opening_cash` | decimal(12,2) | Cash in hand at day start |
| `opening_lipa` | decimal(12,2) | Mobile money balance at day start |
| `closing_cash` | decimal(12,2) | Cash counted at day end |
| `closing_lipa` | decimal(12,2) | Mobile money balance at day end |
| `notes` | text | Reconciliation notes |
| `created_by` | int(11) FK → users | User who created the record |
| `updated_by` | int(11) FK → users | User who last updated it |
| `created_at` | datetime | Record creation time |
| `updated_at` | datetime | Last update time (auto) |

---

### 18. `capital_injections`
Records cash or mobile money investments/top-ups into the business.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(10 UNSIGNED) PK | Auto-increment |
| `injection_date` | date | Date of injection |
| `amount` | decimal(12,2) | Amount in TZS |
| `method` | enum | `cash`, `lipa` (mobile money) |
| `description` | varchar(255) | Source/description |
| `recorded_by` | int(11) FK → users | User who recorded it |
| `created_at` | datetime | Creation time |

**Current record:** TZS 300,000 cash injection on 2026-06-06 — "Mr. Olayce top up"

---

### 19. `audit_log`
Comprehensive activity log of all significant system actions.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `user_id` | int(11) FK → users | User who performed the action |
| `action` | varchar(100) | Action type (see below) |
| `detail` | text | Human-readable description |
| `ip` | varchar(45) | Client IP address |
| `created_at` | timestamp | Timestamp of action |

**Logged action types:**
- `login` – user login events
- `stock_add` – new stock item created
- `stock_edit` – stock item updated
- `sale_add` – new transaction recorded
- `expense_add` – expense entry created
- `expense_edit` – expense updated
- `po_create` – purchase order created
- `po_receive` – purchase order marked received

---

### 20. `settings`
Key-value configuration store for system-wide settings.

| Column | Type | Notes |
|--------|------|-------|
| `id` | int(11) PK | Auto-increment |
| `setting_key` | varchar(100) | Setting name (unique) |
| `setting_value` | text | Setting value |
| `setting_group` | varchar(50) | Group (`general`, `stock`, `system`, `security`, `receipt`, `tax`) |
| `updated_at` | timestamp | Last update (auto) |

**Current settings:**

| Key | Value |
|-----|-------|
| `business_name` | Tretat Bar & Restaurant |
| `business_address` | Geza, Kigamboni |
| `business_phone` | 0766903230 / 0652552116 |
| `currency` | TZS |
| `timezone` | Africa/Dar_es_Salaam |
| `default_tax_rate` | 18.00% |
| `lipa_number` | 68708140 |
| `session_timeout` | 3600 seconds |
| `low_stock_notify` | Enabled (1) |

---

## Entity Relationship Summary

```
users ──────────────────────────┐
  ├── shifts ─── till_movements  │
  ├── sale_transactions ─── sale_items ─── stock_items
  │                  └─── transaction_payments         │
  ├── audit_log                                         │
  ├── expenses ─── categories ─────────────────────────┘
  ├── daily_cash_control
  ├── capital_injections
  └── purchase_orders ─── purchase_order_items ─── stock_items
                                                        │
                                              stock_movements
suppliers ──── stock_items
restaurant_tables ──── sale_transactions
```

---

## Notes & Observations

1. **Dual sales tables** — `sales` (legacy) and `sale_transactions` + `sale_items` (current) coexist. The newer structure is more normalized and feature-rich (supports discounts, tax, voids, tabs).
2. **Split payments** — `transaction_payments` allows one transaction to be paid across multiple methods (e.g. part cash, part mobile money).
3. **Stock auto-decrements** — `stock_movements` records an `out` entry for every item in every sale, maintaining a real-time inventory.
4. **`total_cost` in `purchase_order_items`** is a computed/generated column (not stored from the application), calculated as `quantity_ordered × unit_cost`.
5. **Passwords** are bcrypt-hashed (`$2y$12$...`), standard PHP `password_hash()` format.
6. **Currency** is uniformly TZS; no multi-currency support.
7. **`is_duplicate_flag`** on `sales` and `expenses` suggests the system has duplicate-detection logic.
```