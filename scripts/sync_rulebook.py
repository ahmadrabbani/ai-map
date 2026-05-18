#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def _read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def _write_json(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def _backup(path: Path) -> None:
    if not path.exists():
        return
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup_path = path.with_suffix(path.suffix + f".bak.{stamp}")
    shutil.copy2(path, backup_path)


def _build_legacy_5m(payload: dict[str, Any]) -> dict[str, Any]:
    categories = payload.get("plot_size_categories") or {}
    five_marla = categories.get("5_marla") or {}
    rules = five_marla.get("ground_floor_rules") or []
    if not isinstance(rules, list):
        rules = []

    metadata = payload.get("metadata") or {}
    canonical_units = payload.get("canonical_units") or {}

    return {
        "metadata": {
            "authority": "Lahore Development Authority",
            "title": metadata.get("title", "LDA Residential Rulebook"),
            "plot_category": "5_marla_residential",
            "scheme_type": "approved",
            "road_width_ft": 30,
            "plot_dimensions_ft": {"front": 25, "depth": 45},
            "plot_area_sqft": 1125,
            "synced_from_rulebook_version": metadata.get("version"),
            "canonical_units": canonical_units,
        },
        "rules": rules,
    }


def _coerce_to_runtime_schema(payload: dict[str, Any]) -> dict[str, Any]:
    if isinstance(payload.get("plot_size_categories"), dict):
        return payload

    house_rules = payload.get("residential_house_rules") or {}
    mandatory = house_rules.get("mandatory_open_spaces_approved_scheme") or []
    limits = house_rules.get("coverage_far_height_storeys_approved_scheme") or []
    porch_rules = ((house_rules.get("porch_and_side_space_rules") or {}).get("base_clause_rule") or {})
    toilet_rules = ((house_rules.get("toilet_bath_rules") or {}).get("rear_corner_toilet_bathroom") or {})

    def _find(rows: list[dict[str, Any]], key: str) -> dict[str, Any]:
        for row in rows:
            if isinstance(row, dict) and row.get("plot_size") == key:
                return row
        return {}

    row_5 = _find(mandatory, "5_to_less_than_10_marla")
    lim_5 = _find(limits, "5_to_less_than_10_marla")

    ground_floor_rules = [
        {
            "id": "SETBACK_FRONT",
            "type": "setback",
            "title": "Front setback requirement",
            "value_ft": row_5.get("front_ft", 5),
            "operator": ">=",
            "description": "Front setback as per approved scheme.",
        },
        {
            "id": "SETBACK_REAR",
            "type": "setback",
            "title": "Rear setback requirement",
            "value_ft": row_5.get("rear_ft", 5),
            "operator": ">=",
            "description": "Rear setback as per approved scheme.",
        },
        {
            "id": "SETBACK_SIDE",
            "type": "setback",
            "title": "Side setback requirement",
            "value_ft": 0 if str(row_5.get("side", "")).strip().lower() == "not_required" else 5,
            "operator": "==",
            "description": "Side setback requirement as per approved scheme.",
        },
        {
            "id": "GROUND_COVERAGE",
            "type": "coverage",
            "title": "Maximum ground coverage",
            "value_percent": lim_5.get("max_ground_coverage_percent", 75),
            "operator": "<=",
            "description": "Maximum ground coverage limit.",
        },
        {
            "id": "FAR_LIMIT",
            "type": "far",
            "title": "Maximum Floor Area Ratio",
            "value": lim_5.get("max_far", 2.3),
            "operator": "<=",
            "description": "Maximum FAR limit.",
        },
        {
            "id": "MAX_STOREYS",
            "type": "storeys",
            "title": "Maximum number of storeys",
            "value": lim_5.get("max_storeys_excluding_basement", 3),
            "operator": "<=",
            "description": "Maximum storeys allowed.",
        },
        {
            "id": "MAX_HEIGHT",
            "type": "height",
            "title": "Maximum building height",
            "value_ft": lim_5.get("max_height_ft", 38),
            "operator": "<=",
            "description": "Maximum height limit.",
        },
        {
            "id": "PORCH_LENGTH",
            "type": "porch",
            "title": "Maximum porch length",
            "value_ft": porch_rules.get("max_porch_length_ft", 20),
            "operator": "<=",
            "description": "Maximum porch length allowed.",
        },
        {
            "id": "REAR_TOILET_AREA",
            "type": "ancillary",
            "title": "Rear toilet maximum area",
            "value_sqft": toilet_rules.get("max_area_sqft", 40),
            "operator": "<=",
            "description": "Rear toilet area limit.",
        },
        {
            "id": "REAR_TOILET_HEIGHT",
            "type": "ancillary",
            "title": "Rear toilet maximum height",
            "value_ft": toilet_rules.get("max_height_ft", 8),
            "operator": "<=",
            "description": "Rear toilet height limit.",
        },
    ]

    return {
        "ruleset": "residential_building_approval",
        "building_type": "residential",
        "source_documents": payload.get("metadata", {}).get("source_files", []),
        "plot_size_categories": {
            "5_marla": {
                "label": "5 Marla",
                "basement_required": False,
                "ground_floor_rules": ground_floor_rules,
            },
            "10_marla": {"label": "10 Marla", "basement_required": False},
            "above_10_marla": {"label": "Above 10 Marla", "basement_required": True},
            "custom": {"label": "Custom", "basement_required_when_area_gt_sqft": 2250},
        },
        "required_plans": {
            "ground": {"required": True, "label": "Ground Floor Plan"},
            "basement": {"required": False, "required_when": {"plot_size_category": "above_10_marla"}, "label": "Basement Plan"},
            "first": {"required": False, "label": "First Floor Plan"},
            "second": {"required": False, "label": "Second Floor Plan"},
            "roof": {"required": False, "label": "Roof Plan"},
            "services": {"required": False, "label": "Services Plan"},
        },
        "final_report": {
            "generate_even_if_expert_review_required": True,
            "allow_submit_when_status": ["ready_for_submission"],
        },
        "required_documents": [
            {"id": "document.water_supply_sewerage_drainage_plan", "label": "Water supply, sewerage, and drainage plan"},
            {"id": "document.structural_drawings", "label": "Structural drawings"},
            {"id": "document.electricity_safety_plan", "label": "Electricity safety plan"},
            {"id": "document.fire_safety_plan", "label": "Fire safety plan"},
        ],
        "source_rulebook_snapshot": payload,
    }


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Sync external rulebook JSON into runtime rule files."
    )
    parser.add_argument(
        "--source",
        required=True,
        help="Path to updated rulebook JSON (txt/json file containing JSON).",
    )
    parser.add_argument(
        "--repo-root",
        default=".",
        help="Repository root where rules directory exists.",
    )
    args = parser.parse_args()

    repo_root = Path(args.repo_root).resolve()
    source = Path(args.source).resolve()
    rules_dir = repo_root / "rules"
    approval_path = rules_dir / "approval_rules_meta.json"
    legacy_path = rules_dir / "5MRulesJSON.json"

    payload = _coerce_to_runtime_schema(_read_json(source))

    if not isinstance(payload.get("plot_size_categories"), dict):
        raise ValueError("source JSON missing plot_size_categories")

    if not isinstance(((payload.get("plot_size_categories") or {}).get("5_marla") or {}).get("ground_floor_rules"), list):
        raise ValueError("source JSON missing plot_size_categories.5_marla.ground_floor_rules")

    _backup(approval_path)
    _backup(legacy_path)

    _write_json(approval_path, payload)
    _write_json(legacy_path, _build_legacy_5m(payload))

    print(f"Synced: {source}")
    print(f"Updated: {approval_path}")
    print(f"Updated: {legacy_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
