<?php
// print_invoice.php - Printable invoice/receipt
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

$invoice_id = $_GET['id'] ?? null;

if (!$invoice_id) {
    header('Location: sales_list.php');
    exit;
}

// Fetch invoice details
try {
    $stmt = $db->prepare("
        SELECT 
            si.*,
            c.customer_code,
            c.customer_name,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.postal_code,
            c.phone,
            c.email,
            pl.price_list_name,
            pl.currency,
            au.username as created_by_name
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        JOIN price_lists pl ON si.price_list_id = pl.id
        JOIN admin_users au ON si.created_by = au.id
        WHERE si.id = ?
    ");
    
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        die('Invoice not found');
    }
    
    // Fetch invoice items
    $stmt = $db->prepare("
        SELECT 
            sii.*,
            i.code as item_code,
            i.name as item_name,
            u.symbol as unit,
            l.name as location_name
        FROM sales_invoice_items sii
        JOIN items i ON sii.item_id = i.id
        JOIN units u ON i.unit_id = u.id
        JOIN locations l ON sii.location_id = l.id
        WHERE sii.invoice_id = ?
        ORDER BY sii.id
    ");
    
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch payments
    $stmt = $db->prepare("
        SELECT 
            sp.*,
            au.username as created_by_name
        FROM sales_payments sp
        JOIN admin_users au ON sp.created_by = au.id
        WHERE sp.invoice_id = ?
        ORDER BY sp.payment_date, sp.created_at
    ");
    
    $stmt->execute([$invoice_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die('Error fetching invoice: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo htmlspecialchars($invoice['invoice_no']); ?></title>
    <style>
        @page { size: 9.5in 5.4in; margin: 0.3in; }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .invoice-container {
            width: 9.5in;
            min-height: 5.4in;
            margin: 0 auto;
            background: white;
            padding: 0.3in;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        /* Letterhead styles (matching quotation header) */
        .letterhead {
            border: 1.5px solid #333;
            padding: 8px;
            margin-bottom: 10px;
            page-break-inside: avoid;
        }
        
        .letterhead-inner {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .company-info {
            flex: 1;
        }
        
        .company-name {
            font-size: 16px;
            font-weight: bold;
            color: #c41e3a;
            font-style: italic;
            margin-bottom: 4px;
        }
        
        .company-contacts {
            font-size: 8px;
            color: #333;
            line-height: 1.4;
        }
        
        .company-contacts a {
            color: #0066cc;
            text-decoration: underline;
        }
        
        .reg-number {
            margin-top: 5px;
            font-size: 8px;
            border-top: 1px solid #333;
            padding-top: 4px;
        }
        
        .logo-container {
            width: 70px;
            text-align: right;
        }
        
        .logo-img {
            width: 70px;
            height: auto;
            max-height: 70px;
            object-fit: contain;
        }
        
        .office-address {
            text-align: right;
            font-size: 8px;
            color: #333;
            line-height: 1.4;
        }
        
        /* Document Title bar */
        .document-title {
            text-align: center;
            font-size: 13px;
            font-weight: bold;
            color: #333;
            padding: 6px 0;
            margin: 8px 0;
            border-top: 1.5px solid #333;
            border-bottom: 1.5px solid #333;
        }
        
        .invoice-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        
        .customer-info, .invoice-info {
            flex: 1;
        }
        
        .section-title {
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 5px;
            font-size: 9px;
            text-transform: uppercase;
        }
        
        .info-line {
            margin-bottom: 3px;
            font-size: 8px;
            color: #333;
        }
        
        .info-line strong {
            display: inline-block;
            width: 70px;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        
        thead {
            background: #2563eb;
            color: white;
            page-break-inside: avoid;
            page-break-after: avoid;
        }
        
        th, td {
            padding: 5px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
        }
        
        td {
            font-size: 8px;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        tbody tr {
            page-break-inside: avoid;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .totals-section {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
            page-break-inside: avoid;
        }
        
        .totals-table {
            width: 220px;
        }
        
        .totals-table tr {
            border-bottom: 1px solid #eee;
        }
        
        .totals-table td {
            padding: 4px 8px;
            font-size: 8px;
        }
        
        .totals-table .total-row {
            background: #2563eb;
            color: white;
            font-size: 10px;
            font-weight: bold;
        }
        
        .payment-section {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1.5px solid #eee;
            page-break-inside: avoid;
        }
        
        .payment-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-paid {
            background: #10b981;
            color: white;
        }
        
        .badge-partial {
            background: #f59e0b;
            color: white;
        }
        
        .badge-unpaid {
            background: #ef4444;
            color: white;
        }
        
        .footer {
            margin-top: 15px;
            padding-top: 8px;
            border-top: 1.5px solid #eee;
            text-align: center;
            color: #666;
            font-size: 7px;
        }
        
        .notes-section {
            margin-top: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-left: 2px solid #2563eb;
        }
        
        .notes-section h3 {
            color: #2563eb;
            margin-bottom: 5px;
            font-size: 9px;
        }
        
        .actions {
            text-align: center;
            margin: 12px 0;
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1d4ed8;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .invoice-container {
                box-shadow: none;
                width: 9.5in;
                min-height: 5.4in;
                padding: 0;
            }
            
            .actions {
                display: none;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
            }
        }
        
        @media screen {
            body { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print Invoice</button>
        <a href="cashier.php" class="btn btn-secondary">← Back to Cashier</a>
        <a href="sales_list.php" class="btn btn-secondary">View All Sales</a>
    </div>

    <div class="invoice-container">
        <!-- Letterhead (from quotation) -->
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

        <!-- Document Title -->
        <div class="document-title">INVOICE</div>

        <!-- Invoice Meta Info -->
        <div class="invoice-meta">
            <div class="customer-info">
                <div class="section-title">Bill To</div>
                <div style="font-size: 16px; font-weight: bold; margin-bottom: 8px;">
                    <?php echo htmlspecialchars($invoice['customer_name']); ?>
                </div>
                <div class="info-line">
                    <?php echo htmlspecialchars($invoice['customer_code']); ?>
                </div>
                <?php if ($invoice['address_line1']): ?>
                    <div class="info-line">
                        <?php echo htmlspecialchars($invoice['address_line1']); ?>
                    </div>
                <?php endif; ?>
                <?php if ($invoice['address_line2']): ?>
                    <div class="info-line">
                        <?php echo htmlspecialchars($invoice['address_line2']); ?>
                    </div>
                <?php endif; ?>
                <?php if ($invoice['city']): ?>
                    <div class="info-line">
                        <?php echo htmlspecialchars($invoice['city']); ?>
                        <?php if ($invoice['postal_code']): ?>
                            - <?php echo htmlspecialchars($invoice['postal_code']); ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($invoice['phone']): ?>
                    <div class="info-line">
                        Phone: <?php echo htmlspecialchars($invoice['phone']); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="invoice-info">
                <div class="section-title">Invoice Details</div>
                <div class="info-line">
                    <strong>Invoice No:</strong> 
                    <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                </div>
                <div class="info-line">
                    <strong>Invoice Date:</strong> 
                    <?php echo date('d M Y', strtotime($invoice['invoice_date'])); ?>
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">#</th>
                    <th style="width: 15%;">Item Code</th>
                    <th style="width: 35%;">Description</th>
                    <th class="text-center" style="width: 10%;">Qty</th>
                    <th class="text-right" style="width: 12%;">Unit Price</th>
                    <th class="text-center" style="width: 8%;">Disc %</th>
                    <th class="text-right" style="width: 15%;">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $row_num = 1;
                foreach ($items as $item): 
                ?>
                    <tr>
                        <td class="text-center"><?php echo $row_num++; ?></td>
                        <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                            <br>
                            <small style="color: #666;">Unit: <?php echo htmlspecialchars($item['unit']); ?></small>
                        </td>
                        <td class="text-center">
                            <?php echo number_format($item['quantity'], 3); ?>
                        </td>
                        <td class="text-right">
                            <?php echo number_format($item['unit_price'], 2); ?>
                        </td>
                        <td class="text-center">
                            <?php echo number_format($item['discount_percentage'], 2); ?>%
                        </td>
                        <td class="text-right">
                            <strong><?php echo number_format($item['line_total'], 2); ?></strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td><strong>Subtotal:</strong></td>
                    <td class="text-right"><?php echo number_format($invoice['subtotal'], 2); ?></td>
                </tr>
                <?php if ($invoice['discount_amount'] > 0): ?>
                    <tr>
                        <td><strong>Discount:</strong></td>
                        <td class="text-right" style="color: #ef4444;">
                            -<?php echo number_format($invoice['discount_amount'], 2); ?>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ($invoice['tax_amount'] > 0): ?>
                    <tr>
                        <td><strong>Tax:</strong></td>
                        <td class="text-right"><?php echo number_format($invoice['tax_amount'], 2); ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td><strong>TOTAL:</strong></td>
                    <td class="text-right">
                        <?php echo $invoice['currency']; ?> <?php echo number_format($invoice['total_amount'], 2); ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Payment Section -->
        <?php if (!empty($payments)): ?>
            <div class="payment-section">
                <div class="section-title">Payment History</div>
                <table style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>Payment No</th>
                            <th>Date</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payment['payment_no']); ?></td>
                                <td><?php echo date('d M Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo ucwords(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['reference_no'] ?? '-'); ?></td>
                                <td class="text-right">
                                    <strong><?php echo number_format($payment['amount'], 2); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 15px; text-align: right;">
                    <div style="font-size: 16px; color: #666;">
                        <strong>Total Paid:</strong> 
                        <?php echo $invoice['currency']; ?> <?php echo number_format($invoice['paid_amount'], 2); ?>
                    </div>
                    <?php if ($invoice['balance_amount'] > 0): ?>
                        <div style="font-size: 18px; color: #ef4444; margin-top: 5px;">
                            <strong>Balance Due:</strong> 
                            <?php echo $invoice['currency']; ?> <?php echo number_format($invoice['balance_amount'], 2); ?>
                        </div>
                    <?php elseif ($invoice['paid_amount'] > $invoice['total_amount']): ?>
                        <div style="font-size: 18px; color: #10b981; margin-top: 5px;">
                            <strong>Change Given:</strong> 
                            <?php echo $invoice['currency']; ?> <?php echo number_format($invoice['paid_amount'] - $invoice['total_amount'], 2); ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size: 18px; color: #10b981; margin-top: 5px;">
                            <strong>✓ FULLY PAID</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Notes Section -->
        <?php if ($invoice['notes']): ?>
            <div class="notes-section">
                <h3>Notes</h3>
                <p><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <!-- <div class="footer">
            <p><strong>Thank you for your business!</strong></p>
            <p style="margin-top: 10px;">
                This is a computer-generated invoice and does not require a signature.<br>
                Created by: <?php echo htmlspecialchars($invoice['created_by_name']); ?> on 
                <?php echo date('d M Y h:i A', strtotime($invoice['created_at'])); ?>
            </p>
            <p style="margin-top: 10px; font-size: 11px; color: #999;">
                Invoice ID: <?php echo $invoice['id']; ?> | 
                For queries, please contact us at info@lakgovifoods.com
            </p>
        </div> -->
    </div>

    <div class="actions">
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print Invoice</button>
        <a href="cashier.php" class="btn btn-secondary">← Back to Cashier</a>
        <a href="sales_list.php" class="btn btn-secondary">View All Sales</a>
    </div>
</body>
</html>