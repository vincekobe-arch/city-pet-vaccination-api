<?php
/**
 * Registration Endpoint - Simplified Version
 * POST /auth/register.php
 * Only stores verification code, actual registration happens after verification
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
    error_log("=== REGISTRATION ATTEMPT STARTED ===");
    
    require_once __DIR__ . '/../config/database.php';
    
    $raw_input = file_get_contents("php://input");
    $data = json_decode($raw_input);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format');
    }
    
    // Validate required fields
    $required_fields = ['username', 'email', 'password', 'first_name', 'last_name', 'phone'];
    foreach ($required_fields as $field) {
        if (!isset($data->$field) || empty(trim($data->$field))) {
            throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
        }
    }
    
    // Validate email format
    if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Validate password strength
    if (strlen($data->password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    
    if (!preg_match('/[A-Z]/', $data->password)) {
        throw new Exception('Password must contain at least one uppercase letter');
    }
    
    if (!preg_match('/[a-z]/', $data->password)) {
        throw new Exception('Password must contain at least one lowercase letter');
    }
    
    if (!preg_match('/\d/', $data->password)) {
        throw new Exception('Password must contain at least one number');
    }
    
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Normalize data
    $email = strtolower(trim($data->email));
    $username = trim($data->username);
    
    // Check if username already exists
    $check_username = "SELECT id FROM users WHERE username = ?";
    $stmt_username = $conn->prepare($check_username);
    $stmt_username->execute([$username]);
    
    if ($stmt_username->rowCount() > 0) {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Username already exists'
        ]);
        exit();
    }
    
    // Check if email already exists
    $check_email = "SELECT id FROM users WHERE email = ?";
    $stmt_email = $conn->prepare($check_email);
    $stmt_email->execute([$email]);
    
    if ($stmt_email->rowCount() > 0) {
        ob_clean();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Email already registered'
        ]);
        exit();
    }
    
    // Generate verification code
    $verification_code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// FIXED: Use MySQL's NOW() + INTERVAL instead of PHP date
// This ensures timezone consistency with the database
$expires_at = null; // We'll use MySQL's NOW() + INTERVAL in the query

error_log("Generated verification code: $verification_code");

// Delete old verification attempts for this email
$delete_old = "DELETE FROM email_verifications WHERE email = ?";
$stmt_delete = $conn->prepare($delete_old);
$stmt_delete->execute([$email]);

// Store verification code with expiry using MySQL's NOW()
$insert_verification = "INSERT INTO email_verifications 
                       (email, phone, verification_code, expires_at) 
                       VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))";

$stmt_insert = $conn->prepare($insert_verification);
$stmt_insert->execute([
    $email,
    trim($data->phone),
    $verification_code
    // Note: We removed $expires_at from here - MySQL handles it
]);

error_log("✅ Verification code stored: $verification_code for $email");
    
    // Use VerificationService to send email
    require_once __DIR__ . '/../services/VerificationService.php';
    $verificationService = new VerificationService();
    
    // Send email with stored code
    $emailResult = $verificationService->sendVerificationEmailWithCode(
        $email, 
        $data->first_name, 
        $verification_code
    );
    
    if (!$emailResult['success']) {
        throw new Exception($emailResult['message']);
    }
    
    error_log("✅ Email sent successfully to $email");
    
    ob_clean();
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to your email',
        'email' => $email,
        'requires_verification' => true
    ]);
    
} catch (Exception $e) {
    error_log("❌ Registration error: " . $e->getMessage());
    
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>