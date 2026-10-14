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
                // Verify current password before allowing change
                $pw_stmt = $this->conn->prepare("SELECT password FROM users WHERE id = :id");
                $pw_stmt->bindParam(':id', $id);
                $pw_stmt->execute();
                $pw_row = $pw_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$pw_row || !$this->auth->verifyPassword($data->current_password ?? '', $pw_row['password'])) {
                    $this->conn->rollback();
                    http_response_code(400);
                    echo json_encode(['error' => 'Current password is incorrect']);
                    return;
                }

                $pv = $this->auth->validatePassword($data->password);
                if (!$pv['is_valid']) {
                    $this->conn->rollback();
                    http_response_code(400);
                    echo json_encode(['error' => 'Password validation failed', 'details' => $pv['errors']]);
                    return;
                }
                $user_fields[]            = "password = :password";
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
        $required = ['valid_id_type', 'valid_id_front', 'valid_id_back', 'address'];
        foreach ($required as $field) {
            if (!isset($data->$field) || trim((string)$data->$field) === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

// Determine initial verification status based on OCR result
$initial_status = isset($data->is_muntinlupa) && $data->is_muntinlupa === true
    ? 'fully_verified' // Muntinlupa resident → auto fully verified
    : 'semi_verified'; // Non-Muntinlupa → auto semi verified

        try {
            $this->conn->beginTransaction();

            $valid_id_back  = $data->valid_id_back  ?? null;
            $selfie_with_id = $data->selfie_with_id ?? null;
            $address        = trim((string)$data->address);

            // Update pet_owners with ID images, address, and verification status
            $stmt = $this->conn->prepare(
                "UPDATE pet_owners
                 SET valid_id_type       = :valid_id_type,
                     valid_id_front      = :valid_id_front,
                     valid_id_back       = :valid_id_back,
                     selfie_with_id      = :selfie_with_id,
                     address             = :address,
                     id_submitted_at     = NOW(),
                     verification_status = :verification_status
                 WHERE user_id = :user_id"
            );
            $stmt->bindParam(':valid_id_type',       $data->valid_id_type);
            $stmt->bindParam(':valid_id_front',      $data->valid_id_front);
            $stmt->bindParam(':valid_id_back',       $valid_id_back);
            $stmt->bindParam(':selfie_with_id',      $selfie_with_id);
            $stmt->bindParam(':address',             $address);
            $stmt->bindParam(':verification_status', $initial_status);
            $stmt->bindParam(':user_id',             $id);
            $stmt->execute();

            // Also sync address to users table
            $user_stmt = $this->conn->prepare(
                "UPDATE users
                 SET address    = :address,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :user_id"
            );
            $user_stmt->bindParam(':address', $address);
            $user_stmt->bindParam(':user_id', $id);
            $user_stmt->execute();

            $this->conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'ID submitted successfully. Status is now pending review.'
            ]);

        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'ID submission failed: ' . $e->getMessage()]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  POST /owners/scan-id  – AI-powered ID scan & field extraction
    // ──────────────────────────────────────────────────────────
    public function scanId() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            return;
        }

        // Must be logged in (any authenticated user can scan their own ID)
        $user_data = $this->auth->authenticate();

        if (!isset($_FILES['id_front']) || $_FILES['id_front']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID front image is required']);
            return;
        }

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
        $max_size      = 8 * 1024 * 1024; // 8MB

        $front = $_FILES['id_front'];
        if (!in_array(mime_content_type($front['tmp_name']), $allowed_mimes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID front must be a JPEG, PNG, or WEBP image']);
            return;
        }
        if ($front['size'] > $max_size) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'ID front image is too large (max 8MB)']);
            return;
        }

        $back = null;
        if (isset($_FILES['id_back']) && $_FILES['id_back']['error'] === UPLOAD_ERR_OK) {
            $back = $_FILES['id_back'];
            if (!in_array(mime_content_type($back['tmp_name']), $allowed_mimes)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID back must be a JPEG, PNG, or WEBP image']);
                return;
            }
            if ($back['size'] > $max_size) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'ID back image is too large (max 8MB)']);
                return;
            }
        }

        try {
            $result = $this->callClaudeForIdExtraction($front, $back);

            // Detect front/back mix-ups and mismatched ID pairs (only relevant when both sides were uploaded)
            if ($back && ($result['valid'] ?? false) === true) {
                $side        = $result['side_analysis'] ?? [];
                $frontIsBack = $side['front_looks_like_back'] ?? false;
                $backIsFront = $side['back_looks_like_front'] ?? false;
                $sameId      = $side['same_id'] ?? true;

                if ($frontIsBack && $backIsFront) {
                    $result['valid']      = false;
                    $result['error_type'] = 'sides_swapped';
                    $result['reason']     = 'Your front and back ID photos look swapped. Please upload the front of your ID (with your photo) in the "ID Front" slot, and the back (with the barcode) in the "ID Back" slot.';
                } elseif ($frontIsBack) {
                    $result['valid']      = false;
                    $result['error_type'] = 'front_is_back';
                    $result['reason']     = 'The photo in the "ID Front" slot looks like the back of an ID. Please upload the side that shows your photo and name.';
                } elseif ($backIsFront) {
                    $result['valid']      = false;
                    $result['error_type'] = 'back_is_front';
                    $result['reason']     = 'The photo in the "ID Back" slot looks like the front of an ID. Please upload the side that shows the barcode or terms and conditions.';
                } elseif (!$sameId) {
                    $result['valid']      = false;
                    $result['error_type'] = 'id_mismatch';
                    $result['reason']     = 'Your front and back photos do not appear to be from the same ID. Please make sure both sides are from the same card.';
                }
            }

            // Block IDs whose name + birthdate already belong to a semi/fully verified OTHER account
            if (($result['valid'] ?? false) === true) {
                $ex      = $result['extracted'] ?? [];
                $idFirst = strtolower(trim($ex['first_name'] ?? ''));
                $idLast  = strtolower(trim($ex['last_name']  ?? ''));
                $idBirth = trim($ex['birthdate'] ?? '');

                // Birthdate is required to tell two people with the same name apart
                if ($idFirst !== '' && $idLast !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $idBirth)) {
                    $dup_stmt = $this->conn->prepare(
                        "SELECT po.id FROM pet_owners po
                         WHERE po.user_id != :uid
                           AND po.verification_status IN ('semi_verified', 'fully_verified')
                           AND LOWER(TRIM(po.first_name)) = :first
                           AND LOWER(TRIM(po.last_name))  = :last
                           AND po.birthdate = :birth
                         LIMIT 1"
                    );
                    $dup_stmt->bindValue(':uid',   $user_data['user_id']);
                    $dup_stmt->bindValue(':first', $idFirst);
                    $dup_stmt->bindValue(':last',  $idLast);
                    $dup_stmt->bindValue(':birth', $idBirth);
                    $dup_stmt->execute();

                    if ($dup_stmt->rowCount() > 0) {
                        $result['duplicate'] = [
                            'found'   => true,
                            'message' => 'This name and birthdate are already registered and verified under another account. Each person can only have one verified account. If you believe this is a mistake, please contact your barangay office.'
                        ];
                    }
                }
            }

            http_response_code(200);
            echo json_encode($result);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'valid'   => false,
                'reason'  => 'ID scan failed: ' . $e->getMessage()
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Helper: send ID image(s) to Claude for structured extraction
    // ──────────────────────────────────────────────────────────
        private function callClaudeForIdExtraction($front, $back = null) {
        $apiKey = getenv('GEMINI_API_KEY');
        if (!$apiKey) {
            throw new Exception('Server is not configured with a Gemini API key');
        }

        $frontMime = mime_content_type($front['tmp_name']);
        $frontB64  = base64_encode(file_get_contents($front['tmp_name']));

        $idTypeOptions = '"Muntinlupa Care Card", "Philippine Passport", "Driver\'s License", "SSS ID", "GSIS ID", '
                        . '"PhilHealth ID", "Pag-IBIG ID", "Voter\'s ID", "Postal ID", "National ID (PhilSys)", '
                        . '"PRC ID", "Senior Citizen ID", "PWD ID", "Barangay ID", "Company ID", "Other"';

                                $promptText = 'You are extracting fields from a Philippine government-issued ID for identity verification. '
                    . 'Respond with ONLY raw JSON (no markdown fences, no commentary) matching exactly this schema: '
                    . '{"valid": boolean, "reason": string|null, "extracted": {"id_type": string, "first_name": string, "middle_name": string, '
                    . '"last_name": string, "suffix": string, "birthdate": string|null (format YYYY-MM-DD), '
                    . '"gender": "Male"|"Female"|"Other"|null, "address": string, "id_number": string, "phone": string, "is_muntinlupa": boolean, '
                    . '"address_parts": {"house_no": string, "street": string, "barangay": string, "city": string, "province": string}}, '
                    . '"raw_text": string, '
                    . '"side_analysis": {"front_looks_like_back": boolean, "back_looks_like_front": boolean, "same_id": boolean}}. '
                    . 'SIDE ANALYSIS RULES (only meaningful when a second/back image was actually provided; otherwise set '
                    . 'front_looks_like_back=false, back_looks_like_front=false, same_id=true): '
                    . 'The image given FIRST is labeled the "front" and should show a photo, name, and personal details. '
                    . 'The image given SECOND is labeled the "back" and typically shows a barcode/QR code, signature, or terms/conditions text, with no personal photo. '
                    . 'Set "front_looks_like_back" to true if the FIRST image actually shows barcode/terms content typical of a back side. '
                    . 'Set "back_looks_like_front" to true if the SECOND image actually shows a photo/name typical of a front side. '
                    . 'Set "same_id" to false if the two images clearly belong to two different ID cards (different ID type, different ID number, or different person), '
                    . 'and true if they appear to be the front and back of the same physical card. '
                    . '"id_type" must be EXACTLY one of these strings (pick the closest match, use "Other" only if none apply): '
                    . $idTypeOptions . '. '
                    . 'AUTHENTICITY: Do NOT attempt to judge whether the ID is fake, synthetic, digitally altered, or a photocopy. '
                    . 'That determination is made by human reviewers later. Your only job is to extract the text fields accurately. '
                    . 'Set "valid" to false ONLY if the image is so blurry or dark that you genuinely cannot read any name or address from it, '
                    . 'or if the image is clearly not an ID at all (e.g. a selfie, a landscape photo, a blank page). '
                    . 'If you can read a name and address, set "valid" to true regardless of image quality concerns. '
                    . '"is_muntinlupa" should be true only if the address on the ID is within Muntinlupa City. '
                    . 'Leave any field you cannot confidently read as an empty string (or null for birthdate/gender). '
                    . '"raw_text" must contain every piece of text you can read on the ID, including the full address. '
                    . 'ADDRESS RULES: "address" is the full address exactly as printed. "address_parts" splits that same address: '
                    . '"house_no" = house/lot/block/unit number only (e.g. "59", "Blk 5 Lot 3"); '
                    . '"street" = street name only (e.g. "Rizal St."); '
                    . '"barangay" = barangay name only, without "Brgy."; '
                    . '"city" = the city or municipality only, without "City of"; '
                    . '"province" = the province, or "Metro Manila" for NCR addresses. '
                    . 'IMPORTANT: a street named after a place (e.g. "Rizal St.", "Quezon Ave.") is a STREET, never the city or province. '
                    . 'The city and province normally come LAST in the address, just before the ZIP code. '
                    . 'PHONE RULE: "phone" is the holder\'s mobile/contact number ONLY if it is explicitly printed on the ID (e.g. labeled "Contact No.", "Mobile", "Tel"). '
                    . 'Never use the ID number, license number, barcode digits, or an emergency contact\'s number. Most IDs do not have a phone number, so return an empty string "" if none is printed.';

        $parts = [
            ['text' => $promptText],
            ['inline_data' => ['mime_type' => $frontMime, 'data' => $frontB64]]
        ];

        if ($back) {
            $backMime = mime_content_type($back['tmp_name']);
            $backB64  = base64_encode(file_get_contents($back['tmp_name']));
            $parts[] = ['inline_data' => ['mime_type' => $backMime, 'data' => $backB64]];
        }

        $payload = json_encode([
            'contents' => [
                ['parts' => $parts]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json'
            ]
        ]);

                $model = 'gemini-flash-lite-latest'; // free-tier model, ~500 RPD vs ~20 RPD on Flash
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $maxAttempts = 3;
        $response = false;
        $httpCode = 0;
        $curlErr  = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 45
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // 503 = model overloaded, safe to retry after a short pause
            if ($httpCode === 503 && $attempt < $maxAttempts) {
                sleep(2 * $attempt); // 2s, then 4s
                continue;
            }
            break;
        }

        if ($response === false) {
            throw new Exception('Request to AI service failed: ' . $curlErr);
        }
        if ($httpCode !== 200) {
            throw new Exception('AI service returned an error (HTTP ' . $httpCode . '): ' . $response);
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip accidental markdown fences, just in case
        $clean = trim(preg_replace('/^```json|```$/m', '', $text));
        $parsed = json_decode($clean, true);

        if (!is_array($parsed) || !array_key_exists('valid', $parsed)) {
            throw new Exception('Could not parse AI response');
        }

        // If rejected for suspected fakeness but text was readable, retry once with extraction-only prompt
        $syntheticKeywords = ['synthetic', 'digitally altered', 'manipulated', 'edited', 'fake', 'not authentic', 'fabricated', 'computer-generated'];
        $rejectedForAuthenticity = !($parsed['valid'] ?? true)
            && isset($parsed['reason'])
            && (function() use ($parsed, $syntheticKeywords) {
                $reason = strtolower($parsed['reason'] ?? '');
                foreach ($syntheticKeywords as $kw) {
                    if (str_contains($reason, $kw)) return true;
                }
                return false;
            })();

        if ($rejectedForAuthenticity) {
            // Retry with a prompt that explicitly forbids authenticity judgements
            $retryPrompt = 'Extract text fields from this Philippine ID image. '
                         . 'Do NOT assess whether the ID is real or fake — authenticity is verified by humans later. '
                         . 'Respond with ONLY raw JSON matching this schema: '
                         . '{"valid": boolean, "reason": string|null, "extracted": {"id_type": string, "first_name": string, "middle_name": string, '
                         . '"last_name": string, "suffix": string, "birthdate": string|null (format YYYY-MM-DD), '
                         . '"gender": "Male"|"Female"|"Other"|null, "address": string, "id_number": string, "phone": string, "is_muntinlupa": boolean, '
                         . '"address_parts": {"house_no": string, "street": string, "barangay": string, "city": string, "province": string}}, '
                         . '"raw_text": string, '
                         . '"side_analysis": {"front_looks_like_back": boolean, "back_looks_like_front": boolean, "same_id": boolean}}. '
                         . 'Set "valid" to false ONLY if the image is too blurry to read any text, or is clearly not an ID. '
                         . 'If any name and address is readable, set "valid" to true. '
                         . '"id_type" must be one of: ' . $idTypeOptions . '. '
                         . 'side_analysis: front_looks_like_back=false, back_looks_like_front=false, same_id=true unless clearly wrong. '
                         . '"is_muntinlupa"=true only if the address is in Muntinlupa City.';

            $retryParts = [
                ['text' => $retryPrompt],
                ['inline_data' => ['mime_type' => $frontMime, 'data' => $frontB64]]
            ];
            if ($back) {
                $retryParts[] = ['inline_data' => ['mime_type' => $backMime, 'data' => $backB64]];
            }

            $retryPayload = json_encode([
                'contents' => [['parts' => $retryParts]],
                'generationConfig' => ['responseMimeType' => 'application/json']
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $retryPayload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 45
            ]);
            $retryResponse = curl_exec($ch);
            $retryCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($retryResponse && $retryCode === 200) {
                $retryData   = json_decode($retryResponse, true);
                $retryText   = $retryData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $retryClean  = trim(preg_replace('/^```json|```$/m', '', $retryText));
                $retryParsed = json_decode($retryClean, true);
                if (is_array($retryParsed) && array_key_exists('valid', $retryParsed)) {
                    return $retryParsed;
                }
            }
            // Retry also failed — return original rejection as-is
        }

        return $parsed;
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