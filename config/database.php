<?php
/**
 * Database Configuration Class
 * City Pet and Vaccination Management System
 * 
 * This class handles database connection using PDO
 */

class Database {
    // Database configuration
    private $charset = "utf8mb4";
private $port;

private function isLocal() {
    return isset($_SERVER['HTTP_HOST']) && 
           (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
            strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false);
}

private function getConfig() {
    if ($this->isLocal()) {
        return [
            'host'     => 'localhost',
            'db_name'  => 'city_pet_vaccination_db',
            'username' => 'root',
            'password' => '',
            'port'     => '3306',
            'charset'  => 'utf8mb4'
        ];
    } else {
        return [
            'host'     => 'turntable.proxy.rlwy.net',
            'db_name'  => 'railway',
            'username' => 'root',
            'password' => 'mihOdLCPesiWNfSCYRtXfERyOjuwCdea',
            'port'     => '42299',
            'charset'  => 'utf8mb4'
        ];
    }
}
    
    public $conn;

    /**
     * Get database connection
     * @return PDO|null
     */
    public function getConnection() {
        $this->conn = null;
        
        try {
            // Create PDO connection string
            $config = $this->getConfig();
$dsn = "mysql:host=" . $config['host'] . ";port=" . $config['port'] . ";dbname=" . $config['db_name'] . ";charset=" . $config['charset'];
            
            // PDO options for better security and performance
            $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET SESSION sql_mode = ''"
];
            
            // Create PDO connection
            $this->conn = new PDO($dsn, $config['username'], $config['password'], $options);
            
        } catch(PDOException $exception) {
            // Log the error (in production, don't expose database details)
            error_log("Connection error: " . $exception->getMessage());
            
            // Return user-friendly error
            throw new Exception("Database connection failed. Please try again later.");
        }
        
        return $this->conn;
    }

    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->conn = null;
    }

    /**
     * Test database connection
     * @return bool
     */
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            if ($conn) {
                return true;
            }
        } catch(Exception $e) {
            return false;
        }
        return false;
    }

    /**
     * Execute a query and return results
     * @param string $query
     * @param array $params
     * @return array
     */
    public function query($query, $params = []) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch(Exception $e) {
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Execute insert/update/delete query
     * @param string $query
     * @param array $params
     * @return bool|int Returns last insert ID for INSERT queries, or affected rows count
     */
    public function execute($query, $params = []) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($query);
            $result = $stmt->execute($params);
            
            // Return last insert ID for INSERT queries
            if (stripos($query, 'INSERT') === 0) {
                return $conn->lastInsertId();
            }
            
            // Return affected rows count for UPDATE/DELETE
            return $stmt->rowCount();
            
        } catch(Exception $e) {
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    /**
     * Begin transaction
     */
    public function beginTransaction() {
        $conn = $this->getConnection();
        return $conn->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit() {
        $conn = $this->getConnection();
        return $conn->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback() {
        $conn = $this->getConnection();
        return $conn->rollBack();
    }

    /**
     * Get database info for debugging
     * @return array
     */
    public function getDatabaseInfo() {
        try {
            $conn = $this->getConnection();
            $info = [
                'host' => $this->host,
                'database' => $this->db_name,
                'charset' => $this->charset,
                'server_version' => $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
                'connection_status' => 'Connected'
            ];
            return $info;
        } catch(Exception $e) {
            return [
                'host' => $this->host,
                'database' => $this->db_name,
                'connection_status' => 'Failed: ' . $e->getMessage()
            ];
        }
    }
}

// Test the connection when this file is accessed directly
if (basename($_SERVER['PHP_SELF']) == 'database.php') {
    try {
        $database = new Database();
        $info = $database->getDatabaseInfo();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Database connection successful',
            'info' => $info
        ], JSON_PRETTY_PRINT);
        
    } catch(Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}
?>