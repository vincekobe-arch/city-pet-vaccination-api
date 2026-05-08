<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';
require_once __DIR__ . '/../services/ReminderService.php';


class VaccinationController {
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
            $base_query = "SELECT vr.*, p.name as pet_name, p.registration_number, p.species,
                     COALESCE(ci.item_name, vt.name, inv.item_name) as vaccine_name,
                     COALESCE(vt.description, ci.notes, inv.notes) as vaccine_description,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name,
                     CONCAT(po.first_name, ' ', po.last_name) as owner_name,
                     CASE WHEN usr.role = 'private_clinic' THEN pc.clinic_name
                          ELSE 'City Vet Muntinlupa' END as recorded_by
                     FROM vaccination_records vr
                     JOIN pets p ON vr.pet_id = p.id
                     LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                     LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                     LEFT JOIN inventory inv ON inv.id = vr.inventory_id
                     JOIN users u ON vr.administered_by = u.id
                     JOIN users usr ON vr.administered_by = usr.id
                     LEFT JOIN private_clinics pc ON usr.id = pc.user_id
                     JOIN pet_owners po ON p.owner_id = po.id
                     WHERE p.is_active = 1";

            if ($user_data['role'] === 'private_clinic') {
                $base_query .= " AND vr.administered_by = :user_id";
            }
            // super_admin and barangay_official see ALL records

            $base_query .= " ORDER BY vr.vaccination_date DESC";

            $stmt = $this->conn->prepare($base_query);
            if ($user_data['role'] === 'private_clinic') {
                $stmt->bindParam(':user_id', $user_data['user_id']);
            }
            $stmt->execute();
            
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'vaccination_records' => $records,
                'total' => count($records)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get vaccination records: ' . $e->getMessage()
            ]);
        }
    }
    
    
// In VaccinationController.php - Replace the create() method

public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin', 'private_clinic'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        $required_fields = ['pet_id', 'vaccination_date'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

        // Accept vaccination_type_id (from vaccination_types), inventory_id (admin using inventory directly), or clinic_item_id (private clinic)
        $has_type = (!empty($data->vaccination_type_id)) || (!empty($data->clinic_item_id)) || (!empty($data->inventory_id));
        if (!$has_type) {
            http_response_code(400);
            echo json_encode(['error' => 'Vaccination type or inventory item is required']);
            return;
        }
        
        try {
            $this->conn->beginTransaction();
            
            // Check for duplicate vaccination
            $clinic_item_id_check = isset($data->clinic_item_id) && !empty($data->clinic_item_id) ? $data->clinic_item_id : null;
            $inventory_id_check   = isset($data->inventory_id)   && !empty($data->inventory_id)   ? intval($data->inventory_id) : null;

            if ($clinic_item_id_check) {
                $duplicate_check = "SELECT id, vaccination_date, next_due_date,
                                   DATEDIFF(next_due_date, CURDATE()) as days_until_due
                                   FROM vaccination_records
                                   WHERE pet_id = :pet_id
                                   AND clinic_item_id = :clinic_item_id
                                   AND next_due_date IS NOT NULL
                                   AND next_due_date >= CURDATE()
                                   ORDER BY next_due_date DESC
                                   LIMIT 1";
                $check_stmt = $this->conn->prepare($duplicate_check);
                $check_stmt->bindParam(':pet_id', $data->pet_id);
                $check_stmt->bindParam(':clinic_item_id', $clinic_item_id_check);
            } elseif ($inventory_id_check) {
                // Admin using inventory item directly
                $duplicate_check = "SELECT id, vaccination_date, next_due_date,
                                   DATEDIFF(next_due_date, CURDATE()) as days_until_due
                                   FROM vaccination_records
                                   WHERE pet_id = :pet_id
                                   AND inventory_id = :inventory_id
                                   AND next_due_date IS NOT NULL
                                   AND next_due_date >= CURDATE()
                                   ORDER BY next_due_date DESC
                                   LIMIT 1";
                $check_stmt = $this->conn->prepare($duplicate_check);
                $check_stmt->bindParam(':pet_id', $data->pet_id);
                $check_stmt->bindParam(':inventory_id', $inventory_id_check, PDO::PARAM_INT);
            } else {
                $duplicate_check = "SELECT id, vaccination_date, next_due_date,
                                   DATEDIFF(next_due_date, CURDATE()) as days_until_due
                                   FROM vaccination_records
                                   WHERE pet_id = :pet_id
                                   AND vaccination_type_id = :vaccination_type_id
                                   AND next_due_date IS NOT NULL
                                   AND next_due_date >= CURDATE()
                                   ORDER BY next_due_date DESC
                                   LIMIT 1";
                $check_stmt = $this->conn->prepare($duplicate_check);
                $check_stmt->bindParam(':pet_id', $data->pet_id);
                $check_stmt->bindParam(':vaccination_type_id', $data->vaccination_type_id);
            }
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                $this->conn->rollBack();
                http_response_code(409);
                echo json_encode([
                    'error' => 'Duplicate vaccination detected',
                    'message' => 'This pet already has an active vaccination record for this vaccine type with a future due date.',
                    'details' => [
                        'last_vaccination_date' => $existing['vaccination_date'],
                        'next_due_date' => $existing['next_due_date'],
                        'days_until_due' => $existing['days_until_due']
                    ]
                ]);
                return;
            }
            
            // Insert vaccination record
            $query = "INSERT INTO vaccination_records 
         (pet_id, vaccination_type_id, clinic_item_id, inventory_id, batch_id, clinic_batch_id, vaccination_date, next_due_date, 
          veterinarian_name, weight, batch_number, notes, administered_by) 
         VALUES 
         (:pet_id, :vaccination_type_id, :clinic_item_id, :inventory_id, :batch_id, :clinic_batch_id, :vaccination_date, :next_due_date,
          :veterinarian_name, :weight, :batch_number, :notes, :administered_by)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $data->pet_id);

            // vaccination_type_id: from vaccination_types table; inventory_id maps to it via inventory table
            $vaccination_type_id = null;
            if (!empty($data->vaccination_type_id)) {
                $vaccination_type_id = $data->vaccination_type_id;
            } elseif (!empty($data->inventory_id)) {
                // Resolve inventory item to vaccination_type_id if linked, else leave null
                $inv_stmt = $this->conn->prepare("SELECT id FROM vaccination_types WHERE id = (SELECT item_type_id FROM inventory WHERE id = :inv_id LIMIT 1) LIMIT 1");
                $inv_stmt->execute([':inv_id' => intval($data->inventory_id)]);
                $inv_row = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                $vaccination_type_id = $inv_row ? $inv_row['id'] : null;
            }
            $clinic_item_id = isset($data->clinic_item_id) && !empty($data->clinic_item_id) ? $data->clinic_item_id : null;
            $inventory_id = isset($data->inventory_id) && !empty($data->inventory_id) ? intval($data->inventory_id) : null;
$batch_id_val = isset($data->batch_id) && !empty($data->batch_id) ? intval($data->batch_id) : null;
$clinic_batch_id_val = isset($data->clinic_batch_id) && !empty($data->clinic_batch_id) ? intval($data->clinic_batch_id) : null;
$stmt->bindParam(':vaccination_type_id', $vaccination_type_id);
$stmt->bindParam(':clinic_item_id', $clinic_item_id);
$stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
$stmt->bindParam(':batch_id', $batch_id_val, PDO::PARAM_INT);
$stmt->bindParam(':clinic_batch_id', $clinic_batch_id_val, PDO::PARAM_INT);
            $stmt->bindParam(':vaccination_date', $data->vaccination_date);
            
            $next_due_date = isset($data->next_due_date) && !empty($data->next_due_date) ? $data->next_due_date : null;
            $stmt->bindParam(':next_due_date', $next_due_date);
            
            $veterinarian_name = isset($data->veterinarian_name) ? $data->veterinarian_name : null;
            $stmt->bindParam(':veterinarian_name', $veterinarian_name);
            
            $weight = isset($data->weight) ? $data->weight : null;
            $stmt->bindParam(':weight', $weight);
            
            $batch_number = isset($data->batch_number) ? $data->batch_number : null;
            $stmt->bindParam(':batch_number', $batch_number);
            
            $notes = isset($data->notes) ? $data->notes : null;
            $stmt->bindParam(':notes', $notes);
            
            $stmt->bindParam(':administered_by', $user_data['user_id']);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create vaccination record');
            }
            
            $vaccination_id = $this->conn->lastInsertId();
            
            // ✅ SEND IMMEDIATE CONFIRMATION EMAIL
            try {
                $reminderService = new ReminderService();
                $confirmation_result = $reminderService->sendVaccinationConfirmation($data->pet_id, $vaccination_id);
                
                if ($confirmation_result['success']) {
                    error_log("VaccinationController: Confirmation email sent for vaccination ID $vaccination_id");
                } else {
                    error_log("VaccinationController: Failed to send confirmation - " . $confirmation_result['message']);
                }
            } catch (Exception $e) {
                error_log("VaccinationController: Error sending confirmation email - " . $e->getMessage());
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
            
            // Fetch the created vaccination with details
            $get_vaccination_query = "SELECT vr.*, COALESCE(ci.item_name, vt.name) as vaccine_name,
                                     COALESCE(vt.description, ci.notes) as vaccine_description,
                                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                                     FROM vaccination_records vr
                                     LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                                     LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                                     JOIN users u ON vr.administered_by = u.id
                                     WHERE vr.id = :vaccination_id";
            
            $get_stmt = $this->conn->prepare($get_vaccination_query);
            $get_stmt->bindParam(':vaccination_id', $vaccination_id);
            $get_stmt->execute();
            $vaccination = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Vaccination record created successfully',
                'vaccination_record' => $vaccination
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Vaccination record creation failed: ' . $e->getMessage()]);
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

            $query = "SELECT vr.*, COALESCE(ci.item_name, vt.name, inv.item_name) as vaccine_name,
                     COALESCE(vt.description, ci.notes, inv.notes) as vaccine_description
                     FROM vaccination_records vr
                     LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                     LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                     LEFT JOIN inventory inv ON inv.id = vr.inventory_id
                     WHERE vr.pet_id = :pet_id
                     ORDER BY vr.vaccination_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            $vaccinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $vaccination_json = json_encode($vaccinations);
            
            $update_query = "UPDATE vet_cards 
                            SET vaccination_records = :vaccination_records,
                                last_updated_by = :updated_by,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE pet_id = :pet_id AND is_active = 1";
            
            $update_stmt = $this->conn->prepare($update_query);
            $update_stmt->bindParam(':vaccination_records', $vaccination_json);
            $update_stmt->bindParam(':updated_by', $updated_by);
            $update_stmt->bindParam(':pet_id', $pet_id);
            
            return $update_stmt->execute();
            
        } catch (Exception $e) {
            error_log("Failed to update vet card records: " . $e->getMessage());
            return false;
        }
    }
    
    public function getByPetId($pet_id) {
        try {
            $query = "SELECT vr.*, COALESCE(ci.item_name, vt.name) as vaccine_name,
                     COALESCE(vt.description, ci.notes) as vaccine_description,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM vaccination_records vr
                     LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                     LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                     LEFT JOIN inventory inv ON inv.id = vr.inventory_id
                     JOIN users u ON vr.administered_by = u.id
                     WHERE vr.pet_id = :pet_id
                     ORDER BY vr.vaccination_date DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();
            
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'vaccination_records' => $records
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get vaccination records: ' . $e->getMessage()]);
        }
    }
    
    public function getDue() {
    $user_data = $this->auth->authenticate();
    
    try {
        $today = date('Y-m-d');
        $thirty_days = date('Y-m-d', strtotime('+30 days'));
        
        // ✅ ADD p.species to the SELECT statement
        $query = "SELECT vr.*, p.name as pet_name, p.registration_number, p.species as pet_species,
                 COALESCE(ci.item_name, vt.name) as vaccination_name,
                 DATEDIFF(vr.next_due_date, CURDATE()) as days_until_due,
                 po.user_id as owner_user_id
                 FROM vaccination_records vr
                 JOIN pets p ON vr.pet_id = p.id
                 LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                 LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                 JOIN pet_owners po ON p.owner_id = po.id
                 WHERE vr.next_due_date IS NOT NULL 
                 AND vr.next_due_date BETWEEN :today AND :thirty_days
                 AND p.is_active = 1";
        
        // ✅ ADD FILTER FOR PET OWNERS - Only show their own pets' vaccinations
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
        
        $query .= " ORDER BY vr.next_due_date ASC";
        
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
        
        $due_vaccinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'due_vaccinations' => $due_vaccinations,
            'total' => count($due_vaccinations)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get due vaccinations: ' . $e->getMessage()]);
    }
}
    public function getTypes() {
        try {
            $query = "SELECT * FROM vaccination_types ORDER BY name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'vaccination_types' => $types
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get vaccination types: ' . $e->getMessage()]);
        }
    }
    
    public function getStatistics() {
        $user_data = $this->auth->authenticate();
        
        try {
            // Total vaccinations
            $total_query = "SELECT COUNT(*) as total FROM vaccination_records";
            $total_stmt = $this->conn->prepare($total_query);
            $total_stmt->execute();
            $total_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Vaccinations this month
            $month_query = "SELECT COUNT(*) as total 
                           FROM vaccination_records 
                           WHERE MONTH(vaccination_date) = MONTH(CURRENT_DATE()) 
                           AND YEAR(vaccination_date) = YEAR(CURRENT_DATE())";
            $month_stmt = $this->conn->prepare($month_query);
            $month_stmt->execute();
            $month_result = $month_stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'statistics' => [
                    'total_vaccinations' => $total_result['total'],
                    'this_month' => $month_result['total']
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
            $query = "SELECT vr.*, p.name as pet_name,
                     COALESCE(ci.item_name, vt.name) as vaccine_name,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM vaccination_records vr
                     JOIN pets p ON vr.pet_id = p.id
                     LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                     LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                     JOIN users u ON vr.administered_by = u.id
                     WHERE vr.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success' => true,
                    'vaccination_record' => $record
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Vaccination record not found']);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get vaccination record: ' . $e->getMessage()]);
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
            
            // Get pet_id for this vaccination
            $get_pet_query = "SELECT pet_id FROM vaccination_records WHERE id = :id";
            $get_pet_stmt = $this->conn->prepare($get_pet_query);
            $get_pet_stmt->bindParam(':id', $id);
            $get_pet_stmt->execute();
            $pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pet_data) {
                throw new Exception('Vaccination record not found');
            }
            
            $update_fields = [];
            $params = [':id' => $id];
            
            $allowed_fields = ['vaccination_type_id', 'vaccination_date', 'next_due_date', 
                             'veterinarian_name', 'weight', 'batch_number', 'notes'];
            
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

            // If vaccination_type_id is changing, restore stock from the old batch
            if (isset($data->vaccination_type_id)) {
                $old_rec_stmt = $this->conn->prepare(
                    "SELECT batch_id, vaccination_type_id FROM vaccination_records WHERE id = :id"
                );
                $old_rec_stmt->execute([':id' => $id]);
                $old_rec = $old_rec_stmt->fetch(PDO::FETCH_ASSOC);

                if ($old_rec && $old_rec['vaccination_type_id'] != $data->vaccination_type_id && $old_rec['batch_id']) {
                    $restore_stmt = $this->conn->prepare(
                        "UPDATE inventory_batches SET quantity = quantity + 1,
                         updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                    );
                    $restore_stmt->execute([':batch_id' => $old_rec['batch_id']]);
                    // Clear batch_id since vaccine type changed and no new batch is assigned during edit
                    $update_fields[] = "batch_id = NULL";
                }
            }

            $query = "UPDATE vaccination_records SET " . implode(', ', $update_fields) . 
                    ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update vaccination record');
            }
            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Vaccination record updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Vaccination record update failed: ' . $e->getMessage()]);
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
            
            $get_pet_query = "SELECT vr.pet_id, u.role FROM vaccination_records vr JOIN users u ON vr.administered_by = u.id WHERE vr.id = :id";
$get_pet_stmt = $this->conn->prepare($get_pet_query);
$get_pet_stmt->bindParam(':id', $id);
$get_pet_stmt->execute();
$pet_data = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet_data) {
    throw new Exception('Vaccination record not found');
}

if ($pet_data['role'] === 'private_clinic') {
    $this->conn->rollBack();
    http_response_code(403);
    echo json_encode(['error' => 'Cannot delete records created by a private clinic']);
    return;
}
            
            // Get batch ids before deleting so we can restore stock
            $get_batch_stmt = $this->conn->prepare(
                "SELECT batch_id, clinic_batch_id FROM vaccination_records WHERE id = :id"
            );
            $get_batch_stmt->bindParam(':id', $id);
            $get_batch_stmt->execute();
            $batch_row = $get_batch_stmt->fetch(PDO::FETCH_ASSOC);
            $batch_id_to_restore = $batch_row ? $batch_row['batch_id'] : null;
            $clinic_batch_id_to_restore = $batch_row ? $batch_row['clinic_batch_id'] : null;

            // Delete vaccination record
            $query = "DELETE FROM vaccination_records WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete vaccination record');
            }

            // Restore 1 unit to admin batch — triggers auto-update inventory.current_stock
            if ($batch_id_to_restore) {
                $restore_stmt = $this->conn->prepare(
                    "UPDATE inventory_batches SET quantity = quantity + 1,
                     updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                );
                $restore_stmt->execute([':batch_id' => $batch_id_to_restore]);
            }

            // Restore 1 unit to clinic batch — triggers auto-update clinic_inventory.current_stock
            if ($clinic_batch_id_to_restore) {
                $restore_clinic_stmt = $this->conn->prepare(
                    "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                     updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                );
                $restore_clinic_stmt->execute([':batch_id' => $clinic_batch_id_to_restore]);
            }

            
            // Update vet card records
            $this->updateVetCardRecords($pet_data['pet_id'], $user_data['user_id']);
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Vaccination record deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Vaccination record deletion failed: ' . $e->getMessage()]);
        }
    }
}
?>