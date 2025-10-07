<?php
// customers.php - Customer Management
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
                    
                    // Check if customer code exists
                    $stmt = $db->prepare("SELECT id FROM customers WHERE customer_code = ?");
                    $stmt->execute([$_POST['customer_code']]);
                    if ($stmt->fetch()) {
                        throw new Exception("Customer code already exists");
                    }
                    
                    // Insert customer
                    $stmt = $db->prepare("
                        INSERT INTO customers (
                            customer_code, customer_name, contact_person, email, phone, mobile,
                            address_line1, address_line2, city, state, postal_code, country,
                            tax_id, credit_limit, credit_days, price_list_id, is_active, notes, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $_POST['customer_code'],
                        $_POST['customer_name'],
                        $_POST['contact_person'] ?? null,
                        $_POST['email'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['mobile'] ?? null,
                        $_POST['address_line1'] ?? null,
                        $_POST['address_line2'] ?? null,
                        $_POST['city'] ?? null,
                        $_POST['state'] ?? null,
                        $_POST['postal_code'] ?? null,
                        $_POST['country'] ?? 'Sri Lanka',
                        $_POST['tax_id'] ?? null,
                        $_POST['credit_limit'] ?? 0,
                        $_POST['credit_days'] ?? 0,
                        !empty($_POST['price_list_id']) ? $_POST['price_list_id'] : null,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['notes'] ?? null,
                        $_SESSION['user_id']
                    ]);
                    
                    $db->commit();
                    $success = "Customer created successfully!";
                    break;
                    
                case 'update':
                    $db->beginTransaction();
                    
                    // Check if customer code exists for other customers
                    $stmt = $db->prepare("SELECT id FROM customers WHERE customer_code = ? AND id != ?");
                    $stmt->execute([$_POST['customer_code'], $_POST['customer_id']]);
                    if ($stmt->fetch()) {
                        throw new Exception("Customer code already exists");
                    }
                    
                    // Update customer
                    $stmt = $db->prepare("
                        UPDATE customers SET
                            customer_code = ?, customer_name = ?, contact_person = ?, email = ?, 
                            phone = ?, mobile = ?, address_line1 = ?, address_line2 = ?, 
                            city = ?, state = ?, postal_code = ?, country = ?, tax_id = ?,
                            credit_limit = ?, credit_days = ?, price_list_id = ?, is_active = ?, 
                            notes = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $_POST['customer_code'],
                        $_POST['customer_name'],
                        $_POST['contact_person'] ?? null,
                        $_POST['email'] ?? null,
                        $_POST['phone'] ?? null,
                        $_POST['mobile'] ?? null,
                        $_POST['address_line1'] ?? null,
                        $_POST['address_line2'] ?? null,
                        $_POST['city'] ?? null,
                        $_POST['state'] ?? null,
                        $_POST['postal_code'] ?? null,
                        $_POST['country'] ?? 'Sri Lanka',
                        $_POST['tax_id'] ?? null,
                        $_POST['credit_limit'] ?? 0,
                        $_POST['credit_days'] ?? 0,
                        !empty($_POST['price_list_id']) ? $_POST['price_list_id'] : null,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['notes'] ?? null,
                        $_POST['customer_id']
                    ]);
                    
                    $db->commit();
                    $success = "Customer updated successfully!";
                    break;
                    
                case 'delete':
                    // Check if customer has any transactions (you'll add this later with invoices)
                    // For now, just delete
                    $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
                    $stmt->execute([$_POST['customer_id']]);
                    $success = "Customer deleted successfully!";
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

// Fetch all customers with price list details
try {
    $stmt = $db->query("SELECT * FROM v_customer_details ORDER BY customer_name");
    $customers = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching customers: " . $e->getMessage();
}

// Fetch active price lists for dropdown
try {
    $stmt = $db->query("SELECT id, price_list_code, price_list_name FROM price_lists WHERE is_active = 1 ORDER BY price_list_name");
    $price_lists = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching price lists: " . $e->getMessage();
}

// Get next customer code
try {
    $stmt = $db->query("SELECT customer_code FROM customers ORDER BY id DESC LIMIT 1");
    $last_customer = $stmt->fetch();
    if ($last_customer) {
        $last_number = intval(substr($last_customer['customer_code'], 4));
        $next_code = 'CUST' . str_pad($last_number + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $next_code = 'CUST0001';
    }
} catch(PDOException $e) {
    $next_code = 'CUST0001';
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Customer Management</h1>
            <p class="text-gray-600">Manage your customer database and price list assignments</p>
        </div>
        <button onclick="openModal('createCustomerModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            <svg class="w-5 h-5 inline-block mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add New Customer
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

    <!-- Customers Table -->
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price List</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Credit Terms</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                No customers found. Click "Add New Customer" to create one.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $customer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($customer['customer_code']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer['customer_name']); ?></div>
                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($customer['contact_person'] ?? '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div><?php echo htmlspecialchars($customer['email'] ?? '-'); ?></div>
                                <div><?php echo htmlspecialchars($customer['mobile'] ?? $customer['phone'] ?? '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div><?php echo htmlspecialchars($customer['city'] ?? '-'); ?></div>
                                <div><?php echo htmlspecialchars($customer['state'] ?? '-'); ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <?php if ($customer['price_list_name']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo htmlspecialchars($customer['price_list_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">Not Assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <div>Limit: LKR <?php echo number_format($customer['credit_limit'], 2); ?></div>
                                <div>Days: <?php echo $customer['credit_days']; ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($customer['is_active']): ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                                <?php else: ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                <button onclick='editCustomer(<?php echo json_encode($customer); ?>)' 
                                        class="text-indigo-600 hover:text-indigo-900">Edit</button>
                                <button onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['customer_name']); ?>')" 
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

<!-- Create Customer Modal -->
<div id="createCustomerModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Add New Customer</h3>
            <button onclick="closeModal('createCustomerModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create">
            
            <!-- Basic Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer Code *</label>
                    <input type="text" name="customer_code" value="<?php echo $next_code; ?>" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name *</label>
                    <input type="text" name="customer_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Contact Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                    <input type="text" name="contact_person"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
                    <input type="text" name="mobile"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Address Information -->
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address Line 1</label>
                    <input type="text" name="address_line1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address Line 2</label>
                    <input type="text" name="address_line2"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">State/Province</label>
                    <input type="text" name="state"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Postal Code</label>
                    <input type="text" name="postal_code"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                    <input type="text" name="country" value="Sri Lanka"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID (VAT/TIN)</label>
                    <input type="text" name="tax_id"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Credit Terms & Price List -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (LKR)</label>
                    <input type="number" name="credit_limit" value="0" step="0.01"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Days</label>
                    <input type="number" name="credit_days" value="0"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price List</label>
                    <select name="price_list_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">-- Select Price List --</option>
                        <?php foreach ($price_lists as $pl): ?>
                            <option value="<?php echo $pl['id']; ?>">
                                <?php echo htmlspecialchars($pl['price_list_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="is_active" checked
                       class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                <label for="is_active" class="ml-2 block text-sm text-gray-700">Active</label>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('createCustomerModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Create Customer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Customer Modal -->
<div id="editCustomerModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-bold text-gray-900">Edit Customer</h3>
            <button onclick="closeModal('editCustomerModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        
        <form method="POST" class="space-y-6">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="customer_id" id="edit_customer_id">
            
            <!-- Basic Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer Code *</label>
                    <input type="text" name="customer_code" id="edit_customer_code" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name *</label>
                    <input type="text" name="customer_name" id="edit_customer_name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Contact Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                    <input type="text" name="contact_person" id="edit_contact_person"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="edit_email"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                    <input type="text" name="phone" id="edit_phone"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mobile</label>
                    <input type="text" name="mobile" id="edit_mobile"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Address Information -->
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address Line 1</label>
                    <input type="text" name="address_line1" id="edit_address_line1"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address Line 2</label>
                    <input type="text" name="address_line2" id="edit_address_line2"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">City</label>
                    <input type="text" name="city" id="edit_city"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">State/Province</label>
                    <input type="text" name="state" id="edit_state"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Postal Code</label>
                    <input type="text" name="postal_code" id="edit_postal_code"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
                    <input type="text" name="country" id="edit_country"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tax ID (VAT/TIN)</label>
                    <input type="text" name="tax_id" id="edit_tax_id"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
            </div>

            <!-- Credit Terms & Price List -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Limit (LKR)</label>
                    <input type="number" name="credit_limit" id="edit_credit_limit" step="0.01"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Credit Days</label>
                    <input type="number" name="credit_days" id="edit_credit_days"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Price List</label>
                    <select name="price_list_id" id="edit_price_list_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="">-- Select Price List --</option>
                        <?php foreach ($price_lists as $pl): ?>
                            <option value="<?php echo $pl['id']; ?>">
                                <?php echo htmlspecialchars($pl['price_list_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Notes -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea name="notes" id="edit_notes" rows="3"
                          class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary"></textarea>
            </div>

            <!-- Active Status -->
            <div class="flex items-center">
                <input type="checkbox" name="is_active" id="edit_is_active"
                       class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                <label for="edit_is_active" class="ml-2 block text-sm text-gray-700">Active</label>
            </div>

            <!-- Form Actions -->
            <div class="flex justify-end space-x-3 pt-4 border-t">
                <button type="button" onclick="closeModal('editCustomerModal')"
                        class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="submit"
                        class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">
                    Update Customer
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

function editCustomer(customer) {
    document.getElementById('edit_customer_id').value = customer.id;
    document.getElementById('edit_customer_code').value = customer.customer_code;
    document.getElementById('edit_customer_name').value = customer.customer_name;
    document.getElementById('edit_contact_person').value = customer.contact_person || '';
    document.getElementById('edit_email').value = customer.email || '';
    document.getElementById('edit_phone').value = customer.phone || '';
    document.getElementById('edit_mobile').value = customer.mobile || '';
    document.getElementById('edit_address_line1').value = customer.address_line1 || '';
    document.getElementById('edit_address_line2').value = customer.address_line2 || '';
    document.getElementById('edit_city').value = customer.city || '';
    document.getElementById('edit_state').value = customer.state || '';
    document.getElementById('edit_postal_code').value = customer.postal_code || '';
    document.getElementById('edit_country').value = customer.country || 'Sri Lanka';
    document.getElementById('edit_tax_id').value = customer.tax_id || '';
    document.getElementById('edit_credit_limit').value = customer.credit_limit;
    document.getElementById('edit_credit_days').value = customer.credit_days;
    document.getElementById('edit_price_list_id').value = customer.price_list_id || '';
    document.getElementById('edit_notes').value = customer.notes || '';
    document.getElementById('edit_is_active').checked = customer.is_active == 1;
    
    openModal('editCustomerModal');
}

function deleteCustomer(id, name) {
    if (confirm(`Are you sure you want to delete customer "${name}"? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="customer_id" value="${id}">
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