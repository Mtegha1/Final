<?php

class TrustScoreService
{
    private $db;

    private const WEIGHTS = [
        'identity' => 0.40,
        'price' => 0.25,
        'duplicate' => 0.20,
        'gps' => 0.15
    ];

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
    }

    public function calculateInitialScore($agentId, $propertyId, array $propertySignals)
    {
        $stmt = $this->db->prepare(
            "SELECT verification_confidence, tamper_score, tamper_flagged
             FROM agent_profiles WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$agentId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $calculation = $this->calculateRisk($identity, $propertySignals);

        $stmt = $this->db->prepare(
            "UPDATE properties
             SET risk_score = ?, risk_band = ?, risk_recommendation = ?, risk_signals = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $calculation['risk_score'],
            $calculation['risk_band'],
            $calculation['recommendation'],
            json_encode($calculation['signals'], JSON_THROW_ON_ERROR),
            $propertyId
        ]);

        $stmt = $this->db->prepare(
            "UPDATE agent_profiles SET trust_score = ?, risk_level = ? WHERE user_id = ?"
        );
        $stmt->execute([
            round((100 - $calculation['risk_score']) / 10, 1),
            $calculation['risk_band'],
            $agentId
        ]);

        return $calculation;
    }

    public function recalculateAgentProperties($agentId)
    {
        $stmt = $this->db->prepare(
            "SELECT id, risk_signals FROM properties WHERE agent_id = ?"
        );
        $stmt->execute([$agentId]);
        $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($properties as $property) {
            $savedSignals = json_decode($property['risk_signals'] ?? '', true) ?: [];
            $this->calculateInitialScore($agentId, $property['id'], [
                'price_deviation_pct' => $savedSignals['price']['deviation_pct'] ?? 0,
                'price_flagged' => $savedSignals['price']['flagged'] ?? false,
                'duplicate_image_flagged' => $savedSignals['duplicate']['flagged'] ?? false,
                'gps_mismatch_distance_m' => $savedSignals['gps']['mismatch_distance_m'] ?? null,
                'gps_flagged' => $savedSignals['gps']['flagged'] ?? false
            ]);
        }
    }

    public function calculateRisk(array $identity, array $propertySignals)
    {
        $confidence = isset($identity['verification_confidence'])
            ? max(0, min(100, (float)$identity['verification_confidence']))
            : 0.0;
        $tamperScore = max(0, min(100, (float)($identity['tamper_score'] ?? 0)));
        $tamperFlagged = !empty($identity['tamper_flagged']);
        $identityRisk = $tamperFlagged
            ? 100.0
            : min(100.0, (100 - $confidence) + $tamperScore);

        $priceDeviation = max(0, min(100, (float)($propertySignals['price_deviation_pct'] ?? 0)));
        $priceFlagged = !empty($propertySignals['price_flagged']);
        $priceRisk = $priceDeviation;

        $duplicateFlagged = !empty($propertySignals['duplicate_image_flagged']);
        $duplicateRisk = $duplicateFlagged ? 100.0 : 0.0;

        $gpsDistance = isset($propertySignals['gps_mismatch_distance_m'])
            ? max(0, (float)$propertySignals['gps_mismatch_distance_m'])
            : null;
        $gpsFlagged = !empty($propertySignals['gps_flagged']);
        $gpsRisk = $gpsFlagged
            ? ($gpsDistance === null ? 100.0 : min(100.0, max(30.0, $gpsDistance)))
            : 0.0;

        $signals = [
            'identity' => [
                'verification_confidence' => $confidence,
                'tamper_score' => $tamperScore,
                'tamper_flagged' => $tamperFlagged,
                'risk_score' => round($identityRisk, 2),
                'weight' => self::WEIGHTS['identity']
            ],
            'price' => [
                'deviation_pct' => round($priceDeviation, 2),
                'flagged' => $priceFlagged,
                'risk_score' => round($priceRisk, 2),
                'weight' => self::WEIGHTS['price']
            ],
            'duplicate' => [
                'flagged' => $duplicateFlagged,
                'risk_score' => round($duplicateRisk, 2),
                'weight' => self::WEIGHTS['duplicate']
            ],
            'gps' => [
                'mismatch_distance_m' => $gpsDistance,
                'flagged' => $gpsFlagged,
                'risk_score' => round($gpsRisk, 2),
                'weight' => self::WEIGHTS['gps']
            ]
        ];

        $riskScore = round(
            $identityRisk * self::WEIGHTS['identity'] +
            $priceRisk * self::WEIGHTS['price'] +
            $duplicateRisk * self::WEIGHTS['duplicate'] +
            $gpsRisk * self::WEIGHTS['gps'],
            2
        );

        if ($riskScore < 25) {
            $riskBand = 'low';
            $recommendation = 'auto_approve';
        } elseif ($riskScore < 60) {
            $riskBand = 'medium';
            $recommendation = 'manual_review';
        } else {
            $riskBand = 'high';
            $recommendation = 'auto_flag';
        }

        return [
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
            'recommendation' => $recommendation,
            'signals' => $signals
        ];
    }
}
