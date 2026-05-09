<?php

$allowed_origins = [
    'http://localhost:3000',
    'https://city-pet-vaccination-frontend.vercel.app',
    'https://petunityph.vercel.app'
];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://petunityph.vercel.app");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

// Validate input
if (!isset($data->username) || empty(trim($data->username))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Username is required'
    ]);
    exit();
}

$username = trim($data->username);

// Basic validation
if (strlen($username) < 8) {
    echo json_encode([
        'success' => true,
        'exists' => false,
        'message' => 'Username must be at least 8 characters'
    ]);
    exit();
}

if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
    echo json_encode([
        'success' => true,
        'exists' => false,
        'message' => 'Username can only contain letters, numbers, and underscores'
    ]);
    exit();
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Check if username exists
    $query = "SELECT id FROM users WHERE username = :username AND is_active = 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    $exists = $stmt->rowCount() > 0;
    
    echo json_encode([
        'success' => true,
        'exists' => $exists
    ]);
    
} catch (Exception $e) {
    error_log("Username check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to check username availability'
    ]);
}
?>