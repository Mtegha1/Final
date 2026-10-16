USE iconics_db;

INSERT INTO blantyre_zones
    (area_name, latitude, longitude, radius_m, is_active, source)
VALUES
    ('Nyambadwe', -15.7678010, 35.0319020, 3000, 1, 'OpenStreetMap mapped residential polygon bounds; provisional radius'),
    ('Chinyonga', -15.8129280, 35.0399940, 1100, 1, 'OpenStreetMap mapped residential polygon bounds; provisional radius'),
    ('Chitawira', -15.8355500, 35.0252900, 3100, 1, 'OpenStreetMap mapped neighborhood bounds; provisional radius'),
    ('Chirimba', -15.7393000, 35.0117000, 3000, 1, 'OpenStreetMap mapped neighborhood bounds; provisional radius')
ON DUPLICATE KEY UPDATE
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    radius_m = VALUES(radius_m),
    is_active = VALUES(is_active),
    source = VALUES(source);
