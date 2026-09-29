USE iconics_db;

-- ADMIN USERS (3)
INSERT INTO users
    (full_name, email, password, role)
VALUES
    ('System Admin One', 'admin1@iconics.com', '$2y$10$adminhash1', 'admin'),
    ('System Admin Two', 'admin2@iconics.com', '$2y$10$adminhash2', 'admin'),
    ('System Admin Three', 'admin3@iconics.com', '$2y$10$adminhash3', 'admin');



-- AGENTS 
INSERT INTO users
    (full_name, email, password, role)
VALUES
    ('James Mwale', 'james.mwale@iconics.com', '$2y$10$agent1', 'agent'),
    ('Esther Phiri', 'esther.phiri@iconics.com', '$2y$10$agent2', 'agent'),
    ('Brian Chirwa', 'brian.chirwa@iconics.com', '$2y$10$agent3', 'agent'),
    ('Grace Banda', 'grace.banda@iconics.com', '$2y$10$agent4', 'agent'),
    ('Michael Gondwe', 'michael.gondwe@iconics.com', '$2y$10$agent5', 'agent'),
    ('Lilian Soko', 'lilian.soko@iconics.com', '$2y$10$agent6', 'agent'),
    ('Henry Kalua', 'henry.kalua@iconics.com', '$2y$10$agent7', 'agent'),
    ('Rebecca Moyo', 'rebecca.moyo@iconics.com', '$2y$10$agent8', 'agent'),
    ('Victor Nyirenda', 'victor.nyirenda@iconics.com', '$2y$10$agent9', 'agent'),
    ('Sandra Chisale', 'sandra.chisale@iconics.com', '$2y$10$agent10', 'agent');


-- CLIENTS (10)
INSERT INTO users
    (full_name, email, password, role)
VALUES
    ('Peter Banda', 'peter.banda@gmail.com', '$2y$10$client1', 'client'),
    ('Alice Kumwenda', 'alice.kumwenda@gmail.com', '$2y$10$client2', 'client'),
    ('John Phiri', 'john.phiri@gmail.com', '$2y$10$client3', 'client'),
    ('Mary Chirwa', 'mary.chirwa@gmail.com', '$2y$10$client4', 'client'),
    ('Daniel Moyo', 'daniel.moyo@gmail.com', '$2y$10$client5', 'client'),
    ('Susan Mwale', 'susan.mwale@gmail.com', '$2y$10$client6', 'client'),
    ('Frank Gondwe', 'frank.gondwe@gmail.com', '$2y$10$client7', 'client'),
    ('Eva Soko', 'eva.soko@gmail.com', '$2y$10$client8', 'client'),
    ('George Kalua', 'george.kalua@gmail.com', '$2y$10$client9', 'client'),
    ('Linda Nyirenda', 'linda.nyirenda@gmail.com', '$2y$10$client10', 'client');


-- AGENT PROFILES

INSERT INTO agent_profiles
    (user_id, national_id_path, selfie_path, verification_status, trust_score, verification_confidence, risk_level)
VALUES
    -- VERIFIED AGENTS
    (4, 'docs/id1.jpg', 'selfie1.jpg', 'verified', 9.2, 98.50, 'low'),
    (5, 'docs/id2.jpg', 'selfie2.jpg', 'verified', 8.7, 95.00, 'low'),
    (6, 'docs/id3.jpg', 'selfie3.jpg', 'verified', 9.0, 97.20, 'low'),
    (7, 'docs/id4.jpg', 'selfie4.jpg', 'verified', 8.4, 93.10, 'low'),
    (8, 'docs/id5.jpg', 'selfie5.jpg', 'verified', 8.9, 96.00, 'low'),

    -- NOT VERIFIED AGENTS
    (9, NULL, NULL, 'not_submitted', 2.1, 20.00, 'high'),
    (10, NULL, NULL, 'not_submitted', 3.0, 35.00, 'medium'),
    (11, NULL, NULL, 'not_submitted', 1.8, 15.00, 'high'),
    (12, NULL, NULL, 'not_submitted', 2.5, 25.00, 'high'),
    (13, NULL, NULL, 'not_submitted', 3.2, 40.00, 'medium');



-- PROPERTIES

INSERT INTO properties
    (agent_id, title, description, area_name, latitude, longitude, price, property_type, status)
VALUES

    -- VERIFIED AGENT PROPERTIES
    (4, 'Modern 3 Bedroom House in Namiwawa', 'Well maintained family home with paved driveway and secure fencing.', 'Namiwawa', -15.7800, 35.0150, 85000000, 'house', 'approved'),
    (4, '2 Bedroom Apartment - CBD', 'High rise apartment with security and city view.', 'Blantyre CBD', -15.7861, 35.0058, 55000000, 'apartment', 'approved'),

    (5, 'Luxury 4 Bedroom House Nyambadwe', 'Spacious home with modern finishes and backup power.', 'Nyambadwe', -15.8000, 35.0200, 120000000, 'house', 'approved'),

    (6, 'Starter Home Chinyonga', 'Affordable 2 bedroom home ideal for young families.', 'Chinyonga', -15.7700, 35.0000, 42000000, 'house', 'approved'),

    (7, 'Commercial Plot Chileka Road', 'Prime land suitable for retail or warehouse development.', 'Chileka', -15.6800, 34.9700, 95000000, 'land', 'approved'),

    -- UNVERIFIED AGENTS PROPERTIES (PENDING)
    (9, '3 Bedroom House Mpemba', 'Basic family home in developing area.', 'Mpemba', -15.9000, 34.9500, 38000000, 'house', 'pending'),

    (10, 'Apartment Unit Chitawira', 'Small apartment unit near public transport.', 'Chitawira', -15.8200, 35.0400, 32000000, 'apartment', 'pending'),

    (11, 'Plot in Chirimba', 'Residential plot with access road.', 'Chirimba', -15.8400, 35.0600, 25000000, 'land', 'pending'),

    (12, '2 Bedroom House New Naperi', 'Simple house suitable for rental income.', 'New Naperi', -15.7750, 35.0100, 28000000, 'house', 'pending'),

    (13, 'Small Shop Unit CBD', 'Retail shop space in busy area.', 'Blantyre CBD', -15.7861, 35.0058, 60000000, 'commercial', 'pending');



-- PROPERTY IMAGES

INSERT INTO property_images
    (property_id, image_path, image_hash)
VALUES
    (1, 'houses/namiwawa1.jpg', 'hash1'),
    (1, 'houses/namiwawa2.jpg', 'hash2'),
    (2, 'apartments/cbd1.jpg', 'hash3'),
    (3, 'houses/nyambadwe1.jpg', 'hash4'),
    (4, 'houses/chinyonga1.jpg', 'hash5'),
    (5, 'land/chileka1.jpg', 'hash6');




-- FRAUD LOGS (REALISTIC SYSTEM BEHAVIOR)


INSERT INTO fraud_logs
    (property_id, agent_id, type, message)
VALUES
    (8, 11, 'price', 'Property price unusually below market average'),
    (9, 12, 'gps', 'Location mismatch detected during validation');



-- MARKET RATES 


INSERT INTO zone_market_rates
    (city, property_type, avg_price)
VALUES
    ('Blantyre CBD', 'apartment', 60000000),
    ('Namiwawa', 'house', 90000000),
    ('Nyambadwe', 'house', 110000000),
    ('Chinyonga', 'house', 45000000),
    ('Chileka', 'land', 85000000),
    ('Mpemba', 'house', 40000000),
    ('Chitawira', 'apartment', 35000000),
    ('Chirimba', 'land', 30000000);