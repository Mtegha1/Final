<?php

require_once __DIR__ . '/../core/GeoValidator.php';

class GPSVerificationService
{
    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? Database::getInstance()->conn;
    }

    public function getActiveZones(): array
    {
        return $this->db->query(
            'SELECT area_name, latitude, longitude, radius_m
             FROM blantyre_zones WHERE is_active = 1 ORDER BY area_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function validate($latitude, $longitude, string $areaName): array
    {
        if (
            !is_numeric($latitude) ||
            !is_numeric($longitude) ||
            (float)$latitude < -90 ||
            (float)$latitude > 90 ||
            (float)$longitude < -180 ||
            (float)$longitude > 180
        ) {
            return [
                'available' => false,
                'valid' => false,
                'flag' => false,
                'distance_meters' => null,
                'radius_meters' => null,
                'reason' => 'A valid location pin is required.'
            ];
        }

        $stmt = $this->db->prepare(
            'SELECT latitude, longitude, radius_m FROM blantyre_zones
             WHERE area_name = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$areaName]);
        $zone = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$zone || (int)$zone['radius_m'] <= 0) {
            return [
                'available' => false,
                'valid' => false,
                'flag' => false,
                'distance_meters' => null,
                'radius_meters' => null,
                'reason' => 'This area has no validated offline GPS zone yet.'
            ];
        }

        $distance = GeoValidator::calculateDistance(
            (float)$latitude,
            (float)$longitude,
            (float)$zone['latitude'],
            (float)$zone['longitude']
        );
        $radius = (int)$zone['radius_m'];
        $valid = $distance <= $radius;

        return [
            'available' => true,
            'valid' => $valid,
            'flag' => !$valid,
            'distance_meters' => round($distance, 2),
            'radius_meters' => $radius,
            'overrun_meters' => round(max(0, $distance - $radius), 2),
            'reason' => $valid
                ? 'The property pin is inside the selected area zone.'
                : 'The property pin is outside the selected area zone.'
        ];
    }
}
