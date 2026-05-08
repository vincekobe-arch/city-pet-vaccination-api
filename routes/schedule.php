<?php
// routes/schedule.php

header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/ScheduleController.php';

$controller = new ScheduleController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path from URI
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET vaccination schedules - /schedules/vaccination
    if ($method === 'GET' && $uri === '/schedules/vaccination') {
        $controller->vaccination();
    }
    
    // GET seminar schedules - /schedules/seminar
    else if ($method === 'GET' && $uri === '/schedules/seminar') {
        $controller->seminar();
    }
    
    // GET sterilization schedules - /schedules/sterilization
    else if ($method === 'GET' && $uri === '/schedules/sterilization') {
        $controller->sterilization();
    }
    
    // GET deworming schedules - /schedules/deworming
    else if ($method === 'GET' && $uri === '/schedules/deworming') {
        $controller->deworming();
    }
    
    // GET microchip schedules - /schedules/microchip
    else if ($method === 'GET' && $uri === '/schedules/microchip') {
        $controller->microchip();
    }
    
    // GET other schedules - /schedules/other
    else if ($method === 'GET' && $uri === '/schedules/other') {
        $controller->other();
    }
    
    // GET vaccine registration counts - /schedules/vaccination/{id}/vaccine-counts
    else if ($method === 'GET' && preg_match('/^\/schedules\/vaccination\/(\d+)\/vaccine-counts$/', $uri, $matches)) {
        $controller->getVaccineRegistrationCounts($matches[1]);
    }
    
    // GET registered pets for a schedule - /schedules/registered-pets/1/vaccination
    else if ($method === 'GET' && preg_match('/^\/schedules\/registered-pets\/(\d+)\/(\w+)$/', $uri, $matches)) {
        $controller->getRegisteredPets($matches[1], $matches[2]);
    }
    
    // GET schedule by ID - /schedules/show/1
    else if ($method === 'GET' && preg_match('/^\/schedules\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }
    
    // POST create schedule - /schedules/create
    else if ($method === 'POST' && $uri === '/schedules/create') {
        $controller->create();
    }
    
    // POST register for schedule - /schedules/register
    else if ($method === 'POST' && $uri === '/schedules/register') {
        $controller->register();
    }
    
    // PUT update schedule - /schedules/update/1
    else if ($method === 'PUT' && preg_match('/^\/schedules\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }
    
    // DELETE cancel registration - /schedules/cancel-registration/1
    else if ($method === 'DELETE' && preg_match('/^\/schedules\/cancel-registration\/(\d+)$/', $uri, $matches)) {
        $controller->cancelRegistration($matches[1]);
    }
    
    // PUT update registration - /schedules/update-registration/1
    else if ($method === 'PUT' && preg_match('/^\/schedules\/update-registration\/(\d+)$/', $uri, $matches)) {
        $controller->updateRegistration($matches[1]);
    }
    
    // DELETE schedule - /schedules/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/schedules\/delete\/(\d+)$/', $uri, $matches)) {
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
            'debug_info' => [
                'raw_uri' => $_SERVER['REQUEST_URI'],
                'parsed_uri' => $uri,
                'method' => $method
            ]
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