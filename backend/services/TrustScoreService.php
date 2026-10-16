<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/FraudConfig.php';

class TrustScoreService
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
    }

    public function calculateInitialScore($agentId, $propertyId, array $propertySignals)
    {
        $stmt = $this->db->prepare(
            'SELECT verification_status, verification_confidence, tamper_score, tamper_flagged
             FROM agent_profiles WHERE user_id = ? LIMIT 1'
        );
        $stmt->execute([$agentId]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $calculation = self::calculateRisk($identity, $propertySignals);
        $preserveStatus = !empty($propertySignals['preserve_listing_status']);
        $status = $calculation['recommendation'] === 'auto_approve' ? 'approved' : 'pending';
        $isFlagged = $calculation['has_flagged_signal'] || $calculation['risk_band'] === 'high';

        if ($preserveStatus) {
            $stmt = $this->db->prepare(
                'UPDATE properties
                 SET risk_score = ?, risk_band = ?, risk_recommendation = ?, risk_signals = ?,
                     is_flagged = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $calculation['risk_score'],
                $calculation['risk_band'],
                $calculation['recommendation'],
                json_encode($calculation['signals'], JSON_THROW_ON_ERROR),
                $isFlagged ? 1 : 0,
                $propertyId
            ]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE properties
                 SET risk_score = ?, risk_band = ?, risk_recommendation = ?, risk_signals = ?,
                     status = ?, is_flagged = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $calculation['risk_score'],
                $calculation['risk_band'],
                $calculation['recommendation'],
                json_encode($calculation['signals'], JSON_THROW_ON_ERROR),
                $status,
                $isFlagged ? 1 : 0,
                $propertyId
            ]);
        }

        $stmt = $this->db->prepare(
            'UPDATE agent_profiles SET trust_score = ?, risk_level = ? WHERE user_id = ?'
        );
        $stmt->execute([
            round((100 - $calculation['risk_score']) / 10, 1),
            $calculation['risk_band'],
            $agentId
        ]);

        $calculation['listing_status'] = $status;
        return $calculation;
    }

    public function recalculateAgentProperties($agentId)
    {
        $stmt = $this->db->prepare('SELECT id, risk_signals FROM properties WHERE agent_id = ?');
        $stmt->execute([$agentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $property) {
            $saved = json_decode($property['risk_signals'] ?? '', true) ?: [];
            $this->calculateInitialScore($agentId, $property['id'], [
                'price_deviation_pct' => $saved['price']['deviation_pct'] ?? null,
                'price_flagged' => $saved['price']['flagged'] ?? false,
                'price_available' => $saved['price']['available'] ?? false,
                'price_reason' => $saved['price']['reason'] ?? null,
                'duplicate_image_flagged' => $saved['duplicate']['flagged'] ?? false,
                'duplicate_available' => $saved['duplicate']['available'] ?? false,
                'duplicate_reason' => $saved['duplicate']['reason'] ?? null,
                'gps_mismatch_distance_m' => $saved['gps']['distance_meters'] ?? null,
                'gps_radius_m' => $saved['gps']['radius_meters'] ?? null,
                'gps_flagged' => $saved['gps']['flagged'] ?? false,
                'gps_available' => $saved['gps']['available'] ?? false,
                'gps_reason' => $saved['gps']['reason'] ?? null,
                'preserve_listing_status' => true
            ]);
        }
    }

    public static function calculateRisk(array $identity, array $propertySignals): array
    {
        $config = FraudConfig::section('risk');
        $weights = $config['weights'] ?? [];
        $bands = $config['bands'] ?? [];
        foreach (['identity', 'price', 'duplicate', 'gps'] as $key) {
            if (!isset($weights[$key]) || !is_numeric($weights[$key]) || (float)$weights[$key] < 0) {
                throw new RuntimeException("Risk weight '{$key}' is invalid.");
            }
        }
        if (
            array_sum($weights) <= 0 ||
            !isset($bands['low_max'], $bands['medium_max']) ||
            (float)$bands['low_max'] < 0 ||
            (float)$bands['medium_max'] <= (float)$bands['low_max'] ||
            (float)$bands['medium_max'] > 100
        ) {
            throw new RuntimeException('Risk bands or weights are invalid.');
        }

        $confidence = isset($identity['verification_confidence'])
            ? max(0, min(100, (float)$identity['verification_confidence']))
            : 0.0;
        $tamper = max(0, min(100, (float)($identity['tamper_score'] ?? 0)));
        $identityStatus = $identity['verification_status'] ?? 'not_submitted';
        $tamperFlagged = !empty($identity['tamper_flagged']);
        $identityRisk = $tamperFlagged
            ? 100.0
            : max(100.0 - $confidence, $tamper);
        if ($identityStatus !== 'verified') {
            $identityRisk = max($identityRisk, 100.0);
        }

        $priceDeviation = self::optionalNumber($propertySignals['price_deviation_pct'] ?? null);
        $priceAvailable = !empty($propertySignals['price_available']) && $priceDeviation !== null;
        $priceRisk = null;
        if ($priceAvailable) {
            $fullScoreDeviation = (float)FraudConfig::section('price')['risk_full_score_pct'];
            if ($fullScoreDeviation <= 0) {
                throw new RuntimeException('Price risk normalization percentage must be positive.');
            }
            $priceRisk = min(100.0, max(0.0, 100.0 * $priceDeviation / $fullScoreDeviation));
        }
        $priceFlagged = $priceAvailable && !empty($propertySignals['price_flagged']);

        $duplicateAvailable = !empty($propertySignals['duplicate_available']);
        $duplicateFlagged = !empty($propertySignals['duplicate_image_flagged']);
        $duplicateRisk = $duplicateFlagged
            ? 100.0
            : ($duplicateAvailable ? 0.0 : null);

        $gpsDistance = self::optionalNumber($propertySignals['gps_mismatch_distance_m'] ?? null);
        $gpsRadius = self::optionalNumber($propertySignals['gps_radius_m'] ?? null);
        $gpsAvailable = !empty($propertySignals['gps_available']) &&
            $gpsDistance !== null && $gpsRadius !== null;
        $gpsFlagged = $gpsAvailable && !empty($propertySignals['gps_flagged']);
        $gpsRisk = null;
        if ($gpsAvailable) {
            $overrun = max(0.0, $gpsDistance - $gpsRadius);
            $riskScale = (float)FraudConfig::section('gps')['risk_overrun_distance_m'];
            if ($riskScale <= 0) {
                throw new RuntimeException('GPS risk normalization distance must be positive.');
            }
            $gpsRisk = min(100.0, 100.0 * $overrun / $riskScale);
        }

        $components = [
            'identity' => [
                'available' => true,
                'verification_status' => $identityStatus,
                'verification_confidence' => $confidence,
                'tamper_score' => $tamper,
                'tamper_flagged' => $tamperFlagged,
                'risk_score' => round($identityRisk, 2)
            ],
            'price' => [
                'available' => $priceAvailable,
                'deviation_pct' => $priceDeviation,
                'flagged' => $priceFlagged,
                'reason' => $propertySignals['price_reason'] ?? null,
                'risk_score' => $priceRisk
            ],
            'duplicate' => [
                'available' => $duplicateAvailable,
                'flagged' => $duplicateFlagged,
                'matching_property_id' => $propertySignals['matching_property_id'] ?? null,
                'reason' => $propertySignals['duplicate_reason'] ?? null,
                'risk_score' => $duplicateRisk
            ],
            'gps' => [
                'available' => $gpsAvailable,
                'distance_meters' => $gpsDistance,
                'radius_meters' => $gpsRadius,
                'flagged' => $gpsFlagged,
                'reason' => $propertySignals['gps_reason'] ?? null,
                'risk_score' => $gpsRisk
            ]
        ];

        $weightedTotal = 0.0;
        $availableWeight = 0.0;
        foreach ($components as $key => &$component) {
            $component['weight_pct'] = (float)$weights[$key];
            if ($component['risk_score'] !== null) {
                $weightedTotal += $component['risk_score'] * (float)$weights[$key];
                $availableWeight += (float)$weights[$key];
            }
        }
        unset($component);

        $riskScore = $availableWeight > 0
            ? round($weightedTotal / $availableWeight, 2)
            : 100.0;
        $lowMax = (float)$bands['low_max'];
        $mediumMax = (float)$bands['medium_max'];
        $riskBand = $riskScore < $lowMax
            ? 'low'
            : ($riskScore < $mediumMax ? 'medium' : 'high');
        $allSignalsAvailable = count(array_filter(
            $components,
            static function (array $component): bool {
                return $component['available'];
            }
        )) === 4;
        $recommendation = $riskBand === 'high'
            ? 'auto_flag'
            : ($riskBand === 'low' && $allSignalsAvailable
                ? 'auto_approve'
                : 'manual_review');

        return [
            'risk_score' => $riskScore,
            'risk_band' => $riskBand,
            'recommendation' => $recommendation,
            'has_flagged_signal' => $tamperFlagged || $priceFlagged || $duplicateFlagged || $gpsFlagged,
            'signals' => $components
        ];
    }

    private static function optionalNumber($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
