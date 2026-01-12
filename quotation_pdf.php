<?php
// quotation_pdf.php - Printable quotation with letterhead
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

$quotation_id = $_GET['id'] ?? null;

if (!$quotation_id) {
    header('Location: quotation_list.php');
    exit;
}

// Fetch quotation details
try {
    $stmt = $db->prepare("
        SELECT 
            q.*,
            c.customer_code,
            c.customer_name,
            c.contact_person,
            c.email as customer_email,
            c.phone as customer_phone,
            c.mobile as customer_mobile,
            c.address_line1,
            c.address_line2,
            c.city,
            c.state,
            c.postal_code,
            c.country,
            pl.price_list_code,
            pl.price_list_name,
            au.username as created_by_name,
            au.full_name as created_by_full_name
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        LEFT JOIN price_lists pl ON q.price_list_id = pl.id
        JOIN admin_users au ON q.created_by = au.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        die('Quotation not found');
    }
    
    // Fetch quotation items
    $stmt = $db->prepare("
        SELECT qi.*
        FROM quotation_items qi
        WHERE qi.quotation_id = ?
        ORDER BY qi.sort_order, qi.id
    ");
    $stmt->execute([$quotation_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die('Error loading quotation: ' . $e->getMessage());
}

// Build customer address
$address_parts = array_filter([
    $quotation['address_line1'],
    $quotation['address_line2'],
    $quotation['city'],
    $quotation['state'],
    $quotation['postal_code']
]);
$customer_address = implode(', ', $address_parts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation <?php echo htmlspecialchars($quotation['quotation_no']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
            background: #f5f5f5;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 15mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        /* Letterhead */
        .letterhead {
            border: 2px solid #333;
            padding: 15px;
            margin-bottom: 20px;
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
            font-size: 24px;
            font-weight: bold;
            color: #c41e3a;
            font-style: italic;
            margin-bottom: 8px;
        }
        
        .company-contacts {
            font-size: 11px;
            color: #333;
            line-height: 1.6;
        }
        
        .company-contacts a {
            color: #0066cc;
            text-decoration: underline;
        }
        
        .reg-number {
            margin-top: 10px;
            font-size: 11px;
            border-top: 1px solid #333;
            padding-top: 8px;
        }
        
        .logo-container {
            width: 120px;
            text-align: right;
        }
        
        .logo-img {
            width: 120px;
            height: auto;
            max-height: 120px;
            object-fit: contain;
        }
        
        .office-address {
            text-align: right;
            font-size: 11px;
            color: #333;
            line-height: 1.6;
        }
        
        /* Document Title */
        .document-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            color: #333;
            padding: 10px 0;
            margin: 15px 0;
            border-top: 2px solid #333;
            border-bottom: 2px solid #333;
        }
        
        /* Info Section */
        .info-section {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .customer-info, .quotation-info {
            width: 48%;
        }
        
        .info-label {
            font-weight: bold;
            color: #666;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        
        .info-value {
            font-size: 12px;
            margin-bottom: 8px;
        }
        
        .info-value strong {
            font-size: 14px;
        }
        
        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .items-table th {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 8px 6px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: bold;
        }
        
        .items-table th.text-right {
            text-align: right;
        }
        
        .items-table th.text-center {
            text-align: center;
        }
        
        .items-table td {
            border: 1px solid #ccc;
            padding: 8px 6px;
            font-size: 11px;
            vertical-align: top;
        }
        
        .items-table td.text-right {
            text-align: right;
        }
        
        .items-table td.text-center {
            text-align: center;
        }
        
        .item-description {
            color: #666;
            font-size: 10px;
            margin-top: 3px;
        }
        
        /* Totals */
        .totals-section {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 20px;
        }
        
        .totals-table {
            width: 300px;
        }
        
        .totals-table td {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .totals-table td:first-child {
            text-align: right;
            color: #666;
        }
        
        .totals-table td:last-child {
            text-align: right;
            font-weight: 500;
        }
        
        .totals-table .grand-total td {
            font-size: 14px;
            font-weight: bold;
            border-top: 2px solid #333;
            padding-top: 10px;
        }
        
        .totals-table .grand-total td:last-child {
            color: #c41e3a;
        }
        
        /* Notes Section */
        .notes-section {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }
        
        .notes-title {
            font-weight: bold;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
        }
        
        .notes-content {
            font-size: 11px;
            color: #333;
            white-space: pre-line;
            line-height: 1.6;
        }
        
        /* Footer */
        .quotation-footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            width: 200px;
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 50px;
            padding-top: 5px;
            font-size: 10px;
            color: #666;
        }
        
        .validity-notice {
            font-size: 11px;
            color: #666;
            text-align: center;
            margin-top: 20px;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        /* Print styles */
        @media print {
            body {
                background: white;
            }
            
            .print-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        /* Print button */
        .print-actions {
            text-align: center;
            padding: 15px;
            background: #333;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
        }
        
        .print-btn {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 30px;
            font-size: 14px;
            cursor: pointer;
            border-radius: 4px;
            margin: 0 5px;
        }
        
        .print-btn:hover {
            background: #45a049;
        }
        
        .back-btn {
            background: #666;
        }
        
        .back-btn:hover {
            background: #555;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 3px;
            margin-left: 10px;
        }
        
        .status-draft { background: #e0e0e0; color: #666; }
        .status-sent { background: #bbdefb; color: #1565c0; }
        .status-accepted { background: #c8e6c9; color: #2e7d32; }
        .status-rejected { background: #ffcdd2; color: #c62828; }
        .status-expired { background: #fff3e0; color: #ef6c00; }
    </style>
</head>
<body>
    <!-- Print Actions Bar -->
    <div class="print-actions no-print">
        <button class="print-btn back-btn" onclick="window.location.href='quotation_view.php?id=<?php echo $quotation_id; ?>'">← Back</button>
        <button class="print-btn" onclick="window.print()">🖨️ Print Quotation</button>
    </div>
    
    <div class="print-container" style="margin-bottom: 80px;">
        <!-- Letterhead -->
        <div class="letterhead">
            <div class="letterhead-inner">
                <div class="company-info">
                    <div class="company-name">LAKGOVI MARKETING SERVICES</div>
                    <div class="company-contacts">
                        Tel: 011-2233387 &nbsp;&nbsp;&nbsp; Fax: 011-2233487<br>
                        Email: <a href="mailto:lakgovimarketing@gmail.com">lakgovimarketing@gmail.com</a>
                    </div>
                    <div class="reg-number">
                        Reg. No: WG 10395
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
        <div class="document-title">
            QUOTATION
        </div>
        
        <!-- Info Section -->
        <div class="info-section">
            <div class="customer-info">
                <div class="info-label">Bill To:</div>
                <div class="info-value">
                    <strong><?php echo htmlspecialchars($quotation['customer_name']); ?></strong><br>
                    <?php if ($quotation['contact_person']): ?>
                        Attn: <?php echo htmlspecialchars($quotation['contact_person']); ?><br>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($customer_address); ?><br>
                    <?php if ($quotation['customer_phone'] || $quotation['customer_mobile']): ?>
                        Tel: <?php echo htmlspecialchars($quotation['customer_phone'] ?: $quotation['customer_mobile']); ?><br>
                    <?php endif; ?>
                    <?php if ($quotation['customer_email']): ?>
                        Email: <?php echo htmlspecialchars($quotation['customer_email']); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="quotation-info" style="text-align: right;">
                <table style="margin-left: auto;">
                    <tr>
                        <td class="info-label" style="text-align: right; padding-right: 10px;">Quotation No:</td>
                        <td><strong><?php echo htmlspecialchars($quotation['quotation_no']); ?></strong></td>
                    </tr>
                    <tr>
                        <td class="info-label" style="text-align: right; padding-right: 10px;">Date:</td>
                        <td><?php echo date('F d, Y', strtotime($quotation['quotation_date'])); ?></td>
                    </tr>
                    <tr>
                        <td class="info-label" style="text-align: right; padding-right: 10px;">Valid Until:</td>
                        <td><?php echo $quotation['valid_until'] ? date('F d, Y', strtotime($quotation['valid_until'])) : 'N/A'; ?></td>
                    </tr>
                    <tr>
                        <td class="info-label" style="text-align: right; padding-right: 10px;">Currency:</td>
                        <td><?php echo htmlspecialchars($quotation['currency']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 30px;">#</th>
                    <th style="width: 80px;">Item Code</th>
                    <th>Description</th>
                    <th class="text-right" style="width: 60px;">Qty</th>
                    <th class="text-center" style="width: 50px;">Unit</th>
                    <th class="text-right" style="width: 80px;">Unit Price</th>
                    <th class="text-right" style="width: 50px;">Disc%</th>
                    <th class="text-right" style="width: 90px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $index => $item): ?>
                <tr>
                    <td class="text-center"><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <?php if ($item['description']): ?>
                            <div class="item-description"><?php echo htmlspecialchars($item['description']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-right"><?php echo number_format($item['quantity'], 2); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($item['unit_symbol']); ?></td>
                    <td class="text-right"><?php echo number_format($item['unit_price'], 2); ?></td>
                    <td class="text-right"><?php echo $item['discount_percentage'] > 0 ? number_format($item['discount_percentage'], 1) . '%' : '-'; ?></td>
                    <td class="text-right"><?php echo number_format($item['line_total'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td><?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['subtotal'], 2); ?></td>
                </tr>
                <?php if ($quotation['discount_total'] > 0): ?>
                <tr>
                    <td>Discount<?php echo $quotation['discount_type'] === 'percentage' ? ' (' . number_format($quotation['discount_value'], 1) . '%)' : ''; ?>:</td>
                    <td style="color: #c41e3a;">-<?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['discount_total'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($quotation['tax_total'] > 0): ?>
                <tr>
                    <td>Tax (<?php echo number_format($quotation['tax_percentage'], 1); ?>%):</td>
                    <td>+<?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['tax_total'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total">
                    <td>Grand Total:</td>
                    <td><?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['grand_total'], 2); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Notes -->
        <?php if ($quotation['notes']): ?>
        <div class="notes-section">
            <div class="notes-title">Notes:</div>
            <div class="notes-content"><?php echo htmlspecialchars($quotation['notes']); ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Terms & Conditions -->
        <?php if ($quotation['terms_conditions']): ?>
        <div class="notes-section">
            <div class="notes-title">Terms & Conditions:</div>
            <div class="notes-content"><?php echo htmlspecialchars($quotation['terms_conditions']); ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Validity Notice -->
        <?php if ($quotation['valid_until']): ?>
        <div class="validity-notice">
            <strong>⚠️ This quotation is valid until <?php echo date('F d, Y', strtotime($quotation['valid_until'])); ?>.</strong><br>
            Prices and availability are subject to change after this date.
        </div>
        <?php endif; ?>
        
        <!-- Footer with Signatures -->
        <div class="quotation-footer">
            <div class="signature-box">
                <div class="signature-line">Customer Signature</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized Signature</div>
            </div>
        </div>
        
        <!-- Prepared By -->
        <div style="margin-top: 20px; font-size: 10px; color: #999; text-align: center;">
            Prepared by: <?php echo htmlspecialchars($quotation['created_by_full_name'] ?? $quotation['created_by_name']); ?> | 
            Generated: <?php echo date('F d, Y H:i'); ?>
        </div>
    </div>
</body>
</html>
