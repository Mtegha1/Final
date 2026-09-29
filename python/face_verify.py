import sys
import json
import os
import io

from PIL import Image, ImageChops, ImageStat

ELA_JPEG_QUALITY = 90
ELA_VARIANCE_THRESHOLD = 500.0


def analyze_ela(image_path):
    with Image.open(image_path) as source:
        original = source.convert("RGB")

    recompressed_buffer = io.BytesIO()
    original.save(recompressed_buffer, format="JPEG", quality=ELA_JPEG_QUALITY)
    recompressed_buffer.seek(0)

    with Image.open(recompressed_buffer) as recompressed_image:
        difference = ImageChops.difference(original, recompressed_image.convert("RGB"))

    variance = sum(ImageStat.Stat(difference).var) / 3
    score = min(100.0, variance / ELA_VARIANCE_THRESHOLD * 100)
    return {
        "ela_variance": round(variance, 2),
        "tamper_score": round(score, 2),
        "tamper_flagged": variance > ELA_VARIANCE_THRESHOLD,
    }

try:

    if len(sys.argv) < 3:
        raise Exception("Usage: face_verify.py <id_path> <selfie_path>")

    id_image = sys.argv[1]
    selfie_image = sys.argv[2]

    if not os.path.exists(id_image):
        raise Exception(f"ID image not found: {id_image}")

    if not os.path.exists(selfie_image):
        raise Exception(f"Selfie image not found: {selfie_image}")

    ela_result = analyze_ela(id_image)
    if ela_result["tamper_flagged"]:
        print(json.dumps({"status": "tampered", **ela_result}))
        sys.exit(0)

    from deepface import DeepFace

    result = DeepFace.verify(
        img1_path=id_image,
        img2_path=selfie_image,
        model_name="Facenet512",
        detector_backend="retinaface",
        enforce_detection=False
    )

    distance = float(result["distance"])

    confidence = max(
        0,
        min(
            100,
            round((1 - distance) * 100, 2)
        )
    )

    response = {
        "status": "success",
        "verified": bool(result["verified"]),
        "confidence": confidence,
        "distance": distance,
        **ela_result
    }

    print(json.dumps(response))

except Exception as e:

    error_response = {
        "status": "error",
        "error": str(e)
    }

    print(json.dumps(error_response))

    sys.exit(1)