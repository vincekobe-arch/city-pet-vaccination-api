<?php
// test-email.php - Test your email configuration

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><body>";
echo "<h1>Email Configuration Test</h1>";
echo "<p>Starting email test...</p>";

require_once __DIR__ . '/services/VerificationService.php';

try {
    echo "<p>✓ Creating VerificationService...</p>";
    $service = new VerificationService();
    
    echo "<p>✓ Sending test email to cvincekobe@gmail.com...</p>";
    $result = $service->sendVerificationEmail('cvincekobe@gmail.com', 'Test User');
    
    echo "<h2>Result:</h2>";
    echo "<pre>";
    print_r($result);
    echo "</pre>";
    
    if ($result['success']) {
        echo "<h2 style='color: green;'>✅ SUCCESS! Email sent successfully!</h2>";
        echo "<p>Check your inbox at cvincekobe@gmail.com</p>";
    } else {
        echo "<h2 style='color: red;'>❌ FAILED! Email not sent</h2>";
        echo "<p>Error: " . $result['message'] . "</p>";
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ EXCEPTION OCCURRED</h2>";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "</body></html>";
?>