USE iconics_db;

UPDATE properties SET status = 'pending' WHERE status IS NULL;

ALTER TABLE properties
    ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL,
    MODIFY status ENUM('draft','pending','approved','rejected','blocked') NOT NULL DEFAULT 'pending';

SET @has_created_by_fk = (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND CONSTRAINT_NAME = 'fk_properties_created_by'
);
SET @created_by_fk_sql = IF(
    @has_created_by_fk = 0,
    'ALTER TABLE properties ADD CONSTRAINT fk_properties_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE created_by_fk_stmt FROM @created_by_fk_sql;
EXECUTE created_by_fk_stmt;
DEALLOCATE PREPARE created_by_fk_stmt;

INSERT INTO properties
    (agent_id, created_by, title, description, price, property_type, area_name,
     latitude, longitude, image_url, image_hash, status, is_flagged,
     risk_score, risk_band, risk_recommendation, risk_signals)
SELECT
    NULL,
    admin_user.id,
    CONCAT('Rental draft ', draft_slots.draft_number, ' - ', areas.area_name),
    'Unverified admin draft. Add the confirmed property details, monthly rent, exact location pin, and photos before submitting for review.',
    NULL,
    NULL,
    areas.area_name,
    NULL,
    NULL,
    NULL,
    NULL,
    'draft',
    0,
    NULL,
    NULL,
    'manual_review',
    NULL
FROM (
    SELECT 'Namiwawa' AS area_name
    UNION ALL SELECT 'Chinyonga'
    UNION ALL SELECT 'Chitawira'
    UNION ALL SELECT 'Nyambadwe'
    UNION ALL SELECT 'Sunny Side'
    UNION ALL SELECT 'Mount Pleasant'
    UNION ALL SELECT 'Mpemba'
    UNION ALL SELECT 'Chigumula'
    UNION ALL SELECT 'Bvumbwe'
    UNION ALL SELECT 'Chileka'
) AS areas
CROSS JOIN (
    SELECT 1 AS draft_number
    UNION ALL SELECT 2
    UNION ALL SELECT 3
) AS draft_slots
CROSS JOIN (
    SELECT id
    FROM users
    WHERE role = 'admin'
    ORDER BY id
    LIMIT 1
) AS admin_user
WHERE NOT EXISTS (
    SELECT 1
    FROM properties existing
    WHERE existing.area_name = areas.area_name
      AND existing.title = CONCAT('Rental draft ', draft_slots.draft_number, ' - ', areas.area_name)
);
