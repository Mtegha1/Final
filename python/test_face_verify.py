import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import Image, ImageDraw

import face_verify
import calibrate_verification


class FaceVerifyTests(unittest.TestCase):
    def test_bad_size_is_a_retake_error(self):
        with tempfile.TemporaryDirectory() as directory:
            image_path = Path(directory) / "small.png"
            Image.new("RGB", (100, 100), "white").save(image_path)
            with self.assertRaisesRegex(ValueError, "too small"):
                face_verify._load_rgb_image(
                    image_path,
                    face_verify._load_config()["identity"]["image"]
                )

    def test_ela_score_and_heatmap_are_written(self):
        with tempfile.TemporaryDirectory() as directory:
            image_path = Path(directory) / "id.png"
            heatmap_path = Path(directory) / "heatmap.jpg"
            image = Image.new("RGB", (320, 240), "white")
            draw = ImageDraw.Draw(image)
            for x in range(0, 320, 8):
                draw.line((x, 0, x, 239), fill=(x % 256, 30, 100), width=2)
            image.save(image_path)

            score = face_verify.analyze_ela(image_path, heatmap_path)

            self.assertGreaterEqual(score, 0)
            self.assertLessEqual(score, 100)
            self.assertTrue(heatmap_path.is_file())

    def test_unconfigured_thresholds_never_auto_verify_or_reject(self):
        result = face_verify._classify(
            0.01, 99.0, 0.0, "passed", {"calibrated": False}
        )
        self.assertEqual(result[0], "manual_review")
        self.assertIn("not fully calibrated", result[1])

    def test_partial_thresholds_never_auto_verify(self):
        result = face_verify._classify(
            0.01,
            99.0,
            0.0,
            "passed",
            {"calibrated": True, "match_accept_distance": 0.1, "match_reject_distance": 0.2},
        )
        self.assertEqual(result[0], "manual_review")

    def test_calibrated_thresholds_apply_review_band(self):
        thresholds = {
            "calibrated": True,
            "match_accept_distance": 0.4,
            "match_reject_distance": 0.7,
            "tamper_review_score": 20,
            "tamper_reject_score": 60,
        }
        self.assertEqual(face_verify._classify(0.2, 80, 5, "passed", thresholds)[0], "verified")
        self.assertEqual(face_verify._classify(0.5, 50, 5, "passed", thresholds)[0], "manual_review")
        self.assertEqual(face_verify._classify(0.8, 0, 5, "passed", thresholds)[0], "rejected")
        self.assertEqual(face_verify._classify(0.2, 80, 65, "passed", thresholds)[0], "rejected")

    def test_calibration_selection_uses_zero_face_error_thresholds(self):
        rows = [
            {"pair_label": "genuine", "id_label": "untouched", "distance": 0.2, "tamper_score": 1},
            {"pair_label": "genuine", "id_label": "untouched", "distance": 0.3, "tamper_score": 2},
            {"pair_label": "impostor", "id_label": "edited", "distance": 0.7, "tamper_score": 20},
            {"pair_label": "impostor", "id_label": "edited", "distance": 0.9, "tamper_score": 30},
        ]
        selected = calibrate_verification._select_thresholds(rows)
        self.assertLess(selected["match_accept_distance"], 0.7)
        self.assertGreater(selected["match_reject_distance"], 0.3)
        self.assertEqual(selected["false_accept_rate_pct"], 0)
        self.assertEqual(selected["false_reject_rate_pct"], 0)
        self.assertGreater(selected["tamper_review_score"], 2)

    def test_cli_always_returns_json_for_invalid_arguments(self):
        script = Path(__file__).with_name("face_verify.py")
        completed = subprocess.run(
            [sys.executable, str(script)],
            capture_output=True,
            text=True,
            check=False,
        )
        result = json.loads(completed.stdout)
        self.assertEqual(result["status"], "error")
        self.assertIn("requires", result["reason"])


if __name__ == "__main__":
    unittest.main()
