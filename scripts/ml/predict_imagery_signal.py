#!/usr/bin/env python3
import base64
import json
import os
import pickle
import sys


def baseline(features):
    premise = float(features.get("premise_count_140m", 0) or 0)
    score = float(features.get("normalized_score_0_100", 0) or 0)
    has_premise = bool(features.get("has_premise", False))

    if premise >= 3 or score >= 70 or (has_premise and score >= 55):
        built = min(0.95, max(0.55, score / 100.0))
        open_p = max(0.02, 1.0 - built - 0.08)
        mixed = max(0.03, 1.0 - built - open_p)
        cls = "built"
    elif premise <= 0 and score <= 35:
        open_p = min(0.95, max(0.55, 1.0 - score / 100.0))
        built = max(0.02, 1.0 - open_p - 0.08)
        mixed = max(0.03, 1.0 - open_p - built)
        cls = "open"
    else:
        cls = "mixed"
        mixed, built, open_p = 0.52, 0.24, 0.24

    return {
        "class": cls,
        "built_probability": round(float(built), 4),
        "open_probability": round(float(open_p), 4),
        "mixed_probability": round(float(mixed), 4),
        "confidence": round(max(float(built), float(open_p), float(mixed)), 4),
        "model_version": "imagery-baseline-v1",
    }


def from_probs(class_names, probs):
    mapping = {c: 0.0 for c in ["open", "built", "mixed"]}
    for i, c in enumerate(class_names):
        if c in mapping:
            mapping[c] = float(probs[i])
    cls = max(mapping, key=mapping.get)
    return {
        "class": cls,
        "built_probability": round(mapping["built"], 4),
        "open_probability": round(mapping["open"], 4),
        "mixed_probability": round(mapping["mixed"], 4),
        "confidence": round(float(mapping[cls]), 4),
    }


def predict_with_model(model, features):
    model_type = model.get("type")
    feature_columns = model.get("feature_columns") or []
    x = [float(features.get(c, 0) or 0) for c in feature_columns]

    if model_type == "lightgbm":
        import numpy as np
        import lightgbm as lgb
        booster_txt = (((model.get("payload") or {}).get("booster")) or "")
        if not booster_txt:
            raise RuntimeError("Missing LightGBM payload")
        booster = lgb.Booster(model_str=booster_txt)
        probs = booster.predict(np.array([x], dtype=float))[0]
        class_names = model.get("class_names") or ["built", "mixed", "open"]
        out = from_probs(class_names, probs)
        out["model_version"] = model.get("model_version", "unknown")
        return out

    if model_type == "gradient_boosting":
        import numpy as np
        payload = (model.get("payload") or {}).get("pickle_b64")
        if not payload:
            raise RuntimeError("Missing gradient_boosting payload")
        clf = pickle.loads(base64.b64decode(payload.encode("ascii")))
        probs = clf.predict_proba(np.array([x], dtype=float))[0]
        class_names = model.get("class_names") or ["built", "mixed", "open"]
        out = from_probs(class_names, probs)
        out["model_version"] = model.get("model_version", "unknown")
        return out

    # prior model
    priors = model.get("label_priors") or {}
    mapping = {
        "open": float(priors.get("open", 0.24)),
        "built": float(priors.get("built", 0.24)),
        "mixed": float(priors.get("mixed", 0.52)),
    }
    cls = max(mapping, key=mapping.get)
    return {
        "class": cls,
        "built_probability": round(mapping["built"], 4),
        "open_probability": round(mapping["open"], 4),
        "mixed_probability": round(mapping["mixed"], 4),
        "confidence": round(mapping[cls], 4),
        "model_version": model.get("model_version", "unknown"),
    }


def main():
    raw = sys.stdin.read().strip()
    if not raw:
        print(json.dumps({"class": "mixed", "built_probability": 0.24, "open_probability": 0.24, "mixed_probability": 0.52, "confidence": 0.52, "model_version": "imagery-baseline-v1"}))
        return

    payload = json.loads(raw)
    features = payload.get("features", {}) or {}
    model_path = payload.get("model_path")

    result = baseline(features)
    if model_path and os.path.isfile(model_path):
        try:
            with open(model_path, "r", encoding="utf-8") as f:
                model = json.load(f)
            result = predict_with_model(model, features)
        except Exception:
            pass

    print(json.dumps(result))


if __name__ == "__main__":
    main()
