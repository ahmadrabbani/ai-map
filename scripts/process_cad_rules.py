from __future__ import annotations
import argparse, json, re, sys
from pathlib import Path
from typing import Any, Iterable

import math
from shapely import Polygon, LineString, shortest_line, distance, make_valid

from cad_semantics.layer_resolver import LayerResolver
from cad_semantics.training_store import append_training_events, build_training_event
from cad_semantics.features import build_candidate_features
from cad_semantics.pdf_parser import DxfParser

INSUNITS_TO_UNIT = {0: None, 1: "in", 2: "ft", 4: "mm", 5: "cm", 6: "m"}
UNIT_TO_FOOT = {"ft": 1.0, "in": 1.0 / 12.0, "mm": 0.00328084, "cm": 0.0328084, "m": 3.28084}
PIPELINE_SOURCE_TYPE = "dxf"
PIPELINE_MODEL_VERSION = "resolver_v1"

# Extend this map from your actual submissions over time.
LEGACY_ALIASES = {
    "boundary wall": "SITE-BW",
    "front building line": "SITE-FBL",
    "plot line": "SITE-PL",
    "setback line": "SITE-SB",
    "dimension": "REF-DIM",
    "dimensions": "REF-DIMS",
    "defpoints": "REF-DP",
    "text": "REF-TXT",
    "texts": "REF-TXT",
    "section line": "REF-SEC",
    "wall": "GF-WE",
    "a-wall": "GF-WE",
    "gf wall": "GF-WE",
    "ground wall": "GF-WE",
    "ground floor external walls": "GF-WE",
    "first floor external walls": "FF-WE",
    "second floor external walls": "SF-WE",
    "ground floor services": "GF-SRV",
    "first floor services": "FF-SRV",
    "second floor services": "SF-SRV",
    "ground floor porch": "GF-PR",
    "porch": "GF-PR",
    "dim": "REF-DIM",
    "dimension": "REF-DIM",
    "demention": "REF-DIMS",
    "text": "REF-TXT",
    "texts": "REF-TXT",
    "det": None,
    "detail": None,
    "elevation": None,
    "section": "REF-SEC",
    "win": "CMP-WIN",
    "window": "CMP-WND",
    "door": "GF-DR",
    "hatch": "MAT-H1",
    "hatch2": "MAT-H2",
}

PLOT_LAYERS = {"SITE-BW", "SITE-PL"}
FOOTPRINT_LAYERS = {"GF-WE", "FF-WE", "SF-WE"}
SETBACK_LAYERS = {"SITE-SB"}
MANDATORY_SITE_LAYERS = {"SITE-BW", "SITE-PL", "SITE-FBL", "SITE-SB"}
LAYER_TAG_TO_STANDARD = {
    "plot_boundary": "SITE-PL",
    "ground_floor": "GF-WE",
    "first_floor": "FF-WE",
    "second_floor": "SF-WE",
    "roof": "RF-MUM",
    "water_tank": "RF-WT",
    "ground_services": "GF-SRV",
    "first_services": "FF-SRV",
    "second_services": "SF-SRV",
    "basement_services": "BSM-SRV",
    "porch": "GF-PR",
    "setback": "SITE-SB",
    "building": "GF-WE",
}


def load_layers_json(path: str | Path) -> tuple[dict[str, Any], dict[str, list[str]]]:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    return data["layers"], data.get("rules_to_layers", {})


def build_allowed_layers(rules_json: dict[str, Any], rules_to_layers: dict[str, list[str]] | None = None) -> set[str]:
    allowed: set[str] = set(MANDATORY_SITE_LAYERS)
    layers_meta = rules_json.get("layers")
    if isinstance(layers_meta, dict):
        allowed.update(str(layer) for layer in layers_meta.keys())
    if rules_to_layers:
        for rule in iter_rules(rules_json):
            rule_id = rule.get("id")
            if not rule_id:
                continue
            allowed.update(rules_to_layers.get(rule_id, []))
    return allowed


def iter_rules(rules_json: dict[str, Any]) -> list[dict[str, Any]]:
    if isinstance(rules_json.get("rules"), list):
        return [rule for rule in rules_json["rules"] if isinstance(rule, dict)]
    return []


def infer_plot_size_category(meta_json: dict[str, Any], plot_area_sqft: float | None) -> str | None:
    if plot_area_sqft is None:
        return None
    if plot_area_sqft <= 1125:
        return "5_marla"
    if plot_area_sqft <= 2250:
        return "10_marla"
    return "above_10_marla"


def extract_rule_value(rule: dict[str, Any]) -> Any:
    if "value_ft" in rule:
        return rule.get("value_ft")
    if "value_percent" in rule:
        return rule.get("value_percent")
    if "value_sqft" in rule:
        return rule.get("value_sqft")
    if "requirements" in rule:
        return rule.get("requirements")
    return rule.get("value")


def extract_rule_unit(rule: dict[str, Any]) -> str | None:
    if "value_ft" in rule:
        return "ft"
    if "value_percent" in rule:
        return "%"
    if "value_sqft" in rule:
        return "sqft"
    return rule.get("unit")


def operand_matches_plot_area(operand: dict[str, Any], plot_area_sqft: float | None) -> bool:
    if plot_area_sqft is None:
        return False
    subject = operand.get("subject")
    if subject != "plot_area_sqft":
        return True
    operator = operand.get("operator")
    value = operand.get("value")
    if not isinstance(value, (int, float)):
        return False
    return {
        "<": plot_area_sqft < value,
        "<=": plot_area_sqft <= value,
        ">": plot_area_sqft > value,
        ">=": plot_area_sqft >= value,
        "=": plot_area_sqft == value,
        "==": plot_area_sqft == value,
    }.get(operator, True)


def band_matches_plot_area(band: dict[str, Any], plot_area_sqft: float | None) -> bool:
    when = band.get("when") or {}
    all_of = when.get("all_of") or []
    if not all_of:
        return True
    return all(operand_matches_plot_area(operand, plot_area_sqft) for operand in all_of if isinstance(operand, dict))


def normalized_requirement_to_legacy_rule(requirement: dict[str, Any], source_rule: dict[str, Any]) -> dict[str, Any] | None:
    subject = requirement.get("subject")
    if subject == "max_ground_coverage_percent":
        return {
            "id": "GROUND_COVERAGE",
            "type": "coverage",
            "title": "Maximum ground coverage",
            "operator": requirement.get("operator", "<="),
            "value_percent": requirement.get("value"),
            "description": source_rule.get("title"),
            "source_refs": source_rule.get("source_refs", []),
        }
    if subject == "max_far":
        return {
            "id": "FAR_LIMIT",
            "type": "far",
            "title": "Maximum Floor Area Ratio",
            "operator": requirement.get("operator", "<="),
            "value": requirement.get("value"),
            "unit": requirement.get("unit", "far"),
            "description": source_rule.get("title"),
            "source_refs": source_rule.get("source_refs", []),
        }
    if subject == "max_storeys_excluding_basement":
        return {
            "id": "MAX_STOREYS",
            "type": "storeys",
            "title": "Maximum number of storeys",
            "operator": requirement.get("operator", "<="),
            "value": requirement.get("value"),
            "description": source_rule.get("title"),
            "source_refs": source_rule.get("source_refs", []),
        }
    if subject == "max_height_ft":
        return {
            "id": "MAX_HEIGHT",
            "type": "height",
            "title": "Maximum building height",
            "operator": requirement.get("operator", "<="),
            "value_ft": requirement.get("value"),
            "description": source_rule.get("title"),
            "source_refs": source_rule.get("source_refs", []),
        }
    return None


def normalize_rules_for_plot(rules_json: dict[str, Any], plot_area_sqft: float | None) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    legacy_rules = iter_rules(rules_json)
    if legacy_rules:
        return legacy_rules, {
            "ruleset": rules_json.get("metadata", {}).get("plot_category"),
            "format": "legacy",
            "source_documents": rules_json.get("source_documents", []),
        }

    category_key = infer_plot_size_category(rules_json, plot_area_sqft)
    category_meta = ((rules_json.get("plot_size_categories") or {}).get(category_key or "")) or {}
    combined: dict[str, dict[str, Any]] = {}

    for rule in category_meta.get("ground_floor_rules", []):
        if not isinstance(rule, dict) or not rule.get("id"):
            continue
        combined[rule["id"]] = dict(rule)

    for source_rule in (rules_json.get("normalized_rules") or []):
        if not isinstance(source_rule, dict):
            continue
        evaluation = source_rule.get("evaluation") or {}
        mode = evaluation.get("mode")
        if mode == "band_lookup":
            for band in evaluation.get("bands", []):
                if not isinstance(band, dict) or not band_matches_plot_area(band, plot_area_sqft):
                    continue
                for requirement in band.get("requirements", []):
                    if not isinstance(requirement, dict):
                        continue
                    legacy_rule = normalized_requirement_to_legacy_rule(requirement, source_rule)
                    if legacy_rule and legacy_rule.get("id"):
                        combined[legacy_rule["id"]] = legacy_rule
                break
        elif mode == "document_requirement":
            for requirement in evaluation.get("requirements", []):
                if not isinstance(requirement, dict):
                    continue
                subject = str(requirement.get("subject") or "")
                rid = subject.replace("document.", "DOCUMENT_").upper()
                combined[rid] = {
                    "id": rid,
                    "type": source_rule.get("rule_type", "submission"),
                    "title": subject,
                    "operator": requirement.get("operator", "exists"),
                    "value": requirement.get("value"),
                    "description": source_rule.get("title"),
                    "source_refs": source_rule.get("source_refs", []),
                    "note": "manual_review",
                }

    return list(combined.values()), {
        "ruleset": rules_json.get("ruleset"),
        "format": "approval_meta",
        "plot_size_category": category_key,
        "plot_size_label": category_meta.get("label"),
        "source_documents": rules_json.get("source_documents", []),
        "applicability_scope": rules_json.get("applicability_scope", {}),
        "canonical_units": rules_json.get("canonical_units", {}),
        "evaluation_flow": rules_json.get("evaluation_flow", []),
    }


def list_layers(doc: Any) -> list[str]:
    layers = set()
    try:
        for layer in doc.layers:
            layers.add(str(layer.dxf.name))
    except Exception:
        pass
    try:
        for entity in doc.modelspace():
            raw_layer = getattr(entity.dxf, "layer", None)
            if raw_layer:
                layers.add(str(raw_layer))
    except Exception:
        pass
    return sorted(layers)


def extract_candidate_entities(
    doc: Any,
    resolver: LayerResolver,
    allowed_layers: set[str],
    min_confidence: float = 0.0,
    include_unmapped: bool = True,
) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    allowed_lower = {layer.lower() for layer in allowed_layers}
    for entity in doc.modelspace():
        raw_layer = canon(getattr(entity.dxf, "layer", None))
        resolution = resolver.resolve(raw_layer)
        std_layer = resolution["standard_layer"]
        confidence = float(resolution.get("confidence", 0.0) or 0.0)
        method = resolution.get("method", "none")
        match_type = resolution.get("match_type", method)
        clean_layer_name = resolution.get("clean_layer_name", canon(raw_layer))
        matched_schema_layer = resolution.get("matched_schema_layer", std_layer)
        ignored_reason = None
        accepted = False

        pts = _xy_points(entity)
        if not pts:
            continue

        if std_layer is None:
            if raw_layer.lower() in allowed_lower:
                std_layer = raw_layer
                confidence = 1.0
                method = "raw_allowed"
                accepted = True
            else:
                ignored_reason = "unmapped_layer"
        elif std_layer not in allowed_layers:
            ignored_reason = "layer_not_allowed"
        elif confidence < min_confidence:
            ignored_reason = "low_confidence"
        else:
            accepted = True

        if not accepted and not include_unmapped:
            continue

        candidates.append({
            "handle": str(getattr(entity.dxf, "handle", "") or "").strip(),
            "type": entity.dxftype(),
            "raw_layer": raw_layer,
            "original_layer_name": resolution.get("original_layer_name", raw_layer),
            "clean_layer_name": clean_layer_name,
            "standard_layer": std_layer,
            "matched_schema_layer": matched_schema_layer,
            "confidence": confidence,
            "method": method,
            "match_type": match_type,
            "ignored_reason": ignored_reason,
            "accepted": accepted,
            "points": pts,
            "closed": _is_closed(entity, pts),
        })
    return candidates


def list_polys(candidates: list[dict[str, Any]]) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []

    for candidate in candidates:
        if not candidate.get("closed"):
            continue

        pts = candidate["points"]
        if len(pts) < 3:
            continue

        try:
            poly = Polygon(_close_ring(pts))
            if not poly.is_valid:
                poly = make_valid(poly)

            if poly.geom_type == "MultiPolygon":
                parts = [g for g in poly.geoms if g.area > 0]
                if not parts:
                    continue
                poly = max(parts, key=lambda g: g.area)

            if poly.geom_type != "Polygon" or poly.area <= 0:
                continue

            a = float(poly.area)
            x0, y0, x1, y1 = poly.bounds
            width = max(0.0, x1 - x0)
            height = max(0.0, y1 - y0)
            bbox_area = width * height

            raw_layer = candidate.get("raw_layer")
            original_layer_name = candidate.get("original_layer_name", raw_layer)
            clean_layer_name = candidate.get("clean_layer_name", canon(raw_layer))
            standard_layer = candidate.get("standard_layer")
            matched_schema_layer = candidate.get("matched_schema_layer", standard_layer)
            match_type = candidate.get("match_type", candidate.get("method"))
            raw_lower = canon(raw_layer).lower()

            role_hint = "unknown"

            if standard_layer in {"SITE-PL", "SITE-BW"} or any(k in raw_lower for k in ["plot", "site", "boundary"]):
                role_hint = "plot_candidate"

            if standard_layer in {"GF-WE", "FF-WE", "SF-WE"} or any(k in raw_lower for k in ["wall", "building", "floor", "gf"]):
                role_hint = "floor_candidate"

            if any(k in raw_lower for k in ["title", "sheet", "border", "frame", "table", "schedule", "section", "elevation", "detail"]):
                role_hint = "sheet_or_detail"

            if a > 5000:
                role_hint = "sheet_or_large_frame"

            out.append({
                "handle": candidate.get("handle"),
                "type": candidate.get("type"),
                "raw_layer": raw_layer,
                "original_layer_name": original_layer_name,
                "clean_layer_name": clean_layer_name,
                "standard_layer": standard_layer,
                "matched_schema_layer": matched_schema_layer,
                "match_type": match_type,
                "confidence": candidate.get("confidence"),
                "method": candidate.get("method"),
                "ignored_reason": candidate.get("ignored_reason"),
                "accepted": candidate.get("accepted"),
                "role_hint": role_hint,
                "area": round(a, 3),
                "bbox": [round(x0, 3), round(y0, 3), round(x1, 3), round(y1, 3)],
                "bbox_w": round(width, 3),
                "bbox_h": round(height, 3),
                "rectangularity": round(a / bbox_area, 4) if bbox_area > 0 else None,
            })

        except Exception:
            continue

    return sorted(out, key=lambda p: p["area"], reverse=True)


def canon(name: str | None) -> str:
    s = str(name or "")
    s = re.sub(r"[\u0000-\u001F\u007F-\u009F]+", "", s).strip()
    s = re.sub(r"^\d+\s*[\.\-_\):\s]+\s*", "", s)
    s = re.sub(r"[-_]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def resolve_standard_layer(
    raw_layer: str | None,
    standard_layers: dict[str, Any],
    aliases: dict[str, str] | None = None,
) -> str | None:
    aliases = aliases or LEGACY_ALIASES
    raw = canon(raw_layer)
    if not raw:
        return None
    if raw in standard_layers:
        return raw
    upper = raw.upper()
    if upper in standard_layers:
        return upper
    low = raw.lower()
    mapped = aliases.get(low)
    if mapped and mapped in standard_layers:
        return mapped
    # normalized/case-insensitive match against schema keys
    for std in standard_layers:
        if canon(std).lower() == low:
            return std
    return None


def detect_units(doc: Any) -> str | None:
    try:
        return INSUNITS_TO_UNIT.get(int(doc.header.get("$INSUNITS", 0)))
    except Exception:
        return None


def _confidence_for_layer(raw_layer: str | None, std_layer: str | None, layer_map: dict[str, Any] | None = None) -> float:
    if not raw_layer or not std_layer:
        return 0.0
    raw_norm = canon(raw_layer)
    if raw_norm == std_layer or raw_norm.upper() == std_layer or raw_norm.lower() == std_layer.lower():
        return 1.0
    if layer_map and raw_layer in layer_map:
        return 0.95
    low = raw_norm.lower()
    if low in LEGACY_ALIASES:
        return 0.9
    return 0.75


def _polygon_rectangularity(poly: Polygon) -> float | None:
    if not poly or poly.is_empty:
        return None
    try:
        rect = poly.minimum_rotated_rectangle
        if rect.area and poly.area:
            return float(poly.area / rect.area)
    except Exception:
        pass
    return None


def _downsample_points(points: list[tuple[float, float]], max_points: int = 16) -> list[list[float]]:
    if not points:
        return []
    if len(points) <= max_points:
        return [[float(x), float(y)] for x, y in points]
    step = max(1, len(points) // max_points)
    return [[float(x), float(y)] for x, y in points[::step]]


def to_ft_factor(doc: Any, override_unit: str | None = None) -> float:
    unit = override_unit or detect_units(doc) or "ft"
    return UNIT_TO_FOOT.get(unit, 1.0)


def _xy_points(entity: Any) -> list[tuple[float, float]]:
    t = entity.dxftype()
    if t == "LWPOLYLINE":
        return [(float(x), float(y)) for x, y, *_ in entity.get_points("xy")]
    if t == "POLYLINE":
        pts = []
        for v in entity.vertices:
            loc = v.dxf.location
            pts.append((float(loc.x), float(loc.y)))
        return pts
    if t == "LINE":
        s, e = entity.dxf.start, entity.dxf.end
        return [(float(s.x), float(s.y)), (float(e.x), float(e.y))]
    return []


def _is_closed(entity: Any, pts: list[tuple[float, float]]) -> bool:
    if not pts:
        return False
    t = entity.dxftype()
    if t in {"LWPOLYLINE", "POLYLINE"}:
        closed_attr = getattr(entity, "closed", None)
        if closed_attr is None:
            closed_attr = getattr(entity, "is_closed", None)
        if closed_attr:
            return True
    return len(pts) >= 4 and pts[0] == pts[-1]


def _close_ring(pts: list[tuple[float, float]]) -> list[tuple[float, float]]:
    return pts if not pts or pts[0] == pts[-1] else pts + [pts[0]]


def _safe_polygon(coords: list[tuple[float, float]]) -> Polygon | None:
    if len(coords) < 4:
        return None
    poly = Polygon(coords)
    if poly.is_empty:
        return None
    if not poly.is_valid:
        poly = make_valid(poly)
    if poly.geom_type == "Polygon":
        return poly if poly.area > 0 else None
    if poly.geom_type == "MultiPolygon":
        parts = [g for g in poly.geoms if g.area > 0]
        return max(parts, key=lambda g: g.area) if parts else None
    return None


def write_minimal_pdf(pdf_path: Path, title: str, lines: list[str]) -> None:
    text = "\n".join([title] + lines)
    content_stream = f"BT /F1 12 Tf 50 760 Td ({text.replace('(', '[').replace(')', ']')}) Tj ET"
    objects = []
    objects.append("1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj")
    objects.append("2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj")
    objects.append(
        "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj"
    )
    objects.append(f"4 0 obj<< /Length {len(content_stream)} >>stream\n{content_stream}\nendstream endobj")
    objects.append("5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj")

    xref = []
    pdf = ["%PDF-1.4\n"]
    offset = len(pdf[0].encode("utf-8"))
    for obj in objects:
        xref.append(offset)
        s = obj + "\n"
        pdf.append(s)
        offset += len(s.encode("utf-8"))
    xref_start = offset
    pdf.append(f"xref\n0 {len(objects)+1}\n")
    pdf.append("0000000000 65535 f \n")
    for off in xref:
        pdf.append(f"{off:010d} 00000 n \n")
    pdf.append(f"trailer<< /Size {len(objects)+1} /Root 1 0 R >>\nstartxref\n{xref_start}\n%%EOF\n")
    pdf_path.parent.mkdir(parents=True, exist_ok=True)
    pdf_path.write_bytes("".join(pdf).encode("utf-8"))


def is_nearly_closed(pts: list[tuple[float, float]], tol: float = 1e-6) -> bool:
    return len(pts) >= 3 and abs(pts[0][0] - pts[-1][0]) <= tol and abs(pts[0][1] - pts[-1][1]) <= tol


def _approx_circle(cx: float, cy: float, r: float, segments: int = 96) -> list[tuple[float, float]]:
    pts = []
    for i in range(segments + 1):
        a = (i / float(segments)) * math.tau
        pts.append((cx + math.cos(a) * r, cy + math.sin(a) * r))
    return pts


def _approx_arc(cx: float, cy: float, r: float, start_deg: float, end_deg: float, segments: int = 64) -> list[tuple[float, float]]:
    if end_deg < start_deg:
        end_deg += 360.0
    sweep = max(0.0, end_deg - start_deg)
    steps = max(8, int(segments * (sweep / 360.0)))
    pts = []
    for i in range(steps + 1):
        a = math.radians(start_deg + (sweep * i / float(steps)))
        pts.append((cx + math.cos(a) * r, cy + math.sin(a) * r))
    return pts


def iter_linework(doc: Any, scale_to_ft: float = 1.0) -> Iterable[list[tuple[float, float]]]:
    for entity in doc.modelspace():
        t = entity.dxftype()
        try:
            if t == "LINE":
                s = entity.dxf.start
                e = entity.dxf.end
                pts = [(float(s.x), float(s.y)), (float(e.x), float(e.y))]
            elif t == "LWPOLYLINE":
                pts = [(float(x), float(y)) for x, y, *_ in entity.get_points("xy")]
                if entity.closed or is_nearly_closed(pts):
                    pts = pts + [pts[0]] if pts else pts
            elif t == "POLYLINE":
                pts = [(float(v.dxf.location.x), float(v.dxf.location.y)) for v in entity.vertices]
                if getattr(entity, "is_closed", False) or is_nearly_closed(pts):
                    pts = pts + [pts[0]] if pts else pts
            elif t == "CIRCLE":
                c = entity.dxf.center
                pts = _approx_circle(float(c.x), float(c.y), float(entity.dxf.radius))
            elif t == "ARC":
                c = entity.dxf.center
                pts = _approx_arc(
                    float(c.x),
                    float(c.y),
                    float(entity.dxf.radius),
                    float(entity.dxf.start_angle),
                    float(entity.dxf.end_angle),
                )
            else:
                continue

            if not pts or len(pts) < 2:
                continue
            if scale_to_ft != 1.0:
                pts = [(x * scale_to_ft, y * scale_to_ft) for x, y in pts]
            yield pts
        except Exception:
            continue


def build_pdf_measurement_lines(result: dict[str, Any]) -> list[str]:
    lines: list[str] = []
    lines.append("CAD Compliance Measurements")
    lines.append("----------------------------")
    areas = result.get("areas", {})
    lines.append(f"Plot area: {areas.get('plot_sqft', 'n/a')} sqft")
    lines.append(f"Ground area: {areas.get('ground_sqft', 'n/a')} sqft")
    lines.append(f"Coverage: {areas.get('coverage_percent', 'n/a')} %")
    lines.append(f"FAR: {areas.get('far', 'n/a')}")

    setbacks = result.get("setbacks_ft", {}) or {}
    gaps = setbacks.get("gaps", {}) or {}
    lines.append("")
    lines.append("Setbacks (ft):")
    lines.append(f"  front: {gaps.get('front', 'n/a')}")
    lines.append(f"  rear: {gaps.get('rear', 'n/a')}")
    lines.append(f"  left: {gaps.get('left', 'n/a')}")
    lines.append(f"  right: {gaps.get('right', 'n/a')}")

    rules = result.get("rules", [])
    if rules:
        lines.append("")
        lines.append("Rule summary:")
        for rule in rules:
            rid = rule.get("id")
            status = "PASS" if rule.get("pass") is True else "FAIL" if rule.get("pass") is False else "REVIEW"
            measured = rule.get("measured")
            required = rule.get("required")
            if isinstance(measured, dict):
                measured = json.dumps(measured)
            lines.append(f"  {rid}: {status} ({measured} / {required})")
            if len(lines) >= 25:
                lines.append("  ...")
                break

def draw_measurement_annotations(ax: Any, result: dict[str, Any]) -> None:
    """Draw visual measurement annotations on the plot."""
    selection = result.get("selection", {})
    plot_data = selection.get("plot")
    floors_data = selection.get("floors", {})

    if not plot_data:
        return

    # Plot boundary (green)
    plot_coords = plot_data.get("coords", [])
    if plot_coords:
        x, y = zip(*plot_coords)
        ax.plot(x, y, color="#22c55e", linewidth=2, label="Plot Boundary")

    # Ground floor building (blue)
    ground_data = floors_data.get("GF-WE")
    if ground_data:
        ground_coords = ground_data.get("coords", [])
        if ground_coords:
            x, y = zip(*ground_coords)
            ax.plot(x, y, color="#0ea5e9", linewidth=2, label="Building Footprint")

    # Draw setback measurements
    setbacks = result.get("setbacks_ft", {}).get("gaps", {})
    plot_bounds = plot_data.get("bbox_ft", [])
    if len(plot_bounds) == 4 and ground_data:
        ground_bounds = ground_data.get("bbox_ft", [])
        if len(ground_bounds) == 4:
            # Simple setback visualization: draw lines from plot to building edges
            plot_x0, plot_y0, plot_x1, plot_y1 = plot_bounds
            build_x0, build_y0, build_x1, build_y1 = ground_bounds

            # Front setback (assuming north is top)
            front_dist = setbacks.get("front", 0)
            if front_dist:
                ax.plot([plot_x0, plot_x0], [plot_y1 - front_dist, plot_y1], color="#ef4444", linewidth=1)
                ax.text(plot_x0 + 1, (plot_y1 + plot_y1 - front_dist)/2, f"Front: {front_dist:.1f}ft",
                       fontsize=8, color="#ef4444", ha="left")

            # Rear setback
            rear_dist = setbacks.get("rear", 0)
            if rear_dist:
                ax.plot([plot_x1, plot_x1], [plot_y0 + rear_dist, plot_y0], color="#ef4444", linewidth=1)
                ax.text(plot_x1 - 1, (plot_y0 + plot_y0 + rear_dist)/2, f"Rear: {rear_dist:.1f}ft",
                       fontsize=8, color="#ef4444", ha="right")

            # Left setback
            left_dist = setbacks.get("left", 0)
            if left_dist:
                ax.plot([plot_x0 + left_dist, plot_x0], [plot_y0, plot_y0], color="#ef4444", linewidth=1)
                ax.text((plot_x0 + plot_x0 + left_dist)/2, plot_y0 + 1, f"Left: {left_dist:.1f}ft",
                       fontsize=8, color="#ef4444", ha="center")

            # Right setback
            right_dist = setbacks.get("right", 0)
            if right_dist:
                ax.plot([plot_x1 - right_dist, plot_x1], [plot_y1, plot_y1], color="#ef4444", linewidth=1)
                ax.text((plot_x1 + plot_x1 - right_dist)/2, plot_y1 - 1, f"Right: {right_dist:.1f}ft",
                       fontsize=8, color="#ef4444", ha="center", va="top")

    # Add area labels
    areas = result.get("areas", {})
    plot_area = areas.get("plot_sqft")
    ground_area = areas.get("ground_sqft")
    if plot_area and ground_area:
        # Label plot area
        plot_center_x = (plot_bounds[0] + plot_bounds[2]) / 2 if len(plot_bounds) == 4 else 0
        plot_center_y = (plot_bounds[1] + plot_bounds[3]) / 2 if len(plot_bounds) == 4 else 0
        ax.text(plot_center_x, plot_center_y, f"Plot: {plot_area:.0f} sqft",
               fontsize=10, ha="center", va="center", bbox=dict(boxstyle="round,pad=0.3", facecolor="white", alpha=0.8))

        # Label building area
        build_center_x = (ground_bounds[0] + ground_bounds[2]) / 2 if len(ground_bounds) == 4 else 0
        build_center_y = (ground_bounds[1] + ground_bounds[3]) / 2 if len(ground_bounds) == 4 else 0
        ax.text(build_center_x, build_center_y, f"Building: {ground_area:.0f} sqft",
               fontsize=10, ha="center", va="center", bbox=dict(boxstyle="round,pad=0.3", facecolor="white", alpha=0.8))


def render_drawing_pdf(doc: Any, pdf_path: Path, result: dict[str, Any] | None = None, scale_to_ft: float = 1.0) -> bool:
    try:
        import matplotlib
        matplotlib.use("Agg")
        import matplotlib.pyplot as plt  # type: ignore
    except Exception:
        return False

    fig = plt.figure(figsize=(11.69, 8.27))
    ax = fig.add_subplot(1, 1, 1)
    ax.set_aspect("equal", adjustable="box")
    count = 0
    x_vals: list[float] = []
    y_vals: list[float] = []
    for pts in iter_linework(doc, scale_to_ft):
        if not pts or len(pts) < 2:
            continue
        xs, ys = zip(*pts)
        ax.plot(xs, ys, linewidth=0.35, color="#111111")
        x_vals.extend(xs)
        y_vals.extend(ys)
        count += 1

    if result:
        draw_measurement_annotations(ax, result)

    if x_vals and y_vals:
        margin_x = (max(x_vals) - min(x_vals)) * 0.05
        margin_y = (max(y_vals) - min(y_vals)) * 0.05
        ax.set_xlim(min(x_vals) - margin_x, max(x_vals) + margin_x)
        ax.set_ylim(min(y_vals) - margin_y, max(y_vals) + margin_y)

    ax.axis("off")
    if count == 0:
        plt.close(fig)
        return False
    fig.tight_layout(pad=0)
    fig.savefig(str(pdf_path), format="pdf")
    plt.close(fig)
    return True


def iter_mapped_entities(
    doc: Any,
    standard_layers: dict[str, Any],
    allowed_std_layers: set[str],
    aliases: dict[str, str] | None = None,
    layer_map: dict[str, Any] | None = None,
) -> Iterable[dict[str, Any]]:
    for entity in doc.modelspace():
        raw_layer = canon(getattr(entity.dxf, "layer", None))
        std_layer = resolve_standard_layer(raw_layer, standard_layers, aliases)
        if not std_layer and layer_map and raw_layer in layer_map:
            meta = layer_map.get(raw_layer, {})
            if isinstance(meta, dict):
                std_layer = LAYER_TAG_TO_STANDARD.get(meta.get("tag", ""))
        if not std_layer or std_layer not in allowed_std_layers:
            continue
        pts = _xy_points(entity)
        if not pts:
            continue
        confidence = _confidence_for_layer(raw_layer, std_layer, layer_map)
        yield {
            "handle": str(getattr(entity.dxf, "handle", "")),
            "type": entity.dxftype(),
            "raw_layer": raw_layer,
            "standard_layer": std_layer,
            "points": pts,
            "closed": _is_closed(entity, pts),
            "confidence": confidence,
        }


def extract_entity_features(
    mapped_entities: Iterable[dict[str, Any]],
    scale_to_ft: float = 1.0,
    max_points: int = 16,
) -> list[dict[str, Any]]:
    out = []
    for item in mapped_entities:
        pts = [(float(x) * scale_to_ft, float(y) * scale_to_ft) for x, y in item["points"]]
        entry: dict[str, Any] = {
            "handle": item["handle"],
            "raw_layer": item["raw_layer"],
            "standard_layer": item["standard_layer"],
            "type": item["type"],
            "closed": item["closed"],
            "confidence": item.get("confidence", 0.0),
            "point_count": len(pts),
            "points": _downsample_points(pts, max_points=max_points),
        }
        if item["closed"]:
            poly = _safe_polygon(_close_ring(pts))
            if poly:
                entry["area_sqft"] = float(poly.area)
                entry["bbox_ft"] = list(poly.bounds)
                entry["centroid_ft"] = [float(poly.centroid.x), float(poly.centroid.y)]
                entry["rectangularity"] = _polygon_rectangularity(poly)
        else:
            line = LineString(pts) if len(pts) >= 2 else None
            if line:
                entry["length_ft"] = float(line.length)
                entry["bbox_ft"] = list(line.bounds)
        out.append(entry)
    return out


def extract_closed_polygons(
    mapped_entities: Iterable[dict[str, Any]],
    scale_to_ft: float = 1.0,
) -> list[dict[str, Any]]:
    out = []
    for item in mapped_entities:
        if not item["closed"]:
            continue
        ring = _close_ring(item["points"])
        poly = _safe_polygon(ring)
        if not poly:
            continue
        if scale_to_ft != 1.0:
            poly = Polygon([(x * scale_to_ft, y * scale_to_ft) for x, y in poly.exterior.coords])
        out.append({
            "handle": item["handle"],
            "raw_layer": item["raw_layer"],
            "standard_layer": item["standard_layer"],
            "method": item.get("method"),
            "confidence": item.get("confidence", 0.0),
            "geometry": poly,
            "area_sqft": float(poly.area),
            "bbox_ft": list(poly.bounds),
        })
    out.sort(key=lambda x: x["area_sqft"], reverse=True)
    return out


def extract_setback_lines(
    mapped_entities: Iterable[dict[str, Any]],
    scale_to_ft: float = 1.0,
) -> list[dict[str, Any]]:
    lines = []
    for item in mapped_entities:
        if item["standard_layer"] not in SETBACK_LAYERS:
            continue
        pts = item["points"]
        if len(pts) < 2:
            continue
        line = LineString([(x * scale_to_ft, y * scale_to_ft) for x, y in pts])
        if line.length == 0:
            continue
        lines.append({
            "handle": item["handle"],
            "raw_layer": item["raw_layer"],
            "standard_layer": item["standard_layer"],
            "geometry": line,
            "length_ft": float(line.length),
        })
    return lines


def find_polygon_by_handle(polygons: list[dict[str, Any]], handle: str | None) -> dict[str, Any] | None:
    if not handle:
        return None
    target = str(handle).strip()
    for p in polygons:
        if p["handle"] == target:
            return p
    return None


def select_plot_polygon(
    polygons: list[dict[str, Any]],
    plot_layers: set[str],
    plot_handle: str | None = None,
    allow_heuristic_fallback: bool = False,
) -> dict[str, Any] | None:
    if plot_handle:
        exact = find_polygon_by_handle(polygons, plot_handle)
        if exact:
            return exact

    plot_layers_lower = {canon(layer).lower() for layer in plot_layers}
    candidates = [
        p for p in polygons
        if p["standard_layer"] and canon(p["standard_layer"]).lower() in plot_layers_lower
    ]
    if candidates:
        return max(candidates, key=lambda p: p["area_sqft"], default=None)

    plot_keywords = ("plot", "boundary", "site", "perimeter")
    raw_candidates = [
        p for p in polygons
        if p["raw_layer"] and any(keyword in canon(p["raw_layer"]).lower() for keyword in plot_keywords)
    ]
    if raw_candidates:
        return max(raw_candidates, key=lambda p: p["area_sqft"], default=None)

    if allow_heuristic_fallback and polygons:
        return max(polygons, key=lambda p: p["area_sqft"])

    return None


def select_floor_footprints(
    polygons: list[dict[str, Any]],
    plot: dict[str, Any],
    footprint_layers: set[str],
    floor_handles: list[dict[str, Any]] | None = None,
    allow_heuristic_fallback: bool = False,
) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    plot_geom = plot["geometry"]

    floor_name_by_index = {0: "GF-WE", 1: "FF-WE", 2: "SF-WE", -1: "BSM-WE"}
    if floor_handles:
        for item in floor_handles:
            try:
                floor_index = int(item.get("floor", 0))
            except Exception:
                continue
            handle = str(item.get("handle", "")).strip()
            if not handle:
                continue
            poly = find_polygon_by_handle(polygons, handle)
            if not poly:
                continue
            floor_name = floor_name_by_index.get(floor_index)
            if not floor_name:
                continue
            if not (poly["geometry"].within(plot_geom.buffer(1e-6)) or poly["geometry"].intersects(plot_geom.buffer(1e-6))):
                continue
            out[floor_name] = poly
        if out:
            return out

    footprint_layers_lower = {layer.lower() for layer in footprint_layers}

    def inside_plot(poly: dict[str, Any]) -> bool:
        return poly["geometry"].within(plot_geom.buffer(1e-6))

    def contacts_plot(poly: dict[str, Any]) -> bool:
        return poly["geometry"].intersects(plot_geom.buffer(1e-6))

    for layer in ("GF-WE", "FF-WE", "SF-WE", "BSM-WE"):
        if layer.lower() in footprint_layers_lower and layer not in out:
            cands = [
                p for p in polygons
                if p["standard_layer"] and p["standard_layer"].lower() == layer.lower() and inside_plot(p)
            ]
            if cands:
                out[layer] = max(cands, key=lambda p: p["area_sqft"])
    if out:
        return out

    for layer in ("GF-WE", "FF-WE", "SF-WE", "BSM-WE"):
        if layer.lower() in footprint_layers_lower and layer not in out:
            cands = [
                p for p in polygons
                if p["standard_layer"] and p["standard_layer"].lower() == layer.lower() and p != plot and contacts_plot(p)
            ]
            if cands:
                out[layer] = max(cands, key=lambda p: p["area_sqft"])
    if out:
        return out

    if not allow_heuristic_fallback:
        return out

    inner_polygons = [
        p for p in polygons
        if p["geometry"].within(plot_geom.buffer(1e-6)) and p != plot
    ]
    if not inner_polygons:
        return out

    # Prefer external wall layers first, otherwise largest polygon inside plot.
    external = [p for p in inner_polygons if p["standard_layer"] in {"GF-WE", "FF-WE", "SF-WE"}]
    if external:
        for layer in ("GF-WE", "FF-WE", "SF-WE"):
            layer_cands = [p for p in external if p["standard_layer"] == layer]
            if layer_cands:
                out[layer] = max(layer_cands, key=lambda p: p["area_sqft"])
        if out:
            return out

    largest = max(inner_polygons, key=lambda p: p["area_sqft"])
    out["GF-WE"] = largest
    return out


def measure_site_sb_to_ground(
    setback_lines: list[dict[str, Any]],
    ground_poly: dict[str, Any],
) -> list[dict[str, Any]]:
    boundary = ground_poly["geometry"].boundary
    out = []
    for item in setback_lines:
        line = item["geometry"]
        d = float(distance(line, boundary))
        seg = shortest_line(line, boundary)
        out.append({
            "handle": item["handle"],
            "raw_layer": item["raw_layer"],
            "standard_layer": item["standard_layer"],
            "distance_ft": round(d, 3),
            "nearest_segment_ft": [[round(x, 3), round(y, 3)] for x, y in seg.coords],
        })
    out.sort(key=lambda x: x["distance_ft"])
    return out


def coerce_point(pt: Any) -> list[float]:
    try:
        return [float(pt.x), float(pt.y)]
    except Exception:
        return [0.0, 0.0]


def extract_entity_text(entity: Any) -> str | None:
    try:
        if entity.dxftype() == "TEXT":
            return str(entity.dxf.text or "").strip()
        elif entity.dxftype() == "MTEXT":
            return str(entity.text or "").strip()
    except Exception:
        pass
    return None


def extract_dimension_text(doc: Any, scale_to_ft: float = 1.0) -> list[dict[str, Any]]:
    dimensions = []
    for entity in doc.modelspace():
        if entity.dxftype() not in ("TEXT", "MTEXT"):
            continue
        text = extract_entity_text(entity)
        if not text:
            continue
        # Parse patterns like "10'", "5 ft", "3.5 feet"
        match = re.search(r"(\d+(?:\.\d+)?)\s*(?:'|ft|feet)", text, re.IGNORECASE)
        if match:
            value_ft = float(match.group(1)) * scale_to_ft
            dimensions.append({
                "text": text,
                "value_ft": value_ft,
                "position": coerce_point(entity.dxf.insert),
            })
    return dimensions


def compute_plot_setback_gaps(
    plot: dict[str, Any],
    ground: dict[str, Any],
    front_side: str | None = None,
) -> dict[str, float]:
    plot_geom = plot["geometry"]
    ground_geom = ground["geometry"]
    min_distance = distance(plot_geom, ground_geom)
    # Use bbox for directional gaps, but use actual min distance for front
    plot_minx, plot_miny, plot_maxx, plot_maxy = plot_geom.bounds
    ground_minx, ground_miny, ground_maxx, ground_maxy = ground_geom.bounds
    left_gap = max(0.0, ground_minx - plot_minx)
    right_gap = max(0.0, plot_maxx - ground_maxx)
    bottom_gap = max(0.0, ground_miny - plot_miny)
    top_gap = max(0.0, plot_maxy - ground_maxy)

    def choose_default_orientation() -> tuple[str, str]:
        horizontal_front = min(left_gap, right_gap)
        vertical_front = min(top_gap, bottom_gap)
        if horizontal_front <= vertical_front:
            return ("west", "east") if left_gap <= right_gap else ("east", "west")
        return ("south", "north") if bottom_gap <= top_gap else ("north", "south")

    if front_side in ("north", "south", "east", "west"):
        front_dir = front_side
    else:
        front_dir, _ = choose_default_orientation()

    # Use actual min distance for front setback, bbox for others
    if front_dir == "north":
        front = min_distance
        rear = bottom_gap
        left = left_gap
        right = right_gap
    elif front_dir == "south":
        front = min_distance
        rear = top_gap
        left = right_gap
        right = left_gap
    elif front_dir == "east":
        front = min_distance
        rear = left_gap
        left = bottom_gap
        right = top_gap
    else:
        front = min_distance
        rear = right_gap
        left = top_gap
        right = bottom_gap

    return {
        "front": round(front, 3),
        "rear": round(rear, 3),
        "left": round(left, 3),
        "right": round(right, 3),
        "front_side": front_dir,
    }


def evaluate_rules(
    rules_json: dict[str, Any],
    plot: dict[str, Any],
    floors: dict[str, dict[str, Any]],
    polygons: list[dict[str, Any]],
    setback_gaps: dict[str, float] | None,
    lot_size_ratio: float = 1.0,
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    plot_area = plot["area_sqft"]
    ground = floors.get("GF-WE")
    ground_area = ground["area_sqft"] if ground else None
    total_floor_area = sum(v["area_sqft"] for v in floors.values())
    far = (total_floor_area / plot_area) if plot_area else None
    coverage = ((ground_area / plot_area) * 100.0) if (ground_area is not None and plot_area) else None
    active_rules, ruleset_context = normalize_rules_for_plot(rules_json, plot_area)

    def ok(op: str, measured: float | int | dict | None, required: float | int | dict | None) -> bool | None:
        if measured is None or required is None:
            return None
        if isinstance(measured, dict) or isinstance(required, dict):
            return measured == required
        return {
            ">=": measured >= required,
            "<=": measured <= required,
            ">": measured > required,
            "<": measured < required,
            "==": measured == required,
        }.get(op)

    def get_setback(direction: str) -> float | None:
        if not setback_gaps:
            return None
        return setback_gaps.get(direction)

    def longest_edge_length(poly: dict[str, Any]) -> float:
        coords = list(poly["geometry"].exterior.coords)
        max_len = 0.0
        for i in range(len(coords) - 1):
            segment = LineString([coords[i], coords[i + 1]])
            max_len = max(max_len, float(segment.length))
        return max_len

    porch_polygons = [p for p in polygons if p["standard_layer"] == "GF-PR"]
    porch_length = max((longest_edge_length(p) for p in porch_polygons), default=0.0)
    first_floor = floors.get("FF-WE")
    porch_room_above = False
    if first_floor and porch_polygons:
        porch_geom = porch_polygons[0]["geometry"]
        porch_room_above = any(
            porch_geom.intersects(first_floor["geometry"]) and porch_geom.intersection(first_floor["geometry"]).area > 0
            for porch_geom in [p["geometry"] for p in porch_polygons]
        )

    service_polygons = [p for p in polygons if p["standard_layer"] in {"GF-SRV", "FF-SRV", "SF-SRV"}]
    max_service_area = max((p["area_sqft"] for p in service_polygons), default=0.0)

    def boolean_flag(layer_name: str) -> bool:
        return any(p["standard_layer"] == layer_name for p in polygons)

    has_overhead_tank = boolean_flag("RF-WT")
    has_underground_tank = boolean_flag("BSM-SRV") or boolean_flag("UTIL-WTR")

    front_setback = get_setback("front")
    rear_setback = get_setback("rear")
    left_setback = get_setback("left")
    right_setback = get_setback("right")
    side_setback = None
    if left_setback is not None and right_setback is not None:
        side_setback = min(left_setback, right_setback)
    elif left_setback is not None:
        side_setback = left_setback
    elif right_setback is not None:
        side_setback = right_setback

    out = []
    for rule in active_rules:
        rid = rule.get("id")
        op = rule.get("operator", ">=")
        title = rule.get("title")
        rule_type = rule.get("type") or rule.get("rule_type")
        source_refs = rule.get("source_refs", [])
        description = rule.get("description")

        if rid == "SETBACK_FRONT":
            req = rule.get("value_ft")
            measured = front_setback
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": "ft",
                "pass": ok(op, measured, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "SETBACK_REAR":
            req = rule.get("value_ft")
            measured = rear_setback
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": "ft",
                "pass": ok(op, measured, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "SETBACK_SIDE":
            req = rule.get("value_ft")
            measured = side_setback
            if req == 0 and op == "==":
                rule_pass = measured is not None and measured >= 0
            else:
                rule_pass = ok(op, measured, req)
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": "ft",
                "pass": rule_pass,
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "GROUND_COVERAGE":
            req = rule.get("value_percent")
            if not isinstance(req, (int, float)):
                req = None
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": round(coverage, 3) if coverage is not None else None,
                "required": req,
                "unit": "%",
                "pass": ok(op, coverage, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "FAR_LIMIT":
            req = rule.get("value")
            if not isinstance(req, (int, float)):
                req = None
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": round(far, 4) if far is not None else None,
                "required": req,
                "unit": "ratio",
                "pass": ok(op, far, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "MAX_STOREYS":
            req = rule.get("value")
            measured = len(floors)
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": "storeys",
                "pass": ok(op, measured, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "MAX_HEIGHT":
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": None,
                "required": rule.get("value_ft"),
                "unit": "ft",
                "pass": None,
                "note": "manual_review",
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "STOREY_CLEAR_HEIGHT":
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": None,
                "required": rule.get("value_ft"),
                "unit": "ft",
                "pass": None,
                "note": "manual_review",
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "PORCH_LENGTH":
            req = rule.get("value_ft")
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": round(porch_length, 3),
                "required": req,
                "unit": "ft",
                "pass": ok(op, porch_length, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "PORCH_ROOM_NOT_ALLOWED":
            measured = porch_room_above
            req = rule.get("value")
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": None,
                "pass": ok(op, measured, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "REAR_TOILET_AREA":
            req = rule.get("value_sqft")
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": round(max_service_area, 3),
                "required": req,
                "unit": "sqft",
                "pass": ok(op, max_service_area, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "REAR_TOILET_HEIGHT":
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": None,
                "required": rule.get("value_ft"),
                "unit": "ft",
                "pass": None,
                "note": "manual_review",
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "WATER_TANKS":
            req = rule.get("requirements") or {}
            measured = {
                "underground_tank": has_underground_tank,
                "overhead_tank": has_overhead_tank,
            }
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": measured,
                "required": req,
                "unit": None,
                "pass": ok(op, measured, req),
                "details": description,
                "source_refs": source_refs,
            })
        elif rid == "ENERGY_INSULATION":
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": None,
                "required": rule.get("requirements"),
                "unit": None,
                "pass": None,
                "note": "manual_review",
                "details": description,
                "source_refs": source_refs,
            })
        else:
            out.append({
                "id": rid,
                "title": title,
                "type": rule_type,
                "measured": None,
                "required": extract_rule_value(rule),
                "unit": None,
                "pass": None,
                "note": rule.get("note", "manual_review"),
                "details": description,
                "source_refs": source_refs,
            })
    return out, ruleset_context


def run_analysis(
    doc: Any,
    rules_json: dict[str, Any],
    layers_json: dict[str, Any],
    resolver: LayerResolver,
    source_file: str | None = None,
    min_confidence: float = 0.75,
    allow_heuristic_fallback: bool = False,
    unit_override: str | None = None,
    front_side: str | None = None,
    plot_handle: str | None = None,
    plot_layer: str | None = None,
    building_layer: str | None = None,
    floor_handles: list[dict[str, Any]] | None = None,
    layer_map: dict[str, Any] | None = None,
) -> dict[str, Any]:
    standard_layers = layers_json["layers"]
    rules_to_layers = layers_json.get("rules_to_layers", {})
    layer_map = layer_map or {}

    # 1) Build expected semantic layers from layers.json
    plot_layers = {
        std_layer
        for std_layer, info in standard_layers.items()
        if info.get("tag") in {
            "plot_boundary",
            "site_boundary",
            "boundary",
            "plot",
        }
    }

    footprint_layers = {
        std_layer
        for std_layer, info in standard_layers.items()
        if info.get("tag") in {
            "ground_external_walls",
            "first_external_walls",
            "second_external_walls",
            "basement_external_walls",
            "ground_floor",
            "first_floor",
            "second_floor",
            "building",
            "floor",
            "footprint",
        }
    }

    setback_layers = {
        std_layer
        for std_layer, info in standard_layers.items()
        if info.get("tag") in {
            "setback",
            "building_line",
            "front_building_line",
        }
    }

    # 2) Add safe default layers
    plot_layers.update({"SITE-PL", "SITE-BW"})
    footprint_layers.update({"GF-WE", "FF-WE", "SF-WE", "BSM-WE"})
    setback_layers.update({"SITE-SB", "SITE-FBL"})

    # 3) Create allowed BEFORE using allowed.add(...)
    allowed = build_allowed_layers(rules_json, rules_to_layers)
    allowed.update(standard_layers.keys())
    allowed.update(plot_layers)
    allowed.update(footprint_layers)
    allowed.update(setback_layers)

    # 4) Apply explicit plot layer from Laravel/options
    if plot_layer:
        resolved_plot_layer = resolver.resolve(plot_layer).get("standard_layer")

        if not resolved_plot_layer and layer_map and plot_layer in layer_map:
            meta = layer_map.get(plot_layer, {})
            if isinstance(meta, dict):
                resolved_plot_layer = LAYER_TAG_TO_STANDARD.get(meta.get("tag", ""))

        if resolved_plot_layer:
            plot_layers.add(resolved_plot_layer)
            allowed.add(resolved_plot_layer)
        else:
            fallback_plot_layer = canon(plot_layer)
            if fallback_plot_layer:
                plot_layers.add(fallback_plot_layer)
                allowed.add(fallback_plot_layer)

    # 5) Apply explicit building/floor layer from Laravel/options
    if building_layer:
        resolved_building_layer = resolver.resolve(building_layer).get("standard_layer")

        if not resolved_building_layer and layer_map and building_layer in layer_map:
            meta = layer_map.get(building_layer, {})
            if isinstance(meta, dict):
                resolved_building_layer = LAYER_TAG_TO_STANDARD.get(meta.get("tag", ""))

        if resolved_building_layer:
            footprint_layers.add(resolved_building_layer)
            allowed.add(resolved_building_layer)
        else:
            fallback_building_layer = canon(building_layer)
            if fallback_building_layer:
                footprint_layers.add(fallback_building_layer)
                allowed.add(fallback_building_layer)

    # 6) Extract entities
    scale = to_ft_factor(doc, unit_override)

    candidates = extract_candidate_entities(
        doc,
        resolver,
        allowed,
        min_confidence=min_confidence,
    )

    accepted_candidates = [c for c in candidates if c.get("accepted")]

    dimensions = extract_dimension_text(doc, scale)
    entity_features = extract_entity_features(accepted_candidates, scale)
    polygons = extract_closed_polygons(accepted_candidates, scale)

    # 7) Select plot
    plot = select_plot_polygon(
        polygons,
        plot_layers,
        plot_handle=plot_handle,
        allow_heuristic_fallback=allow_heuristic_fallback,
    )

    if not plot:
        return {
            "status": "error",
            "error_code": "plot_not_found",
            "message": (
                "No plot polygon matched after layer-json filtering. "
                "Run with --list-polys, then pass --plot-handle for the actual plot. "
                "Do not blindly rely on --allow-heuristic-fallback because it may select the drawing sheet."
            ),
            "expected_layers": sorted(list(plot_layers)),
            "debug": {
                "polygon_count": len(polygons),
                "accepted_candidate_count": len(accepted_candidates),
                "available_polygon_summary": [
                    {
                        "handle": p.get("handle"),
                        "raw_layer": p.get("raw_layer"),
                        "standard_layer": p.get("standard_layer"),
                        "area_sqft": round(float(p.get("area_sqft", 0.0)), 3),
                        "bbox_ft": p.get("bbox_ft"),
                    }
                    for p in polygons[:25]
                ],
            },
        }

    # 8) Select floor footprints
    floors = select_floor_footprints(
        polygons,
        plot,
        footprint_layers,
        floor_handles=floor_handles,
        allow_heuristic_fallback=allow_heuristic_fallback,
    )

    if not floors:
        return {
            "status": "error",
            "error_code": "ground_footprint_not_found",
            "message": (
                "No floor footprint polygon found inside the selected plot polygon. "
                "Run with --list-polys and pass --floor-handles, or fix layer mapping. "
                "If the building is drawn as separate wall lines, polygonization fallback is required."
            ),
            "debug": {
                "selected_plot": {
                    "handle": plot.get("handle"),
                    "raw_layer": plot.get("raw_layer"),
                    "standard_layer": plot.get("standard_layer"),
                    "area_sqft": round(float(plot.get("area_sqft", 0.0)), 3),
                    "bbox_ft": plot.get("bbox_ft"),
                },
                "expected_footprint_layers": sorted(list(footprint_layers)),
                "polygon_count": len(polygons),
                "candidate_floor_polygons": [
                    {
                        "handle": p.get("handle"),
                        "raw_layer": p.get("raw_layer"),
                        "standard_layer": p.get("standard_layer"),
                        "area_sqft": round(float(p.get("area_sqft", 0.0)), 3),
                        "bbox_ft": p.get("bbox_ft"),
                        "inside_plot": bool(
                            p.get("geometry").within(plot["geometry"].buffer(1e-6))
                        ) if p.get("geometry") is not None else False,
                        "intersects_plot": bool(
                            p.get("geometry").intersects(plot["geometry"].buffer(1e-6))
                        ) if p.get("geometry") is not None else False,
                    }
                    for p in polygons[:50]
                    if p.get("handle") != plot.get("handle")
                ],
            },
        }

    if "GF-WE" not in floors and not allow_heuristic_fallback:
        return {
            "status": "error",
            "error_code": "ground_footprint_not_found",
            "message": (
                "No GF-WE polygon found inside the selected plot polygon. "
                "Use --floor-handles or enable --allow-heuristic-fallback."
            ),
            "debug": {
                "detected_floors": list(floors.keys()),
                "expected_footprint_layers": sorted(list(footprint_layers)),
            },
        }

    ground_poly = floors.get("GF-WE") or next(iter(floors.values()))

    # 9) Measurements
    setback_lines = extract_setback_lines(accepted_candidates, scale)
    setbacks = measure_site_sb_to_ground(setback_lines, ground_poly)
    setback_gaps = compute_plot_setback_gaps(plot, ground_poly, front_side=front_side)

    lot_size_ratio = 1.0

    rule_results, ruleset_context = evaluate_rules(
        rules_json,
        plot,
        floors,
        polygons,
        setback_gaps,
        lot_size_ratio,
    )

    # 10) Confidence
    plot_confidence = 1.0 if plot_handle else (
        0.9 if plot.get("standard_layer") in plot_layers else 0.6
    )

    selected_floor_handles = {
        str(item.get("handle", "")).strip()
        for item in (floor_handles or [])
        if item.get("handle")
    }

    floor_confidences = {}

    for layer, fp in floors.items():
        if str(fp.get("handle", "")).strip() in selected_floor_handles:
            floor_confidences[layer] = 1.0
        elif fp.get("standard_layer") in footprint_layers:
            floor_confidences[layer] = 0.9
        else:
            floor_confidences[layer] = 0.6

    floors_list = []

    for layer, fp in floors.items():
        floors_list.append({
            "floor": {
                "GF-WE": 0,
                "FF-WE": 1,
                "SF-WE": 2,
                "BSM-WE": -1,
            }.get(layer, 0),
            "handle": fp.get("handle"),
            "layer": fp.get("standard_layer"),
            "raw_layer": fp.get("raw_layer"),
            "method": fp.get("method"),
            "confidence": floor_confidences.get(layer, 0.0),
            "area_sqft": round(float(fp.get("area_sqft", 0.0)), 3),
            "bbox_ft": fp.get("bbox_ft"),
        })

    floors_list.sort(key=lambda x: (x["floor"], -x["area_sqft"]))

    ground_floor_data = {
        "floor": {
            "GF-WE": 0,
            "FF-WE": 1,
            "SF-WE": 2,
            "BSM-WE": -1,
        }.get(next((k for k, v in floors.items() if v == ground_poly), "GF-WE"), 0),
        "handle": ground_poly.get("handle"),
        "layer": ground_poly.get("standard_layer"),
        "raw_layer": ground_poly.get("raw_layer"),
        "method": ground_poly.get("method"),
        "confidence": ground_poly.get("confidence", 0.0),
        "area_sqft": round(float(ground_poly.get("area_sqft", 0.0)), 3),
        "bbox_ft": ground_poly.get("bbox_ft"),
    }

    warnings = [r["id"] for r in rule_results if r.get("pass") is None]

    if front_side is None and setback_lines:
        warnings.append("rear_setback_semantics_unspecified")

    # Warn if selected plot looks like full drawing sheet
    if float(plot.get("area_sqft", 0.0)) > 5000:
        warnings.append("selected_plot_may_be_drawing_sheet_or_title_border")

    # Warn if selected building is suspiciously small
    if float(ground_poly.get("area_sqft", 0.0)) < 250:
        warnings.append("selected_ground_floor_area_seems_too_small")

    total_floor_area = sum(float(v.get("area_sqft", 0.0)) for v in floors.values())
    plot_area = float(plot.get("area_sqft", 0.0))
    ground_area = float(ground_poly.get("area_sqft", 0.0))

    return {
        "status": "ok",
        "source_type": PIPELINE_SOURCE_TYPE,
        "model_version": PIPELINE_MODEL_VERSION,

        "units": {
            "detected": detect_units(doc),
            "scale_to_ft": scale,
        },
        "unit": detect_units(doc),
        "scale_to_ft": scale,

        "overlay": {
            "generated": False,
        },

        "selection": {
            "plot": {
                "handle": plot.get("handle"),
                "layer": plot.get("standard_layer") or plot.get("raw_layer"),
                "raw_layer": plot.get("raw_layer"),
                "standard_layer": plot.get("standard_layer"),
                "method": plot.get("method"),
                "confidence": plot_confidence,
                "area_sqft": round(plot_area, 3),
                "bbox_ft": plot.get("bbox_ft"),
            },

            "floors": {
                layer: {
                    "handle": fp.get("handle"),
                    "layer": fp.get("standard_layer"),
                    "raw_layer": fp.get("raw_layer"),
                    "method": fp.get("method"),
                    "confidence": floor_confidences.get(layer, 0.0),
                    "area_sqft": round(float(fp.get("area_sqft", 0.0)), 3),
                    "bbox_ft": fp.get("bbox_ft"),
                }
                for layer, fp in floors.items()
            },

            "floor_list": floors_list,
            "ground_floor": ground_floor_data,
        },

        "plot_bbox_ft": plot.get("bbox_ft"),
        "ground_bbox_ft": ground_poly.get("bbox_ft"),

        "setbacks_ft": {
            "front": setback_gaps.get("front"),
            "rear": setback_gaps.get("rear"),
            "left": setback_gaps.get("left"),
            "right": setback_gaps.get("right"),
            "gaps": setback_gaps,
            "from_SITE_SB": setbacks,
        },

        "areas": {
            "plot_sqft": round(plot_area, 3),
            "ground_sqft": round(ground_area, 3),
            "ground_floor_sqft": round(ground_area, 3),
            "first_sqft": round(float(floors.get("FF-WE", {}).get("area_sqft", 0.0)), 3) if "FF-WE" in floors else None,
            "second_sqft": round(float(floors.get("SF-WE", {}).get("area_sqft", 0.0)), 3) if "SF-WE" in floors else None,
            "total_floor_sqft": round(total_floor_area, 3),
            "coverage_percent": round((ground_area / plot_area) * 100.0, 3) if plot_area else None,
            "far": round(total_floor_area / plot_area, 4) if plot_area else None,
            "storeys_detected": len(floors_list),
        },

        "rules": rule_results,
        "resolved_ruleset": ruleset_context,
        "entity_features": entity_features,
        "dimensions": dimensions,

        "resolver": {
            "mode": resolver.mode,
            "model_path": getattr(resolver, "model", None) is not None,
            "warnings": resolver.warnings,
        },

        "debug": {
            "expected_plot_layers": sorted(list(plot_layers)),
            "expected_footprint_layers": sorted(list(footprint_layers)),
            "expected_setback_layers": sorted(list(setback_layers)),
            "accepted_candidate_count": len(accepted_candidates),
            "closed_polygon_count": len(polygons),
            "used_plot_handle": plot_handle,
            "used_floor_handles": floor_handles,
            "used_plot_layer": plot_layer,
            "used_building_layer": building_layer,
        },

        "training_events": [
            build_training_event(
                source_file or "",
                candidate,
                "plot"
                if candidate.get("handle") == plot.get("handle")
                else (
                    "floor"
                    if candidate.get("handle") in {
                        fp.get("handle") for fp in floors.values()
                    }
                    else (
                        "ignored"
                        if not candidate.get("accepted")
                        else "unknown"
                    )
                ),
            )
            for candidate in candidates
        ],

        "warnings": warnings,
    }

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dxf", required=True)
    ap.add_argument("--rules", required=True)
    ap.add_argument("--layers-json", required=True)
    ap.add_argument("--out", default=None, help="Optional overlay PDF output path")
    ap.add_argument("--drawing-out", default=None, help="Optional drawing preview PDF output path")
    ap.add_argument("--layer-map-json", default=None, help="Optional JSON string for layer mapping overrides")
    ap.add_argument("--plot-layer", default=None)
    ap.add_argument("--building-layer", default=None)
    ap.add_argument("--plot-handle", default=None)
    ap.add_argument("--front-side", choices=["north", "south", "east", "west"], default=None)
    ap.add_argument("--floor-handles", default=None, help="JSON array of floor handle objects")
    ap.add_argument("--unit", default=None, help="Optional override: in|ft|mm|cm|m")
    ap.add_argument("--resolver-model", default=None, help="Optional trained resolver model path")
    ap.add_argument("--resolver-mode", choices=["strict", "alias", "trained", "hybrid"], default="hybrid")
    ap.add_argument("--min-confidence", type=float, default=0.75)
    ap.add_argument("--allow-heuristic-fallback", action="store_true")
    ap.add_argument("--training-out", default=None, help="Optional JSONL training output file")
    ap.add_argument("--list-layers", action="store_true")
    ap.add_argument("--list-polys", action="store_true")
    ap.add_argument("--debug", action="store_true")
    args = ap.parse_args()

    def log_debug(message: str) -> None:
        if args.debug:
            print(message, file=sys.stderr)

    layer_map = {}
    if args.layer_map_json:
        try:
            layer_map = json.loads(args.layer_map_json)
        except Exception:
            layer_map = {}
    if not isinstance(layer_map, dict):
        layer_map = {}

    floor_handles = None
    if args.floor_handles:
        try:
            floor_handles = json.loads(args.floor_handles)
        except Exception:
            floor_handles = None

    parser = DxfParser()
    doc = parser.parse(args.dxf)
    scale_to_ft = to_ft_factor(doc, args.unit)
    layers, rules_to_layers = load_layers_json(args.layers_json)
    layers_json = {"layers": layers, "rules_to_layers": rules_to_layers}
    rules_json = json.loads(Path(args.rules).read_text(encoding="utf-8"))

    resolver = LayerResolver(
        layers,
        aliases=LEGACY_ALIASES,
        layer_map=layer_map,
        tag_to_standard=LAYER_TAG_TO_STANDARD,
        model_path=args.resolver_model,
        mode=args.resolver_mode,
    )

    if args.list_layers:
        raw_layers = list_layers(doc)
        mapped_layers = {raw: resolver.resolve(raw)["standard_layer"] for raw in raw_layers}
        unmapped_layers = [raw for raw, std in mapped_layers.items() if std is None]
        print(json.dumps({
            "status": "ok",
            "layers": raw_layers,
            "mapped_layers": mapped_layers,
            "unmapped_layers": unmapped_layers,
            "resolver": {
                "mode": resolver.mode,
                "model_path": getattr(resolver, "model", None) is not None,
                "warnings": resolver.warnings,
            },
        }, default=str))
        return

    if args.list_polys:
        allowed = set(layers.keys())
        candidates = extract_candidate_entities(doc, resolver, allowed, min_confidence=0.0)
        polys = list_polys(candidates)
        print(json.dumps({"status": "ok", "count": len(polys), "polys": polys}, default=str))
        return

    result = run_analysis(
        doc,
        rules_json,
        layers_json,
        resolver,
        source_file=args.dxf,
        min_confidence=args.min_confidence,
        allow_heuristic_fallback=args.allow_heuristic_fallback,
        unit_override=args.unit,
        front_side=args.front_side,
        plot_handle=args.plot_handle,
        plot_layer=args.plot_layer,
        building_layer=args.building_layer,
        floor_handles=floor_handles,
        layer_map=layer_map,
    )

    if args.training_out and isinstance(result.get("training_events"), list):
        try:
            append_training_events(args.training_out, result["training_events"])
        except Exception as exc:
            log_debug(f"Failed to write training events: {exc}")

    if args.out:
        out_path = Path(args.out)
        overlay_success = render_drawing_pdf(doc, out_path, result, scale_to_ft=scale_to_ft)
        result["overlay"] = {"generated": overlay_success, "path": str(out_path)}
        if not overlay_success:
            write_minimal_pdf(
                out_path,
                "CAD Compliance Overlay",
                [
                    f"File: {args.dxf}",
                    f"Status: {result.get('status')}",
                    f"Plot area (sqft): {result.get('areas', {}).get('plot_sqft')}",
                    f"Ground area (sqft): {result.get('areas', {}).get('ground_sqft')}",
                    f"Coverage: {result.get('areas', {}).get('coverage_percent')}%",
                    f"FAR: {result.get('areas', {}).get('far')}",
                ],
            )

    if args.drawing_out:
        drawing_path = Path(args.drawing_out)
        drawing_success = render_drawing_pdf(doc, drawing_path, result, scale_to_ft=scale_to_ft)
        if not drawing_success:
            write_minimal_pdf(
                drawing_path,
                "CAD Drawing Preview",
                [
                    f"File: {args.dxf}",
                    "Drawing preview placeholder.",
                    "Run the full CAD pipeline to generate a true drawing PDF.",
                ],
            )

    print(json.dumps(result, default=str))


if __name__ == "__main__":
    main()
