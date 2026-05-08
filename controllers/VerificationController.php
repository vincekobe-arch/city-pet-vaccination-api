<?php
// controllers/VerificationController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/VerificationService.php';

class VerificationController {
    private $verificationService;
    
    public function __construct() {
        $this->verificationService = new VerificationService();
    }
    
    // POST /verification/send
    public function sendCode() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        if (!isset($data->email) || empty($data->email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Email is required'
            ]);
            return;
        }
        
        // Validate email format
        if (!filter_var($data->email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid email format'
            ]);
            return;
        }
        
        $name = isset($data->name) ? $data->name : '';
        
        try {
            $result = $this->verificationService->sendVerificationEmail($data->email, $name);
            
            if ($result['success']) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => $result['message']
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => $result['message']
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to send verification code: ' . $e->getMessage()
            ]);
        }
    }
    
    // POST /verification/verify
    public function verifyCode() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        if (!isset($data->email) || empty($data->email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Email is required'
            ]);
            return;
        }
        
        if (!isset($data->code) || empty($data->code)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Verification code is required'
            ]);
            return;
        }
        
        try {
            $result = $this->verificationService->verifyCode($data->email, $data->code);
            
            if ($result['success']) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => $result['message']
                ]);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $result['message']
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Verification failed: ' . $e->getMessage()
            ]);
        }
    }
    
    // POST /verification/resend
    public function resendCode() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }
        
        $data = json_decode(file_get_contents("php://input"));
        
        if (!isset($data->email) || empty($data->email)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Email is required'
            ]);
            return;
        }
        
        try {
            $result = $this->verificationService->resendVerificationCode($data->email);
            
            if ($result['success']) {
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => $result['message']
                ]);
            } else {
                http_response_code(429); // Too Many Requests
                echo json_encode([
                    'success' => false,
                    'error' => $result['message']
                ]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to resend code: ' . $e->getMessage()
            ]);
        }
    }
}
?>