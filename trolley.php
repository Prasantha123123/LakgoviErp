<?php
// trolley.php - Complete Trolley Management System
include 'header.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $success = null;
    $error = null;
    $transaction_started = false;
    
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_movement':
                    // Create new trolley movement from production
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    // Validate production exists and is completed
                    $stmt = $db->prepare("
                        SELECT p.*, i.name as item_name, i.unit_weight_kg, l.name as location_name
                        FROM production p
                        JOIN items i ON p.item_id = i.id
                        JOIN locations l ON p.location_id = l.id
                        WHERE p.id = ? AND p.status = 'completed'
                    ");
                    $stmt->execute([$_POST['production_id']]);
                    $production = $stmt->fetch();
                    
                    if (!$production) {
                        throw new Exception("Production batch not found or not completed");
                    }
                    
                    // Calculate expected weight
                    $expected_units = floatval($production['actual_qty']);
                    $unit_weight = floatval($production['unit_weight_kg']);
                    $expected_weight = $expected_units * $unit_weight;
                    
                    // Generate movement number
                    $stmt = $db->query("SELECT COUNT(*) as count FROM trolley_movements");
                    $count = $stmt->fetch()['count'] + 1;
                    $movement_no = 'TM' . str_pad($count, 4, '0', STR_PAD_LEFT);
                    
                    // Insert trolley movement
                    $stmt = $db->prepare("
                        INSERT INTO trolley_movements (
                            movement_no, trolley_id, production_id, from_location_id, to_location_id,
                            movement_date, expected_weight_kg, expected_units, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([
                        $movement_no,
                        $_POST['trolley_id'],
                        $_POST['production_id'],
                        $production['location_id'], // From production location
                        1, // To store (location_id = 1)
                        $_POST['movement_date'],
                        $expected_weight,
                        $expected_units
                    ]);
                    $movement_id = $db->lastInsertId();
                    
                    // Insert trolley item
                    $stmt = $db->prepare("
                        INSERT INTO trolley_items (
                            movement_id, item_id, expected_quantity, expected_weight_kg, unit_weight_kg
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $movement_id,
                        $production['item_id'],
                        $expected_units,
                        $expected_weight,
                        $unit_weight
                    ]);
                    
                    // Update trolley status
                    $stmt = $db->prepare("UPDATE trolleys SET status = 'in_use' WHERE id = ?");
                    $stmt->execute([$_POST['trolley_id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Trolley movement {$movement_no} created! Expected: {$expected_units} units, {$expected_weight} kg";
                    break;
                    
                case 'verify_weight':
                    // Verify trolley weight and units
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $movement_id = $_POST['movement_id'];
                    $actual_weight = floatval($_POST['actual_weight_kg']);
                    $actual_units = floatval($_POST['actual_units']);
                    
                    // Get movement details
                    $stmt = $db->prepare("
                        SELECT tm.*, ti.expected_quantity, ti.expected_weight_kg, ti.unit_weight_kg, ti.item_id,
                               i.weight_tolerance_percent, i.name as item_name
                        FROM trolley_movements tm
                        JOIN trolley_items ti ON tm.id = ti.movement_id
                        JOIN items i ON ti.item_id = i.id
                        WHERE tm.id = ?
                    ");
                    $stmt->execute([$movement_id]);
                    $movement = $stmt->fetch();
                    
                    if (!$movement) {
                        throw new Exception("Trolley movement not found");
                    }
                    
                    // Calculate variances
                    $weight_variance = $actual_weight - $movement['expected_weight_kg'];
                    $unit_variance = $actual_units - $movement['expected_quantity'];
                    
                    // Check tolerances
                    $weight_tolerance = ($movement['weight_tolerance_percent'] / 100) * $movement['expected_weight_kg'];
                    $weight_within_tolerance = abs($weight_variance) <= $weight_tolerance;
                    $units_match = $actual_units == $movement['expected_quantity'];
                    
                    $verification_status = ($weight_within_tolerance && $units_match) ? 'verified' : 'rejected';
                    
                    // Update movement
                    $stmt = $db->prepare("
                        UPDATE trolley_movements SET 
                            actual_weight_kg = ?, 
                            actual_units = ?,
                            weight_variance_kg = ?,
                            unit_variance = ?,
                            status = ?,
                            verified_by = ?,
                            verified_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $actual_weight,
                        $actual_units,
                        $weight_variance,
                        $unit_variance,
                        $verification_status,
                        6, // Current user ID - replace with actual user session
                        $movement_id
                    ]);
                    
                    // Update trolley item
                    $stmt = $db->prepare("
                        UPDATE trolley_items SET 
                            actual_quantity = ?,
                            actual_weight_kg = ?,
                            variance_quantity = ?,
                            variance_weight_kg = ?,
                            status = ?
                        WHERE movement_id = ?
                    ");
                    $stmt->execute([
                        $actual_units,
                        $actual_weight,
                        $unit_variance,
                        $weight_variance,
                        $verification_status,
                        $movement_id
                    ]);
                    
                    if ($verification_status === 'verified') {
                        // Transfer stock from production to store
                        $production_location = $movement['from_location_id'];
                        $store_location = $movement['to_location_id'];
                        
                        // Get current balances
                        $stmt = $db->prepare("
                            SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as current_balance 
                            FROM stock_ledger 
                            WHERE item_id = ? AND location_id = ?
                        ");
                        
                        // Production location balance
                        $stmt->execute([$movement['item_id'], $production_location]);
                        $prod_balance = $stmt->fetch()['current_balance'];
                        $new_prod_balance = $prod_balance - $actual_units;
                        
                        // Store location balance  
                        $stmt->execute([$movement['item_id'], $store_location]);
                        $store_balance = $stmt->fetch()['current_balance'];
                        $new_store_balance = $store_balance + $actual_units;
                        
                        // Record stock movements
                        // OUT from production
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger (
                                item_id, location_id, transaction_type, reference_id, reference_no,
                                transaction_date, quantity_out, balance, created_at
                            ) VALUES (?, ?, 'trolley', ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $movement['item_id'],
                            $production_location,
                            $movement_id,
                            $movement['movement_no'],
                            $movement['movement_date'],
                            $actual_units,
                            $new_prod_balance
                        ]);
                        
                        // IN to store
                        $stmt = $db->prepare("
                            INSERT INTO stock_ledger (
                                item_id, location_id, transaction_type, reference_id, reference_no,
                                transaction_date, quantity_in, balance, created_at
                            ) VALUES (?, ?, 'trolley', ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $movement['item_id'],
                            $store_location,
                            $movement_id,
                            $movement['movement_no'],
                            $movement['movement_date'],
                            $actual_units,
                            $new_store_balance
                        ]);
                        
                        // Update movement status to completed
                        $stmt = $db->prepare("UPDATE trolley_movements SET status = 'completed' WHERE id = ?");
                        $stmt->execute([$movement_id]);
                        
                        // Free up trolley
                        $stmt = $db->prepare("UPDATE trolleys SET status = 'available', current_location_id = ? WHERE id = ?");
                        $stmt->execute([$store_location, $movement['trolley_id']]);
                        
                        $success = "✅ Verification PASSED! {$actual_units} units transferred to store. Weight variance: " . 
                                  ($weight_variance >= 0 ? '+' : '') . number_format($weight_variance, 3) . " kg";
                    } else {
                        $rejection_reasons = [];
                        if (!$weight_within_tolerance) {
                            $rejection_reasons[] = "Weight variance " . number_format($weight_variance, 3) . 
                                                 " kg exceeds tolerance ±" . number_format($weight_tolerance, 3) . " kg";
                        }
                        if (!$units_match) {
                            $rejection_reasons[] = "Unit count mismatch: expected {$movement['expected_quantity']}, actual {$actual_units}";
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE trolley_items SET rejection_reason = ? WHERE movement_id = ?
                        ");
                        $stmt->execute([implode('; ', $rejection_reasons), $movement_id]);
                        
                        // Return trolley to available status
                        $stmt = $db->prepare("UPDATE trolleys SET status = 'available' WHERE id = ?");
                        $stmt->execute([$movement['trolley_id']]);
                        
                        $error = "❌ Verification FAILED: " . implode('. ', $rejection_reasons);
                    }
                    
                    $db->commit();
                    $transaction_started = false;
                    break;
                    
                case 'reset_movement':
                    // Reset a rejected movement for retry
                    $db->beginTransaction();
                    $transaction_started = true;
                    
                    $stmt = $db->prepare("UPDATE trolley_movements SET status = 'pending', verified_by = NULL, verified_at = NULL WHERE id = ? AND status = 'rejected'");
                    $stmt->execute([$_POST['movement_id']]);
                    
                    $stmt = $db->prepare("UPDATE trolley_items SET status = 'pending', rejection_reason = NULL WHERE movement_id = ?");
                    $stmt->execute([$_POST['movement_id']]);
                    
                    $db->commit();
                    $transaction_started = false;
                    $success = "Movement reset for retry";
                    break;
            }
        }
    } catch(PDOException $e) {
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = "Database error: " . $e->getMessage();
    } catch(Exception $e) {
        if ($transaction_started && $db->inTransaction()) {
            $db->rollback();
        }
        $error = $e->getMessage();
    }
}

// Fetch data for display
try {
    // Get available trolleys
    $stmt = $db->query("
        SELECT t.*, l.name as current_location_name 
        FROM trolleys t 
        JOIN locations l ON t.current_location_id = l.id 
        ORDER BY t.trolley_no
    ");
    $trolleys = $stmt->fetchAll();
    
    // Get completed productions ready for trolley
    $stmt = $db->query("
        SELECT p.*, i.name as item_name, i.code as item_code, i.unit_weight_kg,
               l.name as location_name
        FROM production p
        JOIN items i ON p.item_id = i.id  
        JOIN locations l ON p.location_id = l.id
        WHERE p.status = 'completed' 
        AND p.id NOT IN (SELECT production_id FROM trolley_movements WHERE production_id IS NOT NULL)
        ORDER BY p.created_at DESC
    ");
    $ready_productions = $stmt->fetchAll();
    
    // Get active trolley movements
    $stmt = $db->query("
        SELECT tm.*, t.trolley_no, t.trolley_name,
               fl.name as from_location, tl.name as to_location,
               ti.item_id, i.name as item_name, i.code as item_code,
               ti.expected_quantity, ti.expected_weight_kg, ti.rejection_reason
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        JOIN trolley_items ti ON tm.id = ti.movement_id
        JOIN items i ON ti.item_id = i.id
        WHERE tm.status IN ('pending', 'in_transit', 'verified', 'rejected')
        ORDER BY tm.created_at DESC
    ");
    $active_movements = $stmt->fetchAll();
    
    // Get recent completed movements for history
    $stmt = $db->query("
        SELECT tm.*, t.trolley_no, i.name as item_name,
               fl.name as from_location, tl.name as to_location,
               ti.actual_quantity, ti.actual_weight_kg, ti.variance_weight_kg
        FROM trolley_movements tm
        JOIN trolleys t ON tm.trolley_id = t.id
        JOIN locations fl ON tm.from_location_id = fl.id
        JOIN locations tl ON tm.to_location_id = tl.id
        JOIN trolley_items ti ON tm.id = ti.movement_id
        JOIN items i ON ti.item_id = i.id
        WHERE tm.status = 'completed'
ORDER BY tm.verified_at DESC, tm.created_at DESC
        LIMIT 20
    ");
    $completed_movements = $stmt->fetchAll();
    
} catch(PDOException $e) {
    $error = "Error fetching data: " . $e->getMessage();
}
?>

<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">🛒 Trolley Management</h1>
            <p class="text-gray-600">Transfer finished goods from Production to Store with weight verification</p>
        </div>
        <button onclick="openModal('createMovementModal')" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-blue-600 transition-colors">
            ➕ Create Trolley Movement
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

    <!-- Trolley Status Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Available Trolleys</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo count(array_filter($trolleys, function($t) { return $t['status'] === 'available'; })); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">In Use</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo count(array_filter($trolleys, function($t) { return $t['status'] === 'in_use'; })); ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-yellow-100">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Ready Productions</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($ready_productions); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white p-6 rounded-lg shadow">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Active Movements</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($active_movements); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Trolley Movements -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">🚛 Active Trolley Movements</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Movement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expected</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($active_movements as $movement): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['movement_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo date('d M Y', strtotime($movement['movement_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['trolley_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $movement['trolley_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['item_name']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $movement['item_code']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div class="flex items-center">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?php echo $movement['from_location']; ?>
                                </span>
                                <svg class="w-4 h-4 mx-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                    <?php echo $movement['to_location']; ?>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div><?php echo number_format($movement['expected_quantity'], 3); ?> units</div>
                            <div class="text-gray-500"><?php echo number_format($movement['expected_weight_kg'], 3); ?> kg</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                echo $movement['status'] === 'completed' ? 'bg-green-100 text-green-800' : 
                                    ($movement['status'] === 'verified' ? 'bg-blue-100 text-blue-800' : 
                                    ($movement['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800')); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $movement['status'])); ?>
                            </span>
                            <?php if ($movement['status'] === 'rejected' && $movement['rejection_reason']): ?>
                                <div class="text-xs text-red-600 mt-1" title="<?php echo htmlspecialchars($movement['rejection_reason']); ?>">
                                    <?php echo substr($movement['rejection_reason'], 0, 30) . (strlen($movement['rejection_reason']) > 30 ? '...' : ''); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <?php if ($movement['status'] === 'pending'): ?>
                                <button onclick="openVerificationModal(<?php echo htmlspecialchars(json_encode($movement)); ?>)" 
                                        class="text-blue-600 hover:text-blue-900 mr-2">
                                    ⚖️ Verify Weight
                                </button>
                            <?php elseif ($movement['status'] === 'verified'): ?>
                                <span class="text-green-600">✅ Ready to complete</span>
                            <?php elseif ($movement['status'] === 'rejected'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="reset_movement">
                                    <input type="hidden" name="movement_id" value="<?php echo $movement['id']; ?>">
                                    <button type="submit" class="text-orange-600 hover:text-orange-900">
                                        🔄 Reset for Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($active_movements)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No active trolley movements</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- All Trolleys Status -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">🛒 Trolley Fleet Status</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Location</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Max Weight</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($trolleys as $trolley): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $trolley['trolley_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo $trolley['trolley_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo $trolley['current_location_name']; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo number_format($trolley['max_weight_kg'], 1); ?> kg
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php 
                                echo $trolley['status'] === 'available' ? 'bg-green-100 text-green-800' : 
                                    ($trolley['status'] === 'in_use' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); 
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $trolley['status'])); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Completed Movements -->
    <?php if (!empty($completed_movements)): ?>
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">📋 Recent Completed Movements</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Movement</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trolley</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actual Results</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight Variance</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($completed_movements as $movement): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['movement_no']; ?></div>
                            <div class="text-sm text-gray-500"><?php echo date('d M Y', strtotime($movement['movement_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900"><?php echo $movement['trolley_no']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo $movement['item_name']; ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <div><?php echo number_format($movement['actual_quantity'], 3); ?> units</div>
                            <div class="text-gray-500"><?php echo number_format($movement['actual_weight_kg'], 3); ?> kg</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="<?php echo $movement['variance_weight_kg'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                <?php echo ($movement['variance_weight_kg'] >= 0 ? '+' : '') . number_format($movement['variance_weight_kg'], 3); ?> kg
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                ✅ Completed
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Create Movement Modal -->
<div id="createMovementModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">🛒 Create Trolley Movement</h3>
            <button onclick="closeModal('createMovementModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_movement">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Production Batch</label>
                <select name="production_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" onchange="updateExpectedWeight(this)">
                    <option value="">Select Production Batch</option>
                    <?php foreach ($ready_productions as $prod): ?>
                        <option value="<?php echo $prod['id']; ?>" 
                                data-quantity="<?php echo $prod['actual_qty']; ?>"
                                data-weight="<?php echo $prod['unit_weight_kg']; ?>"
                                data-item="<?php echo htmlspecialchars($prod['item_name']); ?>">
                            <?php echo $prod['batch_no']; ?> - <?php echo $prod['item_name']; ?> (<?php echo number_format($prod['actual_qty'], 3); ?> units)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Select Trolley</label>
                <select name="trolley_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary">
                    <option value="">Select Trolley</option>
                    <?php foreach ($trolleys as $trolley): ?>
                        <?php if ($trolley['status'] === 'available'): ?>
                            <option value="<?php echo $trolley['id']; ?>" data-max-weight="<?php echo $trolley['max_weight_kg']; ?>">
                                <?php echo $trolley['trolley_no']; ?> - <?php echo $trolley['trolley_name']; ?> (Max: <?php echo number_format($trolley['max_weight_kg'], 1); ?> kg)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Movement Date</label>
                <input type="date" name="movement_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <!-- Expected Weight Display -->
            <div id="expectedWeightDisplay" class="bg-blue-50 border border-blue-200 rounded-md p-3 hidden">
                <h4 class="text-sm font-medium text-blue-800 mb-2">Expected Load:</h4>
                <div class="text-sm text-blue-700">
                    <div>Units: <span id="expectedUnits">-</span></div>
                    <div>Weight: <span id="expectedWeight">-</span> kg</div>
                    <div>Item: <span id="expectedItem">-</span></div>
                </div>
            </div>
            
            <!-- Weight Check Warning -->
            <div id="weightWarning" class="bg-red-50 border border-red-200 rounded-md p-3 hidden">
                <div class="flex">
                    <svg class="w-5 h-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <strong class="text-red-800">Warning:</strong>
                        <p class="text-sm text-red-700 mt-1">
                            Expected weight exceeds trolley capacity!
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('createMovementModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" id="createMovementBtn" class="px-4 py-2 bg-primary text-white rounded-md hover:bg-blue-600">Create Movement</button>
            </div>
        </form>
    </div>
</div>

<!-- Weight Verification Modal -->
<div id="verificationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full modal-backdrop hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold text-gray-900">⚖️ Weight & Unit Verification</h3>
            <button onclick="closeModal('verificationModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="space-y-4" onsubmit="return confirmVerification()">
            <input type="hidden" name="action" value="verify_weight">
            <input type="hidden" name="movement_id" id="verify_movement_id">
            
            <!-- Expected vs Actual Display -->
            <div class="bg-gray-50 border border-gray-200 rounded-md p-4">
                <h4 class="text-sm font-medium text-gray-800 mb-3">Expected Values:</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-gray-600">Units:</div>
                        <div class="font-medium" id="verify_expected_units">-</div>
                    </div>
                    <div>
                        <div class="text-gray-600">Weight:</div>
                        <div class="font-medium" id="verify_expected_weight">-</div>
                    </div>
                </div>
                <div class="mt-2 text-xs text-gray-600">
                    Tolerance: <span id="verify_tolerance">±5%</span>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Actual Units Count</label>
                <input type="number" name="actual_units" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Count the actual units" onchange="calculateVariance()">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Actual Weight (kg)</label>
                <input type="number" name="actual_weight_kg" step="0.001" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Weigh the trolley load" onchange="calculateVariance()">
            </div>
            
            <!-- Variance Display -->
            <div id="varianceDisplay" class="hidden">
                <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                    <h4 class="text-sm font-medium text-blue-800 mb-2">Variance Analysis:</h4>
                    <div class="text-sm text-blue-700">
                        <div>Unit Variance: <span id="unitVariance">-</span></div>
                        <div>Weight Variance: <span id="weightVariance">-</span></div>
                        <div>Status: <span id="varianceStatus">-</span></div>
                    </div>
                </div>
            </div>
            
            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-3">
                <div class="flex">
                    <svg class="w-5 h-5 text-yellow-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div>
                        <strong class="text-yellow-800">Important:</strong>
                        <p class="text-sm text-yellow-700 mt-1">
                            Count units carefully and weigh accurately. Items outside tolerance will be rejected and require investigation.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4">
                <button type="button" onclick="closeModal('verificationModal')" class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                    ⚖️ Verify & Process
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentMovement = null;
let expectedWeight = 0;
let expectedUnits = 0;
let weightTolerance = 5; // Default 5%

function updateExpectedWeight(select) {
    const selectedOption = select.options[select.selectedIndex];
    const trolleySelect = document.querySelector('select[name="trolley_id"]');
    const submitBtn = document.getElementById('createMovementBtn');
    
    if (selectedOption.value) {
        const quantity = parseFloat(selectedOption.getAttribute('data-quantity'));
        const unitWeight = parseFloat(selectedOption.getAttribute('data-weight'));
        const itemName = selectedOption.getAttribute('data-item');
        const totalWeight = quantity * unitWeight;
        
        expectedWeight = totalWeight;
        expectedUnits = quantity;
        
        document.getElementById('expectedUnits').textContent = quantity.toFixed(3);
        document.getElementById('expectedWeight').textContent = totalWeight.toFixed(3);
        document.getElementById('expectedItem').textContent = itemName;
        document.getElementById('expectedWeightDisplay').classList.remove('hidden');
        
        // Check trolley capacity
        checkTrolleyCapacity(totalWeight, trolleySelect);
    } else {
        document.getElementById('expectedWeightDisplay').classList.add('hidden');
        document.getElementById('weightWarning').classList.add('hidden');
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}

function checkTrolleyCapacity(weight, trolleySelect) {
    const submitBtn = document.getElementById('createMovementBtn');
    const warningDiv = document.getElementById('weightWarning');
    
    if (trolleySelect.value) {
        const selectedTrolley = trolleySelect.options[trolleySelect.selectedIndex];
        const maxWeight = parseFloat(selectedTrolley.getAttribute('data-max-weight'));
        
        if (weight > maxWeight) {
            warningDiv.classList.remove('hidden');
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        } else {
            warningDiv.classList.add('hidden');
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
}

// Add event listener to trolley select
document.addEventListener('DOMContentLoaded', function() {
    const trolleySelect = document.querySelector('select[name="trolley_id"]');
    if (trolleySelect) {
        trolleySelect.addEventListener('change', function() {
            if (expectedWeight > 0) {
                checkTrolleyCapacity(expectedWeight, this);
            }
        });
    }
});

function openVerificationModal(movement) {
    currentMovement = movement;
    expectedWeight = parseFloat(movement.expected_weight_kg);
    expectedUnits = parseFloat(movement.expected_quantity);
    weightTolerance = 5; // Default, you might want to get this from the item data
    
    document.getElementById('verify_movement_id').value = movement.id;
    document.getElementById('verify_expected_units').textContent = expectedUnits.toFixed(3) + ' units';
    document.getElementById('verify_expected_weight').textContent = expectedWeight.toFixed(3) + ' kg';
    document.getElementById('verify_tolerance').textContent = '±' + weightTolerance + '%';
    
    // Reset form
    document.querySelector('input[name="actual_units"]').value = '';
    document.querySelector('input[name="actual_weight_kg"]').value = '';
    document.getElementById('varianceDisplay').classList.add('hidden');
    
    openModal('verificationModal');
}

function calculateVariance() {
    const actualUnitsInput = document.querySelector('input[name="actual_units"]');
    const actualWeightInput = document.querySelector('input[name="actual_weight_kg"]');
    
    if (actualUnitsInput.value && actualWeightInput.value) {
        const actualUnits = parseFloat(actualUnitsInput.value);
        const actualWeight = parseFloat(actualWeightInput.value);
        
        const unitVariance = actualUnits - expectedUnits;
        const weightVariance = actualWeight - expectedWeight;
        const weightToleranceAmount = (weightTolerance / 100) * expectedWeight;
        
        const unitsMatch = actualUnits === expectedUnits;
        const weightWithinTolerance = Math.abs(weightVariance) <= weightToleranceAmount;
        
        document.getElementById('unitVariance').textContent = 
            (unitVariance >= 0 ? '+' : '') + unitVariance.toFixed(3) + ' units';
        document.getElementById('weightVariance').textContent = 
            (weightVariance >= 0 ? '+' : '') + weightVariance.toFixed(3) + ' kg';
        
        let status = '';
        let statusClass = '';
        
        if (unitsMatch && weightWithinTolerance) {
            status = '✅ WILL PASS - Within tolerance';
            statusClass = 'text-green-600';
        } else {
            status = '❌ WILL FAIL - Outside tolerance';
            statusClass = 'text-red-600';
            
            const reasons = [];
            if (!unitsMatch) reasons.push('Unit count mismatch');
            if (!weightWithinTolerance) reasons.push('Weight outside ±' + weightToleranceAmount.toFixed(3) + ' kg tolerance');
            status += ' (' + reasons.join(', ') + ')';
        }
        
        const statusElement = document.getElementById('varianceStatus');
        statusElement.textContent = status;
        statusElement.className = statusClass + ' font-medium';
        
        document.getElementById('varianceDisplay').classList.remove('hidden');
    }
}

function confirmVerification() {
    const actualUnits = parseFloat(document.querySelector('input[name="actual_units"]').value);
    const actualWeight = parseFloat(document.querySelector('input[name="actual_weight_kg"]').value);
    
    const unitVariance = actualUnits - expectedUnits;
    const weightVariance = actualWeight - expectedWeight;
    const weightToleranceAmount = (weightTolerance / 100) * expectedWeight;
    
    const unitsMatch = actualUnits === expectedUnits;
    const weightWithinTolerance = Math.abs(weightVariance) <= weightToleranceAmount;
    
    if (!unitsMatch || !weightWithinTolerance) {
        const reasons = [];
        if (!unitsMatch) reasons.push(`Unit count: expected ${expectedUnits}, actual ${actualUnits}`);
        if (!weightWithinTolerance) reasons.push(`Weight variance ${weightVariance.toFixed(3)} kg exceeds ±${weightToleranceAmount.toFixed(3)} kg tolerance`);
        
        return confirm('⚠️ VERIFICATION WILL FAIL\n\n' + reasons.join('\n') + 
                      '\n\nThis movement will be REJECTED. Continue anyway?');
    }
    
    return confirm('✅ Verification will PASS. Continue with stock transfer?');
}

// Modal helper functions
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Auto-refresh page every 30 seconds to show updates
setInterval(function() {
    if (!document.querySelector('.modal-backdrop:not(.hidden)')) {
        location.reload();
    }
}, 30000);
</script>

<?php include 'footer.php'; ?>