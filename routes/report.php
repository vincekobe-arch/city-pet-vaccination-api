<?php
// routes/report.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $pdo = $database->getConnection();
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Server is busy, please try again.']);
    exit;
}

$authMiddleware = new Auth($pdo);
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
// GET /reports/forecast — Rabies risk forecast for the Map Status heatmap
// How it works (simple, explainable):
//   1. Each rabies-related report gets a weight:
//        positive rabies 1.0 · suspected rabies 0.75 · rabies case report 0.5 · animal bite 0.35
//      (declined reports, rescues and "others" are ignored)
//   2. Older reports count less: weight × e^(−age in days ÷ 90)
//   3. Per barangay: compare the last 90 days with the 90 days before
//      → trend, expected cases in the next 30 days, and Low / Moderate / High risk
// =============================================
if ($request_method === 'GET' && preg_match('#^/reports/forecast$#', $uri)) {
    $user = authenticate();   // any logged-in account (admin and community Map Status pages)
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    try {
        date_default_timezone_set('Asia/Manila');
        $now = time();
        $HALF = 30;   // days: how fast old reports fade (half strength after ~3 weeks)

        $stmt = $pdo->prepare("
            SELECT r.id, r.report_type, r.status, r.latitude, r.longitude, r.created_at,
                   r.barangay_id, b.name AS barangay_name
            FROM reports r
            LEFT JOIN barangays b ON r.barangay_id = b.id
            WHERE r.status = 'positive_rabies'
              AND r.report_type IN ('rabies_case', 'animal_bite')
              AND r.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bstmt = $pdo->query("SELECT id, name FROM barangays WHERE is_active = 1 ORDER BY name");
        $brgy = [];
        foreach ($bstmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $brgy[$b['id']] = [
                'id' => (int) $b['id'], 'name' => $b['name'],
                'score' => 0.0, 'recent_90' => 0, 'previous_90' => 0, 'last_30' => 0,
                'positive' => 0, 'last_case' => null,
            ];
        }

        $points = [];
        foreach ($rows as $r) {
            $w = 1.0;   // only positive rabies cases are counted

            $age   = max(0, ($now - strtotime($r['created_at'])) / 86400);
            $score = $w * exp(-$age / $HALF);

            if ($r['latitude'] !== null && $r['longitude'] !== null && $age <= 90) {   // map shows the last 3 months only
                $points[] = [
                    'lat'    => (float) $r['latitude'],
                    'lng'    => (float) $r['longitude'],
                    'weight' => round($score, 4),
                    'status' => $r['status'],
                    'type'   => $r['report_type'],
                    'date'   => substr($r['created_at'], 0, 10),
                    'barangay' => $r['barangay_name'],
                ];
            }

            $bid = $r['barangay_id'];
            if (!isset($brgy[$bid])) continue;
            $brgy[$bid]['score'] += $score;
            if ($age <= 90)               $brgy[$bid]['recent_90']++;
            elseif ($age <= 180)          $brgy[$bid]['previous_90']++;
            if ($age <= 30)               $brgy[$bid]['last_30']++;
            if ($r['status'] === 'positive_rabies') $brgy[$bid]['positive']++;
            if (!$brgy[$bid]['last_case'] || $r['created_at'] > $brgy[$bid]['last_case']) {
                $brgy[$bid]['last_case'] = $r['created_at'];
            }
        }

        $list = [];
        foreach ($brgy as $b) {
            $recent = $b['recent_90'];
            $prev   = $b['previous_90'];
            $ratio  = ($recent + 1) / ($prev + 1);              // +1 so a first case doesn't divide by zero
            $trend  = $ratio >= 1.25 ? 'rising' : ($ratio <= 0.8 ? 'falling' : 'stable');
            $expected = ($recent / 3) * min(2, max(0.5, $ratio)); // monthly average × trend

            $score = round($b['score'], 2);
            if ($score >= 1.5 || ($b['last_30'] > 0 && $trend === 'rising')) $level = 'high';
            elseif ($score >= 0.5)                                          $level = 'moderate';
            elseif ($score > 0)                                             $level = 'low';
            else                                                            $level = 'none';

            $list[] = [
                'id'           => $b['id'],
                'name'         => $b['name'],
                'score'        => $score,
                'level'        => $level,
                'trend'        => $trend,
                'recent_90'    => $recent,
                'previous_90'  => $prev,
                'last_30'      => $b['last_30'],
                'positive'     => $b['positive'],
                'expected_30d' => round($expected, 1),
                'last_case'    => $b['last_case'] ? substr($b['last_case'], 0, 10) : null,
            ];
        }
        usort($list, function ($a, $b) { return $b['score'] <=> $a['score']; });

        echo json_encode([
            'success'      => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'points'       => $points,
            'barangays'    => $list,
            'totals'       => [
                'cases_90d'    => array_sum(array_column($list, 'recent_90')),
                'cases_prev90' => array_sum(array_column($list, 'previous_90')),
                'expected_30d' => round(array_sum(array_column($list, 'expected_30d')), 1),
                'high_risk'    => count(array_filter($list, function ($b) { return $b['level'] === 'high'; })),
            ],
        ]);
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
             WHEN r.reporter_name IS NOT NULL AND r.reporter_name != '' THEN r.reporter_name
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
        $status_val    = (isset($_POST['auto_status']) && $_POST['auto_status'] === 'positive_rabies') ? 'positive_rabies' : 'pending';
        $desc_val      = !empty($description) ? $description : null;
        $lat_val       = isset($latitude)  && $latitude  !== '' ? (float)$latitude  : null;
        $lng_val       = isset($longitude) && $longitude !== '' ? (float)$longitude : null;

        // Handle image uploads
        $images_json = null;
        if (!empty($_FILES['images']['tmp_name'])) {
            $upload_dir = __DIR__ . '/../uploads/reports/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $allowed     = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $image_paths = [];
            $tmp_names   = (array)$_FILES['images']['tmp_name'];
            $file_names  = (array)$_FILES['images']['name'];
            $file_errors = (array)$_FILES['images']['error'];
            foreach ($tmp_names as $i => $tmp) {
                if (count($image_paths) >= 3) break;
                if ($file_errors[$i] !== UPLOAD_ERR_OK) continue;
                $ext = strtolower(pathinfo($file_names[$i], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;
                $filename = 'rpt_' . time() . '_' . $i . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest     = $upload_dir . $filename;
                if (move_uploaded_file($tmp, $dest)) {
                    $image_paths[] = 'uploads/reports/' . $filename;
                }
            }
            if (!empty($image_paths)) $images_json = json_encode($image_paths);
        }

        $stmt = $pdo->prepare("
            INSERT INTO reports (report_number, user_id, report_type, barangay_id, address, phone_number, description, latitude, longitude, images, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

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
            $images_json,
            $status_val
        ]);

        $newId = $pdo->lastInsertId();

        $stmtGet = $pdo->prepare("SELECT r.*, b.name AS barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id = b.id WHERE r.id = ?");
        $stmtGet->execute([$newId]);
        $report = $stmtGet->fetch(PDO::FETCH_ASSOC);
        $report['images'] = !empty($report['images']) ? json_decode($report['images'], true) : [];

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
                         reporter_name, description, latitude, longitude, images, status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, NOW(), NOW())
");
$reporter_name = trim($_POST['reporter_name'] ?? '');
$rname_val = !empty($reporter_name) ? $reporter_name : null;
$stmt->execute([
    $report_number, $user['id'], $report_type, $barangay_id,
    $address, $phone_number, $rname_val, $desc_val, $latitude, $longitude, $auto_status
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