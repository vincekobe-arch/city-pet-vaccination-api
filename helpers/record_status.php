<?php
// helpers/record_status.php
// Shared rules for when a vaccination / deworming record is active or inactive.
//   archived  → archived by staff (deleted_at is set). Data is kept.
//   completed → a later session of the same vaccine/dewormer was recorded
//   missed    → next due date passed and no follow-up session was recorded
//   active    → everything else
// "Inactive" = archived + missed. The vet card shows only active + completed.

if (!function_exists('ph_today')) {
    // Today's date in the Philippines, regardless of server/MySQL timezone
    function ph_today() {
        return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }
}

if (!function_exists('vaccination_status_sql')) {
    function vaccination_status_sql($a = 'vr', $include_archived = true) {
        $today    = ph_today();
        $archived = $include_archived ? "WHEN $a.deleted_at IS NOT NULL THEN 'archived'" : '';
        return "(CASE
            $archived
            WHEN EXISTS (
                SELECT 1 FROM vaccination_records nx
                WHERE nx.pet_id = $a.pet_id
                  AND nx.id <> $a.id
                  AND nx.deleted_at IS NULL
                  AND (nx.vaccination_date > $a.vaccination_date
                       OR (nx.vaccination_date = $a.vaccination_date AND nx.id > $a.id))
                  AND (
                       ($a.clinic_item_id IS NOT NULL AND nx.clinic_item_id = $a.clinic_item_id)
                    OR ($a.clinic_item_id IS NULL AND $a.inventory_id IS NOT NULL AND nx.inventory_id = $a.inventory_id)
                    OR ($a.clinic_item_id IS NULL AND $a.inventory_id IS NULL AND nx.vaccination_type_id = $a.vaccination_type_id)
                  )
            ) THEN 'completed'
            WHEN $a.next_due_date IS NOT NULL AND $a.next_due_date < '$today' THEN 'missed'
            ELSE 'active'
        END)";
    }
}

if (!function_exists('deworming_status_sql')) {
    function deworming_status_sql($a = 'dr', $include_archived = true) {
        $today    = ph_today();
        $archived = $include_archived ? "WHEN $a.deleted_at IS NOT NULL THEN 'archived'" : '';
        return "(CASE
            $archived
            WHEN EXISTS (
                SELECT 1 FROM deworming_records nx
                WHERE nx.pet_id = $a.pet_id
                  AND nx.id <> $a.id
                  AND nx.deleted_at IS NULL
                  AND (nx.deworming_date > $a.deworming_date
                       OR (nx.deworming_date = $a.deworming_date AND nx.id > $a.id))
                  AND (
                       ($a.clinic_item_id IS NOT NULL AND nx.clinic_item_id = $a.clinic_item_id)
                    OR ($a.clinic_item_id IS NULL AND nx.clinic_item_id IS NULL AND nx.deworming_type_id = $a.deworming_type_id)
                  )
            ) THEN 'completed'
            WHEN $a.next_due_date IS NOT NULL AND $a.next_due_date < '$today' THEN 'missed'
            ELSE 'active'
        END)";
    }
}

if (!function_exists('build_restore_update')) {
    // Works out how to restore an archived or missed record.
    // $data may contain next_due_date (YYYY-MM-DD) and reason.
    function build_restore_update(PDO $conn, $type, $id, $data) {
        $is_vac   = $type === 'vaccination';
        $table    = $is_vac ? 'vaccination_records' : 'deworming_records';
        $alias    = $is_vac ? 'vr' : 'dr';
        $date_col = $is_vac ? 'vaccination_date' : 'deworming_date';
        $status   = $is_vac ? vaccination_status_sql('vr', false) : deworming_status_sql('dr', false);

        $stmt = $conn->prepare("SELECT $alias.*, $status AS status_if_active FROM $table $alias WHERE $alias.id = :id");
        $stmt->execute([':id' => $id]);
        $rec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rec) return ['error' => 'Record not found', 'code' => 404];

        $was_archived = !empty($rec['deleted_at']);
        $is_missed    = $rec['status_if_active'] === 'missed';
        if (!$was_archived && !$is_missed) {
            return ['error' => 'This record is already active.', 'code' => 400];
        }

        $new_due = (isset($data->next_due_date) && $data->next_due_date !== '' && $data->next_due_date !== null)
            ? (string) $data->next_due_date : null;
        $reason  = (isset($data->reason) && trim((string) $data->reason) !== '')
            ? substr(trim((string) $data->reason), 0, 255) : null;

        if ($is_missed && !$new_due) {
            return [
                'error' => 'The due date of this record has already passed. Please set a new next due date.',
                'code' => 422,
                'needs_new_due_date' => true
            ];
        }

        $set    = ['deleted_at = NULL', 'deleted_by = NULL'];
        $params = [':id' => $id];

        if ($new_due) {
            $d = DateTime::createFromFormat('Y-m-d', $new_due);
            if (!$d || $d->format('Y-m-d') !== $new_due) return ['error' => 'Invalid next due date.', 'code' => 422];
            if ($new_due < ph_today())                   return ['error' => 'The new next due date cannot be in the past.', 'code' => 422];
            if ($new_due <= $rec[$date_col])             return ['error' => 'The next due date must be after the record date.', 'code' => 422];

            $set[] = 'rescheduled_from = COALESCE(rescheduled_from, :old_due)'; // keeps the very first due date
            $set[] = 'next_due_date = :new_due';
            $set[] = 'reschedule_reason = :reason';
            $params[':old_due'] = $rec['next_due_date'];
            $params[':new_due'] = $new_due;
            $params[':reason']  = $reason;
        }

        return ['error' => null, 'record' => $rec, 'set' => $set, 'params' => $params, 'was_archived' => $was_archived];
    }
}