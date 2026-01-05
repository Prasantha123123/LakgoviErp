<?php
require_once 'header.php';
require_once 'report_functions.php';

// Get customers for dropdown
$customers = getCustomersForDropdown($db);

// Default date range - current month
$defaultDateFrom = date('Y-m-01');
$defaultDateTo = date('Y-m-d');

// Get filter values
$dateFrom = $_GET['date_from'] ?? $defaultDateFrom;
$dateTo = $_GET['date_to'] ?? $defaultDateTo;
$customerId = $_GET['customer_id'] ?? '';
$status = $_GET['status'] ?? 'confirmed';
$paymentStatus = $_GET['payment_status'] ?? '';
$reportType = $_GET['report'] ?? 'total';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = $defaultDateFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = $defaultDateTo;

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'customer_id' => $customerId,
    'status' => $status,
    'payment_status' => $paymentStatus
];

// Get report data based on type
$reportData = null;
switch ($reportType) {
    case 'customer':
        $reportData = getCustomerWiseSalesReport($db, $filters);
        $reportTitle = 'Customer-wise Sales Report';
        break;
    case 'item':
        $reportData = getItemWiseSalesReport($db, $filters);
        $reportTitle = 'Item-wise Sales Report';
        break;
    default:
        $reportData = getTotalSalesReport($db, $filters);
        $reportTitle = 'Total Sales Report';
        break;
}

// Build export URL with current filters
$exportBaseUrl = "export_sales_report.php?report=$reportType&date_from=$dateFrom&date_to=$dateTo&customer_id=$customerId&status=$status&payment_status=$paymentStatus";
?>

<div class="max-w-7xl mx-auto px-4 py-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">📊 Sales Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Generate and export sales reports</p>
        </div>
    </div>

    <!-- Report Type Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                <a href="?report=total&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&customer_id=<?= $customerId ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>" 
                   class="<?= $reportType == 'total' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                    </svg>
                    Total Sales
                </a>
                <a href="?report=customer&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&customer_id=<?= $customerId ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>" 
                   class="<?= $reportType == 'customer' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Customer-wise
                </a>
                <a href="?report=item&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&customer_id=<?= $customerId ?>&status=<?= $status ?>&payment_status=<?= $paymentStatus ?>" 
                   class="<?= $reportType == 'item' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                    Item-wise
                </a>
            </nav>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
        <!-- Quick Date Selection -->
        <div class="flex flex-wrap gap-2 mb-4 pb-4 border-b border-gray-200">
            <span class="text-sm font-medium text-gray-700 mr-2 self-center">Quick Select:</span>
            <button type="button" onclick="setDateRange('today')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 <?= ($dateFrom == date('Y-m-d') && $dateTo == date('Y-m-d')) ? 'bg-blue-100 border-blue-400 text-blue-700' : 'bg-gray-50 border-gray-300 text-gray-700' ?>">
                Today
            </button>
            <button type="button" onclick="setDateRange('yesterday')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 bg-gray-50 border-gray-300 text-gray-700">
                Yesterday
            </button>
            <button type="button" onclick="setDateRange('week')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 bg-gray-50 border-gray-300 text-gray-700">
                This Week
            </button>
            <button type="button" onclick="setDateRange('last_week')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 bg-gray-50 border-gray-300 text-gray-700">
                Last Week
            </button>
            <button type="button" onclick="setDateRange('month')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 <?= ($dateFrom == date('Y-m-01') && $dateTo == date('Y-m-d')) ? 'bg-blue-100 border-blue-400 text-blue-700' : 'bg-gray-50 border-gray-300 text-gray-700' ?>">
                This Month
            </button>
            <button type="button" onclick="setDateRange('last_month')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 bg-gray-50 border-gray-300 text-gray-700">
                Last Month
            </button>
            <button type="button" onclick="setDateRange('year')" class="px-3 py-1.5 text-xs font-medium rounded-full border transition-colors hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 bg-gray-50 border-gray-300 text-gray-700">
                This Year
            </button>
        </div>
        
        <form method="GET" id="reportForm" class="grid grid-cols-1 md:grid-cols-6 gap-4">
            <input type="hidden" name="report" value="<?= htmlspecialchars($reportType) ?>">
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Date From *</label>
                <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($dateFrom) ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Date To *</label>
                <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($dateTo) ?>" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Customer</label>
                <select name="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="">All Customers</option>
                    <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $customerId == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['customer_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Invoice Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="confirmed" <?= $status == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="all" <?= $status == 'all' ? 'selected' : '' ?>>All Statuses</option>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Payment Status</label>
                <select name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-blue-500 focus:border-blue-500">
                    <option value="" <?= $paymentStatus == '' ? 'selected' : '' ?>>All</option>
                    <option value="paid" <?= $paymentStatus == 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="partial" <?= $paymentStatus == 'partial' ? 'selected' : '' ?>>Partial</option>
                    <option value="unpaid" <?= $paymentStatus == 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Generate Report
                </button>
            </div>
        </form>
    </div>

    <!-- Export Buttons -->
    <div class="flex justify-end mb-4 space-x-2">
        <a href="<?= $exportBaseUrl ?>&format=excel" 
           class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            Export Excel
        </a>
        <a href="<?= $exportBaseUrl ?>&format=pdf" 
           class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700 transition-colors">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            Export PDF
        </a>
    </div>

    <?php if ($reportType == 'total'): ?>
        <!-- Total Sales Report -->
        <?php $summary = $reportData['summary']; ?>
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center">
                    <div class="p-3 bg-blue-100 rounded-full">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                        <p class="text-2xl font-bold text-gray-900"><?= number_format($summary['total_invoices']) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center">
                    <div class="p-3 bg-green-100 rounded-full">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Total Sales</p>
                        <p class="text-2xl font-bold text-gray-900">Rs. <?= formatCurrency($summary['total_sales']) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center">
                    <div class="p-3 bg-purple-100 rounded-full">
                        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Total Paid</p>
                        <p class="text-2xl font-bold text-gray-900">Rs. <?= formatCurrency($summary['total_paid']) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <div class="flex items-center">
                    <div class="p-3 bg-red-100 rounded-full">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Total Balance</p>
                        <p class="text-2xl font-bold text-gray-900">Rs. <?= formatCurrency($summary['total_balance']) ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Payment Status Breakdown -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="bg-green-50 rounded-lg border border-green-200 p-4">
                <p class="text-sm font-medium text-green-800">Paid Invoices</p>
                <p class="text-xl font-bold text-green-900"><?= $summary['by_payment_status']['paid']['count'] ?></p>
                <p class="text-sm text-green-700">Rs. <?= formatCurrency($summary['by_payment_status']['paid']['amount']) ?></p>
            </div>
            <div class="bg-yellow-50 rounded-lg border border-yellow-200 p-4">
                <p class="text-sm font-medium text-yellow-800">Partial Invoices</p>
                <p class="text-xl font-bold text-yellow-900"><?= $summary['by_payment_status']['partial']['count'] ?></p>
                <p class="text-sm text-yellow-700">Rs. <?= formatCurrency($summary['by_payment_status']['partial']['amount']) ?></p>
            </div>
            <div class="bg-red-50 rounded-lg border border-red-200 p-4">
                <p class="text-sm font-medium text-red-800">Unpaid Invoices</p>
                <p class="text-xl font-bold text-red-900"><?= $summary['by_payment_status']['unpaid']['count'] ?></p>
                <p class="text-sm text-red-700">Rs. <?= formatCurrency($summary['by_payment_status']['unpaid']['amount']) ?></p>
            </div>
        </div>
        
        <!-- Invoice List Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Invoice Details (<?= count($reportData['rows']) ?> records)</h3>
            </div>
            <div class="overflow-x-auto max-h-96 overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Invoice No</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Paid Amount</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($reportData['rows'])): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">No invoices found for the selected criteria</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportData['rows'] as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-blue-600"><?= htmlspecialchars($row['invoice_no']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900"><?= date('d/m/Y', strtotime($row['invoice_date'])) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['total_amount']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['paid_amount']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['balance_amount']) ?></td>
                                    <td class="px-4 py-3 text-sm text-center">
                                        <?php
                                        $statusClass = match($row['payment_status']) {
                                            'paid' => 'bg-green-100 text-green-800',
                                            'partial' => 'bg-yellow-100 text-yellow-800',
                                            default => 'bg-red-100 text-red-800'
                                        };
                                        ?>
                                        <span class="px-2 py-1 text-xs font-medium rounded-full <?= $statusClass ?>">
                                            <?= ucfirst($row['payment_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($reportType == 'customer'): ?>
        <!-- Customer-wise Sales Report -->
        <?php $totals = $reportData['totals']; ?>
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Customers</p>
                <p class="text-2xl font-bold text-gray-900"><?= count($reportData['rows']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Invoices</p>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($totals['invoices_count']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Sales</p>
                <p class="text-2xl font-bold text-green-600">Rs. <?= formatCurrency($totals['total_sales']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Balance</p>
                <p class="text-2xl font-bold text-red-600">Rs. <?= formatCurrency($totals['total_balance']) ?></p>
            </div>
        </div>
        
        <!-- Customer Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Customer-wise Breakdown</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer Code</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer Name</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Invoices</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sales</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Paid</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($reportData['rows'])): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">No data found for the selected criteria</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportData['rows'] as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($row['customer_code']) ?></td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($row['customer_name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-center"><?= $row['invoices_count'] ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['total_sales']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['total_paid']) ?></td>
                                    <td class="px-4 py-3 text-sm text-right <?= $row['total_balance'] > 0 ? 'text-red-600 font-medium' : 'text-gray-900' ?>"><?= formatCurrency($row['total_balance']) ?></td>
                                    <td class="px-4 py-3 text-sm text-center">
                                        <button onclick="viewCustomerDetails(<?= $row['customer_id'] ?>, '<?= htmlspecialchars($row['customer_name'], ENT_QUOTES) ?>')" 
                                                class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($reportData['rows'])): ?>
                    <tfoot class="bg-gray-100">
                        <tr class="font-semibold">
                            <td colspan="2" class="px-4 py-3 text-sm text-gray-900">Total</td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-center"><?= $totals['invoices_count'] ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($totals['total_sales']) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($totals['total_paid']) ?></td>
                            <td class="px-4 py-3 text-sm text-red-600 text-right"><?= formatCurrency($totals['total_balance']) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

    <?php else: ?>
        <!-- Item-wise Sales Report -->
        <?php $totals = $reportData['totals']; ?>
        
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Items Sold</p>
                <p class="text-2xl font-bold text-gray-900"><?= count($reportData['rows']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Quantity</p>
                <p class="text-2xl font-bold text-blue-600"><?= formatQuantity($totals['total_qty']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Total Sales</p>
                <p class="text-2xl font-bold text-green-600">Rs. <?= formatCurrency($totals['total_sales']) ?></p>
            </div>
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                <p class="text-sm font-medium text-gray-500">Invoices with Items</p>
                <p class="text-2xl font-bold text-gray-900"><?= number_format($totals['invoices_count']) ?></p>
            </div>
        </div>
        
        <!-- Item Table -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-700">Item-wise Breakdown</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Qty</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total Sales</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Avg. Price</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Invoices</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($reportData['rows'])): ?>
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-gray-500">No data found for the selected criteria</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportData['rows'] as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm text-gray-900"><?= htmlspecialchars($row['item_code']) ?></td>
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($row['item_name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatQuantity($row['total_qty']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['total_sales']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($row['avg_price']) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 text-center"><?= $row['invoices_count'] ?></td>
                                    <td class="px-4 py-3 text-sm text-center">
                                        <button onclick="viewItemDetails(<?= $row['item_id'] ?>, '<?= htmlspecialchars($row['item_name'], ENT_QUOTES) ?>')" 
                                                class="text-blue-600 hover:text-blue-800 text-xs font-medium">
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($reportData['rows'])): ?>
                    <tfoot class="bg-gray-100">
                        <tr class="font-semibold">
                            <td colspan="2" class="px-4 py-3 text-sm text-gray-900">Total</td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatQuantity($totals['total_qty']) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-right"><?= formatCurrency($totals['total_sales']) ?></td>
                            <td class="px-4 py-3 text-sm text-gray-500 text-right">-</td>
                            <td class="px-4 py-3 text-sm text-gray-900 text-center"><?= $totals['invoices_count'] ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Customer Details Modal -->
<div id="customerDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900" id="customerModalTitle">Customer Invoices</h3>
            <button onclick="closeCustomerModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[70vh]" id="customerModalContent">
            <div class="text-center text-gray-500 py-8">Loading...</div>
        </div>
    </div>
</div>

<!-- Item Details Modal -->
<div id="itemDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 max-h-[90vh] overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-900" id="itemModalTitle">Item Invoice Details</h3>
            <button onclick="closeItemModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[70vh]" id="itemModalContent">
            <div class="text-center text-gray-500 py-8">Loading...</div>
        </div>
    </div>
</div>

<script>
const currentFilters = {
    date_from: '<?= $dateFrom ?>',
    date_to: '<?= $dateTo ?>',
    status: '<?= $status ?>',
    payment_status: '<?= $paymentStatus ?>'
};

function viewCustomerDetails(customerId, customerName) {
    document.getElementById('customerModalTitle').textContent = 'Invoices - ' + customerName;
    document.getElementById('customerModalContent').innerHTML = '<div class="text-center text-gray-500 py-8">Loading...</div>';
    document.getElementById('customerDetailsModal').classList.remove('hidden');
    
    const params = new URLSearchParams({
        customer_id: customerId,
        ...currentFilters
    });
    
    fetch('get_customer_report_details.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<table class="min-w-full divide-y divide-gray-200">';
                html += '<thead class="bg-gray-50"><tr>';
                html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice No</th>';
                html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Total</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Paid</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>';
                html += '<th class="px-4 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>';
                html += '</tr></thead><tbody class="divide-y divide-gray-200">';
                
                let totalAmount = 0, totalPaid = 0, totalBalance = 0;
                
                data.invoices.forEach(inv => {
                    totalAmount += parseFloat(inv.total_amount);
                    totalPaid += parseFloat(inv.paid_amount);
                    totalBalance += parseFloat(inv.balance_amount);
                    
                    const statusClass = inv.payment_status === 'paid' ? 'bg-green-100 text-green-800' : 
                                        inv.payment_status === 'partial' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800';
                    
                    html += '<tr class="hover:bg-gray-50">';
                    html += '<td class="px-4 py-2 text-sm font-medium text-blue-600">' + inv.invoice_no + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900">' + formatDate(inv.invoice_date) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.total_amount) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.paid_amount) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.balance_amount) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-center"><span class="px-2 py-1 text-xs font-medium rounded-full ' + statusClass + '">' + inv.payment_status.charAt(0).toUpperCase() + inv.payment_status.slice(1) + '</span></td>';
                    html += '</tr>';
                });
                
                html += '</tbody><tfoot class="bg-gray-100"><tr class="font-semibold">';
                html += '<td colspan="2" class="px-4 py-2 text-sm">Total</td>';
                html += '<td class="px-4 py-2 text-sm text-right">' + formatNumber(totalAmount) + '</td>';
                html += '<td class="px-4 py-2 text-sm text-right">' + formatNumber(totalPaid) + '</td>';
                html += '<td class="px-4 py-2 text-sm text-right">' + formatNumber(totalBalance) + '</td>';
                html += '<td></td>';
                html += '</tr></tfoot></table>';
                
                document.getElementById('customerModalContent').innerHTML = html;
            } else {
                document.getElementById('customerModalContent').innerHTML = '<div class="text-center text-red-500 py-8">' + data.message + '</div>';
            }
        })
        .catch(error => {
            document.getElementById('customerModalContent').innerHTML = '<div class="text-center text-red-500 py-8">Error loading data</div>';
        });
}

function closeCustomerModal() {
    document.getElementById('customerDetailsModal').classList.add('hidden');
}

function viewItemDetails(itemId, itemName) {
    document.getElementById('itemModalTitle').textContent = 'Invoice Details - ' + itemName;
    document.getElementById('itemModalContent').innerHTML = '<div class="text-center text-gray-500 py-8">Loading...</div>';
    document.getElementById('itemDetailsModal').classList.remove('hidden');
    
    const params = new URLSearchParams({
        item_id: itemId,
        ...currentFilters
    });
    
    fetch('get_item_report_details.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '<table class="min-w-full divide-y divide-gray-200">';
                html += '<thead class="bg-gray-50"><tr>';
                html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Invoice No</th>';
                html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>';
                html += '<th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unit Price</th>';
                html += '<th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Line Total</th>';
                html += '</tr></thead><tbody class="divide-y divide-gray-200">';
                
                let totalQty = 0, totalSales = 0;
                
                data.invoices.forEach(inv => {
                    totalQty += parseFloat(inv.quantity);
                    totalSales += parseFloat(inv.line_total);
                    
                    html += '<tr class="hover:bg-gray-50">';
                    html += '<td class="px-4 py-2 text-sm font-medium text-blue-600">' + inv.invoice_no + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900">' + formatDate(inv.invoice_date) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900">' + inv.customer_name + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.quantity) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.unit_price) + '</td>';
                    html += '<td class="px-4 py-2 text-sm text-gray-900 text-right">' + formatNumber(inv.line_total) + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody><tfoot class="bg-gray-100"><tr class="font-semibold">';
                html += '<td colspan="3" class="px-4 py-2 text-sm">Total</td>';
                html += '<td class="px-4 py-2 text-sm text-right">' + formatNumber(totalQty) + '</td>';
                html += '<td class="px-4 py-2 text-sm text-right">-</td>';
                html += '<td class="px-4 py-2 text-sm text-right">' + formatNumber(totalSales) + '</td>';
                html += '</tr></tfoot></table>';
                
                document.getElementById('itemModalContent').innerHTML = html;
            } else {
                document.getElementById('itemModalContent').innerHTML = '<div class="text-center text-red-500 py-8">' + data.message + '</div>';
            }
        })
        .catch(error => {
            document.getElementById('itemModalContent').innerHTML = '<div class="text-center text-red-500 py-8">Error loading data</div>';
        });
}

function closeItemModal() {
    document.getElementById('itemDetailsModal').classList.add('hidden');
}

function formatNumber(num) {
    return parseFloat(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB');
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCustomerModal();
        closeItemModal();
    }
});

// Close modals on backdrop click
document.getElementById('customerDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeCustomerModal();
});

document.getElementById('itemDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeItemModal();
});

// Quick date range selection
function setDateRange(range) {
    const today = new Date();
    let dateFrom, dateTo;
    
    switch(range) {
        case 'today':
            dateFrom = dateTo = formatDateForInput(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            dateFrom = dateTo = formatDateForInput(yesterday);
            break;
        case 'week':
            // This week (Monday to today)
            const monday = new Date(today);
            const day = monday.getDay();
            const diff = monday.getDate() - day + (day === 0 ? -6 : 1);
            monday.setDate(diff);
            dateFrom = formatDateForInput(monday);
            dateTo = formatDateForInput(today);
            break;
        case 'last_week':
            // Last week (Monday to Sunday)
            const lastMonday = new Date(today);
            const currentDay = lastMonday.getDay();
            const diffToLastMonday = lastMonday.getDate() - currentDay + (currentDay === 0 ? -6 : 1) - 7;
            lastMonday.setDate(diffToLastMonday);
            const lastSunday = new Date(lastMonday);
            lastSunday.setDate(lastMonday.getDate() + 6);
            dateFrom = formatDateForInput(lastMonday);
            dateTo = formatDateForInput(lastSunday);
            break;
        case 'month':
            // This month
            dateFrom = formatDateForInput(new Date(today.getFullYear(), today.getMonth(), 1));
            dateTo = formatDateForInput(today);
            break;
        case 'last_month':
            // Last month
            const firstDayLastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastDayLastMonth = new Date(today.getFullYear(), today.getMonth(), 0);
            dateFrom = formatDateForInput(firstDayLastMonth);
            dateTo = formatDateForInput(lastDayLastMonth);
            break;
        case 'year':
            // This year
            dateFrom = formatDateForInput(new Date(today.getFullYear(), 0, 1));
            dateTo = formatDateForInput(today);
            break;
    }
    
    document.getElementById('date_from').value = dateFrom;
    document.getElementById('date_to').value = dateTo;
    
    // Auto-submit the form
    document.getElementById('reportForm').submit();
}

function formatDateForInput(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
</script>

<?php require_once 'footer.php'; ?>
