<?php
/**
 * Central validation library
 * Returns array of error strings, empty = valid
 */

function validateStock($conn, $data, $editId = null) {
    $errors = [];
    $name   = trim($data['name'] ?? '');
    $cat    = (int)($data['category_id'] ?? 0);
    $qty    = $data['quantity'] ?? '';
    $thresh = $data['low_stock_threshold'] ?? '';
    $cost   = $data['cost_price'] ?? '';
    $sell   = $data['selling_price'] ?? '';

    if ($name === '')               $errors[] = 'Item name is required.';
    if (strlen($name) > 150)        $errors[] = 'Item name must be 150 characters or less.';
    if ($cat === 0)                 $errors[] = 'Please select a category.';
    if (!is_numeric($qty) || (float)$qty < 0)
                                    $errors[] = 'Quantity must be 0 or more.';
    if (!is_numeric($thresh) || (float)$thresh < 0)
                                    $errors[] = 'Low stock threshold must be 0 or more.';
    if (!is_numeric($cost) || (float)$cost < 0)
                                    $errors[] = 'Cost price must be 0 or more.';
    if (!is_numeric($sell) || (float)$sell < 0)
                                    $errors[] = 'Selling price must be 0 or more.';
    if (is_numeric($sell) && is_numeric($cost) && (float)$sell > 0 && (float)$sell < (float)$cost)
                                    $errors[] = 'Warning: Selling price is lower than cost price.';

    // Uniqueness check
    if ($name !== '' && $cat > 0) {
        $safeName = $conn->real_escape_string($name);
        $where    = $editId ? "AND id != $editId" : '';
        $dup = $conn->query("SELECT id FROM stock_items WHERE name='$safeName' AND category_id=$cat AND is_active=1 $where");
        if ($dup && $dup->num_rows > 0)
            $errors[] = "\"$name\" already exists in this category.";
    }

    return $errors;
}

function validateSale($data) {
    $errors = [];
    $cat    = (int)($data['category_id'] ?? 0);
    $amt    = $data['amount'] ?? '';
    $qty    = $data['quantity'] ?? 1;
    $price  = $data['unit_price'] ?? 0;
    $date   = trim($data['sale_date'] ?? '');

    if ($cat === 0)                 $errors[] = 'Please select a department.';
    if (!is_numeric($amt) || (float)$amt <= 0)
                                    $errors[] = 'Amount must be greater than 0.';
    if (!is_numeric($qty) || (float)$qty <= 0)
                                    $errors[] = 'Quantity must be greater than 0.';
    if (!is_numeric($price) || (float)$price < 0)
                                    $errors[] = 'Unit price must be 0 or more.';
    if ($date === '')               $errors[] = 'Date is required.';
    elseif (!validateDate($date))   $errors[] = 'Invalid date format.';
    elseif ($date > date('Y-m-d'))  $errors[] = 'Sale date cannot be in the future.';

    return $errors;
}

function validateExpense($data) {
    $errors = [];
    $cat    = (int)($data['category_id'] ?? 0);
    $desc   = trim($data['description'] ?? '');
    $amt    = $data['amount'] ?? '';
    $date   = trim($data['expense_date'] ?? '');

    if ($cat === 0)                 $errors[] = 'Please select a department.';
    if ($desc === '')               $errors[] = 'Description is required.';
    if (strlen($desc) > 255)        $errors[] = 'Description must be 255 characters or less.';
    if (!is_numeric($amt) || (float)$amt <= 0)
                                    $errors[] = 'Amount must be greater than 0.';
    if ($date === '')               $errors[] = 'Date is required.';
    elseif (!validateDate($date))   $errors[] = 'Invalid date format.';
    elseif ($date > date('Y-m-d'))  $errors[] = 'Expense date cannot be in the future.';

    return $errors;
}

function validateUser($data, $isEdit = false) {
    $errors = [];
    $name   = trim($data['name'] ?? '');
    $uname  = trim($data['username'] ?? '');
    $pass   = $data['password'] ?? '';
    $role   = $data['role'] ?? '';

    if ($name === '')               $errors[] = 'Full name is required.';
    if (!$isEdit && $uname === '')  $errors[] = 'Username is required.';
    if (!$isEdit && strlen($pass) < 6)
                                    $errors[] = 'Password must be at least 6 characters.';
    if ($isEdit && $pass !== '' && strlen($pass) < 6)
                                    $errors[] = 'New password must be at least 6 characters.';
    if (!in_array($role, ['admin','manager','cashier']))
                                    $errors[] = 'Invalid role selected.';
    if ($uname !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $uname))
                                    $errors[] = 'Username may only contain letters, numbers and underscores.';

    return $errors;
}

function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function validateStockAdjust($conn, $data) {
    $errors = [];
    $id     = (int)($data['item_id'] ?? 0);
    $type   = $data['movement_type'] ?? '';
    $qty    = $data['quantity'] ?? '';

    if ($id === 0)                  $errors[] = 'Invalid stock item.';
    if (!in_array($type, ['in','out','adjustment']))
                                    $errors[] = 'Invalid movement type.';
    if (!is_numeric($qty) || (float)$qty <= 0)
                                    $errors[] = 'Adjustment quantity must be greater than 0.';

    // Floor check: prevent going below zero
    if ($type === 'out' && $id > 0 && is_numeric($qty)) {
        $row = $conn->query("SELECT quantity FROM stock_items WHERE id=$id")->fetch_assoc();
        if ($row && (float)$row['quantity'] < (float)$qty)
            $errors[] = 'Cannot remove ' . (float)$qty . ' — only ' . $row['quantity'] . ' in stock.';
    }

    return $errors;
}

// Duplicate sale detection: same dept, same amount, same description, within 60 seconds
function isDuplicateSale($conn, $cat, $desc, $amt, $date) {
    $safDesc = $conn->real_escape_string($desc);
    $result  = $conn->query("
        SELECT id FROM sales
        WHERE category_id=$cat
          AND description='$safDesc'
          AND amount=$amt
          AND sale_date='$date'
          AND created_at >= NOW() - INTERVAL 60 SECOND
        LIMIT 1
    ");
    return $result && $result->num_rows > 0;
}

// Duplicate expense detection: same dept, description, amount, date, within 60 seconds
function isDuplicateExpense($conn, $cat, $desc, $amt, $date) {
    $safDesc = $conn->real_escape_string($desc);
    $result  = $conn->query("
        SELECT id FROM expenses
        WHERE category_id=$cat
          AND description='$safDesc'
          AND amount=$amt
          AND expense_date='$date'
          AND created_at >= NOW() - INTERVAL 60 SECOND
        LIMIT 1
    ");
    return $result && $result->num_rows > 0;
}

function renderErrors($errors) {
    if (empty($errors)) return '';
    $html = '<div class="alert alert-error"><div>';
    if (count($errors) === 1) {
        $html .= '&#9888; ' . htmlspecialchars($errors[0]);
    } else {
        $html .= '<strong>&#9888; Please fix the following:</strong><ul style="margin:6px 0 0 16px;">';
        foreach ($errors as $e)
            $html .= '<li>' . htmlspecialchars($e) . '</li>';
        $html .= '</ul>';
    }
    $html .= '</div></div>';
    return $html;
}
