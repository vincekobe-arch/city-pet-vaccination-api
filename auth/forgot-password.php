<?php
/**
 * Forgot Password Handler
 * POST /auth/forgot-password  → step=send_code  (send OTP to email)
 * POST /auth/forgot-password  → step=verify_code (verify OTP)
 * POST /auth/forgot-password  → step=reset       (set new password)
 * Pet owners only.
 */

ob_start();

$allowed_origins = [
    'http://localhost:3000',
    'https://city-pet-vaccination-frontend.vercel.app',
    'https://petunityph.vercel.app',
    'https://darkgoldenrod-stingray-609676.hostingersite.com',
    'https://petunity-muntinlupacity.com',
    'https://www.petunity-muntinlupacity.com'
];
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://www.petunity-muntinlupacity.com'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../services/VerificationService.php';

    $data = json_decode(file_get_contents('php://input'));
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data->step)) {
        throw new Exception('Invalid request');
    }

    $database = new Database();
    $conn = $database->getConnection();

    // ── STEP 1: Send OTP ──────────────────────────────────────
    if ($data->step === 'send_code') {
        if (empty($data->identifier)) { throw new Exception('Email or username is required'); }

        $identifier = strtolower(trim($data->identifier));

        // Resolve by email OR username, pet_owners only
        $stmt = $conn->prepare(
            "SELECT id, first_name, last_name, email FROM users
             WHERE (email = ? OR LOWER(username) = ?) AND role = 'pet_owner' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Return a clear error if not found
        if (!$user) {
            ob_clean();
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No account found with that email or username.']);
            exit();
        }

        // Use the real email on record, not what the user typed
        $email = $user['email'];

        // Generate 6-digit code
        $code       = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Rate limit: max 5 requests per hour
        $rate = $conn->prepare(
            "SELECT COUNT(*) FROM password_resets
             WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $rate->execute([$email]);
        if ($rate->fetchColumn() >= 5) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
            exit();
        }

        // Invalidate old codes
        $conn->prepare("UPDATE password_resets SET is_used = 1 WHERE email = ? AND is_used = 0")
             ->execute([$email]);

        // Insert new code
        $conn->prepare(
            "INSERT INTO password_resets (email, reset_code, expires_at) VALUES (?, ?, ?)"
        )->execute([$email, $code, $expires_at]);

        // Send email (reuse VerificationService mailer)
        $vs   = new VerificationService();
        $name = trim($user['first_name'] . ' ' . $user['last_name']);
        $sent = $vs->sendVerificationEmailWithCode($email, $name, $code);

        if (!$sent['success']) {
            throw new Exception('Failed to send email: ' . $sent['message']);
        }

        // Mask the email for the response: c*******@gmail.com
        [$local, $domain] = explode('@', $email);
        $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;

        ob_clean();
        echo json_encode([
            'success'      => true,
            'message'      => 'Verification code sent.',
            'masked_email' => $masked,
            'real_email'   => $email
        ]);
        exit();
    }

    // ── STEP 2: Verify OTP ───────────────────────────────────
    if ($data->step === 'verify_code') {
        if (empty($data->email) || empty($data->code)) {
            throw new Exception('Email and code are required');
        }

        $email = strtolower(trim($data->email));
        $code  = trim($data->code);

        $stmt = $conn->prepare(
            "SELECT id FROM password_resets
             WHERE email = ? AND reset_code = ? AND is_used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email, $code]);

        if ($stmt->rowCount() === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired code.']);
            exit();
        }

        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Code verified.']);
        exit();
    }

    // ── STEP 3: Reset Password ───────────────────────────────
    if ($data->step === 'reset') {
        if (empty($data->email) || empty($data->code) || empty($data->new_password)) {
            throw new Exception('Email, code, and new password are required');
        }

        $email       = strtolower(trim($data->email));
        $code        = trim($data->code);
        $newPassword = $data->new_password;

        // Validate password strength (mirrors register page rules)
        $pwErrors = [];
        if (strlen($newPassword) < 8)         $pwErrors[] = 'At least 8 characters';
        if (!preg_match('/[A-Z]/', $newPassword)) $pwErrors[] = 'One uppercase letter';
        if (!preg_match('/[a-z]/', $newPassword)) $pwErrors[] = 'One lowercase letter';
        if (!preg_match('/\d/',    $newPassword)) $pwErrors[] = 'One number';

        if (!empty($pwErrors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password too weak.', 'details' => $pwErrors]);
            exit();
        }

        // Re-verify code
        $stmt = $conn->prepare(
            "SELECT id FROM password_resets
             WHERE email = ? AND reset_code = ? AND is_used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$email, $code]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or expired code. Please restart.']);
            exit();
        }

        // Fetch current password hash to detect same-password reuse
        $userStmt = $conn->prepare(
            "SELECT id, password FROM users
             WHERE email = ? AND role = 'pet_owner' AND is_active = 1 LIMIT 1"
        );
        $userStmt->execute([$email]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Account not found.']);
            exit();
        }

        // Reject if same as current password
        if (password_verify($newPassword, $user['password'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'same_password',
                'message' => 'This is your current password. Please try a different one.'
            ]);
            exit();
        }

        $conn->beginTransaction();
        try {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);

            $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
                 ->execute([$hash, $user['id']]);

            // Mark reset code as used
            $conn->prepare("UPDATE password_resets SET is_used = 1 WHERE id = ?")
                 ->execute([$reset['id']]);

            $conn->commit();

            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        exit();
    }

    throw new Exception('Unknown step');

} catch (Exception $e) {
    error_log('forgot-password.php error: ' . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>