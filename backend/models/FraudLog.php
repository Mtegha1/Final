<?php

require_once dirname(__DIR__) . '/config/database.php';

class FraudLog
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
    }

    public function create(
        $propertyId,
        $agentId,
        string $type,
        string $message,
        bool $flagged,
        array $details = [],
        $matchedPropertyId = null
    ): bool {
        $stmt = $this->db->prepare(
            'INSERT INTO fraud_logs
             (property_id, agent_id, type, message, flagged, details, matched_property_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        return $stmt->execute([
            $propertyId,
            $agentId,
            $type,
            $message,
            $flagged ? 1 : 0,
            json_encode($details, JSON_THROW_ON_ERROR),
            $matchedPropertyId
        ]);
    }

    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT fl.*, p.title AS property_title, p.risk_score, p.risk_band,
                    p.risk_recommendation, p.risk_signals, u.full_name AS agent_name
             FROM fraud_logs fl
             LEFT JOIN properties p ON p.id = fl.property_id
             LEFT JOIN users u ON u.id = fl.agent_id
             WHERE fl.flagged = 1
             ORDER BY COALESCE(p.risk_score, 100) DESC, fl.created_at DESC'
        );
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logs as &$log) {
            $log['details'] = $log['details'] === null
                ? null
                : json_decode($log['details'], true, 512, JSON_THROW_ON_ERROR);
            $log['risk_signals'] = $log['risk_signals'] === null
                ? null
                : json_decode($log['risk_signals'], true, 512, JSON_THROW_ON_ERROR);
            $log['flagged'] = (bool)$log['flagged'];
        }
        return $logs;
    }

    public function logIdentityRisk(
        int $agentId,
        string $message,
        bool $flagged,
        array $details
    ): bool {
        return $this->create(null, $agentId, 'identity', $message, $flagged, $details);
    }
}
