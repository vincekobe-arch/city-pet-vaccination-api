<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';
require_once __DIR__ . '/../services/ReminderService.php';  // ← ADD THIS LINE


class DewormingController {
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
            $base_query = "SELECT dr.*, p.name as pet_name, p.registration_number, p.species,
                     COALESCE(ci.item_name, dt.name) as deworming_name,
                     COALESCE(dt.description, ci.notes) as deworming_description,
                     dt.target_parasites,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name,
                     CONCAT(po.first_name, ' ', po.last_name) as owner_name,
                     CASE WHEN usr.role = 'private_clinic' THEN pc.clinic_name
                          ELSE 'City Vet Muntinlupa' END as recorded_by
                     FROM deworming_records dr
                     JOIN pets p ON dr.pet_id = p.id
                     LEFT JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                     LEFT JOIN clinic_inventory ci ON dr.clinic_item_id = ci.id
                     JOIN users u ON dr.administered_by = u.id
                     JOIN users usr ON dr.administered_by = usr.id
                     LEFT JOIN private_clinics pc ON usr.id = pc.user_id
                     JOIN pet_owners po ON p.owner_id = po.id
                     WHERE p.is_active = 1";

            if ($user_data['role'] === 'private_clinic') {
                $base_query .= " AND dr.administered_by = :user_id";
            }
            // super_admin and barangay_official see ALL records

            $base_query .= " ORDER BY dr.deworming_date DESC";

            $stmt = $this->conn->prepare($base_query);
            if ($user_data['role'] === 'private_clinic') {
                $stmt->bindParam(':user_id', $user_data['user_id']);
            }
            $stmt->execute();
            
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'deworming_records' => $records,
                'total' => count($records)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get deworming records: ' . $e->getMessage()
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
        
        $required_fields = ['pet_id', 'deworming_date'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

        // Either deworming_type_id (city vet) or clinic_item_id (private clinic) is required
        $has_type = (!empty($data->deworming_type_id)) || (!empty($data->clinic_item_id));
        if (!$has_type) {
            http_response_code(400);
            echo json_encode(['error' => 'Deworming type or clinic inventory item is required']);
            return;
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Check for duplicate deworming
            $duplicate_check = "SELECT id, deworming_date, next_due_date, 
                               DATEDIFF(next_due_date, CURDATE()) as days_until_due
                               FROM deworming_records 
                               WHERE pet_id = :pet_id 
                               AND deworming_type_id = :deworming_type_id 
                               AND next_due_date IS NOT NULL 
                               AND next_due_date >= CURDATE()
                               ORDER BY next_due_date DESC
                               LIMIT 1";
            
            $clinic_item_id_check = isset($data->clinic_item_id) && !empty($data->clinic_item_id) ? $data->clinic_item_id : null;
            if ($clinic_item_id_check) {
                $duplicate_check = "SELECT id, deworming_date, next_due_date,
                                   DATEDIFF(next_due_date, CURDATE()) as days_until_due
                                   FROM deworming_records
                                   WHERE pet_id = :pet_id
                                   AND clinic_item_id = :clinic_item_id
                                   AND next_due_date IS NOT NULL
                                   AND next_due_date >= CURDATE()
                                   ORDER BY next_due_date DESC
                                   LIMIT 1";
                $check_stmt = $this->conn->prepare($duplicate_check);
                $check_stmt->bindParam(':pet_id', $data->pet_id);
                $check_stmt->bindParam(':clinic_item_id', $clinic_item_id_check);
            } else {
                $check_stmt = $this->conn->prepare($duplicate_check);
                $check_stmt->bindParam(':pet_id', $data->pet_id);
                $check_stmt->bindParam(':deworming_type_id', $data->deworming_type_id);
            }
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                $this->conn->rollBack();
                http_response_code(409);
                echo json_encode([
                    'error' => 'Duplicate deworming detected',
                    'message' => 'This pet already has an active deworming record for this type with a future due date.',
                    'details' => [
                        'last_deworming_date' => $existing['deworming_date'],
                        'next_due_date' => $existing['next_due_date'],
                        'days_until_due' => $existing['days_until_due']
                    ]
                ]);
                return;
            }
            
            // Insert deworming record
            $query = "INSERT INTO deworming_records 
         (pet_id, deworming_type_id, clinic_item_id, batch_id, deworming_date, next_due_date, 
          veterinarian_name, weight, dosage, product_name, batch_number, 
          notes, side_effects, administered_by) 
         VALUES 
         (:pet_id, :deworming_type_id, :clinic_item_id, :batch_id, :deworming_date, :next_due_date,
          :veterinarian_name, :weight, :dosage, :product_name, :batch_number,
          :notes, :side_effects, :administered_by)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $data->pet_id);
            $deworming_type_id = isset($data->deworming_type_id) && !empty($data->deworming_type_id) ? $data->deworming_type_id : null;
$clinic_item_id = isset($data->clinic_item_id) && !empty($data->clinic_item_id) ? $data->clinic_item_id : null;
$deworming_batch_id_val = isset($data->batch_id) && !empty($data->batch_id) ? intval($data->batch_id) : null;
$stmt->bindParam(':deworming_type_id', $deworming_type_id);
$stmt->bindParam(':clinic_item_id', $clinic_item_id);
$stmt->bindParam(':batch_id', $deworming_batch_id_val, PDO::PARAM_INT);
            $stmt->bindParam(':deworming_date', $data->deworming_date);
            
            $next_due_date = isset($data->next_due_date) && !empty($data->next_due_date) ? $data->next_due_date : null;
            $stmt->bindParam(':next_due_date', $next_due_date);
            
            $veterinarian_name = isset($data->veterinarian_name) ? $data->veterinarian_name : null;
            $stmt->bindParam(':veterinarian_name', $veterinarian_name);
            
            $weight = isset($data->weight) ? $data->weight : null;
            $stmt->bindParam(':weight', $weight);
            
            $dosage = isset($data->dosage) ? $data->dosage : null;
            $stmt->bindParam(':dosage', $dosage);
            
            $product_name = isset($data->product_name) ? $data->product_name : null;
            $stmt->bindParam(':product_name', $product_name);
            
            $batch_number = isset($data->batch_number) ? $data->batch_number : null;
            $stmt->bindParam(':batch_number', $batch_number);
            
            $notes = isset($data->notes) ? $data->notes : null;
            $stmt->bindParam(':notes', $notes);
            
            $side_effects = isset($data->side_effects) ? $data->side_effects : null;
            $stmt->bindParam(':side_effects', $side_effects);
            
            $stmt->bindParam(':administered_by', $user_data['user_id']);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create deworming record');
            }
            
            $deworming_id = $this->conn->lastInsertId();
            
            // ✅ SEND IMMEDIATE CONFIRMATION EMAIL
            try {
                $reminderService = new ReminderService();
                $confirmation_result = $reminderService->sendDewormingConfirmation($data->pet_id, $deworming_id);
                
                if ($confirmation_result['success']) {
                    error_log("DewormingController: Confirmation email sent for deworming ID $deworming_id");
                } else {
                    error_log("DewormingController: Failed to send confirmation - " . $confirmation_result['message']);
                }
            } catch (Exception $e) {
                error_log("DewormingController: Error sending confirmation email - " . $e->getMessage());
                // Don't fail the whole transaction if email fails
            }
            
            // Create vet card if doesn't exist
            $check_vet_card = "SELECT id FROM vet_cards WHERE pet_id = :pet_id AND is_active = 1 LIMIT 1";
            $check_stmt = $this->conn->prepare($check_vet_card);
            $check_stmt->bindParam(':pet_id', $data->pet_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                require_once 'controllers/VetCardController.php';
                $vetCardController = new VetCardController();
                $result = $vetCardController->createVetCardInternal($data->pet_id, $user_data['user_id']);
                
                if (!$result['success']) {
                    throw new Exception('Failed to create vet card: ' . $result['message']);
                }
            }
            
            // Update vet card records
            $this->updateVetCardRecords($data->pet_id, $user_data['user_id']);
            
            $this->conn->commit();
            
            // Fetch the created deworming with details
            $get_deworming_query = "SELECT dr.*, dt.name as deworming_name, 
                                    dt.description as deworming_description,
                                    dt.target_parasites,
                                    CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                                    FROM deworming_records dr
                                    JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                                    JOIN users u ON dr.administered_by = u.id
                                    WHERE dr.id = :deworming_id";
            
            $get_stmt = $this->conn->prepare($get_deworming_query);
            $get_stmt->bindParam(':deworming_id', $deworming_id);
            $get_stmt->execute();
            $deworming = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Deworming record created successfully',
                'deworming_record' => $deworming
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Deworming record creation failed: ' . $e->getMessage()]);
        }
    }
    
    private function updateVetCardRecords($pet_id, $updated_by) {
        try {
            // Count both vaccination and deworming records
            $count_stmt = $this->conn->prepare(
                "SELECT 
                    (SELECT COUNT(*) FROM vaccination_records WHERE pet_id = :pet_id1) +
                    (SELECT COUNT(*) FROM deworming_records WHERE pet_id = :pet_id2) as total_records"
            );
            $count_stmt->bindParam(':pet_id1', $pet_id);
            $count_stmt->bindParam(':pet_id2', $pet_id);
            $count_stmt->execute();
            $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);

            // If no records at all, deactivate the vet card
            if ((int)$count_row['total_records'] === 0) {
                $deactivate = $this->conn->prepare(
                    "UPDATE vet_cards SET is_active = 0, last_updated_by = :updated_by,
                     updated_at = CURRENT_TIMESTAMP WHERE pet_id = :pet_id AND is_active = 1"
                );
                $deactivate->bindParam(':updated_by', $updated_by);
                $deactivate->bindParam(':pet_id', $pet_id);
                $deactivate->execute();
                return true;
            }

            $update_query = "UPDATE vet_cards 
                            SET last_updated_by = :updated_by,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE pet_id = :pet_id AND is_active = 1";
            
            $update_stmt = $this->conn->prepare($update_query);
            $update_stmt->bindParam(':updated_by', $updated_by);
            $update_stmt->bindParam(':pet_id', $pet_id);
            
            return $update_stmt->execute();
            
        } catch (Exception $e) {
            error_log("Failed to update vet card records: " . $e->getMessage());
            return false;
        }
    }
    
    public function getTypes() {
        try {
            $query = "SELECT * FROM deworming_types WHERE is_active = 1 ORDER BY name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'deworming_types' => $types,
                'total' => count($types)
            ]);
            
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get deworming types: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    public function getStatistics() {
        $user_data = $this->auth->authenticate();
        
        try {
            // Total dewormings
            $total_query = "SELECT COUNT(*) as total FROM deworming_records";
            $total_stmt = $this->conn->prepare($total_query);
            $total_stmt->execute();
            $total_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Dewormings this month
            $month_query = "SELECT COUNT(*) as total 
                           FROM deworming_records 
                           WHERE MONTH(deworming_date) = MONTH(CURRENT_DATE()) 
                           AND YEAR(deworming_date) = YEAR(CURRENT_DATE())";
            $month_stmt = $this->conn->prepare($month_query);
            $month_stmt->execute();
            $month_result = $month_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'statistics' => [
                    'total_dewormings' => $total_result['total'],
                    'this_month' => $month_result['total']
                ]
            ]);
            
            exit;
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get statistics: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    

    
    public function getByPetId($pet_id) {
        try {
            $query = "SELECT dr.*, dt.name as deworming_name, 
                     dt.description as deworming_description,
                     dt.target_parasites,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM deworming_records dr
                     JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                     JOIN users u ON dr.administered_by = u.id
                     WHERE dr.pet_id = :pet_id
                     ORDER BY dr.deworming_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'deworming_records' => $records
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get deworming records: ' . $e->getMessage()]);
        }
    }
    
    public function getDue() {
        $user_data = $this->auth->authenticate();
        
        try {
            $today = date('Y-m-d');
            $thirty_days = date('Y-m-d', strtotime('+30 days'));
            
            // Base query
            $query = "SELECT dr.*, p.name as pet_name, p.registration_number,
                     dt.name as deworming_name,
                     DATEDIFF(dr.next_due_date, CURDATE()) as days_until_due,
                     po.user_id as owner_user_id
                     FROM deworming_records dr
                     JOIN pets p ON dr.pet_id = p.id
                     JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                     JOIN pet_owners po ON p.owner_id = po.id
                     WHERE dr.next_due_date IS NOT NULL 
                     AND dr.next_due_date BETWEEN :today AND :thirty_days
                     AND p.is_active = 1";
            
            // Filter for pet owners - only show their own pets
            if ($user_data['role'] === 'pet_owner') {
                $query .= " AND po.user_id = :user_id";
            }
            // Barangay officials can see pets in their barangay
            elseif ($user_data['role'] === 'barangay_official') {
                $query .= " AND EXISTS (
                    SELECT 1 FROM users u 
                    WHERE u.id = po.user_id 
                    AND u.assigned_barangay_id = :barangay_id
                )";
            }
            // Super admin sees all
            
            $query .= " ORDER BY dr.next_due_date ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':today', $today);
            $stmt->bindParam(':thirty_days', $thirty_days);
            
            // Bind user-specific parameters
            if ($user_data['role'] === 'pet_owner') {
                $stmt->bindParam(':user_id', $user_data['user_id']);
            } elseif ($user_data['role'] === 'barangay_official') {
                $stmt->bindParam(':barangay_id', $user_data['user_details']['assigned_barangay_id']);
            }
            
            $stmt->execute();
            
            $due_dewormings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'due_dewormings' => $due_dewormings,
                'total' => count($due_dewormings)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get due dewormings: ' . $e->getMessage()]);
        }
    }
    
    public function show($id) {
        $user_data = $this->auth->authenticate();
        
        try {
            $query = "SELECT dr.*, p.name as pet_name, dt.name as deworming_name,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM deworming_records dr
                     JOIN pets p ON dr.pet_id = p.id
                     JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                     JOIN users u ON dr.administered_by = u.id
                     WHERE dr.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success' => true,
                    'deworming_record' => $record
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Deworming record not found']);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get deworming record: ' . $e->getMessage()]);
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
            
            // Get pet_id for this deworming
            $get_pet_query = "SELECT pet_id FROM deworming_records WHERE id = :id";
            $get_pet_stmt = $this->conn->prepare($get_pet_query);
            $get_pet_stmt->bindParam(':id', $id);
            $get_pet_stmt->execute();
            $pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pet_data) {
                throw new Exception('Deworming record not found');
            }
            
            $update_fields = [];
            $params = [':id' => $id];
            
            $allowed_fields = ['deworming_type_id', 'deworming_date', 'next_due_date', 
                             'veterinarian_name', 'weight', 'dosage', 'product_name', 
                             'batch_number', 'notes', 'side_effects'];
            
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
            
            $query = "UPDATE deworming_records SET " . implode(', ', $update_fields) . 
                    ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update deworming record');
            }
            
            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Deworming record updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Deworming record update failed: ' . $e->getMessage()]);
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
            $get_pet_query = "SELECT dr.pet_id, u.role FROM deworming_records dr JOIN users u ON dr.administered_by = u.id WHERE dr.id = :id";
$get_pet_stmt = $this->conn->prepare($get_pet_query);
$get_pet_stmt->bindParam(':id', $id);
$get_pet_stmt->execute();
$pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet_data) {
    throw new Exception('Deworming record not found');
}

if ($pet_data['role'] === 'private_clinic') {
    $this->conn->rollBack();
    http_response_code(403);
    echo json_encode(['error' => 'Cannot delete records created by a private clinic']);
    return;
}
            
            // Get batch_id before deleting so we can restore stock
            $get_dew_batch_stmt = $this->conn->prepare(
                "SELECT batch_id FROM deworming_records WHERE id = :id"
            );
            $get_dew_batch_stmt->bindParam(':id', $id);
            $get_dew_batch_stmt->execute();
            $dew_batch_row = $get_dew_batch_stmt->fetch(PDO::FETCH_ASSOC);
            $dew_batch_id_to_restore = $dew_batch_row ? $dew_batch_row['batch_id'] : null;

            // Delete deworming record
            $query = "DELETE FROM deworming_records WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete deworming record');
            }

            // Restore 1 unit to clinic batch — triggers auto-update clinic_inventory.current_stock
            if ($dew_batch_id_to_restore) {
                $restore_dew_stmt = $this->conn->prepare(
                    "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                     updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                );
                $restore_dew_stmt->execute([':batch_id' => $dew_batch_id_to_restore]);
            }
            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Deworming record deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Deworming record deletion failed: ' . $e->getMessage()]);
        }
    }
}
?>