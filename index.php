<?php
// index.php - Main entry point for the API

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS Headers
$allowed_origins = [
    'http://localhost:3000',
    'https://city-pet-vaccination-frontend.vercel.app',
    'https://petunityph.vercel.app'
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://city-pet-vaccination-frontend.vercel.app");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 86400");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get the request URI and method
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove base path (adjust this based on your setup)
$base_path = (isset($_SERVER['HTTP_HOST']) && 
             (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
              strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) 
             ? '/city-pet-vaccination-api' 
             : '';
$uri = str_replace($base_path, '', $request_uri);

// Route to appropriate controller
try {
    // Auth routes
    if (strpos($uri, '/auth/login') === 0) {
        require_once __DIR__ . '/auth/login.php';
    }
    else if (strpos($uri, '/auth/register') === 0) {
        require_once __DIR__ . '/auth/register.php';
    }
    else if (strpos($uri, '/auth/verify-and-complete') === 0) {
        require_once __DIR__ . '/auth/verify-email.php';
    }
    else if (strpos($uri, '/auth/') === 0) {
        // Other auth routes
        require_once __DIR__ . '/auth/login.php';
    }
    
    // Verification routes
    else if (strpos($uri, '/verification') === 0) {
        require_once __DIR__ . '/routes/verification.php';
    }
    
    // Pet routes
    else if (strpos($uri, '/pets') === 0) {
        require_once __DIR__ . '/routes/pet.php';
    }
    
    // Owner routes
    else if (strpos($uri, '/owners') === 0) {
        require_once __DIR__ . '/routes/owner.php';
    }
    
    // Vaccination routes
    else if (strpos($uri, '/vaccinations') === 0) {
        require_once __DIR__ . '/routes/vaccination.php';
    }
    
    // ✅ DEWORMING ROUTES - ADDED
    else if (strpos($uri, '/dewormings') === 0) {
        require_once __DIR__ . '/routes/deworming.php';
    }
    
    // ✅ STERILIZATION ROUTES - ADDED
    else if (strpos($uri, '/sterilizations') === 0) {
        require_once __DIR__ . '/routes/sterilization.php';
    }
    
    // Vet Card routes
    else if (strpos($uri, '/vetcards') === 0) {
        require_once __DIR__ . '/routes/vetcard.php';
    }
    
    // Schedule routes
    else if (strpos($uri, '/schedules') === 0) {
        require_once __DIR__ . '/routes/schedule.php';
    }
    
    // Barangay routes
    else if (strpos($uri, '/barangays') === 0) {
        require_once __DIR__ . '/routes/barangay.php';
    }
    
    // Official routes
    else if (strpos($uri, '/officials') === 0) {
        require_once __DIR__ . '/routes/official.php';
    }
    
    // Clinic routes
    else if (strpos($uri, '/clinics') === 0 || strpos($uri, '/clinic') === 0) {
        require_once __DIR__ . '/routes/clinic.php';
    }
    
    else if (strpos($uri, '/reports') === 0) {
        require_once __DIR__ . '/routes/report.php';
    }
    
    else if (strpos($uri, '/inventory') === 0) {
    require_once __DIR__ . '/routes/inventory.php';
    }

    else if (strpos($uri, '/microchips') === 0) {
        require_once __DIR__ . '/routes/microchip.php';
    }
    

    else if (strpos($uri, '/clinics') === 0) {
        require_once __DIR__ . '/routes/clinic.php';
    }

    
    // Default - API info
    else if ($uri === '/' || $uri === '' || $uri === '/index.php') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'City Pet & Vaccination API',
            'version' => '1.0',
            'endpoints' => [
                '/auth/login' => 'Authentication',
                '/auth/register' => 'Registration',
                '/auth/verify-and-complete' => 'Email verification',
                '/verification' => 'Email verification (standalone)',
                '/pets' => 'Pet management',
                '/owners' => 'Owner management',
                '/vaccinations' => 'Vaccination records',
                '/dewormings' => 'Deworming records',
                '/sterilizations' => 'Sterilization records',
                '/vetcards' => 'Vet cards',
                '/schedules' => 'Schedules',
                '/barangays' => 'Barangay management',
                '/officials' => 'Official management'
            ]
        ]);
    }
    
    // 404 - Route not found
    else {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Route not found',
            'requested_uri' => $uri,
            'method' => $request_method,
            'available_routes' => [
                '/auth/*',
                '/verification/*',
                '/pets/*',
                '/owners/*',
                '/vaccinations/*',
                '/dewormings/*',
                '/sterilizations/*',
                '/vetcards/*',
                '/schedules/*',
                '/barangays/*',
                '/officials/*'
            ]
        ]);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>