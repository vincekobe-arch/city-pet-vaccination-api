<?php
// routes/verification.php

require_once __DIR__ . '/../controllers/VerificationController.php';

header('Content-Type: application/json');

// Get the request URI and method
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove base path
$base_path = '/city-pet-vaccination-api/verification';
$uri = str_replace($base_path, '', $request_uri);

$controller = new VerificationController();

// Route matching
if ($request_method === 'POST') {
    
    // POST /verification/send - Send verification code
    if ($uri === '/send' || $uri === '') {
        $controller->sendCode();
    }
    
    // POST /verification/verify - Verify code
    else if ($uri === '/verify') {
        $controller->verifyCode();
    }
    
    // POST /verification/resend - Resend verification code
    else if ($uri === '/resend') {
        $controller->resendCode();
    }
    
    else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Endpoint not found'
        ]);
    }
    
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
}
?>