<?php

class StockService
{
    public static function deduct($conn, $stockItemId, $qty, $note = 'Sale')
    {
        $stmt = $conn->prepare("
            UPDATE stock_items
            SET quantity = GREATEST(0, quantity - ?)
            WHERE id = ?
        ");
        $stmt->bind_param("di", $qty, $stockItemId);
        $stmt->execute();
        $stmt->close();

        self::log($conn, $stockItemId, 'out', $qty, $note);
    }

    public static function restore($conn, $stockItemId, $qty, $note = 'Reversal')
    {
        $stmt = $conn->prepare("
            UPDATE stock_items
            SET quantity = quantity + ?
            WHERE id = ?
        ");
        $stmt->bind_param("di", $qty, $stockItemId);
        $stmt->execute();
        $stmt->close();

        self::log($conn, $stockItemId, 'in', $qty, $note);
    }

    public static function adjustDifference($conn, $stockItemId, $oldQty, $newQty, $note = 'Edit Sale')
    {
        $diff = $newQty - $oldQty;

        if ($diff > 0) {
            self::deduct($conn, $stockItemId, $diff, $note);
        } elseif ($diff < 0) {
            self::restore($conn, $stockItemId, abs($diff), $note);
        }
    }

    private static function log($conn, $stockItemId, $type, $qty, $note)
    {
        $today = date('Y-m-d');

        $stmt = $conn->prepare("
            INSERT INTO stock_movements
            (stock_item_id, movement_type, quantity, note, moved_at)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->bind_param("isdss", $stockItemId, $type, $qty, $note, $today);
        $stmt->execute();
        $stmt->close();
    }
}
