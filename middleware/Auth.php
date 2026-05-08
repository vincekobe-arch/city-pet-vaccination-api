<?php

require_once __DIR__ . '/../config/database.php';

class Auth {
    private $conn;
    private $secret_key = "city_pet_vaccination_2024_secret_key"; // Change this in production
    
    // FIXED: Accept optional connection parameter
    public function __construct($connection = null) {
        if ($connection) {
            // Use provided connection
            $this->conn = $connection;
        } else {
            // Create new connection if none provided
            $database = new Database();
            $this->conn = $database->getConnection();
        }
    }
    
    /**
     * Authenticate user and return user data
     * @return array User data from token
     * @throws Exception if authentication fails
     */
    public function authenticate() {
        $headers = $this->getAllHeaders();
        $token = null;
        
        // Check for Authorization header
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        // Check for token in different header formats
        if (!$token && isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'No token provided', 'code' => 'NO_TOKEN']);
            exit;
        }
        
        try {
            $decoded = $this->verifyToken($token);
            
            // Check if user still exists and is active
            $query = "SELECT u.*, b.name as barangay_name, b.code as barangay_code 
                     FROM users u 
                     LEFT JOIN barangays b ON u.assigned_barangay_id = b.id 
                     WHERE u.id = :user_id AND u.is_active = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':user_id', $decoded['user_id']);
            $stmt->execute();
            
            if ($stmt->rowCount() === 0) {
                http_response_code(401);
                echo json_encode(['error' => 'User not found or inactive', 'code' => 'USER_NOT_FOUND']);
                exit;
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            unset($user['password']); // Remove password from response

            // Update last_login at most once per hour to avoid excessive DB writes,
            // and reactivate if previously auto-deactivated by inactivity
            if ($user['role'] === 'pet_owner') {
                $shouldUpdate = empty($user['last_login']) ||
                    (time() - strtotime($user['last_login'])) > 3600;
                if ($shouldUpdate) {
                    $ll_stmt = $this->conn->prepare(
                        "UPDATE users SET last_login = NOW(), is_active = 1
                         WHERE id = :user_id AND role = 'pet_owner'"
                    );
                    $ll_stmt->bindParam(':user_id', $decoded['user_id']);
                    $ll_stmt->execute();
                    $user['last_login'] = date('Y-m-d H:i:s');
                    $user['is_active']  = 1;
                }
            }

            return array_merge($decoded, ['user_details' => $user]);
            
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token: ' . $e->getMessage(), 'code' => 'INVALID_TOKEN']);
            exit;
        }
    }
    
    /**
     * Check if user has required role
     * @param array $allowedRoles Array of allowed roles
     * @param array $userData User data from authenticate()
     * @return bool
     */
    public function checkRole($allowedRoles, $userData = null) {
        if (!$userData) {
            $userData = $this->authenticate();
        }
        
        if (!in_array($userData['role'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions', 'code' => 'INSUFFICIENT_PERMISSIONS']);
            exit;
        }
        
        return true;
    }
    
    /**
     * Check if user can access barangay data
     * @param int $barangayId Barangay ID to check access for
     * @param array $userData User data from authenticate()
     * @return bool
     */
    public function checkBarangayAccess($barangayId, $userData = null) {
        if (!$userData) {
            $userData = $this->authenticate();
        }
        
        // Super admin can access all barangays
        if ($userData['role'] === 'super_admin') {
            return true;
        }
        
        // Barangay officials can only access their assigned barangay
        if ($userData['role'] === 'barangay_official') {
            if ($userData['user_details']['assigned_barangay_id'] != $barangayId) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this barangay', 'code' => 'BARANGAY_ACCESS_DENIED']);
                exit;
            }
        }
        
        // Pet owners can only access their own barangay
        if ($userData['role'] === 'pet_owner') {
            if ($userData['user_details']['assigned_barangay_id'] != $barangayId) {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied to this barangay', 'code' => 'BARANGAY_ACCESS_DENIED']);
                exit;
            }
        }
        
        return true;
    }
    
    /**
     * Generate JWT token
     * @param int $userId User ID
     * @param string $role User role
     * @param int $barangayId Assigned barangay ID (optional)
     * @return string JWT token
     */
    public function generateToken($userId, $role, $barangayId = null, $officeRole = null) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    
    $payload = json_encode([
        'user_id' => $userId,
        'role' => $role,
        'barangay_id' => $barangayId,
        'office_role' => $officeRole,
        'iat' => time(), // Issued at
        'exp' => time() + (24 * 60 * 60) // Expires in 24 hours
    ]);
        
        $headerEncoded = $this->base64UrlEncode($header);
        $payloadEncoded = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, $this->secret_key, true);
        $signatureEncoded = $this->base64UrlEncode($signature);
        
        return $headerEncoded . "." . $payloadEncoded . "." . $signatureEncoded;
    }
    
    /**
     * Verify JWT token
     * @param string $token JWT token
     * @return array Decoded payload
     * @throws Exception if token is invalid
     */
    private function verifyToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }
        
        $header = json_decode($this->base64UrlDecode($parts[0]), true);
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        $signature = $this->base64UrlDecode($parts[2]);
        
        // Verify signature
        $expectedSignature = hash_hmac('sha256', $parts[0] . "." . $parts[1], $this->secret_key, true);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception('Invalid signature');
        }
        
        // Check if token has expired
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token expired');
        }
        
        return $payload;
    }
    
    /**
     * Hash password
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Verify password
     * @param string $password Plain text password
     * @param string $hash Hashed password
     * @return bool
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Generate random string for various purposes
     * @param int $length Length of string
     * @return string Random string
     */
    public function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * Validate email format
     * @param string $email Email address
     * @return bool
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate password strength
     * @param string $password Password to validate
     * @return array Result with is_valid and errors
     */
    public function validatePassword($password) {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        /*if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }*/
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Base64 URL encode
     * @param string $data Data to encode
     * @return string Encoded string
     */
    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64 URL decode
     * @param string $data Data to decode
     * @return string Decoded string
     */
    private function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    /**
     * Get all HTTP headers (case-insensitive)
     * @return array Headers
     */
    private function getAllHeaders() {
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback for servers that don't support getallheaders()
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) == 'HTTP_') {
                    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }
        
        return $headers;
    }
    
    /**
     * Log security events
     * @param string $event Event type
     * @param array $data Event data
     */
    private function logSecurityEvent($event, $data = []) {
        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'data' => $data
        ];
        
        // In production, save to database or log file
        error_log('Security Event: ' . json_encode($log));
    }
}

// Test endpoint when accessed directly
if (basename($_SERVER['PHP_SELF']) == 'Auth.php') {
    header('Content-Type: application/json');
    
    try {
        $auth = new Auth();
        echo json_encode([
            'success' => true,
            'message' => 'Auth middleware loaded successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT);
        
    } catch(Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Auth middleware failed to load',
            'error' => $e->getMessage()
        ], JSON_PRETTY_PRINT);
    }
}
?>