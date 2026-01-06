<?php
// quotation_list.php - List and manage quotations
include 'header.php';

$success = '';
$error = '';

// Check for success message from redirect
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $success = 'Quotation created successfully.';
            break;
    }
}

// Build filter conditions
$where_clauses = ["1=1"];
$params = [];

// Status filter
$filter_status = $_GET['status'] ?? '';
if ($filter_status) {
    $where_clauses[] = "q.status = ?";
    $params[] = $filter_status;
}

// Customer filter
$filter_customer = $_GET['customer_id'] ?? '';
if ($filter_customer) {
    $where_clauses[] = "q.customer_id = ?";
    $params[] = $filter_customer;
}

// Date range filter
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
if ($filter_date_from) {
    $where_clauses[] = "q.quotation_date >= ?";
    $params[] = $filter_date_from;
}
if ($filter_date_to) {
    $where_clauses[] = "q.quotation_date <= ?";
    $params[] = $filter_date_to;
}

// Search query
$search = $_GET['search'] ?? '';
if ($search) {
    $where_clauses[] = "(q.quotation_no LIKE ? OR c.customer_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_sql = implode(" AND ", $where_clauses);

// Fetch quotations
try {
    $sql = "
        SELECT 
            q.*,
            c.customer_code,
            c.customer_name,
            c.city as customer_city,
            COALESCE(pl.price_list_name, 'Manual') as price_list_name,
            au.username as created_by_name,
            (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id) as item_count
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        LEFT JOIN price_lists pl ON q.price_list_id = pl.id
        JOIN admin_users au ON q.created_by = au.id
        WHERE $where_sql
        ORDER BY q.created_at DESC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $quotations = [];
    $error = "Error fetching quotations: " . $e->getMessage();
}

// Fetch customers for filter dropdown
try {
    $stmt = $db->query("SELECT id, customer_code, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $customers = [];
}

// Get statistics
try {
    $stats = [
        'total' => 0,
        'draft' => 0,
        'sent' => 0,
        'accepted' => 0,
        'total_value' => 0
    ];
    
    $stmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(grand_total) as total_value
        FROM quotations
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // Use defaults
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
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Quotations</h1>
            <p class="text-gray-600">Manage and track customer quotations</p>
        </div>
        <a href="quotation_create.php" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            New Quotation
        </a>
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

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-gray-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['total'] ?? 0; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-gray-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Draft</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['draft'] ?? 0; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Sent</p>
                    <p class="text-2xl font-semibold text-blue-600"><?php echo $stats['sent'] ?? 0; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Accepted</p>
                    <p class="text-2xl font-semibold text-green-600"><?php echo $stats['accepted'] ?? 0; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Value</p>
                    <p class="text-xl font-semibold text-yellow-600">LKR <?php echo number_format($stats['total_value'] ?? 0, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white shadow rounded-lg p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="Quotation # or customer..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="">All Status</option>
                    <option value="draft" <?php echo $filter_status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="sent" <?php echo $filter_status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="accepted" <?php echo $filter_status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                    <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="expired" <?php echo $filter_status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    <option value="converted" <?php echo $filter_status === 'converted' ? 'selected' : ''; ?>>Converted</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                <select name="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>" <?php echo $filter_customer == $customer['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($customer['customer_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600 text-sm">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Filter
                </button>
                <a href="quotation_list.php" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 text-sm">
                    Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Quotations Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quotation #</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Grand Total</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Valid Until</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($quotations)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                <svg class="w-12 h-12 mx-auto text-gray-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                No quotations found. <a href="quotation_create.php" class="text-primary hover:underline">Create your first quotation</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($quotations as $q): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="quotation_view.php?id=<?php echo $q['id']; ?>" class="text-primary hover:text-blue-800 font-medium">
                                        <?php echo htmlspecialchars($q['quotation_no']); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M d, Y', strtotime($q['quotation_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($q['customer_name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($q['customer_code']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                    <?php echo $q['item_count']; ?> items
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($q['currency']); ?> <?php echo number_format($q['grand_total'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <?php if ($q['valid_until']): ?>
                                        <?php 
                                        $valid_date = strtotime($q['valid_until']);
                                        $is_expired = $valid_date < time() && $q['status'] !== 'accepted' && $q['status'] !== 'converted';
                                        ?>
                                        <span class="<?php echo $is_expired ? 'text-red-600' : 'text-gray-500'; ?>">
                                            <?php echo date('M d, Y', $valid_date); ?>
                                            <?php if ($is_expired): ?><span class="text-xs">(Expired)</span><?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                    <div class="flex items-center justify-center space-x-3">
                                        <a href="quotation_view.php?id=<?php echo $q['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 flex items-center" title="View">
                                            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                            View
                                        </a>
                                        
                                        <a href="quotation_pdf.php?id=<?php echo $q['id']; ?>" 
                                           class="text-green-600 hover:text-green-900 flex items-center" title="Download/Print" target="_blank">
                                            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                            </svg>
                                            Download
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<?php include 'footer.php'; ?>
