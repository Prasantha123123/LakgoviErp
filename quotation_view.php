<?php
// quotation_view.php - View quotation details
include 'header.php';

$success = '';
$error = '';

// Check for success message from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $success = 'Quotation created successfully!';
            break;
    }
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
            au.full_name as created_by_full_name,
            au2.username as updated_by_name
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        LEFT JOIN price_lists pl ON q.price_list_id = pl.id
        JOIN admin_users au ON q.created_by = au.id
        LEFT JOIN admin_users au2 ON q.updated_by = au2.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quotation) {
        header('Location: quotation_list.php');
        exit;
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
    $error = "Error loading quotation: " . $e->getMessage();
    $quotation = null;
    $items = [];
}

// Status badge classes
function getStatusBadge($status) {
    $badges = [
        'draft' => 'bg-gray-100 text-gray-800',
        'sent' => 'bg-blue-100 text-blue-800',
        'accepted' => 'bg-green-100 text-green-800',
        'rejected' => 'bg-red-100 text-red-800',
        'expired' => 'bg-yellow-100 text-yellow-800',
        'converted' => 'bg-purple-100 text-purple-800'
    ];
    return $badges[$status] ?? 'bg-gray-100 text-gray-800';
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div class="flex items-center space-x-3">
            <a href="quotation_list.php" class="text-gray-500 hover:text-gray-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    Quotation <?php echo htmlspecialchars($quotation['quotation_no']); ?>
                </h1>
                <p class="text-gray-600">
                    Created on <?php echo date('F d, Y', strtotime($quotation['created_at'])); ?>
                    by <?php echo htmlspecialchars($quotation['created_by_full_name'] ?? $quotation['created_by_name']); ?>
                </p>
            </div>
        </div>
        
        <div class="flex items-center space-x-3">
            <a href="quotation_pdf.php?id=<?php echo $quotation['id']; ?>" target="_blank"
               class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                <svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download / Print
            </a>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($quotation): ?>
    
    <!-- Quotation Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        
        <!-- Customer Information -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                Customer Information
            </h2>
            
            <div class="space-y-3">
                <div>
                    <span class="text-sm text-gray-500">Customer:</span>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($quotation['customer_name']); ?></p>
                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($quotation['customer_code']); ?></p>
                </div>
                
                <?php if ($quotation['contact_person']): ?>
                <div>
                    <span class="text-sm text-gray-500">Contact Person:</span>
                    <p class="text-gray-900"><?php echo htmlspecialchars($quotation['contact_person']); ?></p>
                </div>
                <?php endif; ?>
                
                <div>
                    <span class="text-sm text-gray-500">Address:</span>
                    <p class="text-gray-900">
                        <?php 
                        $address_parts = array_filter([
                            $quotation['address_line1'],
                            $quotation['address_line2'],
                            $quotation['city'],
                            $quotation['state'],
                            $quotation['postal_code'],
                            $quotation['country']
                        ]);
                        echo htmlspecialchars(implode(', ', $address_parts) ?: '-');
                        ?>
                    </p>
                </div>
                
                <div class="flex space-x-6">
                    <?php if ($quotation['customer_phone'] || $quotation['customer_mobile']): ?>
                    <div>
                        <span class="text-sm text-gray-500">Phone:</span>
                        <p class="text-gray-900"><?php echo htmlspecialchars($quotation['customer_phone'] ?: $quotation['customer_mobile']); ?></p>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($quotation['customer_email']): ?>
                    <div>
                        <span class="text-sm text-gray-500">Email:</span>
                        <p class="text-gray-900"><?php echo htmlspecialchars($quotation['customer_email']); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quotation Information -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Quotation Details
            </h2>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="text-sm text-gray-500">Quotation Number:</span>
                    <p class="font-medium text-gray-900"><?php echo htmlspecialchars($quotation['quotation_no']); ?></p>
                </div>
                
                <div>
                    <span class="text-sm text-gray-500">Date:</span>
                    <p class="text-gray-900"><?php echo date('M d, Y', strtotime($quotation['quotation_date'])); ?></p>
                </div>
                
                <div>
                    <span class="text-sm text-gray-500">Valid Until:</span>
                    <p class="text-gray-900">
                        <?php if ($quotation['valid_until']): ?>
                            <?php 
                            $valid_date = strtotime($quotation['valid_until']);
                            $is_expired = $valid_date < time() && !in_array($quotation['status'], ['accepted', 'converted']);
                            ?>
                            <span class="<?php echo $is_expired ? 'text-red-600' : ''; ?>">
                                <?php echo date('M d, Y', $valid_date); ?>
                                <?php if ($is_expired): ?><span class="text-xs">(Expired)</span><?php endif; ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </p>
                </div>
                
                <div>
                    <span class="text-sm text-gray-500">Currency:</span>
                    <p class="text-gray-900"><?php echo htmlspecialchars($quotation['currency']); ?></p>
                </div>
                
                <div class="col-span-2">
                    <span class="text-sm text-gray-500">Price List:</span>
                    <p class="text-gray-900">
                        <?php echo htmlspecialchars($quotation['price_list_name']); ?>
                        <span class="text-gray-500">(<?php echo htmlspecialchars($quotation['price_list_code']); ?>)</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Quotation Items</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Item Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Unit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Disc %</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($items as $index => $item): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $index + 1; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($item['item_code']); ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700">
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <?php if ($item['description']): ?>
                                    <br><span class="text-gray-500"><?php echo htmlspecialchars($item['description']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <?php echo number_format($item['quantity'], 3); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                <?php echo htmlspecialchars($item['unit_symbol']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <?php echo number_format($item['unit_price'], 2); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                                <?php echo number_format($item['discount_percentage'], 2); ?>%
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium">
                                <?php echo number_format($item['line_total'], 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
            <div class="flex justify-end">
                <div class="w-80 space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Subtotal:</span>
                        <span class="font-medium"><?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['subtotal'], 2); ?></span>
                    </div>
                    
                    <?php if ($quotation['discount_total'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">
                            Discount
                            <?php if ($quotation['discount_type'] === 'percentage'): ?>
                                (<?php echo number_format($quotation['discount_value'], 2); ?>%)
                            <?php endif; ?>:
                        </span>
                        <span class="font-medium text-red-600">-<?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['discount_total'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($quotation['tax_total'] > 0): ?>
                    <div class="flex justify-between text-sm">
                        <span class="text-gray-600">Tax (<?php echo number_format($quotation['tax_percentage'], 2); ?>%):</span>
                        <span class="font-medium">+<?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['tax_total'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between text-lg border-t pt-2">
                        <span class="font-semibold text-gray-900">Grand Total:</span>
                        <span class="font-bold text-primary"><?php echo htmlspecialchars($quotation['currency']); ?> <?php echo number_format($quotation['grand_total'], 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes Section -->
    <?php if ($quotation['notes'] || $quotation['terms_conditions'] || $quotation['internal_notes']): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <?php if ($quotation['notes']): ?>
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-3">Notes</h2>
            <p class="text-gray-700 whitespace-pre-line"><?php echo htmlspecialchars($quotation['notes']); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($quotation['terms_conditions']): ?>
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-3">Terms & Conditions</h2>
            <p class="text-gray-700 whitespace-pre-line"><?php echo htmlspecialchars($quotation['terms_conditions']); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($quotation['internal_notes']): ?>
        <div class="bg-yellow-50 shadow rounded-lg p-6 md:col-span-2">
            <h2 class="text-lg font-semibold text-yellow-800 mb-3 flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Internal Notes (Not visible to customer)
            </h2>
            <p class="text-yellow-900"><?php echo htmlspecialchars($quotation['internal_notes']); ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Audit Trail -->
    <div class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Audit Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <span class="text-gray-500">Created:</span>
                <span class="text-gray-900 ml-2">
                    <?php echo date('M d, Y H:i', strtotime($quotation['created_at'])); ?>
                    by <?php echo htmlspecialchars($quotation['created_by_full_name'] ?? $quotation['created_by_name']); ?>
                </span>
            </div>
            <?php if ($quotation['updated_at'] && $quotation['updated_by']): ?>
            <div>
                <span class="text-gray-500">Last Updated:</span>
                <span class="text-gray-900 ml-2">
                    <?php echo date('M d, Y H:i', strtotime($quotation['updated_at'])); ?>
                    by <?php echo htmlspecialchars($quotation['updated_by_name']); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
