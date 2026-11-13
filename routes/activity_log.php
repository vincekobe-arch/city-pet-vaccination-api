<?php
// routes/activity_log.php

require_once __DIR__ . '/../controllers/ActivityLogController.php';

header('Content-Type: application/json');

$controller = new ActivityLogController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->index();
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>