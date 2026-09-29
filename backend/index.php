<?php
// CORS HEADERS 
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// LOAD CORE FILES
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/Session.php';

$router = new Router();

// REGISTER ROUTES
// REGISTER ROUTES
$router->register('test', __DIR__ . '/api/test.php');
$router->register('auth', __DIR__ . '/api/auth.php');
$router->register('agent', __DIR__ . '/api/agent.php');
$router->register('properties', __DIR__ . '/api/property.php');
$router->register('admin', __DIR__ . '/api/admin.php');

// GET ROUTE
$route = $_GET['route'] ?? 'test';

// RESOLVE ROUTE
$router->resolve($route);
