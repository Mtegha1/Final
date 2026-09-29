<?php

require_once dirname(__DIR__) . '/config/database.php';

class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function register($name, $email, $password, $role)
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$name, $email, $hash, $role])) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    public function authenticate($email, $password)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        return false;
    }

    public function getUserById($id)
    {
        $stmt = $this->db->prepare("SELECT id, full_name, email, role, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function exists($email)
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch() ? true : false;
    }

    public function getAll()
    {
        $stmt = $this->db->prepare("SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAgentsWithProfile()
    {
        $stmt = $this->db->prepare("SELECT u.id AS user_id, u.full_name, u.email, u.created_at, u.role, ap.national_id_path, ap.selfie_path, ap.verification_status, ap.trust_score, ap.verification_confidence, ap.ela_variance, ap.tamper_score, ap.tamper_flagged, ap.risk_level, CASE WHEN ap.verification_status = 'verified' THEN 1 ELSE 0 END AS is_verified FROM users u LEFT JOIN agent_profiles ap ON u.id = ap.user_id WHERE u.role = 'agent' ORDER BY u.created_at DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
