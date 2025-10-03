<?php
// front controller / router
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/ApplianceController.php';

use App\Database;
use App\Response;
use App\ApplianceController;

// enable CORS (ปรับตามต้องการ)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$basePath = '/appliances_api/public';
$uri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// normalize and get path after base
$path = parse_url($uri, PHP_URL_PATH);

// remove base if present
if (strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = trim($path, '/');
$segments = explode('/', $path);

// route prefix expected: api/appliances...
if (empty($segments[0])) {
    Response::error('Not found', 404);
}

try {
    $pdo = Database::getInstance();
    $controller = new ApplianceController($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    // example paths:
    // api/appliances
    // api/appliances/3
    if ($segments[0] !== 'api' || ($segments[1] ?? '') !== 'appliances') {
        Response::error('Not found', 404);
    }

    $id = isset($segments[2]) && is_numeric($segments[2]) ? (int)$segments[2] : null;

    // parse JSON body for non-GET
    $body = null;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::error('Invalid JSON', 400);
        }
        $body = $decoded;
    }

    if ($method === 'GET' && $id === null) {
        $params = $_GET;
        $controller->index($params);
    } elseif ($method === 'GET' && $id !== null) {
        $controller->show($id);
    } elseif ($method === 'POST' && $id === null) {
        $controller->store($body);
    } elseif (($method === 'PUT' || $method === 'PATCH') && $id !== null) {
        $controller->update($id, $body);
    } elseif ($method === 'DELETE' && $id !== null) {
        $controller->destroy($id);
    } else {
        Response::error('Not found', 404);
    }
} catch (Exception $e) {
    Response::error('Server error', 500, $e->getMessage());
}
?>
