<?php

require_once __DIR__ . '/../models/Property.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/TrustScoreService.php';
require_once __DIR__ . '/../services/GPSVerificationService.php';
require_once __DIR__ . '/../services/FraudDetectionService.php';
require_once __DIR__ . '/../config/FraudConfig.php';

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

    public function uploadAdminPropertyPhotos(): void
    {
        Session::start();
        if (!Session::hasRole('admin')) {
            Response::error('Admin access required', 403);
            return;
        }

        $propertyId = filter_var($_POST['property_id'] ?? null, FILTER_VALIDATE_INT);
        $uploadedItems = $this->collectPropertyUploads();
        if (!$propertyId || !$uploadedItems) {
            Response::error('A property and at least one photo are required.', 400);
            return;
        }

        $savedFiles = [];
        $db = null;
        try {
            $imageConfig = FraudConfig::section('property_images');
            $maximumPhotos = (int)$imageConfig['maximum_photo_count'];
            $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($uploadedItems as &$item) {
                if ($item['error'] !== UPLOAD_ERR_OK) {
                    Response::error($this->uploadErrorMessage($item['error']), 400);
                    return;
                }
                if ($item['size'] > (int)$imageConfig['maximum_upload_bytes']) {
                    $maxMegabytes = (int)ceil((int)$imageConfig['maximum_upload_bytes'] / 1048576);
                    Response::error("Each property photo must be {$maxMegabytes} MB or smaller.", 400);
                    return;
                }
                $mime = $finfo->file($item['tmp_name']);
                if (!isset($allowedMime[$mime])) {
                    Response::error('Only valid JPG, PNG, and WEBP property photos are accepted.', 400);
                    return;
                }
                $item['extension'] = $allowedMime[$mime];
            }
            unset($item);

            $db = Database::getInstance()->conn;
            $db->beginTransaction();
            $propertyStmt = $db->prepare(
                "SELECT id, image_url, status FROM properties WHERE id = ? FOR UPDATE"
            );
            $propertyStmt->execute([$propertyId]);
            $property = $propertyStmt->fetch(PDO::FETCH_ASSOC);
            if (!$property || !in_array($property['status'], ['draft', 'pending'], true)) {
                $db->rollBack();
                Response::error('Photos can only be added to a draft or pending listing.', 400);
                return;
            }

            $galleryCount = $db->prepare('SELECT COUNT(*) FROM property_images WHERE property_id = ?');
            $galleryCount->execute([$propertyId]);
            $existingCount = (int)$galleryCount->fetchColumn() + ($property['image_url'] ? 1 : 0);
            if ($existingCount + count($uploadedItems) > $maximumPhotos) {
                $db->rollBack();
                Response::error("A listing can include at most {$maximumPhotos} photos.", 400);
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/properties/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                throw new RuntimeException('Could not create the property photo directory.');
            }
            require_once __DIR__ . '/../services/ImageHashService.php';
            foreach ($uploadedItems as $index => $item) {
                $filename = bin2hex(random_bytes(16)) . '.' . $item['extension'];
                $targetFile = $uploadDir . $filename;
                if (!move_uploaded_file($item['tmp_name'], $targetFile)) {
                    throw new RuntimeException('A property photo could not be saved.');
                }
                $savedFiles[] = $targetFile;
                $hash = null;
                try {
                    $hash = ImageHashService::generateHash($targetFile);
                } catch (Throwable $hashError) {
                    error_log('Property image hash generation failed for admin photo ' . ($index + 1) . ': ' . $hashError->getMessage());
                }
                $uploadedItems[$index]['filename'] = $filename;
                $uploadedItems[$index]['hash'] = $hash;
            }

            $nextImageIndex = 0;
            if (!$property['image_url']) {
                $firstImage = $uploadedItems[0];
                $this->propertyModel->setMainImage(
                    (int)$propertyId,
                    $firstImage['filename'],
                    $firstImage['hash']
                );
                $nextImageIndex = 1;
            }
            if ($nextImageIndex < count($uploadedItems)) {
                $this->propertyModel->addImages(
                    (int)$propertyId,
                    array_slice($uploadedItems, $nextImageIndex)
                );
            }

            $audit = $db->prepare(
                'INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $audit->execute([
                Session::get('user_id'),
                "Property {$propertyId} photos added",
                'updated',
                count($uploadedItems) . ' photo(s) added.'
            ]);
            $db->commit();
            Response::success(
                ['added' => count($uploadedItems)],
                count($uploadedItems) . ' photo(s) added to the listing.'
            );
        } catch (Throwable $error) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($savedFiles as $savedFile) {
                if (is_file($savedFile)) {
                    unlink($savedFile);
                }
            }
            error_log('Admin property photo upload failed: ' . $error->getMessage());
            Response::error('The property photos could not be saved.', 500);
        }
    }

    public function getAllProperties()
    {
        Session::start();
        if (!Session::hasRole('admin')) {
            Response::error("Admin access required", 403);
            return;
        }

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
        if (!$userId || !Session::hasRole('agent')) {
            Response::error("Agent access required", 403);
            return;
        }

        $savedFiles = [];
        $db = null;

        try {
            $uploadedItems = $this->collectPropertyUploads();
            if (!$uploadedItems) {
                Response::error("A main property image is required.", 400);
                return;
            }
            $imageConfig = FraudConfig::section('property_images');
            if (count($uploadedItems) > (int)$imageConfig['maximum_photo_count']) {
                Response::error(
                    "A listing can include at most " . (int)$imageConfig['maximum_photo_count'] . " photos.",
                    400
                );
                return;
            }

            $data = [
                'agent_id' => (int)$userId,
                'title' => trim((string)($_POST['title'] ?? '')),
                'description' => trim((string)($_POST['description'] ?? '')),
                'price' => filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT),
                'property_type' => trim((string)($_POST['property_type'] ?? '')),
                'area_name' => trim((string)($_POST['area_name'] ?? '')),
                'latitude' => filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT),
                'longitude' => filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT),
                'status' => 'pending'
            ];
            foreach (['title', 'description', 'property_type', 'area_name'] as $field) {
                if ($data[$field] === '') {
                    Response::error("Field '{$field}' is required.");
                    return;
                }
            }
            if ($data['price'] === false || $data['price'] <= 0) {
                Response::error("Monthly rent must be greater than zero.", 400);
                return;
            }
            if (
                $data['latitude'] === false ||
                $data['longitude'] === false ||
                $data['latitude'] < -90 ||
                $data['latitude'] > 90 ||
                $data['longitude'] < -180 ||
                $data['longitude'] > 180
            ) {
                Response::error("Select an area and set a valid property pin.", 400);
                return;
            }
            $gpsService = new GPSVerificationService();
            $activeZones = $gpsService->getActiveZones();
            $knownZone = false;
            foreach ($activeZones as $zone) {
                if ($zone['area_name'] === $data['area_name']) {
                    $knownZone = true;
                    break;
                }
            }
            if (!$knownZone) {
                Response::error("Select one of the currently supported Blantyre areas.", 400);
                return;
            }

            $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            foreach ($uploadedItems as &$item) {
                if ($item['error'] !== UPLOAD_ERR_OK) {
                    Response::error($this->uploadErrorMessage($item['error']), 400);
                    return;
                }
                if ($item['size'] > (int)$imageConfig['maximum_upload_bytes']) {
                    $maxMegabytes = (int)ceil((int)$imageConfig['maximum_upload_bytes'] / 1048576);
                    Response::error("Each property photo must be {$maxMegabytes} MB or smaller.", 400);
                    return;
                }
                $mime = $finfo->file($item['tmp_name']);
                if (!isset($allowedMime[$mime])) {
                    Response::error("Only valid JPG, PNG, and WEBP property photos are accepted.", 400);
                    return;
                }
                $item['mime'] = $mime;
                $item['extension'] = $allowedMime[$mime];
            }
            unset($item);

            $uploadDir = __DIR__ . '/../../uploads/properties/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                Response::error("Server error: could not create upload directory.");
                return;
            }

            require_once __DIR__ . '/../services/ImageHashService.php';
            foreach ($uploadedItems as $index => $item) {
                $fileName = bin2hex(random_bytes(16)) . '.' . $item['extension'];
                $targetFile = $uploadDir . $fileName;
                if (!move_uploaded_file($item['tmp_name'], $targetFile)) {
                    throw new RuntimeException('An uploaded property photo could not be saved.');
                }
                $savedFiles[] = $targetFile;
                $imageHash = null;
                try {
                    $imageHash = ImageHashService::generateHash($targetFile);
                } catch (Throwable $hashError) {
                    error_log('Property image hash generation failed for photo ' . ($index + 1) . ': ' . $hashError->getMessage());
                }
                $uploadedItems[$index]['filename'] = $fileName;
                $uploadedItems[$index]['hash'] = $imageHash;
            }

            $data['image_hash'] = $uploadedItems[0]['hash'];
            $data['image_url'] = $uploadedItems[0]['filename'];
            $db = Database::getInstance()->conn;
            $db->beginTransaction();
            $newId = $this->propertyModel->create($data);
            if (!$newId) {
                throw new RuntimeException('Listing could not be saved.');
            }

            if (count($uploadedItems) > 1) {
                $this->propertyModel->addImages($newId, array_slice($uploadedItems, 1));
            }
            $data['property_id'] = $newId;
            $data['image_hashes'] = array_map(
                static function (array $item): ?string {
                    return $item['hash'];
                },
                $uploadedItems
            );
            $fraudResult = (new FraudDetectionService($db))->analyze($data);
            $riskResult = (new TrustScoreService())->calculateInitialScore(
                $userId,
                $newId,
                $fraudResult['signals']
            );
            $db->commit();

            Response::success([
                "message"      => "Listing created successfully",
                "property_id"  => $newId,
                "fraud_status" => $fraudResult['status'] ?? 'unknown',
                "fraud_issues" => $fraudResult['issues'] ?? [],
                "risk_score" => $riskResult['risk_score'],
                "risk_band" => $riskResult['risk_band'],
                "risk_recommendation" => $riskResult['recommendation'],
                "listing_status" => $riskResult['listing_status'],
                "risk_signals" => $riskResult['signals']
            ]);
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($savedFiles as $savedFile) {
                if (is_file($savedFile)) {
                    unlink($savedFile);
                }
            }
            error_log("PropertyController::createProperty error: " . $e->getMessage());
            Response::error("The listing could not be processed. Please retry or contact an administrator.", 500);
        }
    }

    private function collectPropertyUploads(): array
    {
        if (
            !isset($_FILES['property_image']) ||
            !is_array($_FILES['property_image']) ||
            (int)($_FILES['property_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
        ) {
            return [];
        }

        $items = [[
                'tmp_name' => $_FILES['property_image']['tmp_name'] ?? '',
                'size' => (int)($_FILES['property_image']['size'] ?? 0),
                'error' => (int)($_FILES['property_image']['error'] ?? UPLOAD_ERR_NO_FILE)
            ]];

        if (isset($_FILES['property_gallery']) && is_array($_FILES['property_gallery']['name'] ?? null)) {
            $gallery = $_FILES['property_gallery'];
            foreach ($gallery['name'] as $index => $unusedName) {
                $error = (int)($gallery['error'][$index] ?? UPLOAD_ERR_NO_FILE);
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $items[] = [
                    'tmp_name' => $gallery['tmp_name'][$index] ?? '',
                    'size' => (int)($gallery['size'][$index] ?? 0),
                    'error' => $error
                ];
            }
        }

        return $items;
    }

    private function uploadErrorMessage(int $code): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'A photo exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'A photo exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'A photo upload was incomplete.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server temporary upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save a photo.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked a photo upload.'
        ];
        return $messages[$code] ?? 'A property photo upload failed.';
    }

    public function getAdminStats()
    {
        Session::start();
        if (!Session::hasRole('admin')) {
            Response::error("Admin access required", 403);
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
                Session::start();
                $userId = Session::get('user_id');
                $currentRole = Session::currentRole();
                if (
                    $property['status'] !== 'approved' &&
                    $currentRole !== 'admin' &&
                    ($currentRole !== 'agent' || (int)$property['agent_id'] !== (int)$userId)
                ) {
                    Response::error("Property not found", 404);
                    return;
                }
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
            Session::start();
            $isAdmin = Session::hasRole('admin');
            if (!$isAdmin) {
                Response::error("Admin access required", 403);
                return;
            }
            $property = $this->propertyModel->getById($id);
            if (!$property) {
                Response::error("Property not found", 404);
                return;
            }

            $priceInput = trim((string)($input['price'] ?? ''));
            $latitudeInput = trim((string)($input['latitude'] ?? ''));
            $longitudeInput = trim((string)($input['longitude'] ?? ''));
            $price = $priceInput === '' ? null : filter_var($priceInput, FILTER_VALIDATE_FLOAT);
            $latitude = $latitudeInput === '' ? null : filter_var($latitudeInput, FILTER_VALIDATE_FLOAT);
            $longitude = $longitudeInput === '' ? null : filter_var($longitudeInput, FILTER_VALIDATE_FLOAT);
            if (
                ($priceInput !== '' && ($price === false || $price <= 0)) ||
                ($latitudeInput !== '' && ($latitude === false || $latitude < -90 || $latitude > 90)) ||
                ($longitudeInput !== '' && ($longitude === false || $longitude < -180 || $longitude > 180)) ||
                (($latitude === null) !== ($longitude === null))
            ) {
                Response::error('Enter a positive monthly rent and valid coordinate pair, or leave draft fields blank.', 400);
                return;
            }

            $success = $this->propertyModel->update([
                'id'            => $id,
                'title'         => $input['title'] ?? '',
                'description'   => $input['description'] ?? '',
                'price'         => $price,
                'property_type' => trim((string)($input['property_type'] ?? '')) ?: null,
                'area_name'     => trim((string)($input['area_name'] ?? '')) ?: null,
                'latitude'      => $latitude,
                'longitude'     => $longitude,
                'status'        => $property['status'],
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
        if (!Session::hasRole('admin')) {
            Response::error("Unauthorized: Admin access required", 403);
            return;
        }

        $input  = json_decode(file_get_contents("php://input"), true);
        $id     = $input['id'] ?? null;
        $status = $input['status'] ?? null;
        $note = trim((string)($input['note'] ?? ''));

        if (!$id || !$status || strlen($note) > 200) {
            Response::error("Invalid request parameters");
            return;
        }

        try {
            if (!in_array($status, ['draft', 'pending', 'approved', 'rejected', 'blocked'], true)) {
                Response::error("Invalid property status", 400);
                return;
            }

            $db = Database::getInstance()->conn;
            $db->beginTransaction();
            $exists = $db->prepare('SELECT id, created_by FROM properties WHERE id = ? FOR UPDATE');
            $exists->execute([$id]);
            $property = $exists->fetch(PDO::FETCH_ASSOC);
            if (!$property) {
                $db->rollBack();
                Response::error("Property not found", 404);
                return;
            }
            if ($status === 'approved' && $property['created_by'] !== null) {
                $blockers = $this->propertyModel->getApprovalBlockers((int)$id);
                if ($blockers) {
                    $db->rollBack();
                    Response::error(
                        'This admin-created draft is not ready for publication: ' . implode(' ', $blockers),
                        400
                    );
                    return;
                }
            }
            if (!$this->propertyModel->updateStatus($id, $status)) {
                throw new RuntimeException('Property status update failed.');
            }
            if (in_array($status, ['approved', 'rejected'], true)) {
                $audit = $db->prepare(
                    'INSERT INTO audit_logs (user_id, action, decision, note, logged_at)
                     VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $audit->execute([
                    Session::get('user_id'),
                    "Property {$id} {$status}",
                    $status,
                    $note ?: null
                ]);
            }
            $db->commit();
            Response::success([], "Status updated to " . $status);
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Property status update failed: ' . $e->getMessage());
            Response::error("Property status could not be updated.", 500);
        }
    }

    public function deleteProperty()
    {
        Session::start();
        $input = json_decode(file_get_contents("php://input"), true);
        $id    = $input['id'] ?? null;

        if (!$id) {
            Response::error("Property ID is required");
            return;
        }

        try {
            $db   = Database::getInstance()->conn;
            $currentRole = Session::currentRole();
            $stmt = $db->prepare('SELECT agent_id FROM properties WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $agentId = $stmt->fetchColumn();
            if (
                $agentId === false ||
                (
                    $currentRole !== 'admin' &&
                    ($currentRole !== 'agent' ||
                        (int)$agentId !== (int)Session::get('user_id'))
                )
            ) {
                Response::error("Property not found", 404);
                return;
            }
            $stmt = $db->prepare("DELETE FROM properties WHERE id = ?");
            $stmt->execute([$id]);
            Response::success([], "Property deleted");
        } catch (Throwable $e) {
            Response::error($e->getMessage());
        }
    }
}
