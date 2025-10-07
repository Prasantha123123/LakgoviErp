<?php
// price_list_items.php - Manage items within a specific price list
// IMPORTANT: No output before redirects!

require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

// Get price list ID from URL BEFORE including header
$price_list_id = $_GET['price_list_id'] ?? null;

if (!$price_list_id) {
    header('Location: price_lists.php');
    exit;
}

// Get price list details BEFORE including header
try {
    $stmt = $db->prepare("SELECT * FROM price_lists WHERE id = ?");
    $stmt->execute([$price_list_id]);
    $price_list = $stmt->fetch();
    
    if (!$price_list) {
        header('Location: price_lists.php');
        exit;
    }
} catch(PDOException $e) {
    header('Location: price_lists.php?error=invalid_price_list');
    exit;
}

// NOW include header after all redirects are done
include 'header.php';

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_item':
                    $db->beginTransaction();
                    $stmt = $db->prepare("SELECT id FROM price_list_items WHERE price_list_id = ? AND item_id = ?");
                    $stmt->execute([$price_list_id, $_POST['item_id']]);
                    if ($stmt->fetch()) {
                        throw new Exception("This item already exists in the price list");
                    }
                    $stmt = $db->prepare("INSERT INTO price_list_items (price_list_id, item_id, unit_price, min_quantity, discount_percentage, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$price_list_id, $_POST['item_id'], $_POST['unit_price'], $_POST['min_quantity'] ?? 1, $_POST['discount_percentage'] ?? 0, isset($_POST['is_active']) ? 1 : 0]);
                    $db->commit();
                    $success = "Item added to price list successfully!";
                    break;
                    
                case 'update_item':
                    $db->beginTransaction();
                    $stmt = $db->prepare("UPDATE price_list_items SET unit_price = ?, min_quantity = ?, discount_percentage = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$_POST['unit_price'], $_POST['min_quantity'] ?? 1, $_POST['discount_percentage'] ?? 0, isset($_POST['is_active']) ? 1 : 0, $_POST['item_id']]);
                    $db->commit();
                    $success = "Item updated successfully!";
                    break;
                    
                case 'delete_item':
                    $stmt = $db->prepare("DELETE FROM price_list_items WHERE id = ?");
                    $stmt->execute([$_POST['item_id']]);
                    $success = "Item removed from price list!";
                    break;
                    
                case 'bulk_add':
                    $db->beginTransaction();
                    $items_added = 0;
                    $items_skipped = 0;
                    foreach ($_POST['bulk_items'] as $item) {
                        if (!empty($item['item_id']) && !empty($item['unit_price'])) {
                            $stmt = $db->prepare("SELECT id FROM price_list_items WHERE price_list_id = ? AND item_id = ?");
                            $stmt->execute([$price_list_id, $item['item_id']]);
                            if ($stmt->fetch()) {
                                $items_skipped++;
                                continue;
                            }
                            $stmt = $db->prepare("INSERT INTO price_list_items (price_list_id, item_id, unit_price, min_quantity, discount_percentage, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                            $stmt->execute([$price_list_id, $item['item_id'], $item['unit_price'], $item['min_quantity'] ?? 1, $item['discount_percentage'] ?? 0]);
                            $items_added++;
                        }
                    }
                    $db->commit();
                    $success = "Bulk add completed! {$items_added} items added.";
                    if ($items_skipped > 0) {
                        $success .= " {$items_skipped} items skipped (already exist).";
                    }
                    break;
            }
        }
    } catch(Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch items in this price list
try {
    $stmt = $db->prepare("SELECT * FROM v_price_list_items WHERE price_list_id = ? ORDER BY item_name");
    $stmt->execute([$price_list_id]);
    $price_list_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $price_list_items = [];
    $error = "Error fetching price list items: " . $e->getMessage();
}

// Fetch all finished goods for dropdown (not in current price list)
try {
    $stmt = $db->prepare("SELECT i.id, i.code, i.name, u.symbol FROM items i JOIN units u ON i.unit_id = u.id WHERE i.type = 'finished' AND i.id NOT IN (SELECT item_id FROM price_list_items WHERE price_list_id = ?) ORDER BY i.name");
    $stmt->execute([$price_list_id]);
    $available_items = $stmt->fetchAll();
} catch(PDOException $e) {
    $available_items = [];
    $error = "Error fetching items: " . $e->getMessage();
}

// Get customers assigned to this price list
try {
    $stmt = $db->prepare("SELECT customer_code, customer_name, city FROM customers WHERE price_list_id = ? AND is_active = 1 ORDER BY customer_name");
    $stmt->execute([$price_list_id]);
    $assigned_customers = $stmt->fetchAll();
} catch(PDOException $e) {
    $assigned_customers = [];
}
?>

<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <div class="flex items-center space-x-3">
                <a href="price_lists.php" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                </a>
                <div>
                    <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($price_list['price_list_name']); ?></h1>
                    <p class="text-gray-600"><?php echo htmlspecialchars($price_list['price_list_code']); ?> | <?php echo htmlspecialchars($price_list['currency']); ?><?php if ($price_list['is_default']): ?><span class="ml-2 px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">DEFAULT</span><?php endif; ?></p>
                </div>
            </div>
        </div>
        <div class="space-x-2">
            <button onclick="openModal('addItemModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Add Item
            </button>
            <button onclick="openModal('bulkAddModal')" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>Bulk Add Items
            </button>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative"><span class="block sm:inline"><?php echo $success; ?></span></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative"><span class="block sm:inline"><?php echo $error; ?></span></div>
    <?php endif; ?>

    <?php if (!empty($assigned_customers)): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 mt-0.5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div class="flex-1">
                    <h4 class="text-sm font-medium text-blue-900 mb-2">This price list is assigned to <?php echo count($assigned_customers); ?> customer(s):</h4>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($assigned_customers as $customer): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800"><?php echo htmlspecialchars($customer['customer_name']); ?><?php if ($customer['city']): ?><span class="text-blue-600"> - <?php echo htmlspecialchars($customer['city']); ?></span><?php endif; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-blue-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Total Items</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo count($price_list_items); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-green-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Active Items</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo count(array_filter($price_list_items, function($item) { return $item['is_active'] == 1; })); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-purple-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Currency</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo htmlspecialchars($price_list['currency']); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="flex-shrink-0 bg-yellow-100 rounded-md p-3">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-500">Customers</p>
                    <p class="text-2xl font-semibold text-gray-900"><?php echo count($assigned_customers); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
            <h3 class="text-lg font-medium text-gray-900">Items in Price List</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Discount %</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Final Price</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Min Qty</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($price_list_items)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-8 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                <p class="mt-2 text-sm text-gray-500">No items in this price list yet.</p>
                                <p class="text-sm text-gray-500">Click "Add Item" or "Bulk Add Items" to add finished goods.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($price_list_items as $item): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_code']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500"><span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800"><?php echo htmlspecialchars($item['unit_symbol']); ?></span></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900"><?php echo $price_list['currency']; ?> <?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right"><?php if ($item['discount_percentage'] > 0): ?><span class="text-red-600 font-medium">-<?php echo number_format($item['discount_percentage'], 2); ?>%</span><?php else: ?><span class="text-gray-400">-</span><?php endif; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-green-600"><?php echo $price_list['currency']; ?> <?php echo number_format($item['final_price'], 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-500"><?php echo number_format($item['min_quantity'], 3); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-center"><?php if ($item['is_active']): ?><span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span><?php else: ?><span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Inactive</span><?php endif; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                <button onclick='editItem(<?php echo json_encode($item); ?>)' class="text-indigo-600 hover:text-indigo-900 mr-3">
                                    <svg class="w-5 h-5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <button onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>')" class="text-red-600 hover:text-red-900">
                                    <svg class="w-5 h-5 inline-block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="addItemModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Add Item to Price List</h3>
            <button onclick="closeModal('addItemModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="add_item">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Finished Good *</label>
                <select name="item_id" required onchange="updateItemInfo(this)" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="">-- Select Finished Good --</option>
                    <?php foreach ($available_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" data-unit="<?php echo htmlspecialchars($item['symbol']); ?>"><?php echo htmlspecialchars($item['code'] . ' - ' . $item['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-sm text-gray-500">Unit: <span id="selected_unit" class="font-medium text-gray-700">-</span></p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit Price (<?php echo $price_list['currency']; ?>) *</label>
                    <input type="number" name="unit_price" step="0.01" min="0" required placeholder="0.00" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
                    <input type="number" name="discount_percentage" step="0.01" min="0" max="100" value="0" placeholder="0.00" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                    <p class="mt-1 text-xs text-gray-500">Leave 0 for no discount</p>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Order Quantity</label>
                <input type="number" name="min_quantity" step="0.001" min="0" value="1" placeholder="1.000" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                <p class="mt-1 text-xs text-gray-500">Minimum quantity customer must order</p>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="add_is_active" checked class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                <label for="add_is_active" class="ml-2 block text-sm text-gray-700">Active <span class="text-gray-500 text-xs">(Only active items appear on invoices)</span></label>
            </div>
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('addItemModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600 transition-colors"><svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>Add Item</button>
            </div>
        </form>
    </div>
</div>

<div id="editItemModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Edit Item Pricing</h3>
            <button onclick="closeModal('editItemModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" id="edit_item_id">
            <div class="bg-gray-50 p-4 rounded-md border border-gray-200">
                <p class="text-sm text-gray-600">Item: <span id="edit_item_display" class="font-medium text-gray-900"></span></p>
                <p class="text-xs text-gray-500 mt-1">Unit: <span id="edit_item_unit" class="font-medium text-gray-700"></span></p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unit Price (<?php echo $price_list['currency']; ?>) *</label>
                    <input type="number" name="unit_price" id="edit_unit_price" step="0.01" min="0" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Discount %</label>
                    <input type="number" name="discount_percentage" id="edit_discount_percentage" step="0.01" min="0" max="100" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                    <p class="mt-1 text-xs text-gray-500">Final price will beauto-calculated</p>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Minimum Order Quantity</label>
                <input type="number" name="min_quantity" id="edit_min_quantity" step="0.001" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="edit_item_is_active" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                <label for="edit_item_is_active" class="ml-2 block text-sm text-gray-700">Active</label>
            </div>
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('editItemModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600 transition-colors"><svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Update Item</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkAddModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-10 mx-auto p-5 border w-full max-w-6xl shadow-lg rounded-md bg-white mb-10">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Bulk Add Items to Price List</h3>
            <button onclick="closeModal('bulkAddModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="bulk_add">
            <div class="bg-blue-50 border border-blue-200 rounded-md p-3 mb-4">
                <div class="flex">
                    <svg class="w-5 h-5 text-blue-600 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <p class="text-sm text-blue-800 font-medium">Bulk Add Instructions</p>
                        <p class="text-sm text-blue-700 mt-1">Select the items you want to add, fill in their prices, and click "Add Selected Items". Items already in the price list will be automatically skipped.</p>
                    </div>
                </div>
            </div>
            <?php if (empty($available_items)): ?>
                <div class="text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="mt-2 text-sm font-medium text-gray-900">All finished goods are already in this price list</p>
                    <p class="text-sm text-gray-500">There are no more items to add</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto max-h-96 border border-gray-200 rounded-md">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 sticky top-0 z-10">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><input type="checkbox" id="selectAllBulk" onclick="toggleAllBulkItems()" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded"></th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Code</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Name</th>
                                <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price (<?php echo $price_list['currency']; ?>)</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Discount %</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Min Qty</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($available_items as $index => $item): ?>
                            <tr class="hover:bg-gray-50 transition-colors bulk-item-row">
                                <td class="px-4 py-3"><input type="checkbox" class="bulk-item-checkbox h-4 w-4 text-primary border-gray-300 rounded" data-index="<?php echo $index; ?>"></td>
                                <td class="px-4 py-3 text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['code']); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($item['name']); ?></td>
                                <td class="px-4 py-3 text-center"><span class="px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800"><?php echo htmlspecialchars($item['symbol']); ?></span></td>
                                <td class="px-4 py-3">
                                    <input type="number" name="bulk_items[<?php echo $index; ?>][unit_price]" step="0.01" min="0" placeholder="0.00" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary bg-gray-100" disabled>
                                    <input type="hidden" name="bulk_items[<?php echo $index; ?>][item_id]" value="<?php echo $item['id']; ?>">
                                </td>
                                <td class="px-4 py-3"><input type="number" name="bulk_items[<?php echo $index; ?>][discount_percentage]" value="0" step="0.01" min="0" max="100" placeholder="0.00" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary bg-gray-100" disabled></td>
                                <td class="px-4 py-3"><input type="number" name="bulk_items[<?php echo $index; ?>][min_quantity]" value="1" step="0.001" min="0" placeholder="1.000" class="w-full px-2 py-1 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-primary bg-gray-100" disabled></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-between items-center pt-4 border-t">
                    <div><p class="text-sm text-gray-600"><span id="selectedCount" class="font-semibold text-gray-900">0</span> items selected</p></div>
                    <div class="space-x-3">
                        <button type="button" onclick="closeModal('bulkAddModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600 transition-colors"><svg class="w-5 h-5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Add Selected Items</button>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function updateItemInfo(select) {
    const selectedOption = select.options[select.selectedIndex];
    const unit = selectedOption.getAttribute('data-unit');
    document.getElementById('selected_unit').textContent = unit || '-';
}

function editItem(item) {
    document.getElementById('edit_item_id').value = item.id;
    document.getElementById('edit_item_display').textContent = item.item_code + ' - ' + item.item_name;
    document.getElementById('edit_item_unit').textContent = item.unit_symbol;
    document.getElementById('edit_unit_price').value = item.unit_price;
    document.getElementById('edit_discount_percentage').value = item.discount_percentage;
    document.getElementById('edit_min_quantity').value = item.min_quantity;
    document.getElementById('edit_item_is_active').checked = item.is_active == 1;
    openModal('editItemModal');
}

function deleteItem(id, name) {
    if (confirm('Are you sure you want to remove "' + name + '" from this price list?\n\nThis action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.bulk-item-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const row = this.closest('tr');
            const inputs = row.querySelectorAll('input[type="number"]');
            inputs.forEach(input => {
                input.disabled = !this.checked;
                if (this.checked) {
                    input.classList.remove('bg-gray-100');
                    input.classList.add('bg-white');
                } else {
                    input.classList.add('bg-gray-100');
                    input.classList.remove('bg-white');
                }
            });
            updateSelectedCount();
        });
    });
});

function toggleAllBulkItems() {
    const selectAllCheckbox = document.getElementById('selectAllBulk');
    const checkboxes = document.querySelectorAll('.bulk-item-checkbox');
    const isChecked = selectAllCheckbox.checked;
    checkboxes.forEach(checkbox => {
        checkbox.checked = isChecked;
        checkbox.dispatchEvent(new Event('change'));
    });
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.bulk-item-checkbox:checked');
    const countElement = document.getElementById('selectedCount');
    if (countElement) {
        countElement.textContent = checkboxes.length;
    }
    const selectAllCheckbox = document.getElementById('selectAllBulk');
    const allCheckboxes = document.querySelectorAll('.bulk-item-checkbox');
    if (selectAllCheckbox && allCheckboxes.length > 0) {
        const allChecked = Array.from(allCheckboxes).every(cb => cb.checked);
        const someChecked = Array.from(allCheckboxes).some(cb => cb.checked);
        selectAllCheckbox.checked = allChecked;
        selectAllCheckbox.indeterminate = someChecked && !allChecked;
    }
}

window.onclick = function(event) {
    if (event.target.classList.contains('fixed')) {
        const modals = ['addItemModal', 'editItemModal', 'bulkAddModal'];
        modals.forEach(modalId => {
            if (event.target.id === modalId) {
                closeModal(modalId);
            }
        });
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = ['addItemModal', 'editItemModal', 'bulkAddModal'];
        modals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal && !modal.classList.contains('hidden')) {
                closeModal(modalId);
            }
        });
    }
});

document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitButton = this.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<svg class="animate-spin h-5 w-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
            setTimeout(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = submitButton.getAttribute('data-original-text') || 'Submit';
            }, 3000);
        }
    });
});
</script>

<style>
.overflow-x-auto::-webkit-scrollbar {height: 8px;}
.overflow-x-auto::-webkit-scrollbar-track {background: #f1f1f1; border-radius: 4px;}
.overflow-x-auto::-webkit-scrollbar-thumb {background: #888; border-radius: 4px;}
.overflow-x-auto::-webkit-scrollbar-thumb:hover {background: #555;}
.transition-colors {transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out;}
@keyframes spin {to {transform: rotate(360deg);}}
.animate-spin {animation: spin 1s linear infinite;}
.fixed {animation: fadeIn 0.2s ease-in-out;}
@keyframes fadeIn {from {opacity: 0;} to {opacity: 1;}}
.hover\:bg-gray-50:hover {background-color: #f9fafb;}
input:focus, select:focus, textarea:focus {outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);}
input:disabled {cursor: not-allowed; opacity: 0.6;}
input[type="checkbox"]:indeterminate {background-color: #3B82F6; border-color: #3B82F6; background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 16 16'%3e%3cpath stroke='white' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M4 8h8'/%3e%3c/svg%3e");}
@media print {.no-print {display: none !important;} .bg-white {box-shadow: none !important;}}
</style>

<?php include 'footer.php'; ?>