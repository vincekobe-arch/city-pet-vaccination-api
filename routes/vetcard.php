<?php
// routes/vetcard.php

header('Content-Type: application/json');

require_once __DIR__ . '/../controllers/VetCardController.php';

$controller = new VetCardController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all vet cards - /vetcards
    if ($method === 'GET' && $uri === '/vetcards') {
        $controller->index();
    }
    
    // GET vet card by ID - /vetcards/show/1
    else if ($method === 'GET' && preg_match('/^\/vetcards\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET vet cards by pet ID - /vetcards/pet/1
    else if ($method === 'GET' && preg_match('/^\/vetcards\/pet\/(\d+)$/', $uri, $matches)) {
        // Use the controller method which includes deworming records
        $controller->pet($matches[1]);
    }
    
    // POST create vet card - /vetcards/create
    else if ($method === 'POST' && $uri === '/vetcards/create') {
        $controller->create();
    }
    
    // PUT update vet card - /vetcards/update/1
    else if ($method === 'PUT' && preg_match('/^\/vetcards\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE deactivate vet card - /vetcards/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/vetcards\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->delete($matches[1]);
    }
    
    // GET verify vet card - /vetcards/verify/VET-202510-0001
    else if ($method === 'GET' && preg_match('/^\/vetcards\/verify\/(.+)$/', $uri, $matches)) {
        $controller->verify($matches[1]);
    }
    
    // GET expiring vet cards - /vetcards/expiring
    else if ($method === 'GET' && $uri === '/vetcards/expiring') {
        $controller->expiring();
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