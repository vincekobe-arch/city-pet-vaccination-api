<?php
// controllers/ClinicInventoryController.php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class ClinicInventoryController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    /**
     * Authenticate as private_clinic and resolve the clinic_id
     * that belongs to the logged-in user.
     *
     * Returns the integer clinic_id.
     * Aborts with 403 / 404 on failure.
     */
    private function getClinicId(array $user_data): int {
        $stmt = $this->conn->prepare(
            "SELECT id FROM private_clinics WHERE user_id = :uid AND is_active = 1"
        );
        $stmt->execute([':uid' => $user_data['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Active clinic not found for this user']);
            exit;
        }

        return (int) $row['id'];
    }

    /**
     * Confirm that the given inventory item belongs to the
     * requesting clinic.  Aborts with 403 if it does not.
     */
    private function assertOwnership(int $itemId, int $clinicId): void {
        $stmt = $this->conn->prepare(
            "SELECT id FROM clinic_inventory WHERE id = :id AND clinic_id = :cid"
        );
        $stmt->execute([':id' => $itemId, ':cid' => $clinicId]);
        if ($stmt->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied: item does not belong to your clinic']);
            exit;
        }
    }

    // ─────────────────────────────────────────────
    // GET  /clinic/inventory
    // ─────────────────────────────────────────────
    public function index(): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        try {
            $query = "SELECT * FROM clinic_inventory
                      WHERE clinic_id = :cid
                      ORDER BY FIELD(item_type, 'vaccination', 'deworming', 'medicine', 'equipment'),
                               item_name ASC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':cid' => $clinicId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success'   => true,
                'inventory' => $items,
                'total'     => count($items)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get inventory: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // GET  /clinic/inventory/type/{type}
    // ─────────────────────────────────────────────
    public function getByType(string $type): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        $allowed = ['vaccination', 'deworming', 'medicine', 'equipment'];
if (!in_array($type, $allowed)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item_type. Allowed: ' . implode(', ', $allowed)]);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM clinic_inventory
                 WHERE clinic_id = :cid AND item_type = :type
                 ORDER BY item_name ASC"
            );
            $stmt->execute([':cid' => $clinicId, ':type' => $type]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'inventory' => $items, 'total' => count($items)]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get inventory by type: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // GET  /clinic/inventory/show/{id}
    // ─────────────────────────────────────────────
    public function show(int $id): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        try {
            $stmt = $this->conn->prepare("SELECT * FROM clinic_inventory WHERE id = :id");
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'item' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Inventory item not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get item: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // GET  /clinic/inventory/low-stock
    // ─────────────────────────────────────────────
    public function getLowStock(): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM clinic_inventory
                 WHERE clinic_id = :cid AND current_stock <= minimum_stock
                 ORDER BY current_stock ASC"
            );
            $stmt->execute([':cid' => $clinicId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'inventory' => $items, 'total' => count($items)]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get low-stock items: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // POST /clinic/inventory/create
    // ─────────────────────────────────────────────
    public function create(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->item_type) || empty($data->item_name)) {
            http_response_code(400);
            echo json_encode(['error' => 'item_type and item_name are required']);
            return;
        }

        $allowed_types = ['vaccination', 'deworming', 'medicine', 'equipment'];
if (!in_array($data->item_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid item_type. Allowed: ' . implode(', ', $allowed_types)]);
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO clinic_inventory
                   (clinic_id, item_type, item_name, species, current_stock, minimum_stock, unit, notes)
                 VALUES
                   (:clinic_id, :item_type, :item_name, :species, :current_stock, :minimum_stock, :unit, :notes)"
            );

            $stmt->execute([
                ':clinic_id'     => $clinicId,
                ':item_type'     => $data->item_type,
                ':item_name'     => trim($data->item_name),
                ':species'       => isset($data->species)       ? $data->species       : null,
                ':current_stock' => isset($data->current_stock) ? intval($data->current_stock) : 0,
                ':minimum_stock' => isset($data->minimum_stock) ? intval($data->minimum_stock) : 10,
                ':unit'          => isset($data->unit)          ? $data->unit          : 'pcs',
                ':notes'         => isset($data->notes)         ? $data->notes         : null,
            ]);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Inventory item created successfully',
                'id'      => $this->conn->lastInsertId()
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to create item: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // PUT  /clinic/inventory/update/{id}
    // ─────────────────────────────────────────────
    public function update(int $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        $data = json_decode(file_get_contents("php://input"));

        try {
            $fields = [];
            $params = [':id' => $id];

            $allowed = ['item_name', 'species', 'minimum_stock', 'unit', 'notes'];
            foreach ($allowed as $f) {
                if (isset($data->$f)) {
                    $fields[]   = "$f = :$f";
                    $params[":$f"] = $data->$f;
                }
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updatable fields provided']);
                return;
            }

            $query = "UPDATE clinic_inventory SET " . implode(', ', $fields) .
                     ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            $stmt  = $this->conn->prepare($query);

            if ($stmt->execute($params) && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Inventory item updated successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Inventory item not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update item: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // DELETE /clinic/inventory/delete/{id}
    // ─────────────────────────────────────────────
    public function delete(int $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        try {
            $stmt = $this->conn->prepare("DELETE FROM clinic_inventory WHERE id = :id");
            if ($stmt->execute([':id' => $id]) && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Inventory item deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Inventory item not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to delete item: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // PUT  /clinic/inventory/restock/{id}
    // ─────────────────────────────────────────────
    public function restock(int $id): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        $data = json_decode(file_get_contents("php://input"));

        if (!isset($data->quantity) || !is_numeric($data->quantity) || intval($data->quantity) <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'A valid quantity greater than 0 is required']);
            return;
        }

        if (!isset($data->batch_no) || trim($data->batch_no) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'batch_no is required']);
            return;
        }

        try {
            // Unique batch_no check
            $check = $this->conn->prepare(
                "SELECT id FROM clinic_inventory_batches WHERE batch_no = :batch_no"
            );
            $check->execute([':batch_no' => $data->batch_no]);
            if ($check->rowCount() > 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Batch number already exists. Use a unique batch number.']);
                return;
            }

            $this->conn->beginTransaction();

            $batchStmt = $this->conn->prepare(
                "INSERT INTO clinic_inventory_batches
                   (inventory_id, batch_no, quantity, expiration_date, notes)
                 VALUES
                   (:inventory_id, :batch_no, :quantity, :expiration_date, :notes)"
            );

            $qty     = intval($data->quantity);
            $batchNo = trim($data->batch_no);
            $expDate = (isset($data->expiration_date) && $data->expiration_date !== '') ? $data->expiration_date : null;
            $notes   = isset($data->notes) ? $data->notes : null;

            $batchStmt->execute([
                ':inventory_id'    => $id,
                ':batch_no'        => $batchNo,
                ':quantity'        => $qty,
                ':expiration_date' => $expDate,
                ':notes'           => $notes,
            ]);

            // Touch last_restocked_at (triggers handle current_stock)
            $this->conn->prepare(
                "UPDATE clinic_inventory SET last_restocked_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':id' => $id]);

            $this->conn->commit();

            $get = $this->conn->prepare("SELECT * FROM clinic_inventory WHERE id = :id");
            $get->execute([':id' => $id]);
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

    // ─────────────────────────────────────────────
    // GET  /clinic/inventory/batches/{id}
    // ─────────────────────────────────────────────
    public function getBatches(int $id): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        try {
            $stmt = $this->conn->prepare(
                "SELECT b.*, i.item_name, i.unit
                 FROM clinic_inventory_batches b
                 JOIN clinic_inventory i ON i.id = b.inventory_id
                 WHERE b.inventory_id = :id
                 ORDER BY b.created_at DESC"
            );
            $stmt->execute([':id' => $id]);

            echo json_encode([
                'success' => true,
                'batches' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get batches: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // PUT  /clinic/inventory/batch/update/{batchId}
    // ─────────────────────────────────────────────
    public function updateBatch(int $batchId): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        // Verify batch belongs to this clinic's inventory
        $ownerCheck = $this->conn->prepare(
            "SELECT b.id FROM clinic_inventory_batches b
             JOIN clinic_inventory i ON i.id = b.inventory_id
             WHERE b.id = :bid AND i.clinic_id = :cid"
        );
        $ownerCheck->execute([':bid' => $batchId, ':cid' => $clinicId]);
        if ($ownerCheck->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied: batch does not belong to your clinic']);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        try {
            // Unique batch_no check (excluding self)
            if (isset($data->batch_no)) {
                $check = $this->conn->prepare(
                    "SELECT id FROM clinic_inventory_batches
                     WHERE batch_no = :batch_no AND id != :id"
                );
                $check->execute([':batch_no' => $data->batch_no, ':id' => $batchId]);
                if ($check->rowCount() > 0) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'error' => 'Batch number already exists.']);
                    return;
                }
            }

            $fields = [];
            $params = [':id' => $batchId];

            foreach (['batch_no', 'quantity', 'expiration_date', 'notes'] as $f) {
                if (isset($data->$f)) {
                    $fields[]   = "$f = :$f";
                    $params[":$f"] = $data->$f;
                }
            }

            if (empty($fields)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                return;
            }

            $query = "UPDATE clinic_inventory_batches SET " . implode(', ', $fields) .
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

    // ─────────────────────────────────────────────
    // DELETE /clinic/inventory/batch/delete/{batchId}
    // ─────────────────────────────────────────────
    public function deleteBatch(int $batchId): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId  = $this->getClinicId($user_data);

        $ownerCheck = $this->conn->prepare(
            "SELECT b.id FROM clinic_inventory_batches b
             JOIN clinic_inventory i ON i.id = b.inventory_id
             WHERE b.id = :bid AND i.clinic_id = :cid"
        );
        $ownerCheck->execute([':bid' => $batchId, ':cid' => $clinicId]);
        if ($ownerCheck->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied: batch does not belong to your clinic']);
            return;
        }

        try {
            $stmt = $this->conn->prepare("DELETE FROM clinic_inventory_batches WHERE id = :id");
            if ($stmt->execute([':id' => $batchId]) && $stmt->rowCount() > 0) {
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
    // GET record-batch-availability - /clinic/inventory/record-batch-availability/{id}
    public function getRecordBatchAvailability(int $id): void {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId = $this->getClinicId($user_data);
        $this->assertOwnership($id, $clinicId);

        try {
            $stmt = $this->conn->prepare(
                "SELECT b.*, i.unit FROM clinic_inventory_batches b
                 JOIN clinic_inventory i ON i.id = b.inventory_id
                 WHERE b.inventory_id = :id AND b.quantity > 0
                 ORDER BY b.expiration_date ASC, b.created_at ASC"
            );
            $stmt->execute([':id' => $id]);
            $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // For clinic inventory, available_qty = quantity (no schedule allocation system)
            foreach ($batches as &$batch) {
                $batch['available_qty'] = intval($batch['quantity']);
                $batch['allocated_qty'] = 0;
            }

            echo json_encode(['success' => true, 'batches' => $batches]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get batch availability: ' . $e->getMessage()]);
        }
    }

    // POST deduct stock from clinic batch - /clinic/inventory/batch-deduct/{batchId}
    public function deductBatchStock(int $batchId): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinicId = $this->getClinicId($user_data);

        // Verify batch belongs to this clinic
        $ownerCheck = $this->conn->prepare(
            "SELECT b.id FROM clinic_inventory_batches b
             JOIN clinic_inventory i ON i.id = b.inventory_id
             WHERE b.id = :bid AND i.clinic_id = :cid"
        );
        $ownerCheck->execute([':bid' => $batchId, ':cid' => $clinicId]);
        if ($ownerCheck->rowCount() === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        $data = json_decode(file_get_contents("php://input"), true);
        $qty = isset($data['quantity']) ? intval($data['quantity']) : 1;

        try {
            $this->conn->beginTransaction();

            $stmt = $this->conn->prepare(
                "SELECT quantity FROM clinic_inventory_batches WHERE id = :id FOR UPDATE"
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

            $this->conn->prepare(
                "UPDATE clinic_inventory_batches SET quantity = quantity - :qty, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
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