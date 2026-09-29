<?php

require_once __DIR__ . '/../controllers/AdminController.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';

$controller = new AdminController();

$action = $_GET['action'] ?? '';

// Security Check: Only allow logged-in admins
Session::start();
if (Session::get('role') !== 'admin') {
    Response::error("Unauthorized: Admin access required", 403);
    exit;
}

try {
    switch ($action) {
        case 'listings':
            // Fetches all listings (including flagged/pending) for admin review
            $controller->getListings();
            break;

        case 'approve_listing':
            // Logic to set property status to 'approved'
            $controller->approveListing();
            break;

        case 'reject_listing':
            // Logic to set property status to 'rejected' or delete
            $controller->rejectListing();
            break;

        case 'users':
            // Logic to fetch all system users
            $controller->getUsers();
            break;

        case 'fraud_logs':
            // Logic to fetch detected fraud flags
            $controller->getFraudLogs();
            break;

        case 'list_agents':
            $controller->listAgents();
            break;

        case 'verify_agent':
            $controller->verifyAgent();
            break;

        case 'stats':
            // Fetch admin dashboard statistics
            $controller->getStats();
            break;

        case 'audit_logs':
            $controller->getAuditLogs();
            break;

        case 'update_user':
            $controller->updateUser();
            break;

        case 'delete_user':
            $controller->deleteUser();
            break;

        case 'get_agent_profile':
            $controller->getAgentProfile();
            break;

        default:
            Response::error("Invalid admin action: " . $action, 404);
            break;
    }
} catch (Exception $e) {
    Response::error("Admin API Error: " . $e->getMessage(), 500);
}
