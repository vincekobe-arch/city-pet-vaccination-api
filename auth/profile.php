<?php
// auth/profile.php - GET /auth/profile
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET.']);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

$database = new Database();
$conn = $database->getConnection();
$auth = new Auth($conn);

// authenticate() already checks the token, checks the user is active,
// and exits with 401 + code SESSION_INVALIDATED if the token doesn't
// match the stored session_token — exactly what we need here.
$userData = $auth->authenticate();

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Session valid',
    'user' => $userData['user_details']
]);
exit();