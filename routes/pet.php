<?php
// routes/pet.php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/PetController.php';

$controller = new PetController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all pets - /pets
    if ($method === 'GET' && $uri === '/pets') {
        $controller->index();
    }
    
    // GET pet by ID - /pets/show/1
    else if ($method === 'GET' && preg_match('/^\/pets\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET pets by owner USER ID - /pets/owner/6
    else if ($method === 'GET' && preg_match('/^\/pets\/owner\/(\d+)$/', $uri, $matches)) {
        $controller->owner($matches[1]);
    }
    
    // POST create pet - /pets/create
    else if ($method === 'POST' && $uri === '/pets/create') {
        $controller->create();
    }
    
    // PUT update pet - /pets/update/1
    else if ($method === 'PUT' && preg_match('/^\/pets\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE pet - /pets/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/pets\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->delete($matches[1]);
    }
    
    // POST upload pet photo - /pets/upload-photo/1
    else if ($method === 'POST' && preg_match('/^\/pets\/upload-photo\/(\d+)$/', $uri, $matches)) {
        $controller->uploadPhoto($matches[1]);
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