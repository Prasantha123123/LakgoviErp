<?php
// database.php - Database connection and setup
class Database {
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
?>