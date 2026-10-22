# IREA local application

Iconics Real Estate Agency's application runs on XAMPP (Apache/PHP and
MariaDB/MySQL), plain HTML/CSS/JavaScript with Bootstrap, and the local Python
environment for image checks. The demo has no runtime external service
dependency.

## Start on XAMPP

1. Put this project in `C:\xampp\htdocs\Final`, then start Apache and MySQL.
2. For a **new, empty** database only, import `backend/config/schema.sql` in
   phpMyAdmin. It deliberately recreates `iconics_db`; never run it against an
   existing database. For an existing installation, back up `iconics_db` and
   apply `backend/config/migration_fraud_rebuild.sql` instead. Do not apply both.
3. Check that `backend/config/database.php` matches the local MySQL account.
   The checked-in defaults are XAMPP's local `root` account with an empty
   password; use local environment-specific settings if yours differ.
4. From the project root, install the local Python dependencies:

   ```powershell
   py -3.11 -m venv python\.venv
   .\python\.venv\Scripts\python.exe -m pip install -r .\python\requirements.txt
   ```

   DeepFace model weights must be installed once before running fully offline.
   The image-verification and perceptual-hash scripts do not call external
   APIs.
5. Create or promote an administrator account in a local PowerShell window:

   ```powershell
   .\backend\tools\create_admin.ps1
   ```

   The helper prompts for the password without echoing it. Credentials are not
   stored in the repository. Public registration accepts only agent and client
   roles.
6. After an administrator exists, apply
   `backend/config/migration_admin_rental_listings.sql` to add admin-created
   drafts and agent assignment support. This migration is safe to rerun; it
   creates three clearly marked draft slots in each of the ten requested areas.
   Drafts have no fabricated rent, features, or photos and are not public.
7. Open `http://localhost/Final/frontend/index.html`. Agents can register and
   sign in; identity results remain in admin review until local calibration
   thresholds are applied.

## Listings, zones, and rent baselines

Listing creation uses only active entries in `blantyre_zones`. The local
interactive zone sketch and browser GPS do not fetch map tiles or geocode
addresses. The initial circles are provisional, derived from mapped
neighborhood extents, and should be checked locally before being treated as
official boundaries. The initial supported areas are Nyambadwe, Chinyonga,
Chitawira, and Chirimba. Other areas remain inactive until their centers and
radii are curated. Admin-created rental drafts in the other requested areas
must remain drafts until their real property details, photos, and exact pins
are added. Curate GPS zones from verified geographic data before relying on
GPS checks for those areas.

Add an area using phpMyAdmin or the local MySQL console, replacing the example
values with checked coordinates and a conservative radius:

```sql
INSERT INTO blantyre_zones
    (area_name, latitude, longitude, radius_m, is_active, source)
VALUES
    ('New Area', -15.80, 35.02, 1200, 1, 'Locally surveyed boundary, YYYY-MM');
```

The existing `agent/dashboard` API response supplies the active area list to
the listing form; no new endpoint is required. Document the evidence and
review date in `source` when curating each zone.

Monthly-rent market baselines are intentionally **not seeded**: the old sample
values were not validated as rental averages. A missing baseline is logged as
an unavailable price signal and prevents auto-approval; it is not silently
treated as a clean price check. After collecting a reviewed local average,
insert or update it for the area and property type:

```sql
INSERT INTO zone_market_rates (area_name, property_type, average_monthly_rent)
VALUES ('<area_name>', '<property_type>', <reviewed_monthly_rent_mwk>)
ON DUPLICATE KEY UPDATE average_monthly_rent = VALUES(average_monthly_rent);
```

Replace every placeholder with a measured value before executing; the old
`avg_price` values are retained only for compatibility and are not read as
monthly rent.

All fraud and risk tuning values live in
`backend/config/fraud_config.json`. The suggested price, duplicate-image, GPS
normalization, weights, and risk-band values are starting settings, not
empirical findings. Tune them against reviewed local listings. Every completed
identity, price, GPS, and duplicate-image check records its result and
structured details in `fraud_logs`. Property checks never reject a listing:
the composite score can auto-approve only a low-risk listing with all four
signals available; medium, high, or incomplete results stay pending for an
admin.

Zone coordinates were researched from OpenStreetMap neighborhood mappings.
Map data © OpenStreetMap contributors, available under the Open Database
License (ODbL) 1.0: <https://www.openstreetmap.org/copyright>. The application
does not make runtime OpenStreetMap requests.

## Identity calibration and tests

The committed config deliberately has identity auto-decisions disabled. No
labelled, permissioned sample images were supplied, so this repository does
not claim measured false-accept or false-reject rates. Follow
[the calibration guide](python/IDENTITY_VERIFICATION.md) to evaluate local
genuine pairs, impostor pairs, untouched IDs, and deliberately edited IDs.
Only apply thresholds after reviewing calibration and held-out results.

Run the Python unit tests:

```powershell
.\python\.venv\Scripts\python.exe -m unittest discover -s .\python -p "test_*.py"
& C:\xampp\php\php.exe .\backend\tests\risk_scoring_test.php
```

Lint changed PHP files with XAMPP's PHP CLI:

```powershell
Get-ChildItem .\backend -Recurse -Filter *.php |
    ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }
```
