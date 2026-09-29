<?php

require_once __DIR__ . '/../models/FraudLog.php';
require_once __DIR__ . '/../services/PriceAnalysisService.php';
require_once __DIR__ . '/../services/GPSVerificationService.php';

class FraudDetectionService
{
    private $fraudLog;
    private $priceService;
    private $gpsService;

    public function __construct()
    {
        $this->fraudLog = new FraudLog();
        $this->priceService = new PriceAnalysisService();
        $this->gpsService = new GPSVerificationService();
    }

    public function analyze($propertyData, $isDuplicate = false)
    {
        $issues = [];
        $priceDeviation = 0.0;
        $priceFlagged = false;
        $gpsDistance = null;
        $gpsFlagged = false;

        /* 1. PRICE CHECK */
        $priceCheck = $this->priceService->check(
            $propertyData['area_name'],
            $propertyData['property_type'],
            $propertyData['price']
        );
        $priceDeviation = (float)($priceCheck['deviation'] ?? 0);

        if ($priceCheck['flag']) {
            $message = "Price suspicious: {$priceCheck['deviation']}% below market";

            $this->fraudLog->create(
                $propertyData['property_id'],
                $propertyData['agent_id'],
                'price',
                $message
            );

            $issues[] = 'price';
            $priceFlagged = true;
        }

        /* 2. GPS CHECK    */
        $gpsCheck = $this->gpsService->validate(
            $propertyData['latitude'],
            $propertyData['longitude'],
            $propertyData['area_name']
        );

        $gpsDistance = isset($gpsCheck['distance_meters'])
            ? (float)$gpsCheck['distance_meters']
            : null;

        if (!$gpsCheck['valid']) {
            $distanceMessage = $gpsDistance === null ? ($gpsCheck['reason'] ?? 'unknown location') : "{$gpsDistance}m away";
            $message = "Location mismatch: {$distanceMessage} from {$propertyData['area_name']}";

            $this->fraudLog->create(
                $propertyData['property_id'],
                $propertyData['agent_id'],
                'gps',
                $message
            );

            $issues[] = 'gps';
            $gpsFlagged = true;
        }

        if ($isDuplicate) {
            $this->fraudLog->create(
                $propertyData['property_id'],
                $propertyData['agent_id'],
                'duplicate_image',
                'Perceptual image hash matched an existing property image'
            );
            $issues[] = 'duplicate_image';
        }

        return [
            'status' => empty($issues) ? 'clean' : 'flagged',
            'issues' => $issues,
            'signals' => [
                'price_deviation_pct' => $priceDeviation,
                'price_flagged' => $priceFlagged,
                'duplicate_image_flagged' => $isDuplicate,
                'gps_mismatch_distance_m' => $gpsDistance,
                'gps_flagged' => $gpsFlagged
            ]
        ];
    }
}
