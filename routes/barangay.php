<?php
// routes/barangay.php

require_once __DIR__ . '/../controllers/BarangayController.php';

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $request_uri);

// Remove /barangays from the beginning
$uri = str_replace('/barangays', '', $uri);

// Initialize controller
$controller = new BarangayController();

try {
    // GET /barangays - List all barangays
    if ($request_method === 'GET' && ($uri === '' || $uri === '/')) {
        $controller->index();
    }
    
    // GET /barangays/{id} - Get single barangay
    else if ($request_method === 'GET' && preg_match('/^\/(\d+)$/', $uri, $matches)) {
        $id = $matches[1];
        $controller->show($id);
    }
    
    // GET /barangays/statistics - Get statistics
    else if ($request_method === 'GET' && $uri === '/statistics') {
        $controller->statistics();
    }
    
    // POST /barangays - Create new barangay
    else if ($request_method === 'POST' && ($uri === '' || $uri === '/')) {
        $controller->create();
    }
    
    // PUT /barangays/{id} - Update barangay
    else if ($request_method === 'PUT' && preg_match('/^\/(\d+)$/', $uri, $matches)) {
        $id = $matches[1];
        $controller->update($id);
    }
    
    // DELETE /barangays/{id} - Delete barangay
    else if ($request_method === 'DELETE' && preg_match('/^\/(\d+)$/', $uri, $matches)) {
        $id = $matches[1];
        $controller->delete($id);
    }
    
    // Route not found
    else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Barangay route not found',
            'requested_uri' => $uri,
            'method' => $request_method
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}
?>