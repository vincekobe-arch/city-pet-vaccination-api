<?php
// routes/report.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

$database = new Database();
$pdo = $database->getConnection();

$authMiddleware = new Auth();
function authenticate() {
    global $authMiddleware;
    $userData = $authMiddleware->authenticate();
    if (isset($userData['user_id'])) {
        $userData['id'] = $userData['user_id'];
    }
    return $userData;
}

header('Content-Type: application/json');

$request_method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base_path = '/city-pet-vaccination-api';
$uri = str_replace($base_path, '', $uri);

// =============================================
// GET /reports/my-reports — Pet owner's or clinic's own reports
// =============================================
if ($request_method === 'GET' && strpos($uri, '/reports/my-reports') === 0) {
    $user = authenticate();
    if (!$user || !in_array($user['role'], ['pet_owner', 'private_clinic'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, b.name AS barangay_name
            FROM reports r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reports as &$report) {
            $report['images'] = !empty($report['images']) ? json_decode($report['images'], true) : [];
        }
        unset($report);

        echo json_encode(['success' => true, 'reports' => $reports]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// GET /reports/map — Rabies map data (all authenticated roles)
// =============================================
if ($request_method === 'GET' && preg_match('#^/reports/map$#', $uri)) {

    try {
        $stmt = $pdo->prepare("
            SELECT r.id, r.status, r.latitude, r.longitude, r.created_at,
                   b.name AS barangay_name
            FROM reports r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            WHERE r.status IN ('positive_rabies', 'suspected_rabies')
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'reports' => $reports]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// GET /reports — All reports (admin/official only)
// =============================================
if ($request_method === 'GET' && preg_match('#^/reports$#', $uri)) {
    $user = authenticate();
    if (!$user || !in_array($user['role'], ['super_admin', 'barangay_official'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, b.name AS barangay_name,
                   u.email AS reporter_email,
                   CASE
                     WHEN u.role = 'private_clinic' THEN pc.clinic_name
                     ELSE CONCAT(u.first_name, ' ', u.last_name)
                   END AS reporter_name,
                   CASE WHEN u.role = 'private_clinic' THEN 1 ELSE 0 END AS reported_by_clinic
            FROM reports r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN private_clinics pc ON pc.user_id = u.id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();

        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reports as &$report) {
            $report['images'] = !empty($report['images']) ? json_decode($report['images'], true) : [];
        }
        unset($report);

        echo json_encode(['success' => true, 'reports' => $reports]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// GET /reports/show/{id}
// =============================================
if ($request_method === 'GET' && preg_match('#^/reports/show/(\d+)$#', $uri, $matches)) {
    $user = authenticate();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, b.name AS barangay_name,
                   CONCAT(u.first_name, ' ', u.last_name) AS reporter_name,
                   u.email AS reporter_email
            FROM reports r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Report not found']);
            exit;
        }

        if ($user['role'] === 'pet_owner' && $report['user_id'] != $user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }

        $report['images'] = !empty($report['images']) ? json_decode($report['images'], true) : [];

        echo json_encode(['success' => true, 'report' => $report]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// POST /reports/create
// =============================================
if ($request_method === 'POST' && strpos($uri, '/reports/create') === 0) {
    $user = authenticate();
    if (!$user || !in_array($user['role'], ['pet_owner', 'private_clinic'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized to submit reports']);
        exit;
    }

    $report_type  = $_POST['report_type'] ?? '';
    $barangay_id  = $_POST['barangay_id'] ?? null;
    $address      = trim($_POST['address'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $latitude     = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
    $longitude    = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;

    $allowed_types = ['rabies_case', 'animal_bite', 'animal_rescue', 'others'];
    if (!in_array($report_type, $allowed_types)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid report type']);
        exit;
    }
    if (!$barangay_id) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Barangay is required']);
        exit;
    }
    if (empty($address)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Address is required']);
        exit;
    }
    if (empty($phone_number)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Contact number is required']);
        exit;
    }

    try {
        $report_number = 'RPT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $initial_status = (isset($_POST['auto_status']) && $_POST['auto_status'] === 'positive_rabies') ? 'positive_rabies' : 'pending';

        $stmt = $pdo->prepare("
            INSERT INTO reports (report_number, user_id, report_type, barangay_id, address, phone_number, description, latitude, longitude, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $desc_val    = !empty($description) ? $description : null;
        $lat_val     = isset($latitude)  && $latitude  !== '' ? (float)$latitude  : null;
        $lng_val     = isset($longitude) && $longitude !== '' ? (float)$longitude : null;
        $status_val  = (isset($_POST['auto_status']) && $_POST['auto_status'] === 'positive_rabies') ? 'positive_rabies' : 'pending';

        $stmt->execute([
            $report_number,
            $user['id'],
            $report_type,
            $barangay_id,
            $address,
            $phone_number,
            $desc_val,
            $lat_val,
            $lng_val,
            $status_val
        ]);

        $newId = $pdo->lastInsertId();

        $stmtGet = $pdo->prepare("SELECT r.*, b.name AS barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id = b.id WHERE r.id = ?");
        $stmtGet->execute([$newId]);
        $report = $stmtGet->fetch(PDO::FETCH_ASSOC);
        $report['images'] = [];

        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Report submitted successfully', 'report' => $report]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// POST /reports/admin-create — Admin generates rabies report directly
// =============================================
if ($request_method === 'POST' && strpos($uri, '/reports/admin-create') === 0) {
    $user = authenticate();
    if (!$user || !in_array($user['role'], ['super_admin', 'barangay_official'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

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

    if (!in_array($report_type, $allowed_types))    { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Invalid report type']); exit; }
    if (!in_array($auto_status, $allowed_statuses)) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Invalid status']); exit; }
    if (!$barangay_id)    { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Barangay is required']); exit; }
    if (empty($address))  { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Address is required']); exit; }
    if (empty($phone_number)) { http_response_code(422); echo json_encode(['success' => false, 'message' => 'Contact number is required']); exit; }

    try {
        $report_number = 'RPT-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $desc_val = !empty($description) ? $description : null;

        $stmt = $pdo->prepare("
            INSERT INTO reports (report_number, user_id, report_type, barangay_id, address, phone_number,
                                 description, latitude, longitude, images, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $report_number, $user['id'], $report_type, $barangay_id,
            $address, $phone_number, $desc_val, $latitude, $longitude, $auto_status
        ]);

        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Report generated successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// PUT /reports/update/{id} — Admin updates status/notes
// =============================================
if ($request_method === 'PUT' && preg_match('#^/reports/update/(\d+)$#', $uri, $matches)) {
    $user = authenticate();
    if (!$user || !in_array($user['role'], ['super_admin', 'barangay_official', 'private_clinic'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $id   = $matches[1];
    $data = json_decode(file_get_contents('php://input'), true);

    $status      = $data['status']      ?? null;
    $admin_notes = trim($data['admin_notes'] ?? '');
    $address      = isset($data['address'])      ? trim($data['address'])      : null;
    $phone_number = isset($data['phone_number']) ? trim($data['phone_number']) : null;
    $description  = isset($data['description'])  ? trim($data['description'])  : null;

    // Updated allowed statuses
    $allowed_statuses = ['pending', 'suspected_rabies', 'positive_rabies', 'ongoing', 'resolved', 'declined'];

    if ($status && !in_array($status, $allowed_statuses)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    try {
        $fields = ['updated_at = NOW()'];
        $params = [];

        if ($status)                           { $fields[] = 'status = ?';       $params[] = $status; }
        if ($admin_notes !== '')               { $fields[] = 'admin_notes = ?';  $params[] = $admin_notes; }
        if ($address !== null && $address !== '') { $fields[] = 'address = ?';   $params[] = $address; }
        if ($phone_number !== null && $phone_number !== '') { $fields[] = 'phone_number = ?'; $params[] = $phone_number; }
        if ($description !== null)             { $fields[] = 'description = ?';  $params[] = $description; }

        $params[] = $id;

        $stmt = $pdo->prepare("UPDATE reports SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);

        echo json_encode(['success' => true, 'message' => 'Report updated successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================
// DELETE /reports/delete/{id}
// =============================================
if ($request_method === 'DELETE' && preg_match('#^/reports/delete/(\d+)$#', $uri, $matches)) {
    $user = authenticate();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $id = $matches[1];

    try {
        $stmt = $pdo->prepare("SELECT * FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Report not found']);
            exit;
        }

        if ($user['role'] === 'pet_owner') {
            if ($report['user_id'] != $user['id']) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Forbidden']);
                exit;
            }
            if (!in_array($report['status'], ['pending', 'declined'])) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Only pending or declined reports can be withdrawn']);
    exit;
}
        }

        $stmtDel = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmtDel->execute([$id]);

        echo json_encode(['success' => true, 'message' => 'Report withdrawn successfully']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// 404
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Reports route not found']);