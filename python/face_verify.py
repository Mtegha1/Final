import contextlib
import io
import json
import os
import sys
import tempfile
from pathlib import Path

IMPORT_ERROR = None
try:
    import cv2
    import numpy as np
    from PIL import Image, ImageChops
except Exception:
    IMPORT_ERROR = "Required local image-processing packages are not installed."

MODEL_NAME = "ArcFace"
DETECTOR_BACKEND = "retinaface"
DISTANCE_METRIC = "euclidean_l2"
CONFIG_PATH = Path(__file__).resolve().parents[1] / "backend" / "config" / "fraud_config.json"


def response(status, tamper_score=None, match_confidence=None, distance=None,
             reason="", liveness_status="not_checked", ela_heatmap_path=None,
             tamper_flagged=False):
    return {
        "status": status,
        "tamper_score": tamper_score,
        "match_confidence": match_confidence,
        "distance": distance,
        "reason": reason,
        "liveness_status": liveness_status,
        "ela_heatmap_path": ela_heatmap_path,
        "tamper_flagged": bool(tamper_flagged),
    }


def _load_config():
    with CONFIG_PATH.open("r", encoding="utf-8") as config_file:
        config = json.load(config_file)
    identity = config.get("identity")
    if not isinstance(identity, dict) or not isinstance(identity.get("image"), dict):
        raise ValueError("The local fraud configuration is incomplete.")
    return config


def _load_rgb_image(image_path, image_config):
    try:
        source = Image.open(image_path)
    except Exception as exc:
        raise ValueError("The image could not be read. Please upload a JPG or PNG.") from exc

    with source:
        width, height = source.size
        if width * height > image_config["maximum_pixels"]:
            raise ValueError("Image dimensions are too large to process. Resize it and try again.")
        try:
            source.load()
        except Exception as exc:
            raise ValueError("The image could not be read. Please upload a JPG or PNG.") from exc
        image = source.convert("RGB")

    minimum_width = int(image_config["minimum_width"])
    minimum_height = int(image_config["minimum_height"])
    if image.width < minimum_width or image.height < minimum_height:
        raise ValueError(
            f"Image is too small ({image.width}x{image.height}). "
            f"Use an image at least {minimum_width}x{minimum_height} pixels."
        )
    return image


def _detect_face(image_path, image, image_config, anti_spoofing=False):
    from deepface import DeepFace

    try:
        detected = DeepFace.extract_faces(
            img_path=image_path,
            detector_backend=DETECTOR_BACKEND,
            enforce_detection=True,
            align=True,
            color_face="rgb",
            normalize_face=False,
            anti_spoofing=anti_spoofing,
        )
    except ValueError as exc:
        if "face" in str(exc).lower() and "detect" in str(exc).lower():
            raise ValueError("No face was detected. Retake the image with the face clearly visible.") from None
        raise RuntimeError("Local face detection could not process this image.") from None
    if not detected:
        raise ValueError("No face was detected. Retake the image with the face clearly visible.")
    if len(detected) != 1:
        raise ValueError("More than one face was detected. Submit an image showing only one face.")

    face_data = detected[0]
    area = face_data["facial_area"]
    x, y = max(0, int(area["x"])), max(0, int(area["y"]))
    width, height = int(area["w"]), int(area["h"])
    minimum_face_edge = int(image_config["minimum_face_edge"])
    if width < minimum_face_edge or height < minimum_face_edge:
        raise ValueError("The face is too small in the image. Move closer or take a clearer photo.")

    image_array = np.asarray(image)
    face_crop = image_array[y:min(y + height, image.height), x:min(x + width, image.width)]
    if face_crop.size == 0:
        raise ValueError("The detected face region could not be read. Retake the image.")

    gray_face = cv2.cvtColor(face_crop, cv2.COLOR_RGB2GRAY)
    blur_score = float(cv2.Laplacian(gray_face, cv2.CV_64F).var())
    if blur_score < float(image_config["minimum_laplacian_variance"]):
        raise ValueError("The face is blurry. Retake the photo in good light and hold the camera steady.")

    aligned = np.asarray(face_data["face"])
    if aligned.dtype != np.uint8:
        aligned = np.clip(aligned, 0, 255).astype(np.uint8)
    aligned = cv2.cvtColor(aligned, cv2.COLOR_RGB2LAB)
    light, a_channel, b_channel = cv2.split(aligned)
    grid_size = int(image_config["clahe_grid_size"])
    light = cv2.createCLAHE(
        clipLimit=float(image_config["clahe_clip_limit"]),
        tileGridSize=(grid_size, grid_size),
    ).apply(light)
    normalized = cv2.cvtColor(cv2.merge((light, a_channel, b_channel)), cv2.COLOR_LAB2RGB)

    return normalized, face_data


def analyze_ela(image_path, heatmap_path, image_config=None):
    image_config = image_config or _load_config()["identity"]["image"]
    image = _load_rgb_image(image_path, image_config)
    buffer = io.BytesIO()
    image.save(buffer, format="JPEG", quality=int(image_config["jpeg_quality"]))
    buffer.seek(0)

    with Image.open(buffer) as recompressed:
        difference = ImageChops.difference(image, recompressed.convert("RGB"))
        difference_array = np.asarray(difference, dtype=np.uint8)

    pixel_error = difference_array.mean(axis=2)
    score = float(np.count_nonzero(
        pixel_error >= float(image_config["ela_pixel_threshold"])
    )) * 100.0 / pixel_error.size
    amplified = np.clip(
        difference_array.astype(np.float32) * float(image_config["ela_amplification"]),
        0,
        255
    ).astype(np.uint8)
    heatmap = cv2.applyColorMap(cv2.cvtColor(amplified, cv2.COLOR_RGB2GRAY), cv2.COLORMAP_JET)
    heatmap_rgb = cv2.cvtColor(heatmap, cv2.COLOR_BGR2RGB)

    target = Path(heatmap_path)
    target.parent.mkdir(parents=True, exist_ok=True)
    Image.fromarray(heatmap_rgb).save(
        target,
        format="JPEG",
        quality=int(image_config["heatmap_jpeg_quality"])
    )
    return round(score, 2)


def _thresholds_are_valid(thresholds):
    if thresholds.get("calibrated") is not True:
        return False
    try:
        match_accept = float(thresholds["match_accept_distance"])
        match_reject = float(thresholds["match_reject_distance"])
        tamper_review = float(thresholds["tamper_review_score"])
        tamper_reject = float(thresholds["tamper_reject_score"])
    except (KeyError, TypeError, ValueError):
        return False
    return (
        0 <= match_accept <= match_reject and
        0 <= tamper_review <= tamper_reject <= 100
    )


def _classify(distance, match_confidence, tamper_score, liveness_status, thresholds):
    if not _thresholds_are_valid(thresholds):
        return "manual_review", "Verification thresholds are not fully calibrated; admin review is required."

    tamper_review = thresholds.get("tamper_review_score")
    tamper_reject = thresholds.get("tamper_reject_score")
    if tamper_review is not None and tamper_score >= float(tamper_review):
        if tamper_reject is not None and tamper_score >= float(tamper_reject):
            return "rejected", "The ID image exceeded the calibrated tamper threshold."
        return "manual_review", "The ID image is near or above the calibrated tamper review threshold."

    if liveness_status != "passed":
        return "manual_review", "Selfie anti-spoof screening was inconclusive; admin review is required."

    accept_distance = thresholds.get("match_accept_distance")
    reject_distance = thresholds.get("match_reject_distance")
    if accept_distance is None or reject_distance is None:
        return "manual_review", "Verification thresholds are incomplete; admin review is required."

    if distance <= float(accept_distance):
        return "verified", "ID face and selfie match within the calibrated acceptance threshold."
    if distance >= float(reject_distance):
        return "rejected", "ID face and selfie do not match within the calibrated threshold."
    return "manual_review", "Face match is in the calibrated manual-review range."


def verify_files(id_path, selfie_path, heatmap_path):
    config = _load_config()
    identity_config = config["identity"]
    image_config = identity_config["image"]
    id_image = _load_rgb_image(id_path, image_config)
    selfie_image = _load_rgb_image(selfie_path, image_config)

    # Quality checks run before tamper scoring or biometric comparison.
    id_face, _ = _detect_face(id_path, id_image, image_config)
    selfie_face, _ = _detect_face(selfie_path, selfie_image, image_config)

    tamper_score = analyze_ela(id_path, heatmap_path, image_config)
    thresholds = identity_config
    tamper_review = thresholds.get("tamper_review_score")
    tamper_reject = thresholds.get("tamper_reject_score")
    if tamper_review is not None and tamper_score >= float(tamper_review):
        status, reason = _classify(
            0.0, 0.0, tamper_score, "not_checked", thresholds
        )
        return response(
            status,
            tamper_score=tamper_score,
            reason=reason,
            liveness_status="not_checked",
            ela_heatmap_path=str(heatmap_path),
            tamper_flagged=True,
        )

    _, selfie_data = _detect_face(
        selfie_path, selfie_image, image_config, anti_spoofing=True
    )
    liveness_status = "passed" if selfie_data.get("is_real") is True else "review"
    if liveness_status != "passed":
        return response(
            "manual_review",
            tamper_score=tamper_score,
            reason="Selfie anti-spoof screening was inconclusive; admin review is required.",
            liveness_status=liveness_status,
            ela_heatmap_path=str(heatmap_path),
        )

    with tempfile.TemporaryDirectory(prefix="identity_faces_") as temp_dir:
        id_face_path = os.path.join(temp_dir, "id_face.jpg")
        selfie_face_path = os.path.join(temp_dir, "selfie_face.jpg")
        face_jpeg_quality = int(image_config["face_jpeg_quality"])
        Image.fromarray(id_face).save(id_face_path, format="JPEG", quality=face_jpeg_quality)
        Image.fromarray(selfie_face).save(
            selfie_face_path, format="JPEG", quality=face_jpeg_quality
        )

        from deepface import DeepFace

        result = DeepFace.verify(
            img1_path=id_face_path,
            img2_path=selfie_face_path,
            model_name=MODEL_NAME,
            detector_backend=DETECTOR_BACKEND,
            distance_metric=DISTANCE_METRIC,
            enforce_detection=True,
        )

    distance = float(result["distance"])
    model_threshold = float(result["threshold"])
    match_confidence = max(0.0, min(100.0, (1.0 - distance / model_threshold) * 100.0))
    status, reason = _classify(
        distance, match_confidence, tamper_score, liveness_status, thresholds
    )
    return response(
        status,
        tamper_score=tamper_score,
        match_confidence=round(match_confidence, 2),
        distance=round(distance, 6),
        reason=reason,
        liveness_status=liveness_status,
        ela_heatmap_path=str(heatmap_path),
    )


def main():
    result = None
    try:
        if IMPORT_ERROR:
            raise RuntimeError(IMPORT_ERROR)
        if len(sys.argv) != 4:
            result = response(
                "error",
                reason="Verification requires an ID image, a selfie, and a heatmap output path.",
            )
        elif not os.path.isfile(sys.argv[1]) or not os.path.isfile(sys.argv[2]):
            result = response("error", reason="One or both uploaded images could not be found.")
        else:
            # Keep model/library output off stdout; stdout is the JSON protocol for PHP.
            with contextlib.redirect_stdout(io.StringIO()):
                result = verify_files(sys.argv[1], sys.argv[2], sys.argv[3])
    except ValueError as exc:
        result = response("error", reason=str(exc))
    except Exception:
        result = response(
            "error",
            reason="The local verification service could not complete this check. Please retry or request admin assistance.",
        )
    finally:
        print(json.dumps(result or response("error", reason="Verification failed."), ensure_ascii=True))


if __name__ == "__main__":
    main()
