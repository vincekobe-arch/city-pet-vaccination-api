<?php
// routes/owner.php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/OwnerController.php';

$controller = new OwnerController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all owners - /owners
    if ($method === 'GET' && $uri === '/owners') {
        $controller->index();
    }
    
    // GET owner by user_id - /owners/user/6
    else if ($method === 'GET' && preg_match('/^\/owners\/user\/(\d+)$/', $uri, $matches)) {
        $controller->getByUserId($matches[1]);
    }
    
    // GET owner by ID - /owners/show/1
    else if ($method === 'GET' && preg_match('/^\/owners\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // POST create owner - /owners/create
    else if ($method === 'POST' && $uri === '/owners/create') {
        $controller->create();
    }
    
    // PUT update owner - /owners/update/1 OR /owners/1
    else if ($method === 'PUT' && preg_match('/^\/owners\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // PUT update owner - /owners/6
    else if ($method === 'PUT' && preg_match('/^\/owners\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // POST submit ID - /owners/6/submit-id
    else if ($method === 'POST' && preg_match('/^\/owners\/(\d+)\/submit-id$/', $uri, $matches)) {
        $controller->submitId($matches[1]);
    }
    
    // PUT verify owner - /owners/6/verify
    else if ($method === 'PUT' && preg_match('/^\/owners\/(\d+)\/verify$/', $uri, $matches)) {
        $controller->verifyOwner($matches[1]);
    }
    
    // DELETE owner - /owners/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/owners\/delete\/(\d+)$/', $uri, $matches)) {
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