# Local identity verification and calibration

## Verification flow

`face_verify.py` checks image dimensions, detects exactly one face in each
image, and returns a retake error for small, blurry, or faceless images. Only
after both images pass the quality gate does it compute ELA and compare
CLAHE-normalised, aligned face crops with ArcFace, RetinaFace, and
`euclidean_l2`. The ID heatmap is kept with the private identity uploads.

The selfie receives DeepFace's local anti-spoof screen. This is a passive
presentation-attack screen, not proof that the selfie was captured live. An
inconclusive result requires admin review. No identity image or face embedding
is returned to the browser.

Uploads are outside the public document root by default:
`<parent of DOCUMENT_ROOT>/private_uploads/identity`. Set the absolute
`IDENTITY_UPLOAD_DIR` environment variable to use another private directory.
Legacy files in `uploads/ids` are denied direct HTTP access and served to
admins only through the authenticated admin endpoint.

## Install on a Windows laptop

Install Python 3.11. From the project root:

```powershell
py -3.11 -m venv python\.venv
.\python\.venv\Scripts\python.exe -m pip install -r .\python\requirements.txt
```

DeepFace model weights must be installed locally before running without
network access. The application does not call an external identity service.

## Calibration set and thresholds

All identity image-quality, ELA, face-match, price, duplicate-image, GPS-risk,
and composite-risk parameters are in
`backend/config/fraud_config.json`. Identity decisions are intentionally
uncalibrated in the committed configuration: all valid pairs remain in manual
review until a permissioned, representative labelled set is evaluated.

Keep calibration images and results private and local; do not commit real
National IDs, selfies, manifests containing personal information, or raw
biometric material. Collect independently labelled genuine pairs, impostor
pairs, untouched ID images, and deliberately edited IDs (including pasted
photos and altered text). Include worn/faded IDs, low-cost camera captures,
lighting variation, and blurred cases. Keep people in only one split when
reporting held-out performance.

Create a local CSV manifest with paths relative to the manifest:

```csv
id_image,selfie_image,pair_label,id_label
genuine/id_01.jpg,genuine/selfie_01.jpg,genuine,untouched
impostor/id_01.jpg,impostor/selfie_01.jpg,impostor,untouched
edited/id_01.jpg,genuine/selfie_01.jpg,genuine,edited
```

Run the evaluation to produce the candidate grid, per-sample measurements, and
the selected operating point:

```powershell
.\python\.venv\Scripts\python.exe .\python\calibrate_verification.py .\calibration\manifest.csv --output-dir .\calibration-results
```

The selected face-match operating point prioritises zero false accepts and
zero false rejects on the calibration samples. If the labelled samples do not
support both constraints simultaneously, the tool refuses to enable automatic
identity decisions. ELA review begins above the maximum untouched-ID score;
the reject point is selected from edited-ID scores. These rates are calibration
results, not guarantees about future or held-out users.

Only after reviewing the ranges and testing a separate held-out set, apply the
selected operating point to the shared runtime configuration:

```powershell
.\python\.venv\Scripts\python.exe .\python\calibrate_verification.py .\calibration\manifest.csv --output-dir .\calibration-results --apply
```

Generated files:

| File | Contents |
|---|---|
| `calibration_samples.csv` | Pair label, distance, model reference threshold, and ELA score per row |
| `match_thresholds.csv` | Accept/reject threshold grid with false-accept, false-reject, and review rates |
| `tamper_thresholds.csv` | ELA review/reject grid with untouched false alerts and edited-ID detections |
| `calibration_selection.json` | The selected thresholds, sample counts, and measured rates |

### Evaluation results for the report

No labelled local calibration dataset was present when this implementation was
prepared, so there are no measured FAR/FRR values or selected identity
thresholds to report yet. Do not replace these blanks with simulated results.
Copy the measured counts and rates from `calibration_selection.json` and
`calibration_samples.csv` after running the exercise:

| Split | Genuine pairs | Impostor pairs | False accepts | FAR (%) | False rejects | FRR (%) | Untouched ID false alerts (%) | Edited ID flagged (%) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Calibration | — | — | — | — | — | — | — | — |
| Held-out test | — | — | — | — | — | — | — | — |

The UI's match confidence is a linear rescaling against DeepFace's model
reference distance; it is not a probability or a percentage accuracy claim.

## Local tests

```powershell
.\python\.venv\Scripts\python.exe -m unittest discover -s .\python -p "test_*.py"
```
