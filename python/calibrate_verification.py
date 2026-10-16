import argparse
import csv
import contextlib
import io
import json
import os
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
from PIL import Image

from face_verify import (
    DETECTOR_BACKEND,
    DISTANCE_METRIC,
    MODEL_NAME,
    _detect_face,
    _load_config,
    _load_rgb_image,
    analyze_ela,
)


def verify_pair(id_path, selfie_path):
    image_config = _load_config()["identity"]["image"]
    id_image = _load_rgb_image(id_path, image_config)
    selfie_image = _load_rgb_image(selfie_path, image_config)
    id_face, _ = _detect_face(id_path, id_image, image_config)
    selfie_face, _ = _detect_face(selfie_path, selfie_image, image_config)

    with tempfile.TemporaryDirectory(prefix="identity_calibration_") as temp_dir:
        id_face_path = os.path.join(temp_dir, "id_face.jpg")
        selfie_face_path = os.path.join(temp_dir, "selfie_face.jpg")
        quality = int(image_config["face_jpeg_quality"])
        Image.fromarray(id_face).save(id_face_path, format="JPEG", quality=quality)
        Image.fromarray(selfie_face).save(selfie_face_path, format="JPEG", quality=quality)
        from deepface import DeepFace

        result = DeepFace.verify(
            img1_path=id_face_path,
            img2_path=selfie_face_path,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            distance_metric=DISTANCE_METRIC,
            enforce_detection=True,
        )
    return float(result["distance"]), float(result["threshold"])


def _select_thresholds(rows):
    genuine = [row["distance"] for row in rows if row["pair_label"] == "genuine"]
    impostors = [row["distance"] for row in rows if row["pair_label"] == "impostor"]
    untouched = [row["tamper_score"] for row in rows if row["id_label"] == "untouched"]
    edited = [row["tamper_score"] for row in rows if row["id_label"] == "edited"]
    if not genuine or not impostors or not untouched or not edited:
        raise ValueError(
            "Calibration needs genuine and impostor pairs and untouched and edited ID images."
        )

    reject_distance = np.ceil(max(genuine) * 1_000_000) / 1_000_000 + 0.000001
    accept_distance = min(
        np.floor(min(impostors) * 1_000_000) / 1_000_000 - 0.000001,
        reject_distance
    )
    if accept_distance < 0:
        raise ValueError(
            "The labelled impostor samples do not support a non-negative threshold with zero false accepts; "
            "keep identity auto-decisions disabled."
        )

    maximum_untouched_score = max(untouched)
    tamper_review = round(maximum_untouched_score + 0.01, 2)
    if tamper_review > 100:
        raise ValueError(
            "Untouched ID images reach the maximum ELA score; no safe tamper threshold can be selected."
        )
    tamper_reject = max(tamper_review, round(float(np.median(edited)), 2))
    tamper_reject = min(100.0, tamper_reject)

    false_accepts = sum(value <= accept_distance for value in impostors)
    false_rejects = sum(value >= reject_distance for value in genuine)
    untouched_flagged = sum(value >= tamper_review for value in untouched)
    untouched_rejected = sum(value >= tamper_reject for value in untouched)
    edited_flagged = sum(value >= tamper_review for value in edited)
    edited_rejected = sum(value >= tamper_reject for value in edited)
    return {
        "match_accept_distance": float(accept_distance),
        "match_reject_distance": float(reject_distance),
        "tamper_review_score": float(tamper_review),
        "tamper_reject_score": float(tamper_reject),
        "sample_counts": {
            "genuine_pairs": len(genuine),
            "impostor_pairs": len(impostors),
            "untouched_ids": len(untouched),
            "edited_ids": len(edited)
        },
        "false_accept_rate_pct": 100.0 * false_accepts / len(impostors),
        "false_reject_rate_pct": 100.0 * false_rejects / len(genuine),
        "untouched_id_review_or_reject_pct": 100.0 * untouched_flagged / len(untouched),
        "untouched_id_reject_pct": 100.0 * untouched_rejected / len(untouched),
        "edited_id_flagged_pct": 100.0 * edited_flagged / len(edited),
        "edited_id_reject_pct": 100.0 * edited_rejected / len(edited)
    }


def _resolve_path(value, manifest_dir):
    path = Path(value)
    return path if path.is_absolute() else manifest_dir / path


def _write_match_thresholds(rows, output_path):
    genuine = [row["distance"] for row in rows if row["pair_label"] == "genuine"]
    impostors = [row["distance"] for row in rows if row["pair_label"] == "impostor"]
    if not genuine or not impostors:
        raise ValueError("Calibration requires at least one genuine and one impostor pair.")

    candidates = np.unique(np.concatenate((
        np.linspace(min(genuine + impostors), max(genuine + impostors), 51),
        np.asarray([row["model_threshold"] for row in rows]),
    )))
    with output_path.open("w", newline="", encoding="utf-8") as target:
        writer = csv.writer(target)
        writer.writerow([
            "match_accept_distance", "match_reject_distance",
            "false_accept_count", "impostor_count",
            "false_accept_rate_pct", "false_reject_rate_pct",
            "false_reject_count", "genuine_count",
            "genuine_manual_review_pct", "impostor_manual_review_pct",
        ])
        for accept_index, accept_threshold in enumerate(candidates):
            for reject_threshold in candidates[accept_index:]:
                false_accepts = sum(distance <= accept_threshold for distance in impostors)
                false_rejects = sum(distance >= reject_threshold for distance in genuine)
                genuine_reviews = sum(accept_threshold < distance < reject_threshold for distance in genuine)
                impostor_reviews = sum(accept_threshold < distance < reject_threshold for distance in impostors)
                writer.writerow([
                    f"{accept_threshold:.6f}",
                    f"{reject_threshold:.6f}",
                    false_accepts,
                    len(impostors),
                    f"{100.0 * false_accepts / len(impostors):.2f}",
                    f"{100.0 * false_rejects / len(genuine):.2f}",
                    false_rejects,
                    len(genuine),
                    f"{100.0 * genuine_reviews / len(genuine):.2f}",
                    f"{100.0 * impostor_reviews / len(impostors):.2f}",
                ])


def _write_tamper_thresholds(rows, output_path):
    untouched = [row["tamper_score"] for row in rows if row["id_label"] == "untouched"]
    edited = [row["tamper_score"] for row in rows if row["id_label"] == "edited"]
    if not untouched or not edited:
        raise ValueError("Calibration requires untouched and edited ID images.")

    candidates = np.unique(np.linspace(min(untouched + edited), max(untouched + edited), 51))
    with output_path.open("w", newline="", encoding="utf-8") as target:
        writer = csv.writer(target)
        writer.writerow([
            "tamper_review_score", "tamper_reject_score",
            "untouched_review_or_reject_count", "untouched_id_count",
            "untouched_review_or_reject_pct", "untouched_reject_pct",
            "untouched_reject_count",
            "edited_review_or_reject_count", "edited_id_count",
            "edited_review_or_reject_pct", "edited_reject_pct",
            "edited_reject_count",
        ])
        for review_index, review_threshold in enumerate(candidates):
            for reject_threshold in candidates[review_index:]:
                untouched_flagged = sum(score >= review_threshold for score in untouched)
                untouched_rejected = sum(score >= reject_threshold for score in untouched)
                edited_flagged = sum(score >= review_threshold for score in edited)
                edited_rejected = sum(score >= reject_threshold for score in edited)
                writer.writerow([
                    f"{review_threshold:.2f}",
                    f"{reject_threshold:.2f}",
                    untouched_flagged,
                    len(untouched),
                    f"{100.0 * untouched_flagged / len(untouched):.2f}",
                    f"{100.0 * untouched_rejected / len(untouched):.2f}",
                    untouched_rejected,
                    edited_flagged,
                    len(edited),
                    f"{100.0 * edited_flagged / len(edited):.2f}",
                    f"{100.0 * edited_rejected / len(edited):.2f}",
                    edited_rejected,
                ])


def run(manifest_path, output_dir, apply_thresholds=False):
    manifest_path = Path(manifest_path).resolve()
    output_dir = Path(output_dir).resolve()
    output_dir.mkdir(parents=True, exist_ok=True)
    rows = []
    heatmap_path = output_dir / "calibration_ela_heatmap.jpg"

    with manifest_path.open("r", newline="", encoding="utf-8-sig") as source:
        reader = csv.DictReader(source)
        required = {"id_image", "selfie_image", "pair_label", "id_label"}
        if not required.issubset(reader.fieldnames or []):
            raise ValueError("Manifest needs id_image, selfie_image, pair_label, and id_label columns.")

        for index, sample in enumerate(reader, start=1):
            pair_label = sample["pair_label"].strip().lower()
            id_label = sample["id_label"].strip().lower()
            if pair_label not in {"genuine", "impostor"}:
                raise ValueError(f"Manifest row {index}: pair_label must be genuine or impostor.")
            if id_label not in {"untouched", "edited"}:
                raise ValueError(f"Manifest row {index}: id_label must be untouched or edited.")

            id_path = _resolve_path(sample["id_image"], manifest_path.parent)
            selfie_path = _resolve_path(sample["selfie_image"], manifest_path.parent)
            with contextlib.redirect_stdout(io.StringIO()):
                distance, model_threshold = verify_pair(str(id_path), str(selfie_path))
                tamper_score = analyze_ela(
                    str(id_path),
                    str(heatmap_path),
                    _load_config()["identity"]["image"]
                )
            rows.append({
                "sample": index,
                "pair_label": pair_label,
                "id_label": id_label,
                "distance": distance,
                "model_threshold": model_threshold,
                "tamper_score": tamper_score,
            })

    if not rows:
        raise ValueError("The calibration manifest contains no image pairs.")

    results_path = output_dir / "calibration_samples.csv"
    with results_path.open("w", newline="", encoding="utf-8") as target:
        writer = csv.DictWriter(target, fieldnames=list(rows[0].keys()))
        writer.writeheader()
        writer.writerows(rows)

    _write_match_thresholds(rows, output_dir / "match_thresholds.csv")
    _write_tamper_thresholds(rows, output_dir / "tamper_thresholds.csv")
    selected = _select_thresholds(rows)
    selection_path = output_dir / "calibration_selection.json"
    selection_path.write_text(
        json.dumps(selected, indent=2, sort_keys=True) + "\n",
        encoding="utf-8"
    )
    if apply_thresholds:
        config = _load_config()
        config["identity"].update({
            "calibrated": True,
            "match_accept_distance": selected["match_accept_distance"],
            "match_reject_distance": selected["match_reject_distance"],
            "tamper_review_score": selected["tamper_review_score"],
            "tamper_reject_score": selected["tamper_reject_score"],
            "calibration": {
                "calibrated_at_utc": datetime.now(timezone.utc).isoformat(),
                "sample_counts": selected["sample_counts"],
                "false_accept_rate_pct": selected["false_accept_rate_pct"],
                "false_reject_rate_pct": selected["false_reject_rate_pct"],
                "untouched_id_review_or_reject_pct": selected["untouched_id_review_or_reject_pct"],
                "untouched_id_reject_pct": selected["untouched_id_reject_pct"],
                "edited_id_flagged_pct": selected["edited_id_flagged_pct"],
                "edited_id_reject_pct": selected["edited_id_reject_pct"]
            }
        })
        config_path = Path(__file__).resolve().parents[1] / "backend" / "config" / "fraud_config.json"
        temporary_path = config_path.with_suffix(".json.tmp")
        temporary_path.write_text(
            json.dumps(config, indent=2, sort_keys=True) + "\n",
            encoding="utf-8"
        )
        temporary_path.replace(config_path)
    print(json.dumps({
        "status": "success",
        "sample_count": len(rows),
        "results": str(results_path),
        "match_thresholds": str(output_dir / "match_thresholds.csv"),
        "tamper_thresholds": str(output_dir / "tamper_thresholds.csv"),
        "selection": str(selection_path),
        "applied": bool(apply_thresholds),
        "calibration_metrics": selected
    }))


def main():
    parser = argparse.ArgumentParser(
        description="Evaluate face-match and ELA thresholds on a labelled, local test set."
    )
    parser.add_argument("manifest", help="CSV manifest with labelled image pairs.")
    parser.add_argument("--output-dir", default="calibration-results")
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Write selected thresholds into the shared fraud configuration."
    )
    args = parser.parse_args()
    try:
        run(args.manifest, args.output_dir, args.apply)
    except Exception as exc:
        print(json.dumps({"status": "error", "reason": str(exc)}))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
