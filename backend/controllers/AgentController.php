<?php

require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/AgentProfile.php';
require_once __DIR__ . '/../services/FaceVerificationService.php';
require_once __DIR__ . '/../services/TrustScoreService.php';
require_once __DIR__ . '/../services/GPSVerificationService.php';
require_once __DIR__ . '/../config/identity_storage.php';
require_once __DIR__ . '/../config/FraudConfig.php';

class AgentController
{
    private $agentModel;
    private $faceService;
    private $trustService;

    public function __construct()
    {
        $this->agentModel = new AgentProfile();
        $this->faceService = new FaceVerificationService();
        $this->trustService = new TrustScoreService();
    }


    public function verifyIdentity()
    {
        Session::start();
        $userId = Session::get('user_id');

        if (!$userId) {
            Response::error("Unauthorized session", 401);
            return;
        }

        if (!isset($_FILES['id_image'], $_FILES['selfie'])) {
            Response::error("Both the National ID image and selfie are required.");
            return;
        }

        $files = [$_FILES['id_image'], $_FILES['selfie']];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                Response::error($this->uploadErrorMessage($file['error']), 400);
                return;
            }
            $maxBytes = (int)FraudConfig::section('identity')['maximum_upload_bytes'];
            if ($file['size'] > $maxBytes) {
                $maxMegabytes = (int)ceil($maxBytes / 1048576);
                Response::error("Each image must be {$maxMegabytes} MB or smaller.", 400);
                return;
            }

            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                Response::error("Only valid JPG and PNG images are accepted.", 400);
                return;
            }
        }

        $savedPaths = [];
        try {
            $uploadDir = identity_upload_directory();
            $token = bin2hex(random_bytes(16));
            $idPath = $uploadDir . DIRECTORY_SEPARATOR . 'id_' . $token . '.jpg';
            $selfiePath = $uploadDir . DIRECTORY_SEPARATOR . 'selfie_' . $token . '.jpg';
            $heatmapPath = $uploadDir . DIRECTORY_SEPARATOR . 'ela_' . $token . '.jpg';

            foreach ([
                ['file' => $_FILES['id_image'], 'path' => $idPath],
                ['file' => $_FILES['selfie'], 'path' => $selfiePath],
            ] as $upload) {
                if (!move_uploaded_file($upload['file']['tmp_name'], $upload['path'])) {
                    throw new RuntimeException("The uploaded images could not be saved securely.");
                }
                $savedPaths[] = $upload['path'];
            }

            $result = $this->faceService->verify($userId, $idPath, $selfiePath, $heatmapPath);
            if ($result['status'] === 'error') {
                foreach (array_merge($savedPaths, [$heatmapPath]) as $path) {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
                Response::error($result['reason'], 422);
                return;
            }

            Response::success($result, "Identity verification processed.");
        } catch (Throwable $e) {
            foreach (array_merge($savedPaths, isset($heatmapPath) ? [$heatmapPath] : []) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            error_log("Identity verification setup or persistence failed.");
            Response::error("Identity verification could not be completed. Please retry or contact an administrator.", 500);
        }
    }

    private function uploadErrorMessage($code)
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'An image exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'An image exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'An image upload was incomplete. Please retry.',
            UPLOAD_ERR_NO_FILE => 'Both ID and selfie images are required.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save an uploaded image.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the image upload.',
        ];
        return $messages[$code] ?? 'An image upload failed. Please retry.';
    }

    /**
     * Endpoint: action=dashboard
     * Aggregates profile, stats, and listings for the agent UI
     */
    public function getDashboard()
    {
        Session::start();
        $userId = Session::get('user_id');

        if (!$userId) {
            Response::error("Unauthorized", 401);
            return;
        }

        try {
            $db = Database::getInstance()->conn;

            // 1. Fetch User details
            $userQuery = "SELECT full_name, email FROM users WHERE id = ? AND role = 'agent'";
            $stmt = $db->prepare($userQuery);
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                Response::error("Agent record not found.", 404);
                return;
            }

            // 2. Fetch Agent Profile (ID paths, Trust Score, Risk Level)
            $profile = $this->agentModel->get($userId);

            // 3. Fetch Recent Listings
            $listingQuery = "SELECT id, title, area_name, price, status, created_at 
                             FROM properties 
                             WHERE agent_id = ? 
                             ORDER BY created_at DESC LIMIT 10";
            $stmt = $db->prepare($listingQuery);
            $stmt->execute([$userId]);
            $listings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // 4. Calculate Stats
            $statsQuery = "SELECT 
                            COUNT(*) as total_listings, 
                            SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_listings,
                            SUM(CASE WHEN is_flagged=1 THEN 1 ELSE 0 END) as flagged_listings
                           FROM properties WHERE agent_id = ?";
            $stmt = $db->prepare($statsQuery);
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                "total_listings" => 0,
                "approved_listings" => 0,
                "flagged_listings" => 0
            ];

            Response::success([
                "user" => $user,
                "profile" => $profile,
                "listings" => $listings,
                "stats" => $stats,
                "zones" => (new GPSVerificationService($db))->getActiveZones()
            ]);
        } catch (Throwable $e) {
            Response::error("Dashboard Error: " . $e->getMessage());
        }
    }
}
