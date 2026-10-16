<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/FraudConfig.php';

class PriceAnalysisService
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
    }

    public function check(string $areaName, string $propertyType, float $monthlyRent): array
    {
        $stmt = $this->db->prepare(
            'SELECT average_monthly_rent FROM zone_market_rates
             WHERE area_name = ? AND property_type = ?
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$areaName, $propertyType]);
        $average = $stmt->fetchColumn();

        if ($average === false || $average === null || (float)$average <= 0) {
            return [
                'available' => false,
                'flag' => false,
                'deviation_pct' => null,
                'average_monthly_rent' => null,
                'reason' => 'No validated monthly-rent baseline is configured for this area and property type.'
            ];
        }

        $average = (float)$average;
        $deviation = max(0.0, (($average - $monthlyRent) / $average) * 100);
        $threshold = (float)FraudConfig::section('price')['below_market_flag_pct'];

        return [
            'available' => true,
            'flag' => $deviation >= $threshold,
            'deviation_pct' => round($deviation, 2),
            'average_monthly_rent' => $average,
            'threshold_pct' => $threshold,
            'reason' => $deviation >= $threshold
                ? 'Monthly rent is materially below the configured area/type average.'
                : 'Monthly rent is within the configured price-check range.'
        ];
    }
}
