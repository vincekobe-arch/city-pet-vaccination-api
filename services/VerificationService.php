<?php

require_once __DIR__ . '/../PHPMailer-7.0.0/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-7.0.0/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/database.php';

class VerificationService {
    private $conn;
    private $mailer;
    private $emailConfig;
    
    public function __construct() {
        try {
            error_log("VerificationService: Constructor started");
            
            $database = new Database();
            $this->conn = $database->getConnection();
            
            error_log("VerificationService: Database connected");
            
            $this->loadEmailConfig();
            error_log("VerificationService: Email config loaded");
            
            $this->setupMailer();
            error_log("VerificationService: Mailer setup complete");
            
            // Don't create table - assume it already exists
            error_log("VerificationService: Verification table ready");
            
        } catch (Exception $e) {
            error_log("VerificationService: Constructor error - " . $e->getMessage());
            throw $e;
        }
    }
    
    private function loadEmailConfig() {
        $configFile = __DIR__ . '/../config/email_config.php';
        
        error_log("VerificationService: Looking for config at: $configFile");
        
        if (!file_exists($configFile)) {
            error_log("VerificationService: Config file not found!");
            throw new Exception('Email configuration file not found at: ' . $configFile);
        }
        
        $this->emailConfig = require $configFile;
        
        // Validate required configuration
        if (empty($this->emailConfig['smtp']['username']) || 
            empty($this->emailConfig['smtp']['password'])) {
            throw new Exception('Email configuration is incomplete. Check email_config.php');
        }
        
        error_log("VerificationService: Config loaded - Username: " . $this->emailConfig['smtp']['username']);
    }
    
    private function setupMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->emailConfig['smtp']['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->emailConfig['smtp']['username'];
            $this->mailer->Password = $this->emailConfig['smtp']['password'];
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $this->emailConfig['smtp']['port'];
            
            // Enable verbose debug output (for testing)
            $this->mailer->SMTPDebug = 0; // Set to 2 for debugging
            $this->mailer->Debugoutput = function($str, $level) {
                error_log("PHPMailer: $str");
            };
            
            // Sender info
            $this->mailer->setFrom(
                $this->emailConfig['from']['email'], 
                $this->emailConfig['from']['name']
            );
            
            error_log("VerificationService: Mailer configured successfully");
            
        } catch (Exception $e) {
            error_log("VerificationService: Mailer setup error - " . $e->getMessage());
            throw new Exception("Mailer setup failed: " . $e->getMessage());
        }
    }
    
    public function generateVerificationCode() {
        return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    
    // Send verification email with pre-generated code
    public function sendVerificationEmailWithCode($email, $name, $code) {
        try {
            error_log("VerificationService: Sending pre-generated code to $email");
            
            // Prepare email
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($email, $name);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Email Verification Code - PetUnity';
            
            // Email body
            $this->mailer->Body = $this->getEmailTemplate($code, $name);
            $this->mailer->AltBody = "Your verification code is: $code\n\nThis code will expire in 15 minutes.";
            
            // Send email
            $this->mailer->send();
            
            error_log("VerificationService: Email sent successfully to $email");
            
            return [
                'success' => true,
                'message' => 'Verification code sent successfully'
            ];
            
        } catch (Exception $e) {
            error_log("VerificationService: Email send error - " . $e->getMessage());
            error_log("VerificationService: Error trace - " . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'Failed to send verification code: ' . $e->getMessage()
            ];
        }
    }
    
    public function sendVerificationEmail($email, $name = '') {
        try {
            error_log("VerificationService: Starting to send email to $email");
            
            // Delete old verification codes for this email
            $delete_query = "DELETE FROM email_verifications WHERE email = ? AND is_verified = 0";
            $delete_stmt = $this->conn->prepare($delete_query);
            $delete_stmt->execute([$email]);
            
            error_log("VerificationService: Old codes deleted");
            
            // Generate new verification code
            $code = $this->generateVerificationCode();
            $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            error_log("VerificationService: Generated code: $code");
            
            // Save to database
            $insert_query = "INSERT INTO email_verifications (email, verification_code, expires_at) 
                            VALUES (?, ?, ?)";
            $insert_stmt = $this->conn->prepare($insert_query);
            $insert_stmt->execute([$email, $code, $expires_at]);
            
            error_log("VerificationService: Code saved to database");
            
            // Send email
            return $this->sendVerificationEmailWithCode($email, $name, $code);
            
        } catch (Exception $e) {
            error_log("VerificationService: Email send error - " . $e->getMessage());
            error_log("VerificationService: Full trace - " . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'Failed to send verification code: ' . $e->getMessage()
            ];
        }
    }
    
    public function verifyCode($email, $code) {
        try {
            error_log("VerificationService: Verifying code $code for $email");
            
            $query = "SELECT * FROM email_verifications 
                     WHERE email = ? 
                     AND verification_code = ? 
                     AND is_verified = 0 
                     AND expires_at > NOW()
                     ORDER BY created_at DESC 
                     LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$email, $code]);
            
            if ($stmt->rowCount() > 0) {
                // Mark as verified
                $update_query = "UPDATE email_verifications 
                                SET is_verified = 1 
                                WHERE email = ? AND verification_code = ?";
                $update_stmt = $this->conn->prepare($update_query);
                $update_stmt->execute([$email, $code]);
                
                error_log("VerificationService: Code verified successfully");
                
                return [
                    'success' => true,
                    'message' => 'Email verified successfully'
                ];
            } else {
                error_log("VerificationService: Invalid or expired code");
                
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification code'
                ];
            }
        } catch (Exception $e) {
            error_log("VerificationService: Verify error - " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ];
        }
    }
    
    public function resendVerificationCode($email) {
        // Check if user hasn't requested too many codes (rate limiting)
        $check_query = "SELECT COUNT(*) as count FROM email_verifications 
                       WHERE email = ? 
                       AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $check_stmt = $this->conn->prepare($check_query);
        $check_stmt->execute([$email]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] >= 5) {
            return [
                'success' => false,
                'message' => 'Too many requests. Please try again later.'
            ];
        }
        
        return $this->sendVerificationEmail($email);
    }
    
    private function getEmailTemplate($code, $name) {
        $displayName = !empty($name) ? htmlspecialchars($name) : 'User';
        
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
                .code-box { background-color: #f8f9fa; border: 2px solid #dee2e6; border-radius: 4px; padding: 30px; text-align: center; margin: 30px 0; }
                .code { font-size: 38px; font-weight: 700; letter-spacing: 10px; color: #2c3e50; font-family: 'Courier New', monospace; }
                .footer { text-align: center; padding: 30px; border-top: 1px solid #e0e0e0; color: #95a5a6; font-size: 13px; }
                .warning { background-color: #fff9e6; border-left: 4px solid #f39c12; padding: 20px; margin: 25px 0; }
                .warning strong { color: #e67e22; display: block; margin-bottom: 10px; font-size: 15px; }
                .warning ul { margin: 10px 0 0 0; padding-left: 20px; color: #555; }
                .warning li { margin: 8px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>PetUnity</h1>
                    <p>EMAIL VERIFICATION</p>
                </div>
                <div class='content'>
                    <h2>Dear $displayName,</h2>
                    <p>Thank you for registering with PetUnity, your trusted pet care and vaccination management system.</p>
                    <p>To complete your registration, please use the verification code provided below:</p>
                    
                    <div class='code-box'>
                        <div class='code'>$code</div>
                    </div>
                    
                    <div class='warning'>
                        <strong>Important Notice</strong>
                        <ul>
                            <li>This verification code will expire in <strong>15 minutes</strong></li>
                            <li>Please do not share this code with anyone</li>
                            <li>If you did not request this verification, please disregard this email</li>
                        </ul>
                    </div>
                    
                    <p>Should you require any assistance, please do not hesitate to contact our support team.</p>
                    
                    <p>Respectfully,<br><strong>The PetUnity Team</strong></p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; 2025 PetUnity. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}

?>