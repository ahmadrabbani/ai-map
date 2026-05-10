from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

try:
    import joblib
except ImportError:
    joblib = None

try:
    from sklearn.feature_extraction.text import CountVectorizer
    from sklearn.linear_model import LogisticRegression
    from sklearn.pipeline import Pipeline
except ImportError:
    CountVectorizer = None
    LogisticRegression = None
    Pipeline = None


def load_training_events(path: Path) -> list[dict[str, Any]]:
    events: list[dict[str, Any]] = []
    with path.open("r", encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                events.append(json.loads(line))
            except Exception:
                continue
    return events


def build_dataset(events: list[dict[str, Any]]) -> tuple[list[str], list[str]]:
    X: list[str] = []
    y: list[str] = []
    for event in events:
        raw_layer = event.get("raw_layer") or ""
        predicted = event.get("predicted_standard_layer")
        if not predicted:
            continue
        X.append(str(raw_layer).strip().lower())
        y.append(str(predicted))
    return X, y


def train_model(X: list[str], y: list[str]) -> Any:
    if CountVectorizer is None or LogisticRegression is None or Pipeline is None:
        raise RuntimeError("scikit-learn is not installed. Install sklearn and retry.")
    pipeline = Pipeline(
        [
            ("vectorizer", CountVectorizer(analyzer="char_wb", ngram_range=(2, 4), min_df=1)),
            (
                "classifier",
                LogisticRegression(
                    max_iter=500,
                    class_weight="balanced",
                    solver="liblinear",
                    multi_class="ovr",
                ),
            ),
        ]
    )
    pipeline.fit(X, y)
    return pipeline


def print_metrics(model: Any, X: list[str], y: list[str]) -> None:
    if not hasattr(model, "score"):
        return
    accuracy = float(model.score(X, y)) if X else 0.0
    label_counts: dict[str, int] = {}
    for label in y:
        label_counts[label] = label_counts.get(label, 0) + 1
    print(json.dumps({"accuracy": round(accuracy, 4), "per_class_count": label_counts, "total_examples": len(y)}, indent=2))


def main() -> None:
    ap = argparse.ArgumentParser(description="Train a layer resolver model from CAD training events.")
    ap.add_argument("--input", required=True, help="JSONL training events file")
    ap.add_argument("--output", required=True, help="Output model path (*.joblib)")
    args = ap.parse_args()

    input_path = Path(args.input)
    output_path = Path(args.output)

    if joblib is None:
        print("Error: joblib is not installed. Install joblib or scikit-learn to train the resolver.", file=sys.stderr)
        sys.exit(1)

    if CountVectorizer is None or LogisticRegression is None or Pipeline is None:
        print("Error: scikit-learn is not installed. Install scikit-learn to train the resolver.", file=sys.stderr)
        sys.exit(1)

    events = load_training_events(input_path)
    if not events:
        print(f"No training events found in {input_path}", file=sys.stderr)
        sys.exit(1)

    X, y = build_dataset(events)
    if not X or not y:
        print("No valid training rows found. Ensure events contain raw_layer and predicted_standard_layer.", file=sys.stderr)
        sys.exit(1)

    model = train_model(X, y)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(model, str(output_path))
    print(f"Trained resolver saved to {output_path}")
    print_metrics(model, X, y)


if __name__ == "__main__":
    main()
