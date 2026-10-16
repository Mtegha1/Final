<?php

require_once __DIR__ . '/../models/FraudLog.php';
require_once __DIR__ . '/PriceAnalysisService.php';
require_once __DIR__ . '/GPSVerificationService.php';
require_once __DIR__ . '/ImageHashService.php';

class FraudDetectionService
{
    private $db;
    private $fraudLog;
    private $priceService;
    private $gpsService;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
        $this->fraudLog = new FraudLog($this->db);
        $this->priceService = new PriceAnalysisService($this->db);
        $this->gpsService = new GPSVerificationService($this->db);
    }

    public function analyze(array $propertyData): array
    {
        $propertyId = (int)$propertyData['property_id'];
        $agentId = (int)$propertyData['agent_id'];
        $price = $this->priceService->check(
            (string)$propertyData['area_name'],
            (string)$propertyData['property_type'],
            (float)$propertyData['price']
        );
        $this->fraudLog->create(
            $propertyId,
            $agentId,
            'price',
            $price['reason'],
            $price['available'] && $price['flag'],
            $price
        );

        $gps = $this->gpsService->validate(
            $propertyData['latitude'] ?? null,
            $propertyData['longitude'] ?? null,
            (string)$propertyData['area_name']
        );
        $this->fraudLog->create(
            $propertyId,
            $agentId,
            'gps',
            $gps['reason'],
            $gps['available'] && $gps['flag'],
            $gps
        );

        $imageHashes = $propertyData['image_hashes'] ?? [$propertyData['image_hash'] ?? null];
        $duplicate = $this->checkUploadedImages($imageHashes, $propertyId, $agentId);

        $signals = [
            'price_deviation_pct' => $price['deviation_pct'],
            'price_flagged' => $price['available'] && $price['flag'],
            'price_available' => $price['available'],
            'price_average_monthly_rent' => $price['average_monthly_rent'],
            'price_reason' => $price['reason'],
            'duplicate_image_flagged' => $duplicate['flag'],
            'duplicate_available' => $duplicate['available'],
            'matching_property_id' => $duplicate['matching_property_id'],
            'duplicate_hamming_distance' => $duplicate['hamming_distance'],
            'duplicate_reason' => $duplicate['reason'],
            'gps_mismatch_distance_m' => $gps['distance_meters'],
            'gps_radius_m' => $gps['radius_meters'],
            'gps_overrun_m' => $gps['overrun_meters'] ?? null,
            'gps_flagged' => $gps['available'] && $gps['flag'],
            'gps_available' => $gps['available'],
            'gps_reason' => $gps['reason']
        ];
        $anyFlagged = $signals['price_flagged'] ||
            $signals['duplicate_image_flagged'] ||
            $signals['gps_flagged'];

        return [
            'status' => $anyFlagged ? 'flagged' : 'clear',
            'issues' => array_values(array_keys(array_filter([
                'price' => $signals['price_flagged'],
                'duplicate_image' => $signals['duplicate_image_flagged'],
                'gps' => $signals['gps_flagged']
            ]))),
            'signals' => $signals,
            'checks' => [
                'price' => $price,
                'duplicate_image' => $duplicate,
                'gps' => $gps
            ]
        ];
    }

    private function checkUploadedImages(array $imageHashes, int $propertyId, int $agentId): array
    {
        if (!$imageHashes) {
            $imageHashes = [null];
        }

        $stmt = $this->db->prepare(
            "SELECT id, image_hash FROM properties
             WHERE agent_id <> ? AND image_hash IS NOT NULL AND image_hash <> ''
             UNION ALL
             SELECT p.id, pi.image_hash FROM property_images pi
             INNER JOIN properties p ON p.id = pi.property_id
             WHERE p.agent_id <> ? AND pi.image_hash IS NOT NULL AND pi.image_hash <> ''"
        );
        $stmt->execute([$agentId, $agentId]);
        $existingHashes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $threshold = (int)FraudConfig::section('duplicate_image')['maximum_hamming_distance'];
        $bestDistance = null;
        $matchedPropertyId = null;
        $allAvailable = true;
        $anyDuplicate = false;

        foreach ($imageHashes as $index => $imageHash) {
            $available = is_string($imageHash) && $imageHash !== '';
            $allAvailable = $allAvailable && $available;
            $imageDistance = null;
            $imagePropertyId = null;
            if ($available) {
                foreach ($existingHashes as $existing) {
                    $distance = ImageHashService::hammingDistance(
                        $imageHash,
                        (string)$existing['image_hash']
                    );
                    if ($distance !== null && ($imageDistance === null || $distance < $imageDistance)) {
                        $imageDistance = $distance;
                        $imagePropertyId = (int)$existing['id'];
                    }
                }
            }
            $imageFlagged = $available &&
                $imageDistance !== null &&
                $imageDistance <= $threshold;
            $anyDuplicate = $anyDuplicate || $imageFlagged;
            if ($imageFlagged && ($bestDistance === null || $imageDistance < $bestDistance)) {
                $bestDistance = $imageDistance;
                $matchedPropertyId = $imagePropertyId;
            }
            $imageDetails = [
                'status' => !$available ? 'error' : ($imageFlagged ? 'flagged' : 'clear'),
                'image_number' => $index + 1,
                'available' => $available,
                'flag' => $imageFlagged,
                'threshold_hamming_distance' => $threshold,
                'matching_property_id' => $imageFlagged ? $imagePropertyId : null,
                'hamming_distance' => $imageDistance
            ];
            $message = !$available
                ? 'Duplicate image check could not run for this photo because its hash is unavailable.'
                : ($imageFlagged
                    ? 'Perceptual hash matched another agent listing for this photo.'
                    : 'No duplicate was found for this photo among other agents listings.');
            $this->fraudLog->create(
                $propertyId,
                $agentId,
                'duplicate_image',
                $message,
                $imageFlagged,
                $imageDetails,
                $imageFlagged ? $imagePropertyId : null
            );
        }

        return [
            'available' => $allAvailable,
            'flag' => $anyDuplicate,
            'threshold_hamming_distance' => $threshold,
            'matching_property_id' => $matchedPropertyId,
            'hamming_distance' => $bestDistance,
            'photo_count' => count($imageHashes),
            'reason' => !$allAvailable
                ? 'At least one photo hash is unavailable.'
                : ($anyDuplicate
                    ? 'At least one photo matched an image used by another agent.'
                    : 'No photo matched an image used by another agent.')
        ];
    }
}
