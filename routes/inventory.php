<?php
// routes/inventory.php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../controllers/InventoryController.php';

$controller = new InventoryController();
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

try {
    // GET all inventory - /inventory
    if ($method === 'GET' && $uri === '/inventory') {
        $controller->index();
    }

    // GET low stock items - /inventory/low-stock
    else if ($method === 'GET' && $uri === '/inventory/low-stock') {
        $controller->getLowStock();
    }

    // GET inventory by type - /inventory/type/vaccination
    else if ($method === 'GET' && preg_match('/^\/inventory\/type\/(\w+)$/', $uri, $matches)) {
        $controller->getByType($matches[1]);
    }

    // GET single item - /inventory/show/1
    else if ($method === 'GET' && preg_match('/^\/inventory\/show\/(\d+)$/', $uri, $matches)) {
        $controller->show($matches[1]);
    }

    // PUT restock - /inventory/restock/1
    else if ($method === 'PUT' && preg_match('/^\/inventory\/restock\/(\d+)$/', $uri, $matches)) {
        $controller->restock($matches[1]);
    }

    // PUT update item - /inventory/update/1
    else if ($method === 'PUT' && preg_match('/^\/inventory\/update\/(\d+)$/', $uri, $matches)) {
        $controller->update($matches[1]);
    }

    // POST create item - /inventory/create
    else if ($method === 'POST' && $uri === '/inventory/create') {
        $controller->create();
    }

    // GET batches for item - /inventory/batches/1
    else if ($method === 'GET' && preg_match('/^\/inventory\/batches\/(\d+)$/', $uri, $matches)) {
        $controller->getBatches($matches[1]);
    }

    // PUT update a batch - /inventory/batch/update/1
    else if ($method === 'PUT' && preg_match('/^\/inventory\/batch\/update\/(\d+)$/', $uri, $matches)) {
        $controller->updateBatch($matches[1]);
    }

    // DELETE a batch - /inventory/batch/delete/1
    // DELETE a batch - /inventory/batch/delete/1
    else if ($method === 'DELETE' && preg_match('/^\/inventory\/batch\/delete\/(\d+)$/', $uri, $matches)) {
        $controller->deleteBatch($matches[1]);
    }

    // GET batch availability - /inventory/batch-availability/1 or /inventory/batch-availability/1?exclude_schedule=5
    else if ($method === 'GET' && preg_match('/^\/inventory\/batch-availability\/(\d+)$/', $uri, $matches)) {
        $controller->getBatchAvailability($matches[1]);
    }

    else if ($method === 'GET' && preg_match('/^\/inventory\/record-batch-availability\/(\d+)$/', $uri, $matches)) {
        $controller->getRecordBatchAvailability($matches[1]);
    }

    else if ($method === 'POST' && preg_match('/^\/inventory\/batch-deduct\/(\d+)$/', $uri, $matches)) {
        $controller->deductBatchStock($matches[1]);
    }

    // POST save schedule allocations - /inventory/schedule-allocations/1
    else if ($method === 'POST' && preg_match('/^\/inventory\/schedule-allocations\/(\d+)$/', $uri, $matches)) {
        $controller->saveScheduleAllocations($matches[1]);
    }

    // GET schedule allocations - /inventory/schedule-allocations/1
    else if ($method === 'GET' && preg_match('/^\/inventory\/schedule-allocations\/(\d+)$/', $uri, $matches)) {
        $controller->getScheduleAllocations($matches[1]);
    }

    // 404
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