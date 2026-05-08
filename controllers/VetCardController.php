<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class VetCardController {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    /**
     * Helper method to fetch deworming records for a pet
     */
    private function getDewormingRecords($pet_id) {
        $query = "SELECT dr.*,
                 COALESCE(ci.item_name, dt.name) as deworming_type_name,
                 COALESCE(dt.description, ci.notes) as deworming_type_description,
                 u.first_name as admin_first_name, u.last_name as admin_last_name
                 FROM deworming_records dr
                 LEFT JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                 LEFT JOIN clinic_inventory ci ON dr.clinic_item_id = ci.id
                 LEFT JOIN users u ON dr.administered_by = u.id
                 WHERE dr.pet_id = :pet_id
                 ORDER BY dr.deworming_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pet_id', $pet_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    private function getVaccinationRecords($pet_id) {
        $query = "SELECT vr.*,
                 COALESCE(ci.item_name, vt.name, inv.item_name) as vaccine_name,
                 COALESCE(vt.description, ci.notes, inv.notes) as vaccine_description,
                 CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                 FROM vaccination_records vr
                 LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                 LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                 LEFT JOIN inventory inv ON inv.id = vr.inventory_id
                 LEFT JOIN users u ON vr.administered_by = u.id
                 WHERE vr.pet_id = :pet_id
                 ORDER BY vr.vaccination_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pet_id', $pet_id);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function index() {
        $user_data = $this->auth->authenticate();
        
        try {
            if ($user_data['role'] === 'pet_owner') {
                $query = "SELECT vc.*, p.name as pet_name, p.registration_number, p.species, p.breed,
                         po.first_name as owner_first_name, po.last_name as owner_last_name,
                         u.first_name as issued_by_first_name, u.last_name as issued_by_last_name,
                         b.name as barangay_name,
                         DATEDIFF(vc.expiry_date, CURDATE()) as days_until_expiry
                         FROM vet_cards vc
                         JOIN pets p ON vc.pet_id = p.id
                         JOIN pet_owners po ON p.owner_id = po.id
                         JOIN users u ON vc.issued_by = u.id
                         LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                         WHERE po.user_id = :user_id AND vc.is_active = 1
                         ORDER BY vc.issue_date DESC";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
            } else if ($user_data['role'] === 'barangay_official') {
                $query = "SELECT vc.*, p.name as pet_name, p.registration_number, p.species, p.breed,
                         po.first_name as owner_first_name, po.last_name as owner_last_name,
                         u.first_name as issued_by_first_name, u.last_name as issued_by_last_name,
                         DATEDIFF(vc.expiry_date, CURDATE()) as days_until_expiry
                         FROM vet_cards vc
                         JOIN pets p ON vc.pet_id = p.id
                         JOIN pet_owners po ON p.owner_id = po.id
                         LEFT JOIN users owner_u ON po.user_id = owner_u.id
                         JOIN users u ON vc.issued_by = u.id
                         WHERE vc.is_active = 1
                         ORDER BY vc.issue_date DESC";
                
                $stmt = $this->conn->prepare($query);
            } else {
                $query = "SELECT vc.*, p.name as pet_name, p.registration_number, p.species, p.breed,
                         po.first_name as owner_first_name, po.last_name as owner_last_name,
                         u.first_name as issued_by_first_name, u.last_name as issued_by_last_name,
                         b.name as barangay_name,
                         DATEDIFF(vc.expiry_date, CURDATE()) as days_until_expiry
                         FROM vet_cards vc
                         JOIN pets p ON vc.pet_id = p.id
                         JOIN pet_owners po ON p.owner_id = po.id
                         LEFT JOIN users owner_u ON po.user_id = owner_u.id
                         LEFT JOIN barangays b ON owner_u.assigned_barangay_id = b.id
                         JOIN users u ON vc.issued_by = u.id
                         WHERE vc.is_active = 1
                         ORDER BY vc.issue_date DESC";
                
                $stmt = $this->conn->prepare($query);
            }
            
            $stmt->execute();
            $vet_cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add deworming records to each vet card
            foreach ($vet_cards as &$card) {
                if ($card['vaccination_records']) {
                    $card['vaccination_records'] = json_decode($card['vaccination_records'], true);
                }
                
                // Fetch deworming records for this pet
                $card['deworming_records'] = $this->getDewormingRecords($card['pet_id']);
            }
            
            echo json_encode([
                'success' => true,
                'vet_cards' => $vet_cards,
                'total' => count($vet_cards)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get vet cards: ' . $e->getMessage()
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
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        $required_fields = ['pet_id'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }
        
        // Use the issued_by from request or default to authenticated user
        $issued_by = isset($data->issued_by) ? $data->issued_by : $user_data['user_id'];
        
        try {
            // FIXED: Check if vet card already exists FIRST
            $check_existing_query = "SELECT vc.*, p.name as pet_name 
                                    FROM vet_cards vc
                                    JOIN pets p ON vc.pet_id = p.id
                                    WHERE vc.pet_id = :pet_id AND vc.is_active = 1";
            $check_existing_stmt = $this->conn->prepare($check_existing_query);
            $check_existing_stmt->bindParam(':pet_id', $data->pet_id);
            $check_existing_stmt->execute();
            
            if ($check_existing_stmt->rowCount() > 0) {
                $existing = $check_existing_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Add deworming records
                $existing['deworming_records'] = $this->getDewormingRecords($data->pet_id);
                
                // Return success with existing card info instead of error
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Vet card already exists for ' . $existing['pet_name'],
                    'existing' => true,
                    'vet_card' => $existing
                ]);
                return;
            }
            
            // Create vet card using the helper method
            $result = $this->createVetCardInternal($data->pet_id, $issued_by);
            
            if ($result['success']) {
                // Add deworming records to the newly created card
                if (isset($result['card'])) {
                    $result['card']['deworming_records'] = $this->getDewormingRecords($data->pet_id);
                }
                
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Vet card issued successfully',
                    'vet_card' => $result['card']
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result['message']
                ]);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Vet card creation failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Internal method to create vet card WITHOUT authentication
     * Used by VaccinationController for automatic creation
     */
    public function createVetCardInternal($pet_id, $issued_by) {
        try {
            // Check if pet exists and is active
            $check_pet_query = "SELECT p.*, po.user_id, u.assigned_barangay_id
                               FROM pets p
                               JOIN pet_owners po ON p.owner_id = po.id
                               JOIN users u ON po.user_id = u.id
                               WHERE p.id = :pet_id AND p.is_active = 1";
            
            $check_pet_stmt = $this->conn->prepare($check_pet_query);
            $check_pet_stmt->bindParam(':pet_id', $pet_id);
            $check_pet_stmt->execute();
            
            if ($check_pet_stmt->rowCount() === 0) {
                return [
                    'success' => false,
                    'message' => 'Pet not found or inactive'
                ];
            }
            
            $pet = $check_pet_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if active vet card already exists
            $check_existing_query = "SELECT id, card_number FROM vet_cards WHERE pet_id = :pet_id AND is_active = 1";
            $check_existing_stmt = $this->conn->prepare($check_existing_query);
            $check_existing_stmt->bindParam(':pet_id', $pet_id);
            $check_existing_stmt->execute();
            
            if ($check_existing_stmt->rowCount() > 0) {
                $existing = $check_existing_stmt->fetch(PDO::FETCH_ASSOC);
                return [
                    'success' => true, // Changed to true since card exists
                    'message' => 'Active vet card already exists',
                    'existing' => true,
                    'existing_card_number' => $existing['card_number']
                ];
            }
            
            // Generate card number
            $year = date('Y');
            $month = date('m');
            
            $countQuery = "SELECT COUNT(*) as count FROM vet_cards 
                          WHERE YEAR(issue_date) = :year AND MONTH(issue_date) = :month";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bindParam(':year', $year);
            $countStmt->bindParam(':month', $month);
            $countStmt->execute();
            $result = $countStmt->fetch(PDO::FETCH_ASSOC);
            
            $count = $result['count'] + 1;
            $sequence = str_pad($count, 4, '0', STR_PAD_LEFT);
            $card_number = "VET-{$year}{$month}-{$sequence}";
            
            // Check for duplicate card number
            $checkDuplicate = "SELECT id FROM vet_cards WHERE card_number = :card_number";
            $checkStmt = $this->conn->prepare($checkDuplicate);
            $checkStmt->bindParam(':card_number', $card_number);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                $card_number .= '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
            }
            
            $issue_date = date('Y-m-d');
            $expiry_date = date('Y-m-d', strtotime('+1 year'));
            
            // Insert vet card
            $query = "INSERT INTO vet_cards 
                     (pet_id, card_number, issue_date, expiry_date, issued_by, 
                      is_active, health_status, last_updated_by, created_at, updated_at) 
                     VALUES 
                     (:pet_id, :card_number, :issue_date, :expiry_date, :issued_by, 
                      1, 'healthy', :last_updated_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->bindParam(':card_number', $card_number);
            $stmt->bindParam(':issue_date', $issue_date);
            $stmt->bindParam(':expiry_date', $expiry_date);
            $stmt->bindParam(':issued_by', $issued_by);
            $stmt->bindParam(':last_updated_by', $issued_by);
            
            if ($stmt->execute()) {
                $card_id = $this->conn->lastInsertId();
                
                // Fetch the created card with details
                $get_card_query = "SELECT vc.*, p.name as pet_name, p.registration_number
                                  FROM vet_cards vc
                                  JOIN pets p ON vc.pet_id = p.id
                                  WHERE vc.id = :card_id";
                
                $get_card_stmt = $this->conn->prepare($get_card_query);
                $get_card_stmt->bindParam(':card_id', $card_id);
                $get_card_stmt->execute();
                $card = $get_card_stmt->fetch(PDO::FETCH_ASSOC);
                
                return [
                    'success' => true,
                    'message' => 'Vet card created successfully',
                    'card_number' => $card_number,
                    'card_id' => $card_id,
                    'card' => $card
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to execute insert statement'
                ];
            }
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }
    
    public function show($id) {
        $user_data = $this->auth->authenticate();
        
        try {
            $query = "SELECT vc.*, p.name as pet_name, p.registration_number, p.species, p.breed, p.color,
                     p.birth_date, p.gender, p.weight, p.microchip_number,
                     po.first_name as owner_first_name, po.last_name as owner_last_name,
                     owner_u.phone as owner_phone, owner_u.address as owner_address, owner_u.email as owner_email,
                     issued_u.first_name as issued_by_first_name, issued_u.last_name as issued_by_last_name,
                     b.name as barangay_name,
                     DATEDIFF(vc.expiry_date, CURDATE()) as days_until_expiry
                     FROM vet_cards vc
                     JOIN pets p ON vc.pet_id = p.id
                     JOIN pet_owners po ON p.owner_id = po.id
                     JOIN users owner_u ON po.user_id = owner_u.id
                     LEFT JOIN barangays b ON owner_u.assigned_barangay_id = b.id
                     JOIN users issued_u ON vc.issued_by = issued_u.id
                     WHERE vc.id = :card_id AND vc.is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':card_id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $card = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Always fetch live vaccination records instead of relying on stored JSON
                $card['vaccination_records'] = $this->getVaccinationRecords($card['pet_id']);
                
                // Add deworming records
                $card['deworming_records'] = $this->getDewormingRecords($card['pet_id']);
                
                echo json_encode([
                    'success' => true,
                    'vet_card' => $card
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Vet card not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get vet card: ' . $e->getMessage()]);
        }
    }
    
    public function pet($pet_id) {
    try {
        // Auto-deactivate vet card if pet has no vaccination AND no deworming records
        $count_query = "SELECT 
            (SELECT COUNT(*) FROM vaccination_records WHERE pet_id = :pet_id1) +
            (SELECT COUNT(*) FROM deworming_records WHERE pet_id = :pet_id2) as total_records";
        $count_stmt = $this->conn->prepare($count_query);
        $count_stmt->bindParam(':pet_id1', $pet_id);
        $count_stmt->bindParam(':pet_id2', $pet_id);
        $count_stmt->execute();
        $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);

        if ((int)$count_row['total_records'] === 0) {
            $deactivate = $this->conn->prepare(
                "UPDATE vet_cards SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE pet_id = :pet_id AND is_active = 1"
            );
            $deactivate->bindParam(':pet_id', $pet_id);
            $deactivate->execute();

            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'No vet card found for this pet'
            ]);
            return;
        }

        $query = "SELECT vc.*, p.name as pet_name, p.registration_number
                 FROM vet_cards vc
                 JOIN pets p ON vc.pet_id = p.id
                 WHERE vc.pet_id = :pet_id AND vc.is_active = 1
                 ORDER BY vc.created_at DESC
                 LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $card = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Always fetch live vaccination records instead of relying on stored JSON
                $card['vaccination_records'] = $this->getVaccinationRecords($pet_id);
                
                // Add deworming records
                $card['deworming_records'] = $this->getDewormingRecords($pet_id);
                
                echo json_encode([
                    'success' => true,
                    'vet_card' => $card
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'No vet card found for this pet'
                ]);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
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
            $update_fields = [];
            $params = [':card_id' => $id];
            
            $allowed_fields = ['health_status', 'special_conditions'];
            
            foreach ($allowed_fields as $field) {
                if (isset($data->$field)) {
                    $update_fields[] = "$field = :$field";
                    $params[":$field"] = $data->$field;
                }
            }
            
            if (isset($data->extend_expiry) && $data->extend_expiry === true) {
                $update_fields[] = "expiry_date = DATE_ADD(expiry_date, INTERVAL 1 YEAR)";
            }
            
            if (empty($update_fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }
            
            $update_fields[] = "last_updated_by = :updated_by";
            $params[":updated_by"] = $user_data['user_id'];
            
            $query = "UPDATE vet_cards SET " . implode(', ', $update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :card_id";
            
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute($params)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Vet card updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Vet card update failed']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Vet card update failed: ' . $e->getMessage()]);
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
            $query = "UPDATE vet_cards SET is_active = 0, last_updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :card_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':card_id', $id);
            $stmt->bindParam(':updated_by', $user_data['user_id']);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Vet card deactivated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Vet card deactivation failed']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Vet card deactivation failed: ' . $e->getMessage()]);
        }
    }
}
?>