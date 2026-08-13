#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import sys


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Predict CAD expert-review risk using LightGBM model.")
    parser.add_argument("--model", required=True, help="Path to LightGBM model text file.")
    parser.add_argument("--features", required=True, help="JSON object with numeric features.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    features = json.loads(args.features)

    # Lazy import so the caller can fall back if package is missing.
    import lightgbm as lgb  # type: ignore

    feature_order = [
        "total_rules",
        "pass_count",
        "fail_count",
        "needs_review_count",
        "warn_count",
        "pass_ratio",
        "fail_ratio",
        "needs_review_ratio",
        "warn_ratio",
    ]
    row = [[float(features.get(k, 0.0)) for k in feature_order]]

    booster = lgb.Booster(model_file=args.model)
    pred = booster.predict(row)
    prob = float(pred[0]) if isinstance(pred, (list, tuple)) else float(pred)

    label = "high_risk" if prob >= 0.7 else ("medium_risk" if prob >= 0.35 else "low_risk")
    out = {
        "needs_expert_review_probability": round(prob, 6),
        "risk_score": round(prob * 100.0, 2),
        "advisory_label": label,
    }
    print(json.dumps(out))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:  # noqa: BLE001
        sys.stderr.write(str(exc) + "\n")
        raise

