<?php

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../models/FraudLog.php';
require_once __DIR__ . '/../models/AgentProfile.php';
require_once __DIR__ . '/../services/TrustScoreService.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/identity_storage.php';

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

    public function ensureRentalDrafts(): void
    {
        Session::start();
        $adminId = filter_var(Session::get('user_id'), FILTER_VALIDATE_INT);
        if (!$adminId || !Session::hasRole('admin')) {
            Response::error('Admin access required', 403);
            return;
        }

        $areas = [
            'Namiwawa',
            'Chinyonga',
            'Chitawira',
            'Nyambadwe',
            'Sunny Side',
            'Mount Pleasant',
            'Mpemba',
            'Chigumula',
            'Bvumbwe',
            'Chileka'
        ];
        $db = Database::getInstance()->conn;

        try {
            $db->beginTransaction();
            $countDrafts = $db->prepare(
                "SELECT COUNT(*) FROM properties
                 WHERE area_name = ? AND created_by IS NOT NULL AND status = 'draft'
                   AND title LIKE 'Rental draft %'"
            );
            $createDraft = $db->prepare(
                "INSERT INTO properties
                    (agent_id, created_by, title, description, price, property_type,
                     area_name, latitude, longitude, image_url, image_hash, status, is_flagged,
                     risk_score, risk_band, risk_recommendation, risk_signals)
                 VALUES (NULL, ?, ?, ?, NULL, NULL, ?, NULL, NULL, NULL, NULL, 'draft', 0,
                         NULL, NULL, 'manual_review', NULL)"
            );

            $created = 0;
            foreach ($areas as $area) {
                $countDrafts->execute([$area]);
                $missing = max(0, 3 - (int)$countDrafts->fetchColumn());
                for ($index = 1; $index <= $missing; $index++) {
                    $draftNumber = 1;
                    do {
                        $title = "Rental draft {$draftNumber} - {$area}";
                        $exists = $db->prepare(
                            'SELECT 1 FROM properties WHERE area_name = ? AND title = ? LIMIT 1'
                        );
                        $exists->execute([$area, $title]);
                        $draftNumber++;
                    } while ($exists->fetchColumn());

                    $createDraft->execute([
                        $adminId,
                        $title,
                        'Unverified admin draft. Add the confirmed property details, monthly rent, exact location pin, and photos before submitting for review.',
                        $area
                    ]);
                    $created++;
                }
            }

            if ($created > 0) {
                $audit = $db->prepare(
                    'INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $audit->execute([
                    $adminId,
                    'Created rental listing drafts',
                    'draft',
                    "{$created} draft listing(s) created across the ten requested areas."
                ]);
            }
            $db->commit();
            Response::success(
                ['created' => $created],
                $created > 0
                    ? "{$created} draft listing(s) created. Existing drafts were not duplicated."
                    : 'Each requested area already has three unverified admin draft slots.'
            );
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Admin draft creation failed: ' . $error->getMessage());
            Response::error('Rental drafts could not be created.', 500);
        }
    }

    public function assignListingAgent(): void
    {
        Session::start();
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Response::error('A valid request body is required.', 400);
            return;
        }

        $propertyId = filter_var($input['property_id'] ?? null, FILTER_VALIDATE_INT);
        $rawAgentId = $input['agent_id'] ?? null;
        $agentId = ($rawAgentId === null || $rawAgentId === '')
            ? null
            : filter_var($rawAgentId, FILTER_VALIDATE_INT);
        if (!$propertyId || ($rawAgentId !== null && $rawAgentId !== '' && (!$agentId || $agentId < 1))) {
            Response::error('A valid property and optional agent are required.', 400);
            return;
        }

        $db = Database::getInstance()->conn;
        try {
            $db->beginTransaction();
            $property = $db->prepare('SELECT id FROM properties WHERE id = ? FOR UPDATE');
            $property->execute([$propertyId]);
            if (!$property->fetchColumn()) {
                $db->rollBack();
                Response::error('Property not found.', 404);
                return;
            }

            if ($agentId !== null) {
                $agent = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'agent' LIMIT 1");
                $agent->execute([$agentId]);
                if (!$agent->fetchColumn()) {
                    $db->rollBack();
                    Response::error('Choose an existing agent account.', 400);
                    return;
                }
            }

            $update = $db->prepare('UPDATE properties SET agent_id = ? WHERE id = ?');
            $update->execute([$agentId, $propertyId]);
            $audit = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $audit->execute([
                Session::get('user_id'),
                "Property {$propertyId} agent assignment updated",
                $agentId === null ? 'unassigned' : 'assigned',
                $agentId === null ? null : "Assigned to agent {$agentId}."
            ]);
            $db->commit();
            Response::success([], $agentId === null ? 'Agent assignment removed.' : 'Agent assigned.');
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Property agent assignment failed: ' . $error->getMessage());
            Response::error('The agent assignment could not be saved.', 500);
        }
    }

    public function verifyAgent()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $userId = $input['user_id'] ?? null;
        $status = $input['status'] ?? null;
        $note = trim((string)($input['note'] ?? ''));
        if (!$userId || !$status) {
            Response::error("User ID and status are required");
            return;
        }
        if (strlen($note) > 200) {
            Response::error("Review note must be 200 bytes or fewer", 400);
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
            $db->beginTransaction();
            $isVerified = $verificationStatus === 'verified' ? 1 : 0;
            $stmt = $db->prepare("INSERT INTO agent_profiles (user_id, verification_status, is_verified) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE verification_status = VALUES(verification_status), is_verified = VALUES(is_verified)");
            $stmt->execute([$userId, $verificationStatus, $isVerified]);
            (new TrustScoreService($db))->recalculateAgentProperties($userId);
            $action = "Agent {$userId} verification {$verificationStatus}";
            if ($note !== '') {
                $action .= ": " . $note;
            }
            $audit = $db->prepare(
                "INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
            );
            $audit->execute([Session::get('user_id'), $action, $verificationStatus, $note ?: null]);
            $db->commit();
            Response::success([], "Agent verification updated");
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            Response::error("Failed to update agent verification: " . $e->getMessage());
        }
    }

    public function approveListing()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['property_id'] ?? null;
        $note = trim((string)($input['note'] ?? ''));
        if (strlen($note) > 200) {
            Response::error("Review note must be 200 bytes or fewer", 400);
            return;
        }
        $this->decideListing($id, 'approved', $note);
    }

    public function rejectListing()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id = $input['property_id'] ?? null;
        $note = trim((string)($input['note'] ?? ''));
        if (strlen($note) > 200) {
            Response::error("Review note must be 200 bytes or fewer", 400);
            return;
        }
        $this->decideListing($id, 'rejected', $note);
    }

    private function decideListing($propertyId, string $decision, string $note): void
    {
        Session::start();
        $db = Database::getInstance()->conn;
        if (!filter_var($propertyId, FILTER_VALIDATE_INT) || !in_array($decision, ['approved', 'rejected'], true)) {
            Response::error("A valid property and decision are required", 400);
            return;
        }

        try {
            $db->beginTransaction();
            $exists = $db->prepare('SELECT id FROM properties WHERE id = ? FOR UPDATE');
            $exists->execute([$propertyId]);
            if (!$exists->fetchColumn()) {
                $db->rollBack();
                Response::error("Property not found", 404);
                return;
            }
            if ($decision === 'approved') {
                $blockers = $this->propertyModel->getApprovalBlockers((int)$propertyId);
                if ($blockers) {
                    $db->rollBack();
                    Response::error(
                        'This admin-created draft is not ready for publication: ' . implode(' ', $blockers),
                        400
                    );
                    return;
                }
            }
            if (!$this->propertyModel->updateStatus($propertyId, $decision)) {
                throw new RuntimeException("Property status could not be updated.");
            }

            $action = "Property {$propertyId} {$decision}";
            $audit = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $audit->execute([Session::get('user_id'), $action, $decision, $note ?: null]);
            $db->commit();
            Response::success([], "Property {$decision}");
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            Response::error("The property decision could not be recorded.", 500);
        }
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
            $stmt = $db->query(
                "SELECT COUNT(*) as count FROM users u
                 INNER JOIN agent_profiles ap ON ap.user_id = u.id
                 WHERE u.role = 'agent' AND ap.verification_status = 'verified'"
            );
            $agents = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            $fraudReport = $db->query(
                "SELECT type, DATE(created_at) AS report_date, COUNT(*) AS count
                 FROM fraud_logs
                 WHERE flagged = 1 AND created_at >= CURRENT_DATE - INTERVAL 30 DAY
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

    public function serveAgentMedia()
    {
        $userId = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
        $mediaType = $_GET['type'] ?? '';
        $mediaColumns = [
            'id' => 'national_id_path',
            'selfie' => 'selfie_path',
            'heatmap' => 'ela_heatmap_path',
        ];

        if (!$userId || !isset($mediaColumns[$mediaType])) {
            Response::error("Invalid agent media request", 400);
            return;
        }

        $column = $mediaColumns[$mediaType];
        $stmt = Database::getInstance()->conn->prepare(
            "SELECT {$column} FROM agent_profiles WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$userId]);
        $filename = $stmt->fetchColumn();

        if (
            !$filename ||
            basename($filename) !== $filename ||
            !preg_match('/\A[A-Za-z0-9_.-]{1,128}\z/', $filename)
        ) {
            Response::error("Agent image not found", 404);
            return;
        }

        $directory = identity_upload_directory();
        $path = realpath($directory . DIRECTORY_SEPARATOR . $filename);
        $directoryPrefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($path === false || strpos($path, $directoryPrefix) !== 0 || !is_file($path)) {
            // Legacy images stay readable only through this admin-authenticated endpoint.
            $legacyDirectory = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ids');
            $path = $legacyDirectory === false
                ? false
                : realpath($legacyDirectory . DIRECTORY_SEPARATOR . $filename);
            $legacyPrefix = $legacyDirectory === false
                ? ''
                : rtrim($legacyDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($path === false || strpos($path, $legacyPrefix) !== 0 || !is_file($path)) {
                Response::error("Agent image not found", 404);
                return;
            }
        }

        if (!is_file($path)) {
            Response::error("Agent image not found", 404);
            return;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            Response::error("Unsupported agent image format", 415);
            return;
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
