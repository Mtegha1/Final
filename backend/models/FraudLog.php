<?php

class FraudLog
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function create($propertyId, $agentId, $type, $message)
    {
        $sql = "INSERT INTO fraud_logs (property_id, agent_id, type, message)
                VALUES (?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            $propertyId,
            $agentId,
            $type,
            $message
        ]);
    }

    public function getAll()
    {
        $sql = "SELECT fl.*, p.title AS property_title, p.risk_score, p.risk_band,
                   p.risk_recommendation, p.risk_signals,
                   ap.verification_confidence, ap.ela_variance,
                   ap.tamper_score, ap.tamper_flagged
                FROM fraud_logs fl
                LEFT JOIN properties p ON p.id = fl.property_id
            LEFT JOIN agent_profiles ap ON ap.user_id = fl.agent_id
                ORDER BY fl.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$log) {
            $log['risk_signals'] = $log['risk_signals'] === null
                ? null
                : json_decode($log['risk_signals'], true);
            if ($log['type'] === 'identity' && $log['risk_signals'] === null) {
                $log['risk_signals'] = [
                    'identity' => [
                        'verification_confidence' => $log['verification_confidence'],
                        'ela_variance' => $log['ela_variance'],
                        'tamper_score' => $log['tamper_score'],
                        'tamper_flagged' => (bool)$log['tamper_flagged']
                    ],
                    'price' => null,
                    'duplicate' => null,
                    'gps' => null
                ];
            }
        }
        return $logs;
    }

    public function logIdentityRisk($agentId, $message)
    {
        return $this->create(
            null,
            $agentId,
            'identity',
            $message
        );
    }
}
