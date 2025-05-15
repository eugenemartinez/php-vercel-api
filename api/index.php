<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // For testing, allow all origins
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle OPTIONS request for CORS preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204); // No Content
    exit;
}

// --- Database Configuration from DATABASE_URL ---
$database_url_env = getenv('DATABASE_URL');
$conn_string = '';
$db_connection = null;
$db_connection_error = null; // To store any initial connection error

if (empty($database_url_env)) {
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_port = getenv('DB_PORT') ?: '5432';
    $db_name = getenv('DB_DATABASE') ?: 'experiments_local';
    $db_user = getenv('DB_USERNAME') ?: 'postgres';
    $db_password = getenv('DB_PASSWORD') ?: 'yowhenyow';
    $conn_string = "host={$db_host} port={$db_port} dbname={$db_name} user={$db_user} password={$db_password}";
} else {
    $url_parts = parse_url($database_url_env);
    if ($url_parts === false || !isset($url_parts['scheme']) || $url_parts['scheme'] !== 'postgresql') {
        $db_connection_error = 'Invalid DATABASE_URL format or scheme. Expected format: postgresql://user:password@host:port/dbname. Received: ' . $database_url_env;
    } else {
        $db_user = $url_parts['user'] ?? null;
        $db_password = $url_parts['pass'] ?? null;
        $db_host = $url_parts['host'] ?? null;
        $db_port = $url_parts['port'] ?? 5432;
        $db_name = isset($url_parts['path']) ? ltrim($url_parts['path'], '/') : null;

        if (!$db_user || !$db_host || !$db_name) {
            $db_connection_error = 'DATABASE_URL is missing required components (user, host, or database name). Parsed: ' . json_encode($url_parts);
        } else {
            $conn_string = "host={$db_host} port={$db_port} dbname={$db_name} user={$db_user}";
            if ($db_password !== null) {
                $conn_string .= " password={$db_password}";
            }
        }
    }
}

if ($db_connection_error === null) { // Only attempt connection if config parsing was okay
    try {
        if (!extension_loaded('pgsql')) {
            throw new Exception("pgsql extension is not loaded. Cannot connect to PostgreSQL.");
        }
        $db_connection = pg_connect($conn_string);
        if (!$db_connection) {
            // Use pg_last_error() without a connection resource if pg_connect failed
            throw new Exception("Failed to connect to PostgreSQL: " . pg_last_error());
        }
    } catch (Throwable $e) {
        $db_connection_error = $e->getMessage(); // Store the error message
        $db_connection = null; // Ensure connection is null if it failed
    }
}


$method = $_SERVER['REQUEST_METHOD'];
// Use REQUEST_URI and parse it to get the path, as PATH_INFO might not always be set reliably depending on server config
$request_uri_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = trim($request_uri_path, '/');
// If your script is in a subdirectory of the domain (e.g. domain.com/api/script.php)
// and you want paths relative to the script, you might need to adjust how $path is derived.
// For Vercel with routes like "/(.*)" -> "/api/index.php", $request_uri_path should give you the part after the domain.

$path_parts = explode('/', $path);

$resource = $path_parts[0] ?? null;
// If your vercel.json routes to "api/index.php", "api" might be the first part.
// Adjust if your base path on Vercel is effectively /api/ and you want "items" from "/api/items"
if ($resource === 'api' && isset($path_parts[1])) { // Simple adjustment if "api" is part of the path from Vercel routing
    $resource = $path_parts[1];
    $resource_id = $path_parts[2] ?? null;
} else {
    $resource_id = $path_parts[1] ?? null;
}


$response = ['error' => 'Invalid request'];
http_response_code(400);

// Root route health check
if (($resource === null || $resource === '' || $resource === 'api') && count($path_parts) <=1 && $method === 'GET') {
    $api_status = "OK";
    $database_status = "Not Connected";
    $database_message = $db_connection_error ?: "No attempt to connect (config error or initial state).";

    if ($db_connection_error === null && $db_connection) {
        // Try a simple query to confirm the connection is truly alive
        $health_check_result = @pg_query($db_connection, "SELECT 1"); // Use @ to suppress warnings if query fails
        if ($health_check_result) {
            $database_status = "Connected and Responsive";
            $database_message = "Successfully executed a test query.";
            pg_free_result($health_check_result);
        } else {
            $database_status = "Connected but Unresponsive";
            $database_message = "Failed to execute a test query: " . pg_last_error($db_connection);
        }
    } elseif ($db_connection_error) {
        $database_status = "Connection Error";
        // $database_message is already set from $db_connection_error
    }

    $response = [
        'message' => 'Welcome to the Basic PHP CRUD API!',
        'api_status' => $api_status,
        'database_status' => $database_status,
        'database_details' => $database_message,
        'database_config_source' => !empty($database_url_env) ? 'DATABASE_URL' : 'Fallback/Individual Vars',
        'php_version' => phpversion(),
        'timestamp' => date('Y-m-d H:i:s'),
        'info' => 'Try accessing /items (or /api/items depending on your exact Vercel path)'
    ];
    http_response_code(200);
} else if ($resource === 'items') {
    // --- Ensure database connection is available for CRUD operations ---
    if (!$db_connection) {
        http_response_code(503); // Service Unavailable
        echo json_encode([
            'error' => 'Database service not available.',
            'message' => $db_connection_error ?: "Database connection was not established."
        ]);
        if ($db_connection) pg_close($db_connection); // Should not be reachable if $db_connection is false
        exit;
    }
    // --- End Database Connection Check for CRUD ---

    switch ($method) {
        case 'GET':
            if ($resource_id) {
                $result = pg_query_params($db_connection, 'SELECT id, name, description FROM items WHERE id = $1', [$resource_id]);
                if ($result && pg_num_rows($result) > 0) {
                    $response = pg_fetch_assoc($result);
                    http_response_code(200);
                } else {
                    $response = ['error' => 'Item not found', 'db_error' => pg_last_error($db_connection)];
                    http_response_code(404);
                }
            } else {
                $result = pg_query($db_connection, 'SELECT id, name, description FROM items ORDER BY id');
                $items = [];
                if ($result) {
                    while ($row = pg_fetch_assoc($result)) {
                        $items[] = $row;
                    }
                    $response = $items;
                    http_response_code(200);
                } else {
                    $response = ['error' => 'Failed to fetch items', 'db_error' => pg_last_error($db_connection)];
                    http_response_code(500);
                }
            }
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (isset($input['name']) && isset($input['description'])) {
                $result = pg_query_params(
                    $db_connection,
                    'INSERT INTO items (name, description) VALUES ($1, $2) RETURNING id, name, description',
                    [$input['name'], $input['description']]
                );
                if ($result && pg_num_rows($result) > 0) {
                    $response = pg_fetch_assoc($result);
                    http_response_code(201);
                } else {
                    $response = ['error' => 'Failed to create item: ' . pg_last_error($db_connection)];
                    http_response_code(500);
                }
            } else {
                $response = ['error' => 'Missing name or description'];
                http_response_code(400);
            }
            break;

        case 'PUT':
            if ($resource_id) {
                $input = json_decode(file_get_contents('php://input'), true);
                if (isset($input['name']) && isset($input['description'])) {
                    $result = pg_query_params(
                        $db_connection,
                        'UPDATE items SET name = $1, description = $2 WHERE id = $3 RETURNING id, name, description',
                        [$input['name'], $input['description'], $resource_id]
                    );
                    if ($result && pg_num_rows($result) > 0) {
                        $response = pg_fetch_assoc($result);
                        http_response_code(200);
                    } else {
                        $response = ['error' => 'Failed to update item or item not found: ' . pg_last_error($db_connection)];
                        http_response_code(500);
                    }
                } else {
                    $response = ['error' => 'Missing name or description'];
                    http_response_code(400);
                }
            } else {
                $response = ['error' => 'Missing item ID for update'];
                http_response_code(400);
            }
            break;

        case 'DELETE':
            if ($resource_id) {
                $result = pg_query_params($db_connection, 'DELETE FROM items WHERE id = $1 RETURNING id', [$resource_id]);
                if ($result && pg_num_rows($result) > 0) {
                    $response = ['message' => 'Item deleted successfully', 'id' => $resource_id];
                    http_response_code(200);
                } else {
                    $response = ['error' => 'Failed to delete item or item not found: ' . pg_last_error($db_connection)];
                    http_response_code(404);
                }
            } else {
                $response = ['error' => 'Missing item ID for delete'];
                http_response_code(400);
            }
            break;

        default:
            $response = ['error' => 'Method not allowed'];
            http_response_code(405);
            break;
    }
}


if ($db_connection) {
    pg_close($db_connection);
}

echo json_encode($response);
?>