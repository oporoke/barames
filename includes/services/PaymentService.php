<?php
class PaymentService {

    /**
     * Attach one payment leg to a transaction.
     * Call multiple times for split payments.
     */
    public static function addPayment(
        mysqli $conn,
        int    $transactionId,
        string $method,
        float  $amount,
        string $reference = ''
    ): int {
        $stmt = $conn->prepare("
            INSERT INTO transaction_payments
                (transaction_id, method, amount, reference)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isds", $transactionId, $method, $amount, $reference);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Total amount paid so far against a transaction.
     */
    public static function totalPaid(mysqli $conn, int $transactionId): float {
        $res = $conn->query("
            SELECT COALESCE(SUM(amount), 0) AS paid
            FROM transaction_payments
            WHERE transaction_id = $transactionId
        ");
        return (float)$res->fetch_assoc()['paid'];
    }

    /**
     * All payment legs for a transaction.
     */
    public static function getPayments(mysqli $conn, int $transactionId): array {
        $res = $conn->query("
            SELECT * FROM transaction_payments
            WHERE transaction_id = $transactionId
            ORDER BY id ASC
        ");
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Validate that payments cover the total.
     * Returns array of error strings (empty = OK).
     */
    public static function validate(array $payments, float $total): array {
        $errors = [];
        $paid   = 0;

        if (empty($payments)) {
            $errors[] = 'At least one payment method is required.';
            return $errors;
        }

        foreach ($payments as $i => $p) {
            $amt = (float)($p['amount'] ?? 0);
            $method = $p['method'] ?? '';

            if ($amt <= 0) {
                $errors[] = "Payment #".($i+1).": amount must be greater than 0.";
            }
            if (!in_array($method, ['cash','mobile_money','card','other'])) {
                $errors[] = "Payment #".($i+1).": invalid method.";
            }
            if ($method === 'mobile_money' && empty($p['reference'])) {
                $errors[] = "Payment #".($i+1).": M-Pesa reference required.";
            }
            $paid += $amt;
        }

        if ($paid < $total) {
            $errors[] = sprintf(
                'Underpayment: TZS %s paid, TZS %s required.',
                number_format($paid, 2),
                number_format($total, 2)
            );
        }

        return $errors;
    }

    /**
     * Calculate change due (overpayment).
     */
    public static function changeDue(array $payments, float $total): float {
        $paid = array_sum(array_column($payments, 'amount'));
        return max(0, $paid - $total);
    }
}