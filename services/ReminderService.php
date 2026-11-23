<?php

require_once __DIR__ . '/../PHPMailer-7.0.0/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/database.php';

class ReminderService {
    private $conn;
    private $mailer;
    private $emailConfig;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();

            $this->loadEmailConfig();
            $this->setupMailer();
            $this->createReminderLogsTable();

        } catch (\Throwable $e) {
            error_log("ReminderService: Constructor error - " . $e->getMessage());
            throw new \Exception($e->getMessage());
        }
    }

    private function loadEmailConfig() {
        $configFile = __DIR__ . '/../config/email_config.php';

        if (!file_exists($configFile)) {
            throw new \Exception('Email configuration file not found');
        }

        $this->emailConfig = require $configFile;

        if (empty($this->emailConfig['smtp']['username']) ||
            empty($this->emailConfig['smtp']['password'])) {
            throw new \Exception('Email configuration is incomplete');
        }
    }

    private function setupMailer() {
        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        $this->mailer->Host       = $this->emailConfig['smtp']['host'];
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = $this->emailConfig['smtp']['username'];
        $this->mailer->Password   = $this->emailConfig['smtp']['password'];
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port       = $this->emailConfig['smtp']['port'];
        $this->mailer->SMTPDebug  = 0;
        $this->mailer->CharSet    = 'UTF-8';
        $this->mailer->Debugoutput = function($str, $level) {
            error_log("PHPMailer: $str");
        };
        $this->mailer->setFrom(
            $this->emailConfig['from']['email'],
            $this->emailConfig['from']['name']
        );
    }

    private function createReminderLogsTable() {
        $this->conn->exec("CREATE TABLE IF NOT EXISTS reminder_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pet_id INT NOT NULL,
            owner_email VARCHAR(255) NOT NULL,
            reminder_type ENUM('vaccination', 'deworming') NOT NULL,
            record_id INT NOT NULL,
            reminder_period ENUM('confirmation', '7_days', '1_day') NOT NULL,
            due_date DATE NOT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('sent', 'failed') DEFAULT 'sent',
            error_message TEXT NULL,
            INDEX idx_pet_reminder (pet_id, reminder_type, record_id, reminder_period),
            INDEX idx_due_date (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function hasReminderBeenSent($record_id, $reminder_type, $reminder_period) {
        $stmt = $this->conn->prepare(
            "SELECT id FROM reminder_logs
             WHERE record_id = :record_id AND reminder_type = :reminder_type
               AND reminder_period = :reminder_period AND status = 'sent'
             LIMIT 1"
        );
        $stmt->execute([
            ':record_id'       => $record_id,
            ':reminder_type'   => $reminder_type,
            ':reminder_period' => $reminder_period,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    private function logReminder($pet_id, $owner_email, $reminder_type, $record_id, $reminder_period, $due_date, $status = 'sent', $error = null) {
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO reminder_logs
                 (pet_id, owner_email, reminder_type, record_id, reminder_period, due_date, status, error_message)
                 VALUES (:pet_id, :owner_email, :reminder_type, :record_id, :reminder_period, :due_date, :status, :error)"
            );
            $stmt->execute([
                ':pet_id'          => $pet_id,
                ':owner_email'     => $owner_email,
                ':reminder_type'   => $reminder_type,
                ':record_id'       => $record_id,
                ':reminder_period' => $reminder_period,
                ':due_date'        => $due_date,
                ':status'          => $status,
                ':error'           => $error,
            ]);
        } catch (\Throwable $e) {
            error_log("ReminderService: Could not write reminder log - " . $e->getMessage());
        }
    }

    // ─── Shared SELECT parts ─────────────────────────────────────────────────
    // Works for every way a record can be saved:
    //   admin  → vaccination_type_id or inventory_id
    //   clinic → clinic_item_id
    // (LEFT JOINs, so a record with no vaccination_type_id is still found.)

    private function vaccinationSelect() {
        return "SELECT vr.*, p.name AS pet_name, p.species, p.breed,
                       COALESCE(ci.item_name, vt.name, inv.item_name, 'Vaccine') AS vaccine_name,
                       po.first_name, po.last_name, u.email AS owner_email,
                       CASE WHEN au.role = 'private_clinic' THEN pc.clinic_name
                            ELSE 'City Vet Muntinlupa' END AS recorded_by
                FROM vaccination_records vr
                JOIN pets p              ON vr.pet_id = p.id
                LEFT JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                LEFT JOIN clinic_inventory ci  ON vr.clinic_item_id = ci.id
                LEFT JOIN inventory inv        ON vr.inventory_id = inv.id
                JOIN pet_owners po       ON p.owner_id = po.id
                JOIN users u             ON po.user_id = u.id
                LEFT JOIN users au       ON vr.administered_by = au.id
                LEFT JOIN private_clinics pc ON pc.user_id = au.id";
    }

    private function dewormingSelect() {
        return "SELECT dr.*, p.name AS pet_name, p.species, p.breed,
                       COALESCE(ci.item_name, dt.name, dr.product_name, 'Dewormer') AS deworming_name,
                       dt.target_parasites,
                       po.first_name, po.last_name, u.email AS owner_email,
                       CASE WHEN au.role = 'private_clinic' THEN pc.clinic_name
                            ELSE 'City Vet Muntinlupa' END AS recorded_by
                FROM deworming_records dr
                JOIN pets p              ON dr.pet_id = p.id
                LEFT JOIN deworming_types dt  ON dr.deworming_type_id = dt.id
                LEFT JOIN clinic_inventory ci ON dr.clinic_item_id = ci.id
                JOIN pet_owners po       ON p.owner_id = po.id
                JOIN users u             ON po.user_id = u.id
                LEFT JOIN users au       ON dr.administered_by = au.id
                LEFT JOIN private_clinics pc ON pc.user_id = au.id";
    }

    // ─── Instant email right after a vaccination is saved ────────────────────
    public function sendVaccinationConfirmation($pet_id, $vaccination_id) {
        try {
            $stmt = $this->conn->prepare($this->vaccinationSelect() . " WHERE vr.id = :id");
            $stmt->execute([':id' => $vaccination_id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec || empty($rec['owner_email'])) {
                error_log("ReminderService: No owner email found for vaccination ID $vaccination_id");
                return ['success' => false, 'message' => 'No owner email found'];
            }

            if ($this->hasReminderBeenSent($vaccination_id, 'vaccination', 'confirmation')) {
                return ['success' => true, 'message' => 'Confirmation already sent'];
            }

            $result = $this->sendMail(
                $rec['owner_email'],
                trim($rec['first_name'] . ' ' . $rec['last_name']),
                "Vaccination Record for {$rec['pet_name']} - PetUnity",
                $this->getConfirmationTemplate([
                    'kind'      => 'vaccination',
                    'owner'     => trim($rec['first_name'] . ' ' . $rec['last_name']),
                    'pet'       => $rec['pet_name'],
                    'item'      => $rec['vaccine_name'],
                    'date'      => $rec['vaccination_date'],
                    'next'      => $rec['next_due_date'],
                    'by'        => $rec['recorded_by'],
                    'vet'       => $rec['veterinarian_name'] ?? null,
                ]),
                $this->getConfirmationText('vaccination', $rec['pet_name'], $rec['vaccine_name'],
                    $rec['vaccination_date'], $rec['next_due_date'], $rec['recorded_by'])
            );

            $this->logReminder($rec['pet_id'], $rec['owner_email'], 'vaccination', $vaccination_id,
                'confirmation', $rec['next_due_date'] ?: $rec['vaccination_date'],
                $result['success'] ? 'sent' : 'failed', $result['success'] ? null : $result['message']);

            return $result;

        } catch (\Throwable $e) {
            error_log("ReminderService: Error sending vaccination confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── Instant email right after a deworming is saved ──────────────────────
    public function sendDewormingConfirmation($pet_id, $deworming_id) {
        try {
            $stmt = $this->conn->prepare($this->dewormingSelect() . " WHERE dr.id = :id");
            $stmt->execute([':id' => $deworming_id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec || empty($rec['owner_email'])) {
                error_log("ReminderService: No owner email found for deworming ID $deworming_id");
                return ['success' => false, 'message' => 'No owner email found'];
            }

            if ($this->hasReminderBeenSent($deworming_id, 'deworming', 'confirmation')) {
                return ['success' => true, 'message' => 'Confirmation already sent'];
            }

            $result = $this->sendMail(
                $rec['owner_email'],
                trim($rec['first_name'] . ' ' . $rec['last_name']),
                "Deworming Record for {$rec['pet_name']} - PetUnity",
                $this->getConfirmationTemplate([
                    'kind'      => 'deworming',
                    'owner'     => trim($rec['first_name'] . ' ' . $rec['last_name']),
                    'pet'       => $rec['pet_name'],
                    'item'      => $rec['deworming_name'],
                    'date'      => $rec['deworming_date'],
                    'next'      => $rec['next_due_date'],
                    'by'        => $rec['recorded_by'],
                    'vet'       => $rec['veterinarian_name'] ?? null,
                    'dosage'    => $rec['dosage'] ?? null,
                ]),
                $this->getConfirmationText('deworming', $rec['pet_name'], $rec['deworming_name'],
                    $rec['deworming_date'], $rec['next_due_date'], $rec['recorded_by'])
            );

            $this->logReminder($rec['pet_id'], $rec['owner_email'], 'deworming', $deworming_id,
                'confirmation', $rec['next_due_date'] ?: $rec['deworming_date'],
                $result['success'] ? 'sent' : 'failed', $result['success'] ? null : $result['message']);

            return $result;

        } catch (\Throwable $e) {
            error_log("ReminderService: Error sending deworming confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── Scheduled reminders (7 days / 1 day before the next session) ────────
    public function sendVaccinationReminders() {
        return $this->sendDueReminders('vaccination');
    }

    public function sendDewormingReminders() {
        return $this->sendDueReminders('deworming');
    }

    // Runs once a day (see cron/send_reminders.php).
    //  • "7 days" reminder: next session is 2–7 days away
    //  • "1 day" reminder:  next session is tomorrow or today
    // Each reminder is sent once per record. If the cron missed a day, the
    // reminder still goes out on the next run instead of being skipped.
    private function sendDueReminders($type) {
        try {
            $today = date('Y-m-d');
            $week  = date('Y-m-d', strtotime('+7 days'));
            $a = $type === 'vaccination' ? 'vr' : 'dr';
            $given = $type === 'vaccination' ? 'vaccination_date' : 'deworming_date';
            $select = $type === 'vaccination' ? $this->vaccinationSelect() : $this->dewormingSelect();

            $stmt = $this->conn->prepare($select . "
                WHERE $a.next_due_date BETWEEN :today AND :week
                  AND $a.$given < :today2
                  AND $a.deleted_at IS NULL
                  AND p.is_active = 1
                  AND u.email IS NOT NULL AND u.email <> ''");
            $stmt->execute([':today' => $today, ':week' => $week, ':today2' => $today]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $sent = 0;
            $failed = 0;
            foreach ($rows as $rec) {
                $days_left = (int) round((strtotime($rec['next_due_date']) - strtotime($today)) / 86400);
                $period = $days_left <= 1 ? '1_day' : '7_days';
                if ($this->hasReminderBeenSent($rec['id'], $type, $period)) continue;

                // A newer record for the same pet replaced this one — no reminder needed
                if ($this->hasNewerRecord($type, $rec)) continue;

                $item  = $type === 'vaccination' ? $rec['vaccine_name'] : $rec['deworming_name'];
                $when  = $days_left <= 0 ? 'today' : ($days_left === 1 ? 'tomorrow' : "in $days_left days");
                $label = $type === 'vaccination' ? 'Vaccination' : 'Deworming';

                $result = $this->sendMail(
                    $rec['owner_email'],
                    trim($rec['first_name'] . ' ' . $rec['last_name']),
                    "$label Reminder: {$rec['pet_name']} is due $when - PetUnity",
                    $this->getReminderTemplate($type, $rec, $item, $when),
                    "Reminder: {$rec['pet_name']}'s next " . strtolower($label) . " ($item) is due $when, on " .
                        $this->formatDate($rec['next_due_date']) . "."
                );

                $this->logReminder($rec['pet_id'], $rec['owner_email'], $type, $rec['id'], $period,
                    $rec['next_due_date'], $result['success'] ? 'sent' : 'failed',
                    $result['success'] ? null : $result['message']);
                $result['success'] ? $sent++ : $failed++;
            }

            error_log("ReminderService: $type reminders done - Sent: $sent, Failed: $failed");
            return ['success' => true, 'sent' => $sent, 'failed' => $failed];

        } catch (\Throwable $e) {
            error_log("ReminderService: Error sending $type reminders - " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // True when the pet already got the same vaccine/dewormer again after this record
    private function hasNewerRecord($type, $rec) {
        if ($type === 'vaccination') {
            $stmt = $this->conn->prepare(
                "SELECT id FROM vaccination_records
                 WHERE pet_id = :pet AND id <> :id AND deleted_at IS NULL
                   AND vaccination_date > :d
                   AND ( (vaccination_type_id IS NOT NULL AND vaccination_type_id = :vt)
                      OR (clinic_item_id IS NOT NULL AND clinic_item_id = :ci)
                      OR (inventory_id IS NOT NULL AND inventory_id = :inv) )
                 LIMIT 1"
            );
            $stmt->execute([':pet' => $rec['pet_id'], ':id' => $rec['id'], ':d' => $rec['vaccination_date'],
                ':vt' => $rec['vaccination_type_id'], ':ci' => $rec['clinic_item_id'], ':inv' => $rec['inventory_id']]);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT id FROM deworming_records
                 WHERE pet_id = :pet AND id <> :id AND deleted_at IS NULL
                   AND deworming_date > :d
                   AND ( (deworming_type_id IS NOT NULL AND deworming_type_id = :dt)
                      OR (clinic_item_id IS NOT NULL AND clinic_item_id = :ci) )
                 LIMIT 1"
            );
            $stmt->execute([':pet' => $rec['pet_id'], ':id' => $rec['id'], ':d' => $rec['deworming_date'],
                ':dt' => $rec['deworming_type_id'], ':ci' => $rec['clinic_item_id']]);
        }
        return $stmt->fetchColumn() !== false;
    }

    // ─── Sending ─────────────────────────────────────────────────────────────
    private function sendMail($to, $name, $subject, $html, $text) {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to, $name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $html;
            $this->mailer->AltBody = $text;
            $this->mailer->send();
            error_log("ReminderService: \"$subject\" sent to $to");
            return ['success' => true, 'message' => 'Email sent successfully'];
        } catch (\Throwable $e) {
            error_log("ReminderService: Failed to send \"$subject\" to $to - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function formatDate($date) {
        return $date ? date('F j, Y', strtotime($date)) : '';
    }

    // ─── Templates ───────────────────────────────────────────────────────────
    private function emailStyles() {
        return "
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; background-color: #ffffff; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 40px auto; padding: 0; background-color: #ffffff; }
            .header { background-color: #ffffff; color: #2c3e50; padding: 40px 30px 20px 30px; text-align: center; border-bottom: 2px solid #e0e0e0; }
            .header h1 { margin: 0; font-size: 28px; font-weight: 600; color: #2c3e50; }
            .header p { margin: 8px 0 0 0; font-size: 14px; color: #7f8c8d; letter-spacing: 0.5px; }
            .content { background-color: #ffffff; padding: 40px 30px; }
            .content h2 { color: #2c3e50; font-size: 20px; font-weight: 600; margin: 0 0 20px 0; }
            .content p { margin: 15px 0; color: #555; font-size: 15px; }
            .details { width: 100%; border-collapse: collapse; margin: 25px 0; background-color: #f8f9fa; border: 2px solid #dee2e6; border-radius: 4px; }
            .details td { padding: 12px 18px; font-size: 15px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
            .details tr:last-child td { border-bottom: none; }
            .details td.label { color: #7f8c8d; width: 40%; }
            .details td.value { color: #2c3e50; font-weight: 600; }
            .next { background-color: #fff9e6; border-left: 4px solid #f39c12; padding: 20px; margin: 25px 0; }
            .next strong { color: #e67e22; display: block; margin-bottom: 6px; font-size: 15px; }
            .next .date { font-size: 22px; font-weight: 700; color: #2c3e50; }
            .footer { text-align: center; padding: 30px; border-top: 1px solid #e0e0e0; color: #95a5a6; font-size: 13px; }
        ";
    }

    private function wrapEmail($headerLabel, $inner) {
        $year = date('Y');
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>{$this->emailStyles()}</style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>PetUnity</h1>
                    <p>$headerLabel</p>
                </div>
                <div class='content'>
                    $inner
                    <p>Respectfully,<br><strong>The PetUnity Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; $year PetUnity. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>";
    }

    private function getConfirmationTemplate($d) {
        $isVac   = $d['kind'] === 'vaccination';
        $owner   = htmlspecialchars($d['owner'] ?: 'Pet Owner');
        $pet     = htmlspecialchars($d['pet']);
        $item    = htmlspecialchars($d['item']);
        $date    = $this->formatDate($d['date']);
        $by      = htmlspecialchars($d['by'] ?: 'City Vet Muntinlupa');
        $what    = $isVac ? 'vaccination' : 'deworming';
        $itemLbl = $isVac ? 'Vaccine' : 'Dewormer';

        $rows  = "<tr><td class='label'>Pet</td><td class='value'>$pet</td></tr>";
        $rows .= "<tr><td class='label'>$itemLbl</td><td class='value'>$item</td></tr>";
        if (!empty($d['dosage'])) {
            $rows .= "<tr><td class='label'>Dosage</td><td class='value'>" . htmlspecialchars($d['dosage']) . "</td></tr>";
        }
        $rows .= "<tr><td class='label'>Date given</td><td class='value'>$date</td></tr>";
        $rows .= "<tr><td class='label'>Given by</td><td class='value'>$by</td></tr>";
        if (!empty($d['vet'])) {
            $rows .= "<tr><td class='label'>Veterinarian</td><td class='value'>" . htmlspecialchars($d['vet']) . "</td></tr>";
        }

        $next = '';
        if (!empty($d['next'])) {
            $nextDate = $this->formatDate($d['next']);
            $next = "
                <div class='next'>
                    <strong>Next $what session</strong>
                    <div class='date'>$nextDate</div>
                    <p style='margin:8px 0 0 0;'>We'll also remind you by email 7 days and 1 day before this date.</p>
                </div>";
        }

        $inner = "
            <h2>Dear $owner,</h2>
            <p>A new $what record has been added for <strong>$pet</strong>. Here are the details:</p>
            <table class='details' role='presentation'>$rows</table>
            $next
            <p>You can view $pet's full records and vet card anytime in your PetUnity account.</p>";

        return $this->wrapEmail($isVac ? 'VACCINATION RECORD' : 'DEWORMING RECORD', $inner);
    }

    private function getConfirmationText($kind, $pet, $item, $date, $next, $by) {
        $label = $kind === 'vaccination' ? 'Vaccination' : 'Deworming';
        $text  = "$label record added for $pet.\n";
        $text .= ($kind === 'vaccination' ? 'Vaccine' : 'Dewormer') . ": $item\n";
        $text .= "Date given: " . $this->formatDate($date) . "\n";
        $text .= "Given by: $by\n";
        if (!empty($next)) {
            $text .= "Next $kind session: " . $this->formatDate($next) . "\n";
        }
        return $text;
    }

    private function getReminderTemplate($type, $rec, $item, $days_text) {  // $days_text: 'today' / 'tomorrow' / 'in N days'
        $isVac = $type === 'vaccination';
        $owner = htmlspecialchars(trim($rec['first_name'] . ' ' . $rec['last_name']) ?: 'Pet Owner');
        $pet   = htmlspecialchars($rec['pet_name']);
        $item  = htmlspecialchars($item);
        $due   = $this->formatDate($rec['next_due_date']);
        $what  = $isVac ? 'vaccination' : 'deworming';

        $inner = "
            <h2>Dear $owner,</h2>
            <p>This is a reminder that <strong>$pet</strong>'s next $what is due <strong>$days_text</strong>.</p>
            <div class='next'>
                <strong>Next $what session</strong>
                <div class='date'>$due</div>
                <p style='margin:8px 0 0 0;'>" . ($isVac ? 'Vaccine' : 'Dewormer') . ": $item</p>
            </div>
            <p>Please visit " . htmlspecialchars($rec['recorded_by'] ?: 'City Vet Muntinlupa') . " or your preferred clinic on or before this date.</p>";

        return $this->wrapEmail($isVac ? 'VACCINATION REMINDER' : 'DEWORMING REMINDER', $inner);
    }
}
?>