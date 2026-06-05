<?php
/**
 * ESC/POS Receipt Printer
 * Printer : HZTZ H-Z8070
 * Port    : USB001 (Windows)
 *
 * Sends raw ESC/POS bytes directly to the printer via a
 * temporary file + Windows COPY command.
 */

class ReceiptPrinter {

    private $port   = 'USB001';
    private $buffer = '';
    private $width  = 32; // 80mm paper = ~32 chars at font size A

    // ESC/POS command constants
    const ESC = "\x1B";
    const GS  = "\x1D";
    const LF  = "\n";

    public function __construct($port = 'USB001') {
        $this->port = $port;
    }

    // ── INIT ────────────────────────────────────────────────
    public function init() {
        $this->buffer .= self::ESC . "@"; // Initialize printer
        return $this;
    }

    // ── TEXT ────────────────────────────────────────────────
    public function text($str) {
        $this->buffer .= $str;
        return $this;
    }

    public function line($str = '') {
        $this->buffer .= $str . self::LF;
        return $this;
    }

    public function emptyLine($n = 1) {
        $this->buffer .= str_repeat(self::LF, $n);
        return $this;
    }

    // ── FORMATTING ──────────────────────────────────────────
    public function bold($on = true) {
        $this->buffer .= self::ESC . "E" . ($on ? "\x01" : "\x00");
        return $this;
    }

    public function align($pos = 'left') {
        $map = ['left'=>"\x00", 'center'=>"\x01", 'right'=>"\x02"];
        $this->buffer .= self::ESC . "a" . ($map[$pos] ?? "\x00");
        return $this;
    }

    public function doubleHeight($on = true) {
        $this->buffer .= self::ESC . "!" . ($on ? "\x10" : "\x00");
        return $this;
    }

    public function divider($char = '-') {
        $this->buffer .= str_repeat($char, $this->width) . self::LF;
        return $this;
    }

    // Two-column row: left text + right text
    public function row($left, $right) {
        $left  = substr($left,  0, $this->width - strlen($right) - 1);
        $spaces = $this->width - strlen($left) - strlen($right);
        $this->buffer .= $left . str_repeat(' ', max(1, $spaces)) . $right . self::LF;
        return $this;
    }

    // Center a string
    public function center($str) {
        $pad = max(0, floor(($this->width - strlen($str)) / 2));
        $this->buffer .= str_repeat(' ', $pad) . $str . self::LF;
        return $this;
    }

    // ── CASH DRAWER ─────────────────────────────────────────
    public function openDrawer() {
        // Pin 2 kick pulse
        $this->buffer .= self::ESC . "p\x00\x19\xfa";
        return $this;
    }

    // ── CUT PAPER ───────────────────────────────────────────
    public function cut($full = false) {
        $this->buffer .= self::GS . "V" . ($full ? "\x00" : "\x01");
        return $this;
    }

    // ── PRINT ───────────────────────────────────────────────
    public function printReceipt() {
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_receipt_' . time() . '.txt';
        file_put_contents($tmpFile, $this->buffer);

        $printerName = 'POS-80';

        // Force Windows print via Notepad (works with USB printers)

        $cmd = 'copy /b "' . $tmpFile . '" \\\\localhost\\POS-80';
        exec($cmd);
        // $cmd = 'notepad /p "' . $tmpFile . '"';
        // exec($cmd);

        @unlink($tmpFile);
        $this->buffer = '';

        return true;
    }

    public function normalFont() {
        $this->buffer .= self::ESC . "M" . "\x01"; // Font 
        $this->buffer .= self::ESC . "!" . "\x00"; // Normal size
        return $this;
    }
}

/**
 * Build and print a sale receipt
 */
function printSaleReceiptt($conn, $saleId) {
    require_once __DIR__ . '/../settings.php';

    $sale = $conn->query("
        SELECT s.*, c.name AS cat
        FROM sales s
        JOIN categories c ON s.category_id = c.id
        WHERE s.id = $saleId
    ")->fetch_assoc();

    if (!$sale) return false;

    $bizName    = getSetting($conn, 'business_name',   'Bar & Restaurant');
    $bizPhone   = getSetting($conn, 'business_phone',  '');
    $bizAddress = getSetting($conn, 'business_address','');
    $footer     = getSetting($conn, 'receipt_footer',  'Thank you!');
    $currency   = getSetting($conn, 'currency_symbol', 'TZS');

    $payLabels  = [
        'cash'         => 'Cash',
        'mobile_money' => 'Mobile Money',
        'card'         => 'Card',
        'other'        => 'Other',
    ];
    $payMethod  = $payLabels[$sale['payment_method'] ?? 'cash'] ?? 'Cash';
    $receiptNo  = str_pad($saleId, 6, '0', STR_PAD_LEFT);

    $p = new ReceiptPrinter('USB001');
    $p->init()
      ->align('center')
      ->bold(true)->doubleHeight(true)
      ->line($bizName)
      ->doubleHeight(false)->bold(false)
      ->line($bizAddress ?: '')
      ->line($bizPhone   ?: '')
      ->emptyLine()
      ->bold(true)->line('RECEIPT')->bold(false)
      ->align('left')
      ->divider()
      ->row('Receipt #', $receiptNo)
      ->row('Date',      date('d/m/Y H:i', strtotime($sale['created_at'])))
      ->row('Cashier',   $_SESSION['user_name'] ?? 'Staff')
      ->divider();

    // Items
    $desc = $sale['description'] ?: $sale['cat'];
    $qty  = isset($sale['quantity'])   ? (float)$sale['quantity']   : 1;
    $price= isset($sale['unit_price']) ? (float)$sale['unit_price'] : (float)$sale['amount'];

    $p->row($desc, '')
      ->row('  ' . $qty . ' x ' . number_format($price, 0), $currency . ' ' . number_format($sale['amount'], 0))
      ->divider()
      ->bold(true)
      ->row('TOTAL', $currency . ' ' . number_format($sale['amount'], 2))
      ->bold(false)
      ->row('Payment', $payMethod)
      ->divider()
      ->emptyLine()
      ->align('center')
      ->line($footer)
      ->emptyLine(3)
      ->cut()
      ->openDrawer()   // open cash drawer on cash payments only
      ->printReceipt();

    return true;
}

function printSaleReceipt($conn, $saleId) {
    require_once __DIR__ . '/../settings.php';

    $sale = $conn->query("
        SELECT s.*, c.name AS cat
        FROM sales s
        JOIN categories c ON s.category_id = c.id
        WHERE s.id = $saleId
    ")->fetch_assoc();

    if (!$sale) {
        return false;
    }

    $bizName    = getSetting($conn, 'business_name', 'Bar & Restaurant');
    $bizPhone   = getSetting($conn, 'business_phone', '');
    $bizAddress = getSetting($conn, 'business_address', '');
    $footer     = getSetting($conn, 'receipt_footer', 'Thank you for your visit!');
    $currency   = getSetting($conn, 'currency_symbol', 'TZS');

    $payLabels = [
        'cash'         => 'Cash',
        'mobile_money' => 'Mobile Money',
        'card'         => 'Card',
        'other'        => 'Other',
    ];

    $payMethod = $payLabels[$sale['payment_method'] ?? 'cash'] ?? 'Cash';
    $receiptNo = str_pad($saleId, 6, '0', STR_PAD_LEFT);

    $desc  = trim($sale['description'] ?: $sale['cat']);
    $qty   = (float)($sale['quantity'] ?? 1);
    $price = (float)($sale['unit_price'] ?? $sale['amount']);
    $total = (float)$sale['amount'];

    $p = new ReceiptPrinter('USB001');

    $p->init()
      ->normalFont()
      // HEADER
      ->align('center')
      ->bold(true)
      ->doubleHeight(true)
      ->line($bizName)
      ->doubleHeight(false)
      ->bold(false);

    if (!empty($bizAddress)) {
        $p->line($bizAddress);
    }

    if (!empty($bizPhone)) {
        $p->line('Tel: ' . $bizPhone);
    }

    $p->divider('=')
      ->bold(true)
      ->line('CUSTOMER RECEIPT')
      ->bold(false)
      ->divider()

      // RECEIPT INFO
      ->align('left')
      ->row('Receipt #', $receiptNo)
      ->row('Date', date('d/m/Y H:i', strtotime($sale['created_at'])))
      ->row('Cashier', $_SESSION['user_name'] ?? 'Staff')
      ->divider();

    // ITEM
    $p->line(substr($desc, 0, 32));

    $p->row(
        $qty . ' x ' . number_format($price, 0),
        number_format($total, 0)
    );

    $p->divider();

    // TOTAL
    $p->bold(true)
      ->row('TOTAL', $currency . ' ' . number_format($total, 0))
      ->bold(false)
      ->divider();

    // PAYMENT
    $p->row('Payment', $payMethod);

    $p->divider()
      ->align('center')
      ->line('*** THANK YOU ***');

    if (!empty($footer)) {
        $p->line($footer);
    }

    $p->emptyLine(2)
      ->cut()
      ->openDrawer()
      ->printReceipt();

    return true;
}