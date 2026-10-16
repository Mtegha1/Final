<?php

require_once __DIR__ . '/../services/TrustScoreService.php';
require_once __DIR__ . '/../services/ImageHashService.php';
require_once __DIR__ . '/../core/GeoValidator.php';

function expectSame($expected, $actual, string $description): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $description . ': expected ' . var_export($expected, true) .
            ', received ' . var_export($actual, true)
        );
    }
}

function expectTrue(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

$verifiedIdentity = [
    'verification_status' => 'verified',
    'verification_confidence' => 100,
    'tamper_score' => 0,
    'tamper_flagged' => false
];
$cleanSignals = [
    'price_deviation_pct' => 0,
    'price_available' => true,
    'price_flagged' => false,
    'duplicate_available' => true,
    'duplicate_image_flagged' => false,
    'gps_available' => true,
    'gps_mismatch_distance_m' => 1000,
    'gps_radius_m' => 1000,
    'gps_flagged' => false
];

$low = TrustScoreService::calculateRisk($verifiedIdentity, $cleanSignals);
expectSame('low', $low['risk_band'], 'All clean signals should be low risk');
expectSame('auto_approve', $low['recommendation'], 'Complete low-risk signals may auto-approve');
expectSame(0.0, $low['risk_score'], 'Clean signals should have no risk score');

$identityRisk = TrustScoreService::calculateRisk(
    ['verification_status' => 'pending', 'verification_confidence' => 90, 'tamper_score' => 0],
    $cleanSignals
);
expectSame(30.0, $identityRisk['risk_score'], 'Unverified identity uses the configured identity weight');
expectSame('medium', $identityRisk['risk_band'], 'Unverified identity should require review');

$priceSignals = $cleanSignals;
$priceSignals['price_deviation_pct'] = 40;
$priceSignals['price_flagged'] = true;
$priceRisk = TrustScoreService::calculateRisk($verifiedIdentity, $priceSignals);
expectSame(30.0, $priceRisk['risk_score'], 'Price deviation is normalized to the configured full-risk point');
expectSame('medium', $priceRisk['risk_band'], 'Materially under-market rent should require review');

$duplicateSignals = $cleanSignals;
$duplicateSignals['duplicate_image_flagged'] = true;
$duplicateRisk = TrustScoreService::calculateRisk($verifiedIdentity, $duplicateSignals);
expectSame(20.0, $duplicateRisk['risk_score'], 'Duplicate image uses its configured weight');
expectSame('medium', $duplicateRisk['risk_band'], 'A confirmed duplicate should not auto-approve');

$gpsSignals = $cleanSignals;
$gpsSignals['gps_mismatch_distance_m'] = 1200;
$gpsRisk = TrustScoreService::calculateRisk($verifiedIdentity, $gpsSignals);
expectSame(4.0, $gpsRisk['risk_score'], 'GPS risk scales with distance beyond the zone radius');

$high = TrustScoreService::calculateRisk(
    ['verification_status' => 'pending', 'verification_confidence' => 0, 'tamper_score' => 0],
    array_merge($priceSignals, [
        'duplicate_available' => true,
        'duplicate_image_flagged' => true,
        'gps_mismatch_distance_m' => 2000,
        'gps_radius_m' => 1000,
        'gps_flagged' => true
    ])
);
expectSame('high', $high['risk_band'], 'Combined strong signals should be high risk');
expectSame('auto_flag', $high['recommendation'], 'High-risk listings should be flagged for an admin');

$incompleteSignals = $cleanSignals;
$incompleteSignals['price_available'] = false;
$incompleteSignals['price_deviation_pct'] = null;
$incompleteSignals['duplicate_available'] = false;
$incomplete = TrustScoreService::calculateRisk($verifiedIdentity, $incompleteSignals);
expectSame('low', $incomplete['risk_band'], 'Available clean signals can have a low numeric score');
expectSame('manual_review', $incomplete['recommendation'], 'Incomplete checks must never auto-approve');

expectSame(1, ImageHashService::hammingDistance('0000', '0001'), 'pHash Hamming distance');
expectSame(null, ImageHashService::hammingDistance('invalid', '0001'), 'Malformed pHash is unavailable');
expectTrue(
    GeoValidator::calculateDistance(0, 0, 0, 0.001) > 100,
    'Haversine distance should return metres'
);

echo "Risk, pHash, and GPS service tests passed.\n";
