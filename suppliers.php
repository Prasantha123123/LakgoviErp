<?php
// suppliers.php - Suppliers management
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    $stmt = $db->prepare("INSERT INTO suppliers (code, name, contact, address) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$_POST['code'], $_POST['name'], $_POST['contact'], $_POST['address']]);
                    $success = "Supplier created successfully!";
                    break;
                    
                case 'update':
                    $stmt = $db->prepare("UPDATE suppliers SET code = ?, name = ?, contact = ?, address = ? WHERE id = ?");
                    $stmt->execute([$_POST['code'], $_POST['name'], $_POST['contact'], $_POST['address'], $_POST['id']]);
                    $success = "Supplier updated successfully!";
                    break;
                    
                case 'delete':
                    $stmt = $db->prepare("DELETE FROM suppliers WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    $success = "Supplier deleted successfully!";
                    break;
            }
        }
    } catch(PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch suppliers with stats
try {
    $stmt = $db->query("
        SELECT s.*, 
               COUNT(g.id) as total_grn,
               COALESCE(SUM(g.total_amount), 0) as total_purchase_amount
        FROM suppliers s 
        LEFT JOIN grn g ON s.id = g.supplier_id 
        GROUP BY s.id 
        ORDER BY s.name
    ");
    $suppliers = $stmt->fetchAll();
} catch(PDOException $e) {
    $error = "Error fetching suppliers: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Suppliers Management</h1>
            <p class="text-gray-600">Manage your supplier information and relationships</p>
        </div>
        <button onclick="openModal('createSupplierModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            Add New Supplier
        </button>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <!-- Suppliers Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($suppliers as $supplier): ?>
        <div class="bg-white rounded-lg shadow hover:shadow-md transition-shadow">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($supplier['name']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($supplier['code']); ?></p>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="editSupplier(<?php echo htmlspecialchars(json_encode($supplier)); ?>)" class="text-indigo-600 hover:text-indigo-900">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <form method="POST" class="inline" onsubmit="return confirmDelete('Are you sure you want to delete this supplier?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $supplier['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="space-y-2 mb-4">
                    <?php if ($supplier['contact']): ?>
                    <div class="flex items-center text-sm text-gray-600">
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        <?php echo htmlspecialchars($supplier['contact']); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($supplier['address']): ?>
                    <div class="flex items-start text-sm text-gray-600">
                        <svg class="w-4 h-4 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span class="break-words"><?php echo htmlspecialchars($supplier['address']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="border-t pt-4">
                    <div class="grid grid-cols-2 gap-4 text-center">
                        <div>
                            <p class="text-2xl font-bold text-primary"><?php echo $supplier['total_grn']; ?></p>
                            <p class="text-xs text-gray-600">Total GRNs</p>
                        </div>
                        <div>
                            <p class="text-2xl font-bold text-green-600">රු.<?php echo number_format($supplier['total_purchase_amount'], 0); ?></p>
                            <p class="text-xs text-gray-600">Total Purchase</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($suppliers)): ?>
        <div class="col-span-full bg-white rounded-lg shadow p-8 text-center">
            <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No suppliers found</h3>
            <p class="text-gray-600 mb-4">Get started by adding your first supplier.</p>
            <button onclick="openModal('createSupplierModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
                Add Supplier
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Create Supplier Modal -->
<div id="createSupplierModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Add New Supplier</h3>
            <button onclick="closeModal('createSupplierModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Code</label>
                <input type="text" name="code" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., SUP001">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name</label>
                <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., ABC Trading Co.">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contact</label>
                <input type="text" name="contact" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="e.g., +91 9876543210">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent" placeholder="Enter supplier address"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createSupplierModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Supplier</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div id="editSupplierModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">Edit Supplier</h3>
            <button onclick="closeModal('editSupplierModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_supplier_id">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Code</label>
                <input type="text" name="code" id="edit_supplier_code" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Supplier Name</label>
                <input type="text" name="name" id="edit_supplier_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Contact</label>
                <input type="text" name="contact" id="edit_supplier_contact" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" id="edit_supplier_address" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"></textarea>
            </div>
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('editSupplierModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Update Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
function editSupplier(supplier) {
    document.getElementById('edit_supplier_id').value = supplier.id;
    document.getElementById('edit_supplier_code').value = supplier.code;
    document.getElementById('edit_supplier_name').value = supplier.name;
    document.getElementById('edit_supplier_contact').value = supplier.contact || '';
    document.getElementById('edit_supplier_address').value = supplier.address || '';
    openModal('editSupplierModal');
}
</script>

<?php include 'footer.php'; ?>