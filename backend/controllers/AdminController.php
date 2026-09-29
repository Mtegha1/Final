<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../models/FraudLog.php';
require_once __DIR__ . '/../models/AgentProfile.php';
require_once __DIR__ . '/../services/TrustScoreService.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';

class AdminController
{
    private $userModel;
    private $propertyModel;
    private $fraudModel;
    private $agentModel;

    public function __construct()
    {
        $this->userModel = new User();
        $this->propertyModel = new Property();
        $this->fraudModel = new FraudLog();
        $this->agentModel = new AgentProfile();
    }

    public function getUsers()
    {
        Response::success($this->userModel->getAll());
    }

    public function getListings()
    {
        Response::success($this->propertyModel->getAll());
    }

    public function getFraudLogs()
    {
        Response::success($this->fraudModel->getAll());
    }

    public function listAgents()
    {
        Response::success($this->userModel->getAgentsWithProfile());
    }

    public function verifyAgent()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $userId = $input['user_id'] ?? null;
        $status = $input['status'] ?? null;
        if (!$userId || !$status) {
            Response::error("User ID and status are required");
            return;
        }

        $status = strtolower($status);
        $verificationStatus = null;

        if ($status === 'approved') {
            $verificationStatus = 'verified';
        } elseif ($status === 'denied' || $status === 'rejected') {
            $verificationStatus = 'rejected';
        } elseif ($status === 'pending') {
            $verificationStatus = 'pending';
        } else {
            Response::error("Invalid verification status");
            return;
        }

        try {
            $db = Database::getInstance()->conn;
            $isVerified = $verificationStatus === 'verified' ? 1 : 0;
            $stmt = $db->prepare("INSERT INTO agent_profiles (user_id, verification_status, is_verified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verification_status = VALUES(verification_status), is_verified = VALUES(is_verified)");
            $stmt->execute([$userId, $verificationStatus, $isVerified]);
            (new TrustScoreService($db))->recalculateAgentProperties($userId);
            Response::success([], "Agent verification updated");
        } catch (Throwable $e) {
            Response::error("Failed to update agent verification: " . $e->getMessage());
        }
    }

    public function approveListing()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['property_id'] ?? null;
        if (!$id || !$this->propertyModel->updateStatus($id, 'approved')) {
            Response::error("Failed to approve property");
            return;
        }
        $this->recordListingDecision($id, 'approved');
        Response::success([], "Property approved");
    }

    public function rejectListing()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['property_id'] ?? null;
        if (!$id || !$this->propertyModel->updateStatus($id, 'rejected')) {
            Response::error("Failed to reject property");
            return;
        }
        $this->recordListingDecision($id, 'rejected');
        Response::success([], "Property rejected");
    }

    private function recordListingDecision($propertyId, $action)
    {
        Session::start();
        $adminId = Session::get('user_id');
        $db = Database::getInstance()->conn;
        $stmt = $db->prepare(
            "INSERT INTO audit_logs (user_id, action, logged_at) VALUES (?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$adminId, "Property {$propertyId} {$action}"]);
    }

    public function getStats()
    {
        try {
            $db = Database::getInstance()->conn;

            // Pending verifications
            $stmt = $db->query("SELECT COUNT(*) as count FROM properties WHERE status = 'pending'");
            $pending = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Flagged listings
            $stmt = $db->query("SELECT COUNT(*) as count FROM properties WHERE is_flagged = 1");
            $flagged = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            // Active agents
            $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role = 'agent'");
            $agents = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $fraudReport = $db->query(
                "SELECT type, DATE(created_at) AS report_date, COUNT(*) AS count
                 FROM fraud_logs
                 WHERE created_at >= CURRENT_DATE - INTERVAL 30 DAY
                 GROUP BY type, DATE(created_at)
                 ORDER BY report_date DESC, type"
            )->fetchAll(PDO::FETCH_ASSOC);

            Response::success([
                "pending" => $pending,
                "flagged" => $flagged,
                "agents" => $agents,
                "fraud_by_type_over_time" => $fraudReport
            ]);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function getAuditLogs()
    {
        try {
            $db = Database::getInstance()->conn;
            // Logic inspired by your audit_log.php file
            $sql = "SELECT l.*, u.full_name, u.role as user_role 
                FROM audit_logs l 
                LEFT JOIN users u ON l.user_id = u.id 
                ORDER BY l.logged_at DESC LIMIT 10";
            $stmt = $db->prepare($sql);
            $stmt->execute();
            Response::success($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function updateUser()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['id'] ?? null;

        if (!$id) {
            Response::error("User ID is required");
            return;
        }

        try {
            $db = Database::getInstance()->conn;
            $sql = "UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $success = $stmt->execute([
                $input['full_name'],
                $input['email'],
                $input['role'],
                $id
            ]);

            // Only update password if a new one is provided
            if (!empty($input['password'])) {
                $hashed = password_hash($input['password'], PASSWORD_BCRYPT);
                $pwStmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $pwStmt->execute([$hashed, $id]);
            }

            Response::success([], "User updated successfully");
        } catch (Throwable $e) {
            Response::error("Update failed: " . $e->getMessage());
        }
    }

    public function deleteUser()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['id'] ?? null;

        if (!$id) {
            Response::error("User ID is required");
            return;
        }

        try {
            $db = Database::getInstance()->conn;

            // Start transaction
            $db->beginTransaction();

            // Delete related records first (cascade delete)
            // Delete agent profile if exists
            $stmt = $db->prepare("DELETE FROM agent_profiles WHERE user_id = ?");
            $stmt->execute([$id]);

            // Finally delete the user
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);

            // Commit transaction
            $db->commit();

            Response::success([], "User deleted successfully");
        } catch (Throwable $e) {
            // Rollback on error
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error("Delete failed: " . $e->getMessage());
        }
    }

    public function getAgentProfile()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $userId = $input['user_id'] ?? null;

        if (!$userId) {
            Response::error("User ID is required");
            return;
        }

        $profile = $this->agentModel->get($userId);
        if ($profile) {
            Response::success($profile);
        } else {
            Response::error("Agent profile not found");
        }
    }
}
