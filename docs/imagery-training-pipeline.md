# Imagery Build/Open Training Pipeline

## 1) Collect dataset from current applications

This command reads saved map selections (`lat/lng`, context, geocode diagnostics) and creates a dataset manifest:

```bash
php artisan bp:imagery-dataset:collect --limit=500 --download-static
```

- Manifest output: `storage/app/ml/imagery/datasets/manifest_YYYYMMDD_HHMMSS.jsonl`
- Static images (optional): `storage/app/ml/imagery/images/YYYY/MM/*.png`

Each JSONL row includes:
- `application_id`, `application_number`
- `lat`, `lng`
- `formatted_address`, `plot_number`
- `scheme`, `phase`, `block`, `plot_ref`
- `site_signal`, `geocode_json_path`
- `image_path` (if downloaded)
- `label` (initially null)

## 2) Create labels CSV

Create CSV with columns:

```csv
application_id,label
8,built
11,open
14,mixed
```

Allowed labels: `open`, `built`, `mixed`.

Tip: Label using both imagery and ground truth from AD review when available.

## 3) Train structured-feature model

```bash
php artisan bp:imagery-model:train storage/app/ml/imagery/labels.csv --manifest=/absolute/path/to/storage/app/ml/imagery/datasets/manifest_YYYYMMDD_HHMMSS.jsonl
```

Or custom output model path:

```bash
php artisan bp:imagery-model:train storage/app/ml/imagery/labels.csv --manifest=/absolute/path/to/storage/app/ml/imagery/datasets/manifest_YYYYMMDD_HHMMSS.jsonl --out=storage/app/ml/imagery/imagery_signal_model.json
```

Training behavior:
- tries `LightGBM` first (if installed)
- falls back to `GradientBoostingClassifier` (scikit-learn)
- if dataset/features are insufficient, falls back to prior baseline model

## 4) Enable imagery signal in app

In `.env`:

```env
ML_IMAGERY_ENABLED=true
ML_IMAGERY_PYTHON_BIN=python3
ML_IMAGERY_PREDICT_SCRIPT=scripts/ml/predict_imagery_signal.py
ML_IMAGERY_MODEL_PATH=storage/app/ml/imagery/imagery_signal_model.json
ML_IMAGERY_TIMEOUT_SECONDS=5
```

Then:

```bash
php artisan config:clear
```

## 5) Where signal appears

Pipeline adds advisory JSON at:
- `analysis_json.imagery_signal`
- `analysis_json.imagery_policy`

This is advisory only; CAD/rule engine remains authoritative.

## 6) Upgrade path to real CV model

Replace `scripts/ml/train_imagery_signal.py` and `scripts/ml/predict_imagery_signal.py` with:
- model training from downloaded imagery chips
- inference that reads image patches and outputs probabilities
- keep output schema unchanged (`class`, `built_probability`, `open_probability`, `mixed_probability`, `confidence`, `model_version`)

That keeps Laravel integration unchanged.
