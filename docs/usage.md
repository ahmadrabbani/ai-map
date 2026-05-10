# CAD Analysis Script Usage

This project uses `scripts/process_cad_rules.py` to evaluate DXF drawings against planning rules and generate PDF overlays.

## Requirements

- Python 3
- `ezdxf`
- `matplotlib`

Install dependencies:

```bash
python3 -m pip install -r requirements.txt
```

If `requirements.txt` is missing these libraries, install directly:

```bash
python3 -m pip install ezdxf matplotlib
```

## Primary command

```bash
python3 scripts/process_cad_rules.py --dxf /path/to/file.dxf --rules rules/5MRulesJSON.json --out /path/to/overlay.pdf
```

## Optional arguments

- `--plot-layer <layer>`
  - limit plot selection to a specific DXF layer

- `--building-layer <layer>`
  - limit building footprint candidates to a specific DXF layer

- `--plot-handle <handle>`
  - specify the exact DXF polyline handle for the plot boundary

- `--floor-handles <json>`
  - provide expert floor mapping:

```text
'[{"floor":0,"handle":"1A2"},{"floor":1,"handle":"1B3"}]'
```

- `--front-side <north|south|east|west>`
  - choose the front setback orientation explicitly

- `--layer-map-json <json>`
  - pass layer tags from the CAD layer viewer, for example:

```text
'{"Layer1": {"tag": "plot_boundary"}, "Layer2": {"tag": "ground_floor"}}'
```

If not provided, the script will auto-generate layer mappings based on common layer name patterns (e.g., layers containing "PLOT" are tagged as "plot_boundary", "GF" as "ground_floor", etc.).

- `--drawing-out <path>`
  - generate a second PDF containing the full drawing preview for manual inspection

- `--list-layers`
  - print available DXF layers as JSON and exit

- `--list-polys`
  - print detected closed polylines as JSON and exit

- `--debug`
  - enable additional debug information when running the script

## Script behavior

The script follows these selection strategies in order:

1. Expert-provided floor handles via `--floor-handles`
2. Layer-tag mapping via `--layer-map-json`
3. Z-level grouping when meaningful elevation values exist in the DXF
4. Layer ranking heuristics based on layer name keywords

If multiple building footprints are detected, the script uses the largest polygon for each floor and computes total floor area for FAR.

## Output

The script prints JSON to stdout with these main sections:

- `status`: `ok` or `error`
- `overlay`: overlay generation status
- `selection`: chosen plot and floor handle metadata
- `setbacks_ft`: front/rear/left/right setback values in feet
- `areas`: ground floor area, total floor area, coverage percent, FAR, and storeys detected
- `rules`: individual rule evaluation results
- `entity_features`: compact metadata for candidate polylines

If PDF generation succeeds, `--out` receives the compliance overlay PDF path. If `--drawing-out` is provided, a second drawing preview PDF will be generated.

## Notes

- The script assumes the DXF uses closed polylines for plot and building boundaries.
- If units are available in the DXF header, the script scales geometry to feet automatically.
- The rules file is expected to be a JSON file like `rules/5MRulesJSON.json`.

## Integration with Laravel

The Laravel service at `app/Services/CadComplianceService.php` runs this script to:

- convert DWG to DXF
- execute `process_cad_rules.py`
- capture JSON output
- save overlay PDFs to public storage
- store analysis results on `CadSubmission`
