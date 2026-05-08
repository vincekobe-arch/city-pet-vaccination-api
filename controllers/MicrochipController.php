<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';

class MicrochipController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    public function index() {
        $user_data = $this->auth->authenticate();

        try {
            $base_query = "SELECT mr.*, mr.qr_code, p.name as pet_name, p.registration_number, p.species,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name,
                     CONCAT(po.first_name, ' ', po.last_name) as owner_name,
                     CASE WHEN usr.role = 'private_clinic' THEN pc.clinic_name
                          ELSE 'City Vet Muntinlupa' END as recorded_by
                     FROM microchip_records mr
                     JOIN pets p ON mr.pet_id = p.id
                     JOIN users u ON mr.administered_by = u.id
                     JOIN users usr ON mr.administered_by = usr.id
                     LEFT JOIN private_clinics pc ON usr.id = pc.user_id
                     JOIN pet_owners po ON p.owner_id = po.id
                     WHERE p.is_active = 1";

            if ($user_data['role'] === 'private_clinic') {
                $base_query .= " AND mr.administered_by = :user_id";
            }

            $base_query .= " ORDER BY mr.implant_date DESC";

            $stmt = $this->conn->prepare($base_query);
            if ($user_data['role'] === 'private_clinic') {
                $stmt->bindParam(':user_id', $user_data['user_id']);
            }
            $stmt->execute();

            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'microchip_records' => $records,
                'total' => count($records)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get microchip records: ' . $e->getMessage()
            ]);
        }
    }

    // ── QR Code generator (pure PHP, no library needed) ─────────────────
    private function generateQRCode($data) {
        // Use Google Charts QR API as a fallback-free approach
        // We store the data string; frontend renders it via qrcode.js
        return base64_encode($data);
    }

    private function buildPetQRData($petId, $microchipNumber) {
        // Fetch full pet info for QR payload
        $query = "SELECT p.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as owner_name,
                         u.phone as owner_phone,
                         b.name as barangay_name
                  FROM pets p
                  LEFT JOIN pet_owners po ON p.owner_id = po.id
                  LEFT JOIN users u ON po.user_id = u.id
                  LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                  WHERE p.id = :pet_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pet_id', $petId);
        $stmt->execute();
        $pet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pet) return json_encode(['microchip' => $microchipNumber]);

        return json_encode([
            'microchip'    => $microchipNumber,
            'reg_no'       => $pet['registration_number'],
            'name'         => $pet['name'],
            'species'      => $pet['species'],
            'breed'        => $pet['breed'] ?? 'Unknown',
            'gender'       => $pet['gender'],
            'birth_date'   => $pet['birth_date'] ?? 'N/A',
            'color'        => $pet['color'] ?? 'N/A',
            'weight'       => $pet['weight'] ?? 'N/A',
            'sterilized'   => $pet['sterilized'] ? 'Yes' : 'No',
            'owner'        => $pet['owner_name'],
            'owner_phone'  => $pet['owner_phone'] ?? 'N/A',
            'barangay'     => $pet['barangay_name'] ?? 'N/A',
            'issued_by'    => 'City Vet Muntinlupa',
        ], JSON_UNESCAPED_UNICODE);
    }

    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin', 'private_clinic'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        $required_fields = ['pet_id', 'microchip_number', 'implant_date'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

        try {
            $this->conn->beginTransaction();

            // Check if pet already has a microchip record
            $pet_check = "SELECT id, microchip_number FROM pets WHERE id = :pet_id AND is_active = 1";
            $pet_stmt = $this->conn->prepare($pet_check);
            $pet_stmt->bindParam(':pet_id', $data->pet_id);
            $pet_stmt->execute();
            $pet_data = $pet_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pet_data) {
                $this->conn->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Pet not found', 'message' => 'The specified pet does not exist or is inactive.']);
                return;
            }

            // Check if this pet already has a microchip record
            $dup_pet_check = "SELECT id, microchip_number FROM microchip_records WHERE pet_id = :pet_id LIMIT 1";
            $dup_pet_stmt = $this->conn->prepare($dup_pet_check);
            $dup_pet_stmt->bindParam(':pet_id', $data->pet_id);
            $dup_pet_stmt->execute();

            if ($dup_pet_stmt->rowCount() > 0) {
                $existing = $dup_pet_stmt->fetch(PDO::FETCH_ASSOC);
                $this->conn->rollBack();
                http_response_code(409);
                echo json_encode([
                    'error' => 'Pet already microchipped',
                    'message' => 'This pet already has a microchip record.',
                    'details' => ['microchip_number' => $existing['microchip_number']]
                ]);
                return;
            }

            // Check if microchip number is already used by another pet (skip for QR-only records)
            $microchip_subtype = isset($data->microchip_subtype) ? $data->microchip_subtype : 'microchip';
            if ($microchip_subtype === 'microchip') {
                $chip_check = "SELECT id FROM microchip_records WHERE microchip_number = :microchip_number LIMIT 1";
                $chip_stmt = $this->conn->prepare($chip_check);
                $chip_stmt->bindParam(':microchip_number', $data->microchip_number);
                $chip_stmt->execute();

                if ($chip_stmt->rowCount() > 0) {
                    $this->conn->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error' => 'Microchip number already exists',
                        'message' => 'This microchip number is already registered to another pet.'
                    ]);
                    return;
                }
            }

            // Insert microchip record
            $query = "INSERT INTO microchip_records
                     (pet_id, microchip_number, implant_date, implant_site,
                      microchip_brand, veterinarian_name, weight, notes, administered_by, batch_id, clinic_batch_id)
                     VALUES
                     (:pet_id, :microchip_number, :implant_date, :implant_site,
                      :microchip_brand, :veterinarian_name, :weight, :notes, :administered_by, :batch_id, :clinic_batch_id)";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $data->pet_id);
            $stmt->bindParam(':microchip_number', $data->microchip_number);
            $stmt->bindParam(':implant_date', $data->implant_date);

            $implant_site = isset($data->implant_site) ? $data->implant_site : null;
            $stmt->bindParam(':implant_site', $implant_site);

            $microchip_brand = isset($data->microchip_brand) ? $data->microchip_brand : null;
            $stmt->bindParam(':microchip_brand', $microchip_brand);

            $veterinarian_name = isset($data->veterinarian_name) ? $data->veterinarian_name : null;
            $stmt->bindParam(':veterinarian_name', $veterinarian_name);

            $weight = isset($data->weight) ? $data->weight : null;
            $stmt->bindParam(':weight', $weight);

            $notes = isset($data->notes) ? $data->notes : null;
            $stmt->bindParam(':notes', $notes);

            $stmt->bindParam(':administered_by', $user_data['user_id']);

            // Only store batch_id for physical microchip, not QR
            $batch_id_val = ($microchip_subtype === 'microchip' && isset($data->batch_id) && !empty($data->batch_id))
                ? intval($data->batch_id) : null;
            $stmt->bindParam(':batch_id', $batch_id_val, PDO::PARAM_INT);

            if (!$stmt->execute()) {
                throw new Exception('Failed to create microchip record');
            }

            $microchip_id = $this->conn->lastInsertId();

            // Generate QR code data and store it
            $qrData = $this->buildPetQRData($data->pet_id, $data->microchip_number);
            $qrBase64 = $this->generateQRCode($qrData);
            $qrStmt = $this->conn->prepare("UPDATE microchip_records SET qr_code = :qr WHERE id = :id");
            $qrStmt->bindParam(':qr', $qrBase64);
            $qrStmt->bindParam(':id', $microchip_id, PDO::PARAM_INT);
            $qrStmt->execute();

            // Update pets table microchip_number (only for physical microchip, not QR-only)
            if ($microchip_subtype === 'microchip') {
                $update_pet = "UPDATE pets SET microchip_number = :microchip_number WHERE id = :pet_id";
                $update_stmt = $this->conn->prepare($update_pet);
                $update_stmt->bindParam(':microchip_number', $data->microchip_number);
                $update_stmt->bindParam(':pet_id', $data->pet_id);
                $update_stmt->execute();
            }

            $this->conn->commit();

            // Fetch the created record with details
            $get_query = "SELECT mr.*, CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                         FROM microchip_records mr
                         JOIN users u ON mr.administered_by = u.id
                         WHERE mr.id = :microchip_id";
            $get_stmt = $this->conn->prepare($get_query);
            $get_stmt->bindParam(':microchip_id', $microchip_id);
            $get_stmt->execute();
            $record = $get_stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Microchip record created successfully',
                'microchip_record' => $record
            ]);

        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Microchip record creation failed: ' . $e->getMessage()]);
        }
    }

    public function show($id) {
        $user_data = $this->auth->authenticate();

        try {
            $query = "SELECT mr.*, mr.qr_code, p.name as pet_name, p.registration_number, p.species,
                     CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM microchip_records mr
                     JOIN pets p ON mr.pet_id = p.id
                     JOIN users u ON mr.administered_by = u.id
                     WHERE mr.id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'microchip_record' => $record]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Microchip record not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get microchip record: ' . $e->getMessage()]);
        }
    }

    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        try {
            $this->conn->beginTransaction();

            $get_pet_query = "SELECT pet_id, microchip_number FROM microchip_records WHERE id = :id";
            $get_pet_stmt = $this->conn->prepare($get_pet_query);
            $get_pet_stmt->bindParam(':id', $id);
            $get_pet_stmt->execute();
            $existing = $get_pet_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                throw new Exception('Microchip record not found');
            }

            $update_fields = [];
            $params = [':id' => $id];

            $allowed_fields = ['implant_date', 'implant_site', 'microchip_brand',
                               'veterinarian_name', 'weight', 'notes'];

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

            $query = "UPDATE microchip_records SET " . implode(', ', $update_fields) .
                    ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            if (!$stmt->execute($params)) {
                throw new Exception('Failed to update microchip record');
            }

            $this->conn->commit();

            echo json_encode(['success' => true, 'message' => 'Microchip record updated successfully']);

        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Microchip record update failed: ' . $e->getMessage()]);
        }
    }

    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['barangay_official', 'super_admin'], $user_data);

        try {
            $this->conn->beginTransaction();

            $get_query = "SELECT mr.pet_id, u.role FROM microchip_records mr JOIN users u ON mr.administered_by = u.id WHERE mr.id = :id";
$get_stmt = $this->conn->prepare($get_query);
$get_stmt->bindParam(':id', $id);
$get_stmt->execute();
$record = $get_stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    throw new Exception('Microchip record not found');
}

if ($record['role'] === 'private_clinic') {
    $this->conn->rollBack();
    http_response_code(403);
    echo json_encode(['error' => 'Cannot delete records created by a private clinic']);
    return;
}

            // Get batch ids before deleting so we can restore stock
            $get_batch_stmt = $this->conn->prepare(
                "SELECT batch_id, clinic_batch_id FROM microchip_records WHERE id = :id"
            );
            $get_batch_stmt->bindParam(':id', $id);
            $get_batch_stmt->execute();
            $batch_row = $get_batch_stmt->fetch(PDO::FETCH_ASSOC);
            $batch_id_to_restore = $batch_row ? $batch_row['batch_id'] : null;
            $clinic_batch_id_to_restore = $batch_row ? $batch_row['clinic_batch_id'] : null;

            $query = "DELETE FROM microchip_records WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if (!$stmt->execute()) {
                throw new Exception('Failed to delete microchip record');
            }

            // Restore 1 unit to admin batch
            if ($batch_id_to_restore) {
                $this->conn->prepare(
                    "UPDATE inventory_batches SET quantity = quantity + 1,
                     updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                )->execute([':batch_id' => $batch_id_to_restore]);
            }

            // Restore 1 unit to clinic batch
            if ($clinic_batch_id_to_restore) {
                $this->conn->prepare(
                    "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                     updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                )->execute([':batch_id' => $clinic_batch_id_to_restore]);
            }

            // Clear microchip_number from pets table
            $update_pet = "UPDATE pets SET microchip_number = NULL WHERE id = :pet_id";
            $update_stmt = $this->conn->prepare($update_pet);
            $update_stmt->bindParam(':pet_id', $record['pet_id']);
            $update_stmt->execute();

            $this->conn->commit();

            echo json_encode(['success' => true, 'message' => 'Microchip record deleted successfully']);

        } catch (Exception $e) {
            $this->conn->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Microchip record deletion failed: ' . $e->getMessage()]);
        }
    }

    public function getByPetId($pet_id) {
        try {
            $query = "SELECT mr.*, CONCAT(u.first_name, ' ', u.last_name) as administered_by_name
                     FROM microchip_records mr
                     JOIN users u ON mr.administered_by = u.id
                     WHERE mr.pet_id = :pet_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':pet_id', $pet_id);
            $stmt->execute();

            $record = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'microchip_record' => $record]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get microchip record: ' . $e->getMessage()]);
        }
    }
}
?>