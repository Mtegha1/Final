<?php

require_once dirname(__DIR__) . '/controllers/PropertyController.php';
require_once dirname(__DIR__) . '/core/Response.php';
require_once dirname(__DIR__) . '/core/Session.php';

$controller = new PropertyController();

// Get the action from the URL (e.g., ?action=create)
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list_all':
            $controller->getAllProperties();
            break;

        case 'get':
            $controller->getPropertyById();
            break;

        case 'update':
            $controller->updateProperty();
            break;

        case 'update_status':
            $controller->changePropertyStatus();
            break;

        case 'delete':
            $controller->deleteProperty();
            break;

        case 'list':
        case 'approved':
            // Fetch all approved properties (default public listing)
            $controller->getApprovedProperties();
            break;

        case 'create':
            // Create a new property listing
            $controller->createProperty();
            break;

        case 'admin_upload':
            $controller->uploadAdminPropertyPhotos();
            break;

        case 'stats':
            // Get admin dashboard statistics
            $controller->getAdminStats();
            break;

        default:
            // Default: return approved properties
            $controller->getApprovedProperties();
            break;
    }
} catch (Exception $e) {
    Response::error("Property API Error: " . $e->getMessage(), 500);
}
