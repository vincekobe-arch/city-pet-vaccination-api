<?php
// helpers/pet_qr.php
// Builds the pet identity QR payload (same format as the microchip QR)
// and saves it on the pet, so every registered pet has a QR code.

if (!function_exists('build_pet_qr_data')) {
    function build_pet_qr_data($conn, $pet_id) {
        $query = "SELECT p.*,
                         CONCAT(u.first_name, ' ', u.last_name) as owner_name,
                         u.phone as owner_phone,
                         b.name as barangay_name
                  FROM pets p
                  LEFT JOIN pet_owners po ON p.owner_id = po.id
                  LEFT JOIN users u ON po.user_id = u.id
                  LEFT JOIN barangays b ON u.assigned_barangay_id = b.id
                  WHERE p.id = :pet_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':pet_id', $pet_id);
        $stmt->execute();
        $pet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pet) return null;

        $GLOBALS['__pet_qr_existing'] = $pet['qr_code'] ?? null;
        return json_encode([
            'microchip'    => $pet['microchip_number'] ?: 'N/A',
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
}

if (!function_exists('refresh_pet_qr')) {
    // Generates (or regenerates) the QR for a pet and saves it. Returns the base64 value.
    function refresh_pet_qr($conn, $pet_id) {
        try {
            $data = build_pet_qr_data($conn, $pet_id);
            if ($data === null) return null;
            $qr = base64_encode($data);
            // Only write when something changed; keep updated_at as it was
            if (($GLOBALS['__pet_qr_existing'] ?? null) === $qr) return $qr;
            $stmt = $conn->prepare("UPDATE pets SET qr_code = :qr, updated_at = updated_at WHERE id = :id");
            $stmt->bindParam(':qr', $qr);
            $stmt->bindParam(':id', $pet_id, PDO::PARAM_INT);
            $stmt->execute();
            return $qr;
        } catch (Exception $e) {
            // Never block registration or updates because of the QR
            error_log('refresh_pet_qr failed for pet ' . $pet_id . ': ' . $e->getMessage());
            return null;
        }
    }
}
?>