<?php
header('Content-Type: application/json; charset=UTF-8');

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
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit();
}

try {
    require_once __DIR__ . '/../config/database.php';
    
    $data = json_decode(file_get_contents("php://input"));
    
    if (!isset($data->email) || empty(trim($data->email))) {
        throw new Exception('Email is required');
    }
    
    $email = strtolower(trim($data->email));
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT id FROM users WHERE LOWER(email) = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$email]);
    
    $exists = $stmt->rowCount() > 0;
    
    echo json_encode([
        'success' => true,
        'exists' => $exists
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>