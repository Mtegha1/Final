<?php

class GeoValidator
{
    public static function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadiusMeters = 6371000;
        $latitudeDelta = deg2rad((float)$lat2 - (float)$lat1);
        $longitudeDelta = deg2rad((float)$lon2 - (float)$lon1);

        $a = sin($latitudeDelta / 2) ** 2 +
            cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) *
            sin($longitudeDelta / 2) ** 2;
        return $earthRadiusMeters * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
