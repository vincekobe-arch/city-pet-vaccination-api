<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class BarangayController {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    public function index() {
    // Remove authentication check temporarily for testing
    try {
        $query = "SELECT id, name, code, address, contact_person, contact_number, email, is_active, created_at, updated_at
                 FROM barangays 
                 WHERE is_active = 1
                 ORDER BY name ASC";
        
        $stmt = $this->conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->conn->error);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'barangays' => $barangays,
            'total' => count($barangays)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get barangays: ' . $e->getMessage()
        ]);
    }
}
    
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        $required_fields = ['name', 'code'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst($field) . ' is required']);
                return;
            }
        }
        
        try {
            $check_query = "SELECT id FROM barangays WHERE code = :code OR name = :name";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':code', $data->code);
            $check_stmt->bindParam(':name', $data->name);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Barangay code or name already exists']);
                return;
            }
            
            // FIXED: Removed 'population' column
            $query = "INSERT INTO barangays (name, code, address, contact_person, contact_number, email, is_active) 
                     VALUES (:name, :code, :address, :contact_person, :contact_number, :email, 1)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':name', $data->name);
            $stmt->bindParam(':code', $data->code);
            $stmt->bindParam(':address', $data->address ?? null);
            $stmt->bindParam(':contact_person', $data->contact_person ?? null);
            $stmt->bindParam(':contact_number', $data->contact_number ?? null);
            $stmt->bindParam(':email', $data->email ?? null);
            
            if ($stmt->execute()) {
                $barangay_id = $this->conn->lastInsertId();
                
                $get_barangay_query = "SELECT * FROM barangays WHERE id = :barangay_id";
                $get_barangay_stmt = $this->conn->prepare($get_barangay_query);
                $get_barangay_stmt->bindParam(':barangay_id', $barangay_id);
                $get_barangay_stmt->execute();
                
                $barangay = $get_barangay_stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Barangay created successfully',
                    'barangay' => $barangay
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Barangay creation failed']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Barangay creation failed: ' . $e->getMessage()]);
        }
    }
    
    public function show($id) {
        $user_data = $this->auth->authenticate();
        
        try {
            $query = "SELECT b.*, 
                     COUNT(DISTINCT u.id) as total_officials,
                     COUNT(DISTINCT po.id) as total_pet_owners,
                     COUNT(DISTINCT p.id) as total_pets,
                     COUNT(DISTINCT vr.id) as total_vaccinations
                     FROM barangays b
                     LEFT JOIN users u ON b.id = u.assigned_barangay_id AND u.role = 'barangay_official' AND u.is_active = 1
                     LEFT JOIN users u2 ON b.id = u2.assigned_barangay_id AND u2.role = 'pet_owner' AND u2.is_active = 1
                     LEFT JOIN pet_owners po ON u2.id = po.user_id
                     LEFT JOIN pets p ON po.id = p.owner_id AND p.is_active = 1
                     LEFT JOIN vaccination_records vr ON p.id = vr.pet_id
                     WHERE b.id = :barangay_id AND b.is_active = 1
                     GROUP BY b.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':barangay_id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $barangay = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'barangay' => $barangay
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Barangay not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get barangay details: ' . $e->getMessage()]);
        }
    }
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        try {
            $check_query = "SELECT id FROM barangays WHERE id = :barangay_id AND is_active = 1";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':barangay_id', $id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Barangay not found']);
                return;
            }
            
            if (isset($data->code) || isset($data->name)) {
                $duplicate_query = "SELECT id FROM barangays WHERE (code = :code OR name = :name) AND id != :barangay_id";
                $duplicate_stmt = $this->conn->prepare($duplicate_query);
                $duplicate_stmt->bindParam(':code', $data->code ?? '');
                $duplicate_stmt->bindParam(':name', $data->name ?? '');
                $duplicate_stmt->bindParam(':barangay_id', $id);
                $duplicate_stmt->execute();
                
                if ($duplicate_stmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Barangay code or name already exists']);
                    return;
                }
            }
            
            $update_fields = [];
            $params = [':barangay_id' => $id];
            
            // FIXED: Removed 'population' from allowed fields
            $allowed_fields = ['name', 'code', 'address', 'contact_person', 'contact_number', 'email'];
            
            foreach ($allowed_fields as $field) {
                if (isset($data->$field)) {
                    $update_fields[] = "$field = :$field";
                    $params[":$field"] = $data->$field;
                }
            }
            
            if (empty($update_fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }
            
            $query = "UPDATE barangays SET " . implode(', ', $update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :barangay_id";
            
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute($params)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Barangay updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Barangay update failed']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Barangay update failed: ' . $e->getMessage()]);
        }
    }
    
    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);
        
        try {
            $check_users_query = "SELECT COUNT(*) as user_count FROM users WHERE assigned_barangay_id = :barangay_id AND is_active = 1";
            $check_users_stmt = $this->conn->prepare($check_users_query);
            $check_users_stmt->bindParam(':barangay_id', $id);
            $check_users_stmt->execute();
            
            $result = $check_users_stmt->fetch(PDO::FETCH_ASSOC);
            if ($result['user_count'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete barangay with active users. Please reassign or deactivate users first.']);
                return;
            }
            
            $query = "UPDATE barangays SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :barangay_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':barangay_id', $id);
            
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Barangay deactivated successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Barangay not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Barangay deletion failed: ' . $e->getMessage()]);
        }
    }
    
    public function statistics() {
        $user_data = $this->auth->authenticate();
        
        try {
            $stats_query = "SELECT 
                           COUNT(DISTINCT b.id) as total_barangays,
                           COUNT(DISTINCT CASE WHEN u.role = 'barangay_official' THEN u.id END) as total_officials,
                           COUNT(DISTINCT CASE WHEN u.role = 'pet_owner' THEN u.id END) as total_pet_owners,
                           COUNT(DISTINCT p.id) as total_pets,
                           COUNT(DISTINCT vr.id) as total_vaccinations
                           FROM barangays b
                           LEFT JOIN users u ON b.id = u.assigned_barangay_id AND u.is_active = 1
                           LEFT JOIN pet_owners po ON u.id = po.user_id AND u.role = 'pet_owner'
                           LEFT JOIN pets p ON po.id = p.owner_id AND p.is_active = 1
                           LEFT JOIN vaccination_records vr ON p.id = vr.pet_id
                           WHERE b.is_active = 1";
            
            $stats_stmt = $this->conn->prepare($stats_query);
            $stats_stmt->execute();
            $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            
            $top_barangays_query = "SELECT b.name, b.code,
                                   COUNT(DISTINCT p.id) as pet_count,
                                   COUNT(DISTINCT vr.id) as vaccination_count
                                   FROM barangays b
                                   LEFT JOIN users u ON b.id = u.assigned_barangay_id AND u.role = 'pet_owner' AND u.is_active = 1
                                   LEFT JOIN pet_owners po ON u.id = po.user_id
                                   LEFT JOIN pets p ON po.id = p.owner_id AND p.is_active = 1
                                   LEFT JOIN vaccination_records vr ON p.id = vr.pet_id
                                   WHERE b.is_active = 1
                                   GROUP BY b.id
                                   ORDER BY pet_count DESC, vaccination_count DESC
                                   LIMIT 5";
            
            $top_barangays_stmt = $this->conn->prepare($top_barangays_query);
            $top_barangays_stmt->execute();
            $top_barangays = $top_barangays_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'statistics' => $stats,
                'top_barangays' => $top_barangays
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get statistics: ' . $e->getMessage()]);
        }
    }
}
?>