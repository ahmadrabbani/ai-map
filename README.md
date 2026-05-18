# Map AI Verification

Map AI Verification is a Laravel-based application for validating CAD plan submissions against defined planning rules. It supports DWG/DXF conversion, CAD footprint extraction, multi-storey building detection, setback checks, ground coverage/FAR estimation, and overlay report generation.

## Project overview

- Backend: Laravel 12, PHP 8.2
- Frontend: Vite + React + Tailwind CSS
- CAD processing: Python script at `scripts/process_cad_rules.py`
- Rules engine: JSON rules file at `rules/5MRulesJSON.json`
- Data models: CAD submissions, expert labels, training labels, rule results

## Key capabilities

- Convert CAD drawings (DWG) to DXF for analysis
- Extract closed building footprints and plot boundary shapes
- Detect multiple floors via layer tags, handles, or Z-level grouping
- Compute setbacks, ground coverage, FAR, and storey count
- Generate overlay PDFs for manual review
- Store results with submission records and report views

## Architecture

### Primary services

- `app/Services/CadComplianceService.php`
  - orchestrates DWG→DXF conversion, Python analysis, and PDF publishing
  - accepts expert labels and layer maps for more accurate floor selection
  - writes analysis results back to `CadSubmission`

- `scripts/process_cad_rules.py`
  - reads DXF files with `ezdxf`
  - extracts closed polylines and evaluates compliance rules
  - outputs structured JSON and creates PDF overlays

### Routes

Key frontend/admin routes:

- `GET /admin/plan/cad-compliance` — CAD submission form
- `POST /admin/plan/cad-compliance` — submit CAD for compliance analysis
- `GET /admin/plan/cad-submissions/{id}/compliance` — view results
- `GET /admin/plan/cad-submissions/{id}/overlay` — overlay PDF access
- `POST /admin/plan/cad-submissions/{id}/rerun` — rerun analysis with labels

API route:

- `POST /api/plan/check-setback` — rule evaluation endpoint

## Installation

1. Clone the repository.
2. Copy environment configuration:

```bash
cp .env.example .env
```

3. Install PHP dependencies:

```bash
composer install
```

4. Install JavaScript dependencies:

```bash
npm install
```

5. Create database and run migrations:

```bash
php artisan migrate
```

6. Generate application key:

```bash
php artisan key:generate
```

7. Build frontend assets:

```bash
npm run build
```

8. Install Python dependencies:

```bash
python3 -m pip install -r requirements.txt
```

## Running locally

- Start Laravel server:

```bash
php artisan serve
```

- Start Vite development server:

```bash
npm run dev
```

## AD ePermit + DFPS workflow

This project includes end-to-end public submission -> AD ePermit decision -> DFPS/internal push flow.

### New status lifecycle

- `draft`
- `submitted_to_ad_epermit`
- `under_review`
- `observation_marked`
- `rejected_by_ad_epermit`
- `approved_by_ad_epermit`
- `pushed_to_dfps`
- `dfps_push_failed`

### Required environment variables

```bash
DFPS_PUSH_ENDPOINT=
DFPS_USERNAME=
DFPS_PASSWORD=
DFPS_TOKEN=
DFPS_TIMEOUT=60
```

### Manual end-to-end test flow

1. Submit a public application from `/building-plan-ai/applications/create`.
2. Confirm application is stored in `building_plan_applications` with `submitted_to_ad_epermit`.
3. Login as AD ePermit user and open `/admin/plan/ad-epermit`.
4. Open an application detail page.
5. Save satellite site review JSON in **Google Satellite Site Review** section.
6. Use **Decision Panel** to mark `observation`, `reject`, or `approve`.
7. Push to DFPS using **Push To DFPS/Internal** button.
8. Verify generated ZIP under `storage/app/private/uploads/dfps-zips/{application_id}/`.
9. Verify `dfps_push_logs` and `application_status_logs` entries.

### Running the workflow tests

```bash
php artisan test --filter=AdEpermitWorkflowTest
```

## CAD analysis workflow

The CAD compliance workflow is driven by `app/Services/CadComplianceService.php`.

1. DWG is converted to DXF using one of the configured converters.
2. `scripts/process_cad_rules.py` reads the DXF and extracts closed polylines.
3. The script selects the plot boundary and building footprints.
4. It computes setbacks, coverage, FAR, and storey counts.
5. Results are returned as JSON and stored with the submission.
6. Overlay and drawing PDFs are generated and published to public storage.

## Script usage

See `docs/usage.md` for full CLI options and examples.

## Expert labeling and layer maps

The system improves CAD detection when expert labels are available.

- `--plot-handle` identifies the plot boundary by polyline handle
- `--building-layer` narrows building footprint candidates by layer
- `--floor-handles` supplies floor-specific footprint handles
- `--layer-map-json` provides layer tags such as `plot_boundary` and `ground_floor`

These values are passed by Laravel from `CadExpertLabel` and `CadTrainingLabel` records.

## Layer resolver and training

The DXF pipeline now supports a layer-aware resolver using `rules/layers.json`.
It can map raw CAD layers to semantic tags and avoid false-positive plot/floor selection from non-plot elements.

- `--resolver-mode` controls lookup mode: `strict`, `alias`, `trained`, or `hybrid`
- `--resolver-model` loads an optional trained model for ambiguous layer names
- `--min-confidence` requires a minimum semantic mapping confidence
- `--allow-heuristic-fallback` enables fallback selection only when explicitly requested
- `--training-out` writes training events to a JSONL file for later model training

This preserves the existing Laravel JSON output format while adding layer-aware candidate filtering and trainable semantics.

## Important files

- `app/Services/CadComplianceService.php` — CAD processing orchestration
- `app/Http/Controllers` — web and API controllers
- `scripts/process_cad_rules.py` — DXF analysis and PDF generation
- `rules/5MRulesJSON.json` — planning rule definitions
- `tests/` — PHPUnit tests
- `docs/deployment.md` — deployment and CI notes

## Deployment

Read `docs/deployment.md` for CI/CD and server requirements.

## License

This project is released under the MIT license.
