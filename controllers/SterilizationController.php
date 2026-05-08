<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class SterilizationController {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    public function index() {
        $user_data = $this->auth->authenticate();
        
        try {
            $base_query = "SELECT sr.*, p.name as pet_name, p.registration_number, p.species,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name,
                     CONCAT(po.first_name, ' ', po.last_name) as owner_name,
                     CASE WHEN usr.role = 'private_clinic' THEN pc.clinic_name
                          ELSE 'City Vet Muntinlupa' END as recorded_by
                     FROM sterilization_records sr
                     JOIN pets p ON sr.pet_id = p.id
                     JOIN users u ON sr.administered_by = u.id
                     JOIN users usr ON sr.administered_by = usr.id
                     LEFT JOIN private_clinics pc ON usr.id = pc.user_id
                     JOIN pet_owners po ON p.owner_id = po.id
                     WHERE p.is_active = 1";

            if ($user_data['role'] === 'private_clinic') {
                $base_query .= " AND sr.administered_by = :user_id";
            }
            // super_admin and barangay_official see ALL records

            $base_query .= " ORDER BY sr.sterilization_date DESC";

            $stmt = $this->conn->prepare($base_query);
            if ($user_data['role'] === 'private_clinic') {
                $stmt->bindParam(':user_id', $user_data['user_id']);
            }
            $stmt->execute();
            
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'sterilization_records' => $records,
                'total' => count($records)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get sterilization records: ' . $e->getMessage()
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
        $this->auth->checkRole(['barangay_official', 'super_admin', 'private_clinic'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        $required_fields = ['pet_id', 'procedure_type', 'sterilization_date'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }
        
        try {
    $this->conn->beginTransaction();
    
    // First, check if pet exists and get current sterilization status
    $pet_check = "SELECT id, sterilized, sterilization_date 
                  FROM pets 
                  WHERE id = :pet_id AND is_active = 1";
    
    $pet_stmt = $this->conn->prepare($pet_check);
    $pet_stmt->bindParam(':pet_id', $data->pet_id);
    $pet_stmt->execute();
    
    $pet_data = $pet_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pet_data) {
        $this->conn->rollBack();
        http_response_code(404);
        echo json_encode([
            'error' => 'Pet not found',
            'message' => 'The specified pet does not exist or is inactive.'
        ]);
        return;
    }
    
    // Check if pet is already marked as sterilized
    if ($pet_data['sterilized'] == 1) {
        $this->conn->rollBack();
        http_response_code(409);
        echo json_encode([
            'error' => 'Pet already sterilized',
            'message' => 'This pet is already sterilized and cannot be sterilized again.',
            'details' => [
                'sterilization_date' => $pet_data['sterilization_date']
            ]
        ]);
        return;
    }
    
    // Double-check sterilization records table
    $duplicate_check = "SELECT id, sterilization_date, procedure_type
                       FROM sterilization_records 
                       WHERE pet_id = :pet_id 
                       LIMIT 1";
    
    $check_stmt = $this->conn->prepare($duplicate_check);
    $check_stmt->bindParam(':pet_id', $data->pet_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
        $this->conn->rollBack();
        http_response_code(409);
        echo json_encode([
            'error' => 'Pet already sterilized',
            'message' => 'This pet already has a sterilization record.',
            'details' => [
                'sterilization_date' => $existing['sterilization_date'],
                'procedure_type' => $existing['procedure_type']
            ]
        ]);
        return;
    }
            
            // Insert sterilization record
            // Insert sterilization record
$query = "INSERT INTO sterilization_records 
         (pet_id, sterilization_date, procedure_type, veterinarian_name, weight, administered_by) 
         VALUES 
         (:pet_id, :sterilization_date, :procedure_type, :veterinarian_name, :weight, :administered_by)";

$stmt = $this->conn->prepare($query);
$stmt->bindParam(':pet_id', $data->pet_id);
$stmt->bindParam(':sterilization_date', $data->sterilization_date);
$stmt->bindParam(':procedure_type', $data->procedure_type);

$veterinarian_name = isset($data->veterinarian_name) ? $data->veterinarian_name : null;
$stmt->bindParam(':veterinarian_name', $veterinarian_name);

$weight = isset($data->weight) ? $data->weight : null;
$stmt->bindParam(':weight', $weight);

$stmt->bindParam(':administered_by', $user_data['user_id']);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create sterilization record');
            }
            
            $sterilization_id = $this->conn->lastInsertId();
            
            // Update pet table - mark as sterilized
            $update_pet = "UPDATE pets SET sterilized = 1, 
                          sterilized_by = :veterinarian_name,
                          sterilization_date = :sterilization_date
                          WHERE id = :pet_id";
            $update_stmt = $this->conn->prepare($update_pet);
            $update_stmt->bindParam(':veterinarian_name', $veterinarian_name);
            $update_stmt->bindParam(':sterilization_date', $data->sterilization_date);
            $update_stmt->bindParam(':pet_id', $data->pet_id);
            $update_stmt->execute();
            
            // Update vet card with sterilization info
            $this->updateVetCardRecords($data->pet_id, $user_data['user_id']);
            
            $this->conn->commit();
            
            // Fetch the created record with details
            $get_record_query = "SELECT sr.*, 
                                CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                                FROM sterilization_records sr
                                JOIN users u ON sr.administered_by = u.id
                                WHERE sr.id = :sterilization_id";
            
            $get_stmt = $this->conn->prepare($get_record_query);
            $get_stmt->bindParam(':sterilization_id', $sterilization_id);
            $get_stmt->execute();
            $record = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Sterilization record created successfully',
                'sterilization_record' => $record
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Sterilization record creation failed: ' . $e->getMessage()]);
        }
    }
    
    private function updateVetCardRecords($pet_id, $updated_by) {
        try {
            // Get sterilization record for the pet
            $query = "SELECT * FROM sterilization_records WHERE pet_id = :pet_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            $sterilization = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sterilization) {
                // Convert to JSON
                $sterilization_json = json_encode($sterilization);
                
                // Update vet card - add sterilization_record column if not exists
                $update_query = "UPDATE vet_cards 
                                SET last_updated_by = :updated_by,
                                    updated_at = CURRENT_TIMESTAMP
                                WHERE pet_id = :pet_id AND is_active = 1";
                
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->bindParam(':updated_by', $updated_by);
                $update_stmt->bindParam(':pet_id', $pet_id);
                
                return $update_stmt->execute();
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Failed to update vet card records: " . $e->getMessage());
            return false;
        }
    }
    
    public function getByPetId($pet_id) {
        try {
            $query = "SELECT sr.*, 
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM sterilization_records sr
                     JOIN users u ON sr.administered_by = u.id
                     WHERE sr.pet_id = :pet_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'sterilization_record' => $record
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get sterilization record: ' . $e->getMessage()]);
        }
    }
    
    public function getStatistics() {
        $user_data = $this->auth->authenticate();
        
        try {
            // Total sterilizations
            $total_query = "SELECT COUNT(*) as total FROM sterilization_records";
            $total_stmt = $this->conn->prepare($total_query);
            $total_stmt->execute();
            $total_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Sterilizations this month
            $month_query = "SELECT COUNT(*) as total 
                           FROM sterilization_records 
                           WHERE MONTH(sterilization_date) = MONTH(CURRENT_DATE()) 
                           AND YEAR(sterilization_date) = YEAR(CURRENT_DATE())";
            $month_stmt = $this->conn->prepare($month_query);
            $month_stmt->execute();
            $month_result = $month_stmt->fetch(PDO::FETCH_ASSOC);
            
            // By procedure type
            $type_query = "SELECT procedure_type, COUNT(*) as count 
                          FROM sterilization_records 
                          GROUP BY procedure_type";
            $type_stmt = $this->conn->prepare($type_query);
            $type_stmt->execute();
            $type_result = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'statistics' => [
                    'total_sterilizations' => $total_result['total'],
                    'this_month' => $month_result['total'],
                    'by_procedure_type' => $type_result
                ]
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get statistics: ' . $e->getMessage()]);
        }
    }
    
    public function show($id) {
        $user_data = $this->auth->authenticate();
        
        try {
            $query = "SELECT sr.*, p.name as pet_name, 
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM sterilization_records sr
                     JOIN pets p ON sr.pet_id = p.id
                     JOIN users u ON sr.administered_by = u.id
                     WHERE sr.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success' => true,
                    'sterilization_record' => $record
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Sterilization record not found']);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get sterilization record: ' . $e->getMessage()]);
        }
    }
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        try {
            $this->conn->beginTransaction();
            
            // Get pet_id for this record
            $get_pet_query = "SELECT pet_id FROM sterilization_records WHERE id = :id";
            $get_pet_stmt = $this->conn->prepare($get_pet_query);
            $get_pet_stmt->bindParam(':id', $id);
            $get_pet_stmt->execute();
            $pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pet_data) {
                throw new Exception('Sterilization record not found');
            }
            
            $update_fields = [];
            $params = [':id' => $id];
            
            $allowed_fields = ['sterilization_date', 'procedure_type', 'veterinarian_name', 'weight'];
            
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
            
            $query = "UPDATE sterilization_records SET " . implode(', ', $update_fields) . 
                    ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update sterilization record');
            }
            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sterilization record updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Sterilization record update failed: ' . $e->getMessage()]);
        }
    }
    
    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);
        
        try {
            $this->conn->beginTransaction();
            
            // Get pet_id before deleting
            $get_pet_query = "SELECT sr.pet_id, u.role FROM sterilization_records sr JOIN users u ON sr.administered_by = u.id WHERE sr.id = :id";
$get_pet_stmt = $this->conn->prepare($get_pet_query);
$get_pet_stmt->bindParam(':id', $id);
$get_pet_stmt->execute();
$pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet_data) {
    throw new Exception('Sterilization record not found');
}

if ($pet_data['role'] === 'private_clinic') {
    $this->conn->rollBack();
    http_response_code(403);
    echo json_encode(['error' => 'Cannot delete records created by a private clinic']);
    return;
}
            
            // Delete sterilization record
            $query = "DELETE FROM sterilization_records WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete sterilization record');
            }
            
            // Update pet table - mark as not sterilized
            $update_pet = "UPDATE pets SET sterilized = 0, 
                          sterilized_by = NULL,
                          sterilization_date = NULL
                          WHERE id = :pet_id";
            $update_stmt = $this->conn->prepare($update_pet);
            $update_stmt->bindParam(':pet_id', $pet_data['pet_id']);
            $update_stmt->execute();
            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Sterilization record deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Sterilization record deletion failed: ' . $e->getMessage()]);
        }
    }
}
?>