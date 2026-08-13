#!/usr/bin/env python3
import argparse
import csv
import json
import os
import sys
from datetime import datetime

LABELS = {"open", "built", "mixed"}


def load_manifest_features(manifest_path):
    feats = {}
    with open(manifest_path, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            row = json.loads(line)
            app_id = int(row.get("application_id", 0) or 0)
            if app_id <= 0:
                continue
            geo = {}
            gp = row.get("geocode_json_path")
            if gp:
                local_gp = gp
                if not os.path.isabs(local_gp):
                    local_gp = os.path.join("storage", "app", gp.replace("storage/app/", ""))
                if os.path.isfile(local_gp):
                    try:
                        with open(local_gp, "r", encoding="utf-8") as gf:
                            geo = json.load(gf) or {}
                    except Exception:
                        geo = {}
            diag = (geo.get("signal_diagnostics") or {}) if isinstance(geo, dict) else {}
            feats[app_id] = {
                "premise_count_140m": float(diag.get("premise_count_140m", 0) or 0),
                "poi_count_170m": float(diag.get("poi_count_170m", 0) or 0),
                "establishment_count_170m": float(diag.get("establishment_count_170m", 0) or 0),
                "has_premise": 1.0 if bool(diag.get("has_premise", False)) else 0.0,
                "has_street_number": 1.0 if bool(diag.get("has_street_number", False)) else 0.0,
                "normalized_score_0_100": float(diag.get("normalized_score_0_100", 0) or 0),
            }
    return feats


def baseline_model(counts):
    total = sum(counts.values())
    priors = {k: (counts[k] / total if total else 0.0) for k in counts}
    return {
        "model_version": f"imagery-prior-{datetime.utcnow().strftime('%Y%m%d%H%M%S')}",
        "type": "scaffold_prior_model",
        "label_counts": counts,
        "label_priors": priors,
        "feature_columns": [
            "premise_count_140m", "poi_count_170m", "establishment_count_170m",
            "has_premise", "has_street_number", "normalized_score_0_100"
        ],
        "note": "Fallback prior model used because ML libs/data were insufficient.",
    }


def main():
    p = argparse.ArgumentParser(description="Train imagery signal model")
    p.add_argument("--labels-csv", required=True, help="CSV: application_id,label")
    p.add_argument("--manifest", default="", help="Manifest JSONL from bp:imagery-dataset:collect")
    p.add_argument("--out", required=True, help="Output model JSON path")
    args = p.parse_args()

    if not os.path.isfile(args.labels_csv):
        print(
            "Labels CSV not found: "
            + args.labels_csv
            + "\nCreate it first (example):\n"
            + "  php artisan bp:imagery-dataset:collect --limit=500 --download-static\n"
            + "  php artisan bp:imagery-labels:template\n"
            + "Then fill label column with open/built/mixed and run training again.",
            file=sys.stderr,
        )
        sys.exit(1)

    label_rows = []
    counts = {"open": 0, "built": 0, "mixed": 0}
    with open(args.labels_csv, "r", encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            try:
                app_id = int(str(row.get("application_id", "")).strip())
            except Exception:
                continue
            label = str(row.get("label", "")).strip().lower()
            if app_id > 0 and label in LABELS:
                label_rows.append((app_id, label))
                counts[label] += 1

    model = None
    feature_rows = {}
    if args.manifest and os.path.isfile(args.manifest):
        feature_rows = load_manifest_features(args.manifest)

    dataset = []
    for app_id, label in label_rows:
        if app_id in feature_rows:
            dataset.append((feature_rows[app_id], label))

    if len(dataset) >= 20:
        try:
            import numpy as np
            feature_columns = [
                "premise_count_140m", "poi_count_170m", "establishment_count_170m",
                "has_premise", "has_street_number", "normalized_score_0_100"
            ]
            X = np.array([[row[c] for c in feature_columns] for row, _ in dataset], dtype=float)
            y_labels = [lbl for _, lbl in dataset]
            class_names = sorted(list(LABELS))
            class_to_idx = {c: i for i, c in enumerate(class_names)}
            y = np.array([class_to_idx[v] for v in y_labels], dtype=int)

            model_type = "gradient_boosting"
            model_payload = None

            # Try LightGBM first
            try:
                import lightgbm as lgb
                clf = lgb.LGBMClassifier(objective="multiclass", n_estimators=200, learning_rate=0.06, num_leaves=31)
                clf.fit(X, y)
                model_type = "lightgbm"
                model_payload = {
                    "booster": clf.booster_.model_to_string(),
                }
                train_probs = clf.predict_proba(X)
                train_pred = np.argmax(train_probs, axis=1)
            except Exception:
                from sklearn.ensemble import GradientBoostingClassifier
                clf = GradientBoostingClassifier(random_state=42)
                clf.fit(X, y)
                # serialize sklearn model as base64 pickle
                import base64, pickle
                model_payload = {
                    "pickle_b64": base64.b64encode(pickle.dumps(clf)).decode("ascii"),
                }
                # approximate proba fallback
                if hasattr(clf, "predict_proba"):
                    train_probs = clf.predict_proba(X)
                    train_pred = np.argmax(train_probs, axis=1)
                else:
                    train_pred = clf.predict(X)
                    train_probs = None

            acc = float((train_pred == y).mean()) if len(y) else 0.0
            model = {
                "model_version": f"imagery-{model_type}-{datetime.utcnow().strftime('%Y%m%d%H%M%S')}",
                "type": model_type,
                "class_names": class_names,
                "feature_columns": feature_columns,
                "train_size": int(len(y)),
                "train_accuracy": round(acc, 4),
                "label_counts": counts,
                "payload": model_payload,
                "note": "Structured-feature classifier; advisory only.",
            }
        except Exception:
            model = None

    if model is None:
        model = baseline_model(counts)

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as f:
        json.dump(model, f, indent=2)

    print(json.dumps({"ok": True, "model_path": args.out, "model_type": model.get("type"), "label_counts": counts, "train_size": model.get("train_size", 0)}))


if __name__ == "__main__":
    main()
