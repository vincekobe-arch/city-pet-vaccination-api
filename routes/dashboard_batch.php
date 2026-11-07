<?php
// routes/dashboard_batch.php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

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

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = '/city-pet-vaccination-api';
$uri    = str_replace($base, '', $uri);

if ($method !== 'GET' || $uri !== '/dashboard/batch') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

$user = authenticate();
if (!$user || !in_array($user['role'], ['barangay_official', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // barangay_id lives in user_details for barangay_official
    $barangay_id = $user['user_details']['assigned_barangay_id'] ?? null;

    // ── 1. Pets with vaccination count ──────────────────────────────────────
    // Matches PetController::index() barangay_official logic exactly:
    // filter by owner's assigned_barangay_id, not pet's column
    if ($barangay_id) {
        $petsStmt = $pdo->prepare("
            SELECT p.id, p.name, p.species, p.breed, p.gender,
                   p.registration_date, p.created_at, p.color, p.photo_url,
                   CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
                   COUNT(vr.id) AS vaccination_count
            FROM pets p
            LEFT JOIN pet_owners po ON p.owner_id = po.id
            LEFT JOIN users u       ON po.user_id  = u.id
            LEFT JOIN vaccination_records vr ON vr.pet_id = p.id
            WHERE p.deleted_at IS NULL
              AND p.is_active = 1
              AND (u.assigned_barangay_id = ? OR u.assigned_barangay_id IS NULL)
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ");
        $petsStmt->execute([$barangay_id]);
    } else {
        // super_admin: all pets
        $petsStmt = $pdo->prepare("
            SELECT p.id, p.name, p.species, p.breed, p.gender,
                   p.registration_date, p.created_at, p.color, p.photo_url,
                   CONCAT(u.first_name, ' ', u.last_name) AS owner_name,
                   COUNT(vr.id) AS vaccination_count
            FROM pets p
            LEFT JOIN pet_owners po ON p.owner_id = po.id
            LEFT JOIN users u       ON po.user_id  = u.id
            LEFT JOIN vaccination_records vr ON vr.pet_id = p.id
            WHERE p.deleted_at IS NULL AND p.is_active = 1
            GROUP BY p.id
            ORDER BY p.created_at DESC
        ");
        $petsStmt->execute();
    }
    $pets = $petsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── 2. Owners: total + pending verification count ────────────────────────
    // Matches OwnerController::index() — filters by assigned_barangay_id on users
    if ($barangay_id) {
        $ownersStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT u.id) AS total,
                   SUM(CASE WHEN po.verification_status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM users u
            LEFT JOIN pet_owners po ON u.id = po.user_id
            WHERE u.role = 'pet_owner'
              AND u.assigned_barangay_id = ?
        ");
        $ownersStmt->execute([$barangay_id]);
    } else {
        $ownersStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT u.id) AS total,
                   SUM(CASE WHEN po.verification_status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            FROM users u
            LEFT JOIN pet_owners po ON u.id = po.user_id
            WHERE u.role = 'pet_owner'
        ");
        $ownersStmt->execute();
    }
    $ownersData = $ownersStmt->fetch(PDO::FETCH_ASSOC);

    // ── 3. Reports: recent 10 ────────────────────────────────────────────────
    // Matches ReportController::index() reporter_name CASE logic exactly
    if ($barangay_id) {
        $reportsStmt = $pdo->prepare("
            SELECT r.id, r.report_type, r.status, r.latitude, r.longitude,
                   r.created_at,
                   b.name AS barangay_name,
                   CASE
                     WHEN r.reporter_name IS NOT NULL AND r.reporter_name != '' THEN r.reporter_name
                     WHEN u.role = 'private_clinic' THEN pc.clinic_name
                     ELSE CONCAT(u.first_name, ' ', u.last_name)
                   END AS reporter_name
            FROM reports r
            LEFT JOIN barangays b       ON b.id  = r.barangay_id
            LEFT JOIN users u           ON u.id  = r.user_id
            LEFT JOIN private_clinics pc ON pc.user_id = u.id
            WHERE r.deleted_at IS NULL
              AND r.barangay_id = ?
            ORDER BY r.created_at DESC
            LIMIT 10
        ");
        $reportsStmt->execute([$barangay_id]);
    } else {
        $reportsStmt = $pdo->prepare("
            SELECT r.id, r.report_type, r.status, r.latitude, r.longitude,
                   r.created_at,
                   b.name AS barangay_name,
                   CASE
                     WHEN r.reporter_name IS NOT NULL AND r.reporter_name != '' THEN r.reporter_name
                     WHEN u.role = 'private_clinic' THEN pc.clinic_name
                     ELSE CONCAT(u.first_name, ' ', u.last_name)
                   END AS reporter_name
            FROM reports r
            LEFT JOIN barangays b        ON b.id  = r.barangay_id
            LEFT JOIN users u            ON u.id  = r.user_id
            LEFT JOIN private_clinics pc ON pc.user_id = u.id
            WHERE r.deleted_at IS NULL
            ORDER BY r.created_at DESC
            LIMIT 10
        ");
        $reportsStmt->execute();
    }
    $reports        = $reportsStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingReports = count(array_filter($reports, fn($r) => $r['status'] === 'pending'));

    // ── 4. Expired inventory batches count ──────────────────────────────────
    // Matches InventoryController — tables: inventory + inventory_batches
    // inventory has no barangay_id column so no filter needed
    $expiredStmt = $pdo->prepare("
        SELECT COUNT(*) AS expired_count
        FROM inventory_batches ib
        JOIN inventory i ON i.id = ib.inventory_id
        WHERE ib.expiration_date < CURDATE()
          AND ib.quantity > 0
    ");
    $expiredStmt->execute();
    $expiredData = $expiredStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'         => true,
        'pets'            => $pets,
        'owners'          => [
            'total'         => (int)($ownersData['total']         ?? 0),
            'pending_count' => (int)($ownersData['pending_count'] ?? 0),
        ],
        'reports'         => $reports,
        'pending_reports' => $pendingReports,
        'expired_batches' => (int)($expiredData['expired_count'] ?? 0),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}