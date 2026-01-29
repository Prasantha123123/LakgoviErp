<?php
// database.php - Database connection and setup
class Database {
    // private $host = 'localhost';
    // private $db_name = 'jaan_lakgovi_erp';
    // private $username = 'root';
    // private $password = '';
    // private $conn;

     private $host = 'localhost';
    private $db_name = 'lakgovi_erp';
     private $username = 'dbuser';
    private $password = 'L{582Phb1Lh5';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                                $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }

}

/**
 * Update item current_stock in items table
 * 
 * @param PDO $db Database connection
 * @param int $item_id Item ID to update
 * @param float $quantity Quantity to add (positive) or subtract (negative)
 * @param bool $set_absolute If true, sets the stock to the exact quantity instead of adding/subtracting
 * @return bool True on success
 */
function updateItemStock($db, $item_id, $quantity, $set_absolute = false) {
    if ($set_absolute) {
        // Set stock to exact value
        $stmt = $db->prepare("UPDATE items SET current_stock = ? WHERE id = ?");
        return $stmt->execute([$quantity, $item_id]);
    } else {
        // Add/subtract from current stock
        $stmt = $db->prepare("UPDATE items SET current_stock = COALESCE(current_stock, 0) + ? WHERE id = ?");
        return $stmt->execute([$quantity, $item_id]);
    }
}

/**
 * Get current stock of an item from items table
 * 
 * @param PDO $db Database connection
 * @param int $item_id Item ID
 * @return float Current stock value
 */
function getItemCurrentStock($db, $item_id) {
    $stmt = $db->prepare("SELECT COALESCE(current_stock, 0) as current_stock FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? floatval($result['current_stock']) : 0;
}

/**
 * Sync item current_stock with stock_ledger balance
 * 
 * @param PDO $db Database connection
 * @param int $item_id Item ID
 * @param int $location_id Location ID (default 1 for main store)
 * @return bool True on success
 */
function syncItemStockFromLedger($db, $item_id, $location_id = 1) {
    // Calculate balance from stock_ledger
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity_in - quantity_out), 0) as ledger_balance 
        FROM stock_ledger 
        WHERE item_id = ? AND location_id = ?
    ");
    $stmt->execute([$item_id, $location_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $ledger_balance = $result ? floatval($result['ledger_balance']) : 0;
    
    // Update items table
    return updateItemStock($db, $item_id, $ledger_balance, true);
}
?>