<?php
// routes/deworming.php

header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/DewormingController.php';

$controller = new DewormingController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all deworming records - /dewormings
    if ($method === 'GET' && $uri === '/dewormings') {
        $controller->index();
    }
    
    // GET deworming record by ID - /dewormings/show/1
    else if ($method === 'GET' && preg_match('/^\/dewormings\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET dewormings by pet ID - /dewormings/pet/1
    else if ($method === 'GET' && preg_match('/^\/dewormings\/pet\/(\d+)$/', $uri, $matches)) {
        $controller->getByPetId($matches[1]);
    }
    
    // GET due dewormings - /dewormings/due
    else if ($method === 'GET' && $uri === '/dewormings/due') {
        $controller->getDue();
    }
    
    // GET deworming types - /dewormings/types
    else if ($method === 'GET' && $uri === '/dewormings/types') {
        $controller->getTypes();
    }
    
    // GET deworming statistics - /dewormings/statistics
    else if ($method === 'GET' && $uri === '/dewormings/statistics') {
        $controller->getStatistics();
    }
    
    // POST create deworming record - /dewormings/create
    else if ($method === 'POST' && $uri === '/dewormings/create') {
        $controller->create();
    }
    
    // PUT update deworming record - /dewormings/update/1
    else if ($method === 'PUT' && preg_match('/^\/dewormings\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE deworming record - /dewormings/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/dewormings\/delete\/(\d+)$/', $uri, $matches)) {
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