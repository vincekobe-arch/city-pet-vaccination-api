<?php
// routes/vaccination.php

header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/VaccinationController.php';

$controller = new VaccinationController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all vaccination records - /vaccinations
    if ($method === 'GET' && $uri === '/vaccinations') {
        $controller->index();
    }
    
    // GET vaccination record by ID - /vaccinations/show/1
    else if ($method === 'GET' && preg_match('/^\/vaccinations\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // GET vaccinations by pet ID - /vaccinations/pet/1
    else if ($method === 'GET' && preg_match('/^\/vaccinations\/pet\/(\d+)$/', $uri, $matches)) {
        $controller->getByPetId($matches[1]);
    }
    
    // GET due vaccinations - /vaccinations/due
    else if ($method === 'GET' && $uri === '/vaccinations/due') {
        $controller->getDue();
    }
    
    // GET vaccination types - /vaccinations/types
    else if ($method === 'GET' && $uri === '/vaccinations/types') {
        $controller->getTypes();
    }
    
    // GET vaccination statistics - /vaccinations/statistics
    else if ($method === 'GET' && $uri === '/vaccinations/statistics') {
        $controller->getStatistics();
    }
    
    // POST create vaccination record - /vaccinations/create
    else if ($method === 'POST' && $uri === '/vaccinations/create') {
        $controller->create();
    }
    
    // PUT update vaccination record - /vaccinations/update/1
    else if ($method === 'PUT' && preg_match('/^\/vaccinations\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE vaccination record - /vaccinations/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/vaccinations\/delete\/(\d+)$/', $uri, $matches)) {
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