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
            error_log("ReminderService: Constructor started");
            
            $database = new Database();
            $this->conn = $database->getConnection();
            
            error_log("ReminderService: Database connected");
            
            $this->loadEmailConfig();
            error_log("ReminderService: Email config loaded");
            
            $this->setupMailer();
            error_log("ReminderService: Mailer setup complete");
            
            $this->createReminderLogsTable();
            
        } catch (Exception $e) {
            error_log("ReminderService: Constructor error - " . $e->getMessage());
            throw $e;
        }
    }
    
    private function loadEmailConfig() {
        $configFile = __DIR__ . '/../config/email_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception('Email configuration file not found');
        }
        
        $this->emailConfig = require $configFile;
        
        if (empty($this->emailConfig['smtp']['username']) || 
            empty($this->emailConfig['smtp']['password'])) {
            throw new Exception('Email configuration is incomplete');
        }
    }
    
    private function setupMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->emailConfig['smtp']['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->emailConfig['smtp']['username'];
            $this->mailer->Password = $this->emailConfig['smtp']['password'];
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $this->emailConfig['smtp']['port'];
            $this->mailer->SMTPDebug = 0;
            $this->mailer->Debugoutput = function($str, $level) {
                error_log("PHPMailer: $str");
            };
            
            $this->mailer->setFrom(
                $this->emailConfig['from']['email'], 
                $this->emailConfig['from']['name']
            );
            
        } catch (Exception $e) {
            throw new Exception("Mailer setup failed: " . $e->getMessage());
        }
    }
    
    private function createReminderLogsTable() {
        $query = "CREATE TABLE IF NOT EXISTS reminder_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->conn->exec($query);
    }
    
    private function hasReminderBeenSent($record_id, $reminder_type, $reminder_period) {
        $query = "SELECT id FROM reminder_logs 
                  WHERE record_id = :record_id 
                  AND reminder_type = :reminder_type 
                  AND reminder_period = :reminder_period
                  AND status = 'sent'
                  LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':record_id' => $record_id,
            ':reminder_type' => $reminder_type,
            ':reminder_period' => $reminder_period
        ]);
        
        return $stmt->rowCount() > 0;
    }
    
    private function logReminder($pet_id, $owner_email, $reminder_type, $record_id, $reminder_period, $due_date, $status = 'sent', $error = null) {
        $query = "INSERT INTO reminder_logs 
                  (pet_id, owner_email, reminder_type, record_id, reminder_period, due_date, status, error_message)
                  VALUES (:pet_id, :owner_email, :reminder_type, :record_id, :reminder_period, :due_date, :status, :error)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([
            ':pet_id' => $pet_id,
            ':owner_email' => $owner_email,
            ':reminder_type' => $reminder_type,
            ':record_id' => $record_id,
            ':reminder_period' => $reminder_period,
            ':due_date' => $due_date,
            ':status' => $status,
            ':error' => $error
        ]);
    }
    
    // ✅ NEW: Send immediate confirmation when vaccination is added
    public function sendVaccinationConfirmation($pet_id, $vaccination_id) {
        try {
            error_log("ReminderService: Sending vaccination confirmation for ID $vaccination_id");
            
            // Get vaccination details with owner info
            $query = "SELECT vr.*, p.name as pet_name, p.species, p.breed,
                     vt.name as vaccine_name, vt.description as vaccine_description,
                     po.first_name, po.last_name, u.email as owner_email
                     FROM vaccination_records vr
                     JOIN pets p ON vr.pet_id = p.id
                     JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                     JOIN pet_owners po ON p.owner_id = po.id
                     JOIN users u ON po.user_id = u.id
                     WHERE vr.id = :vaccination_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':vaccination_id' => $vaccination_id]);
            $vaccination = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$vaccination || empty($vaccination['owner_email'])) {
                error_log("ReminderService: No owner email found for vaccination ID $vaccination_id");
                return ['success' => false, 'message' => 'No owner email found'];
            }
            
            // Check if confirmation already sent
            if ($this->hasReminderBeenSent($vaccination_id, 'vaccination', 'confirmation')) {
                error_log("ReminderService: Confirmation already sent for vaccination ID $vaccination_id");
                return ['success' => true, 'message' => 'Confirmation already sent'];
            }
            
            // Send confirmation email
            $result = $this->sendVaccinationConfirmationEmail($vaccination);
            
            if ($result['success']) {
                $due_date = $vaccination['next_due_date'] ?? date('Y-m-d');
                $this->logReminder(
                    $vaccination['pet_id'],
                    $vaccination['owner_email'],
                    'vaccination',
                    $vaccination_id,
                    'confirmation',
                    $due_date,
                    'sent'
                );
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("ReminderService: Error sending vaccination confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ✅ NEW: Send immediate confirmation when deworming is added
    public function sendDewormingConfirmation($pet_id, $deworming_id) {
        try {
            error_log("ReminderService: Sending deworming confirmation for ID $deworming_id");
            
            // Get deworming details with owner info
            $query = "SELECT dr.*, p.name as pet_name, p.species, p.breed,
                     dt.name as deworming_name, dt.description as deworming_description,
                     dt.target_parasites,
                     po.first_name, po.last_name, u.email as owner_email
                     FROM deworming_records dr
                     JOIN pets p ON dr.pet_id = p.id
                     JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                     JOIN pet_owners po ON p.owner_id = po.id
                     JOIN users u ON po.user_id = u.id
                     WHERE dr.id = :deworming_id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([':deworming_id' => $deworming_id]);
            $deworming = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$deworming || empty($deworming['owner_email'])) {
                error_log("ReminderService: No owner email found for deworming ID $deworming_id");
                return ['success' => false, 'message' => 'No owner email found'];
            }
            
            // Check if confirmation already sent
            if ($this->hasReminderBeenSent($deworming_id, 'deworming', 'confirmation')) {
                error_log("ReminderService: Confirmation already sent for deworming ID $deworming_id");
                return ['success' => true, 'message' => 'Confirmation already sent'];
            }
            
            // Send confirmation email
            $result = $this->sendDewormingConfirmationEmail($deworming);
            
            if ($result['success']) {
                $due_date = $deworming['next_due_date'] ?? date('Y-m-d');
                $this->logReminder(
                    $deworming['pet_id'],
                    $deworming['owner_email'],
                    'deworming',
                    $deworming_id,
                    'confirmation',
                    $due_date,
                    'sent'
                );
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("ReminderService: Error sending deworming confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function sendVaccinationReminders() {
        try {
            error_log("ReminderService: Starting vaccination reminders check");
            
            $seven_days = date('Y-m-d', strtotime('+7 days'));
            $one_day = date('Y-m-d', strtotime('+1 day'));
            
            $query = "SELECT vr.id, vr.pet_id, vr.next_due_date, vr.vaccination_date,
                      p.name as pet_name, p.species, p.breed,
                      vt.name as vaccine_name, vt.description as vaccine_description,
                      po.first_name, po.last_name, u.email as owner_email
                      FROM vaccination_records vr
                      JOIN pets p ON vr.pet_id = p.id
                      JOIN vaccination_types vt ON vr.vaccination_type_id = vt.id
                      JOIN pet_owners po ON p.owner_id = po.id
                      JOIN users u ON po.user_id = u.id
                      WHERE vr.next_due_date IS NOT NULL
                      AND (vr.next_due_date = :seven_days OR vr.next_due_date = :one_day)
                      AND p.is_active = 1
                      AND u.email IS NOT NULL";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':seven_days' => $seven_days,
                ':one_day' => $one_day
            ]);
            
            $vaccinations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sent_count = 0;
            $failed_count = 0;
            
            foreach ($vaccinations as $vaccination) {
                $days_until_due = (strtotime($vaccination['next_due_date']) - strtotime(date('Y-m-d'))) / 86400;
                $reminder_period = $days_until_due <= 1 ? '1_day' : '7_days';
                
                if ($this->hasReminderBeenSent($vaccination['id'], 'vaccination', $reminder_period)) {
                    error_log("ReminderService: Reminder already sent for vaccination ID {$vaccination['id']} ({$reminder_period})");
                    continue;
                }
                
                $result = $this->sendVaccinationReminderEmail($vaccination, $reminder_period);
                
                if ($result['success']) {
                    $this->logReminder(
                        $vaccination['pet_id'],
                        $vaccination['owner_email'],
                        'vaccination',
                        $vaccination['id'],
                        $reminder_period,
                        $vaccination['next_due_date'],
                        'sent'
                    );
                    $sent_count++;
                } else {
                    $this->logReminder(
                        $vaccination['pet_id'],
                        $vaccination['owner_email'],
                        'vaccination',
                        $vaccination['id'],
                        $reminder_period,
                        $vaccination['next_due_date'],
                        'failed',
                        $result['message']
                    );
                    $failed_count++;
                }
            }
            
            error_log("ReminderService: Vaccination reminders completed - Sent: $sent_count, Failed: $failed_count");
            
            return [
                'success' => true,
                'sent' => $sent_count,
                'failed' => $failed_count
            ];
            
        } catch (Exception $e) {
            error_log("ReminderService: Error sending vaccination reminders - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function sendDewormingReminders() {
        try {
            error_log("ReminderService: Starting deworming reminders check");
            
            $seven_days = date('Y-m-d', strtotime('+7 days'));
            $one_day = date('Y-m-d', strtotime('+1 day'));
            
            $query = "SELECT dr.id, dr.pet_id, dr.next_due_date, dr.deworming_date,
                      p.name as pet_name, p.species, p.breed,
                      dt.name as deworming_name, dt.description as deworming_description,
                      dt.target_parasites,
                      po.first_name, po.last_name, u.email as owner_email
                      FROM deworming_records dr
                      JOIN pets p ON dr.pet_id = p.id
                      JOIN deworming_types dt ON dr.deworming_type_id = dt.id
                      JOIN pet_owners po ON p.owner_id = po.id
                      JOIN users u ON po.user_id = u.id
                      WHERE dr.next_due_date IS NOT NULL
                      AND (dr.next_due_date = :seven_days OR dr.next_due_date = :one_day)
                      AND p.is_active = 1
                      AND u.email IS NOT NULL";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                ':seven_days' => $seven_days,
                ':one_day' => $one_day
            ]);
            
            $dewormings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sent_count = 0;
            $failed_count = 0;
            
            foreach ($dewormings as $deworming) {
                $days_until_due = (strtotime($deworming['next_due_date']) - strtotime(date('Y-m-d'))) / 86400;
                $reminder_period = $days_until_due <= 1 ? '1_day' : '7_days';
                
                if ($this->hasReminderBeenSent($deworming['id'], 'deworming', $reminder_period)) {
                    error_log("ReminderService: Reminder already sent for deworming ID {$deworming['id']} ({$reminder_period})");
                    continue;
                }
                
                $result = $this->sendDewormingReminderEmail($deworming, $reminder_period);
                
                if ($result['success']) {
                    $this->logReminder(
                        $deworming['pet_id'],
                        $deworming['owner_email'],
                        'deworming',
                        $deworming['id'],
                        $reminder_period,
                        $deworming['next_due_date'],
                        'sent'
                    );
                    $sent_count++;
                } else {
                    $this->logReminder(
                        $deworming['pet_id'],
                        $deworming['owner_email'],
                        'deworming',
                        $deworming['id'],
                        $reminder_period,
                        $deworming['next_due_date'],
                        'failed',
                        $result['message']
                    );
                    $failed_count++;
                }
            }
            
            error_log("ReminderService: Deworming reminders completed - Sent: $sent_count, Failed: $failed_count");
            
            return [
                'success' => true,
                'sent' => $sent_count,
                'failed' => $failed_count
            ];
            
        } catch (Exception $e) {
            error_log("ReminderService: Error sending deworming reminders - " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ✅ NEW: Confirmation email template
    private function sendVaccinationConfirmationEmail($vaccination) {
        try {
            $owner_name = $vaccination['first_name'] . ' ' . $vaccination['last_name'];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($vaccination['owner_email'], $owner_name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = "Vaccination Record Added for {$vaccination['pet_name']} - PetUnity";
            
            $this->mailer->Body = $this->getVaccinationConfirmationTemplate($vaccination);
            $this->mailer->AltBody = "Vaccination record added for {$vaccination['pet_name']} on {$vaccination['vaccination_date']}.";
            
            $this->mailer->send();
            
            error_log("ReminderService: Vaccination confirmation sent to {$vaccination['owner_email']}");
            
            return ['success' => true, 'message' => 'Confirmation sent successfully'];
            
        } catch (Exception $e) {
            error_log("ReminderService: Failed to send vaccination confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ✅ NEW: Confirmation email template
    private function sendDewormingConfirmationEmail($deworming) {
        try {
            $owner_name = $deworming['first_name'] . ' ' . $deworming['last_name'];
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($deworming['owner_email'], $owner_name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = "Deworming Record Added for {$deworming['pet_name']} - PetUnity";
            
            $this->mailer->Body = $this->getDewormingConfirmationTemplate($deworming);
            $this->mailer->AltBody = "Deworming record added for {$deworming['pet_name']} on {$deworming['deworming_date']}.";
            
            $this->mailer->send();
            
            error_log("ReminderService: Deworming confirmation sent to {$deworming['owner_email']}");
            
            return ['success' => true, 'message' => 'Confirmation sent successfully'];
            
        } catch (Exception $e) {
            error_log("ReminderService: Failed to send deworming confirmation - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function sendVaccinationReminderEmail($vaccination, $reminder_period) {
        try {
            $owner_name = $vaccination['first_name'] . ' ' . $vaccination['last_name'];
            $days_text = $reminder_period === '1_day' ? '1 day' : '7 days';
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($vaccination['owner_email'], $owner_name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = "Vaccination Reminder for {$vaccination['pet_name']} - PetUnity";
            
            $this->mailer->Body = $this->getVaccinationReminderTemplate($vaccination, $days_text);
            $this->mailer->AltBody = "Reminder: {$vaccination['pet_name']}'s {$vaccination['vaccine_name']} vaccination is due in $days_text on {$vaccination['next_due_date']}.";
            
            $this->mailer->send();
            
            error_log("ReminderService: Vaccination reminder sent to {$vaccination['owner_email']}");
            
            return ['success' => true, 'message' => 'Reminder sent successfully'];
            
        } catch (Exception $e) {
            error_log("ReminderService: Failed to send vaccination reminder - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    private function sendDewormingReminderEmail($deworming, $reminder_period) {
        try {
            $owner_name = $deworming['first_name'] . ' ' . $deworming['last_name'];
            $days_text = $reminder_period === '1_day' ? '1 day' : '7 days';
            
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($deworming['owner_email'], $owner_name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = "Deworming Reminder for {$deworming['pet_name']} - PetUnity";
            
            $this->mailer->Body = $this->getDewormingReminderTemplate($deworming, $days_text);
            $this->mailer->AltBody = "Reminder: {$deworming['pet_name']}'s {$deworming['deworming_name']} deworming is due in $days_text on {$deworming['next_due_date']}.";
            
            $this->mailer->send();
            
            error_log("ReminderService: Deworming reminder sent to {$deworming['owner_email']}");
            
            return ['success' => true, 'message' => 'Reminder sent successfully'];
            
        } catch (Exception $e) {
            error_log("ReminderService: Failed to send deworming reminder - " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ✅ NEW: Confirmation email template
    private function getVaccinationReminderTemplate($vaccination, $days_text) {
        $owner_name = htmlspecialchars($vaccination['first_name'] . ' ' . $vaccination['last_name']);
        $pet_name = htmlspecialchars($vaccination['pet_name']);
        $vaccine_name = htmlspecialchars($vaccination['vaccine_name']);
        $next_due_date = date('F j, Y', strtotime($vaccination['next_due_date']));
        
        $urgency_color = $days_text === '1 day' ? '#dc3545' : '#ffc107';
        $urgency_bg = $days_text === '1 day' ? '#fff5f5' : '#fffbf0';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; }
                .header { background-color: #28a745; color: white; padding: 40px 30px 20px 30px; text-align: center; }
                .urgent-badge { background-color: $urgency_color; color: white; padding: 8px 20px; border-radius: 20px; display: inline-block; font-weight: 700; margin-top: 10px; }
                .content { padding: 40px 30px; }
                .urgent-reminder { background-color: $urgency_bg; border-left: 4px solid $urgency_color; padding: 20px; margin: 25px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏰ PetUnity - Vaccination Reminder</h1>
                    <div class='urgent-badge'>DUE IN $days_text</div>
                </div>
                <div class='content'>
                    <h2>Dear $owner_name,</h2>
                    <p>This is a reminder that <strong>$pet_name</strong>'s vaccination is coming up!</p>
                    <div class='urgent-reminder'>
                        <strong>📅 Vaccination Due Date: $next_due_date</strong>
                        <p>Vaccine: $vaccine_name</p>
                        <p>Only $days_text remaining!</p>
                    </div>
                    <p>Please schedule an appointment as soon as possible.</p>
                    <p>Best regards,<br><strong>The PetUnity Team</strong></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private function getDewormingReminderTemplate($deworming, $days_text) {
        $owner_name = htmlspecialchars($deworming['first_name'] . ' ' . $deworming['last_name']);
        $pet_name = htmlspecialchars($deworming['pet_name']);
        $deworming_name = htmlspecialchars($deworming['deworming_name']);
        $next_due_date = date('F j, Y', strtotime($deworming['next_due_date']));
        
        $urgency_color = $days_text === '1 day' ? '#dc3545' : '#ffc107';
        $urgency_bg = $days_text === '1 day' ? '#fff5f5' : '#fffbf0';
        
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 40px auto; background-color: #ffffff; }
                .header { background-color: #17a2b8; color: white; padding: 40px 30px 20px 30px; text-align: center; }
                .urgent-badge { background-color: $urgency_color; color: white; padding: 8px 20px; border-radius: 20px; display: inline-block; font-weight: 700; margin-top: 10px; }
                .content { padding: 40px 30px; }
                .urgent-reminder { background-color: $urgency_bg; border-left: 4px solid $urgency_color; padding: 20px; margin: 25px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏰ PetUnity - Deworming Reminder</h1>
                    <div class='urgent-badge'>DUE IN $days_text</div>
                </div>
                <div class='content'>
                    <h2>Dear $owner_name,</h2>
                    <p>This is a reminder that <strong>$pet_name</strong>'s deworming treatment is coming up!</p>
                    <div class='urgent-reminder'>
                        <strong>📅 Deworming Due Date: $next_due_date</strong>
                        <p>Treatment: $deworming_name</p>
                        <p>Only $days_text remaining!</p>
                    </div>
                    <p>Regular deworming is essential for your pet's health.</p>
                    <p>Best regards,<br><strong>The PetUnity Team</strong></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
?>