<?php

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/services/GPSVerificationService.php';

class Property
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function create($data)
    {
        $sql = "INSERT INTO properties 
        (agent_id, title, description, price, property_type,
         area_name, latitude, longitude, image_url, image_hash,
         status, is_flagged)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt->execute([
            $data['agent_id'],
            $data['title'],
            $data['description'],
            $data['price'],
            $data['property_type'],
            $data['area_name'],
            $data['latitude'],
            $data['longitude'],
            $data['image_url'] ?? null,
            $data['image_hash'],
            $data['status'] ?? 'pending',
            $data['is_flagged'] ?? 0
        ])) {
            return false;
        }

        return (int)$this->db->lastInsertId();
    }

    public function addImages(int $propertyId, array $images): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO property_images (property_id, image_path, image_hash) VALUES (?, ?, ?)'
        );
        foreach ($images as $image) {
            $stmt->execute([
                $propertyId,
                $image['filename'],
                $image['hash']
            ]);
        }
    }

    public function getAllApproved()
    {
        $sql = "SELECT p.*, u.full_name, ap.trust_score, ap.verification_status
                FROM properties p
                JOIN users u ON p.agent_id = u.id
                LEFT JOIN agent_profiles ap ON u.id = ap.user_id
                WHERE p.status = 'approved'
                ORDER BY p.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAll()
    {
        $sql = "SELECT p.*, u.full_name AS agent_name, creator.full_name AS creator_name
                FROM properties p
                LEFT JOIN users u ON p.agent_id = u.id
                LEFT JOIN users creator ON p.created_by = creator.id
                ORDER BY p.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $sql = "SELECT p.*, u.full_name as agent_name FROM properties p LEFT JOIN users u ON p.agent_id = u.id WHERE p.id = ? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($data)
    {
        $sql = "UPDATE properties SET title = ?, description = ?, price = ?, property_type = ?, area_name = ?, latitude = ?, longitude = ?, status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['title'],
            $data['description'],
            $data['price'],
            $data['property_type'],
            $data['area_name'],
            $data['latitude'],
            $data['longitude'],
            $data['status'],
            $data['id']
        ]);
    }

    public function updateStatus($id, $status)
    {
        $isFlagged = in_array($status, ['approved', 'draft'], true) ? 0 : 1;
        $sql = "UPDATE properties SET status = ?, is_flagged = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $isFlagged, $id]);
    }

    public function getApprovalBlockers(int $id): array
    {
        $property = $this->getById($id);
        if (!$property) {
            return ['Property not found.'];
        }
        if ($property['created_by'] === null) {
            return [];
        }

        $requiredFields = [
            'title' => 'a title',
            'description' => 'a description',
            'price' => 'a monthly rent',
            'property_type' => 'a property type',
            'area_name' => 'an area',
            'latitude' => 'a latitude pin',
            'longitude' => 'a longitude pin',
            'image_url' => 'at least one photo',
            'agent_id' => 'an assigned agent'
        ];
        $blockers = [];
        foreach ($requiredFields as $field => $label) {
            if ($property[$field] === null || trim((string)$property[$field]) === '') {
                $blockers[] = "Add {$label}.";
            }
        }
        if ($property['price'] !== null && (float)$property['price'] <= 0) {
            $blockers[] = 'Monthly rent must be greater than zero.';
        }
        if (
            $property['latitude'] !== null &&
            (!is_numeric($property['latitude']) || (float)$property['latitude'] < -90 || (float)$property['latitude'] > 90)
        ) {
            $blockers[] = 'Enter a valid latitude pin.';
        }
        if (
            $property['longitude'] !== null &&
            (!is_numeric($property['longitude']) || (float)$property['longitude'] < -180 || (float)$property['longitude'] > 180)
        ) {
            $blockers[] = 'Enter a valid longitude pin.';
        }
        if (
            is_numeric($property['latitude']) &&
            is_numeric($property['longitude']) &&
            (float)$property['latitude'] >= -90 &&
            (float)$property['latitude'] <= 90 &&
            (float)$property['longitude'] >= -180 &&
            (float)$property['longitude'] <= 180
        ) {
            $gps = (new GPSVerificationService($this->db))->validate(
                $property['latitude'],
                $property['longitude'],
                (string)$property['area_name']
            );
            if (!$gps['available'] || !$gps['valid']) {
                $blockers[] = $gps['reason'];
            }
        }
        return $blockers;
    }

    public function setMainImage(int $propertyId, string $filename, ?string $imageHash): void
    {
        $stmt = $this->db->prepare(
            'UPDATE properties SET image_url = ?, image_hash = ? WHERE id = ?'
        );
        $stmt->execute([$filename, $imageHash, $propertyId]);
    }
}
