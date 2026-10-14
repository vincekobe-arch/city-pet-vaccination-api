<?php
// routes/cleanup.php
// Run daily via cron: 0 2 * * * php /path/to/routes/cleanup.php

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

$tables = [
    'vaccination_records',
    'deworming_records',
    'sterilization_records',
    'microchip_records',
];

$purged = [];
foreach ($tables as $table) {
    $stmt = $conn->prepare(
        "DELETE FROM `$table` WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL 30 DAY"
    );
    $stmt->execute();
    $purged[$table] = $stmt->rowCount();
}

echo json_encode(['success' => true, 'purged' => $purged]);