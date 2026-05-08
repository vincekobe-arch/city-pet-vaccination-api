<?php
// controllers/ReportController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

class ReportController {
    private $conn;
    private $table = 'reports';
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth($this->conn);
    }

    private function decodeImages(&$report) {
        $report['images'] = !empty($report['images'])
            ? json_decode($report['images'], true)
            : [];
    }

    private function handleImageUploads() {
        if (empty($_FILES['images']['tmp_name'])) return null;

        $upload_dir = __DIR__ . '/../../uploads/reports/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $allowed     = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $image_paths = [];

        foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
            if (count($image_paths) >= 3) break;
            if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['images']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $filename = 'rpt_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest     = $upload_dir . $filename;
            if (move_uploaded_file($tmp, $dest)) {
                $image_paths[] = 'uploads/reports/' . $filename;
            }
        }
        return !empty($image_paths) ? json_encode($image_paths) : null;
    }

    public function myReports() {
        $user_data = $this->auth->authenticate();
        if ($user_data['role'] !== 'pet_owner') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only pet owners can access this endpoint']);
            return;
        }
        try {
            $query = "SELECT r.*, b.name AS barangay_name
                      FROM {$this->table} r
                      LEFT JOIN barangays b ON r.barangay_id = b.id
                      WHERE r.user_id = :user_id
                      ORDER BY r.created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $user_data['user_id']);
            $stmt->execute();
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reports as &$report) { $this->decodeImages($report); }
            unset($report);
            http_response_code(200);
            echo json_encode(['success' => true, 'reports' => $reports, 'count' => count($reports)]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to fetch reports: ' . $e->getMessage()]);
        }
    }

    public function index() {
        $user_data = $this->auth->authenticate();
        if (!in_array($user_data['role'], ['super_admin', 'barangay_official'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }
        try {
            $query = "SELECT r.*, b.name AS barangay_name,
                 u.email AS reporter_email,
                 CASE
                   WHEN u.role = 'private_clinic' THEN pc.clinic_name
                   ELSE CONCAT(u.first_name, ' ', u.last_name)
                 END AS reporter_name,
                 CASE WHEN u.role = 'private_clinic' THEN 1 ELSE 0 END AS reported_by_clinic
          FROM {$this->table} r
          LEFT JOIN barangays b ON r.barangay_id = b.id
          LEFT JOIN users u ON r.user_id = u.id
          LEFT JOIN private_clinics pc ON pc.user_id = u.id
          ORDER BY r.created_at DESC";
$stmt = $this->conn->prepare($query);
            $stmt->execute();
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reports as &$report) { $this->decodeImages($report); }
            unset($report);
            http_response_code(200);
            echo json_encode(['success' => true, 'reports' => $reports, 'count' => count($reports)]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to fetch reports: ' . $e->getMessage()]);
        }
    }

    public function show($id = null) {
        $user_data = $this->auth->authenticate();
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report ID is required']);
            return;
        }
        try {
    error_log("INDEX CALLED - role: " . $user_data['role']);
    $query = "SELECT r.*, b.name AS barangay_name,
                             u.email AS reporter_email,
                             CONCAT(u.first_name, ' ', u.last_name) AS reporter_name
                      FROM {$this->table} r
                      LEFT JOIN barangays b ON r.barangay_id = b.id
                      LEFT JOIN users u ON r.user_id = u.id
                      WHERE r.id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Report not found']);
                return;
            }
            if ($user_data['role'] === 'pet_owner' && $report['user_id'] != $user_data['user_id']) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied to this report']);
                return;
            }
            
            $this->decodeImages($report);
            http_response_code(200);
            echo json_encode(['success' => true, 'report' => $report]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to fetch report: ' . $e->getMessage()]);
        }
    }

    public function create() {
        $user_data = $this->auth->authenticate();
        if (!in_array($user_data['role'], ['pet_owner', 'private_clinic'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized to submit reports']);
            return;
        }
        try {
            $report_type  = trim($_POST['report_type']  ?? '');
            $barangay_id  = $_POST['barangay_id']        ?? null;
            $address      = trim($_POST['address']       ?? '');
            $phone_number = trim($_POST['phone_number']  ?? '');
            $description  = trim($_POST['description']   ?? '');
            $latitude     = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
            $longitude    = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

            $allowed_types = ['rabies_case', 'animal_bite', 'animal_rescue', 'others'];
            if (!in_array($report_type, $allowed_types)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid report type. Must be one of: ' . implode(', ', $allowed_types)]);
                return;
            }
            if (empty($barangay_id)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Barangay is required']);
                return;
            }
            if (empty($address)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Address is required']);
                return;
            }
            if (empty($phone_number)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Contact number is required']);
                return;
            }

            $images_json   = $this->handleImageUploads();
            $report_number = $this->generateReportNumber();
            $status = 'pending';

            $query = "INSERT INTO {$this->table}
                        (report_number, user_id, report_type, barangay_id, address, phone_number,
                         description, latitude, longitude, images, status, created_at, updated_at)
                      VALUES
                        (:report_number, :user_id, :report_type, :barangay_id, :address, :phone_number,
                         :description, :latitude, :longitude, :images, :status, NOW(), NOW())";

            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':report_number', $report_number);
            $stmt->bindParam(':user_id',       $user_data['user_id']);
            $stmt->bindParam(':report_type',   $report_type);
            $stmt->bindParam(':barangay_id',   $barangay_id);
            $stmt->bindParam(':address',       $address);
            $stmt->bindParam(':phone_number',  $phone_number);
            $desc = !empty($description) ? $description : null;
            $stmt->bindParam(':description',   $desc);
            $stmt->bindParam(':latitude',      $latitude);
            $stmt->bindParam(':longitude',     $longitude);
            $stmt->bindParam(':images',        $images_json);
            $stmt->bindParam(':status',        $status);

            if ($stmt->execute()) {
                $new_id = $this->conn->lastInsertId();
                $fetchQuery = "SELECT r.*, b.name AS barangay_name
                               FROM {$this->table} r
                               LEFT JOIN barangays b ON r.barangay_id = b.id
                               WHERE r.id = :id";
                $fetchStmt = $this->conn->prepare($fetchQuery);
                $fetchStmt->bindParam(':id', $new_id);
                $fetchStmt->execute();
                $report = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                $this->decodeImages($report);
                http_response_code(201);
                echo json_encode(['success' => true, 'message' => 'Report submitted successfully', 'report' => $report]);
            } else {
                throw new Exception('Failed to submit report');
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    public function update($id = null) {
        $user_data = $this->auth->authenticate();
        $isAdmin = in_array($user_data['role'], ['super_admin', 'barangay_official']);
$isClinic = $user_data['role'] === 'private_clinic';

if (!$isAdmin && !$isClinic) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    return;
}
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report ID is required']);
            return;
        }
        try {
            $checkQuery = "SELECT * FROM {$this->table} WHERE id = :id";
            $checkStmt  = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $report = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Report not found']);
                return;
            }
            
            $data        = json_decode(file_get_contents('php://input'), true);
$status      = $data['status']      ?? null;
$admin_notes = trim($data['admin_notes'] ?? '');

// Clinics can only edit their own pending reports, and cannot change status
if ($isClinic) {
    if ($report['user_id'] != $user_data['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied to this report']);
        return;
    }
    if ($report['status'] !== 'pending') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only pending reports can be edited']);
        return;
    }
    $status = null; // clinics cannot change status
}
            $allowed_statuses = ['pending', 'suspected_rabies', 'positive_rabies', 'ongoing', 'resolved', 'declined'];

            if ($status && !in_array($status, $allowed_statuses)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid status value']);
                return;
            }
            $fields = ['updated_at = NOW()'];
            $params = [];
            if ($status)       { $fields[] = 'status = ?';      $params[] = $status; }
            if ($admin_notes !== '') { $fields[] = 'admin_notes = ?'; $params[] = $admin_notes; }
            $params[] = $id;
            $query = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt  = $this->conn->prepare($query);
            $stmt->execute($params);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Report updated successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    public function delete($id = null) {
        $user_data = $this->auth->authenticate();
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Report ID is required']);
            return;
        }
        try {
            $checkQuery = "SELECT * FROM {$this->table} WHERE id = :id";
            $checkStmt  = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $report = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$report) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Report not found']);
                return;
            }
            if ($user_data['role'] === 'pet_owner') {
                if ($report['user_id'] != $user_data['user_id']) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Access denied to this report']);
                    return;
                }
                if (!in_array($report['status'], ['pending', 'declined'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Only pending or declined reports can be withdrawn']);
    return;
}
            }
            // Delete image files from disk
            if (!empty($report['images'])) {
                $paths = json_decode($report['images'], true);
                if (is_array($paths)) {
                    foreach ($paths as $path) {
                        $full_path = __DIR__ . '/../../' . $path;
                        if (file_exists($full_path)) unlink($full_path);
                    }
                }
            }
            $query = "DELETE FROM {$this->table} WHERE id = :id";
            $stmt  = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Report withdrawn successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    public function adminCreate() {
        $user_data = $this->auth->authenticate();
        if (!in_array($user_data['role'], ['super_admin', 'barangay_official'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }
        try {
            $report_type  = trim($_POST['report_type']  ?? 'rabies_case');
            $barangay_id  = $_POST['barangay_id']        ?? null;
            $address      = trim($_POST['address']       ?? '');
            $phone_number = trim($_POST['phone_number']  ?? '');
            $description  = trim($_POST['description']   ?? '');
            $auto_status  = trim($_POST['auto_status']   ?? 'positive_rabies');
            $latitude     = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
            $longitude    = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

            $allowed_types    = ['rabies_case', 'animal_bite'];
            $allowed_statuses = ['suspected_rabies', 'positive_rabies'];
            if (!in_array($report_type, $allowed_types))    { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid report type']); return; }
            if (!in_array($auto_status, $allowed_statuses)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid status']); return; }
            if (empty($barangay_id)) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Barangay is required']); return; }
            if (empty($address))     { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Address is required']);   return; }
            if (empty($phone_number)){ http_response_code(422); echo json_encode(['success'=>false,'message'=>'Contact number is required']); return; }

            $report_number = $this->generateReportNumber();

            $query = "INSERT INTO {$this->table}
                        (report_number, user_id, report_type, barangay_id, address, phone_number,
                         description, latitude, longitude, images, status, created_at, updated_at)
                      VALUES
                        (:report_number, :user_id, :report_type, :barangay_id, :address, :phone_number,
                         :description, :latitude, :longitude, NULL, :status, NOW(), NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':report_number', $report_number);
            $stmt->bindParam(':user_id',       $user_data['user_id']);
            $stmt->bindParam(':report_type',   $report_type);
            $stmt->bindParam(':barangay_id',   $barangay_id);
            $stmt->bindParam(':address',       $address);
            $stmt->bindParam(':phone_number',  $phone_number);
            $desc = !empty($description) ? $description : null;
            $stmt->bindParam(':description',   $desc);
            $stmt->bindParam(':latitude',      $latitude);
            $stmt->bindParam(':longitude',     $longitude);
            $stmt->bindParam(':status',        $auto_status);
            $stmt->execute();
            http_response_code(201);
            echo json_encode(['success' => true, 'message' => 'Report generated successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }

    private function generateReportNumber() {
        $year  = date('Y');
        $month = date('m');
        $pattern = "RPT-{$year}{$month}-%";
        $query   = "SELECT report_number FROM {$this->table}
                    WHERE report_number LIKE :pattern
                    ORDER BY report_number DESC LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':pattern', $pattern);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && $result['report_number']) {
            preg_match('/RPT-\d{6}-(\d{4})/', $result['report_number'], $matches);
            $lastSequence = isset($matches[1]) ? intval($matches[1]) : 0;
            $nextSequence = $lastSequence + 1;
        } else {
            $nextSequence = 1;
        }
        $sequence     = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        $reportNumber = "RPT-{$year}{$month}-{$sequence}";
        $checkQuery = "SELECT id FROM {$this->table} WHERE report_number = :rpt_num";
        $checkStmt  = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(':rpt_num', $reportNumber);
        $checkStmt->execute();
        while ($checkStmt->rowCount() > 0) {
            $nextSequence++;
            $sequence     = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
            $reportNumber = "RPT-{$year}{$month}-{$sequence}";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':rpt_num', $reportNumber);
            $checkStmt->execute();
        }
        return $reportNumber;
    }
}
?>