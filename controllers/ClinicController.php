<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware/Auth.php';

class ClinicController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    // ─── GET /clinics ─────────────────────────────────────────────────────────
    public function index() {

        try {
            $query = "SELECT u.id, u.username, u.email, u.phone, u.is_active, u.created_at, u.updated_at,
                             pc.id as clinic_id, pc.clinic_name, pc.clinic_code, pc.owner_name,
                             pc.address, pc.latitude, pc.longitude, pc.license_number, pc.specialization,
                             COUNT(DISTINCT vr.id) as vaccinations_administered
                      FROM users u
                      JOIN private_clinics pc ON u.id = pc.user_id
                      LEFT JOIN vaccination_records vr ON u.id = vr.administered_by
                      WHERE u.role = 'private_clinic'
                      GROUP BY u.id, pc.id
                      ORDER BY pc.clinic_name ASC";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($clinics as &$clinic) {
                unset($clinic['password']);
            }

            echo json_encode([
                'success' => true,
                'clinics' => $clinics,
                'total' => count($clinics)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get clinics: ' . $e->getMessage()]);
        }
    }

    // ─── GET /clinics/show/:id ────────────────────────────────────────────────
    public function show($id) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT u.id, u.username, u.email, u.phone, u.is_active, u.created_at, u.updated_at,
                             pc.id as clinic_id, pc.clinic_name, pc.clinic_code, pc.owner_name,
                             pc.address, pc.latitude, pc.longitude, pc.license_number, pc.specialization,
                             COUNT(DISTINCT vr.id) as vaccinations_administered
                      FROM users u
                      JOIN private_clinics pc ON u.id = pc.user_id
                      LEFT JOIN vaccination_records vr ON u.id = vr.administered_by
                      WHERE u.id = :clinic_user_id AND u.role = 'private_clinic'
                      GROUP BY u.id, pc.id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':clinic_user_id', $id);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $clinic = $stmt->fetch(PDO::FETCH_ASSOC);
                unset($clinic['password']);

                echo json_encode([
                    'success' => true,
                    'clinic' => $clinic
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Clinic not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get clinic details: ' . $e->getMessage()]);
        }
    }

    // ─── POST /clinics/create ─────────────────────────────────────────────────
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        error_log("=== CLINIC CREATE DEBUG ===");
        error_log("Received data: " . json_encode($data));

        // Required fields
        $required_fields = ['clinic_name', 'owner_name', 'email', 'username'];
        foreach ($required_fields as $field) {
            if (!isset($data->$field) || empty(trim($data->$field))) {
                http_response_code(400);
                echo json_encode(['error' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                return;
            }
        }

        if (!$this->auth->validateEmail($data->email)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email format']);
            return;
        }

        try {
            // Check username/email uniqueness
            $check_query = "SELECT id FROM users WHERE username = :username OR email = :email";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':username', $data->username);
            $check_stmt->bindParam(':email', $data->email);
            $check_stmt->execute();

            if ($check_stmt->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Username or email already exists']);
                return;
            }

            $this->conn->beginTransaction();

            $raw_password = (!empty($data->password)) ? $data->password : 'clinic123';
            $hashed_password = $this->auth->hashPassword($raw_password);

            // Split owner_name into first/last for users table
            $name_parts = explode(' ', trim($data->owner_name), 2);
            $first_name = !empty($name_parts[0]) ? substr($name_parts[0], 0, 50) : 'Clinic';
            $last_name = !empty($name_parts[1]) ? substr($name_parts[1], 0, 50) : 'Owner';

            // Insert into users table with role 'private_clinic'
            $user_query = "INSERT INTO users (username, email, password, first_name, last_name, role, phone, address, is_active)
                           VALUES (:username, :email, :password, :first_name, :last_name, 'private_clinic', :phone, :address, 1)";

            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':username', $data->username);
            $user_stmt->bindParam(':email', $data->email);
            $user_stmt->bindParam(':password', $hashed_password);
            $user_stmt->bindParam(':first_name', $first_name);
            $user_stmt->bindParam(':last_name', $last_name);
            $phone = !empty($data->phone) ? $data->phone : null;
            $address = !empty($data->address) ? $data->address : null;
            $user_stmt->bindParam(':phone', $phone);
            $user_stmt->bindParam(':address', $address);

            if (!$user_stmt->execute()) {
                $this->conn->rollback();
                error_log("User insert failed: " . print_r($user_stmt->errorInfo(), true));
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create user account']);
                return;
            }

            $user_id = $this->conn->lastInsertId();
            error_log("Clinic user created with ID: " . $user_id);

            // Generate clinic code: CLIN-YYYY-###
            $current_year = date('Y');
            $count_query = "SELECT COALESCE(MAX(id), 0) + 1 as next_num FROM private_clinics";
            $count_stmt = $this->conn->prepare($count_query);
            $count_stmt->execute();
            $count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $clinic_code = 'CLIN-' . $current_year . '-' . str_pad($count_row['next_num'], 3, '0', STR_PAD_LEFT);
            // Insert into private_clinics table
            $clinic_query = "INSERT INTO private_clinics (user_id, clinic_name, clinic_code, owner_name, address, latitude, longitude, phone, email, license_number, specialization, is_active)
                             VALUES (:user_id, :clinic_name, :clinic_code, :owner_name, :address, :latitude, :longitude, :phone, :email, :license_number, :specialization, 1)";

            $clinic_stmt = $this->conn->prepare($clinic_query);
            $clinic_stmt->bindParam(':user_id', $user_id);
            $clinic_stmt->bindParam(':clinic_name', $data->clinic_name);
            $clinic_stmt->bindParam(':clinic_code', $clinic_code);
            $clinic_stmt->bindParam(':owner_name', $data->owner_name);
            $clinic_stmt->bindParam(':address', $address);
            $latitude  = !empty($data->latitude)  ? $data->latitude  : null;
            $longitude = !empty($data->longitude) ? $data->longitude : null;
            $clinic_stmt->bindParam(':latitude',  $latitude);
            $clinic_stmt->bindParam(':longitude', $longitude);
            $clinic_stmt->bindParam(':phone', $phone);
            $clinic_stmt->bindParam(':email', $data->email);
            $license = !empty($data->license_number) ? $data->license_number : null;
            $specialization = !empty($data->specialization) ? $data->specialization : null;
            $clinic_stmt->bindParam(':license_number', $license);
            $clinic_stmt->bindParam(':specialization', $specialization);

            error_log("=== ATTEMPTING CLINIC INSERT ===");
            error_log("clinic_code: " . $clinic_code);
            error_log("user_id: " . $user_id);
            error_log("clinic_name: " . $data->clinic_name);

            try {
                $clinic_stmt->execute();
                error_log("Clinic insert rowCount: " . $clinic_stmt->rowCount());
            } catch (Exception $clinicEx) {
                $this->conn->rollback();
                error_log("=== CLINIC INSERT EXCEPTION: " . $clinicEx->getMessage() . " ===");
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create clinic details: ' . $clinicEx->getMessage()]);
                return;
            }

            $this->conn->commit();
            error_log("Clinic transaction committed successfully!");

            // Return created clinic
            $get_query = "SELECT u.id, u.username, u.email, u.phone, u.is_active, u.created_at,
                                 pc.id as clinic_id, pc.clinic_name, pc.clinic_code, pc.owner_name,
                                 pc.address, pc.license_number, pc.specialization
                          FROM users u
                          JOIN private_clinics pc ON u.id = pc.user_id
                          WHERE u.id = :user_id";
            $get_stmt = $this->conn->prepare($get_query);
            $get_stmt->bindParam(':user_id', $user_id);
            $get_stmt->execute();
            $clinic = $get_stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Private clinic created successfully',
                'clinic' => $clinic
            ]);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            $pdo_error = $this->conn->errorInfo();
            error_log("Exception in clinic create: " . $e->getMessage());
            error_log("PDO errorInfo: " . print_r($pdo_error, true));
            http_response_code(500);
            echo json_encode([
                'error' => 'Clinic creation failed: ' . $e->getMessage(),
                'debug' => $e->getMessage()
            ]);
        }
    }

    // ─── GET /clinics/dashboard ───────────────────────────────────────────────
    public function dashboard() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);

        $clinic_user_id = $user_data['user_id'];

        try {
            $clinic_query = "SELECT clinic_name FROM private_clinics WHERE user_id = :user_id";
            $clinic_stmt = $this->conn->prepare($clinic_query);
            $clinic_stmt->bindParam(':user_id', $clinic_user_id);
            $clinic_stmt->execute();
            $clinic = $clinic_stmt->fetch(PDO::FETCH_ASSOC);
            $clinic_name = $clinic ? $clinic['clinic_name'] : 'City Vet Muntinlupa';

            $vac_query = "SELECT vr.*, p.name as pet_name, p.registration_number, p.species,
                                 vt.name as vaccine_name, :clinic_name as recorded_by
                          FROM vaccination_records vr
                          JOIN pets p ON vr.pet_id = p.id
                          LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                          WHERE vr.administered_by = :user_id
                          ORDER BY vr.vaccination_date DESC";
            $vac_stmt = $this->conn->prepare($vac_query);
            $vac_stmt->bindParam(':user_id', $clinic_user_id);
            $vac_stmt->bindParam(':clinic_name', $clinic_name);
            $vac_stmt->execute();
            $vaccinations = $vac_stmt->fetchAll(PDO::FETCH_ASSOC);

            $dew_query = "SELECT dr.*, p.name as pet_name, p.registration_number, p.species,
                                 dt.name as deworming_name, :clinic_name as recorded_by
                          FROM deworming_records dr
                          JOIN pets p ON dr.pet_id = p.id
                          LEFT JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                          WHERE dr.administered_by = :user_id
                          ORDER BY dr.deworming_date DESC";
            $dew_stmt = $this->conn->prepare($dew_query);
            $dew_stmt->bindParam(':user_id', $clinic_user_id);
            $dew_stmt->bindParam(':clinic_name', $clinic_name);
            $dew_stmt->execute();
            $dewormings = $dew_stmt->fetchAll(PDO::FETCH_ASSOC);

            $ster_query = "SELECT sr.*, p.name as pet_name, p.registration_number, p.species,
                                  :clinic_name as recorded_by
                           FROM sterilization_records sr
                           JOIN pets p ON sr.pet_id = p.id
                           WHERE sr.administered_by = :user_id
                           ORDER BY sr.sterilization_date DESC";
            $ster_stmt = $this->conn->prepare($ster_query);
            $ster_stmt->bindParam(':user_id', $clinic_user_id);
            $ster_stmt->bindParam(':clinic_name', $clinic_name);
            $ster_stmt->execute();
            $sterilizations = $ster_stmt->fetchAll(PDO::FETCH_ASSOC);

            $pets_query = "SELECT p.*, COUNT(DISTINCT vr.id) as vaccination_count
                           FROM pets p
                           LEFT JOIN vaccination_records vr ON p.id = vr.pet_id 
                               AND vr.administered_by = :user_id
                           WHERE p.is_active = 1
                           GROUP BY p.id";
            $pets_stmt = $this->conn->prepare($pets_query);
            $pets_stmt->bindParam(':user_id', $clinic_user_id);
            $pets_stmt->execute();
            $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'clinic_name' => $clinic_name,
                'vaccination_records' => $vaccinations,
                'deworming_records' => $dewormings,
                'sterilization_records' => $sterilizations,
                'pets' => $pets
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load dashboard: ' . $e->getMessage()]);
        }
    }

    // ─── GET /clinics/records ─────────────────────────────────────────────────
    public function records() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinic_user_id = $user_data['user_id'];

        try {
            $clinic_query = "SELECT clinic_name FROM private_clinics WHERE user_id = :user_id";
            $clinic_stmt = $this->conn->prepare($clinic_query);
            $clinic_stmt->bindParam(':user_id', $clinic_user_id);
            $clinic_stmt->execute();
            $clinic = $clinic_stmt->fetch(PDO::FETCH_ASSOC);
            $clinic_name = $clinic ? $clinic['clinic_name'] : 'City Vet Muntinlupa';

            $vac_stmt = $this->conn->prepare("
                SELECT vr.*, p.name as pet_name, p.registration_number, p.species,
                       COALESCE(ci.item_name, vt.name) as vaccine_name,
                       CASE WHEN u.role = 'private_clinic' THEN pc.clinic_name
                            ELSE 'City Vet Muntinlupa' END as recorded_by,
                       CASE WHEN vr.administered_by = :user_id THEN 1 ELSE 0 END as is_mine
                FROM vaccination_records vr
                JOIN pets p ON vr.pet_id = p.id
                LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                LEFT JOIN clinic_inventory ci ON vr.clinic_item_id = ci.id
                JOIN users u ON vr.administered_by = u.id
                LEFT JOIN private_clinics pc ON u.id = pc.user_id
                ORDER BY vr.vaccination_date DESC
            ");
            $vac_stmt->bindParam(':user_id', $clinic_user_id, PDO::PARAM_INT);
            $vac_stmt->execute();
            $vaccinations = $vac_stmt->fetchAll(PDO::FETCH_ASSOC);

            $dew_stmt = $this->conn->prepare("
                SELECT dr.*, p.name as pet_name, p.registration_number, p.species,
                       COALESCE(ci.item_name, dt.name) as deworming_name,
                       CASE WHEN u.role = 'private_clinic' THEN pc.clinic_name
                            ELSE 'City Vet Muntinlupa' END as recorded_by,
                       CASE WHEN dr.administered_by = :user_id THEN 1 ELSE 0 END as is_mine
                FROM deworming_records dr
                JOIN pets p ON dr.pet_id = p.id
                LEFT JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                LEFT JOIN clinic_inventory ci ON dr.clinic_item_id = ci.id
                JOIN users u ON dr.administered_by = u.id
                LEFT JOIN private_clinics pc ON u.id = pc.user_id
                ORDER BY dr.deworming_date DESC
            ");
            $dew_stmt->bindParam(':user_id', $clinic_user_id, PDO::PARAM_INT);
            $dew_stmt->execute();
            $dewormings = $dew_stmt->fetchAll(PDO::FETCH_ASSOC);

            $ster_stmt = $this->conn->prepare("
                SELECT sr.*, p.name as pet_name, p.registration_number, p.species,
                       CASE WHEN u.role = 'private_clinic' THEN pc.clinic_name
                            ELSE 'City Vet Muntinlupa' END as recorded_by,
                       CASE WHEN sr.administered_by = :user_id THEN 1 ELSE 0 END as is_mine
                FROM sterilization_records sr
                JOIN pets p ON sr.pet_id = p.id
                JOIN users u ON sr.administered_by = u.id
                LEFT JOIN private_clinics pc ON u.id = pc.user_id
                ORDER BY sr.sterilization_date DESC
            ");
            $ster_stmt->bindParam(':user_id', $clinic_user_id, PDO::PARAM_INT);
            $ster_stmt->execute();
            $sterilizations = $ster_stmt->fetchAll(PDO::FETCH_ASSOC);

            $vac_types_query = "SELECT * FROM vaccination_types ORDER BY name ASC";
            $vac_types_stmt = $this->conn->prepare($vac_types_query);
            $vac_types_stmt->execute();
            $vaccination_types = $vac_types_stmt->fetchAll(PDO::FETCH_ASSOC);

            $dew_types_query = "SELECT * FROM deworming_types WHERE is_active = 1 ORDER BY name ASC";
            $dew_types_stmt = $this->conn->prepare($dew_types_query);
            $dew_types_stmt->execute();
            $deworming_types = $dew_types_stmt->fetchAll(PDO::FETCH_ASSOC);

            $pets_query = "SELECT p.*, CONCAT(po.first_name, ' ', po.last_name) as owner_name
                           FROM pets p
                           JOIN pet_owners po ON p.owner_id = po.id
                           WHERE p.is_active = 1
                           ORDER BY p.name ASC";
            $pets_stmt = $this->conn->prepare($pets_query);
            $pets_stmt->execute();
            $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'clinic_name' => $clinic_name,
                'vaccination_records' => $vaccinations,
                'deworming_records' => $dewormings,
                'sterilization_records' => $sterilizations,
                'vaccination_types' => $vaccination_types,
                'deworming_types' => $deworming_types,
                'pets' => $pets
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to load records: ' . $e->getMessage()]);
        }
    }

    // ─── PUT /clinics/records/update/:id ─────────────────────────────────────
    public function updateRecord($id, $type) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinic_user_id = $user_data['user_id'];

        $data = json_decode(file_get_contents("php://input"), true);

        try {
            if ($type === 'vaccination') {
                $check = $this->conn->prepare("SELECT administered_by FROM vaccination_records WHERE id = :id");
                $check->bindParam(':id', $id);
                $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only edit your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("UPDATE vaccination_records SET vaccination_type_id = :vt, vaccination_date = :vd, next_due_date = :ndd, veterinarian_name = :vn, weight = :w, notes = :n WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':vt', $data['vaccination_type_id']);
                $stmt->bindParam(':vd', $data['vaccination_date']);
                $stmt->bindParam(':ndd', $data['next_due_date']);
                $stmt->bindParam(':vn', $data['veterinarian_name']);
                $stmt->bindParam(':w', $data['weight']);
                $stmt->bindParam(':n', $data['notes']);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();
            } elseif ($type === 'deworming') {
                $check = $this->conn->prepare("SELECT administered_by FROM deworming_records WHERE id = :id");
                $check->bindParam(':id', $id);
                $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only edit your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("UPDATE deworming_records SET deworming_type_id = :dt, deworming_date = :dd, next_due_date = :ndd, veterinarian_name = :vn, weight = :w, dosage = :dos, notes = :n WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':dt', $data['deworming_type_id']);
                $stmt->bindParam(':dd', $data['deworming_date']);
                $stmt->bindParam(':ndd', $data['next_due_date']);
                $stmt->bindParam(':vn', $data['veterinarian_name']);
                $stmt->bindParam(':w', $data['weight']);
                $stmt->bindParam(':dos', $data['dosage']);
                $stmt->bindParam(':n', $data['notes']);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();
            } elseif ($type === 'sterilization') {
                $check = $this->conn->prepare("SELECT administered_by FROM sterilization_records WHERE id = :id");
                $check->bindParam(':id', $id);
                $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only edit your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("UPDATE sterilization_records SET sterilization_date = :sd, veterinarian_name = :vn, weight = :w WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':sd', $data['sterilization_date']);
                $stmt->bindParam(':vn', $data['veterinarian_name']);
                $stmt->bindParam(':w', $data['weight']);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();
            }

            echo json_encode(['success' => true, 'message' => 'Record updated successfully.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
        }
    }

    // ─── DELETE /clinics/records/delete/:id/:type ─────────────────────────────
    public function deleteRecord($id, $type) {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['private_clinic'], $user_data);
        $clinic_user_id = $user_data['user_id'];

        try {
            if ($type === 'vaccination') {
                $check = $this->conn->prepare("SELECT administered_by, clinic_batch_id FROM vaccination_records WHERE id = :id");
                $check->bindParam(':id', $id); $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only delete your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("DELETE FROM vaccination_records WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();

                // Restore clinic vaccine batch stock
                if (!empty($record['clinic_batch_id'])) {
                    $this->conn->prepare(
                        "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                         updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                    )->execute([':batch_id' => $record['clinic_batch_id']]);
                }
            } elseif ($type === 'deworming') {
                $check = $this->conn->prepare("SELECT administered_by, batch_id FROM deworming_records WHERE id = :id");
                $check->bindParam(':id', $id); $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only delete your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("DELETE FROM deworming_records WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();

                // Restore clinic dewormer batch stock
                if (!empty($record['batch_id'])) {
                    $this->conn->prepare(
                        "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                         updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                    )->execute([':batch_id' => $record['batch_id']]);
                }
            } elseif ($type === 'sterilization') {
                $check = $this->conn->prepare("SELECT administered_by FROM sterilization_records WHERE id = :id");
                $check->bindParam(':id', $id); $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only delete your own clinic records.']);
                    return;
                }
                // Also un-mark pet as sterilized
                $pet_stmt = $this->conn->prepare("SELECT pet_id FROM sterilization_records WHERE id = :id");
                $pet_stmt->bindParam(':id', $id); $pet_stmt->execute();
                $pet_data = $pet_stmt->fetch(PDO::FETCH_ASSOC);

                $stmt = $this->conn->prepare("DELETE FROM sterilization_records WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();

                if ($pet_data) {
                    $up = $this->conn->prepare("UPDATE pets SET sterilized = 0, sterilized_by = NULL, sterilization_date = NULL WHERE id = :pet_id");
                    $up->bindParam(':pet_id', $pet_data['pet_id']);
                    $up->execute();
                }
            } elseif ($type === 'microchip') {
                $check = $this->conn->prepare("SELECT administered_by, pet_id, clinic_batch_id FROM microchip_records WHERE id = :id");
                $check->bindParam(':id', $id); $check->execute();
                $record = $check->fetch(PDO::FETCH_ASSOC);
                if (!$record || $record['administered_by'] != $clinic_user_id) {
                    http_response_code(403);
                    echo json_encode(['error' => 'You can only delete your own clinic records.']);
                    return;
                }
                $stmt = $this->conn->prepare("DELETE FROM microchip_records WHERE id = :id AND administered_by = :user_id");
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':user_id', $clinic_user_id);
                $stmt->execute();

                // Restore clinic microchip batch stock
                if (!empty($record['clinic_batch_id'])) {
                    $this->conn->prepare(
                        "UPDATE clinic_inventory_batches SET quantity = quantity + 1,
                         updated_at = CURRENT_TIMESTAMP WHERE id = :batch_id"
                    )->execute([':batch_id' => $record['clinic_batch_id']]);
                }

                $up = $this->conn->prepare("UPDATE pets SET microchip_number = NULL WHERE id = :pet_id");
                $up->bindParam(':pet_id', $record['pet_id']);
                $up->execute();
            }

            echo json_encode(['success' => true, 'message' => 'Record deleted successfully.']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Delete failed: ' . $e->getMessage()]);
        }
    }

    // ─── PUT /clinics/update/:id ──────────────────────────────────────────────
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        $data = json_decode(file_get_contents("php://input"));

        error_log("=== CLINIC UPDATE DEBUG ===");
        error_log("ID: " . $id);
        error_log("Data: " . json_encode($data));

        try {
            // Check clinic exists
            $check_query = "SELECT u.id FROM users u
                            JOIN private_clinics pc ON u.id = pc.user_id
                            WHERE u.id = :clinic_user_id AND u.role = 'private_clinic'";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(':clinic_user_id', $id);
            $check_stmt->execute();

            if ($check_stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Clinic not found']);
                return;
            }

            $this->conn->beginTransaction();

            // ── 1. Update users table ──────────────────────────────────────
            $user_fields  = [];
            $user_params  = [':user_id' => $id];

            if (property_exists($data, 'phone')) {
                $user_fields[] = "phone = :phone";
                $user_params[':phone'] = $data->phone !== '' ? $data->phone : null;
            }
            if (property_exists($data, 'email') && !empty($data->email)) {
                $user_fields[] = "email = :email";
                $user_params[':email'] = $data->email;
            }
            if (property_exists($data, 'address')) {
                $user_fields[] = "address = :address";
                $user_params[':address'] = $data->address !== '' ? $data->address : null;
            }

            if (!empty($user_fields)) {
                $user_query = "UPDATE users SET " . implode(', ', $user_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :user_id";
                $user_stmt  = $this->conn->prepare($user_query);
                $user_stmt->execute($user_params);
                error_log("Users updated: " . $user_stmt->rowCount() . " rows");
            }

            // ── 2. Update private_clinics table ────────────────────────────
            $clinic_fields = [];
            $clinic_params = [':user_id' => $id];

            $string_fields = ['clinic_name', 'owner_name', 'address', 'phone', 'email', 'license_number', 'specialization'];
            foreach ($string_fields as $field) {
                if (property_exists($data, $field)) {
                    $clinic_fields[] = "$field = :$field";
                    $clinic_params[":$field"] = $data->$field !== '' ? $data->$field : null;
                }
            }

            // Handle lat/lng separately (must be numeric or null)
            if (property_exists($data, 'latitude')) {
                $clinic_fields[] = "latitude = :latitude";
                $clinic_params[':latitude'] = ($data->latitude !== null && $data->latitude !== '') ? floatval($data->latitude) : null;
            }
            if (property_exists($data, 'longitude')) {
                $clinic_fields[] = "longitude = :longitude";
                $clinic_params[':longitude'] = ($data->longitude !== null && $data->longitude !== '') ? floatval($data->longitude) : null;
            }

            if (!empty($clinic_fields)) {
                $clinic_query = "UPDATE private_clinics SET " . implode(', ', $clinic_fields) . ", updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
                $clinic_stmt  = $this->conn->prepare($clinic_query);
                $clinic_stmt->execute($clinic_params);
                error_log("private_clinics updated: " . $clinic_stmt->rowCount() . " rows");
            }

            $this->conn->commit();

            echo json_encode([
                'success' => true,
                'message' => 'Clinic updated successfully'
            ]);

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Clinic update exception: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Clinic update failed: ' . $e->getMessage()]);
        }
    }

    // ─── DELETE /clinics/delete/:id  (soft deactivate) ───────────────────────
    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $this->conn->beginTransaction();

            $user_query = "UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'private_clinic'";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':id', $id);

            $clinic_query = "UPDATE private_clinics SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id";
            $clinic_stmt = $this->conn->prepare($clinic_query);
            $clinic_stmt->bindParam(':id', $id);

            if ($user_stmt->execute() && $clinic_stmt->execute() && $user_stmt->rowCount() > 0) {
                $this->conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Clinic deactivated successfully'
                ]);
            } else {
                $this->conn->rollback();
                http_response_code(404);
                echo json_encode(['error' => 'Clinic not found']);
            }

        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Clinic deactivation failed: ' . $e->getMessage()]);
        }
    }

    // ─── PUT /clinics/restore/:id ─────────────────────────────────────────────
    public function restore($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $this->conn->beginTransaction();

            $user_query = "UPDATE users SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND role = 'private_clinic'";
            $user_stmt = $this->conn->prepare($user_query);
            $user_stmt->bindParam(':id', $id);

            $clinic_query = "UPDATE private_clinics SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE user_id = :id";
            $clinic_stmt = $this->conn->prepare($clinic_query);
            $clinic_stmt->bindParam(':id', $id);

            if ($user_stmt->execute() && $clinic_stmt->execute() && $user_stmt->rowCount() > 0) {
                $this->conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Clinic restored successfully'
                ]);
            } else {
                $this->conn->rollback();
                http_response_code(404);
                echo json_encode(['error' => 'Clinic not found']);
            }

        } catch (Exception $e) {
            $this->conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Clinic restoration failed: ' . $e->getMessage()]);
        }
    }

    // ─── GET /clinics/statistics ──────────────────────────────────────────────
    public function statistics() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin', 'barangay_official'], $user_data);

        try {
            $query = "SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active,
                        SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) as inactive
                      FROM users u
                      JOIN private_clinics pc ON u.id = pc.user_id
                      WHERE u.role = 'private_clinic'";

            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'statistics' => $stats
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get statistics: ' . $e->getMessage()]);
        }
    }
}
?>