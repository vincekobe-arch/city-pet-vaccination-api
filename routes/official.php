<?php
// routes/official.php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/OfficialController.php';

$controller = new OfficialController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all officials - /officials
    if ($method === 'GET' && $uri === '/officials') {
        $controller->index();
    }
    
    // GET official by ID - /officials/show/1
    else if ($method === 'GET' && preg_match('/^\/officials\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET officials by barangay ID - /officials/barangay/1
    else if ($method === 'GET' && preg_match('/^\/officials\/barangay\/(\d+)$/', $uri, $matches)) {
        $controller->byBarangay($matches[1]);
    }
    
    // GET officials statistics - /officials/statistics
    else if ($method === 'GET' && $uri === '/officials/statistics') {
        // If you have a statistics method in your controller
        $controller->statistics();
    }
    
    // POST create official - /officials/create
    else if ($method === 'POST' && $uri === '/officials/create') {
        $controller->create();
    }
    
    // PUT update official - /officials/update/1
    else if ($method === 'PUT' && preg_match('/^\/officials\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // PUT restore official - /officials/restore/1
    else if ($method === 'PUT' && preg_match('/^\/officials\/restore\/(\d+)$/', $uri, $matches)) {
        $controller->restore($matches[1]);
    }
    
    // DELETE official - /officials/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/officials\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->delete($matches[1]);
    }
    
    // 404 - Route not found
    else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint not found',
            'requested_uri' => $uri,
            'method' => $method,
            'available_endpoints' => [
                'GET /officials' => 'Get all officials',
                'GET /officials/show/{id}' => 'Get official by ID',
                'GET /officials/barangay/{id}' => 'Get officials by barangay',
                'POST /officials/create' => 'Create new official',
                'PUT /officials/update/{id}' => 'Update official',
                'PUT /officials/restore/{id}' => 'Restore official',
                'DELETE /officials/delete/{id}' => 'Delete official'
            ]
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>