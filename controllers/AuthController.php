<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

class AuthController {
    private $conn;
    private $auth;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }
    
    /**
     * User Login
     * POST /api/auth/login
     */
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        // Validate required fields
        if (!isset($data->username) || empty($data->username)) {
            http_response_code(400);
            echo json_encode(['error' => 'Username is required']);
            return;
        }
        
        if (!isset($data->password) || empty($data->password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Password is required']);
            return;
        }
        
        try {
            // Get user by username
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code,
 bo.office_role,
 pc.clinic_name, pc.owner_name as clinic_owner_name
 FROM users u
 LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
 LEFT JOIN barangay_officials bo ON u.id = bo.user_id
LEFT JOIN private_clinics pc ON CAST(u.id AS UNSIGNED) = CAST(pc.user_id AS UNSIGNED)
 WHERE u.username = :username AND u.is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $data->username);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid username or password']);
                return;
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (!$this->auth->verifyPassword($data->password, $user['password'])) {
                http_response_code(401);
                echo json_encode(['error' => 'Invalid username or password']);
                return;
            }
            
            // Get role-specific details
            $user_details = null;
            
            if ($user['role'] === 'pet_owner') {
    $owner_query = "SELECT * FROM pet_owners WHERE user_id = :user_id";
    $owner_stmt = $this->conn->prepare($owner_query);
    $owner_stmt->bindParam(':user_id', $user['id']);
    $owner_stmt->execute();
    $user_details = $owner_stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'barangay_official') {
    $official_query = "SELECT * FROM barangay_officials WHERE user_id = :user_id";
    $official_stmt = $this->conn->prepare($official_query);
    $official_stmt->bindParam(':user_id', $user['id']);
    $official_stmt->execute();
    $user_details = $official_stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'private_clinic') {
    $clinic_query = "SELECT * FROM private_clinics WHERE user_id = :user_id";
    $clinic_stmt = $this->conn->prepare($clinic_query);
    $clinic_user_id = (int) $user['id'];
    $clinic_stmt->bindParam(':user_id', $clinic_user_id, PDO::PARAM_INT);
    $clinic_stmt->execute();
    $user_details = $clinic_stmt->fetch(PDO::FETCH_ASSOC);
}
            
            // Generate JWT token - FIXED: Pass individual parameters, not array
            $token = $this->auth->generateToken(
    $user['id'],
    $user['role'],
    $user['assigned_barangay_id'],
    $user['office_role'] ?? null
);
            
            // Remove sensitive data
            unset($user['password']);
            
            // Update last login
            $update_login_query = "UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
            $update_login_stmt = $this->conn->prepare($update_login_query);
            $update_login_stmt->bindParam(':user_id', $user['id']);
            $update_login_stmt->execute();
            
            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user,
                'user_details' => $user_details
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Login failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * User Registration (Pet Owner only)
     * POST /api/auth/register
     */
    public function register() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        // Validate required fields
        $required_fields = ['username', 'email', 'password', 'first_name', 'last_name', 'phone'];
        
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }
        
        // Validate email format
        if (!$this->auth->validateEmail($data->email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email format']);
            return;
        }
        
        // Validate password strength
        $password_validation = $this->auth->validatePassword($data->password);
        if (!$password_validation['is_valid']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Password validation failed',
                'details' => $password_validation['errors']
            ]);
            return;
        }
        
        try {
            // Check if username or email already exists
            $check_query = "SELECT id FROM users WHERE username = :username OR email = :email";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':username', $data->username);
            $check_stmt->bindParam(':email', $data->email);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Username or email already exists']);
                return;
            }
            
            $this->conn->beginTransaction();
            
            // Hash password
            $hashed_password = $this->auth->hashPassword($data->password);
            
            // Insert into users table
            $user_query = "INSERT INTO users (username, email, password, first_name, last_name, role, phone) 
                          VALUES (:username, :email, :password, :first_name, :last_name, 'pet_owner', :phone)";
            
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':username', $data->username);
            $user_stmt->bindParam(':email', $data->email);
            $user_stmt->bindParam(':password', $hashed_password);
            $user_stmt->bindParam(':first_name', $data->first_name);
            $user_stmt->bindParam(':last_name', $data->last_name);
            $user_stmt->bindParam(':phone', $data->phone);
            
            if (!$user_stmt->execute()) {
                $this->conn->rollback();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create user account']);
                return;
            }
            
            $user_id = $this->conn->lastInsertId();
            
            // Insert into pet_owners table
            $owner_query = "INSERT INTO pet_owners (user_id, first_name, last_name, phone) 
                           VALUES (:user_id, :first_name, :last_name, :phone)";
            
            $owner_stmt = $this->conn->prepare($owner_query);
            $owner_stmt->bindParam(':user_id', $user_id);
            $owner_stmt->bindParam(':first_name', $data->first_name);
            $owner_stmt->bindParam(':last_name', $data->last_name);
            $owner_stmt->bindParam(':phone', $data->phone);
            
            if (!$owner_stmt->execute()) {
                $this->conn->rollback();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create pet owner profile']);
                return;
            }
            
            $this->conn->commit();
            
            // Generate token for auto-login - FIXED: Pass individual parameters
            $token = $this->auth->generateToken(
                $user_id,
                'pet_owner',
                null
            );
            
            // Get created user details
            $get_user_query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code
                              FROM users u
                              LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                              WHERE u.id = :user_id";
            
            $get_user_stmt = $this->conn->prepare($get_user_query);
            $get_user_stmt->bindParam(':user_id', $user_id);
            $get_user_stmt->execute();
            
            $user = $get_user_stmt->fetch(PDO::FETCH_ASSOC);
            unset($user['password']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Registration successful',
                'token' => $token,
                'user' => $user
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Get Current User Profile
     * GET /api/auth/me
     */
    public function me() {
        try {
            $user_data = $this->auth->authenticate();
            
            // Get full user details
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code
                     FROM users u
                     LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                     WHERE u.id = :user_id AND u.is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            unset($user['password']);
            
            // Get role-specific details
            $user_details = null;
            
            if ($user['role'] === 'pet_owner') {
                $owner_query = "SELECT po.*, COUNT(DISTINCT p.id) as total_pets
                               FROM pet_owners po
                               LEFT JOIN pets p ON po.id = p.owner_id AND p.is_active = 1
                               WHERE po.user_id = :user_id
                               GROUP BY po.id";
                
                $owner_stmt = $this->conn->prepare($owner_query);
                $owner_stmt->bindParam(':user_id', $user['id']);
                $owner_stmt->execute();
                $user_details = $owner_stmt->fetch(PDO::FETCH_ASSOC);
                
            } elseif ($user['role'] === 'barangay_official') {
                $official_query = "SELECT bo.*,
                                  COUNT(DISTINCT vr.id) as total_vaccinations_administered
                                  FROM barangay_officials bo
                                  LEFT JOIN vaccination_records vr ON bo.user_id = vr.administered_by
                                  WHERE bo.user_id = :user_id
                                  GROUP BY bo.id";
                
                $official_stmt = $this->conn->prepare($official_query);
                $official_stmt->bindParam(':user_id', $user['id']);
                $official_stmt->execute();
                $user_details = $official_stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            echo json_encode([
                'success' => true,
                'user' => $user,
                'user_details' => $user_details
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get user profile: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Update Current User Profile
     * PUT /api/auth/profile
     */
    public function updateProfile() {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        try {
            $user_data = $this->auth->authenticate();
            $data = json_decode(file_get_contents("php://input"));
            
            // Validate email if provided
            if (isset($data->email) && !$this->auth->validateEmail($data->email)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email format']);
                return;
            }
            
            // Check for duplicate username/email
            if (isset($data->username) || isset($data->email)) {
                $duplicate_query = "SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :user_id";
                $duplicate_stmt = $this->conn->prepare($duplicate_query);
                $username_check = isset($data->username) ? $data->username : '';
                $email_check = isset($data->email) ? $data->email : '';
                $duplicate_stmt->bindParam(':username', $username_check);
                $duplicate_stmt->bindParam(':email', $email_check);
                $duplicate_stmt->bindParam(':user_id', $user_data['user_id']);
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
            $user_params = [':user_id' => $user_data['user_id']];
            
            $user_allowed_fields = ['username', 'email', 'first_name', 'last_name', 'phone', 'address'];
            
            foreach ($user_allowed_fields as $field) {
                if (isset($data->$field)) {
                    $user_update_fields[] = "$field = :$field";
                    $user_params[":$field"] = $data->$field;
                }
            }
            
            // Handle password change
            if (isset($data->password) && !empty($data->password)) {
                $password_validation = $this->auth->validatePassword($data->password);
                if (!$password_validation['is_valid']) {
                    $this->conn->rollback();
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Password validation failed',
                        'details' => $password_validation['errors']
                    ]);
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
            
            // Update role-specific table
            if ($user_data['role'] === 'pet_owner') {
                $owner_update_fields = [];
                $owner_params = [':user_id' => $user_data['user_id']];
                
                $owner_allowed_fields = ['first_name', 'last_name', 'phone'];
                
                foreach ($owner_allowed_fields as $field) {
                    if (isset($data->$field)) {
                        $owner_update_fields[] = "$field = :$field";
                        $owner_params[":$field"] = $data->$field;
                    }
                }
                
                if (!empty($owner_update_fields)) {
                    $owner_query = "UPDATE pet_owners SET " . implode(', ', $owner_update_fields) . " WHERE user_id = :user_id";
                    $owner_stmt = $this->conn->prepare($owner_query);
                    $owner_stmt->execute($owner_params);
                }
            }
            
            $this->conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile updated successfully'
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            http_response_code(500);
            echo json_encode(['error' => 'Profile update failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Change Password
     * POST /api/auth/change-password
     */
    public function changePassword() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        try {
            $user_data = $this->auth->authenticate();
            $data = json_decode(file_get_contents("php://input"));
            
            // Validate required fields
            if (!isset($data->current_password) || empty($data->current_password)) {
                http_response_code(400);
                echo json_encode(['error' => 'Current password is required']);
                return;
            }
            
            if (!isset($data->new_password) || empty($data->new_password)) {
                http_response_code(400);
                echo json_encode(['error' => 'New password is required']);
                return;
            }
            
            if (!isset($data->confirm_password) || empty($data->confirm_password)) {
                http_response_code(400);
                echo json_encode(['error' => 'Password confirmation is required']);
                return;
            }
            
            // Check if new passwords match
            if ($data->new_password !== $data->confirm_password) {
                http_response_code(400);
                echo json_encode(['error' => 'New passwords do not match']);
                return;
            }
            
            // Get current user password
            $query = "SELECT password FROM users WHERE id = :user_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            $stmt->execute();
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify current password
            if (!$this->auth->verifyPassword($data->current_password, $user['password'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Current password is incorrect']);
                return;
            }
            
            // Validate new password strength
            $password_validation = $this->auth->validatePassword($data->new_password);
            if (!$password_validation['is_valid']) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'New password validation failed',
                    'details' => $password_validation['errors']
                ]);
                return;
            }
            
            // Update password
            $hashed_password = $this->auth->hashPassword($data->new_password);
            
            $update_query = "UPDATE users SET password = :password, updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
            $update_stmt = $this->conn->prepare($update_query);
            $update_stmt->bindParam(':password', $hashed_password);
            $update_stmt->bindParam(':user_id', $user_data['user_id']);
            
            if ($update_stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Password changed successfully'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to change password']);
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Password change failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Logout (Optional - mainly for token invalidation if implemented)
     * POST /api/auth/logout
     */
    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        try {
            // If you implement token blacklisting, add it here
            // For now, just return success (client will remove token)
            
            echo json_encode([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Logout failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * Verify Token
     * GET /api/auth/verify
     */
    public function verify() {
        try {
            $user_data = $this->auth->authenticate();
            
            echo json_encode([
                'success' => true,
                'valid' => true,
                'user_id' => $user_data['user_id'],
                'role' => $user_data['role']
            ]);
            
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'valid' => false,
                'error' => 'Invalid token'
            ]);
        }
    }
}
?>