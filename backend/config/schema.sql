-- ICONICS DATABASE SCHEMA
-- FIX: renamed database from `iconics` to `iconics_db` to match database.php connection string.

DROP DATABASE IF EXISTS iconics_db;
CREATE DATABASE iconics_db;
USE iconics_db;


-- USERS TABLE
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255),
    email VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    role ENUM('admin','agent','client') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


-- AGENT PROFILES
CREATE TABLE agent_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNIQUE,
    national_id_path TEXT,
    selfie_path TEXT,
    -- FIX: expanded ENUM to include 'pending_review' and 'not_submitted' used by FaceVerificationService
    verification_status ENUM('not_submitted','pending','pending_review','verified','rejected') DEFAULT 'not_submitted',
    trust_score DECIMAL(3,1) DEFAULT 0.0,
    -- FIX: these two columns were added via ALTER in the old schema but must exist from the start
    verification_confidence DECIMAL(5,2) DEFAULT 0.00,
    risk_level ENUM('low','medium','high') DEFAULT 'low',
    is_verified TINYINT(1) DEFAULT 0,
    ela_variance DECIMAL(10,2) DEFAULT NULL,
    tamper_score DECIMAL(5,2) DEFAULT 0.00,
    tamper_flagged TINYINT(1) DEFAULT 0,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);


-- PROPERTIES
-- FIX: The original table was missing `image_hash`, `image_url`, `area_name`,
--      and `is_flagged` columns. PropertyController reads/writes all four directly
--      on this table, so the duplicate-hash detection was silently querying a
--      column that did not exist, returning zero rows every time and making the
--      entire image-hash pipeline a no-op.
-- FIX: `status` ENUM expanded to include 'rejected' used by AdminController.
CREATE TABLE properties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT,
    title VARCHAR(255),
    description TEXT,
    area_name VARCHAR(100),          -- FIX: replaced `city` with `area_name` (matches all PHP code)
    latitude DECIMAL(10,7),
    longitude DECIMAL(10,7),
    price DECIMAL(12,2),
    property_type VARCHAR(50),
    image_url VARCHAR(255),          -- FIX: filename stored by PropertyController after upload
    image_hash VARCHAR(255),         -- FIX: perceptual hash used for duplicate detection
    is_flagged TINYINT(1) DEFAULT 0, -- FIX: flag set by fraud/duplicate checks
    -- FIX: added 'rejected' to match AdminController::rejectListing()
    status ENUM('pending','approved','rejected','blocked') DEFAULT 'pending',
    risk_score DECIMAL(5,2) DEFAULT 0.00,
    risk_band ENUM('low','medium','high') DEFAULT 'low',
    risk_recommendation ENUM('auto_approve','manual_review','auto_flag') DEFAULT 'auto_approve',
    risk_signals JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (agent_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);


-- PROPERTY IMAGES (secondary table for multi-image support, kept for future use)
CREATE TABLE property_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT,
    image_path TEXT,
    image_hash VARCHAR(255),

    FOREIGN KEY (property_id)
        REFERENCES properties(id)
        ON DELETE CASCADE
);


-- FRAUD LOGS
CREATE TABLE fraud_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT,
    agent_id INT,
    type VARCHAR(50), -- identity, price, gps, duplicate_image
    message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (property_id)
        REFERENCES properties(id)
        ON DELETE CASCADE,

    FOREIGN KEY (agent_id)
        REFERENCES users(id)
        ON DELETE CASCADE
);


-- AUDIT LOGS (referenced by AdminController::getAuditLogs)
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(255),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE SET NULL
);


-- MARKET RATES (PRICE ANALYSIS)
CREATE TABLE zone_market_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    city VARCHAR(100),
    property_type VARCHAR(50),
    avg_price DECIMAL(12,2)
);


-- BLANTYRE ZONES (GPS VALIDATION)
CREATE TABLE blantyre_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    area_name VARCHAR(100),
    latitude DECIMAL(10,7),
    longitude DECIMAL(10,7)
);


-- INDEXES (PERFORMANCE BOOST)
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_properties_area ON properties(area_name);
CREATE INDEX idx_properties_agent ON properties(agent_id);
CREATE INDEX idx_properties_image_hash ON properties(image_hash); -- FIX: speeds up duplicate scan


-- SAMPLE DATA (OPTIONAL FOR TESTING)
INSERT INTO blantyre_zones (area_name, latitude, longitude) VALUES
('Blantyre CBD',    -15.7861, 35.0058),
('Namiwawa',        -15.7800, 35.0150),
('Nyambadwe',       -15.8000, 35.0200),
('Chinyonga',       -15.7700, 35.0000),
('Chapima Heights', -15.8100, 35.0300),
('Chitawira',       -15.8200, 35.0400),
('New Naperi',      -15.7750, 35.0100),
('Mpemba',          -15.9000, 34.9500),
('Chirimba',        -15.8400, 35.0600),
('Chileka',         -15.6800, 34.9700);
