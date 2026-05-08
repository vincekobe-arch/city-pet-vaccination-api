<?php
/**
 * Verify Email and Complete Registration - OPTION 2 VERSION
 * POST /auth/verify-email.php
 * Receives ALL registration data + verification code
 */

ob_start();

// CORS handled by .htaccess - DO NOT add headers here
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_clean();
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit();
}

try {
    error_log("=== VERIFICATION AND ACCOUNT CREATION ===");
    
    require_once __DIR__ . '/../config/database.php';
    
    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format');
    }
    
    // Validate ALL required fields (not just email and code)
    $required_fields = ['email', 'verification_code', 'username', 'password', 'first_name', 'last_name', 'phone'];
    foreach ($required_fields as $field) {
        if (!isset($data->$field) || empty(trim($data->$field))) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
        }
    }
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $email = strtolower(trim($data->email));
$code = trim($data->verification_code);
$username = trim($data->username);
$password = $data->password;
$first_name = trim($data->first_name);
$middle_name = isset($data->middle_name) && !empty(trim($data->middle_name)) ? trim($data->middle_name) : null;
$last_name = trim($data->last_name);
$phone = trim($data->phone);
$address = isset($data->address) && !empty(trim($data->address)) ? trim($data->address) : null;
    
    error_log("Verifying code for email: $email");
    
    // Verify the code is valid and not expired
    $check_verification = "SELECT * FROM email_verifications 
                          WHERE email = ? 
                          AND verification_code = ? 
                          AND is_verified = 0 
                          AND expires_at > NOW()
                          ORDER BY created_at DESC 
                          LIMIT 1";
    
    $stmt = $conn->prepare($check_verification);
    $stmt->execute([$email, $code]);
    
    if ($stmt->rowCount() === 0) {
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or expired verification code'
        ]);
        exit();
    }
    
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("✅ Valid verification found");
    
    // Check if user already exists (double-check)
    $check_user = "SELECT id FROM users WHERE email = ? OR username = ?";
    $stmt_check = $conn->prepare($check_user);
    $stmt_check->execute([$email, $username]);
    
    if ($stmt_check->rowCount() > 0) {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'User already exists'
        ]);
        exit();
    }
    
    // Hash the password
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    
    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Create user account with provided data
$insert_user = "INSERT INTO users 
               (username, email, password, first_name, last_name, phone, address, role, is_active) 
               VALUES (?, ?, ?, ?, ?, ?, ?, 'pet_owner', 1)";

$stmt_user = $conn->prepare($insert_user);
$stmt_user->execute([
    $username,
    $email,
    $password_hash,
    $first_name,
    $last_name,
    $phone,
    $address
]);
        
        $user_id = $conn->lastInsertId();
        error_log("✅ User created with ID: $user_id");
        
        // Create pet owner record
$insert_owner = "INSERT INTO pet_owners (user_id, first_name, middle_name, last_name, phone, address) 
                VALUES (?, ?, ?, ?, ?, ?)";

$stmt_owner = $conn->prepare($insert_owner);
$stmt_owner->execute([
    $user_id,
    $first_name,
    $middle_name,
    $last_name,
    $phone,
    $address
]);
        
        error_log("✅ Pet owner record created");
        
        // Mark verification as used
        $mark_verified = "UPDATE email_verifications 
                         SET is_verified = 1 
                         WHERE id = ?";
        $stmt_mark = $conn->prepare($mark_verified);
        $stmt_mark->execute([$verification['id']]);
        
        // Commit transaction
        $conn->commit();
        
        error_log("✅ Registration completed successfully");
        
        ob_clean();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully! You can now login.',
            'user' => [
                'id' => $user_id,
                'username' => $username,
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("❌ Transaction failed: " . $e->getMessage());
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("❌ Verification error: " . $e->getMessage());
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>