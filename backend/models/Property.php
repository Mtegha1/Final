<?php

require_once dirname(__DIR__) . '/config/database.php';

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

    public function getAllApproved()
    {
        $sql = "SELECT p.*, u.full_name, ap.trust_score
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
        $sql = "SELECT p.*, u.full_name as agent_name FROM properties p LEFT JOIN users u ON p.agent_id = u.id ORDER BY p.created_at DESC";
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
        $isFlagged = ($status === 'approved') ? 0 : 1;
        $sql = "UPDATE properties SET status = ?, is_flagged = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $isFlagged, $id]);
    }
}
