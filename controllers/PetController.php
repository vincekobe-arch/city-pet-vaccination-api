<?php
// controllers/PetController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

class PetController {
    private $conn;
    private $table = 'pets';
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth($this->conn);
    }

    /**
     * Helper method to fetch deworming records for a pet
     */
    private function getDewormingRecords($pet_id) {
        $query = "SELECT dr.*, dt.name as deworming_type_name, dt.description as deworming_type_description,
                 u.first_name as admin_first_name, u.last_name as admin_last_name
                 FROM deworming_records dr
                 JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                 LEFT JOIN users u ON dr.administered_by = u.id
                 WHERE dr.pet_id = :pet_id
                 ORDER BY dr.deworming_date DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pet_id', $pet_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function index() {
    // ✅ AUTHENTICATE USER FIRST
    $user_data = $this->auth->authenticate();
    
    try {
        // ✅ FILTER BASED ON USER ROLE
        if ($user_data['role'] === 'pet_owner') {
            // Pet owners can ONLY see their own pets
            $query = "SELECT 
            p.*,
            po.user_id as owner_user_id,
            u.first_name as owner_first_name,
            u.last_name as owner_last_name,
            u.email as owner_email,
            u.phone as owner_phone,
            u.address as owner_address,
            b.name as barangay_name,
            CONCAT(u.first_name, ' ', u.last_name) as owner_name,
            (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.pet_id = p.id) as vaccination_count,
            (SELECT MAX(vr2.vaccination_date) FROM vaccination_records vr2 WHERE vr2.pet_id = p.id) as last_vaccination_date,
            (SELECT COUNT(*) FROM deworming_records dr WHERE dr.pet_id = p.id) as deworming_count,
            (SELECT MAX(dr2.deworming_date) FROM deworming_records dr2 WHERE dr2.pet_id = p.id) as last_deworming_date
          FROM {$this->table} p
          LEFT JOIN pet_owners po ON p.owner_id = po.id
          LEFT JOIN users u ON po.user_id = u.id
          LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
          WHERE p.is_active = 1 AND po.user_id = :user_id
          ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            
        } elseif ($user_data['role'] === 'barangay_official') {
            // Barangay officials can see pets in their barangay only
            // FIXED: Check the owner's assigned_barangay_id, not the pet's
            $query = "SELECT 
            p.*,
            po.user_id as owner_user_id,
            u.first_name as owner_first_name,
            u.last_name as owner_last_name,
            u.email as owner_email,
            u.phone as owner_phone,
            u.address as owner_address,
            b.name as barangay_name,
            u.assigned_barangay_id,
            CONCAT(u.first_name, ' ', u.last_name) as owner_name,
            (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.pet_id = p.id) as vaccination_count,
            (SELECT MAX(vr2.vaccination_date) FROM vaccination_records vr2 WHERE vr2.pet_id = p.id) as last_vaccination_date,
            (SELECT COUNT(*) FROM deworming_records dr WHERE dr.pet_id = p.id) as deworming_count,
            (SELECT MAX(dr2.deworming_date) FROM deworming_records dr2 WHERE dr2.pet_id = p.id) as last_deworming_date
          FROM {$this->table} p
          LEFT JOIN pet_owners po ON p.owner_id = po.id
          LEFT JOIN users u ON po.user_id = u.id
          LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
          WHERE p.is_active = 1 
          AND (u.assigned_barangay_id = :barangay_id OR u.assigned_barangay_id IS NULL)
          ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $barangay_id = $user_data['user_details']['assigned_barangay_id'];
            $stmt->bindParam(':barangay_id', $barangay_id);
            
        } else {
            // Super admin can see all pets
            $query = "SELECT 
            p.*,
            po.user_id as owner_user_id,
            u.first_name as owner_first_name,
            u.last_name as owner_last_name,
            u.email as owner_email,
            u.phone as owner_phone,
            u.address as owner_address,
            b.name as barangay_name,
            CONCAT(u.first_name, ' ', u.last_name) as owner_name,
            (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.pet_id = p.id) as vaccination_count,
            (SELECT MAX(vr2.vaccination_date) FROM vaccination_records vr2 WHERE vr2.pet_id = p.id) as last_vaccination_date,
            (SELECT COUNT(*) FROM deworming_records dr WHERE dr.pet_id = p.id) as deworming_count,
            (SELECT MAX(dr2.deworming_date) FROM deworming_records dr2 WHERE dr2.pet_id = p.id) as last_deworming_date
          FROM {$this->table} p
          LEFT JOIN pet_owners po ON p.owner_id = po.id
          LEFT JOIN users u ON po.user_id = u.id
          LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
          WHERE p.is_active = 1
          ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
        }
        
        $stmt->execute();
        $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'pets' => $pets,
            'count' => count($pets)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch pets: ' . $e->getMessage()
        ]);
    }
}

    // GET pet by ID - /pets/show/1
    public function show($id = null) {
    // ✅ AUTHENTICATE USER
    $user_data = $this->auth->authenticate();
    
    if (!$id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Pet ID is required'
        ]);
        return;
    }

    try {
        $query = "SELECT 
                    p.*,
                    po.user_id as owner_user_id,
                    u.first_name as owner_first_name,
                    u.last_name as owner_last_name,
                    u.email as owner_email,
                    u.phone as owner_phone,
                    u.address as owner_address,
                    u.assigned_barangay_id,
                    b.name as barangay_name,
                    CONCAT(u.first_name, ' ', u.last_name) as owner_name
                  FROM {$this->table} p
                  LEFT JOIN pet_owners po ON p.owner_id = po.id
                  LEFT JOIN users u ON po.user_id = u.id
                  LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                  WHERE p.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $pet = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pet) {
            // ✅ CHECK ACCESS PERMISSIONS
            if ($user_data['role'] === 'pet_owner' && $pet['owner_user_id'] != $user_data['user_id']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to this pet'
                ]);
                return;
            }
            
            // FIXED: Check owner's assigned_barangay_id, not pet's
            if ($user_data['role'] === 'barangay_official') {
                $official_barangay_id = $user_data['user_details']['assigned_barangay_id'];
                if ($pet['assigned_barangay_id'] != $official_barangay_id && $pet['assigned_barangay_id'] !== null) {
                    http_response_code(403);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Access denied to this pet'
                    ]);
                    return;
                }
            }
            
            // Get vaccination history
            $vacQuery = "SELECT 
                            vr.*,
                            vt.name as vaccination_name,
                            u.first_name,
                            u.last_name
                         FROM vaccination_records vr
                         LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                         LEFT JOIN users u ON vr.administered_by = u.id
                         WHERE vr.pet_id = :pet_id
                         ORDER BY vr.vaccination_date DESC";
            
            $vacStmt = $this->conn->prepare($vacQuery);
            $vacStmt->bindParam(':pet_id', $id);
            $vacStmt->execute();
            $pet['vaccination_history'] = $vacStmt->fetchAll(PDO::FETCH_ASSOC);

            // ✅ ADD DEWORMING RECORDS
            $pet['deworming_records'] = $this->getDewormingRecords($id);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'pet' => $pet
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Pet not found'
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch pet: ' . $e->getMessage()
        ]);
    }
}
    // GET pets by owner USER ID - /pets/owner/6
    public function owner($user_id = null) {
        // ✅ AUTHENTICATE USER
        $user_data = $this->auth->authenticate();
        
        if (!$user_id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'User ID is required'
            ]);
            return;
        }
        
        // ✅ CHECK ACCESS PERMISSIONS
        if ($user_data['role'] === 'pet_owner' && $user_data['user_id'] != $user_id) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied to other owners pets'
            ]);
            return;
        }

        try {
            $query = "SELECT 
            p.*,
            po.id as owner_id,
            po.user_id as owner_user_id,
            u.first_name as owner_first_name,
            u.last_name as owner_last_name,
            CONCAT(u.first_name, ' ', u.last_name) as owner_name,
            (SELECT COUNT(*) FROM vaccination_records vr WHERE vr.pet_id = p.id) as vaccination_count,
            (SELECT MAX(vr2.vaccination_date) FROM vaccination_records vr2 WHERE vr2.pet_id = p.id) as last_vaccination_date,
            (SELECT COUNT(*) FROM deworming_records dr WHERE dr.pet_id = p.id) as deworming_count,
            (SELECT MAX(dr2.deworming_date) FROM deworming_records dr2 WHERE dr2.pet_id = p.id) as last_deworming_date
          FROM {$this->table} p
          LEFT JOIN pet_owners po ON p.owner_id = po.id
          LEFT JOIN users u ON po.user_id = u.id
          WHERE po.user_id = :user_id AND p.is_active = 1
          ORDER BY p.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            http_response_code(200);
            echo json_encode([
                'success' => true,
                'pets' => $pets,
                'count' => count($pets)
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch pets: ' . $e->getMessage()
            ]);
        }
    }

    // POST create new pet - /pets/create
    public function create() {
        // ✅ AUTHENTICATE USER
        $user_data = $this->auth->authenticate();
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);

            // Validate required fields
            if (empty($data['owner_id']) || empty($data['name']) || 
                empty($data['species']) || empty($data['gender'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Missing required fields: owner_id, name, species, gender'
                ]);
                return;
            }
            
            // ✅ SECURITY CHECK: Pet owners can only create pets for themselves
            if ($user_data['role'] === 'pet_owner' && $user_data['user_id'] != $data['owner_id']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'You can only register pets for your own account'
                ]);
                return;
            }

            // Get the pet_owners table ID from user_id
            $ownerQuery = "SELECT id FROM pet_owners WHERE user_id = :user_id";
            $ownerStmt = $this->conn->prepare($ownerQuery);
            $ownerStmt->bindParam(':user_id', $data['owner_id']);
            $ownerStmt->execute();
            $ownerResult = $ownerStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ownerResult) {
                $userQuery = "SELECT first_name, last_name, phone FROM users WHERE id = :user_id AND role = 'pet_owner'";
                $userStmt = $this->conn->prepare($userQuery);
                $userStmt->bindParam(':user_id', $data['owner_id']);
                $userStmt->execute();
                $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userData) {
                    http_response_code(404);
                    echo json_encode([
                        'success' => false,
                        'message' => 'User not found or is not a pet owner. Unable to register pet.'
                    ]);
                    return;
                }
                
                $createOwnerQuery = "INSERT INTO pet_owners (user_id, first_name, last_name, phone) 
                                    VALUES (:user_id, :first_name, :last_name, :phone)";
                $createOwnerStmt = $this->conn->prepare($createOwnerQuery);
                $createOwnerStmt->bindParam(':user_id', $data['owner_id']);
                $createOwnerStmt->bindParam(':first_name', $userData['first_name']);
                $createOwnerStmt->bindParam(':last_name', $userData['last_name']);
                $createOwnerStmt->bindParam(':phone', $userData['phone']);
                $createOwnerStmt->execute();
                
                $pet_owner_id = $this->conn->lastInsertId();
            } else {
                $pet_owner_id = $ownerResult['id'];
            }

            $registration_number = $this->generateRegistrationNumber();

            $query = "INSERT INTO {$this->table} 
                      (registration_number, owner_id, name, species, breed, gender, birth_date, 
                       color, weight, microchip_number, sterilized, sterilized_by, sterilization_date, 
                       special_notes, registration_date, is_active)
                      VALUES 
                      (:registration_number, :owner_id, :name, :species, :breed, :gender, :birth_date, 
                       :color, :weight, :microchip_number, :sterilized, :sterilized_by, :sterilization_date,
                       :special_notes, CURDATE(), 1)";

            $stmt = $this->conn->prepare($query);
            
            $stmt->bindParam(':registration_number', $registration_number);
            $stmt->bindParam(':owner_id', $pet_owner_id);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':species', $data['species']);

            $breed = !empty($data['breed']) ? $data['breed'] : null;
            $stmt->bindParam(':breed', $breed);
            $stmt->bindParam(':gender', $data['gender']);
            
            $birth_date = !empty($data['birth_date']) ? $data['birth_date'] : null;
            $stmt->bindParam(':birth_date', $birth_date);
            
            $color = !empty($data['color']) ? $data['color'] : null;
            $stmt->bindParam(':color', $color);
            
            $weight = !empty($data['weight']) ? $data['weight'] : null;
            $stmt->bindParam(':weight', $weight);
            
            $microchip_number = !empty($data['microchip_number']) ? $data['microchip_number'] : null;
            $stmt->bindParam(':microchip_number', $microchip_number);
            
            $sterilized = isset($data['sterilized']) ? ($data['sterilized'] ? 1 : 0) : 0;
            $stmt->bindParam(':sterilized', $sterilized);
            
            $sterilized_by = !empty($data['sterilized_by']) ? $data['sterilized_by'] : null;
            $stmt->bindParam(':sterilized_by', $sterilized_by);
            
            $sterilization_date = !empty($data['sterilization_date']) ? $data['sterilization_date'] : null;
            $stmt->bindParam(':sterilization_date', $sterilization_date);
            
            $special_notes = !empty($data['special_notes']) ? $data['special_notes'] : null;
            $stmt->bindParam(':special_notes', $special_notes);

            if ($stmt->execute()) {
                $pet_id = $this->conn->lastInsertId();
                
                $fetchQuery = "SELECT 
                                p.*,
                                po.user_id as owner_user_id,
                                u.first_name as owner_first_name,
                                u.last_name as owner_last_name,
                                CONCAT(u.first_name, ' ', u.last_name) as owner_name
                              FROM {$this->table} p
                              LEFT JOIN pet_owners po ON p.owner_id = po.id
                              LEFT JOIN users u ON po.user_id = u.id
                              WHERE p.id = :pet_id";
                
                $fetchStmt = $this->conn->prepare($fetchQuery);
                $fetchStmt->bindParam(':pet_id', $pet_id);
                $fetchStmt->execute();
                $pet = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'message' => 'Pet registered successfully',
                    'pet' => $pet
                ]);
            } else {
                throw new Exception('Failed to register pet');
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // PUT update pet - /pets/update/1
    public function update($id = null) {
        // ✅ AUTHENTICATE USER
        $user_data = $this->auth->authenticate();
        
        if (!$id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Pet ID is required'
            ]);
            return;
        }

        try {
            // ✅ CHECK OWNERSHIP
            $checkQuery = "SELECT po.user_id FROM {$this->table} p
                          LEFT JOIN pet_owners po ON p.owner_id = po.id
                          WHERE p.id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $petOwner = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$petOwner) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Pet not found'
                ]);
                return;
            }
            
            if ($user_data['role'] === 'pet_owner' && $petOwner['user_id'] != $user_data['user_id']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to update this pet'
                ]);
                return;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);

        $query = "UPDATE {$this->table} 
                  SET name = :name,
                      breed = :breed,
                      birth_date = :birth_date,
                      color = :color,
                      weight = :weight,
                      microchip_number = :microchip_number,
                      sterilized = :sterilized,
                      sterilized_by = :sterilized_by,
                      sterilization_date = :sterilization_date,
                      special_notes = :special_notes,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':name', $data['name']);
        
        $breed = !empty($data['breed']) ? $data['breed'] : null;
        $stmt->bindParam(':breed', $breed);
        
        // Add birth_date binding
        $birth_date = !empty($data['birth_date']) ? $data['birth_date'] : null;
        $stmt->bindParam(':birth_date', $birth_date);
        
        $color = !empty($data['color']) ? $data['color'] : null;
        $stmt->bindParam(':color', $color);
        
        $weight = !empty($data['weight']) ? $data['weight'] : null;
        $stmt->bindParam(':weight', $weight);
        
        $microchip_number = !empty($data['microchip_number']) ? $data['microchip_number'] : null;
        $stmt->bindParam(':microchip_number', $microchip_number);
        
        $sterilized = isset($data['sterilized']) ? ($data['sterilized'] ? 1 : 0) : 0;
        $stmt->bindParam(':sterilized', $sterilized);
        
        $sterilized_by = !empty($data['sterilized_by']) ? $data['sterilized_by'] : null;
        $stmt->bindParam(':sterilized_by', $sterilized_by);

        $sterilization_date = !empty($data['sterilization_date']) ? $data['sterilization_date'] : null;
        $stmt->bindParam(':sterilization_date', $sterilization_date);
        
        $special_notes = !empty($data['special_notes']) ? $data['special_notes'] : null;
        $stmt->bindParam(':special_notes', $special_notes);

        if ($stmt->execute()) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Pet updated successfully'
            ]);
        } else {
            throw new Exception('Failed to update pet');
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
}

    // DELETE (deactivate) pet - /pets/delete/1
    public function delete($id = null) {
        // ✅ AUTHENTICATE USER
        $user_data = $this->auth->authenticate();
        
        if (!$id) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Pet ID is required'
            ]);
            return;
        }

        try {
            // ✅ CHECK OWNERSHIP
            $checkQuery = "SELECT p.id, p.name, p.is_active, po.user_id 
                          FROM {$this->table} p
                          LEFT JOIN pet_owners po ON p.owner_id = po.id
                          WHERE p.id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $pet = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pet) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Pet not found'
                ]);
                return;
            }
            
            if ($user_data['role'] === 'pet_owner' && $pet['user_id'] != $user_data['user_id']) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Access denied to delete this pet'
                ]);
                return;
            }

            if ($pet['is_active'] == 0) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Pet is already deactivated'
                ]);
                return;
            }

            $query = "UPDATE {$this->table} 
                      SET is_active = 0,
                          updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Pet deactivated successfully',
                    'pet_name' => $pet['name']
                ]);
            } else {
                throw new Exception('Failed to deactivate pet');
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    // POST upload pet photo - /pets/upload-photo/1
    public function uploadPhoto($id = null) {
        $user_data = $this->auth->authenticate();

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Pet ID is required']);
            return;
        }

        try {
            // Check ownership
            $checkQuery = "SELECT po.user_id, p.photo_url FROM {$this->table} p
                           LEFT JOIN pet_owners po ON p.owner_id = po.id
                           WHERE p.id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $pet = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$pet) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Pet not found']);
                return;
            }

            if ($user_data['role'] === 'pet_owner' && $pet['user_id'] != $user_data['user_id']) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                return;
            }

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No valid file uploaded']);
                return;
            }

            $file = $_FILES['photo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, or WEBP allowed']);
                return;
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Image must be under 2MB']);
                return;
            }

            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/city-pet-vaccination-frontend/public/pet-photos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Delete old photo if exists
            if (!empty($pet['photo_url'])) {
                $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/city-pet-vaccination-frontend/public' . parse_url($pet['photo_url'], PHP_URL_PATH);
                if (file_exists($oldPath)) unlink($oldPath);
            }

            $ext = $mimeType === 'image/png' ? 'png' : ($mimeType === 'image/webp' ? 'webp' : 'jpg');
            $filename = 'pet_' . $id . '_' . time() . '.' . $ext;
            $destination = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to save image']);
                return;
            }

            $photoUrl = '/pet-photos/' . $filename;
            $updateQuery = "UPDATE {$this->table} SET photo_url = :photo_url, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':photo_url', $photoUrl);
            $updateStmt->bindParam(':id', $id);
            $updateStmt->execute();

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Photo updated successfully', 'photo_url' => $photoUrl]);

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    // Generate unique registration number
    private function generateRegistrationNumber() {
        $year = date('Y');
        $month = date('m');
        
        $query = "SELECT registration_number FROM {$this->table} 
                WHERE registration_number LIKE :pattern
                ORDER BY registration_number DESC 
                LIMIT 1";
        
        $pattern = "PET-{$year}{$month}-%";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pattern', $pattern);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['registration_number']) {
            $lastRegNumber = $result['registration_number'];
            preg_match('/PET-\d{6}-(\d{4})/', $lastRegNumber, $matches);
            $lastSequence = isset($matches[1]) ? intval($matches[1]) : 0;
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }
        
        $sequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        $registrationNumber = "PET-{$year}{$month}-{$sequence}";
        
        $checkQuery = "SELECT id FROM {$this->table} WHERE registration_number = :reg_num";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':reg_num', $registrationNumber);
        $checkStmt->execute();
        
        while ($checkStmt->rowCount() > 0) {
            $nextSequence++;
            $sequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
            $registrationNumber = "PET-{$year}{$month}-{$sequence}";
            
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':reg_num', $registrationNumber);
            $checkStmt->execute();
        }
        
        return $registrationNumber;
    }
}
?>