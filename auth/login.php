<?php
/**
 * Login Endpoint
 * POST /auth/login.php
 */

// Set headers FIRST before any output
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ]);
    exit();
}

try {
    error_log("=== LOGIN ATTEMPT STARTED ===");
    
    // Include required files
    error_log("Including database.php from: " . __DIR__ . '/../config/database.php');
    require_once __DIR__ . '/../config/database.php';
    
    error_log("Including Auth.php from: " . __DIR__ . '/../middleware/Auth.php');
    require_once __DIR__ . '/../middleware/Auth.php';
    
    error_log("Files included successfully");

    // Get JSON input
    $raw_input = file_get_contents("php://input");
    error_log("Raw input received: " . $raw_input);
    
    $data = json_decode($raw_input);
    
    // Validate JSON parsing
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON parse error: " . json_last_error_msg());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON format'
        ]);
        exit();
    }
    
    // Validate required fields
    if (!isset($data->username) || empty(trim($data->username))) {
        error_log("Username missing or empty");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Username is required'
        ]);
        exit();
    }
    
    if (!isset($data->password) || empty($data->password)) {
        error_log("Password missing or empty");
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Password is required'
        ]);
        exit();
    }
    
    error_log("Username: " . $data->username);
    
    // Initialize database
    error_log("Creating Database object...");
    $database = new Database();
    
    error_log("Getting database connection...");
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception('Database connection returned null');
    }
    
    error_log("Database connection successful");
    
    // Initialize auth with connection
    error_log("Creating Auth object with connection...");
    $auth = new Auth($conn);
    
    error_log("Auth object created successfully");
    
    // Query user
    error_log("Preparing user query...");
    $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code
              FROM users u
              LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
              WHERE (u.username = ? OR u.email = ?) 
              AND u.is_active = 1";
    
    $stmt = $conn->prepare($query);
    
    $username = trim($data->username);
    error_log("Binding username: " . $username);
    
    error_log("Executing user query...");
    $stmt->execute([$username, $username]);
    
    error_log("Query executed. Rows found: " . $stmt->rowCount());
    
    // Check if user exists
    if ($stmt->rowCount() === 0) {
        error_log("User not found: " . $username);
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid username or password'
        ]);
        exit();
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
    
    // Verify password
    error_log("Verifying password...");
    if (!$auth->verifyPassword($data->password, $user['password'])) {
        error_log("Password verification failed");
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid username or password'
        ]);
        exit();
    }
    
    error_log("Password verified successfully");
    
    // Get role-specific details
    $user_details = null;
    
    if ($user['role'] === 'pet_owner') {
        error_log("Getting pet owner details...");
        $owner_query = "SELECT po.*, COUNT(DISTINCT p.id) as total_pets
                       FROM pet_owners po
                       LEFT JOIN pets p ON po.id = p.owner_id AND p.is_active = 1
                       WHERE po.user_id = ?
                       GROUP BY po.id";
        
        $owner_stmt = $conn->prepare($owner_query);
        $owner_stmt->execute([$user['id']]);
        
        if ($owner_stmt->rowCount() > 0) {
            $user_details = $owner_stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Pet owner details retrieved");
        }
        
    } elseif ($user['role'] === 'barangay_official') {
        error_log("Getting barangay official details...");
        $official_query = "SELECT bo.*
                          FROM barangay_officials bo
                          WHERE bo.user_id = ?";
        
        $official_stmt = $conn->prepare($official_query);
        $official_stmt->execute([$user['id']]);
        
        if ($official_stmt->rowCount() > 0) {
            $user_details = $official_stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Barangay official details retrieved");
        }
    }
    
    // Generate JWT token
    error_log("Generating JWT token...");
    $token = $auth->generateToken(
        $user['id'],
        $user['role'],
        $user['assigned_barangay_id']
    );
    
    error_log("Token generated successfully: " . substr($token, 0, 20) . "...");
    
    // Update last login and save session token
    error_log("Updating last login timestamp...");
    $update_query = "UPDATE users SET updated_at = CURRENT_TIMESTAMP, session_token = :token WHERE id = :user_id";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':token', $token);
    $update_stmt->bindParam(':user_id', $user['id']);
    $update_stmt->execute();
    
    error_log("Last login updated");
    
    // Remove sensitive data
    unset($user['password']);
    
    // Prepare successful response
    $response = [
        'success' => true,
        'message' => 'Login successful',
        'token' => $token,
        'user' => $user,
        'user_details' => $user_details
    ];
    
    error_log("Login successful for user ID: " . $user['id']);
    
    // Send response
    http_response_code(200);
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log("PDOException in login: " . $e->getMessage());
    error_log("PDO Error Code: " . $e->getCode());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    
} catch (Exception $e) {
    error_log("Exception in login: " . $e->getMessage());
    error_log("Exception Code: " . $e->getCode());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>