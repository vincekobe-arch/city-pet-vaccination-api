<?php
// services/EventNotificationService.php
// Emails the community about events:
//   • new event       → every active community (pet owner) account
//   • rescheduled     → people registered in that event
//   • cancelled       → people registered in that event

require_once __DIR__ . '/../PHPMailer-7.0.0/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../config/database.php';

class EventNotificationService {
    private $conn;
    private $mailer;
    private $emailConfig;

    // How many people per email when announcing a new event (they are put in BCC,
    // so nobody sees anyone else's address)
    const BCC_BATCH = 50;

    const TABLES = [
        'vaccination'   => 'vaccination_schedules',
        'deworming'     => 'deworming_schedules',
        'seminar'       => 'seminar_schedules',
        'sterilization' => 'sterilization_schedules',
        'microchip'     => 'microchip_schedules',
        'other'         => 'other_schedules',
    ];

    const LABELS = [
        'vaccination'   => 'Vaccination Drive',
        'deworming'     => 'Deworming Drive',
        'seminar'       => 'Seminar',
        'sterilization' => 'Sterilization (Kapon) Drive',
        'microchip'     => 'Microchipping Drive',
        'other'         => 'Event',
    ];

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();

        $configFile = __DIR__ . '/../config/email_config.php';
        if (!file_exists($configFile)) throw new \Exception('Email configuration file not found');
        $this->emailConfig = require $configFile;

        $this->mailer = new PHPMailer(true);
        $this->mailer->isSMTP();
        $this->mailer->Host       = $this->emailConfig['smtp']['host'];
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = $this->emailConfig['smtp']['username'];
        $this->mailer->Password   = $this->emailConfig['smtp']['password'];
        $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port       = $this->emailConfig['smtp']['port'];
        $this->mailer->CharSet    = 'UTF-8';
        $this->mailer->SMTPKeepAlive = true;   // one connection for many emails
        $this->mailer->setFrom($this->emailConfig['from']['email'], $this->emailConfig['from']['name']);
    }

    public function __destruct() {
        try { if ($this->mailer) $this->mailer->smtpClose(); } catch (\Throwable $e) {}
    }

    // ─── Send the API response now, keep running to send emails ──────────────
    // So the admin doesn't wait for hundreds of emails to go out.
    public static function finishResponse() {
        ignore_user_abort(true);
        @set_time_limit(0);
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
        if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
        while (ob_get_level() > 0) { @ob_end_flush(); }
        @flush();
    }

    // ─── Lookups (call these BEFORE deleting an event) ────────────────────────
    public function getEvent($type, $id) {
        if (!isset(self::TABLES[$type])) return null;
        $table = self::TABLES[$type];
        $stmt = $this->conn->prepare(
            "SELECT s.*, b.name AS barangay_name
             FROM `$table` s
             LEFT JOIN barangays b ON b.id = s.barangay_id
             WHERE s.id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) $row['_type'] = $type;
        return $row ?: null;
    }

    public function getRegistrants($type, $id) {
        $stmt = $this->conn->prepare(
            "SELECT u.email, MAX(u.first_name) AS first_name, MAX(u.last_name) AS last_name
             FROM schedule_registrations sr
             JOIN users u ON u.id = sr.user_id
             WHERE sr.schedule_id = :id AND sr.schedule_type = :type
               AND sr.status <> 'cancelled'
               AND u.is_active = 1 AND u.email IS NOT NULL AND u.email <> ''
             GROUP BY u.email"
        );
        $stmt->execute([':id' => $id, ':type' => $type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── 1. New event → all community accounts ───────────────────────────────
    public function notifyNewEvent($type, $id) {
        try {
            $event = $this->getEvent($type, $id);
            if (!$event) return ['success' => false, 'message' => 'Event not found'];

            $stmt = $this->conn->query(
                "SELECT DISTINCT email FROM users
                 WHERE role = 'pet_owner' AND is_active = 1
                   AND email IS NOT NULL AND email <> ''"
            );
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $label   = $this->eventLabel($event);
            $subject = "Upcoming $label: {$event['title']} on " . $this->fmtDate($event['scheduled_date']) . " - PetUnity";
            $inner   = "
                <h2>Dear PetUnity Community,</h2>
                <p>A new <strong>" . htmlspecialchars($label) . "</strong> has been scheduled. Here are the details:</p>
                {$this->detailsTable($event)}
                <p>Log in to your PetUnity account to see the full details and register.</p>";
            $html = $this->wrapEmail('UPCOMING EVENT', $inner);
            $text = "Upcoming $label: {$event['title']}\n" . $this->detailsText($event) .
                    "\nLog in to your PetUnity account to see the full details and register.";

            $sent = 0; $failed = 0;
            foreach (array_chunk($emails, self::BCC_BATCH) as $batch) {
                $ok = $this->send([], $batch, $subject, $html, $text);
                $ok ? $sent += count($batch) : $failed += count($batch);
            }
            error_log("EventNotification: new $type #$id — sent to $sent, failed $failed");
            return ['success' => true, 'sent' => $sent, 'failed' => $failed];

        } catch (\Throwable $e) {
            error_log("EventNotification: new event error - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── 2. Rescheduled → people registered in the event ─────────────────────
    // $old = the event row from BEFORE the update
    public function notifyRescheduled($type, $id, $old) {
        try {
            $event = $this->getEvent($type, $id);
            if (!$event) return ['success' => false, 'message' => 'Event not found'];

            $label   = $this->eventLabel($event);
            $subject = "Rescheduled: {$event['title']} - PetUnity";
            $before  = $this->fmtDate($old['scheduled_date']) . ', ' . $this->fmtTimeRange($old) .
                       ' · ' . $this->place($old + ['barangay_name' => $event['barangay_name']]);

            return $this->sendToEach($this->getRegistrants($type, $id), $subject, function ($name) use ($event, $label, $before) {
                $inner = "
                    <h2>Dear " . htmlspecialchars($name) . ",</h2>
                    <p>The <strong>" . htmlspecialchars($label) . "</strong> you registered for has been <strong>rescheduled</strong>. Please take note of the new schedule:</p>
                    {$this->detailsTable($event)}
                    <div class='note'><strong>Previous schedule</strong>" . htmlspecialchars($before) . "</div>
                    <p>Your registration stays the same — no need to register again. If you can no longer attend, you can cancel your registration in your PetUnity account.</p>";
                return [
                    $this->wrapEmail('EVENT RESCHEDULED', $inner),
                    "The event {$event['title']} has been rescheduled.\nNew schedule:\n" . $this->detailsText($event) . "\nPrevious: $before",
                ];
            });

        } catch (\Throwable $e) {
            error_log("EventNotification: rescheduled error - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── 3. Cancelled / deleted → people registered in the event ─────────────
    // $event and $registrants must be read BEFORE the event is deleted
    public function notifyCancelled($event, $registrants) {
        try {
            if (!$event || empty($registrants)) return ['success' => true, 'sent' => 0, 'failed' => 0];

            $label   = $this->eventLabel($event);
            $subject = "Cancelled: {$event['title']} - PetUnity";

            return $this->sendToEach($registrants, $subject, function ($name) use ($event, $label) {
                $inner = "
                    <h2>Dear " . htmlspecialchars($name) . ",</h2>
                    <p>We're sorry to inform you that the <strong>" . htmlspecialchars($label) . "</strong> you registered for has been <strong>cancelled</strong>.</p>
                    {$this->detailsTable($event, true)}
                    <p>Your registration for this event has been removed. Watch your email and your PetUnity account for upcoming events.</p>
                    <p>We apologize for the inconvenience.</p>";
                return [
                    $this->wrapEmail('EVENT CANCELLED', $inner),
                    "The event {$event['title']} has been cancelled.\n" . $this->detailsText($event),
                ];
            });

        } catch (\Throwable $e) {
            error_log("EventNotification: cancelled error - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─── Sending helpers ─────────────────────────────────────────────────────
    private function sendToEach($people, $subject, $build) {
        $sent = 0; $failed = 0;
        foreach ($people as $p) {
            $name = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?: 'Pet Owner';
            [$html, $text] = $build($name);
            $this->send([[$p['email'], $name]], [], $subject, $html, $text) ? $sent++ : $failed++;
        }
        error_log("EventNotification: \"$subject\" — sent $sent, failed $failed");
        return ['success' => true, 'sent' => $sent, 'failed' => $failed];
    }

    private function send($to, $bcc, $subject, $html, $text) {
        try {
            $this->mailer->clearAllRecipients();
            if (empty($to)) {
                // Broadcast: address it to ourselves, everyone else in BCC
                $this->mailer->addAddress($this->emailConfig['from']['email'], $this->emailConfig['from']['name']);
            }
            foreach ($to as [$email, $name]) $this->mailer->addAddress($email, $name);
            foreach ($bcc as $email) $this->mailer->addBCC($email);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $html;
            $this->mailer->AltBody = $text;
            $this->mailer->send();
            return true;
        } catch (\Throwable $e) {
            error_log("EventNotification: send failed (\"$subject\") - " . $e->getMessage());
            return false;
        }
    }

    // ─── Formatting ──────────────────────────────────────────────────────────
    private function eventLabel($event) {
        $type = $event['_type'] ?? 'other';
        if ($type === 'other' && !empty($event['other_event_type'])) return $event['other_event_type'];
        return self::LABELS[$type] ?? 'Event';
    }

    private function fmtDate($d)  { return $d ? date('l, F j, Y', strtotime($d)) : ''; }
    private function fmtTime($t)  { return $t ? date('g:i A', strtotime($t)) : ''; }
    private function fmtTimeRange($e) {
        return trim($this->fmtTime($e['start_time'] ?? null) . ' – ' . $this->fmtTime($e['end_time'] ?? null), ' –');
    }

    private function place($e) {
        $parts = [];
        if (!empty($e['venue'])) $parts[] = $e['venue'];
        if (!empty($e['custom_place'])) $parts[] = $e['custom_place'];
        elseif (!empty($e['barangay_name'])) $parts[] = 'Brgy. ' . $e['barangay_name'];
        $parts[] = 'Muntinlupa City';
        return implode(', ', array_unique($parts));
    }

    private function detailsTable($e, $struck = false) {
        $rows = [
            'Event' => $e['title'],
            'Type'  => $this->eventLabel($e),
            'Date'  => $this->fmtDate($e['scheduled_date']),
            'Time'  => $this->fmtTimeRange($e),
            'Where' => $this->place($e),
        ];
        if (!empty($e['speaker'])) $rows['Speaker'] = $e['speaker'];
        if (!empty($e['max_capacity'])) $rows['Slots'] = $e['max_capacity'] . ' only';
        if (!empty($e['description'])) $rows['Details'] = $e['description'];
        if ($struck) unset($rows['Slots']);

        $html = '';
        foreach ($rows as $k => $v) {
            $val = nl2br(htmlspecialchars((string) $v));
            if ($struck && in_array($k, ['Date', 'Time'])) $val = "<s>$val</s>";
            $html .= "<tr><td class='label'>$k</td><td class='value'>$val</td></tr>";
        }
        return "<table class='details' role='presentation'>$html</table>";
    }

    private function detailsText($e) {
        return "Date: " . $this->fmtDate($e['scheduled_date']) . "\n" .
               "Time: " . $this->fmtTimeRange($e) . "\n" .
               "Where: " . $this->place($e) . "\n";
    }

    private function wrapEmail($headerLabel, $inner) {
        $year = date('Y');
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; background-color: #ffffff; margin: 0; padding: 0; }
                .container { max-width: 600px; margin: 40px auto; padding: 0; background-color: #ffffff; }
                .header { background-color: #ffffff; color: #2c3e50; padding: 40px 30px 20px 30px; text-align: center; border-bottom: 2px solid #e0e0e0; }
                .header h1 { margin: 0; font-size: 28px; font-weight: 600; color: #2c3e50; }
                .header p { margin: 8px 0 0 0; font-size: 14px; color: #7f8c8d; letter-spacing: 0.5px; }
                .content { background-color: #ffffff; padding: 40px 30px; }
                .content h2 { color: #2c3e50; font-size: 20px; font-weight: 600; margin: 0 0 20px 0; }
                .content p { margin: 15px 0; color: #555; font-size: 15px; }
                .details { width: 100%; border-collapse: collapse; margin: 25px 0; background-color: #f8f9fa; border: 2px solid #dee2e6; }
                .details td { padding: 12px 18px; font-size: 15px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
                .details tr:last-child td { border-bottom: none; }
                .details td.label { color: #7f8c8d; width: 30%; }
                .details td.value { color: #2c3e50; font-weight: 600; }
                .note { background-color: #fff9e6; border-left: 4px solid #f39c12; padding: 16px 20px; margin: 25px 0; color: #555; font-size: 15px; }
                .note strong { color: #e67e22; display: block; margin-bottom: 4px; }
                .footer { text-align: center; padding: 30px; border-top: 1px solid #e0e0e0; color: #95a5a6; font-size: 13px; }
            </style>
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
}
?>