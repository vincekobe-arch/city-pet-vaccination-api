<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/Auth.php';

/**
 * ActivityLogController
 * Read-only activity log built from existing tables.
 * Shows records (vaccination, deworming, sterilization, microchip) and
 * scheduled events added by City Vet admins and private clinics,
 * including who added them and when.
 *
 * GET /activity-logs
 *   ?role=admin|clinic        (optional)
 *   ?category=record|event    (optional)
 *   ?date_from=YYYY-MM-DD     (optional, based on date added)
 *   ?date_to=YYYY-MM-DD       (optional)
 *   ?search=text              (optional)
 *   ?limit=10                 (optional, omit for all)
 */
class ActivityLogController {
    private $conn;
    private $auth;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        $this->auth = new Auth();
    }

    public function index() {
        $user_data = $this->auth->authenticate();
        $this->auth->checkRole(['super_admin'], $user_data);

        try {
            $query = "SELECT l.*,
                        CONCAT(u.first_name, ' ', u.last_name) AS performed_by_name,
                        u.username AS performed_by_username,
                        u.role AS performed_by_role,
                        CASE
                            WHEN u.role = 'private_clinic' THEN COALESCE(pc.clinic_name, 'Private Clinic')
                            WHEN u.role = 'super_admin' THEN 'Super Administrator'
                            ELSE 'City Vet Muntinlupa'
                        END AS organization,
                        pc.clinic_code,
                        (SELECT bo.office_role FROM barangay_officials bo
                          WHERE bo.user_id = u.id ORDER BY bo.id DESC LIMIT 1) AS office_role
                      FROM (" . $this->unionQuery() . ") l
                      JOIN users u ON u.id = l.performed_by
                      LEFT JOIN private_clinics pc ON pc.user_id = u.id
                      WHERE u.role IN ('barangay_official', 'private_clinic', 'super_admin')";

            $params = [];

            // Filter: who performed it
            $role = $_GET['role'] ?? '';
            if ($role === 'admin') {
                $query .= " AND u.role IN ('barangay_official', 'super_admin')";
            } elseif ($role === 'clinic') {
                $query .= " AND u.role = 'private_clinic'";
            }

            // Filter: records or events
            $category = $_GET['category'] ?? '';
            if ($category === 'record' || $category === 'event') {
                $query .= " AND l.category = :category";
                $params[':category'] = $category;
            }

            // Filter: date added range
            if (!empty($_GET['date_from'])) {
                $query .= " AND DATE(l.created_at) >= :date_from";
                $params[':date_from'] = $_GET['date_from'];
            }
            if (!empty($_GET['date_to'])) {
                $query .= " AND DATE(l.created_at) <= :date_to";
                $params[':date_to'] = $_GET['date_to'];
            }

            // Filter: search text
            if (!empty($_GET['search'])) {
                $query .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE :s1
                             OR pc.clinic_name LIKE :s2
                             OR l.item_name LIKE :s3
                             OR l.pet_name LIKE :s4
                             OR l.registration_number LIKE :s5
                             OR l.owner_name LIKE :s6)";
                $term = '%' . $_GET['search'] . '%';
                for ($i = 1; $i <= 6; $i++) {
                    $params[":s$i"] = $term;
                }
            }

            $query .= " ORDER BY l.created_at DESC";

            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 0;
            if ($limit > 0) {
                $query .= " LIMIT " . min($limit, 1000);
            }

            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'total' => count($logs)
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get activity logs: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Combines all records and schedules into one list with the same columns.
     */
    private function unionQuery() {
        $parts = [];

        // Vaccination records
        $parts[] = "SELECT CONCAT('vaccination-', vr.id) AS log_id,
                'record' AS category, 'vaccination' AS activity_type,
                'Added Vaccination Record' AS action,
                COALESCE(ci.item_name, vt.name, inv.item_name, 'Vaccine') AS item_name,
                p.name AS pet_name, p.registration_number, p.species,
                CONCAT(po.first_name, ' ', po.last_name) AS owner_name,
                vr.veterinarian_name,
                vr.vaccination_date AS activity_date,
                NULL AS start_time, NULL AS end_time, NULL AS location,
                CASE WHEN vr.deleted_at IS NULL THEN 'active' ELSE 'archived' END AS status,
                vr.administered_by AS performed_by, vr.created_at
            FROM vaccination_records vr
            JOIN pets p ON p.id = vr.pet_id
            LEFT JOIN pet_owners po ON po.id = p.owner_id
            LEFT JOIN vaccination_types vt ON vt.id = vr.vaccination_type_id
            LEFT JOIN clinic_inventory ci ON ci.id = vr.clinic_item_id
            LEFT JOIN inventory inv ON inv.id = vr.inventory_id";

        // Deworming records
        $parts[] = "SELECT CONCAT('deworming-', dr.id),
                'record', 'deworming',
                'Added Deworming Record',
                COALESCE(ci.item_name, dt.name, dr.product_name, 'Deworming'),
                p.name, p.registration_number, p.species,
                CONCAT(po.first_name, ' ', po.last_name),
                dr.veterinarian_name,
                dr.deworming_date,
                NULL, NULL, NULL,
                CASE WHEN dr.deleted_at IS NULL THEN 'active' ELSE 'archived' END,
                dr.administered_by, dr.created_at
            FROM deworming_records dr
            JOIN pets p ON p.id = dr.pet_id
            LEFT JOIN pet_owners po ON po.id = p.owner_id
            LEFT JOIN deworming_types dt ON dt.id = dr.deworming_type_id
            LEFT JOIN clinic_inventory ci ON ci.id = dr.clinic_item_id";

        // Sterilization records
        $parts[] = "SELECT CONCAT('sterilization-', sr.id),
                'record', 'sterilization',
                'Added Sterilization Record',
                CONCAT(UPPER(LEFT(sr.procedure_type, 1)), SUBSTRING(sr.procedure_type, 2)),
                p.name, p.registration_number, p.species,
                CONCAT(po.first_name, ' ', po.last_name),
                sr.veterinarian_name,
                sr.sterilization_date,
                NULL, NULL, NULL,
                CASE WHEN sr.deleted_at IS NULL THEN 'active' ELSE 'archived' END,
                sr.administered_by, sr.created_at
            FROM sterilization_records sr
            JOIN pets p ON p.id = sr.pet_id
            LEFT JOIN pet_owners po ON po.id = p.owner_id";

        // Microchip records
        $parts[] = "SELECT CONCAT('microchip-', mr.id),
                'record', 'microchip',
                'Added Microchip Record',
                CONCAT_WS(' - ', CONCAT('Microchip #', mr.microchip_number), mr.microchip_brand),
                p.name, p.registration_number, p.species,
                CONCAT(po.first_name, ' ', po.last_name),
                mr.veterinarian_name,
                mr.implant_date,
                NULL, NULL, NULL,
                CASE WHEN mr.deleted_at IS NULL THEN 'active' ELSE 'archived' END,
                mr.administered_by, mr.created_at
            FROM microchip_records mr
            JOIN pets p ON p.id = mr.pet_id
            LEFT JOIN pet_owners po ON po.id = p.owner_id";

        // Scheduled events
        $schedules = [
            'vaccination_schedules'   => ['vaccination',   'Vaccination'],
            'deworming_schedules'     => ['deworming',     'Deworming'],
            'sterilization_schedules' => ['sterilization', 'Sterilization'],
            'microchip_schedules'     => ['microchip',     'Microchip'],
            'seminar_schedules'       => ['seminar',       'Seminar'],
            'other_schedules'         => ['other',         'Other'],
        ];

        foreach ($schedules as $table => [$key, $label]) {
            $parts[] = "SELECT CONCAT('{$key}-event-', s.id),
                    'event', '{$key}_event',
                    'Scheduled {$label} Event',
                    s.title,
                    NULL, NULL, NULL, NULL, NULL,
                    s.scheduled_date,
                    s.start_time, s.end_time,
                    CONCAT_WS(', ', s.venue, COALESCE(b.name, s.custom_place)),
                    s.status,
                    s.created_by, s.created_at
                FROM {$table} s
                LEFT JOIN barangays b ON b.id = s.barangay_id";
        }

        return implode("\nUNION ALL\n", $parts);
    }
}
?>