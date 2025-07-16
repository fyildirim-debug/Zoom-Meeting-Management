<?php
/**
 * JavaScript Error Logger
 */

require_once '../config/config.php';
require_once '../config/auth.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['error'])) {
    http_response_code(400);
    exit('Bad request');
}

$error = $input['error'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$url = $input['url'] ?? 'Unknown';

// Log the JavaScript error
writeLog("JavaScript Error: {$error} | URL: {$url} | User-Agent: {$userAgent}", 'error');

// Return success response
header('Content-Type: application/json');
echo json_encode(['success' => true]);
exit();