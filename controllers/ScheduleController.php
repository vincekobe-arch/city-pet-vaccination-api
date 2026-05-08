    <?php

    require_once 'config/database.php';
    require_once 'middleware/Auth.php';

    class ScheduleController {
        private $conn;
        private $auth;
        
        public function __construct() {
            $database = new Database();
            $this->conn = $database->getConnection();
            $this->auth = new Auth();
        }

        private function updateScheduleStatuses() {
        try {
            $current_datetime = new DateTime();
            $current_date = $current_datetime->format('Y-m-d');
            
            $tables = [
    'vaccination_schedules',
    'deworming_schedules',
    'seminar_schedules',
    'sterilization_schedules',
    'other_schedules',
    'microchip_schedules'
];
            
            foreach ($tables as $table) {
                // First, get all schedules that might need status updates
                $selectQuery = "SELECT id, scheduled_date, start_time, end_time, status FROM {$table} 
                            WHERE status IN ('scheduled', 'ongoing')";
                $selectStmt = $this->conn->prepare($selectQuery);
                $selectStmt->execute();
                $schedules = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($schedules as $schedule) {
                    // Create datetime objects for comparison
                    $schedule_start = new DateTime($schedule['scheduled_date'] . ' ' . $schedule['start_time']);
                    $schedule_end = new DateTime($schedule['scheduled_date'] . ' ' . $schedule['end_time']);
                    
                    // Check if should be ongoing
                    if ($current_datetime >= $schedule_start && $current_datetime < $schedule_end && $schedule['status'] === 'scheduled') {
                        $updateQuery = "UPDATE {$table} SET status = 'ongoing' WHERE id = :id";
                        $updateStmt = $this->conn->prepare($updateQuery);
                        $updateStmt->bindParam(':id', $schedule['id']);
                        $updateStmt->execute();
                    }
                    
                    // Check if should be completed
                    if ($current_datetime >= $schedule_end && $schedule['status'] !== 'completed') {
                        $updateQuery = "UPDATE {$table} SET status = 'completed' WHERE id = :id";
                        $updateStmt = $this->conn->prepare($updateQuery);
                        $updateStmt->bindParam(':id', $schedule['id']);
                        $updateStmt->execute();
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error updating schedule statuses: " . $e->getMessage());
        }
    }
    public function getVaccineRegistrationCounts($schedule_id) {
    $user_data = $this->auth->authenticate();
    
    try {
        // Get all registrations for this vaccination schedule
        $query = "SELECT selected_vaccines 
                  FROM schedule_registrations 
                  WHERE schedule_id = :schedule_id 
                  AND schedule_type = 'vaccination'
                  AND status != 'cancelled'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':schedule_id', $schedule_id);
        $stmt->execute();
        
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Count vaccines
        $vaccine_counts = [];
        
        foreach ($registrations as $registration) {
            if ($registration['selected_vaccines']) {
                $selected = json_decode($registration['selected_vaccines'], true);
                if (is_array($selected)) {
                    foreach ($selected as $vaccine_id) {
                        $vaccine_id_str = strval($vaccine_id);
                        if (!isset($vaccine_counts[$vaccine_id_str])) {
                            $vaccine_counts[$vaccine_id_str] = 0;
                        }
                        $vaccine_counts[$vaccine_id_str]++;
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'vaccine_counts' => $vaccine_counts
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get vaccine counts: ' . $e->getMessage()
        ]);
    }
}
        
        // GET vaccination schedules - /schedules/vaccination
        public function vaccination() {
            $user_data = $this->auth->authenticate();
            $this->updateScheduleStatuses();
            
            try {
                $query = "SELECT vs.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name
                        FROM vaccination_schedules vs
                        LEFT JOIN barangays b ON vs.barangay_id = b.id
                        LEFT JOIN users u ON vs.created_by = u.id
                        ORDER BY vs.scheduled_date DESC, vs.start_time DESC";
                
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Decode JSON fields
                foreach ($schedules as &$schedule) {
                    if ($schedule['vaccination_types']) {
                        $schedule['vaccination_types'] = json_decode($schedule['vaccination_types'], true);
                    }
                    if ($schedule['vaccine_shot_limits']) {
                        $schedule['vaccine_shot_limits'] = json_decode($schedule['vaccine_shot_limits'], true);
                    }
                    if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
                        $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
                    } else {
                        $schedule['pet_types_allowed'] = [];
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'schedules' => $schedules,
                    'total' => count($schedules)
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to get vaccination schedules: ' . $e->getMessage()
                ]);
            }
        }
        public function deworming() {
        $user_data = $this->auth->authenticate();
        $this->updateScheduleStatuses();
        
        try {
            $query = "SELECT ds.*, 
                    b.id as barangay_id,
                    b.name as barangay_name,
                    u.first_name as created_by_first_name,
                    u.last_name as created_by_last_name
                    FROM deworming_schedules ds
                    LEFT JOIN barangays b ON ds.barangay_id = b.id
                    LEFT JOIN users u ON ds.created_by = u.id
                    ORDER BY ds.scheduled_date DESC, ds.start_time DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($schedules as &$schedule) {
                if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
                    $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
                } else {
                    $schedule['pet_types_allowed'] = [];
                }
            }
            
            echo json_encode([
                'success' => true,
                'schedules' => $schedules,
                'total' => count($schedules)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get deworming schedules: ' . $e->getMessage()
            ]);
        }
    }
        
        // GET seminar schedules - /schedules/seminar
        public function seminar() {
            $user_data = $this->auth->authenticate();
            $this->updateScheduleStatuses();
            
            try {
                $query = "SELECT ss.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name
                        FROM seminar_schedules ss
                        LEFT JOIN barangays b ON ss.barangay_id = b.id
                        LEFT JOIN users u ON ss.created_by = u.id
                        ORDER BY ss.scheduled_date DESC, ss.start_time DESC";
                
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'schedules' => $schedules,
                    'total' => count($schedules)
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to get seminar schedules: ' . $e->getMessage()
                ]);
            }
        }
        
        // GET sterilization schedules - /schedules/sterilization
        public function sterilization() {
            $user_data = $this->auth->authenticate();
            $this->updateScheduleStatuses();
            
            try {
                $query = "SELECT st.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name
                        FROM sterilization_schedules st
                        LEFT JOIN barangays b ON st.barangay_id = b.id
                        LEFT JOIN users u ON st.created_by = u.id
                        ORDER BY st.scheduled_date DESC, st.start_time DESC";
                
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Decode JSON fields for sterilization_species
                foreach ($schedules as &$schedule) {
                    if (isset($schedule['sterilization_species']) && $schedule['sterilization_species']) {
                        $schedule['sterilization_species'] = json_decode($schedule['sterilization_species'], true);
                    } else {
                        $schedule['sterilization_species'] = [];
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'schedules' => $schedules,
                    'total' => count($schedules)
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to get sterilization schedules: ' . $e->getMessage()
                ]);
            }
        }

        // GET microchip schedules - /schedules/microchip
public function microchip() {
    $user_data = $this->auth->authenticate();
    $this->updateScheduleStatuses();

    try {
        $query = "SELECT ms.*,
                b.id as barangay_id,
                b.name as barangay_name,
                u.first_name as created_by_first_name,
                u.last_name as created_by_last_name
                FROM microchip_schedules ms
                LEFT JOIN barangays b ON ms.barangay_id = b.id
                LEFT JOIN users u ON ms.created_by = u.id
                ORDER BY ms.scheduled_date DESC, ms.start_time DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($schedules as &$schedule) {
            if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
                $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
            } else {
                $schedule['pet_types_allowed'] = [];
            }
        }

        echo json_encode([
            'success' => true,
            'schedules' => $schedules,
            'total' => count($schedules)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get microchip schedules: ' . $e->getMessage()
        ]);
    }
}
        // GET other schedules - /schedules/other
public function other() {
    $user_data = $this->auth->authenticate();
    $this->updateScheduleStatuses();
    
    try {
        $query = "SELECT os.*, 
                b.id as barangay_id,
                b.name as barangay_name,
                u.first_name as created_by_first_name,
                u.last_name as created_by_last_name
                FROM other_schedules os
                LEFT JOIN barangays b ON os.barangay_id = b.id
                LEFT JOIN users u ON os.created_by = u.id
                ORDER BY os.scheduled_date DESC, os.start_time DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON fields
        foreach ($schedules as &$schedule) {
            if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
                $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
            } else {
                $schedule['pet_types_allowed'] = [];
            }
        }
        
        echo json_encode([
            'success' => true,
            'schedules' => $schedules,
            'total' => count($schedules)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to get other schedules: ' . $e->getMessage()
        ]);
    }
}
        public function getRegisteredPets($schedule_id, $schedule_type) {
    $user_data = $this->auth->authenticate();
    
    try {
        // IMPORTANT: Also select selected_vaccines field
        // For seminars, pet_id can be NULL since they don't require pet registration
        if ($schedule_type === 'seminar') {
            $query = "SELECT id, pet_id, registration_date, status, notes, selected_vaccines
                    FROM schedule_registrations 
                    WHERE user_id = :user_id 
                    AND schedule_id = :schedule_id 
                    AND schedule_type = :schedule_type
                    AND status != 'cancelled'";
        } else {
            $query = "SELECT id, pet_id, registration_date, status, notes, selected_vaccines
                    FROM schedule_registrations 
                    WHERE user_id = :user_id 
                    AND schedule_id = :schedule_id 
                    AND schedule_type = :schedule_type
                    AND status != 'cancelled'
                    AND pet_id IS NOT NULL";
        }
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            $stmt->bindParam(':schedule_id', $schedule_id);
            $stmt->bindParam(':schedule_type', $schedule_type);
            $stmt->execute();
            
            $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode selected_vaccines JSON for each registration
            foreach ($registrations as &$registration) {
                if (isset($registration['selected_vaccines']) && $registration['selected_vaccines']) {
                    $registration['selected_vaccines'] = json_decode($registration['selected_vaccines'], true);
                } else {
                    $registration['selected_vaccines'] = [];
                }
            }
            
            $registered_pet_ids = array_column($registrations, 'pet_id');
            
            echo json_encode([
                'success' => true,
                'registered_pet_ids' => $registered_pet_ids,
                'registrations' => $registrations
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get registered pets: ' . $e->getMessage()
            ]);
        }
    }

        // POST register for schedule - /schedules/register
        // Replace the register() method in your ScheduleController.php with this fixed version:

    public function register() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['pet_owner'], $user_data);
    
    $data = json_decode(file_get_contents("php://input"));
    
    // Validate required fields
    if (!isset($data->schedule_id) || !isset($data->schedule_type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Schedule ID and type are required']);
        return;
    }
    
    try {
        $this->conn->beginTransaction();
        
        // Check capacity for the schedule
        $table = $data->schedule_type === 'vaccination' ? 'vaccination_schedules' : 
        ($data->schedule_type === 'deworming' ? 'deworming_schedules' :
        ($data->schedule_type === 'seminar' ? 'seminar_schedules' : 'sterilization_schedules'));

        // Only select vaccine_shot_limits for vaccination schedules
        if ($data->schedule_type === 'vaccination') {
            $capacityQuery = "SELECT max_capacity, current_registrations, vaccine_shot_limits FROM {$table} WHERE id = :schedule_id";
        } else {
            $capacityQuery = "SELECT max_capacity, current_registrations FROM {$table} WHERE id = :schedule_id";
        }
        
        $capacityStmt = $this->conn->prepare($capacityQuery);
        $capacityStmt->bindParam(':schedule_id', $data->schedule_id);
        $capacityStmt->execute();
        $schedule = $capacityStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($schedule && $schedule['max_capacity']) {
            if ($schedule['current_registrations'] >= $schedule['max_capacity']) {
                http_response_code(400);
                echo json_encode(['error' => 'This event is fully booked']);
                $this->conn->rollBack();
                return;
            }
        }
        
        $registration_ids = [];
        
        // VACCINATION REGISTRATION
        if ($data->schedule_type === 'vaccination') {
            // Validate that pets_with_vaccines is provided
            if (!isset($data->pets_with_vaccines) || !is_array($data->pets_with_vaccines) || count($data->pets_with_vaccines) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'At least one pet with selected vaccines must be provided']);
                $this->conn->rollBack();
                return;
            }
            
            // Get vaccine shot limits
            $vaccine_shot_limits = json_decode($schedule['vaccine_shot_limits'], true) ?: [];
            
            // Calculate total capacity
            $totalCapacity = array_sum($vaccine_shot_limits);
            
            // Get current registrations
            $currentRegs = $schedule['current_registrations'] ?? 0;
            
            // Check if event is full
            if ($currentRegs >= $totalCapacity) {
                http_response_code(400);
                echo json_encode(['error' => 'This vaccination event is fully booked']);
                $this->conn->rollBack();
                return;
            }
            
            // Validate vaccines exist in the schedule
            foreach ($data->pets_with_vaccines as $pet_registration) {
                if (!isset($pet_registration->pet_id) || !isset($pet_registration->selected_vaccines) || 
                    !is_array($pet_registration->selected_vaccines) || count($pet_registration->selected_vaccines) === 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Each pet must have at least one vaccine selected']);
                    $this->conn->rollBack();
                    return;
                }
                
                foreach ($pet_registration->selected_vaccines as $vaccine_id) {
                    $vaccine_id_str = strval($vaccine_id);
                    if (!isset($vaccine_shot_limits[$vaccine_id_str])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Selected vaccine is not available in this schedule']);
                        $this->conn->rollBack();
                        return;
                    }
                }
            }
            
            // Register each pet
            $total_vaccine_shots = 0;

            foreach ($data->pets_with_vaccines as $pet_registration) {
                $pet_id = $pet_registration->pet_id;
                $selected_vaccines = $pet_registration->selected_vaccines;
                
                // Check if pet is already registered
                $checkQuery = "SELECT id FROM schedule_registrations 
                            WHERE user_id = :user_id 
                            AND schedule_id = :schedule_id 
                            AND schedule_type = :schedule_type
                            AND pet_id = :pet_id
                            AND status != 'cancelled'";
                
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user_data['user_id']);
                $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                $checkStmt->bindParam(':pet_id', $pet_id);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    continue;
                }
                
                // Insert registration
                $query = "INSERT INTO schedule_registrations 
                        (user_id, schedule_id, schedule_type, pet_id, selected_vaccines, status, notes) 
                        VALUES 
                        (:user_id, :schedule_id, :schedule_type, :pet_id, :selected_vaccines, 'registered', :notes)";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
                $stmt->bindParam(':schedule_id', $data->schedule_id);
                $stmt->bindParam(':schedule_type', $data->schedule_type);
                $stmt->bindParam(':pet_id', $pet_id);
                
                $selected_vaccines_json = json_encode($selected_vaccines);
                $stmt->bindParam(':selected_vaccines', $selected_vaccines_json);
                
                $notes = '';
                if (isset($data->special_requests) && !empty($data->special_requests)) {
                    $notes .= "Special Requests: " . $data->special_requests . "\n";
                }
                if (isset($data->notes) && !empty($data->notes)) {
                    $notes .= "Notes: " . $data->notes;
                }
                $stmt->bindParam(':notes', $notes);
                
                if ($stmt->execute()) {
                    $registration_ids[] = $this->conn->lastInsertId();
                    $total_vaccine_shots += count($selected_vaccines);
                }
            }

            if (count($registration_ids) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'All selected pets are already registered for this event']);
                $this->conn->rollBack();
                return;
            }

            // Update by vaccine shots count
            $updateQuery = "UPDATE vaccination_schedules 
                       SET current_registrations = COALESCE(current_registrations, 0) + :vaccine_shots
                       WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':vaccine_shots', $total_vaccine_shots, PDO::PARAM_INT);
            $updateStmt->bindParam(':schedule_id', $data->schedule_id);
            $updateStmt->execute();
        } 
        // DEWORMING REGISTRATION
        else if ($data->schedule_type === 'deworming') {
            // Validate deworming-specific requirements
            if (!isset($data->pets_registered) || !is_array($data->pets_registered) || count($data->pets_registered) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'At least one pet must be selected']);
                $this->conn->rollBack();
                return;
            }
            
            foreach ($data->pets_registered as $pet_id) {
                $checkQuery = "SELECT id FROM schedule_registrations 
                            WHERE user_id = :user_id 
                            AND schedule_id = :schedule_id 
                            AND schedule_type = :schedule_type
                            AND pet_id = :pet_id
                            AND status != 'cancelled'";
                
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user_data['user_id']);
                $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                $checkStmt->bindParam(':pet_id', $pet_id);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    continue;
                }
                
                $query = "INSERT INTO schedule_registrations 
                        (user_id, schedule_id, schedule_type, pet_id, status, notes) 
                        VALUES 
                        (:user_id, :schedule_id, :schedule_type, :pet_id, 'registered', :notes)";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
                $stmt->bindParam(':schedule_id', $data->schedule_id);
                $stmt->bindParam(':schedule_type', $data->schedule_type);
                $stmt->bindParam(':pet_id', $pet_id);
                
                $notes = '';
                if (isset($data->special_requests) && !empty($data->special_requests)) {
                    $notes .= "Special Requests: " . $data->special_requests . "\n";
                }
                if (isset($data->notes) && !empty($data->notes)) {
                    $notes .= "Notes: " . $data->notes;
                }
                $stmt->bindParam(':notes', $notes);
                
                if ($stmt->execute()) {
                    $registration_ids[] = $this->conn->lastInsertId();
                }
            }
            
            if (count($registration_ids) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'All selected pets are already registered for this event']);
                $this->conn->rollBack();
                return;
            }
            
            $updateQuery = "UPDATE deworming_schedules 
                        SET current_registrations = COALESCE(current_registrations, 0) + " . count($registration_ids) . "
                        WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $data->schedule_id);
            $updateStmt->execute();
        } 
        // STERILIZATION REGISTRATION
        else if ($data->schedule_type === 'sterilization') {
            if (!isset($data->pets_registered) || !is_array($data->pets_registered) || count($data->pets_registered) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'At least one pet must be selected']);
                $this->conn->rollBack();
                return;
            }
            
            foreach ($data->pets_registered as $pet_id) {
                $checkQuery = "SELECT id FROM schedule_registrations 
                            WHERE user_id = :user_id 
                            AND schedule_id = :schedule_id 
                            AND schedule_type = :schedule_type
                            AND pet_id = :pet_id
                            AND status != 'cancelled'";
                
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user_data['user_id']);
                $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                $checkStmt->bindParam(':pet_id', $pet_id);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    continue;
                }
                
                $query = "INSERT INTO schedule_registrations 
                        (user_id, schedule_id, schedule_type, pet_id, status, notes) 
                        VALUES 
                        (:user_id, :schedule_id, :schedule_type, :pet_id, 'registered', :notes)";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
                $stmt->bindParam(':schedule_id', $data->schedule_id);
                $stmt->bindParam(':schedule_type', $data->schedule_type);
                $stmt->bindParam(':pet_id', $pet_id);
                
                $notes = '';
                if (isset($data->special_requests) && !empty($data->special_requests)) {
                    $notes .= "Special Requests: " . $data->special_requests . "\n";
                }
                if (isset($data->notes) && !empty($data->notes)) {
                    $notes .= "Notes: " . $data->notes;
                }
                $stmt->bindParam(':notes', $notes);
                
                if ($stmt->execute()) {
                    $registration_ids[] = $this->conn->lastInsertId();
                }
            }
            
            if (count($registration_ids) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'All selected pets are already registered for this event']);
                $this->conn->rollBack();
                return;
            }
            
            $updateQuery = "UPDATE sterilization_schedules 
                        SET current_registrations = COALESCE(current_registrations, 0) + " . count($registration_ids) . "
                        WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $data->schedule_id);
            $updateStmt->execute();
        } 
        // SEMINAR REGISTRATION
        else if ($data->schedule_type === 'seminar') {
            $checkQuery = "SELECT id FROM schedule_registrations 
                        WHERE user_id = :user_id 
                        AND schedule_id = :schedule_id 
                        AND schedule_type = :schedule_type
                        AND status != 'cancelled'";
            
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':user_id', $user_data['user_id']);
            $checkStmt->bindParam(':schedule_id', $data->schedule_id);
            $checkStmt->bindParam(':schedule_type', $data->schedule_type);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'You are already registered for this seminar']);
                $this->conn->rollBack();
                return;
            }
            
            $query = "INSERT INTO schedule_registrations 
                    (user_id, schedule_id, schedule_type, status, notes) 
                    VALUES 
                    (:user_id, :schedule_id, :schedule_type, 'registered', :notes)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            $stmt->bindParam(':schedule_id', $data->schedule_id);
            $stmt->bindParam(':schedule_type', $data->schedule_type);
            
            $notes = '';
            if (isset($data->special_requests) && !empty($data->special_requests)) {
                $notes .= "Special Requests: " . $data->special_requests . "\n";
            }
            if (isset($data->notes) && !empty($data->notes)) {
                $notes .= "Notes: " . $data->notes;
            }
            $stmt->bindParam(':notes', $notes);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create registration');
            }
            
            $registration_ids[] = $this->conn->lastInsertId();
            
            $updateQuery = "UPDATE seminar_schedules 
                        SET current_registrations = COALESCE(current_registrations, 0) + 1
                        WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $data->schedule_id);
            $updateStmt->execute();
        }
        // MICROCHIP REGISTRATION
        else if ($data->schedule_type === 'microchip') {
            if (!isset($data->pets_registered) || !is_array($data->pets_registered) || count($data->pets_registered) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'At least one pet must be selected']);
                $this->conn->rollBack();
                return;
            }

            foreach ($data->pets_registered as $pet_id) {
                $checkQuery = "SELECT id FROM schedule_registrations
                            WHERE user_id = :user_id
                            AND schedule_id = :schedule_id
                            AND schedule_type = :schedule_type
                            AND pet_id = :pet_id
                            AND status != 'cancelled'";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user_data['user_id']);
                $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                $checkStmt->bindParam(':pet_id', $pet_id);
                $checkStmt->execute();

                if ($checkStmt->rowCount() > 0) continue;

                $query = "INSERT INTO schedule_registrations
                        (user_id, schedule_id, schedule_type, pet_id, status, notes)
                        VALUES
                        (:user_id, :schedule_id, :schedule_type, :pet_id, 'registered', :notes)";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
                $stmt->bindParam(':schedule_id', $data->schedule_id);
                $stmt->bindParam(':schedule_type', $data->schedule_type);
                $stmt->bindParam(':pet_id', $pet_id);
                $notes = '';
                if (isset($data->notes) && !empty($data->notes)) $notes = $data->notes;
                $stmt->bindParam(':notes', $notes);
                if ($stmt->execute()) $registration_ids[] = $this->conn->lastInsertId();
            }

            if (count($registration_ids) === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'All selected pets are already registered for this event']);
                $this->conn->rollBack();
                return;
            }

            $updateQuery = "UPDATE microchip_schedules
                        SET current_registrations = COALESCE(current_registrations, 0) + " . count($registration_ids) . "
                        WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $data->schedule_id);
            $updateStmt->execute();
        }
        // OTHER EVENT REGISTRATION
        else if ($data->schedule_type === 'other') {
            // Check if pet selection is required for this other event
            $otherScheduleQuery = "SELECT pet_types_allowed FROM other_schedules WHERE id = :schedule_id";
            $otherScheduleStmt = $this->conn->prepare($otherScheduleQuery);
            $otherScheduleStmt->bindParam(':schedule_id', $data->schedule_id);
            $otherScheduleStmt->execute();
            $otherSchedule = $otherScheduleStmt->fetch(PDO::FETCH_ASSOC);
            
            $petTypesAllowed = json_decode($otherSchedule['pet_types_allowed'] ?? '[]', true);
            $requiresPetSelection = is_array($petTypesAllowed) && count($petTypesAllowed) > 0;
            
            if ($requiresPetSelection) {
                // Pet selection required - register pets
                if (!isset($data->pets_registered) || !is_array($data->pets_registered) || count($data->pets_registered) === 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'At least one pet must be selected']);
                    $this->conn->rollBack();
                    return;
                }
                
                foreach ($data->pets_registered as $pet_id) {
                    $checkQuery = "SELECT id FROM schedule_registrations 
                                WHERE user_id = :user_id 
                                AND schedule_id = :schedule_id 
                                AND schedule_type = :schedule_type
                                AND pet_id = :pet_id
                                AND status != 'cancelled'";
                    
                    $checkStmt = $this->conn->prepare($checkQuery);
                    $checkStmt->bindParam(':user_id', $user_data['user_id']);
                    $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                    $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                    $checkStmt->bindParam(':pet_id', $pet_id);
                    $checkStmt->execute();
                    
                    if ($checkStmt->rowCount() > 0) {
                        continue;
                    }
                    
                    $query = "INSERT INTO schedule_registrations 
                            (user_id, schedule_id, schedule_type, pet_id, status, notes) 
                            VALUES 
                            (:user_id, :schedule_id, :schedule_type, :pet_id, 'registered', :notes)";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':user_id', $user_data['user_id']);
                    $stmt->bindParam(':schedule_id', $data->schedule_id);
                    $stmt->bindParam(':schedule_type', $data->schedule_type);
                    $stmt->bindParam(':pet_id', $pet_id);
                    
                    $notes = '';
                    if (isset($data->special_requests) && !empty($data->special_requests)) {
                        $notes .= "Special Requests: " . $data->special_requests . "\n";
                    }
                    if (isset($data->notes) && !empty($data->notes)) {
                        $notes .= "Notes: " . $data->notes;
                    }
                    $stmt->bindParam(':notes', $notes);
                    
                    if ($stmt->execute()) {
                        $registration_ids[] = $this->conn->lastInsertId();
                    }
                }
                
                if (count($registration_ids) === 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'All selected pets are already registered for this event']);
                    $this->conn->rollBack();
                    return;
                }
                
                $updateQuery = "UPDATE other_schedules 
                            SET current_registrations = COALESCE(current_registrations, 0) + " . count($registration_ids) . "
                            WHERE id = :schedule_id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':schedule_id', $data->schedule_id);
                $updateStmt->execute();
            } else {
                // No pet selection required - seminar-like registration
                $checkQuery = "SELECT id FROM schedule_registrations 
                            WHERE user_id = :user_id 
                            AND schedule_id = :schedule_id 
                            AND schedule_type = :schedule_type
                            AND status != 'cancelled'";
                
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->bindParam(':user_id', $user_data['user_id']);
                $checkStmt->bindParam(':schedule_id', $data->schedule_id);
                $checkStmt->bindParam(':schedule_type', $data->schedule_type);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'You are already registered for this event']);
                    $this->conn->rollBack();
                    return;
                }
                
                $query = "INSERT INTO schedule_registrations 
                        (user_id, schedule_id, schedule_type, status, notes) 
                        VALUES 
                        (:user_id, :schedule_id, :schedule_type, 'registered', :notes)";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $user_data['user_id']);
                $stmt->bindParam(':schedule_id', $data->schedule_id);
                $stmt->bindParam(':schedule_type', $data->schedule_type);
                
                $notes = '';
                if (isset($data->special_requests) && !empty($data->special_requests)) {
                    $notes .= "Special Requests: " . $data->special_requests . "\n";
                }
                if (isset($data->notes) && !empty($data->notes)) {
                    $notes .= "Notes: " . $data->notes;
                }
                $stmt->bindParam(':notes', $notes);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create registration');
                }
                
                $registration_ids[] = $this->conn->lastInsertId();
                
                $updateQuery = "UPDATE other_schedules 
                            SET current_registrations = COALESCE(current_registrations, 0) + 1
                            WHERE id = :schedule_id";
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateStmt->bindParam(':schedule_id', $data->schedule_id);
                $updateStmt->execute();
            }
        }
        
        $this->conn->commit();
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Successfully registered for the event',
            'registration_ids' => $registration_ids,
            'registered_count' => count($registration_ids)
        ]);
        
    } catch (Exception $e) {
        $this->conn->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Registration failed: ' . $e->getMessage()
        ]);
    }
}
    public function cancelRegistration($registration_id) {
    error_log("cancelRegistration called with ID: " . $registration_id);
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed. Expected DELETE, got ' . $_SERVER['REQUEST_METHOD']
        ]);
        return;
    }
    
    try {
        $user_data = $this->auth->authenticate();
        error_log("User authenticated: " . $user_data['user_id']);
        
        $this->auth->checkRole(['pet_owner'], $user_data);
        
        $this->conn->beginTransaction();
        
        // Step 1: Get registration details INCLUDING selected_vaccines
        $checkQuery = "SELECT id, schedule_id, schedule_type, status, selected_vaccines, pet_id 
                    FROM schedule_registrations 
                    WHERE id = :registration_id AND user_id = :user_id";
        
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':registration_id', $registration_id, PDO::PARAM_INT);
        $checkStmt->bindParam(':user_id', $user_data['user_id'], PDO::PARAM_INT);
        $checkStmt->execute();
        
        error_log("Check query executed. Rows found: " . $checkStmt->rowCount());
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Registration not found or does not belong to you'
            ]);
            $this->conn->rollBack();
            return;
        }
        
        $registration = $checkStmt->fetch(PDO::FETCH_ASSOC);
        error_log("Registration found: " . json_encode($registration));
        
        // Check if already cancelled
        if ($registration['status'] === 'cancelled') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Registration is already cancelled'
            ]);
            $this->conn->rollBack();
            return;
        }
        
        // Step 2: Update registration status to cancelled
        $cancelQuery = "UPDATE schedule_registrations 
                    SET status = 'cancelled' 
                    WHERE id = :registration_id";
        
        $cancelStmt = $this->conn->prepare($cancelQuery);
        $cancelStmt->bindParam(':registration_id', $registration_id, PDO::PARAM_INT);
        
        $cancelExecuted = $cancelStmt->execute();
        error_log("Cancel query executed: " . ($cancelExecuted ? 'true' : 'false'));
        
        if (!$cancelExecuted) {
            throw new Exception('Failed to update registration status');
        }
        
        // Step 3: Handle capacity/shot restoration based on schedule type
        if ($registration['schedule_type'] === 'vaccination') {
            // VACCINATION: Restore vaccine shots (count of vaccines, not just 1)
            if ($registration['selected_vaccines']) {
                $selected_vaccines = json_decode($registration['selected_vaccines'], true);
                
                if (is_array($selected_vaccines) && count($selected_vaccines) > 0) {
                    $num_vaccine_shots = count($selected_vaccines);
                    
                    // Decrease current_registrations by the NUMBER OF VACCINE SHOTS
                    $updateQuery = "UPDATE vaccination_schedules 
                                SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) - :vaccine_shots)
                                WHERE id = :schedule_id";
                    $updateStmt = $this->conn->prepare($updateQuery);
                    $updateStmt->bindParam(':vaccine_shots', $num_vaccine_shots, PDO::PARAM_INT);
                    $updateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
                    $updateStmt->execute();
                    
                    error_log("Vaccination cancelled: restored $num_vaccine_shots shots for schedule " . $registration['schedule_id']);
                }
            }
        } 
        else if ($registration['schedule_type'] === 'deworming') {
            // DEWORMING: Decrease by 1 pet
            $updateQuery = "UPDATE deworming_schedules 
                        SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) - 1)
                        WHERE id = :schedule_id";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
            
            $updateExecuted = $updateStmt->execute();
            error_log("Deworming count update executed: " . ($updateExecuted ? 'true' : 'false'));
        } 
        else if ($registration['schedule_type'] === 'sterilization') {
            // STERILIZATION: Decrease by 1 pet
            $updateQuery = "UPDATE sterilization_schedules 
                        SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) - 1)
                        WHERE id = :schedule_id";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
            
            $updateExecuted = $updateStmt->execute();
            error_log("Sterilization count update executed: " . ($updateExecuted ? 'true' : 'false'));
        } 
        else if ($registration['schedule_type'] === 'seminar') {
            // SEMINAR: Decrease by 1
            $updateQuery = "UPDATE seminar_schedules 
                        SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) - 1)
                        WHERE id = :schedule_id";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
            
            $updateExecuted = $updateStmt->execute();
            error_log("Seminar count update executed: " . ($updateExecuted ? 'true' : 'false'));
        }
        else if ($registration['schedule_type'] === 'microchip') {
            $updateQuery = "UPDATE microchip_schedules
                        SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) - 1)
                        WHERE id = :schedule_id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
            $updateStmt->execute();
        }
        
        // Commit transaction
        $this->conn->commit();
        error_log("Transaction committed successfully");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Registration cancelled successfully',
            'registration_id' => $registration_id,
            'schedule_type' => $registration['schedule_type'],
            'schedule_id' => $registration['schedule_id']
        ]);
        
    } catch (Exception $e) {
        error_log("Exception in cancelRegistration: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        $this->conn->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to cancel registration: ' . $e->getMessage()
        ]);
    }
}
public function updateRegistration($registration_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed'
        ]);
        return;
    }
    
    try {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['pet_owner'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        error_log("updateRegistration called for ID: " . $registration_id);
        error_log("New vaccines data: " . json_encode($data->selected_vaccines ?? []));
        
        // Get current registration
        $checkQuery = "SELECT id, schedule_id, schedule_type, selected_vaccines 
                      FROM schedule_registrations 
                      WHERE id = :registration_id AND user_id = :user_id AND status != 'cancelled'";
        
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':registration_id', $registration_id, PDO::PARAM_INT);
        $checkStmt->bindParam(':user_id', $user_data['user_id'], PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Registration not found'
            ]);
            return;
        }
        
        $registration = $checkStmt->fetch(PDO::FETCH_ASSOC);
        error_log("Current registration: " . json_encode($registration));
        
        $this->conn->beginTransaction();
        
        // Only allow updating selected_vaccines for vaccination schedules
        if ($registration['schedule_type'] === 'vaccination' && isset($data->selected_vaccines)) {
            $old_vaccines = json_decode($registration['selected_vaccines'], true) ?: [];
            $new_vaccines = $data->selected_vaccines;
            
            // Calculate the difference in vaccine count
            $old_count = count($old_vaccines);
            $new_count = count($new_vaccines);
            $count_diff = $new_count - $old_count;
            
            error_log("Old vaccine count: $old_count, New vaccine count: $new_count, Diff: $count_diff");
            
            // Update registration
            $updateQuery = "UPDATE schedule_registrations 
                          SET selected_vaccines = :selected_vaccines 
                          WHERE id = :registration_id";
            
            $updateStmt = $this->conn->prepare($updateQuery);
            $selected_vaccines_json = json_encode($new_vaccines);
            $updateStmt->bindParam(':selected_vaccines', $selected_vaccines_json);
            $updateStmt->bindParam(':registration_id', $registration_id, PDO::PARAM_INT);
            $updateStmt->execute();
            
            error_log("Registration updated in database");
            
            // Update vaccination schedule count
            if ($count_diff != 0) {
                $countUpdateQuery = "UPDATE vaccination_schedules 
                                   SET current_registrations = GREATEST(0, COALESCE(current_registrations, 0) + :count_diff)
                                   WHERE id = :schedule_id";
                
                $countUpdateStmt = $this->conn->prepare($countUpdateQuery);
                $countUpdateStmt->bindParam(':count_diff', $count_diff, PDO::PARAM_INT);
                $countUpdateStmt->bindParam(':schedule_id', $registration['schedule_id'], PDO::PARAM_INT);
                $countUpdateStmt->execute();
                
                error_log("Updated schedule count by: $count_diff for schedule ID: " . $registration['schedule_id']);
            }
        }
        
        $this->conn->commit();
        error_log("Transaction committed successfully");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Registration updated successfully',
            'count_diff' => $count_diff ?? 0,
            'old_count' => $old_count ?? 0,
            'new_count' => $new_count ?? 0
        ]);
        
    } catch (Exception $e) {
        error_log("Error in updateRegistration: " . $e->getMessage());
        $this->conn->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to update registration: ' . $e->getMessage()
        ]);
    }
}
        
        // POST create schedule - /schedules/create
        public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);
        
        $data = json_decode(file_get_contents("php://input"));
        
        // Validate required fields
        $required_fields = ['barangay_id', 'title', 'scheduled_date', 'start_time', 'end_time', 'venue', 'schedule_type'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }
        
        try {
            $this->conn->beginTransaction();
            
            if ($data->schedule_type === 'vaccination') {
                // Validate vaccination-specific requirements
                if (!isset($data->vaccine_shot_limits) || empty($data->vaccine_shot_limits)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'At least one vaccination type must be selected']);
                    $this->conn->rollBack();
                    return;
                }
                
                // Create vaccination schedule
                $query = "INSERT INTO vaccination_schedules 
                        (barangay_id, title, description, scheduled_date, start_time, end_time, 
                        venue, max_capacity, vaccination_types, vaccine_shot_limits, pet_types_allowed, status, created_by) 
                        VALUES 
                        (:barangay_id, :title, :description, :scheduled_date, :start_time, :end_time,
                        :venue, :max_capacity, :vaccination_types, :vaccine_shot_limits, :pet_types_allowed, 'scheduled', :created_by)";
                
                // vaccine_shot_limits is the main data (vaccine_id => shot_limit)
                $vaccine_shot_limits_json = json_encode($data->vaccine_shot_limits);
                
                // vaccination_types should just be array of vaccine IDs for backward compatibility
                $vaccination_types_json = json_encode(array_keys((array)$data->vaccine_shot_limits));
                
                // Get pet_types_allowed from frontend (auto-calculated)
                $pet_types_allowed_json = isset($data->pet_types_allowed) && is_array($data->pet_types_allowed)
                    ? json_encode($data->pet_types_allowed)
                    : json_encode([]);
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':barangay_id', $data->barangay_id);
                $stmt->bindParam(':title', $data->title);
                $stmt->bindParam(':description', $data->description);
                $stmt->bindParam(':scheduled_date', $data->scheduled_date);
                $stmt->bindParam(':start_time', $data->start_time);
                $stmt->bindParam(':end_time', $data->end_time);
                $stmt->bindParam(':venue', $data->venue);
                $stmt->bindParam(':max_capacity', $data->max_capacity);
                $stmt->bindParam(':vaccination_types', $vaccination_types_json);
                $stmt->bindParam(':vaccine_shot_limits', $vaccine_shot_limits_json);
                $stmt->bindParam(':pet_types_allowed', $pet_types_allowed_json);
                $stmt->bindParam(':created_by', $user_data['user_id']);
                
            } else if ($data->schedule_type === 'deworming') {
        // Validate deworming-specific requirements
        if (!isset($data->max_capacity) || empty($data->max_capacity)) {
            http_response_code(400);
            echo json_encode(['error' => 'Max capacity is required for deworming']);
            $this->conn->rollBack();
            return;
        }
        
        if (!isset($data->pet_types_allowed) || !is_array($data->pet_types_allowed) || empty($data->pet_types_allowed)) {
            http_response_code(400);
            echo json_encode(['error' => 'At least one pet type must be selected for deworming']);
            $this->conn->rollBack();
            return;
        }
        
        // Create deworming schedule
        $query = "INSERT INTO deworming_schedules 
                (barangay_id, title, description, scheduled_date, start_time, end_time, 
                venue, max_capacity, pet_types_allowed, status, created_by) 
                VALUES 
                (:barangay_id, :title, :description, :scheduled_date, :start_time, :end_time,
                :venue, :max_capacity, :pet_types_allowed, 'scheduled', :created_by)";
        
        // Get pet_types_allowed from frontend
        $pet_types_allowed_json = json_encode($data->pet_types_allowed);
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':barangay_id', $data->barangay_id);
        $stmt->bindParam(':title', $data->title);
        $stmt->bindParam(':description', $data->description);
        $stmt->bindParam(':scheduled_date', $data->scheduled_date);
        $stmt->bindParam(':start_time', $data->start_time);
        $stmt->bindParam(':end_time', $data->end_time);
        $stmt->bindParam(':venue', $data->venue);
        $stmt->bindParam(':max_capacity', $data->max_capacity);
        $stmt->bindParam(':pet_types_allowed', $pet_types_allowed_json);
        $stmt->bindParam(':created_by', $user_data['user_id']);
                
            } else if ($data->schedule_type === 'seminar') {
    // Validate seminar-specific requirements
    if (!isset($data->max_capacity) || empty($data->max_capacity)) {
        http_response_code(400);
        echo json_encode(['error' => 'Max capacity is required for seminars']);
        $this->conn->rollBack();
        return;
    }
    
    // Create seminar schedule
    $query = "INSERT INTO seminar_schedules 
            (barangay_id, title, description, scheduled_date, start_time, end_time, 
            venue, speaker, max_capacity, status, created_by) 
            VALUES 
            (:barangay_id, :title, :description, :scheduled_date, :start_time, :end_time,
            :venue, :speaker, :max_capacity, 'scheduled', :created_by)";
    
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':barangay_id', $data->barangay_id);
    $stmt->bindParam(':title', $data->title);
    
    $description = $data->description ?? '';
    $stmt->bindParam(':description', $description);
    
    $stmt->bindParam(':scheduled_date', $data->scheduled_date);
    $stmt->bindParam(':start_time', $data->start_time);
    $stmt->bindParam(':end_time', $data->end_time);
    $stmt->bindParam(':venue', $data->venue);
    
    $speaker = $data->speaker ?? null;
    $stmt->bindParam(':speaker', $speaker);
    
    $stmt->bindParam(':max_capacity', $data->max_capacity);
    $stmt->bindParam(':created_by', $user_data['user_id']);
    
                
            } else if ($data->schedule_type === 'sterilization') {
                // Validate sterilization-specific requirements
                if (!isset($data->max_capacity) || empty($data->max_capacity)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Max capacity is required for sterilization']);
                    $this->conn->rollBack();
                    return;
                }
                
                if (!isset($data->sterilization_species) || !is_array($data->sterilization_species) || empty($data->sterilization_species)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'At least one pet type must be selected for sterilization']);
                    $this->conn->rollBack();
                    return;
                }
                
                // Create sterilization schedule
                $query = "INSERT INTO sterilization_schedules 
                        (barangay_id, title, description, scheduled_date, start_time, end_time, 
                        venue, max_capacity, sterilization_species, status, created_by) 
                        VALUES 
                        (:barangay_id, :title, :description, :scheduled_date, :start_time, :end_time,
                        :venue, :max_capacity, :sterilization_species, 'scheduled', :created_by)";
                
                // Get sterilization_species from frontend
                $sterilization_species_json = json_encode($data->sterilization_species);
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':barangay_id', $data->barangay_id);
                $stmt->bindParam(':title', $data->title);
                $stmt->bindParam(':description', $data->description);
                $stmt->bindParam(':scheduled_date', $data->scheduled_date);
                $stmt->bindParam(':start_time', $data->start_time);
                $stmt->bindParam(':end_time', $data->end_time);
                $stmt->bindParam(':venue', $data->venue);
                $stmt->bindParam(':max_capacity', $data->max_capacity);
                $stmt->bindParam(':sterilization_species', $sterilization_species_json);
                $stmt->bindParam(':created_by', $user_data['user_id']);
                
            } else if ($data->schedule_type === 'other') {
    // Validate other event-specific requirements
    if (!isset($data->max_capacity) || empty($data->max_capacity)) {
        http_response_code(400);
        echo json_encode(['error' => 'Max capacity is required for other events']);
        $this->conn->rollBack();
        return;
    }
    
    if (!isset($data->other_event_type) || empty($data->other_event_type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Event type is required for other events']);
        $this->conn->rollBack();
        return;
    }
    
    // Create other schedule
    $query = "INSERT INTO other_schedules 
            (barangay_id, title, description, other_event_type, scheduled_date, start_time, end_time, 
            venue, max_capacity, pet_types_allowed, status, created_by) 
            VALUES 
            (:barangay_id, :title, :description, :other_event_type, :scheduled_date, :start_time, :end_time,
            :venue, :max_capacity, :pet_types_allowed, 'scheduled', :created_by)";
    
    // Get pet_types_allowed from frontend (optional for other events)
    $pet_types_allowed_json = isset($data->pet_types_allowed) && is_array($data->pet_types_allowed)
        ? json_encode($data->pet_types_allowed)
        : json_encode([]);
    
    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':barangay_id', $data->barangay_id);
    $stmt->bindParam(':title', $data->title);
    $stmt->bindParam(':description', $data->description);
    $stmt->bindParam(':other_event_type', $data->other_event_type);
    $stmt->bindParam(':scheduled_date', $data->scheduled_date);
    $stmt->bindParam(':start_time', $data->start_time);
    $stmt->bindParam(':end_time', $data->end_time);
    $stmt->bindParam(':venue', $data->venue);
    $stmt->bindParam(':max_capacity', $data->max_capacity);
    $stmt->bindParam(':pet_types_allowed', $pet_types_allowed_json);
    $stmt->bindParam(':created_by', $user_data['user_id']);
    
} else if ($data->schedule_type === 'microchip') {
    if (!isset($data->max_capacity) || empty($data->max_capacity)) {
        http_response_code(400);
        echo json_encode(['error' => 'Max capacity is required for microchipping']);
        $this->conn->rollBack();
        return;
    }

    $query = "INSERT INTO microchip_schedules
            (barangay_id, title, description, scheduled_date, start_time, end_time,
            venue, max_capacity, pet_types_allowed, status, created_by)
            VALUES
            (:barangay_id, :title, :description, :scheduled_date, :start_time, :end_time,
            :venue, :max_capacity, :pet_types_allowed, 'scheduled', :created_by)";

    $pet_types_allowed_json = isset($data->pet_types_allowed) && is_array($data->pet_types_allowed)
        ? json_encode($data->pet_types_allowed)
        : json_encode([]);

    $stmt = $this->conn->prepare($query);
    $stmt->bindParam(':barangay_id', $data->barangay_id);
    $stmt->bindParam(':title', $data->title);
    $stmt->bindParam(':description', $data->description);
    $stmt->bindParam(':scheduled_date', $data->scheduled_date);
    $stmt->bindParam(':start_time', $data->start_time);
    $stmt->bindParam(':end_time', $data->end_time);
    $stmt->bindParam(':venue', $data->venue);
    $stmt->bindParam(':max_capacity', $data->max_capacity);
    $stmt->bindParam(':pet_types_allowed', $pet_types_allowed_json);
    $stmt->bindParam(':created_by', $user_data['user_id']);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid schedule type. Must be vaccination, deworming, seminar, sterilization, other, or microchip']);
    $this->conn->rollBack();
    return;
}
            
            if ($stmt->execute()) {
                $schedule_id = $this->conn->lastInsertId();
                $this->conn->commit();
                
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => ucfirst($data->schedule_type) . ' schedule created successfully',
                    'id' => $schedule_id,
                    'schedule_id' => $schedule_id
                ]);
            } else {
                throw new Exception('Failed to create schedule');
            }
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Schedule creation failed: ' . $e->getMessage()
            ]);
        }
    }
        
        // GET schedule by ID - /schedules/show/1
        public function show($id) {
            $user_data = $this->auth->authenticate();
            $this->updateScheduleStatuses();
            
            try {
                // Try vaccination schedule first
                $query = "SELECT vs.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name,
                        'vaccination' as type
                        FROM vaccination_schedules vs
                        LEFT JOIN barangays b ON vs.barangay_id = b.id
                        LEFT JOIN users u ON vs.created_by = u.id
                        WHERE vs.id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($schedule['vaccination_types']) {
                        $schedule['vaccination_types'] = json_decode($schedule['vaccination_types'], true);
                    }
                    if ($schedule['vaccine_shot_limits']) {
                        $schedule['vaccine_shot_limits'] = json_decode($schedule['vaccine_shot_limits'], true);
                    }
                    if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
                        $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
                    } else {
                        $schedule['pet_types_allowed'] = [];
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'schedule' => $schedule
                    ]);
                    return;
                }
                
                // Try seminar schedule
                $query = "SELECT ss.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name,
                        'seminar' as type
                        FROM seminar_schedules ss
                        LEFT JOIN barangays b ON ss.barangay_id = b.id
                        LEFT JOIN users u ON ss.created_by = u.id
                        WHERE ss.id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'schedule' => $schedule
                    ]);
                    return;
                }
                
                // Try sterilization schedule
                $query = "SELECT st.*, 
                        b.id as barangay_id,
                        b.name as barangay_name,
                        u.first_name as created_by_first_name,
                        u.last_name as created_by_last_name,
                        'sterilization' as type
                        FROM sterilization_schedules st
                        LEFT JOIN barangays b ON st.barangay_id = b.id
                        LEFT JOIN users u ON st.created_by = u.id
                        WHERE st.id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (isset($schedule['sterilization_species']) && $schedule['sterilization_species']) {
        $schedule['sterilization_species'] = json_decode($schedule['sterilization_species'], true);
    } else {
        $schedule['sterilization_species'] = [];
    }
    
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
    return;
}

// Try deworming schedule
$query = "SELECT ds.*, 
        b.id as barangay_id,
        b.name as barangay_name,
        u.first_name as created_by_first_name,
        u.last_name as created_by_last_name,
        'deworming' as type
        FROM deworming_schedules ds
        LEFT JOIN barangays b ON ds.barangay_id = b.id
        LEFT JOIN users u ON ds.created_by = u.id
        WHERE ds.id = :id";

$stmt = $this->conn->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
        $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
    } else {
        $schedule['pet_types_allowed'] = [];
    }
    
    echo json_encode([
        'success' => true,
        'schedule' => $schedule
    ]);
    return;
}

// Try other schedule
$query = "SELECT os.*,
        b.id as barangay_id,
        b.name as barangay_name,
        u.first_name as created_by_first_name,
        u.last_name as created_by_last_name,
        'other' as type
        FROM other_schedules os
        LEFT JOIN barangays b ON os.barangay_id = b.id
        LEFT JOIN users u ON os.created_by = u.id
        WHERE os.id = :id";

$stmt = $this->conn->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
        $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
    } else {
        $schedule['pet_types_allowed'] = [];
    }
    echo json_encode(['success' => true, 'schedule' => $schedule]);
    return;
}

// Try microchip schedule
$query = "SELECT ms.*,
        b.id as barangay_id,
        b.name as barangay_name,
        u.first_name as created_by_first_name,
        u.last_name as created_by_last_name,
        'microchip' as type
        FROM microchip_schedules ms
        LEFT JOIN barangays b ON ms.barangay_id = b.id
        LEFT JOIN users u ON ms.created_by = u.id
        WHERE ms.id = :id";
$stmt = $this->conn->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();

if ($stmt->rowCount() > 0) {
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (isset($schedule['pet_types_allowed']) && $schedule['pet_types_allowed']) {
        $schedule['pet_types_allowed'] = json_decode($schedule['pet_types_allowed'], true);
    } else {
        $schedule['pet_types_allowed'] = [];
    }
    echo json_encode(['success' => true, 'schedule' => $schedule]);
    return;
}

            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to get schedule: ' . $e->getMessage()
                ]);
            }
        }
        
        // PUT update schedule - /schedules/update/1
        // PUT update schedule - /schedules/update/1
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
            // Determine which table to update
            $table = 'vaccination_schedules';
            if (isset($data->schedule_type)) {
    if ($data->schedule_type === 'vaccination') {
        $table = 'vaccination_schedules';
    } else if ($data->schedule_type === 'deworming') {
        $table = 'deworming_schedules';
    } else if ($data->schedule_type === 'seminar') {
        $table = 'seminar_schedules';
    } else if ($data->schedule_type === 'sterilization') {
        $table = 'sterilization_schedules';
    } else if ($data->schedule_type === 'other') {
        $table = 'other_schedules';
    } else if ($data->schedule_type === 'microchip') {
        $table = 'microchip_schedules';
    }
}
            
            $update_fields = [];
            $params = [':id' => $id];
            
            $allowed_fields = ['title', 'description', 'scheduled_date', 'start_time', 
                            'end_time', 'venue', 'max_capacity', 'status'];
            
            foreach ($allowed_fields as $field) {
                if (isset($data->$field)) {
                    $update_fields[] = "$field = :$field";
                    $params[":$field"] = $data->$field;
                }
            }
            
            // Handle vaccine shot limits for vaccination schedules
            if ($table === 'vaccination_schedules') {
                if (isset($data->vaccine_shot_limits)) {
                    $update_fields[] = "vaccine_shot_limits = :vaccine_shot_limits";
                    $params[":vaccine_shot_limits"] = json_encode($data->vaccine_shot_limits);
                    
                    $update_fields[] = "vaccination_types = :vaccination_types";
                    $params[":vaccination_types"] = json_encode(array_keys((array)$data->vaccine_shot_limits));
                }
                
                if (isset($data->pet_types_allowed)) {
                    $update_fields[] = "pet_types_allowed = :pet_types_allowed";
                    $params[":pet_types_allowed"] = is_array($data->pet_types_allowed) 
                        ? json_encode($data->pet_types_allowed)
                        : json_encode([]);
                }
            }
            
            // Handle deworming limits for deworming schedules
            // Handle pet_types_allowed for deworming schedules
    if ($table === 'deworming_schedules') {
        if (isset($data->pet_types_allowed)) {
            $update_fields[] = "pet_types_allowed = :pet_types_allowed";
            $params[":pet_types_allowed"] = is_array($data->pet_types_allowed) 
                ? json_encode($data->pet_types_allowed)
                : json_encode([]);
        }
    }
            
            // Handle speaker for seminar schedules
            if ($table === 'seminar_schedules' && isset($data->speaker)) {
                $update_fields[] = "speaker = :speaker";
                $params[":speaker"] = $data->speaker;
            }
            
            // Handle sterilization_species for sterilization schedules
            if ($table === 'sterilization_schedules' && isset($data->sterilization_species)) {
                $update_fields[] = "sterilization_species = :sterilization_species";
                $params[":sterilization_species"] = is_array($data->sterilization_species)
                    ? json_encode($data->sterilization_species)
                    : json_encode([]);
            }
            // Handle pet_types_allowed for microchip schedules
if ($table === 'microchip_schedules') {
    if (isset($data->pet_types_allowed)) {
        $update_fields[] = "pet_types_allowed = :pet_types_allowed";
        $params[":pet_types_allowed"] = is_array($data->pet_types_allowed)
            ? json_encode($data->pet_types_allowed)
            : json_encode([]);
    }
}
            // Handle other event type and pet types for other schedules
if ($table === 'other_schedules') {
    if (isset($data->other_event_type)) {
        $update_fields[] = "other_event_type = :other_event_type";
        $params[":other_event_type"] = $data->other_event_type;
    }
    
    if (isset($data->pet_types_allowed)) {
        $update_fields[] = "pet_types_allowed = :pet_types_allowed";
        $params[":pet_types_allowed"] = is_array($data->pet_types_allowed) 
            ? json_encode($data->pet_types_allowed)
            : json_encode([]);
    }
}
            
            if (empty($update_fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }
            
            $query = "UPDATE $table SET " . implode(', ', $update_fields) . 
                    ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            
            $stmt = $this->conn->prepare($query);
            
            if ($stmt->execute($params)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Schedule updated successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Schedule update failed']);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Schedule update failed: ' . $e->getMessage()
            ]);
        }
    }
        
        // DELETE schedule - /schedules/delete/1
        public function delete($id) {
            if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                return;
            }
            
            $user_data = $this->auth->authenticate();
            $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);
            
            try {
                $tables = [
    'vaccination_schedules'   => 'Vaccination',
    'seminar_schedules'       => 'Seminar',
    'sterilization_schedules' => 'Sterilization',
    'deworming_schedules'     => 'Deworming',
    'microchip_schedules'     => 'Microchip',
    'other_schedules'         => 'Other',
];

foreach ($tables as $table => $label) {
                    $query = "DELETE FROM {$table} WHERE id = :id";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                    $stmt->execute();

                    if ($stmt->rowCount() > 0) {
                        echo json_encode([
                            'success' => true,
                            'message' => "{$label} schedule deleted successfully"
                        ]);
                        return;
                    }
                }

                http_response_code(404);
                echo json_encode(['error' => 'Schedule not found']);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Schedule deletion failed: ' . $e->getMessage()
                ]);
            }
        }
    }
    ?>