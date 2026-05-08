<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class InventoryController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    // GET all inventory - /inventory
    public function index() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT * FROM inventory ORDER BY FIELD(item_type, 'microchip', 'vaccination', 'medicine', 'equipment'), item_name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'inventory' => $items,
                'total' => count($items)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get inventory: ' . $e->getMessage()
            ]);
        }
    }

    // GET inventory by type - /inventory/type/vaccination
    public function getByType($type) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT * FROM inventory WHERE item_type = :item_type ORDER BY item_name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':item_type', $type);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'inventory' => $items,
                'total' => count($items)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get inventory by type: ' . $e->getMessage()
            ]);
        }
    }

    // GET single inventory item - /inventory/show/1
    public function show($id) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT * FROM inventory WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success' => true,
                    'item' => $item
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Inventory item not found'
                ]);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get inventory item: ' . $e->getMessage()
            ]);
        }
    }

    // PUT restock an item (add a batch) - /inventory/restock/1
public function restock($id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->quantity) || !is_numeric($data->quantity) || intval($data->quantity) <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'A valid quantity greater than 0 is required']);
        return;
    }

    if (!isset($data->batch_no) || trim($data->batch_no) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Batch number is required']);
        return;
    }

    try {
        // Check batch_no uniqueness
        $check = $this->conn->prepare("SELECT id FROM inventory_batches WHERE batch_no = :batch_no");
        $check->bindParam(':batch_no', $data->batch_no);
        $check->execute();
        if ($check->rowCount() > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Batch number already exists. Please use a unique batch number.']);
            return;
        }

        $this->conn->beginTransaction();

        // Insert batch
        $batchStmt = $this->conn->prepare(
            "INSERT INTO inventory_batches (inventory_id, batch_no, quantity, expiration_date, notes)
             VALUES (:inventory_id, :batch_no, :quantity, :expiration_date, :notes)"
        );
        $qty  = intval($data->quantity);
        $batchNo = trim($data->batch_no);
        $expDate = isset($data->expiration_date) && $data->expiration_date !== '' ? $data->expiration_date : null;
        $notes   = isset($data->notes) ? $data->notes : null;

        $batchStmt->bindParam(':inventory_id',    $id,      PDO::PARAM_INT);
        $batchStmt->bindParam(':batch_no',        $batchNo);
        $batchStmt->bindParam(':quantity',        $qty,     PDO::PARAM_INT);
        $batchStmt->bindParam(':expiration_date', $expDate);
        $batchStmt->bindParam(':notes',           $notes);
        $batchStmt->execute();

        // Update last_restocked_at (triggers handle current_stock)
        $this->conn->prepare(
            "UPDATE inventory SET last_restocked_at = CURRENT_TIMESTAMP WHERE id = :id"
        )->execute([':id' => $id]);

        $this->conn->commit();

        $get = $this->conn->prepare("SELECT * FROM inventory WHERE id = :id");
        $get->bindParam(':id', $id);
        $get->execute();
        $item = $get->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'message' => 'Stock batch added successfully',
            'item'    => $item
        ]);

    } catch (Exception $e) {
        $this->conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to restock: ' . $e->getMessage()]);
    }
}
// GET all batches for an item - /inventory/batches/1
public function getBatches($id) {
    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

    try {
        $query = "SELECT b.*, i.item_name, i.unit
                  FROM inventory_batches b
                  JOIN inventory i ON i.id = b.inventory_id
                  WHERE b.inventory_id = :id
                  ORDER BY b.created_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'batches' => $batches]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to get batches: ' . $e->getMessage()]);
    }
}

// PUT update a batch - /inventory/batch/update/1
public function updateBatch($batchId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

    $data = json_decode(file_get_contents("php://input"));

    try {
        // If batch_no is being changed, check uniqueness (excluding current batch)
        if (isset($data->batch_no)) {
            $check = $this->conn->prepare(
                "SELECT id FROM inventory_batches WHERE batch_no = :batch_no AND id != :id"
            );
            $check->bindParam(':batch_no', $data->batch_no);
            $check->bindParam(':id', $batchId, PDO::PARAM_INT);
            $check->execute();
            if ($check->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Batch number already exists.']);
                return;
            }
        }

        $fields = [];
        $params = [':id' => $batchId];

        $allowed = ['batch_no', 'quantity', 'expiration_date', 'notes'];
        foreach ($allowed as $f) {
            if (isset($data->$f)) {
                $fields[] = "$f = :$f";
                $params[":$f"] = $data->$f;
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $query = "UPDATE inventory_batches SET " . implode(', ', $fields) .
                 ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt  = $this->conn->prepare($query);

        if ($stmt->execute($params) && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Batch updated successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Batch not found']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to update batch: ' . $e->getMessage()]);
    }
}

// DELETE a batch - /inventory/batch/delete/1
public function deleteBatch($batchId) {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }

    $user_data = $this->auth->authenticate();
    $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

    try {
        $stmt = $this->conn->prepare("DELETE FROM inventory_batches WHERE id = :id");
        $stmt->bindParam(':id', $batchId, PDO::PARAM_INT);

        if ($stmt->execute() && $stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Batch deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Batch not found']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete batch: ' . $e->getMessage()]);
    }
}

    // PUT update minimum stock threshold - /inventory/update/1
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        try {
            $update_fields = [];
            $params = [':id' => $id];

            $allowed_fields = ['current_stock', 'minimum_stock', 'unit', 'notes'];

            foreach ($allowed_fields as $field) {
                if (isset($data->$field)) {
                    $update_fields[] = "$field = :$field";
                    $params[":$field"] = $data->$field;
                }
            }

            if (empty($update_fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $query = "UPDATE inventory SET " . implode(', ', $update_fields) .
                     ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";

            $stmt = $this->conn->prepare($query);

            if ($stmt->execute($params) && $stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Inventory item updated successfully'
                ]);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => 'Inventory item not found'
                ]);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to update inventory: ' . $e->getMessage()
            ]);
        }
    }

    // POST create new inventory item - /inventory/create
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        $allowed_types = ['vaccination', 'medicine', 'microchip', 'equipment'];
if (!isset($data->item_type) || !in_array($data->item_type, $allowed_types) || !isset($data->item_name) || !isset($data->current_stock)) {
    http_response_code(400);
    echo json_encode(['error' => 'item_type (vaccination/medicine/microchip/equipment), item_name, and current_stock are required']);
    return;
}

        try {
            $query = "INSERT INTO inventory (item_type, item_name, species, current_stock, minimum_stock, unit, notes)
                      VALUES (:item_type, :item_name, :species, :current_stock, :minimum_stock, :unit, :notes)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':item_type',     $data->item_type);
            $stmt->bindParam(':item_name',     $data->item_name);
            $species = isset($data->species) ? $data->species : null;
            $stmt->bindParam(':species',       $species);
            $stmt->bindParam(':current_stock', $data->current_stock, PDO::PARAM_INT);
            $min = isset($data->minimum_stock) ? intval($data->minimum_stock) : 10;
            $stmt->bindParam(':minimum_stock', $min, PDO::PARAM_INT);
            $unit = isset($data->unit) ? $data->unit : 'pcs';
            $stmt->bindParam(':unit',          $unit);
            $notes = isset($data->notes) ? $data->notes : null;
            $stmt->bindParam(':notes',         $notes);

            if ($stmt->execute()) {
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Inventory item created successfully',
                    'id'      => $this->conn->lastInsertId()
                ]);
            } else {
                throw new Exception('Insert failed');
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Failed to create inventory item: ' . $e->getMessage()
            ]);
        }
    }

    // GET low stock items - /inventory/low-stock
    public function getLowStock() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT * FROM inventory 
                      WHERE current_stock <= minimum_stock 
                      ORDER BY current_stock ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'inventory' => $items,
                'total' => count($items)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get low stock items: ' . $e->getMessage()
            ]);
        }
    }
    public function getBatchAvailability($inventoryIdRaw) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        // Support ?exclude_schedule= passed as part of the path string
        $parts = explode('?', $inventoryIdRaw);
        $inventoryId = intval($parts[0]);
        $excludeScheduleId = isset($_GET['exclude_schedule']) ? intval($_GET['exclude_schedule']) : null;

        try {
            $batchStmt = $this->conn->prepare(
                "SELECT b.*, i.unit FROM inventory_batches b
                 JOIN inventory i ON i.id = b.inventory_id
                 WHERE b.inventory_id = :inventory_id AND b.quantity > 0
                 ORDER BY b.expiration_date ASC, b.created_at ASC"
            );
            $batchStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
            $batchStmt->execute();
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

            $today = date('Y-m-d');
            foreach ($batches as &$batch) {
                $excludeClause = $excludeScheduleId ? "AND svb.schedule_id != :exclude_id" : "";
                $sql = "SELECT COALESCE(SUM(svb.allocated_qty), 0) as total_allocated
                        FROM schedule_vaccine_batches svb
                        WHERE svb.batch_id = :batch_id
                          AND (
                            EXISTS (
                              SELECT 1 FROM vaccination_schedules vs
                              WHERE vs.id = svb.schedule_id
                                AND vs.status IN ('scheduled', 'ongoing')
                                AND vs.scheduled_date >= :today
                                {$excludeClause}
                            )
                            OR EXISTS (
                              SELECT 1 FROM microchip_schedules ms
                              WHERE ms.id = svb.schedule_id
                                AND ms.status IN ('scheduled', 'ongoing')
                                AND ms.scheduled_date >= :today2
                                {$excludeClause}
                            )
                          )";
                $params = [':batch_id' => $batch['id'], ':today' => $today, ':today2' => $today];

                if ($excludeScheduleId) {
                    $params[':exclude_id'] = $excludeScheduleId;
                }

                $allocStmt = $this->conn->prepare($sql);
                $allocStmt->execute($params);
                $allocated = intval($allocStmt->fetch(PDO::FETCH_ASSOC)['total_allocated']);

                $batch['allocated_qty'] = $allocated;
                $batch['available_qty'] = max(0, intval($batch['quantity']) - $allocated);
            }

            echo json_encode(['success' => true, 'batches' => $batches]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get batch availability: ' . $e->getMessage()]);
        }
    }

    // POST save schedule vaccine batch allocations - /inventory/schedule-allocations/{scheduleId}
    public function saveScheduleAllocations($scheduleId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"), true);

        try {
            $this->conn->beginTransaction();

            $del = $this->conn->prepare("DELETE FROM schedule_vaccine_batches WHERE schedule_id = :schedule_id");
            $del->execute([':schedule_id' => intval($scheduleId)]);

            if (!empty($data['allocations'])) {
                $ins = $this->conn->prepare(
                    "INSERT INTO schedule_vaccine_batches (schedule_id, inventory_id, batch_id, allocated_qty)
                     VALUES (:schedule_id, :inventory_id, :batch_id, :allocated_qty)"
                );
                foreach ($data['allocations'] as $alloc) {
                    if (intval($alloc['allocated_qty']) <= 0) continue;
                    $ins->execute([
                        ':schedule_id'   => intval($scheduleId),
                        ':inventory_id'  => intval($alloc['inventory_id']),
                        ':batch_id'      => intval($alloc['batch_id']),
                        ':allocated_qty' => intval($alloc['allocated_qty']),
                    ]);
                }
            }

            $this->conn->commit();
            echo json_encode(['success' => true, 'message' => 'Allocations saved']);

        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save allocations: ' . $e->getMessage()]);
        }
    }
    public function getRecordBatchAvailability($inventoryId) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official', 'private_clinic'], $user_data);

        try {
            $batchStmt = $this->conn->prepare(
                "SELECT b.*, i.unit FROM inventory_batches b
                 JOIN inventory i ON i.id = b.inventory_id
                 WHERE b.inventory_id = :inventory_id AND b.quantity > 0
                 ORDER BY b.expiration_date ASC, b.created_at ASC"
            );
            $batchStmt->bindParam(':inventory_id', $inventoryId, PDO::PARAM_INT);
            $batchStmt->execute();
            $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

            $today = date('Y-m-d');
            foreach ($batches as &$batch) {
                // Deduct qty already allocated to upcoming/ongoing vaccination events
                $excludeClause = "";
                $sql = "SELECT COALESCE(SUM(svb.allocated_qty), 0) as total_allocated
                        FROM schedule_vaccine_batches svb
                        WHERE svb.batch_id = :batch_id
                          AND (
                            EXISTS (
                              SELECT 1 FROM vaccination_schedules vs
                              WHERE vs.id = svb.schedule_id
                                AND vs.status IN ('scheduled', 'ongoing')
                                AND vs.scheduled_date >= :today
                            )
                            OR EXISTS (
                              SELECT 1 FROM microchip_schedules ms
                              WHERE ms.id = svb.schedule_id
                                AND ms.status IN ('scheduled', 'ongoing')
                                AND ms.scheduled_date >= :today2
                            )
                          )";
                $allocStmt = $this->conn->prepare($sql);
                $allocStmt->execute([':batch_id' => $batch['id'], ':today' => $today, ':today2' => $today]);
                $allocated = intval($allocStmt->fetch(PDO::FETCH_ASSOC)['total_allocated']);

                $batch['allocated_qty'] = $allocated;
                $batch['available_qty'] = max(0, intval($batch['quantity']) - $allocated);
            }

            echo json_encode(['success' => true, 'batches' => $batches]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get batch availability: ' . $e->getMessage()]);
        }
    }

    // POST deduct stock from a batch - /inventory/batch-deduct/{batchId}
    public function deductBatchStock($batchId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official', 'private_clinic'], $user_data);

        $data = json_decode(file_get_contents("php://input"), true);
        $qty = isset($data['quantity']) ? intval($data['quantity']) : 1;

        try {
            $this->conn->beginTransaction();

            // Get batch and lock it
            $stmt = $this->conn->prepare(
                "SELECT b.*, i.id as inv_id FROM inventory_batches b
                 JOIN inventory i ON i.id = b.inventory_id
                 WHERE b.id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $batchId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                $this->conn->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Batch not found']);
                return;
            }

            if (intval($batch['quantity']) < $qty) {
                $this->conn->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Insufficient batch stock']);
                return;
            }

            // Deduct from batch
            $this->conn->prepare(
                "UPDATE inventory_batches SET quantity = quantity - :qty, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':qty' => $qty, ':id' => $batchId]);

            $this->conn->commit();
            echo json_encode(['success' => true, 'message' => 'Stock deducted successfully']);

        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to deduct stock: ' . $e->getMessage()]);
        }
    }
}
?>