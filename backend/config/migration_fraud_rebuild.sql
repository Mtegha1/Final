USE iconics_db;

ALTER TABLE agent_profiles
    ADD COLUMN IF NOT EXISTS tamper_score DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS verification_distance DECIMAL(10,6) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS ela_heatmap_path TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS liveness_status ENUM('passed','review','not_checked') DEFAULT 'not_checked';

ALTER TABLE properties
    ADD COLUMN IF NOT EXISTS image_hash VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS risk_score DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS risk_band ENUM('low','medium','high') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS risk_recommendation ENUM('auto_approve','manual_review','auto_flag') DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS risk_signals JSON DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS is_flagged TINYINT(1) DEFAULT 0;

ALTER TABLE fraud_logs
    ADD COLUMN IF NOT EXISTS flagged TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS details JSON DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS matched_property_id INT DEFAULT NULL;

ALTER TABLE audit_logs
    ADD COLUMN IF NOT EXISTS decision VARCHAR(32) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS note TEXT DEFAULT NULL;

ALTER TABLE blantyre_zones
    ADD COLUMN IF NOT EXISTS radius_m INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS source VARCHAR(255) DEFAULT NULL;

ALTER TABLE zone_market_rates
    ADD COLUMN IF NOT EXISTS area_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS average_monthly_rent DECIMAL(12,2) DEFAULT NULL;
UPDATE zone_market_rates SET area_name = city WHERE area_name IS NULL;
ALTER TABLE zone_market_rates MODIFY avg_price DECIMAL(12,2) DEFAULT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_blantyre_zones_area_name ON blantyre_zones(area_name);
UPDATE blantyre_zones SET is_active = 0;
INSERT INTO blantyre_zones (area_name, latitude, longitude, radius_m, is_active, source)
VALUES
    ('Nyambadwe', -15.7678010, 35.0319020, 3000, 1, 'OpenStreetMap residential polygon bounds; provisional radius'),
    ('Chinyonga', -15.8129280, 35.0399940, 1100, 1, 'OpenStreetMap residential polygon bounds; provisional radius'),
    ('Chitawira', -15.8355500, 35.0252900, 3100, 1, 'OpenStreetMap mapped neighborhood bounds; provisional radius'),
    ('Chirimba', -15.7393000, 35.0117000, 3000, 1, 'OpenStreetMap mapped neighborhood bounds; provisional radius')
ON DUPLICATE KEY UPDATE
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    radius_m = VALUES(radius_m),
    is_active = VALUES(is_active),
    source = VALUES(source);

CREATE INDEX IF NOT EXISTS idx_fraud_logs_property_type ON fraud_logs(property_id, type);
CREATE INDEX IF NOT EXISTS idx_properties_risk_queue ON properties(status, risk_score);
CREATE INDEX IF NOT EXISTS idx_zone_market_rate_lookup ON zone_market_rates(area_name, property_type);
