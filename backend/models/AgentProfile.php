<?php

require_once __DIR__ . '/../config/database.php';

class AgentProfile
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function get($userId)
    {
        $stmt = $this->db->prepare("
            SELECT u.full_name, u.email, u.role, ap.id, ap.user_id,
                   ap.verification_status, ap.trust_score,
                   ap.verification_confidence, ap.verification_distance,
                   ap.risk_level, ap.is_verified, ap.ela_variance,
                   ap.tamper_score, ap.tamper_flagged, ap.liveness_status,
                   CASE WHEN ap.ela_heatmap_path IS NOT NULL THEN 1 ELSE 0 END AS has_ela_heatmap
            FROM users u
            LEFT JOIN agent_profiles ap ON u.id = ap.user_id
            WHERE u.id = ?
        ");

        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    public function updateVerificationStatus($userId, $idImage, $selfieImage, $confidence, $status, $risk, $elaVariance, $tamperScore, $tamperFlagged, $distance, $heatmapPath, $livenessStatus)
    {
        $sql = "INSERT INTO agent_profiles 
            (user_id, national_id_path, selfie_path, verification_confidence, verification_status, is_verified, risk_level, ela_variance, tamper_score, tamper_flagged, verification_distance, ela_heatmap_path, liveness_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            national_id_path = VALUES(national_id_path),
            selfie_path = VALUES(selfie_path),
            verification_confidence = VALUES(verification_confidence),
            verification_status = VALUES(verification_status),
            risk_level = VALUES(risk_level),
            is_verified = VALUES(is_verified),
            ela_variance = VALUES(ela_variance),
            tamper_score = VALUES(tamper_score),
            tamper_flagged = VALUES(tamper_flagged),
            verification_distance = VALUES(verification_distance),
            ela_heatmap_path = VALUES(ela_heatmap_path),
            liveness_status = VALUES(liveness_status)";

        $isVerified = ($status === 'verified') ? 1 : 0;
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $userId,
            $idImage,
            $selfieImage,
            $confidence,
            $status,
            $isVerified,
            $risk,
            $elaVariance,
            $tamperScore,
            $tamperFlagged ? 1 : 0,
            $distance,
            $heatmapPath,
            $livenessStatus
        ]);
    }
}
