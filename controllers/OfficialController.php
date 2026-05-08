<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class OfficialController {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    public function index() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);
        
        try {
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code,
         bo.first_name, bo.middle_name, bo.last_name, bo.age, bo.gender, bo.phone, bo.office_role,
         COUNT(DISTINCT vr.id) as vaccinations_administered
         FROM users u
         JOIN barangay_officials bo ON u.id = bo.user_id
         LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
         LEFT JOIN vaccination_records vr ON u.id = vr.administered_by
         WHERE u.role = 'barangay_official'
         GROUP BY u.id
         ORDER BY b.name ASC, bo.first_name ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $officials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($officials as &$official) {
                unset($official['password']);
                // Set default values for fields that don't exist in database
                $official['position'] = 'Barangay Official';
                $official['department'] = null;
            }
            
            echo json_encode([
                'success' => true,
                'officials' => $officials,
                'total' => count($officials)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get officials: ' . $e->getMessage()]);
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
    
    // Debug logging
    error_log("=== OFFICIAL CREATE DEBUG ===");
    error_log("Received data: " . json_encode($data));
    
    $required_fields = ['username', 'email', 'password', 'first_name', 'last_name', 'assigned_barangay_id'];
    foreach ($required_fields as $field) {
        if (!isset($data->$field) || empty($data->$field)) {
            http_response_code(400);
            echo json_encode(['error' => ucfirst($field) . ' is required']);
            return;
        }
    }
    
    if (!$this->auth->validateEmail($data->email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email format']);
        return;
    }
    
    // Validate gender if provided
    if (isset($data->gender) && !in_array($data->gender, ['Male', 'Female', 'Other'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid gender value. Must be Male, Female, or Other']);
        return;
    }
    
    try {
        $check_user_query = "SELECT id FROM users WHERE username = :username OR email = :email";
        $check_user_stmt = $this->conn->prepare($check_user_query);
        $check_user_stmt->bindParam(':username', $data->username);
        $check_user_stmt->bindParam(':email', $data->email);
        $check_user_stmt->execute();
        
        if ($check_user_stmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Username or email already exists']);
            return;
        }
        
        $check_barangay_query = "SELECT id FROM barangays WHERE id = :barangay_id AND is_active = 1";
        $check_barangay_stmt = $this->conn->prepare($check_barangay_query);
        $check_barangay_stmt->bindParam(':barangay_id', $data->assigned_barangay_id);
        $check_barangay_stmt->execute();
        
        if ($check_barangay_stmt->rowCount() === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid barangay assignment']);
            return;
        }
        
        $this->conn->beginTransaction();
        
        $hashed_password = $this->auth->hashPassword($data->password);
        
        // Insert into users table - NO middle_name or gender here!
        $user_query = "INSERT INTO users (username, email, password, first_name, last_name, role, assigned_barangay_id, phone) 
                      VALUES (:username, :email, :password, :first_name, :last_name, 'barangay_official', :assigned_barangay_id, :phone)";
        
        $user_stmt = $this->conn->prepare($user_query);
        $user_stmt->bindParam(':username', $data->username);
        $user_stmt->bindParam(':email', $data->email);
        $user_stmt->bindParam(':password', $hashed_password);
        $user_stmt->bindParam(':first_name', $data->first_name);
        $user_stmt->bindParam(':last_name', $data->last_name);
        $user_stmt->bindParam(':assigned_barangay_id', $data->assigned_barangay_id);
        $phone = $data->phone ?? null;
        $user_stmt->bindParam(':phone', $phone);
        
        if (!$user_stmt->execute()) {
            $this->conn->rollback();
            error_log("User insert failed: " . print_r($user_stmt->errorInfo(), true));
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create user account', 'debug' => $user_stmt->errorInfo()]);
            return;
        }
        
        $user_id = $this->conn->lastInsertId();
        error_log("User created with ID: " . $user_id);
        
        // Insert into barangay_officials table - middle_name and gender go here!
        $official_query = "INSERT INTO barangay_officials (user_id, first_name, middle_name, last_name, age, gender, phone, office_role, barangay_id, is_active) 
                          VALUES (:user_id, :first_name, :middle_name, :last_name, :age, :gender, :phone, :office_role, NULL, 1)";
        
        $official_stmt = $this->conn->prepare($official_query);
        $official_stmt->bindParam(':user_id', $user_id);
        $official_stmt->bindParam(':first_name', $data->first_name);
        $middle_name = $data->middle_name ?? null;
        $official_stmt->bindParam(':middle_name', $middle_name);
        $official_stmt->bindParam(':last_name', $data->last_name);
        $age = $data->age ?? null;
        $official_stmt->bindParam(':age', $age);
        $gender = $data->gender ?? null;
        $official_stmt->bindParam(':gender', $gender);
        $phone = $data->phone ?? null;
        $official_stmt->bindParam(':phone', $phone);
        $office_role = $data->office_role ?? null;
        $official_stmt->bindParam(':office_role', $office_role);
        
        if (!$official_stmt->execute()) {
            $this->conn->rollback();
            error_log("Official insert failed: " . print_r($official_stmt->errorInfo(), true));
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create official details', 'debug' => $official_stmt->errorInfo()]);
            return;
        }
        
        $this->conn->commit();
        error_log("Transaction committed successfully!");
        
        // Get the created official details
        $get_official_query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code,
                              bo.first_name, bo.middle_name, bo.last_name, bo.age, bo.gender, bo.phone
                              FROM users u
                              JOIN barangay_officials bo ON u.id = bo.user_id
                              LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                              WHERE u.id = :user_id";
        
        $get_official_stmt = $this->conn->prepare($get_official_query);
        $get_official_stmt->bindParam(':user_id', $user_id);
        $get_official_stmt->execute();
        
        $official = $get_official_stmt->fetch(PDO::FETCH_ASSOC);
        unset($official['password']);
        
        // Add default values
        $official['position'] = 'Barangay Official';
        $official['department'] = null;
        
        echo json_encode([
            'success' => true,
            'message' => 'Official created successfully',
            'official' => $official
        ]);
        
    } catch (Exception $e) {
        if ($this->conn->inTransaction()) {
            $this->conn->rollback();
        }
        error_log("Exception in create: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Official creation failed: ' . $e->getMessage()]);
    }
}
    
    public function show($id) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);
        
        try {
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code,
                     bo.first_name, bo.middle_name, bo.last_name, bo.age, bo.gender, bo.phone,
                     COUNT(DISTINCT vr.id) as total_vaccinations_administered,
                     COUNT(DISTINCT vs.id) as vaccination_schedules_created,
                     COUNT(DISTINCT ss.id) as seminar_schedules_created
                     FROM users u
                     JOIN barangay_officials bo ON u.id = bo.user_id
                     LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                     LEFT JOIN vaccination_records vr ON u.id = vr.administered_by
                     LEFT JOIN vaccination_schedules vs ON u.id = vs.created_by
                     LEFT JOIN seminar_schedules ss ON u.id = ss.created_by
                     WHERE u.id = :official_id AND u.role = 'barangay_official' AND u.is_active = 1 AND bo.is_active = 1
                     GROUP BY u.id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':official_id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $official = $stmt->fetch(PDO::FETCH_ASSOC);
                unset($official['password']);
                
                // Add default values
                $official['position'] = 'Barangay Official';
                $official['department'] = null;
                
                $recent_activity_query = "SELECT 'vaccination' as activity_type, vr.vaccination_date as activity_date, 
                                         CONCAT('Vaccinated pet: ', p.name, ' (', p.registration_number, ')') as description
                                         FROM vaccination_records vr
                                         JOIN pets p ON vr.pet_id = p.id
                                         WHERE vr.administered_by = :official_id
                                         UNION ALL
                                         SELECT 'schedule_vaccination' as activity_type, vs.scheduled_date as activity_date,
                                         CONCAT('Created vaccination schedule: ', vs.title) as description
                                         FROM vaccination_schedules vs
                                         WHERE vs.created_by = :official_id
                                         UNION ALL
                                         SELECT 'schedule_seminar' as activity_type, ss.scheduled_date as activity_date,
                                         CONCAT('Created seminar schedule: ', ss.title) as description
                                         FROM seminar_schedules ss
                                         WHERE ss.created_by = :official_id
                                         ORDER BY activity_date DESC LIMIT 10";
                
                $recent_activity_stmt = $this->conn->prepare($recent_activity_query);
                $recent_activity_stmt->bindParam(':official_id', $id);
                $recent_activity_stmt->execute();
                
                $official['recent_activity'] = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'success' => true,
                    'official' => $official
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Official not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get official details: ' . $e->getMessage()]);
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
        
        // Validate gender if provided
        if (isset($data->gender) && !in_array($data->gender, ['Male', 'Female', 'Other'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid gender value. Must be Male, Female, or Other']);
            return;
        }
        
        try {
            $check_query = "SELECT u.id FROM users u 
                           JOIN barangay_officials bo ON u.id = bo.user_id
                           WHERE u.id = :official_id AND u.role = 'barangay_official' AND u.is_active = 1";
            
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':official_id', $id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Official not found']);
                return;
            }
            
            if (isset($data->email) && !$this->auth->validateEmail($data->email)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email format']);
                return;
            }
            
            if (isset($data->username) || isset($data->email)) {
                $duplicate_query = "SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :official_id";
                $duplicate_stmt = $this->conn->prepare($duplicate_query);
                $duplicate_stmt->bindParam(':username', $data->username ?? '');
                $duplicate_stmt->bindParam(':email', $data->email ?? '');
                $duplicate_stmt->bindParam(':official_id', $id);
                $duplicate_stmt->execute();
                
                if ($duplicate_stmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Username or email already exists']);
                    return;
                }
            }
            
            $this->conn->beginTransaction();
            
            // Update users table
            $user_update_fields = [];
            $user_params = [':user_id' => $id];
            
            // If last_name is being updated, regenerate email and username
            if (isset($data->last_name)) {
                // Get the current email to extract the ID
                $get_email_query = "SELECT email FROM users WHERE id = :user_id";
                $get_email_stmt = $this->conn->prepare($get_email_query);
                $get_email_stmt->bindParam(':user_id', $id);
                $get_email_stmt->execute();
                $current_email = $get_email_stmt->fetch(PDO::FETCH_ASSOC)['email'];
                
                // Extract the year and number from current email (e.g., "2025001" from "oldname.2025001@muntinlupa.gov.ph")
                preg_match('/\.(\d+)@/', $current_email, $matches);
                $official_id = $matches[1] ?? '';
                
                if ($official_id) {
                    // Generate new email and username with the same ID
                    $new_email = strtolower($data->last_name) . '.' . $official_id . '@muntinlupa.gov.ph';
                    $new_username = strtolower($data->last_name) . '.' . $official_id;
                    
                    $user_update_fields[] = "email = :email";
                    $user_params[":email"] = $new_email;
                    
                    $user_update_fields[] = "username = :username";
                    $user_params[":username"] = $new_username;
                }
            }
            
            $user_allowed_fields = ['first_name', 'last_name', 'assigned_barangay_id', 'phone', 'address'];
            
            foreach ($user_allowed_fields as $field) {
                if (isset($data->$field)) {
                    $user_update_fields[] = "$field = :$field";
                    $user_params[":$field"] = $data->$field;
                }
            }
            
            if (isset($data->password)) {
                $password_validation = $this->auth->validatePassword($data->password);
                if (!$password_validation['is_valid']) {
                    $this->conn->rollback();
                    http_response_code(400);
                    echo json_encode(['error' => 'Password validation failed', 'details' => $password_validation['errors']]);
                    return;
                }
                $user_update_fields[] = "password = :password";
                $user_params[":password"] = $this->auth->hashPassword($data->password);
            }
            
            if (!empty($user_update_fields)) {
                $user_query = "UPDATE users SET " . implode(', ', $user_update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
                $user_stmt = $this->conn->prepare($user_query);
                $user_stmt->execute($user_params);
            }
            
            // Update barangay_officials table with gender column
            $official_update_fields = [];
            $official_params = [':user_id' => $id];
            
            // Update fields including the gender column
            $official_allowed_fields = ['first_name', 'middle_name', 'last_name', 'age', 'gender', 'phone', 'office_role'];
            
            foreach ($official_allowed_fields as $field) {
                if (isset($data->$field)) {
                    $official_update_fields[] = "$field = :$field";
                    $official_params[":$field"] = $data->$field;
                }
            }
            
            // Update barangay_id if assigned_barangay_id is provided
            if (isset($data->assigned_barangay_id)) {
                $official_update_fields[] = "barangay_id = :barangay_id";
                $official_params[":barangay_id"] = $data->assigned_barangay_id;
            }
            
            if (!empty($official_update_fields)) {
                $official_query = "UPDATE barangay_officials SET " . implode(', ', $official_update_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
                $official_stmt = $this->conn->prepare($official_query);
                $official_stmt->execute($official_params);
            }
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Official updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Official update failed: ' . $e->getMessage()]);
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
            $check_activities_query = "SELECT 
                                      COUNT(vr.id) as vaccination_count,
                                      COUNT(vs.id) as vaccination_schedule_count,
                                      COUNT(ss.id) as seminar_schedule_count
                                      FROM users u
                                      LEFT JOIN vaccination_records vr ON u.id = vr.administered_by
                                      LEFT JOIN vaccination_schedules vs ON u.id = vs.created_by
                                      LEFT JOIN seminar_schedules ss ON u.id = ss.created_by
                                      WHERE u.id = :official_id";
            
            $check_activities_stmt = $this->conn->prepare($check_activities_query);
            $check_activities_stmt->bindParam(':official_id', $id);
            $check_activities_stmt->execute();
            
            $activities = $check_activities_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($activities['vaccination_count'] > 0 || $activities['vaccination_schedule_count'] > 0 || $activities['seminar_schedule_count'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete official with existing activities. Please deactivate instead.']);
                return;
            }
            
            // Deactivate both users and barangay_officials records
            $this->conn->beginTransaction();
            
            $user_query = "UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :official_id AND role = 'barangay_official'";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':official_id', $id);
            
            $official_query = "UPDATE barangay_officials SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE user_id = :official_id";
            $official_stmt = $this->conn->prepare($official_query);
            $official_stmt->bindParam(':official_id', $id);
            
            if ($user_stmt->execute() && $official_stmt->execute() && $user_stmt->rowCount() > 0) {
                $this->conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Official deactivated successfully'
                ]);
            } else {
                $this->conn->rollback();
                http_response_code(404);
                echo json_encode(['error' => 'Official not found']);
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Official deletion failed: ' . $e->getMessage()]);
        }
    }
    public function restore($id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['super_admin'], $user_data);
    
    try {
        $this->conn->beginTransaction();
        
        $user_query = "UPDATE users SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :official_id AND role = 'barangay_official'";
        $user_stmt = $this->conn->prepare($user_query);
        $user_stmt->bindParam(':official_id', $id);
        
        $official_query = "UPDATE barangay_officials SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE user_id = :official_id";
        $official_stmt = $this->conn->prepare($official_query);
        $official_stmt->bindParam(':official_id', $id);
        
        if ($user_stmt->execute() && $official_stmt->execute() && $user_stmt->rowCount() > 0) {
            $this->conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Official restored successfully'
            ]);
        } else {
            $this->conn->rollback();
            http_response_code(404);
            echo json_encode(['error' => 'Official not found']);
        }
    } catch (Exception $e) {
        $this->conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Official restoration failed: ' . $e->getMessage()]);
    }
}
    
    public function byBarangay($barangay_id) {
        $user_data = $this->auth->authenticate();
        
        try {
            $query = "SELECT u.id, bo.first_name, bo.middle_name, bo.last_name, u.email, bo.phone, bo.age, bo.gender
                     FROM users u
                     JOIN barangay_officials bo ON u.id = bo.user_id
                     WHERE u.assigned_barangay_id = :barangay_id AND u.is_active = 1 AND bo.is_active = 1
                     ORDER BY bo.first_name ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':barangay_id', $barangay_id);
            $stmt->execute();
            
            $officials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add default values
            foreach ($officials as &$official) {
                $official['position'] = 'Barangay Official';
                $official['department'] = null;
            }
            
            echo json_encode([
                'success' => true,
                'officials' => $officials,
                'total' => count($officials)
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get barangay officials: ' . $e->getMessage()]);
        }
    }
}
?>