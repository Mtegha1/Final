USE iconics_db;

ALTER TABLE agent_profiles
    ADD COLUMN is_verified TINYINT(1) DEFAULT 0,
    ADD COLUMN ela_variance DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN tamper_score DECIMAL(5,2) DEFAULT 0.00,
    ADD COLUMN tamper_flagged TINYINT(1) DEFAULT 0;

ALTER TABLE properties
    ADD COLUMN risk_score DECIMAL(5,2) DEFAULT 0.00,
    ADD COLUMN risk_band ENUM('low','medium','high') DEFAULT 'low',
    ADD COLUMN risk_recommendation ENUM('auto_approve','manual_review','auto_flag') DEFAULT 'auto_approve',
    ADD COLUMN risk_signals JSON DEFAULT NULL;

ALTER TABLE agent_profiles
    DROP COLUMN avg_rating,
    DROP COLUMN total_reviews;

DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS reviews;