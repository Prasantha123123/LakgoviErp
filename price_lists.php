<?php
// price_lists.php - Price List Management
include 'header.php';

require_once 'database.php';
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $db->beginTransaction();
                    
                    // Check if price list code exists
                    $stmt = $db->prepare("SELECT id FROM price_lists WHERE price_list_code = ?");
                    $stmt->execute([$_POST['price_list_code']]);
                    if ($stmt->fetch()) {
                        throw new Exception("Price list code already exists");
                    }
                    
                    // If this is set as default, unset other defaults
                    if (isset($_POST['is_default'])) {
                        $stmt = $db->prepare("UPDATE price_lists SET is_default = 0");
                        $stmt->execute();
                    }
                    
                    // Insert price list
                    $stmt = $db->prepare("
                        INSERT INTO price_lists (
                            price_list_code, price_list_name, description, currency, 
                            is_default, is_active, valid_from, valid_to, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $_POST['price_list_code'],
                        $_POST['price_list_name'],
                        $_POST['description'] ?? null,
                        $_POST['currency'] ?? 'LKR',
                        isset($_POST['is_default']) ? 1 : 0,
                        isset($_POST['is_active']) ? 1 : 0,
                        !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
                        !empty($_POST['valid_to']) ? $_POST['valid_to'] : null,
                        $_SESSION['user_id']
                    ]);
                    
                    $db->commit();
                    $success = "Price list created successfully!";
                    break;
                    
                case 'update':
                    $db->beginTransaction();
                    
                    // Check if price list code exists for other price lists
                    $stmt = $db->prepare("SELECT id FROM price_lists WHERE price_list_code = ? AND id != ?");
                    $stmt->execute([$_POST['price_list_code'], $_POST['price_list_id']]);
                    if ($stmt->fetch()) {
                        throw new Exception("Price list code already exists");
                    }
                    
                    // If this is set as default, unset other defaults
                    if (isset($_POST['is_default'])) {
                        $stmt = $db->prepare("UPDATE price_lists SET is_default = 0 WHERE id != ?");
                        $stmt->execute([$_POST['price_list_id']]);
                    }
                    
                    // Update price list
                    $stmt = $db->prepare("
                        UPDATE price_lists SET
                            price_list_code = ?, price_list_name = ?, description = ?, currency = ?,
                            is_default = ?, is_active = ?, valid_from = ?, valid_to = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $_POST['price_list_code'],
                        $_POST['price_list_name'],
                        $_POST['description'] ?? null,
                        $_POST['currency'] ?? 'LKR',
                        isset($_POST['is_default']) ? 1 : 0,
                        isset($_POST['is_active']) ? 1 : 0,
                        !empty($_POST['valid_from']) ? $_POST['valid_from'] : null,
                        !empty($_POST['valid_to']) ? $_POST['valid_to'] : null,
                        $_POST['price_list_id']
                    ]);
                    
                    $db->commit();
                    $success = "Price list updated successfully!";
                    break;
                    
                case 'delete':
                    // Check if price list is assigned to any customers
                    $stmt = $db->prepare("SELECT COUNT(*) FROM customers WHERE price_list_id = ?");
                    $stmt->execute([$_POST['price_list_id']]);
                    $customer_count = $stmt->fetchColumn();
                    
                    if ($customer_count > 0) {
                        throw new Exception("Cannot delete price list. It is assigned to {$customer_count} customer(s).");
                    }
                    
                    // Delete price list (will cascade to price_list_items)
                    $stmt = $db->prepare("DELETE FROM price_lists WHERE id = ?");
                    $stmt->execute([$_POST['price_list_id']]);
                    $success = "Price list deleted successfully!";
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

// Fetch all price lists with summary
try {
    $stmt = $db->query("SELECT * FROM v_price_list_summary ORDER BY price_list_name");
    $price_lists = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching price lists: " . $e->getMessage();
}

// Get next price list code
try {
    $stmt = $db->query("SELECT price_list_code FROM price_lists ORDER BY id DESC LIMIT 1");
    $last_pl = $stmt->fetch();
    if ($last_pl) {
        $last_number = intval(substr($last_pl['price_list_code'], 2));
        $next_code = 'PL' . str_pad($last_number + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $next_code = 'PL0001';
    }
} catch(PDOException $e) {
    $next_code = 'PL0001';
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Price List Management</h1>
            <p class="text-gray-600">Create and manage price lists for finished goods</p>
        </div>
        <button onclick="openModal('createPriceListModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create New Price List
        </button>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Price Lists Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price List Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Currency</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Items</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customers</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Validity</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($price_lists)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                No price lists found. Click "Create New Price List" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($price_lists as $pl): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($pl['price_list_code']); ?>
                                <?php if ($pl['is_default']): ?>
                                    <span class="ml-2 px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">DEFAULT</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($pl['price_list_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($pl['description'] ?? '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($pl['currency']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <a href="price_list_items.php?price_list_id=<?php echo $pl['id']; ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium">
                                    <?php echo $pl['total_items']; ?> items
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if ($pl['assigned_customers'] > 0): ?>
                                    <span class="text-green-600 font-medium"><?php echo $pl['assigned_customers']; ?> assigned</span>
                                <?php else: ?>
                                    <span class="text-gray-400">No customers</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php if ($pl['valid_from'] || $pl['valid_to']): ?>
                                    <div><?php echo $pl['valid_from'] ? date('M d, Y', strtotime($pl['valid_from'])) : '-'; ?></div>
                                    <div>to <?php echo $pl['valid_to'] ? date('M d, Y', strtotime($pl['valid_to'])) : 'No end'; ?></div>
                                <?php else: ?>
                                    <span class="text-gray-400">Always valid</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($pl['is_active']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                <a href="price_list_items.php?price_list_id=<?php echo $pl['id']; ?>" 
                                   class="text-blue-600 hover:text-blue-900">Manage Items</a>
                                <button onclick='editPriceList(<?php echo json_encode($pl); ?>)' 
                                        class="text-indigo-600 hover:text-indigo-900">Edit</button>
                                <button onclick="deletePriceList(<?php echo $pl['id']; ?>, '<?php echo htmlspecialchars($pl['price_list_name']); ?>')" 
                                        class="text-red-600 hover:text-red-900">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Price List Modal -->
<div id="createPriceListModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Create New Price List</h3>
            <button onclick="closeModal('createPriceListModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price List Code *</label>
                    <input type="text" name="price_list_code" value="<?php echo $next_code; ?>" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                    <select name="currency"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="LKR" selected>LKR - Sri Lankan Rupee</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="GBP">GBP - British Pound</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Price List Name *</label>
                <input type="text" name="price_list_name" required placeholder="e.g., Retail Price List, Wholesale Prices"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="2" placeholder="Brief description of this price list"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid From</label>
                    <input type="date" name="valid_from"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid To</label>
                    <input type="date" name="valid_to"
                           class="w-full px-3 py-2 border border-gray-300rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center">
                    <input type="checkbox" name="is_default" id="is_default"
                           class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                    <label for="is_default" class="ml-2 block text-sm text-gray-700">
                        Set as Default Price List
                        <span class="text-gray-500 text-xs">(Will be used for new customers if not specified)</span>
                    </label>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="is_active" checked
                           class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                    <label for="is_active" class="ml-2 block text-sm text-gray-700">Active</label>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('createPriceListModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Create Price List
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Price List Modal -->
<div id="editPriceListModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Edit Price List</h3>
            <button onclick="closeModal('editPriceListModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="price_list_id" id="edit_price_list_id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price List Code *</label>
                    <input type="text" name="price_list_code" id="edit_price_list_code" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                    <select name="currency" id="edit_currency"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="LKR">LKR - Sri Lankan Rupee</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="GBP">GBP - British Pound</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Price List Name *</label>
                <input type="text" name="price_list_name" id="edit_price_list_name" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" id="edit_description" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid From</label>
                    <input type="date" name="valid_from" id="edit_valid_from"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valid To</label>
                    <input type="date" name="valid_to" id="edit_valid_to"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center">
                    <input type="checkbox" name="is_default" id="edit_is_default"
                           class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                    <label for="edit_is_default" class="ml-2 block text-sm text-gray-700">
                        Set as Default Price List
                    </label>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="edit_is_active"
                           class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                    <label for="edit_is_active" class="ml-2 block text-sm text-gray-700">Active</label>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('editPriceListModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Update Price List
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}

function editPriceList(pl) {
    document.getElementById('edit_price_list_id').value = pl.id;
    document.getElementById('edit_price_list_code').value = pl.price_list_code;
    document.getElementById('edit_price_list_name').value = pl.price_list_name;
    document.getElementById('edit_description').value = pl.description || '';
    document.getElementById('edit_currency').value = pl.currency || 'LKR';
    document.getElementById('edit_valid_from').value = pl.valid_from || '';
    document.getElementById('edit_valid_to').value = pl.valid_to || '';
    document.getElementById('edit_is_default').checked = pl.is_default == 1;
    document.getElementById('edit_is_active').checked = pl.is_active == 1;
    
    openModal('editPriceListModal');
}

function deletePriceList(id, name) {
    if (confirm(`Are you sure you want to delete price list "${name}"? This will also delete all items in this price list.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="price_list_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('fixed')) {
        event.target.classList.add('hidden');
    }
}
</script>

<?php include 'footer.php'; ?>