<?php

require_once __DIR__ . '/../controllers/AgentController.php';
require_once __DIR__ . '/../core/Response.php';
// FIX: Session::start() is called below but the Session class was never loaded
// in this file. Without this require the script throws a fatal "Class not found"
// error before any agent action can execute.
require_once __DIR__ . '/../core/Session.php';

$controller = new AgentController();
$action = $_GET['action'] ?? '';

// Security: Only allow agents to access these endpoints
Session::start();
if (!Session::hasRole('agent')) {
    Response::error("Unauthorized: Agent access required", 403);
    exit;
}

try {
    switch ($action) {
        case 'verify':
            // Handles the upload of Selfie and ID Image
            $controller->verifyIdentity();
            break;

        case 'dashboard':
            // Fetches profile, verification status, stats, and recent listings
            $controller->getDashboard();
            break;

        default:
            Response::error("Invalid agent route: " . $action, 404);
            break;
    }
} catch (Throwable $e) {
    Response::error("System Error: " . $e->getMessage(), 500);
}
