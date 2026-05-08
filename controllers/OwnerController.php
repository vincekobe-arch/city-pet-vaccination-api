<?php

require_once 'config/database.php';
require_once 'middleware/Auth.php';
require_once 'services/VerificationService.php';

class OwnerController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    // ──────────────────────────────────────────────────────────
    //  GET /owners  – list all pet owners (admin / official)
    // ──────────────────────────────────────────────────────────
    public function index() {
        $user_data = $this->auth->authenticate();

        try {
            $query = "SELECT u.id as user_id, u.*, b.name as barangay_name, b.code as barangay_code,
                         po.id                    as pet_owner_id,
                         po.first_name            as po_first_name,
                         po.middle_name           as po_middle_name,
                         po.last_name             as po_last_name,
                         po.birthdate             as po_birthdate,
                         po.gender                as po_gender,
                         po.phone                 as po_phone,
                         po.address               as po_address,
                         po.verification_status,
                         po.valid_id_type,
                         po.valid_id_front,
                         po.valid_id_back,
                         po.selfie_with_id,
                         po.id_submitted_at,
                         po.verified_at,
                         po.verification_notes,
                         u.last_login,
                         COUNT(DISTINCT p.id)     as total_pets,
                         COUNT(DISTINCT vr.id)    as total_vaccinations
                     FROM users u
                     LEFT JOIN pet_owners po   ON u.id = po.user_id
                     LEFT JOIN barangays b     ON u.assigned_barangay_id = b.id
                     LEFT JOIN pets p          ON po.id = p.owner_id AND p.is_active = 1
                     LEFT JOIN vaccination_records vr ON p.id = vr.pet_id
                     WHERE u.role = 'pet_owner'
                     GROUP BY u.id
                     ORDER BY u.created_at DESC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($owners as &$owner) {
                unset($owner['password']);
            }

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'owners'  => $owners,
                'total'   => count($owners)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Failed to get pet owners: ' . $e->getMessage()
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  GET /owners/user/{user_id}
    // ──────────────────────────────────────────────────────────
    public function getByUserId($user_id) {
        try {
            $query = "SELECT po.*,
                         u.username, u.email
                     FROM pet_owners po
                     JOIN users u ON po.user_id = u.id
                     WHERE po.user_id = :user_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'owner' => $owner]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Owner profile not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to get owner: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  POST /owners  – public registration (NO address)
    //  Address is collected later via the ID-verification flow.
    // ──────────────────────────────────────────────────────────
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        // Required fields – address intentionally excluded
        $required_fields = [
            'username', 'email', 'password',
            'first_name', 'last_name', 'phone',
            'verification_code'
        ];

        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty($data->$field)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error'   => ucfirst(str_replace('_', ' ', $field)) . ' is required'
                ]);
                return;
            }
        }

        // Verify email OTP first
        $verificationService = new VerificationService();
        $verificationResult  = $verificationService->verifyCode($data->email, $data->verification_code);

        if (!$verificationResult['success']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid or expired verification code. Please request a new code.'
            ]);
            return;
        }

        if (!$this->auth->validateEmail($data->email)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email format']);
            return;
        }

        $password_validation = $this->auth->validatePassword($data->password);
        if (!$password_validation['is_valid']) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Password validation failed',
                'details' => $password_validation['errors']
            ]);
            return;
        }

        try {
            // Duplicate username / email check
            $check_stmt = $this->conn->prepare(
                "SELECT id FROM users WHERE username = :username OR email = :email"
            );
            $check_stmt->bindParam(':username', $data->username);
            $check_stmt->bindParam(':email',    $data->email);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Username or email already exists']);
                return;
            }

            $this->conn->beginTransaction();

            $hashed_password = $this->auth->hashPassword($data->password);

            // ── Insert into users (no address at registration time) ──
            $user_query = "INSERT INTO users
                               (username, email, password, first_name, last_name, role, phone)
                           VALUES
                               (:username, :email, :password, :first_name, :last_name, 'pet_owner', :phone)";

            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':username',   $data->username);
            $user_stmt->bindParam(':email',      $data->email);
            $user_stmt->bindParam(':password',   $hashed_password);
            $user_stmt->bindParam(':first_name', $data->first_name);
            $user_stmt->bindParam(':last_name',  $data->last_name);
            $user_stmt->bindParam(':phone',      $data->phone);

            if (!$user_stmt->execute()) {
                $this->conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to create user account']);
                return;
            }

            $user_id = $this->conn->lastInsertId();

            // Optional fields
            $middle_name = isset($data->middle_name) && $data->middle_name !== '' ? $data->middle_name : null;
            $birthdate   = isset($data->birthdate)   && $data->birthdate   !== '' ? $data->birthdate   : null;
            $gender      = isset($data->gender)      && $data->gender      !== '' ? $data->gender      : null;

            // ── Insert into pet_owners ──
            // address = NULL (will be filled after ID verification)
            // verification_status defaults to 'not_verified' in DB
            $owner_query = "INSERT INTO pet_owners
                                (user_id, first_name, middle_name, last_name,
                                 birthdate, gender, phone,
                                 address, verification_status)
                            VALUES
                                (:user_id, :first_name, :middle_name, :last_name,
                                 :birthdate, :gender, :phone,
                                 NULL, 'not_verified')";

            $owner_stmt = $this->conn->prepare($owner_query);
            $owner_stmt->bindParam(':user_id',     $user_id);
            $owner_stmt->bindParam(':first_name',  $data->first_name);
            $owner_stmt->bindParam(':middle_name', $middle_name);
            $owner_stmt->bindParam(':last_name',   $data->last_name);
            $owner_stmt->bindParam(':birthdate',   $birthdate);
            $owner_stmt->bindParam(':gender',      $gender);
            $owner_stmt->bindParam(':phone',       $data->phone);

            if (!$owner_stmt->execute()) {
                $this->conn->rollback();
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to create owner details']);
                return;
            }

            $this->conn->commit();

            // Return the created owner details (without sensitive fields)
            $get_query = "SELECT u.id as user_id, u.username, u.email,
                              po.first_name, po.middle_name, po.last_name,
                              po.birthdate, po.gender, po.phone,
                              po.verification_status
                          FROM users u
                          JOIN pet_owners po ON u.id = po.user_id
                          WHERE u.id = :user_id";

            $get_stmt = $this->conn->prepare($get_query);
            $get_stmt->bindParam(':user_id', $user_id);
            $get_stmt->execute();
            $owner = $get_stmt->fetch(PDO::FETCH_ASSOC);

            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Pet owner registered successfully',
                'owner'   => $owner
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'Pet owner registration failed: ' . $e->getMessage()
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  GET /owners/{id}
    // ──────────────────────────────────────────────────────────
    public function show($id) {
        $user_data = $this->auth->authenticate();

        try {
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code,
                         po.first_name            as po_first_name,
                         po.middle_name           as po_middle_name,
                         po.last_name             as po_last_name,
                         po.birthdate             as po_birthdate,
                         po.gender                as po_gender,
                         po.phone                 as po_phone,
                         po.address               as po_address,
                         po.verification_status,
                         po.valid_id_type,
                         po.valid_id_front,
                         po.valid_id_back,
                         po.selfie_with_id,
                         po.id_submitted_at,
                         po.verified_at,
                         po.verification_notes,
                         COUNT(DISTINCT p.id)     as total_pets,
                         COUNT(DISTINCT vr.id)    as total_vaccinations,
                         COUNT(DISTINCT sr.id)    as total_schedule_registrations
                     FROM users u
                     JOIN pet_owners po          ON u.id = po.user_id
                     LEFT JOIN barangays b       ON u.assigned_barangay_id = b.id
                     LEFT JOIN pets p            ON po.id = p.owner_id AND p.is_active = 1
                     LEFT JOIN vaccination_records vr  ON p.id = vr.pet_id
                     LEFT JOIN schedule_registrations sr ON u.id = sr.user_id
                     WHERE u.id = :owner_id AND u.role = 'pet_owner' AND u.is_active = 1
                     GROUP BY u.id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':owner_id', $id);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Pet owner not found']);
                return;
            }

            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            unset($owner['password']);

            // Access control
            if ($user_data['role'] === 'pet_owner' && $user_data['user_id'] != $id) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this owner profile']);
                return;
            }

            if ($user_data['role'] === 'barangay_official') {
                if ($owner['assigned_barangay_id'] != $user_data['user_details']['assigned_barangay_id']) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Access denied to this owner profile']);
                    return;
                }
            }

            // Pets
            $pets_stmt = $this->conn->prepare(
                "SELECT p.*, COUNT(vr.id) as vaccination_count,
                    MAX(vr.vaccination_date) as last_vaccination_date
                 FROM pets p
                 LEFT JOIN vaccination_records vr ON p.id = vr.pet_id
                 WHERE p.owner_id = (SELECT id FROM pet_owners WHERE user_id = :owner_id)
                   AND p.is_active = 1
                 GROUP BY p.id
                 ORDER BY p.created_at DESC"
            );
            $pets_stmt->bindParam(':owner_id', $id);
            $pets_stmt->execute();
            $owner['pets'] = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Recent schedule registrations
            $reg_stmt = $this->conn->prepare(
                "SELECT sr.*,
                     CASE
                         WHEN sr.schedule_type = 'vaccination' THEN vs.title
                         WHEN sr.schedule_type = 'seminar'     THEN ss.title
                     END as schedule_title,
                     CASE
                         WHEN sr.schedule_type = 'vaccination' THEN vs.scheduled_date
                         WHEN sr.schedule_type = 'seminar'     THEN ss.scheduled_date
                     END as scheduled_date
                 FROM schedule_registrations sr
                 LEFT JOIN vaccination_schedules vs ON sr.schedule_id = vs.id AND sr.schedule_type = 'vaccination'
                 LEFT JOIN seminar_schedules ss      ON sr.schedule_id = ss.id AND sr.schedule_type = 'seminar'
                 WHERE sr.user_id = :owner_id
                 ORDER BY sr.registration_date DESC LIMIT 5"
            );
            $reg_stmt->bindParam(':owner_id', $id);
            $reg_stmt->execute();
            $owner['recent_registrations'] = $reg_stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'owner' => $owner]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get pet owner details: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  PUT /owners/{id}  – update profile
    // ──────────────────────────────────────────────────────────
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $data      = json_decode(file_get_contents("php://input"));

        try {
            $check_stmt = $this->conn->prepare(
                "SELECT u.id FROM users u
                 JOIN pet_owners po ON u.id = po.user_id
                 WHERE u.id = :owner_id AND u.role = 'pet_owner' AND u.is_active = 1"
            );
            $check_stmt->bindParam(':owner_id', $id);
            $check_stmt->execute();

            if ($check_stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Pet owner not found']);
                return;
            }

            if ($user_data['role'] === 'pet_owner' && $user_data['user_id'] != $id) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to update this profile']);
                return;
            }

            if (isset($data->email) && !$this->auth->validateEmail($data->email)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email format']);
                return;
            }

            if (isset($data->username) || isset($data->email)) {
                $dup_stmt = $this->conn->prepare(
                    "SELECT id FROM users
                     WHERE (username = :username OR email = :email) AND id != :owner_id"
                );
                $dup_stmt->bindParam(':username', $data->username ?? '');
                $dup_stmt->bindParam(':email',    $data->email    ?? '');
                $dup_stmt->bindParam(':owner_id', $id);
                $dup_stmt->execute();

                if ($dup_stmt->rowCount() > 0) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Username or email already exists']);
                    return;
                }
            }

            $this->conn->beginTransaction();

            // ── users table ──
            $user_fields  = [];
            $user_params  = [':user_id' => $id];
            $user_allowed = ['username', 'email', 'first_name', 'last_name', 'phone'];

            foreach ($user_allowed as $field) {
                if (isset($data->$field)) {
                    $user_fields[]        = "$field = :$field";
                    $user_params[":$field"] = $data->$field;
                }
            }

            if (isset($data->password)) {
                $pv = $this->auth->validatePassword($data->password);
                if (!$pv['is_valid']) {
                    $this->conn->rollback();
                    http_response_code(400);
                    echo json_encode(['error' => 'Password validation failed', 'details' => $pv['errors']]);
                    return;
                }
                $user_fields[]          = "password = :password";
                $user_params[':password'] = $this->auth->hashPassword($data->password);
            }

            if (!empty($user_fields)) {
                $this->conn->prepare(
                    "UPDATE users SET " . implode(', ', $user_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :user_id"
                )->execute($user_params);
            }

            // ── pet_owners table ──
            $owner_fields  = [];
            $owner_params  = [':user_id' => $id];
            // NOTE: address is only updated via the ID-verification / admin flow, NOT here
            $owner_allowed = ['first_name', 'middle_name', 'last_name', 'birthdate', 'gender', 'phone'];

            foreach ($owner_allowed as $field) {
                if (isset($data->$field)) {
                    $owner_fields[]         = "$field = :$field";
                    $owner_params[":$field"] = $data->$field;
                }
            }

            if (!empty($owner_fields)) {
                $this->conn->prepare(
                    "UPDATE pet_owners SET " . implode(', ', $owner_fields) . " WHERE user_id = :user_id"
                )->execute($owner_params);
            }

            $this->conn->commit();

            echo json_encode(['success' => true, 'message' => 'Pet owner updated successfully']);

        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Pet owner update failed: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  POST /owners/{id}/submit-id  – owner submits valid ID
    // ──────────────────────────────────────────────────────────
    public function submitId($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();

        if ($user_data['role'] === 'pet_owner' && $user_data['user_id'] != $id) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $data = json_decode(file_get_contents("php://input"));

        // Expect: valid_id_type, valid_id_front (base64/path), valid_id_back, selfie_with_id, address
        $required = ['valid_id_type', 'valid_id_front', 'address'];
        foreach ($required as $field) {
            if (empty($data->$field)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

        try {
            $stmt = $this->conn->prepare(
                "UPDATE pet_owners
                 SET valid_id_type       = :valid_id_type,
                     valid_id_front      = :valid_id_front,
                     valid_id_back       = :valid_id_back,
                     selfie_with_id      = :selfie_with_id,
                     address             = :address,
                     id_submitted_at     = NOW(),
                     verification_status = 'pending'
                 WHERE user_id = :user_id"
            );

            $valid_id_back  = $data->valid_id_back  ?? null;
            $selfie_with_id = $data->selfie_with_id ?? null;

            $stmt->bindParam(':valid_id_type',  $data->valid_id_type);
            $stmt->bindParam(':valid_id_front', $data->valid_id_front);
            $stmt->bindParam(':valid_id_back',  $valid_id_back);
            $stmt->bindParam(':selfie_with_id', $selfie_with_id);
            $stmt->bindParam(':address',        $data->address);
            $stmt->bindParam(':user_id',        $id);

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'ID submitted successfully. Status is now pending review.'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to submit ID']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'ID submission failed: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  PUT /owners/{id}/verify  – admin updates verification status
    // ──────────────────────────────────────────────────────────
    public function verifyOwner($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        $allowed_statuses = ['not_verified', 'pending', 'semi_verified', 'fully_verified'];
        if (!isset($data->verification_status) || !in_array($data->verification_status, $allowed_statuses)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid verification_status. Allowed: ' . implode(', ', $allowed_statuses)
            ]);
            return;
        }

        try {
            // If fully_verified, address must be present (either already stored or sent now)
            $extra_set    = '';
            $extra_params = [];

            if (isset($data->address) && $data->address !== '') {
                $extra_set              = ', address = :address';
                $extra_params[':address'] = $data->address;
            }

            if (isset($data->verification_notes)) {
                $extra_set                       .= ', verification_notes = :notes';
                $extra_params[':notes']           = $data->verification_notes;
            }

            // If declining (not_verified), also wipe all submitted ID data
            if ($data->verification_status === 'not_verified') {
                $extra_set .= ", valid_id_type = NULL
                     , valid_id_front = NULL
                     , valid_id_back = NULL
                     , selfie_with_id = NULL
                     , id_submitted_at = NULL
                     , verified_by = NULL
                     , verified_at = NULL
                     , verification_notes = NULL";
                $verified_by = null;
            } else {
                $verified_by = $user_data['user_id'];
            }

            $stmt = $this->conn->prepare(
                "UPDATE pet_owners
                 SET verification_status = :status,
                     verified_by         = :verified_by,
                     verified_at         = IF(:status2 = 'not_verified', NULL, NOW())
                     $extra_set
                 WHERE user_id = :owner_id"
            );

            $stmt->bindParam(':status',      $data->verification_status);
            $stmt->bindParam(':status2',     $data->verification_status);
            $stmt->bindParam(':verified_by', $verified_by);
            $stmt->bindParam(':owner_id',    $id);

            foreach ($extra_params as $key => $val) {
                $stmt->bindValue($key, $val);
            }

            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Verification status updated to: ' . $data->verification_status
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to update verification status']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Verification update failed: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  DELETE /owners/{id}  – soft-delete (deactivate)
    // ──────────────────────────────────────────────────────────
    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $check_pets = $this->conn->prepare(
                "SELECT COUNT(p.id) as pet_count
                 FROM pets p
                 JOIN pet_owners po ON p.owner_id = po.id
                 WHERE po.user_id = :owner_id AND p.is_active = 1"
            );
            $check_pets->bindParam(':owner_id', $id);
            $check_pets->execute();

            if ($check_pets->fetch(PDO::FETCH_ASSOC)['pet_count'] > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete owner with active pets. Please deactivate pets first.']);
                return;
            }

            $stmt = $this->conn->prepare(
                "UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :owner_id AND role = 'pet_owner'"
            );
            $stmt->bindParam(':owner_id', $id);

            if ($stmt->execute() && $stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Pet owner deactivated successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Pet owner not found']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Pet owner deletion failed: ' . $e->getMessage()]);
        }
    }
}
?>