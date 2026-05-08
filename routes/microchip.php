<?php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/MicrochipController.php';

$controller = new MicrochipController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    if ($method === 'GET' && $uri === '/microchips') {
        $controller->index();
    }
    else if ($method === 'GET' && preg_match('/^\/microchips\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    else if ($method === 'GET' && preg_match('/^\/microchips\/pet\/(\d+)$/', $uri, $matches)) {
        $controller->getByPetId($matches[1]);
    }
    else if ($method === 'POST' && $uri === '/microchips/create') {
        $controller->create();
    }
    else if ($method === 'PUT' && preg_match('/^\/microchips\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    else if ($method === 'DELETE' && preg_match('/^\/microchips\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->delete($matches[1]);
    }
    else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>