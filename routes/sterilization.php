<?php
// routes/sterilization.php

header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/SterilizationController.php';

$controller = new SterilizationController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all sterilization records - /sterilizations
    if ($method === 'GET' && $uri === '/sterilizations') {
        $controller->index();
    }
    
    // GET sterilization record by ID - /sterilizations/show/1
    else if ($method === 'GET' && preg_match('/^\/sterilizations\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET sterilization by pet ID - /sterilizations/pet/1
    else if ($method === 'GET' && preg_match('/^\/sterilizations\/pet\/(\d+)$/', $uri, $matches)) {
        $controller->getByPetId($matches[1]);
    }
    
    // GET sterilization statistics - /sterilizations/statistics
    else if ($method === 'GET' && $uri === '/sterilizations/statistics') {
        $controller->getStatistics();
    }
    
    // POST create sterilization record - /sterilizations/create
    else if ($method === 'POST' && $uri === '/sterilizations/create') {
        $controller->create();
    }
    
    // PUT update sterilization record - /sterilizations/update/1
    else if ($method === 'PUT' && preg_match('/^\/sterilizations\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE sterilization record - /sterilizations/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/sterilizations\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->delete($matches[1]);
    }
    
    // 404 - Route not found
    else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Endpoint not found',
            'requested_uri' => $uri,
            'method' => $method
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>