<?php
// routes/clinic.php

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/controllers/ClinicController.php';
require_once dirname(__DIR__) . '/controllers/ClinicInventoryController.php';

$clinicController    = new ClinicController();
$inventoryController = new ClinicInventoryController();

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$base_path = '/city-pet-vaccination-api';
$uri       = str_replace($base_path, '', $uri);

try {

    // ════════════════════════════════════════════
    // CLINIC MANAGEMENT  (existing routes)
    // ════════════════════════════════════════════

    // GET /clinics
    if ($method === 'GET' && $uri === '/clinics') {
        $clinicController->index();
    }

    // GET /clinics/statistics
    elseif ($method === 'GET' && $uri === '/clinics/statistics') {
        $clinicController->statistics();
    }

    // GET /clinics/dashboard
    elseif ($method === 'GET' && $uri === '/clinics/dashboard') {
        $clinicController->dashboard();
    }

    // GET /clinics/records
    elseif ($method === 'GET' && $uri === '/clinics/records') {
        $clinicController->records();
    }

    // PUT /clinics/records/update/{id}/{type}
    elseif ($method === 'PUT' &&
            preg_match('/^\/clinics\/records\/update\/(\d+)\/(vaccination|deworming|sterilization)$/', $uri, $m)) {
        $clinicController->updateRecord($m[1], $m[2]);
    }

    // DELETE /clinics/records/delete/{id}/{type}
    elseif ($method === 'DELETE' &&
            preg_match('/^\/clinics\/records\/delete\/(\d+)\/(vaccination|deworming|sterilization|microchip)$/', $uri, $m)) {
        $clinicController->deleteRecord($m[1], $m[2]);
    }

    // GET /clinics/show/{id}
    elseif ($method === 'GET' && preg_match('/^\/clinics\/show\/(\d+)$/', $uri, $m)) {
        $clinicController->show($m[1]);
    }

    // POST /clinics/create
    elseif ($method === 'POST' && $uri === '/clinics/create') {
        $clinicController->create();
    }

    // PUT /clinics/update/{id}
    elseif ($method === 'PUT' && preg_match('/^\/clinics\/update\/(\d+)$/', $uri, $m)) {
        $clinicController->update($m[1]);
    }

    // PUT /clinics/restore/{id}
    elseif ($method === 'PUT' && preg_match('/^\/clinics\/restore\/(\d+)$/', $uri, $m)) {
        $clinicController->restore($m[1]);
    }

    // DELETE /clinics/delete/{id}
    elseif ($method === 'DELETE' && preg_match('/^\/clinics\/delete\/(\d+)$/', $uri, $m)) {
        $clinicController->delete($m[1]);
    }

    // ════════════════════════════════════════════
    // CLINIC INVENTORY  (new routes)
    // All prefixed with /clinic/inventory
    // Accessible only by role: private_clinic
    // ════════════════════════════════════════════

    // GET /clinic/inventory
    // Returns all inventory items for the authenticated clinic.
    elseif ($method === 'GET' && $uri === '/clinic/inventory') {
        $inventoryController->index();
    }

    // GET /clinic/inventory/low-stock
    // Returns items where current_stock <= minimum_stock.
    elseif ($method === 'GET' && $uri === '/clinic/inventory/low-stock') {
        $inventoryController->getLowStock();
    }

    // GET /clinic/inventory/type/{type}
    // type: vaccination | deworming | sterilization | equipment
    elseif ($method === 'GET' &&
            preg_match('/^\/clinic\/inventory\/type\/(vaccination|deworming|sterilization|equipment)$/', $uri, $m)) {
        $inventoryController->getByType($m[1]);
    }

    // GET /clinic/inventory/show/{id}
    elseif ($method === 'GET' && preg_match('/^\/clinic\/inventory\/show\/(\d+)$/', $uri, $m)) {
        $inventoryController->show((int) $m[1]);
    }

    // GET /clinic/inventory/batches/{id}
    // All batch records for a single inventory item.
    elseif ($method === 'GET' && preg_match('/^\/clinic\/inventory\/batches\/(\d+)$/', $uri, $m)) {
        $inventoryController->getBatches((int) $m[1]);
    }

    // POST /clinic/inventory/create
    // Body: { item_type, item_name, species?, minimum_stock?, unit?, notes? }
    elseif ($method === 'POST' && $uri === '/clinic/inventory/create') {
        $inventoryController->create();
    }

    // PUT /clinic/inventory/update/{id}
    // Body: { item_name?, species?, minimum_stock?, unit?, notes? }
    elseif ($method === 'PUT' && preg_match('/^\/clinic\/inventory\/update\/(\d+)$/', $uri, $m)) {
        $inventoryController->update((int) $m[1]);
    }

    // PUT /clinic/inventory/restock/{id}
    // Body: { batch_no, quantity, expiration_date?, notes? }
    elseif ($method === 'PUT' && preg_match('/^\/clinic\/inventory\/restock\/(\d+)$/', $uri, $m)) {
        $inventoryController->restock((int) $m[1]);
    }

    // PUT /clinic/inventory/batch/update/{batchId}
    // Body: { batch_no?, quantity?, expiration_date?, notes? }
    elseif ($method === 'PUT' && preg_match('/^\/clinic\/inventory\/batch\/update\/(\d+)$/', $uri, $m)) {
        $inventoryController->updateBatch((int) $m[1]);
    }

    // DELETE /clinic/inventory/batch/delete/{batchId}
    elseif ($method === 'DELETE' && preg_match('/^\/clinic\/inventory\/batch\/delete\/(\d+)$/', $uri, $m)) {
        $inventoryController->deleteBatch((int) $m[1]);
    }

    // DELETE /clinic/inventory/delete/{id}
    // Permanently removes the inventory item and all its batches (CASCADE).
    // DELETE /clinic/inventory/delete/{id}
    // Permanently removes the inventory item and all its batches (CASCADE).
    elseif ($method === 'DELETE' && preg_match('/^\/clinic\/inventory\/delete\/(\d+)$/', $uri, $m)) {
        $inventoryController->delete((int) $m[1]);
    }

    // GET /clinic/inventory/record-batch-availability/{id}
    elseif ($method === 'GET' && preg_match('/^\/clinic\/inventory\/record-batch-availability\/(\d+)$/', $uri, $m)) {
        $inventoryController->getRecordBatchAvailability((int) $m[1]);
    }

    // POST /clinic/inventory/batch-deduct/{batchId}
    elseif ($method === 'POST' && preg_match('/^\/clinic\/inventory\/batch-deduct\/(\d+)$/', $uri, $m)) {
        $inventoryController->deductBatchStock((int) $m[1]);
    }

    // ════════════════════════════════════════════
    // 404
    else {
        http_response_code(404);
        echo json_encode([
            'success'       => false,
            'message'       => 'Endpoint not found',
            'requested_uri' => $uri,
            'method'        => $method,
            'available_endpoints' => [
                // Clinic management
                'GET    /clinics'                                      => 'Get all private clinics',
                'GET    /clinics/statistics'                           => 'Get clinic statistics',
                'GET    /clinics/dashboard'                            => 'Get clinic dashboard',
                'GET    /clinics/records'                              => 'Get clinic records',
                'PUT    /clinics/records/update/{id}/{type}'           => 'Update a clinic record',
                'GET    /clinics/show/{id}'                            => 'Get clinic by ID',
                'POST   /clinics/create'                               => 'Create new private clinic',
                'PUT    /clinics/update/{id}'                          => 'Update clinic',
                'PUT    /clinics/restore/{id}'                         => 'Restore deactivated clinic',
                'DELETE /clinics/delete/{id}'                          => 'Deactivate clinic',
                // Clinic inventory
                'GET    /clinic/inventory'                             => 'Get all inventory for authenticated clinic',
                'GET    /clinic/inventory/low-stock'                   => 'Get low-stock items',
                'GET    /clinic/inventory/type/{type}'                 => 'Get inventory by type (vaccination|deworming|sterilization|equipment)',
                'GET    /clinic/inventory/show/{id}'                   => 'Get single inventory item',
                'GET    /clinic/inventory/batches/{id}'                => 'Get all batches for an inventory item',
                'POST   /clinic/inventory/create'                      => 'Create new inventory item',
                'PUT    /clinic/inventory/update/{id}'                 => 'Update inventory item details',
                'PUT    /clinic/inventory/restock/{id}'                => 'Add a restock batch',
                'PUT    /clinic/inventory/batch/update/{batchId}'      => 'Update a batch',
                'DELETE /clinic/inventory/batch/delete/{batchId}'      => 'Delete a batch',
                'DELETE /clinic/inventory/delete/{id}'                 => 'Delete an inventory item',
            ]
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'trace'   => $e->getTraceAsString()
    ]);
}
?>