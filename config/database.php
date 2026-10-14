<?php
require_once __DIR__ . '/env.php';

class Database {

    public $conn;

    private function getConfig() {
        return [
            'host'     => getenv('DB_HOST') ?: 'localhost',
            'db_name'  => getenv('DB_NAME') ?: 'city_pet_vaccination_db',
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') ?: '',
            'port'     => getenv('DB_PORT') ?: '3306',
            'charset'  => 'utf8mb4'
        ];
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $config = $this->getConfig();
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['db_name']};charset={$config['charset']}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4; SET SESSION sql_mode = ''"
            ];

            $this->conn = new PDO($dsn, $config['username'], $config['password'], $options);

        } catch (PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed. Please try again later.");
        }

        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }

    public function testConnection() {
        try {
            return (bool) $this->getConnection();
        } catch (Exception $e) {
            return false;
        }
    }

    public function query($query, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function execute($query, $params = []) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($query);
            $stmt->execute($params);

            if (stripos($query, 'INSERT') === 0) {
                return $conn->lastInsertId();
            }

            return $stmt->rowCount();

        } catch (Exception $e) {
            throw new Exception("Query execution failed: " . $e->getMessage());
        }
    }

    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }

    public function commit() {
        return $this->getConnection()->commit();
    }

    public function rollback() {
        return $this->getConnection()->rollBack();
    }

    public function getDatabaseInfo() {
        try {
            $config = $this->getConfig();
            $conn = $this->getConnection();
            return [
                'host'              => $config['host'],
                'database'          => $config['db_name'],
                'charset'           => $config['charset'],
                'server_version'    => $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
                'connection_status' => 'Connected'
            ];
        } catch (Exception $e) {
            $config = $this->getConfig();
            return [
                'host'              => $config['host'],
                'database'          => $config['db_name'],
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
            'info'    => $info
        ], JSON_PRETTY_PRINT);

    } catch (Exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed',
            'error'   => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}
?>