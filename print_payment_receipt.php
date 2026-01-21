<?php
// print_payment_receipt.php - Printable receipt for a payment line
require_once 'database.php';
require_once 'config/simple_auth.php';

session_start();

$database = new Database();
$db = $database->getConnection();
$auth = new SimpleAuth($db);

if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$payment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($payment_id <= 0) {
    die('Payment ID is required');
}

// Fetch payment + invoice + customer info
$stmt = $db->prepare("
    SELECT 
        sp.*, 
        si.invoice_no, si.invoice_date, si.total_amount, si.paid_amount, si.balance_amount,
        si.subtotal, si.discount_amount, si.tax_amount,
        c.customer_name, c.customer_code, c.address_line1, c.address_line2, c.city, c.phone,
        pl.currency,
        au.username AS created_by_name, au.full_name AS created_by_full_name
    FROM sales_payments sp
    JOIN sales_invoices si ON sp.invoice_id = si.id
    JOIN customers c ON si.customer_id = c.id
    LEFT JOIN price_lists pl ON si.price_list_id = pl.id
    LEFT JOIN admin_users au ON sp.created_by = au.id
    WHERE sp.id = ?
");
$stmt->execute([$payment_id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die('Payment not found');
}

// Helpers
function format_currency($amount) {
    return number_format($amount, 2);
}

$currency = $payment['currency'] ?? 'LKR';
$method_labels = [
    'cash' => 'Cash',
    'card' => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'cheque' => 'Cheque',
];
$method_label = $method_labels[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt <?php echo htmlspecialchars($payment['payment_no']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; color: #333; }
        .receipt-container { max-width: 800px; margin: 0 auto; background: white; padding: 24px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .letterhead { border: 2px solid #333; padding: 15px; margin-bottom: 20px; }
        .letterhead-inner { display: flex; justify-content: space-between; align-items: flex-start; }
        .company-info { flex: 1; }
        .company-name { font-size: 24px; font-weight: bold; color: #c41e3a; font-style: italic; margin-bottom: 8px; }
        .company-contacts { font-size: 11px; color: #333; line-height: 1.6; }
        .company-contacts a { color: #0066cc; text-decoration: underline; }
        .reg-number { margin-top: 10px; font-size: 11px; border-top: 1px solid #333; padding-top: 8px; }
        .logo-container { width: 120px; text-align: right; }
        .logo-img { width: 120px; height: auto; max-height: 120px; object-fit: contain; }
        .office-address { text-align: right; font-size: 11px; color: #333; line-height: 1.6; }
        .document-title { text-align: center; font-size: 18px; font-weight: bold; color: #333; padding: 10px 0; margin: 15px 0; border-top: 2px solid #333; border-bottom: 2px solid #333; }
        .meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .meta-card { padding: 12px; border: 1px solid #ddd; border-radius: 6px; background: #fafafa; }
        .meta-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        .meta-value { font-size: 14px; font-weight: 600; margin-top: 4px; }
        .section { margin-top: 18px; }
        .section h3 { font-size: 14px; margin-bottom: 8px; color: #555; text-transform: uppercase; letter-spacing: 0.5px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border: 1px solid #ddd; font-size: 13px; }
        th { background: #f0f0f0; text-align: left; text-transform: uppercase; font-size: 11px; letter-spacing: 0.4px; }
        .text-right { text-align: right; }
        .badge { display: inline-block; padding: 4px 8px; font-size: 11px; border-radius: 4px; font-weight: 600; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .totals { max-width: 320px; margin-left: auto; }
        .totals td { border: none; padding: 6px 0; }
        .totals .label { color: #666; }
        .totals .value { text-align: right; font-weight: 600; }
        .totals .grand { border-top: 2px solid #333; padding-top: 10px; font-size: 15px; }
        .print-actions { text-align: center; margin-top: 18px; }
        .btn { background: #2563eb; color: white; border: none; padding: 10px 24px; font-size: 14px; border-radius: 4px; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        @media print {
            body { background: white; padding: 0; }
            .receipt-container { box-shadow: none; margin: 0; }
            .print-actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="letterhead">
            <div class="letterhead-inner">
                <div class="company-info">
                    <div class="company-name">LAKGOVI MARKETING SERVICES</div>
                    <div class="company-contacts">
                        Tel: 011-2233387 &nbsp;&nbsp;&nbsp; Fax: 011-2233487<br>
                        Email: <a href="mailto:lakgovimarketing@gmail.com">lakgovimarketing@gmail.com</a>
                    </div>
                    <div class="reg-number">
                        Reg. No: Wයු 10395
                    </div>
                </div>
                <div style="text-align: right;">
                    <div class="logo-container" style="display: inline-block;">
                        <img src="public/lakgovilogo.jpeg" alt="Lakgovi Logo" class="logo-img">
                    </div>
                    <div class="office-address">
                        Office: No-391,<br>
                        Awariyawaththa Road,<br>
                        Batagama North,<br>
                        Ja-Ela.
                    </div>
                </div>
            </div>
        </div>

        <div class="document-title">PAYMENT RECEIPT</div>

        <div class="meta-grid">
            <div class="meta-card">
                <div class="meta-label">Receipt No</div>
                <div class="meta-value"><?php echo htmlspecialchars($payment['payment_no']); ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Date</div>
                <div class="meta-value"><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Invoice</div>
                <div class="meta-value">#<?php echo htmlspecialchars($payment['invoice_no']); ?> (<?php echo date('d M Y', strtotime($payment['invoice_date'])); ?>)</div>
            </div>
            <div class="meta-card">
                <div class="meta-label">Amount</div>
                <div class="meta-value"><?php echo htmlspecialchars($currency); ?> <?php echo format_currency($payment['amount']); ?></div>
            </div>
        </div>

        <div class="section">
            <h3>Customer</h3>
            <table>
                <tr>
                    <th style="width: 150px;">Name</th>
                    <td><?php echo htmlspecialchars($payment['customer_name']); ?> (<?php echo htmlspecialchars($payment['customer_code']); ?>)</td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td>
                        <?php 
                        $addr_parts = array_filter([$payment['address_line1'], $payment['address_line2'], $payment['city']]);
                        echo htmlspecialchars(implode(', ', $addr_parts));
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Contact</th>
                    <td><?php echo htmlspecialchars($payment['phone'] ?: '-'); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h3>Payment Details</h3>
            <table>
                <tr>
                    <th style="width: 150px;">Method</th>
                    <td><?php echo htmlspecialchars($method_label); ?></td>
                </tr>
                <tr>
                    <th>Reference</th>
                    <td>
                        <?php if ($payment['payment_method'] === 'cheque'): ?>
                            Cheque #: <?php echo htmlspecialchars($payment['cheque_number'] ?? '-'); ?>
                            <?php if ($payment['cheque_date']): ?> | Date: <?php echo date('d M Y', strtotime($payment['cheque_date'])); ?><?php endif; ?>
                            <?php if ($payment['bank_name']): ?> | Bank: <?php echo htmlspecialchars($payment['bank_name']); ?><?php endif; ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($payment['reference_no'] ?: '-'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($payment['payment_method'] === 'cheque'): ?>
                <tr>
                    <th>Status</th>
                    <td>
                        <?php 
                        $status = $payment['cheque_status'] ?: 'pending';
                        $badgeClass = $status === 'cleared' ? 'badge-green' : ($status === 'pending' ? 'badge-yellow' : 'badge-red');
                        ?>
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span>
                        <?php if ($status === 'cleared' && $payment['clearance_date']): ?>
                            (Cleared: <?php echo date('d M Y', strtotime($payment['clearance_date'])); ?>)
                        <?php elseif ($status === 'bounced' && $payment['bounce_reason']): ?>
                            (Reason: <?php echo htmlspecialchars($payment['bounce_reason']); ?>)
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="section">
            <h3>Invoice Summary</h3>
            <table class="totals">
                <tr>
                    <td class="label">Invoice Total:</td>
                    <td class="value"><?php echo htmlspecialchars($currency); ?> <?php echo format_currency($payment['total_amount']); ?></td>
                </tr>
                <tr>
                    <td class="label">Paid To Date:</td>
                    <td class="value"><?php echo htmlspecialchars($currency); ?> <?php echo format_currency($payment['paid_amount']); ?></td>
                </tr>
                <tr>
                    <td class="label">Balance:</td>
                    <td class="value"><?php echo htmlspecialchars($currency); ?> <?php echo format_currency($payment['balance_amount']); ?></td>
                </tr>
                <tr class="grand">
                    <td class="label">This Payment:</td>
                    <td class="value"><?php echo htmlspecialchars($currency); ?> <?php echo format_currency($payment['amount']); ?></td>
                </tr>
            </table>
        </div>

        <div class="section" style="margin-top: 28px;">
            <table>
                <tr>
                    <th style="width: 50%; text-align: center;">Customer Signature</th>
                    <th style="width: 50%; text-align: center;">Authorized Signature</th>
                </tr>
                <tr>
                    <td style="height: 70px;"></td>
                    <td></td>
                </tr>
            </table>
        </div>

        <div class="section" style="text-align: center; font-size: 11px; color: #777; margin-top: 20px;">
            Generated on <?php echo date('d M Y H:i'); ?> by <?php echo htmlspecialchars($payment['created_by_full_name'] ?? $payment['created_by_name'] ?? 'System'); ?>
        </div>

        <div class="print-actions">
            <button class="btn" onclick="window.print()">🖨️ Print Receipt</button>
            <a href="sales_payments.php" class="btn" style="background:#6b7280; margin-left:6px; text-decoration:none;">← Back</a>
        </div>
    </div>
</body>
</html>
