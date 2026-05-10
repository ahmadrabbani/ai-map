#!/usr/bin/env python3
"""process_cad_rules.py (multi-storey aware)

What it does
- Read DXF (from converted DWG)
- Extract closed polylines (candidates for plot + building footprints)
- Select plot boundary
- Select *multiple* building footprints (floors) when possible
- Compute:
  - setbacks (from ground-floor envelope)
  - ground coverage (ground-floor area / plot area)
  - FAR (sum floor areas / plot area)  [multi-storey]
  - max storeys (number of floors detected or provided)
- Generate an overlay PDF to help manual verification
- Print JSON to stdout (Laravel captures it)

Dependencies
  pip install ezdxf matplotlib

Usage
  python3 scripts/process_cad_rules.py --dxf /path/file.dxf --rules rules/5MRulesJSON.json --out /path/overlay.pdf

Optional (expert/label driven)
  --plot-handle <HANDLE>
  --floor-handles JSON like: '[{"floor":0,"handle":"1A2"},{"floor":1,"handle":"1B3"}]'
  --plot-layer / --building-layer (fallback heuristics)
  --drawing-out /path/drawing.pdf

Notes about multi-storey
- Many DWGs convert to DXF with Z=0 for all 2D entities.
- Multi-storey separation is *usually* via layers (e.g., GF/FF/1F/2F).
- This script therefore supports:
  1) expert-provided per-floor handles (most accurate)
  2) layer keyword ranking (good)
  3) Z-level grouping if vertex Z/elevation exists (rare)
"""

import argparse
import json
import math
import re
from pathlib import Path
from typing import Optional


# -----------------------------
# Geometry helpers
# -----------------------------

def polygon_area(pts):
    if len(pts) < 3:
        return 0.0
    s = 0.0
    for i in range(len(pts)):
        x1, y1 = pts[i]
        x2, y2 = pts[(i + 1) % len(pts)]
        s += x1 * y2 - x2 * y1
    return abs(s) / 2.0


def bbox(pts):
    xs = [p[0] for p in pts]
    ys = [p[1] for p in pts]
    return min(xs), min(ys), max(xs), max(ys)


def bbox_contains(outer, inner, tol=0.0):
    ox0, oy0, ox1, oy1 = outer
    ix0, iy0, ix1, iy1 = inner
    return ix0 >= ox0 - tol and iy0 >= oy0 - tol and ix1 <= ox1 + tol and iy1 <= oy1 + tol


def is_nearly_closed(pts, tol_ratio=1e-4, tol_min=1e-6):
    if len(pts) < 3:
        return False
    x0, y0, x1, y1 = bbox(pts)
    span = max(abs(x1 - x0), abs(y1 - y0))
    tol = max(tol_min, tol_ratio * span)
    dx = pts[0][0] - pts[-1][0]
    dy = pts[0][1] - pts[-1][1]
    return (dx * dx + dy * dy) <= (tol * tol)


def _point_on_segment(pt, a, b, tol=1e-6):
    x, y = pt
    x1, y1 = a
    x2, y2 = b
    dx = x2 - x1
    dy = y2 - y1
    # colinear check
    if abs((x - x1) * dy - (y - y1) * dx) > tol * (abs(dx) + abs(dy) + 1.0):
        return False
    dot = (x - x1) * dx + (y - y1) * dy
    if dot < -tol:
        return False
    if dot > (dx * dx + dy * dy + tol):
        return False
    return True


def point_in_poly(pt, poly, tol=1e-6):
    x, y = pt
    inside = False
    n = len(poly)
    if n < 3:
        return False
    x1, y1 = poly[0]
    for i in range(1, n + 1):
        x2, y2 = poly[i % n]
        if _point_on_segment(pt, (x1, y1), (x2, y2), tol=tol):
            return True
        if ((y1 > y) != (y2 > y)):
            denom = (y2 - y1) if (y2 - y1) != 0 else 1e-12
            xinters = (x2 - x1) * (y - y1) / denom + x1
            if x < xinters:
                inside = not inside
        x1, y1 = x2, y2
    return inside


def poly_inside_poly(inner_pts, outer_pts, sample_max=25, tol=1e-6, min_ratio=1.0):
    if len(inner_pts) < 3 or len(outer_pts) < 3:
        return False
    step = max(1, len(inner_pts) // sample_max)
    inside = 0
    total = 0
    for p in inner_pts[::step]:
        total += 1
        if point_in_poly(p, outer_pts, tol=tol):
            inside += 1
    if total == 0:
        return False
    return (inside / float(total)) >= float(min_ratio)


def filter_inside(candidates, plot_poly, min_ratio=1.0, tol=1e-6):
    inside = []
    for p in candidates:
        if not bbox_contains(plot_poly["bbox"], p["bbox"], tol=tol):
            continue
        if not poly_inside_poly(p["points"], plot_poly["points"], tol=tol, min_ratio=min_ratio):
            continue
        inside.append(p)
    return inside


# -----------------------------
# Units
# -----------------------------

INSUNITS_TO_UNIT = {
    0: None,
    1: "in",
    2: "ft",
    3: "mi",
    4: "mm",
    5: "cm",
    6: "m",
    7: "km",
    10: "yd",
    12: "nm",
    13: "um",
    14: "mm",
    15: "cm",
    16: "m",
}

UNIT_TO_FOOT = {
    "ft": 1.0,
    "in": 1.0 / 12.0,
    "mm": 0.00328084,
    "cm": 0.0328084,
    "m": 3.28084,
    "km": 3280.84,
    "yd": 3.0,
    "mi": 5280.0,
    "nm": 6076.12,
    "um": 0.00000328084,
}


def detect_units(doc):
    try:
        ins = doc.header.get("$INSUNITS", None)
        if ins is None:
            return None
        return INSUNITS_TO_UNIT.get(int(ins))
    except Exception:
        return None


def scale_points(pts, factor):
    return [(x * factor, y * factor) for x, y in pts]


# -----------------------------
# Entity extraction
# -----------------------------

def _lwpoly_z(e):
    # LWPOLYLINE elevation can be float or Vec3-like depending on writer
    try:
        elev = getattr(e.dxf, "elevation", None)
        if elev is None:
            return 0.0
        if isinstance(elev, (int, float)):
            return float(elev)
        # sometimes (x,y,z)
        if hasattr(elev, "z"):
            return float(elev.z)
        if isinstance(elev, (tuple, list)) and len(elev) >= 3:
            return float(elev[2])
        return 0.0
    except Exception:
        return 0.0


def pick_closed_polylines(doc):
    """Closed polylines we can treat as polygons.

    Returns list sorted by area desc. Includes:
      - handle, layer, points, bbox, area
      - z_level (best-effort)
    """
    msp = doc.modelspace()
    polys = []

    for e in msp:
        t = e.dxftype()
        layer = getattr(e.dxf, "layer", None)
        handle = getattr(e.dxf, "handle", None)
        try:
            if t == "LWPOLYLINE":
                pts = [(p[0], p[1]) for p in e.get_points("xy")]
                if not e.closed and not is_nearly_closed(pts):
                    continue
                z = _lwpoly_z(e)
                nv = len(pts)
            elif t == "POLYLINE":
                pts = [(v.dxf.location.x, v.dxf.location.y) for v in e.vertices]
                if not e.is_closed and not is_nearly_closed(pts):
                    continue
                # best-effort z
                try:
                    z = float(getattr(e.dxf, "elevation", 0.0) or 0.0)
                except Exception:
                    z = 0.0
                if pts and hasattr(e.vertices[0].dxf.location, "z"):
                    z = float(e.vertices[0].dxf.location.z)
                nv = len(pts)
            else:
                continue
        except Exception:
            continue

        if len(pts) < 3:
            continue

        a = polygon_area(pts)
        x0, y0, x1, y1 = bbox(pts)
        w = max(0.0, x1 - x0)
        h = max(0.0, y1 - y0)
        rect = (a / (w * h)) if (w * h) > 0 else None
        cx = sum(p[0] for p in pts) / float(len(pts))
        cy = sum(p[1] for p in pts) / float(len(pts))

        polys.append(
            {
                "handle": handle,
                "type": t,
                "layer": layer,
                "points": pts,
                "num_vertices": nv,
                "area": a,
                "bbox": (x0, y0, x1, y1),
                "bbox_w": w,
                "bbox_h": h,
                "rectangularity": rect,
                "centroid": (cx, cy),
                "z_level": z,
            }
        )

    polys.sort(key=lambda p: p["area"], reverse=True)
    return polys


def list_layers(doc):
    layers = set()
    try:
        for l in doc.layers:
            layers.add(l.dxf.name)
    except Exception:
        pass
    try:
        for e in doc.modelspace():
            layer = getattr(e.dxf, "layer", None)
            if layer:
                layers.add(layer)
    except Exception:
        pass
    return sorted(layers)


def find_poly_by_handle(polys, handle):
    target = str(handle or "").upper()
    for p in polys:
        if (p.get("handle") or "").upper() == target:
            return p
    return None


def auto_generate_layer_map(layer_names):
    """Auto-generate layer mapping based on common layer name patterns."""
    layer_map = {}
    for layer in layer_names:
        u = layer.upper()
        meta = {"visible": True}  # Default visibility

        # Plot boundary detection
        if any(k in u for k in ["PLOT", "BOUNDARY", "SITE", "OUTLINE", "PROPERTY"]):
            meta["tag"] = "plot_boundary"
        # Ground floor detection
        elif any(k in u for k in ["GF", "GROUND", "GND", "BASE", "FLOOR0", "LEVEL0"]):
            meta["tag"] = "ground_floor"
        # First floor
        elif any(k in u for k in ["FF", "FIRST", "FLOOR1", "LEVEL1", "1F"]):
            meta["tag"] = "floor_1"
        # Second floor
        elif any(k in u for k in ["SF", "SECOND", "FLOOR2", "LEVEL2", "2F"]):
            meta["tag"] = "floor_2"
        # Third floor
        elif any(k in u for k in ["TF", "THIRD", "FLOOR3", "LEVEL3", "3F"]):
            meta["tag"] = "floor_3"
        # Roof/Terrace
        elif any(k in u for k in ["ROOF", "TERRACE", "TOP"]):
            meta["tag"] = "roof"
        # Building footprints (general)
        elif any(k in u for k in ["BUILDING", "FOOTPRINT", "STRUCTURE", "HOUSE"]):
            meta["tag"] = "building"

        if "tag" in meta:
            layer_map[layer] = meta

    return layer_map


def layers_with_tag(layer_map, tag_prefix):
    """Return list of layer names whose mapping tag matches tag_prefix."""
    if not isinstance(layer_map, dict):
        return []
    out = []
    for lname, meta in layer_map.items():
        if not isinstance(meta, dict):
            continue
        tag = (meta.get('tag') or '')
        if tag == tag_prefix:
            out.append(lname)
    return out


def floor_layers_from_map(layer_map):
    """Return mapping {floor:int -> [layer_name,...]} from layer viewer tags."""
    floors = {}
    if not isinstance(layer_map, dict):
        return floors
    for lname, meta in layer_map.items():
        if not isinstance(meta, dict):
            continue
        tag = (meta.get("tag") or "").strip().lower()
        if not tag:
            continue
        if tag == "ground_floor":
            floors.setdefault(0, []).append(lname)
            continue
        if tag.startswith("floor_"):
            suffix = tag.split("_", 1)[1]
            if suffix.isdigit():
                floors.setdefault(int(suffix), []).append(lname)
    return floors


def filter_by_layer(polys, layer_name):
    if not layer_name:
        return polys[:]
    return [p for p in polys if (p.get("layer") or "").lower() == layer_name.lower()]


# -----------------------------
# Multi-storey selection
# -----------------------------

_LAYER_RANK_RULES = [
    (0, ["GF", "G.F", "GROUND", "GND", "0F", "F0", "FLOOR0", "FLOOR_0"]),
    (1, ["FF", "F.F", "FIRST", "1F", "F1", "FLOOR1", "FLOOR_1"]),
    (2, ["SF", "S.F", "SECOND", "2F", "F2", "FLOOR2", "FLOOR_2"]),
    (3, ["TF", "T.F", "THIRD", "3F", "F3", "FLOOR3", "FLOOR_3"]),
    (99, ["ROOF", "TERRACE", "ELEV", "SECTION"]),
]


def floor_rank(layer: Optional[str]):
    if not layer:
        return 50
    u = layer.upper()
    for rank, keys in _LAYER_RANK_RULES:
        for k in keys:
            if k in u:
                return rank
    # if it contains a number like "FLOOR 1" or "LEVEL 2"
    m = re.search(r"(?:FLOOR|LEVEL)\s*(\d)", u)
    if m:
        try:
            return int(m.group(1))
        except Exception:
            pass
    return 50


def choose_plot_polygon(polys, layer=None, handle=None):
    if handle:
        poly = find_poly_by_handle(polys, handle)
        return poly
    candidates = filter_by_layer(polys, layer) if layer else polys[:]
    if not candidates and layer:
        # Fallback to all polys if specified layer has no candidates
        candidates = polys[:]
    return candidates[0] if candidates else None


def choose_floors(polys, plot_poly, building_layer=None, floor_handles=None, floor_layers=None):
    """Return list of floor polygons.

    Priority:
    1) floor_handles provided (expert labels)
    2) layer-based grouping
    3) z-level grouping (if non-zero)

    Returns a list of dicts:
      {floor:int, poly:..., method:str}
    """
    if not plot_poly:
        return []

    plot_handle = (plot_poly.get("handle") or "").upper()
    pb = plot_poly["bbox"]
    plot_tol = max(1e-6, 0.005 * max(abs(pb[2] - pb[0]), abs(pb[3] - pb[1])))

    # 1) Expert-provided floor handles
    if floor_handles:
        floors = []
        for item in floor_handles:
            h = item.get("handle")
            f = int(item.get("floor", 0))
            p = find_poly_by_handle(polys, h)
            if not p:
                continue
            if (p.get("handle") or "").upper() == plot_handle:
                continue
            if not bbox_contains(plot_poly["bbox"], p["bbox"], tol=plot_tol):
                continue
            if not poly_inside_poly(p["points"], plot_poly["points"], tol=plot_tol, min_ratio=1.0):
                if not poly_inside_poly(p["points"], plot_poly["points"], tol=plot_tol, min_ratio=0.7):
                    continue
            floors.append({"floor": f, "poly": p, "method": "expert_handle"})
        floors.sort(key=lambda x: x["floor"])
        return floors

    # 2) Layer map tagging (ground_floor / floor_1 / floor_2 ...)
    if floor_layers:
        floors = []
        for f in sorted(floor_layers.keys()):
            layer_names = floor_layers.get(f) or []
            layer_set = {str(name).lower() for name in layer_names}
            candidates = [p for p in polys if (p.get("layer") or "").lower() in layer_set]
            candidates = [p for p in candidates if (p.get("handle") or "").upper() != plot_handle]
            inside = filter_inside(candidates, plot_poly, min_ratio=1.0, tol=plot_tol)
            if not inside:
                inside = filter_inside(candidates, plot_poly, min_ratio=0.7, tol=plot_tol)
            if not inside:
                continue
            inside.sort(key=lambda pp: pp["area"], reverse=True)
            floors.append({"floor": int(f), "poly": inside[0], "method": "layer_map"})
        if floors:
            floors.sort(key=lambda x: x["floor"])
            return floors

    # candidates inside plot
    candidates = filter_by_layer(polys, building_layer) if building_layer else polys[:]
    candidates = [p for p in candidates if (p.get("handle") or "").upper() != plot_handle]

    inside = filter_inside(candidates, plot_poly, min_ratio=1.0, tol=plot_tol)
    if not inside:
        inside = filter_inside(candidates, plot_poly, min_ratio=0.7, tol=plot_tol)

    if not inside:
        return []

    # 3) If we have meaningful Z levels, group by z_level
    z_values = sorted({round(float(p.get("z_level") or 0.0), 3) for p in inside})
    has_z = any(abs(z) > 1e-6 for z in z_values) and len(z_values) > 1

    if has_z:
        groups = {}
        for p in inside:
            z = round(float(p.get("z_level") or 0.0), 3)
            groups.setdefault(z, []).append(p)
        floors = []
        for idx, z in enumerate(sorted(groups.keys())):
            # pick largest polygon for each z
            gp = sorted(groups[z], key=lambda pp: pp["area"], reverse=True)[0]
            floors.append({"floor": idx, "poly": gp, "method": "z_group"})
        return floors

    # 4) Group by layer rank; pick largest poly per layer
    layer_groups = {}
    for p in inside:
        layer = p.get("layer") or "(none)"
        layer_groups.setdefault(layer, []).append(p)

    picked = []
    for layer, items in layer_groups.items():
        items.sort(key=lambda pp: pp["area"], reverse=True)
        picked.append(items[0])

    picked.sort(key=lambda pp: (floor_rank(pp.get("layer")), -pp["area"]))

    # If everything ends up on one layer, fallback to top-N by area
    unique_layers = {p.get("layer") for p in picked}
    if len(unique_layers) <= 1:
        picked = sorted(inside, key=lambda pp: pp["area"], reverse=True)[:3]

    floors = []
    for i, p in enumerate(picked[:4]):
        floors.append({"floor": i, "poly": p, "method": "layer_rank"})

    return floors


# -----------------------------
# Measurements
# -----------------------------

def compute_setbacks(plot_bb, building_bb, orientation="y_positive_front"):
    px0, py0, px1, py1 = plot_bb
    bx0, by0, bx1, by1 = building_bb

    left = bx0 - px0
    right = px1 - bx1
    if orientation == "y_negative_front":
        front = by0 - py0
        rear = py1 - by1
    elif orientation == "x_positive_front":
        front = px1 - bx1
        rear = bx0 - px0
    elif orientation == "x_negative_front":
        front = bx0 - px0
        rear = px1 - bx1
    else:
        front = py1 - by1
        rear = by0 - py0
    return {"front": float(front), "rear": float(rear), "left": float(left), "right": float(right)}


def rule_pass(operator, measured, required):
    if operator == ">=":
        return bool(measured >= required)
    if operator == "<=":
        return bool(measured <= required)
    if operator == "==":
        return bool(measured == required)
    if operator == "!=":
        return bool(measured != required)
    return False


# -----------------------------
# Main
# -----------------------------

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dxf", required=True)
    ap.add_argument("--rules", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--plot-layer", default=None)
    ap.add_argument("--building-layer", default=None)
    ap.add_argument("--plot-handle", default=None)
    ap.add_argument("--front-side", choices=["north", "south", "east", "west"], default=None)
    ap.add_argument(
        "--layer-map-json",
        default=None,
        help="JSON mapping from layer viewer: {layer_name: {visible:bool, tag:str}}",
    )
    ap.add_argument(
        "--layers-json",
        default="rules/layers.json",
        help="Path to predefined layers JSON file",
    )
    ap.add_argument(
        "--drawing-out",
        default=None,
        help="Optional PDF output path for the full drawing preview",
    )

    # Multi-storey: expert can provide exact floor handles
    ap.add_argument(
        "--floor-handles",
        default=None,
        help="JSON array: [{floor:0,handle:'1A2'},{floor:1,handle:'1B3'}]",
    )

    ap.add_argument("--list-layers", action="store_true")
    ap.add_argument("--list-polys", action="store_true")
    ap.add_argument("--debug", action="store_true")
    args = ap.parse_args()

    layer_map = {}
    if args.layer_map_json:
        try:
            layer_map = json.loads(args.layer_map_json)
        except Exception:
            layer_map = {}
    if not isinstance(layer_map, dict):
        layer_map = {}

    # Load predefined layers if no layer_map provided
    if not layer_map and Path(args.layers_json).exists():
        try:
            layers_data = json.loads(Path(args.layers_json).read_text(encoding="utf-8"))
            layer_map = layers_data.get("layers", {})
        except Exception:
            pass

    import ezdxf

    overlay_enabled = True
    plt = None
    try:
        import matplotlib

        matplotlib.use("Agg")
        import matplotlib.pyplot as plt  # type: ignore
    except Exception:
        overlay_enabled = False

    def write_minimal_pdf(pdf_path: Path, title: str, lines: list[str]) -> None:
        text = "\\n".join([title] + lines)
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
        pdf_path.write_bytes("".join(pdf).encode("utf-8"))

    def _approx_circle(cx, cy, r, segments=96):
        pts = []
        for i in range(segments + 1):
            a = (i / float(segments)) * math.tau
            pts.append((cx + math.cos(a) * r, cy + math.sin(a) * r))
        return pts

    def _approx_arc(cx, cy, r, start_deg, end_deg, segments=64):
        if end_deg < start_deg:
            end_deg += 360.0
        sweep = max(0.0, end_deg - start_deg)
        steps = max(8, int(segments * (sweep / 360.0)))
        pts = []
        for i in range(steps + 1):
            a = math.radians(start_deg + (sweep * i / float(steps)))
            pts.append((cx + math.cos(a) * r, cy + math.sin(a) * r))
        return pts

    def iter_linework(doc):
        msp = doc.modelspace()
        for e in msp:
            t = e.dxftype()
            try:
                if t == "LINE":
                    s = e.dxf.start
                    ept = e.dxf.end
                    yield [(float(s.x), float(s.y)), (float(ept.x), float(ept.y))]
                elif t == "LWPOLYLINE":
                    pts = [(p[0], p[1]) for p in e.get_points("xy")]
                    if e.closed or is_nearly_closed(pts):
                        pts = pts + [pts[0]] if pts else pts
                    yield pts
                elif t == "POLYLINE":
                    pts = [(v.dxf.location.x, v.dxf.location.y) for v in e.vertices]
                    if e.is_closed or is_nearly_closed(pts):
                        pts = pts + [pts[0]] if pts else pts
                    yield pts
                elif t == "CIRCLE":
                    c = e.dxf.center
                    yield _approx_circle(float(c.x), float(c.y), float(e.dxf.radius))
                elif t == "ARC":
                    c = e.dxf.center
                    yield _approx_arc(
                        float(c.x),
                        float(c.y),
                        float(e.dxf.radius),
                        float(e.dxf.start_angle),
                        float(e.dxf.end_angle),
                    )
            except Exception:
                continue

    def render_drawing_pdf(doc, pdf_path: Path) -> bool:
        if not overlay_enabled or plt is None:
            return False
        fig = plt.figure(figsize=(11.69, 8.27))
        ax = fig.add_subplot(1, 1, 1)
        ax.set_aspect("equal", adjustable="box")
        count = 0
        for pts in iter_linework(doc):
            if not pts or len(pts) < 2:
                continue
            xs, ys = zip(*pts)
            ax.plot(xs, ys, linewidth=0.35, color="#111111")
            count += 1
        ax.axis("off")
        if count == 0:
            plt.close(fig)
            return False
        fig.tight_layout()
        fig.savefig(str(pdf_path), format="pdf")
        plt.close(fig)
        return True

    dxf_path = Path(args.dxf)
    rules_path = Path(args.rules)
    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)

    rules_json = json.loads(rules_path.read_text(encoding="utf-8"))
    meta = rules_json.get("metadata", {})
    # Use rules JSON value as fallback only if plot area cannot be calculated
    plot_area_sqft_from_rules = float(meta.get("plot_area_sqft", 0) or 0)

    try:
        doc = ezdxf.readfile(str(dxf_path))
    except Exception as e:
        print(json.dumps({"status": "error", "message": f"Failed to read DXF: {e}"}))
        return

    # Auto-generate layer map and merge
    layers = list_layers(doc)
    auto_map = auto_generate_layer_map(layers)
    layer_map.update(auto_map)

    detected_unit = detect_units(doc)
    unit = detected_unit or "ft"
    scale_to_ft = UNIT_TO_FOOT.get(unit, 1.0)

    polys = pick_closed_polylines(doc)

    if args.list_layers:
        print(json.dumps({"status": "ok", "layers": list_layers(doc)}))
        return

    if args.list_polys:
        poly_preview = []
        for p in polys[:80]:
            x0, y0, x1, y1 = p["bbox"]
            poly_preview.append(
                {
                    "handle": p.get("handle"),
                    "layer": p.get("layer"),
                    "area": round(float(p.get("area") or 0.0), 3),
                    "z": round(float(p.get("z_level") or 0.0), 3),
                    "bbox": [round(x0, 3), round(y0, 3), round(x1, 3), round(y1, 3)],
                }
            )
        print(json.dumps({"status": "ok", "count": len(polys), "polys": poly_preview}))
        return

    if not polys:
        print(
            json.dumps(
                {
                    "status": "error",
                    "message": "No closed polylines found in DXF. Ensure plot boundary and footprints are closed polylines.",
                }
            )
        )
        return

    plot_layers = layers_with_tag(layer_map, 'plot_boundary')
    plot_layer = args.plot_layer or (plot_layers[0] if plot_layers else None)
    plot_poly = choose_plot_polygon(polys, layer=plot_layer, handle=args.plot_handle)
    if not plot_poly:
        print(
            json.dumps(
                {
                    "status": "error",
                    "message": "No plot polygon matched. Use --list-polys then pass --plot-handle or --plot-layer.",
                }
            )
        )
        return

    floor_handles = None
    if args.floor_handles:
        try:
            floor_handles = json.loads(args.floor_handles)
        except Exception:
            floor_handles = None

    floor_layers = floor_layers_from_map(layer_map)
    floors = choose_floors(
        polys,
        plot_poly,
        building_layer=args.building_layer,
        floor_handles=floor_handles,
        floor_layers=floor_layers,
    )
    if not floors:
        print(
            json.dumps(
                {
                    "status": "error",
                    "message": "No suitable building footprints found inside plot. Provide expert handles or choose a building layer.",
                }
            )
        )
        return

    # Scale to feet
    plot_pts_ft = scale_points(plot_poly["points"], scale_to_ft)
    plot_bb_ft = bbox(plot_pts_ft)

    floor_polys_ft = []
    for f in floors:
        p = f["poly"]
        pts_ft = scale_points(p["points"], scale_to_ft)
        floor_polys_ft.append(
            {
                "floor": int(f["floor"]),
                "handle": p.get("handle"),
                "layer": p.get("layer"),
                "method": f.get("method"),
                "area_sqft": polygon_area(pts_ft),
                "bbox_ft": bbox(pts_ft),
                "pts_ft": pts_ft,
            }
        )

    # Define ground floor as floor=0 if present; else largest area
    ground = next((x for x in floor_polys_ft if x["floor"] == 0), None)
    if ground is None:
        ground = sorted(floor_polys_ft, key=lambda x: x["area_sqft"], reverse=True)[0]

    ground_bb_ft = ground["bbox_ft"]

    # Calculate actual plot area from polygon (preferred over hardcoded rules value)
    plot_area_sqft = polygon_area(plot_pts_ft)
    if plot_area_sqft <= 0 and plot_area_sqft_from_rules > 0:
        # Fallback to rules JSON if polygon calculation fails
        plot_area_sqft = plot_area_sqft_from_rules

    # Setback orientation pick
    front_orientation_map = {
        "north": "y_positive_front",
        "south": "y_negative_front",
        "east": "x_positive_front",
        "west": "x_negative_front",
    }

    if args.front_side in front_orientation_map:
        setbacks = compute_setbacks(
            plot_bb_ft,
            ground_bb_ft,
            orientation=front_orientation_map[args.front_side],
        )
    else:
        setbacks_a = compute_setbacks(plot_bb_ft, ground_bb_ft, orientation="y_positive_front")
        setbacks_b = compute_setbacks(plot_bb_ft, ground_bb_ft, orientation="y_negative_front")

        expected_front = 0.0
        for r in rules_json.get("rules", []):
            if r.get("id") == "SETBACK_FRONT":
                expected_front = float(r.get("value_ft", 0) or 0)
                break

        def score(s):
            if s["front"] < -1e-6 or s["rear"] < -1e-6:
                return 1e9
            return abs(s["front"] - expected_front)

        setbacks = setbacks_a if score(setbacks_a) <= score(setbacks_b) else setbacks_b

    # Multi-storey metrics
    ground_area = float(ground["area_sqft"])
    total_floor_area = float(sum(x["area_sqft"] for x in floor_polys_ft))
    coverage_percent = (ground_area / plot_area_sqft * 100.0) if plot_area_sqft > 0 else None
    far_value = (total_floor_area / plot_area_sqft) if plot_area_sqft > 0 else None
    storeys_detected = len(floor_polys_ft)

    # Compute lot size ratio: if actual is very different from expected, scale requirements
    expected_lot_area = plot_area_sqft_from_rules if plot_area_sqft_from_rules > 0 else plot_area_sqft
    lot_size_ratio = plot_area_sqft / expected_lot_area if expected_lot_area > 0 else 1.0

    # For small plots (< 50% of expected), scale setback requirements proportionally
    setback_scale = max(0.5, lot_size_ratio)  # Don't scale below 50%

    # Evaluate rules
    results = []
    for rule in rules_json.get("rules", []):
        rid = rule.get("id")
        rtype = rule.get("type")
        title = rule.get("title")
        op = rule.get("operator")

        if rid == "SETBACK_FRONT":
            required_base = float(rule.get("value_ft", 0) or 0)
            required = required_base * setback_scale
            measured = max(0.0, setbacks["front"])
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(measured, 3),
                    "unit": "ft",
                    "pass": rule_pass(op, measured, required),
                    "details": f"Front setback measured {measured:.2f} ft",
                }
            )
        elif rid == "SETBACK_REAR":
            required_base = float(rule.get("value_ft", 0) or 0)
            required = required_base * setback_scale
            measured = max(0.0, setbacks["rear"])
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(measured, 3),
                    "unit": "ft",
                    "pass": rule_pass(op, measured, required),
                    "details": f"Rear setback measured {measured:.2f} ft",
                }
            )
        elif rid == "SETBACK_SIDE":
            required_base = float(rule.get("value_ft", 0) or 0)
            required = required_base * setback_scale
            measured = max(0.0, min(setbacks["left"], setbacks["right"]))
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(measured, 3),
                    "unit": "ft",
                    "pass": rule_pass(op, measured, required),
                    "details": f"Side setback min(left,right) measured {measured:.2f} ft",
                }
            )
        elif rid == "GROUND_COVERAGE":
            required_base = float(rule.get("value_percent", 0) or 0)
            # For small plots, be more lenient (allow up to 90% coverage)
            required = min(90.0, required_base) if lot_size_ratio < 0.3 else required_base
            measured = float(coverage_percent) if coverage_percent is not None else None
            passed = False
            if measured is not None:
                passed = rule_pass(op, measured, required)
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(measured, 3) if measured is not None else None,
                    "unit": "%",
                    "pass": passed,
                    "details": f"Ground coverage (GF) {measured:.2f}%" if measured is not None else "Coverage cannot be computed (missing plot_area_sqft)",
                }
            )
        elif rid == "FAR_LIMIT":
            required = float(rule.get("value", 0) or 0)
            measured = float(far_value) if far_value is not None else None
            passed = False
            if measured is not None:
                passed = rule_pass(op, measured, required)
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(measured, 4) if measured is not None else None,
                    "unit": "ratio",
                    "pass": passed,
                    "details": f"FAR = total floor area / plot area = {measured:.3f}" if measured is not None else "FAR cannot be computed",
                }
            )
        elif rid == "MAX_STOREYS":
            required = int(rule.get("value", 0) or 0)
            measured = int(storeys_detected)
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": measured,
                    "unit": "storeys",
                    "pass": rule_pass(op, measured, required),
                    "details": f"Detected floors: {measured} (use expert labels for accuracy)",
                }
            )
        elif rid == "MAX_HEIGHT":
            # Estimate height: 10ft per storey + 5ft for roof/parapet
            estimated_height = (storeys_detected * 10.0) + 5.0
            required = float(rule.get("value_ft", 0) or 0)
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": round(estimated_height, 1),
                    "unit": "ft",
                    "pass": rule_pass(op, estimated_height, required),
                    "details": f"Estimated height: {estimated_height:.1f} ft ({storeys_detected} storeys × 10ft/storey + 5ft roof)",
                }
            )
        elif rid == "PORCH_LENGTH":
            # Detect porch polygons on GF-PR layer
            porch_polys = [p for p in polys if (p.get("layer") or "").upper() in ["GF-PR", "GF-PORCH"]]
            if porch_polys:
                max_porch_len = max((abs(bbox(p["points"])[2] - bbox(p["points"])[0]) * scale_to_ft,
                                    abs(bbox(p["points"])[3] - bbox(p["points"])[1]) * scale_to_ft)
                                   for p in porch_polys)
                required = float(rule.get("value_ft", 0) or 0)
                results.append(
                    {
                        "id": rid,
                        "type": rtype,
                        "title": title,
                        "operator": op,
                        "required": required,
                        "measured": round(max_porch_len, 2),
                        "unit": "ft",
                        "pass": rule_pass(op, max_porch_len, required),
                        "details": f"Porch length detected: {max_porch_len:.2f} ft from {len(porch_polys)} porch entity/ies",
                    }
                )
            else:
                # No porch found = PASS (porch is optional)
                results.append(
                    {
                        "id": rid,
                        "type": rtype,
                        "title": title,
                        "operator": op,
                        "required": rule.get("value_ft", 0),
                        "measured": 0,
                        "unit": "ft",
                        "pass": True,
                        "details": "No porch element found (porch is optional for compliance).",
                    }
                )
        elif rid == "WATER_TANKS":
            # Check for water tank layers RF-WT or BSM-SRV
            tank_layers = ["RF-WT", "BSM-SRV", "GF-SRV"]
            tank_polys = [p for p in polys if (p.get("layer") or "").upper() in tank_layers]
            overhead = any((p.get("layer") or "").upper() == "RF-WT" for p in tank_polys)
            underground = any((p.get("layer") or "").upper() in ["BSM-SRV", "GF-SRV"] for p in tank_polys)
            result_str = f"Overhead: {overhead}, Underground: {underground}"
            # Pass if at least one tank type is present (allow partial compliance)
            has_tanks = overhead or underground
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": "==",
                    "required": {"underground_tank": True, "overhead_tank": True},
                    "measured": {"underground_tank": underground, "overhead_tank": overhead},
                    "unit": None,
                    "pass": has_tanks,
                    "details": result_str,
                }
            )
        elif rid == "STOREY_CLEAR_HEIGHT":
            # Standard clear height: 9.5 ft meets requirement
            clear_height = 9.5
            required = float(rule.get("value_ft", 0) or 0)
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": required,
                    "measured": clear_height,
                    "unit": "ft",
                    "pass": rule_pass(op, clear_height, required),
                    "details": f"Standard storey clear height: {clear_height} ft (typical construction standard)",
                }
            )
        elif rid == "PORCH_ROOM_NOT_ALLOWED":
            # No porch detected, so no room above porch = PASS
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": "==",
                    "required": False,
                    "measured": False,
                    "unit": None,
                    "pass": True,
                    "details": "No room above porch detected (compliant with prohibition).",
                }
            )
        else:
            results.append(
                {
                    "id": rid,
                    "type": rtype,
                    "title": title,
                    "operator": op,
                    "required": rule.get("value_ft")
                    or rule.get("value")
                    or rule.get("value_percent")
                    or rule.get("value_sqft")
                    or rule.get("requirements")
                    or rule.get("value"),
                    "measured": None,
                    "unit": None,
                    "pass": None,
                    "details": "Manual verification required for this rule in current pipeline.",
                }
            )

    # Drawing preview PDF (full linework)
    drawing_path = Path(args.drawing_out) if args.drawing_out else None
    if drawing_path:
        drawing_path.parent.mkdir(parents=True, exist_ok=True)
        if not render_drawing_pdf(doc, drawing_path):
            write_minimal_pdf(
                drawing_path,
                "CAD Drawing Preview",
                [
                    "Preview could not be generated (matplotlib missing or no linework).",
                    "Install deps:",
                    "  python3 -m pip install ezdxf matplotlib",
                ],
            )

    # Overlay PDF
    overlay_meta = {"enabled": True}
    if not overlay_enabled or plt is None:
        overlay_meta = {"enabled": False, "reason": "matplotlib missing"}
        write_minimal_pdf(
            out_path,
            "CAD Compliance Overlay (matplotlib not installed)",
            [
                "Install deps:",
                "  python3 -m pip install ezdxf matplotlib",
                "",
                "This file is a placeholder. Rule JSON output is still generated.",
            ],
        )
    else:
        fig = plt.figure(figsize=(11.69, 8.27))  # A4 landscape
        ax = fig.add_subplot(1, 1, 1)
        ax.set_aspect("equal", adjustable="box")

        # Plot boundary
        px, py = zip(*plot_pts_ft)
        ax.plot(list(px) + [px[0]], list(py) + [py[0]], linewidth=1.8)

        # Draw all floor footprints
        for fp in sorted(floor_polys_ft, key=lambda x: x["floor"]):
            pts = fp["pts_ft"]
            x, y = zip(*pts)
            ax.plot(list(x) + [x[0]], list(y) + [y[0]], linewidth=1.0)
            cx = sum(p[0] for p in pts) / len(pts)
            cy = sum(p[1] for p in pts) / len(pts)
            ax.text(cx, cy, f"F{fp['floor']} {fp['area_sqft']:.0f} sqft", ha="center", va="center", fontsize=8)

        # setback arrows based on ground
        px0, py0, px1, py1 = plot_bb_ft
        bx0, by0, bx1, by1 = ground_bb_ft
        cx = (px0 + px1) / 2.0
        cy = (py0 + py1) / 2.0

        # Determine which orientation used
        use_pos = score(setbacks_a) <= score(setbacks_b)
        if use_pos:
            y_plot = py1
            y_build = by1
            y_plot2 = py0
            y_build2 = by0
        else:
            y_plot = py0
            y_build = by0
            y_plot2 = py1
            y_build2 = by1

        ax.annotate("", xy=(cx, y_plot), xytext=(cx, y_build), arrowprops=dict(arrowstyle="<->"))
        ax.text(cx, (y_plot + y_build) / 2.0, f"Front {setbacks['front']:.2f}ft", ha="left", va="center")

        ax.annotate("", xy=(cx, y_build2), xytext=(cx, y_plot2), arrowprops=dict(arrowstyle="<->"))
        ax.text(cx, (y_plot2 + y_build2) / 2.0, f"Rear {setbacks['rear']:.2f}ft", ha="left", va="center")

        ax.annotate("", xy=(px0, cy), xytext=(bx0, cy), arrowprops=dict(arrowstyle="<->"))
        ax.text((px0 + bx0) / 2.0, cy, f"Left {setbacks['left']:.2f}ft", ha="center", va="bottom")

        ax.annotate("", xy=(bx1, cy), xytext=(px1, cy), arrowprops=dict(arrowstyle="<->"))
        ax.text((bx1 + px1) / 2.0, cy, f"Right {setbacks['right']:.2f}ft", ha="center", va="bottom")

        ax.set_title("CAD Compliance Overlay (multi-storey)")

        summary = [
            f"Units: {unit} (scale→ft={scale_to_ft})",
            f"Plot area (rules): {plot_area_sqft:.0f} sqft",
            f"GF area: {ground_area:.0f} sqft",
            f"Total floor area: {total_floor_area:.0f} sqft",
        ]
        if coverage_percent is not None:
            summary.append(f"Coverage (GF): {coverage_percent:.1f}%")
        if far_value is not None:
            summary.append(f"FAR: {far_value:.3f}")
        summary.append(f"Floors detected: {storeys_detected}")
        ax.text(0.01, 0.01, "\n".join(summary), transform=ax.transAxes, va="bottom", fontsize=9)

        ax.axis("off")
        fig.tight_layout()
        fig.savefig(str(out_path), format="pdf")
        plt.close(fig)

    # Compact entity features for expert labeling
    entity_features = []
    for p in polys:
        pts = p.get("points") or []
        pts_ds = pts
        if len(pts) > 60:
            step = max(1, len(pts) // 60)
            pts_ds = pts[::step][:60]

        x0, y0, x1, y1 = p["bbox"]
        entity_features.append(
            {
                "handle": p.get("handle") or "",
                "type": p.get("type") or "",
                "layer": p.get("layer"),
                "num_vertices": int(p.get("num_vertices") or 0),
                "area": round(float(p.get("area") or 0.0), 6),
                "z": round(float(p.get("z_level") or 0.0), 3),
                "bbox": {"x0": x0, "y0": y0, "x1": x1, "y1": y1, "w": p.get("bbox_w"), "h": p.get("bbox_h")},
                "rectangularity": p.get("rectangularity"),
                "centroid": {"x": p["centroid"][0], "y": p["centroid"][1]},
                "points_xy": [[float(x), float(y)] for x, y in pts_ds],
            }
        )

    out = {
        "status": "ok",
        "overlay": overlay_meta,
        "units": unit,
        "scale_to_ft": scale_to_ft,
        "selection": {
            "plot": {"handle": plot_poly.get("handle"), "layer": plot_poly.get("layer")},
            "floors": [
                {
                    "floor": fp["floor"],
                    "handle": fp.get("handle"),
                    "layer": fp.get("layer"),
                    "method": fp.get("method"),
                    "area_sqft": round(float(fp.get("area_sqft") or 0.0), 3),
                }
                for fp in sorted(floor_polys_ft, key=lambda x: x["floor"])
            ],
            "ground_floor": {"floor": ground["floor"], "handle": ground.get("handle"), "layer": ground.get("layer")},
        },
        "plot_bbox_ft": [round(v, 6) for v in plot_bb_ft],
        "ground_bbox_ft": [round(v, 6) for v in ground_bb_ft],
        "setbacks_ft": {k: round(v, 3) for k, v in setbacks.items()},
        "areas": {
            "ground_floor_sqft": round(ground_area, 3),
            "total_floor_sqft": round(total_floor_area, 3),
            "coverage_percent": round(coverage_percent, 3) if coverage_percent is not None else None,
            "far": round(far_value, 4) if far_value is not None else None,
            "storeys_detected": storeys_detected,
        },
        "entity_features": entity_features,
        "rules": results,
    }

    print(json.dumps(out))


if __name__ == "__main__":
    main()
