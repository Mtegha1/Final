<?php

require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/TrustScoreService.php';

class PropertyController
{
    private $propertyModel;

    public function __construct()
    {
        $this->propertyModel = new Property();
    }

    public function getApprovedProperties()
    {
        try {
            $data = $this->propertyModel->getAllApproved();
            Response::success($data);
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function getAllProperties()
    {
        try {
            $data = $this->propertyModel->getAll();
            Response::success($data);
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function createProperty()
    {
        Session::start();
        $userId = Session::get('user_id');
        if (!$userId) {
            Response::error("Unauthorized", 401);
            return;
        }

        $isDuplicate = false;
        $targetFile  = null;

        try {
            // 1. Validate file upload
            if (!isset($_FILES['property_image']) || $_FILES['property_image']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
                ];
                $code    = $_FILES['property_image']['error'] ?? UPLOAD_ERR_NO_FILE;
                $message = $uploadErrors[$code] ?? 'Unknown upload error.';
                Response::error("Property image upload failed: {$message}");
                return;
            }

            // 2. Validate MIME type (don't trust $_FILES['type'] alone — check the actual file)
            $allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            $finfo       = new finfo(FILEINFO_MIME_TYPE);
            $realMime    = $finfo->file($_FILES['property_image']['tmp_name']);
            if (!in_array($realMime, $allowedMime, true)) {
                Response::error("Invalid image type. Only JPG, PNG, and WEBP are allowed.");
                return;
            }

            // 3. Ensure upload directory exists
            $uploadDir = __DIR__ . '/../../uploads/properties/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                Response::error("Server error: could not create upload directory.");
                return;
            }

            // 4. Move uploaded file with a sanitised, unique filename
            $ext        = pathinfo($_FILES['property_image']['name'], PATHINFO_EXTENSION);
            $fileName   = time() . '_' . $userId . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            $targetFile = $uploadDir . $fileName;

            if (!move_uploaded_file($_FILES['property_image']['tmp_name'], $targetFile)) {
                Response::error("Failed to save the uploaded image. Check server permissions.");
                return;
            }

            // 5. Generate perceptual hash via Python
            require_once __DIR__ . '/../services/ImageHashService.php';
            $imageHash = null;
            try {
                $imageHash = ImageHashService::generateHash($targetFile);
            } catch (Throwable $hashErr) {
                // Hash generation failed — log but don't block the listing.
                error_log("ImageHashService failed: " . $hashErr->getMessage());
            }

            // 6. Duplicate image check — only when we have a valid hash.

            if ($imageHash !== null) {
                $db = Database::getInstance()->conn;
                $hashStmt = $db->prepare(
                    "SELECT id, image_hash FROM properties WHERE image_hash IS NOT NULL AND image_hash != ''"
                );
                $hashStmt->execute();
                $existingHashes = $hashStmt->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($existingHashes as $existing) {
                    if (ImageHashService::areDuplicates($imageHash, $existing['image_hash'])) {
                        error_log("Duplicate image detected. New hash: {$imageHash}, matched property ID: {$existing['id']}");
                        $isDuplicate = true;
                        break;
                    }
                }
            }

            // 7. Prepare data for database
            $data = [
                'agent_id'      => $userId,
                'title'         => trim($_POST['title'] ?? ''),
                'description'   => trim($_POST['description'] ?? ''),
                'price'         => floatval($_POST['price'] ?? 0),
                'property_type' => trim($_POST['property_type'] ?? ''),
                'area_name'     => trim($_POST['area_name'] ?? ''),
                'latitude'      => isset($_POST['latitude']) && $_POST['latitude'] !== '' ? floatval($_POST['latitude']) : null,
                'longitude'     => isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null,
                'image_hash'    => $imageHash,
                'image_url'     => $fileName,
                'status'        => 'pending',
            ];

            // Validate required fields
            foreach (['title', 'description', 'property_type', 'area_name'] as $field) {
                if (empty($data[$field])) {
                    @unlink($targetFile);
                    Response::error("Field '{$field}' is required.");
                    return;
                }
            }
            if ($data['price'] <= 0) {
                @unlink($targetFile);
                Response::error("Price must be greater than zero.");
                return;
            }

            // 8. Insert into database
            $newId = $this->propertyModel->create($data);

            if (!$newId) {
                @unlink($targetFile);
                Response::error("Database error: failed to save the listing.");
                return;
            }

            $db = Database::getInstance()->conn;

            // 9. Collect fraud signals without changing the listing's pending status.

            require_once __DIR__ . '/../services/FraudDetectionService.php';
            $data['property_id'] = $newId;
            $fraudService = new FraudDetectionService();
            $fraudResult  = $fraudService->analyze($data, $isDuplicate);

            // Keep flags visible to admins; the risk recommendation is not a rejection.
            if (!empty($fraudResult['status']) && $fraudResult['status'] === 'flagged') {
                $db->prepare("UPDATE properties SET is_flagged = 1 WHERE id = ?")->execute([$newId]);
            }

            $riskResult = (new TrustScoreService())->calculateInitialScore(
                $userId,
                $newId,
                $fraudResult['signals']
            );

            Response::success([
                "message"      => "Listing created successfully",
                "property_id"  => $newId,
                "hash"         => $imageHash,
                "fraud_status" => $fraudResult['status'] ?? 'unknown',
                "fraud_issues" => $fraudResult['issues'] ?? [],
                "is_duplicate" => $isDuplicate,
                "risk_score" => $riskResult['risk_score'],
                "risk_band" => $riskResult['risk_band'],
                "risk_recommendation" => $riskResult['recommendation'],
                "risk_signals" => $riskResult['signals'],
            ]);
        } catch (Throwable $e) {
            if ($targetFile !== null && file_exists($targetFile)) {
                @unlink($targetFile);
            }
            error_log("PropertyController::createProperty error: " . $e->getMessage());
            Response::error("Server error: " . $e->getMessage(), 500);
        }
    }

    public function getAdminStats()
    {
        Session::start();
        if (!Session::get('user_id')) {
            Response::error("Unauthorized", 401);
            return;
        }

        try {
            $db = Database::getInstance()->conn;

            $pending = $db->query("SELECT COUNT(*) FROM properties WHERE status = 'pending'")->fetchColumn();
            $flagged = $db->query("SELECT COUNT(*) FROM properties WHERE is_flagged = 1")->fetchColumn();
            $agents  = $db->query("SELECT COUNT(*) FROM users WHERE role = 'agent'")->fetchColumn();

            Response::success([
                "pending" => (int)$pending,
                "flagged" => (int)$flagged,
                "agents"  => (int)$agents,
            ]);
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function getPropertyById()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id    = $input['id'] ?? null;

        if (!$id) {
            Response::error("Property ID is required", 400);
            return;
        }

        try {
            $property = $this->propertyModel->getById($id);
            if ($property) {
                Response::success($property);
            } else {
                Response::error("Property not found", 404);
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage(), 500);
        }
    }

    public function updateProperty()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id    = $input['id'] ?? null;

        if (!$id) {
            Response::error("Missing ID");
            return;
        }

        try {
            $success = $this->propertyModel->update([
                'id'            => $id,
                'title'         => $input['title'] ?? '',
                'description'   => $input['description'] ?? '',
                'price'         => $input['price'] ?? 0,
                'property_type' => $input['property_type'] ?? '',
                'area_name'     => $input['area_name'] ?? '',
                'latitude'      => $input['latitude'] ?? null,
                'longitude'     => $input['longitude'] ?? null,
                'status'        => $input['status'] ?? 'pending',
            ]);

            if ($success) {
                Response::success([], "Property updated");
            } else {
                Response::error("Property update failed");
            }
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function changePropertyStatus()
    {
        Session::start();
        if (Session::get('role') !== 'admin') {
            Response::error("Unauthorized: Admin access required", 403);
            return;
        }

        $input  = json_decode(file_get_contents("php://input"), true);
        $id     = $input['id'] ?? null;
        $status = $input['status'] ?? null;

        if (!$id || !$status) {
            Response::error("Invalid request parameters");
            return;
        }

        try {
            if (!in_array($status, ['pending', 'approved', 'rejected', 'blocked'], true)) {
                Response::error("Invalid property status", 400);
                return;
            }

            if (!$this->propertyModel->updateStatus($id, $status)) {
                Response::error("Failed to update property status", 500);
                return;
            }

            if (in_array($status, ['approved', 'rejected'], true)) {
                $db = Database::getInstance()->conn;
                $audit = $db->prepare(
                    "INSERT INTO audit_logs (user_id, action, logged_at) VALUES (?, ?, CURRENT_TIMESTAMP)"
                );
                $audit->execute([
                    Session::get('user_id'),
                    "Property {$id} {$status}"
                ]);
            }
            Response::success([], "Status updated to " . $status);
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }

    public function deleteProperty()
    {
        $input = json_decode(file_get_contents("php://input"), true);
        $id    = $input['id'] ?? null;

        if (!$id) {
            Response::error("Property ID is required");
            return;
        }

        try {
            $db   = Database::getInstance()->conn;
            $stmt = $db->prepare("DELETE FROM properties WHERE id = ?");
            $stmt->execute([$id]);
            Response::success([], "Property deleted");
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }
}
