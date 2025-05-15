<?php

header("Content-Type: application/json");
// Allow CORS for all origins for this basic test
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

$response = [
    'message' => 'Hello World from basic PHP API on Vercel!',
    'php_version' => phpversion(),
    'timestamp' => date('Y-m-d H:i:s')
];

http_response_code(200);
echo json_encode($response);

?>