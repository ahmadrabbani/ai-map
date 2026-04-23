#!/usr/bin/env python3
import sys
import re
import json
from pathlib import Path
import argparse
import subprocess
import tempfile
import os
import shutil
from uuid import uuid4

from typing import Optional
from html import escape

import pdfplumber
import cv2
import numpy as np
import ezdxf
from ezdxf import bbox as ezdxf_bbox
from shapely.geometry import Polygon
from shapely.ops import nearest_points

MARLA_SQFT = 272.25
RULES_PATH = Path(__file__).resolve().parents[1] / "LDA AI" / "lda_map_compliance_demo" / "rules.json"
try:
    with open(RULES_PATH, "r", encoding="utf-8") as fh:
        COMPLIANCE_RULES = json.load(fh)
except (OSError, ValueError):
    COMPLIANCE_RULES = None


class AttrDict(dict):
    def __getattr__(self, item):
        return self[item]

    def __setattr__(self, key, value):
        self[key] = value


def make_rule_entry(rule, status, explanation, extra=None):
    data = {
        "rule_id": rule.get("id"),
        "title": rule.get("title"),
        "status": status,
        "explanation": explanation,
    }
    if extra:
        data.update(extra)
    return data


def fill_template(template, values):
    result = template
    for key, value in values.items():
        placeholder = "{{" + key + "}}"
        if placeholder not in result:
            continue
        if value is None:
            replacement = "N/A"
        elif isinstance(value, float):
            replacement = f"{value:.2f}"
        else:
            replacement = str(value)
        result = result.replace(placeholder, replacement)
    return result
FRACTION_MAP = {"½": 0.5, "¼": 0.25, "¾": 0.75}
DIMENSION_RE = re.compile(r"(\d+\s*'\s*(?:-\s*[0-9\s./]+)?\s*\")")
SIMPLE_FEET_RE = re.compile(r"(\d+)\s*'")
BATHROOM_KEYWORDS = ["BATH", "WASH", "TOILET", "W.C", "W.C.", "LAV", "POWDER"]
CAR_PARKING_KEYWORDS = ["CAR", "GARAGE", "PARK", "PORCH"]
IGNORED_PLAN_KEYWORDS = [
    "SCALE",
    "SECTION",
    "ELEVATION",
    "FOUNDATION",
    "DETAIL",
    "RAMP",
    "STAIR",
    "SEWER",
    "PIPE",
    "WINDOW",
    "DOOR",
    "SCHEDULE",
    "LEVEL",
    "ROAD",
    "PLOT",
    "RECHARGE",
    "LOCATION",
    "DRAIN",
    "GROUND LINE",
]

def feet_inches_to_inches(s: str):
    s = s.strip()
    m = re.match(r"(\d+)'-(.+)\"", s)
    if not m:
        return None
    feet = int(m.group(1))
    inch_part = m.group(2)
    inches = 0.0
    for frac_char, frac_val in FRACTION_MAP.items():
        if frac_char in inch_part:
            base = inch_part.replace(frac_char, "").strip() or "0"
            try:
                inches = float(base) + frac_val
            except ValueError:
                inches = frac_val
            break
    else:
        if " " in inch_part:
            parts = inch_part.split()
            base = float(parts[0])
            frac = parts[1]
            num, den = frac.split("/")
            inches = base + float(num) / float(den)
        else:
            inches = float(inch_part)
    return feet * 12 + inches

def try_parse_dimension(text: str):
    normalized = text.replace("''", '"')
    dim_match = DIMENSION_RE.search(normalized)
    if dim_match:
        dim_str = dim_match.group(1)
        inches = feet_inches_to_inches(dim_str)
        if inches is not None:
            return inches, dim_str
    simple_match = SIMPLE_FEET_RE.search(normalized)
    if simple_match:
        feet_value = simple_match.group(1)
        dim_str = f"{feet_value}'-0\""
        inches = feet_inches_to_inches(dim_str)
        return inches, dim_str
    return None, None

def extract_text_objects(pdf_path):
    text_objs = []
    with pdfplumber.open(pdf_path) as pdf:
        for page_idx, page in enumerate(pdf.pages):
            words = page.extract_words(
                extra_attrs=["fontname", "size", "x0", "x1", "top", "bottom"]
            )
            for w in words:
                text_objs.append({
                    "page": page_idx,
                    "text": w["text"],
                    "x0": w["x0"],
                    "x1": w["x1"],
                    "top": w["top"],
                    "bottom": w["bottom"],
                    "mid_x": (w["x0"] + w["x1"]) / 2,
                    "mid_y": (w["top"] + w["bottom"]) / 2,
                })
    return text_objs

def extract_page_texts(pdf_path):
    page_texts = []
    with pdfplumber.open(pdf_path) as pdf:
        for page in pdf.pages:
            page_texts.append(page.extract_text(x_tolerance=2, y_tolerance=2) or "")
    return page_texts

def pdf_to_image(pdf_path, dpi=300):
    with pdfplumber.open(pdf_path) as pdf:
        if not pdf.pages:
            raise ValueError("PDF has no pages to render.")
        first_page = pdf.pages[0]
        pil_image = first_page.to_image(resolution=dpi).original
        img = np.array(pil_image)
        img_bgr = cv2.cvtColor(img, cv2.COLOR_RGB2BGR)
        return img_bgr

def detect_polygons_from_image(img_bgr):
    gray = cv2.cvtColor(img_bgr, cv2.COLOR_BGR2GRAY)
    _, th = cv2.threshold(gray, 240, 255, cv2.THRESH_BINARY_INV)
    edges = cv2.Canny(th, 50, 150)
    cnts, _ = cv2.findContours(edges, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not cnts:
        return None, None
    cnts = sorted(cnts, key=cv2.contourArea, reverse=True)
    boundary_cnt = cnts[0]
    boundary_pts = boundary_cnt.reshape(-1, 2).tolist()
    building_cnt = cnts[1] if len(cnts) > 1 else None
    building_pts = building_cnt.reshape(-1, 2).tolist() if building_cnt is not None else None
    return building_pts, boundary_pts

def infer_scale_from_text(text_objs, img_width, img_height):
    candidate = None
    for t in text_objs:
        if "5'-0\"" in t["text"]:
            candidate = t
            break
    if not candidate:
        for t in text_objs:
            inches, raw = try_parse_dimension(t["text"])
            if inches is not None:
                candidate = t
                break
    if not candidate:
        return None
    inches, raw = try_parse_dimension(candidate["text"])
    if inches is None:
        return None
    approx_text_width_pixels = 80  # heuristic; adjust after testing
    inches_per_pixel = inches / approx_text_width_pixels
    return inches_per_pixel

def measure_min_distance(building_pts, boundary_pts):
    if not building_pts or not boundary_pts:
        return None, None, None
    building_poly = Polygon(building_pts)
    boundary_poly = Polygon(boundary_pts)
    dist = building_poly.distance(boundary_poly)
    p1, p2 = nearest_points(building_poly, boundary_poly)
    return dist, (p1.x, p1.y), (p2.x, p2.y)

def compute_plan_metrics(boundary_pts, building_pts, inches_per_pixel):
    metrics = {}
    if not boundary_pts or not building_pts:
        return metrics

    boundary_poly = Polygon(boundary_pts)
    building_poly = Polygon(building_pts)

    b_minx, b_miny, b_maxx, b_maxy = boundary_poly.bounds
    build_minx, build_miny, build_maxx, build_maxy = building_poly.bounds

    to_feet = inches_per_pixel / 12.0
    metrics["plot_width_ft"] = round((b_maxx - b_minx) * to_feet, 2)
    metrics["plot_depth_ft"] = round((b_maxy - b_miny) * to_feet, 2)
    metrics["building_width_ft"] = round((build_maxx - build_minx) * to_feet, 2)
    metrics["building_depth_ft"] = round((build_maxy - build_miny) * to_feet, 2)

    return metrics

FLOOR_RE = re.compile(r'(GROUND|FIRST|SECOND|THIRD|FOURTH|BASEMENT|ROOF)[^\n]*FLOOR', re.IGNORECASE)
DIM_PAIR_RE = re.compile(
    r"(\d+\s*'\s*(?:-\s*\d+)?\"?)\s*[xX×]\s*(\d+\s*'\s*(?:-\s*\d+)?\"?)"
)
SINGLE_DIM_RE = re.compile(r"\d+\s*'\s*(?:-\s*\d+)?\"")
AREA_RE = re.compile(r'([0-9]+(?:\.[0-9]+)?)\s*(?:SFT|SQ\.?\s*FT)', re.IGNORECASE)
FLOOR_KEYWORDS = [
    ("GROUND FLOOR PLAN", "Ground Floor"),
    ("FIRST FLOOR PLAN", "First Floor"),
    ("SECOND FLOOR PLAN", "Second Floor"),
    ("THIRD FLOOR PLAN", "Third Floor"),
    ("ROOF PLAN", "Roof"),
    ("BASEMENT PLAN", "Basement"),
]
ROOM_KEYWORDS = [
    "BED ROOM",
    "BEDROOM",
    "KITCHEN",
    "STORE",
    "TV LOUNGE",
    "T.V LOUNGE",
    "LOUNGE",
    "PORCH",
    "MUMTY",
    "PASSAGE",
    "FRONT LAWN",
    "BATH",
    "WASHROOM",
    "TOILET",
    "MAIN GATE",
    "ROOM",
    "S/ROOM",
]

def normalize_quotes(text: str) -> str:
    return (
        text.replace("’", "'")
        .replace("‘", "'")
        .replace("“", '"')
        .replace("”", '"')
        .replace("×", "x")
    )

def sanitize_label(label: str):
    if not label:
        return None
    cleaned = label.strip(" :-–—")
    if len(cleaned) < 3:
        return None
    if not any(ch.isalpha() for ch in cleaned):
        return None
    if any(keyword in cleaned.upper() for keyword in IGNORED_PLAN_KEYWORDS):
        return None
    return cleaned

def summarize_floor_rooms(lines):
    rooms = []
    prev_label = None
    seen = set()
    for raw_line in lines:
        clean = normalize_quotes(raw_line.strip())
        if not clean:
            continue
        upper = clean.upper()
        if any(keyword in upper for keyword in IGNORED_PLAN_KEYWORDS):
            continue

        pair_match = DIM_PAIR_RE.search(clean)
        if pair_match:
            dims = pair_match.group(0).replace(" ", "")
            label = sanitize_label(clean[:pair_match.start()]) or sanitize_label(prev_label)
            if not label:
                prev_label = None
                continue
            key = (label.upper(), dims)
            if key in seen:
                prev_label = label
                continue
            seen.add(key)
            rooms.append({"name": label, "dimensions": dims})
            prev_label = label
            continue

        single_match = SINGLE_DIM_RE.search(clean)
        if single_match:
            dims = single_match.group(0).replace(" ", "")
            label = sanitize_label(clean[:single_match.start()]) or sanitize_label(prev_label)
            if not label:
                prev_label = None
                continue
            key = (label.upper(), dims)
            if key in seen:
                prev_label = label
                continue
            seen.add(key)
            rooms.append({"name": label, "dimensions": dims})
            prev_label = label
            continue

        if len(clean) > 3 and upper == clean and any(ch.isalpha() for ch in clean):
            valid_label = sanitize_label(clean)
            if valid_label:
                prev_label = valid_label
                continue

        keyword_label = detect_keyword_room(upper, clean)
        if keyword_label:
            key = (keyword_label.upper(), "")
            if key in seen:
                prev_label = keyword_label
                continue
            seen.add(key)
            rooms.append({"name": keyword_label, "dimensions": None})
            prev_label = keyword_label
    return rooms

def detect_keyword_room(upper_text: str, original: str):
    for keyword in ROOM_KEYWORDS:
        if keyword.upper() in upper_text:
            return keyword.title()
    # fallback: if line has "ROOM" word alone
    if " ROOM" in upper_text or upper_text.startswith("ROOM"):
        label = original.title()
        if "Room" in label:
            return label
    return None

def is_bathroom_label(label: str) -> bool:
    upper = (label or "").upper()
    return any(keyword in upper for keyword in BATHROOM_KEYWORDS)

def is_car_parking_label(label: str) -> bool:
    upper = (label or "").upper()
    return any(keyword in upper for keyword in CAR_PARKING_KEYWORDS)

def detect_floor_name(text: str):
    upper = text.upper()
    for keyword, label in FLOOR_KEYWORDS:
        if keyword in upper:
            return label
    match = FLOOR_RE.search(text)
    if match:
        return match.group(0).title()
    return None

def parse_floor_plans(page_texts):
    sections = []
    current = None
    for page_text in page_texts:
        lines = [line.strip() for line in page_text.splitlines() if line.strip()]
        for line in lines:
            norm = normalize_quotes(line)
            floor_name = detect_floor_name(norm)
            if floor_name:
                if current and current["lines"]:
                    sections.append(current)
                if current and current["name"].lower() == floor_name.lower():
                    # duplicate header, continue collecting in same section
                    continue
                current = {"name": floor_name, "lines": []}
            elif current:
                current["lines"].append(norm)
        if current and current["lines"]:
            sections.append(current)
            current = None

    if current and current["lines"]:
        sections.append(current)

    merged_sections = []
    for section in sections:
        if merged_sections and merged_sections[-1]["name"].lower() == section["name"].lower():
            merged_sections[-1]["lines"].extend(section["lines"])
        else:
            merged_sections.append(section)

    floors = []
    for section in merged_sections:
        floors.append(build_floor_entry(section["name"], section["lines"]))
    return floors

def build_floor_entry(name, lines):
    rooms = summarize_floor_rooms(lines)
    area = None
    for line in lines:
        area_match = AREA_RE.search(line)
        if area_match:
            try:
                area = float(area_match.group(1))
            except ValueError:
                pass
            break

    bathrooms = [room for room in rooms if is_bathroom_label(room.get("name", ""))]
    entry = {
        "name": name,
        "room_count": len(rooms),
        "bathroom_count": len(bathrooms),
        "rooms": rooms,
    }
    if bathrooms:
        entry["bathrooms"] = bathrooms
    if area is not None:
        entry["covered_area_sft"] = area

    car_parking = any(is_car_parking_label(room.get("name", "")) for room in rooms)
    if car_parking:
        entry["has_car_parking"] = True
    return entry

def compute_directional_setback(building_poly, boundary_pts_subset, inches_per_pixel):
    if len(boundary_pts_subset) <= 2:
        return None
    boundary_section = Polygon(boundary_pts_subset)
    dist = building_poly.distance(boundary_section)
    return (dist * inches_per_pixel) / 12.0

def determine_washroom_alignment(floors, first_label="FIRST", second_label="SECOND"):
    def find_floor(keyword):
        keyword = keyword.upper()
        for floor in floors:
            if keyword in floor["name"].upper():
                return floor
        return None

    first_floor = find_floor(first_label)
    second_floor = find_floor(second_label)
    if not first_floor or not second_floor:
        return None

    baths_first = {
        bath.get("dimensions")
        for bath in first_floor.get("bathrooms", [])
        if bath.get("dimensions")
    }
    baths_second = {
        bath.get("dimensions")
        for bath in second_floor.get("bathrooms", [])
        if bath.get("dimensions")
    }
    if not baths_first or not baths_second:
        return None

    return len(baths_first & baths_second) > 0

def summarize_textual_context(text_objs, page_texts):
    raw_texts = []
    dimension_texts = set()
    labels = []
    for t in text_objs:
        text = t["text"].strip()
        if not text:
            continue
        normalized = normalize_quotes(text)
        raw_texts.append(normalized)
        inches, dim_str = try_parse_dimension(normalized)
        if inches is not None and dim_str:
            dimension_texts.add(dim_str.strip())
        if len(normalized) >= 3 and normalized.upper() == normalized and re.search(r"[A-Z]", normalized):
            labels.append(normalized)

    page_texts = page_texts or ["\n".join(raw_texts)]
    floors = parse_floor_plans(page_texts)
    floor_room_counts = {floor["name"]: floor.get("room_count") for floor in floors}
    floor_bath_counts = {floor["name"]: floor.get("bathroom_count") for floor in floors}

    return {
        "raw_texts": raw_texts,
        "dimension_texts": sorted(dimension_texts),
        "labels": sorted(set(labels)),
        "floors_detected": floors,
        "floor_room_counts": floor_room_counts,
        "floor_bathroom_counts": floor_bath_counts,
    }

AREA_PATTERNS = [
    ("plot_area_sft", re.compile(r"TOTAL\s+AREA\s+OF\s+PLOT\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("ground_floor_area_sft", re.compile(r"G[/.\-]?FLOOR\s+(?:COV\.?\s+AREA|COVERED\s+AREA)\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("first_floor_area_sft", re.compile(r"F[/.\-]?FLOOR\s+(?:COV\.?\s+AREA|COVERED\s+AREA)\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("second_floor_area_sft", re.compile(r"SECOND\s+FLOOR\s+(?:COV\.?\s+AREA|COVERED\s+AREA)\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("mumty_area_sft", re.compile(r"MUMTY\s+(?:PLAN|COV\.?\s+AREA)\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("total_covered_area_sft", re.compile(r"TOTAL\s+PROPOSED\s+COV\.?\s+AREA\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
    ("open_area_sft", re.compile(r"OPEN\s+AREA\s+OF\s+THE?\s+PLOT\s+([0-9]+(?:\.[0-9]+)?)", re.IGNORECASE)),
]

def extract_area_details(raw_texts):
    joined = "\n".join(raw_texts)
    data = {}
    for key, pattern in AREA_PATTERNS:
        match = pattern.search(joined)
        if match:
            try:
                data[key] = float(match.group(1))
            except ValueError:
                continue
    return data

def extract_plot_dimensions(raw_texts):
    dims = {}
    for text in raw_texts:
        normalized = normalize_quotes(text.upper())
        if "PLOT" not in normalized:
            continue
        match = DIM_PAIR_RE.search(text)
        if match:
            width_in, _ = try_parse_dimension(match.group(1))
            depth_in, _ = try_parse_dimension(match.group(2))
            if width_in:
                dims["plot_width_ft"] = round(width_in / 12.0, 2)
            if depth_in:
                dims["plot_depth_ft"] = round(depth_in / 12.0, 2)
            break
    return dims

def estimate_setbacks_from_text(text_objs):
    front = rear = left = right = None
    passages = []
    for t in text_objs:
        normalized = normalize_quotes(t["text"])
        upper = normalized.upper()
        inches, _ = try_parse_dimension(normalized)
        if inches is None:
            continue
        feet_val = inches / 12.0
        if "FRONT" in upper or "LAWN" in upper:
            front = feet_val
        elif any(word in upper for word in ["REAR", "BACK"]):
            rear = feet_val
        elif "LEFT" in upper:
            left = feet_val
        elif "RIGHT" in upper:
            right = feet_val
        elif any(word in upper for word in ["PASSAGE", "SIDE SPACE", "SIDE"]):
            passages.append(feet_val)

    for val in passages:
        if left is None:
            left = val
        elif right is None:
            right = val
        else:
            break
    return {"front": front, "rear": rear, "left": left, "right": right}

def ensure_overlay_dir(storage_root: Optional[Path]) -> Optional[Path]:
    if storage_root is None:
        return None
    overlay_dir = storage_root / "public" / "overlays"
    overlay_dir.mkdir(parents=True, exist_ok=True)
    return overlay_dir


def save_overlay_image(img_bgr, storage_root: Optional[Path], prefix: str = "plan_overlay") -> Optional[str]:
    overlay_dir = ensure_overlay_dir(storage_root)
    if overlay_dir is None:
        return None
    filename = f"{prefix}_{uuid4().hex}.png"
    output_path = overlay_dir / filename
    cv2.imwrite(str(output_path), img_bgr)
    return f"overlays/{filename}"


def draw_pdf_overlay(img_bgr, building_pts, boundary_pts, p_build, p_bound, storage_root: Optional[Path]) -> Optional[str]:
    if img_bgr is None:
        return None

    overlay = img_bgr.copy()
    drawn = False

    if boundary_pts:
        boundary = np.array(boundary_pts, dtype=np.int32)
        cv2.polylines(overlay, [boundary], True, (34, 197, 94), 3)
        drawn = True
    if building_pts:
        building = np.array(building_pts, dtype=np.int32)
        cv2.polylines(overlay, [building], True, (14, 165, 233), 3)
        drawn = True

    if p_build and p_bound:
        pt1 = tuple(int(round(v)) for v in p_build)
        pt2 = tuple(int(round(v)) for v in p_bound)
        cv2.line(overlay, pt1, pt2, (239, 68, 68), 3)
        cv2.circle(overlay, pt1, 6, (239, 68, 68), -1)
        cv2.circle(overlay, pt2, 6, (239, 68, 68), -1)
        drawn = True

    if not drawn:
        return None

    legend_items = [
        ("Boundary", (34, 197, 94)),
        ("Building", (14, 165, 233)),
        ("Min distance", (239, 68, 68)),
    ]

    base_x = 24
    base_y = 36
    for idx, (label, color) in enumerate(legend_items):
        y = base_y + idx * 24
        cv2.rectangle(overlay, (base_x, y - 12), (base_x + 40, y), color, -1)
        cv2.putText(
            overlay,
            label,
            (base_x + 52, y),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.5,
            (15, 23, 42),
            2,
            cv2.LINE_AA,
        )

    return save_overlay_image(overlay, storage_root)


def enhance_svg_for_display(svg_file: Path, bounds=None, overlay=None) -> None:
    try:
        svg_text = svg_file.read_text()
    except OSError:
        return

    overlay_style = '<style id="plan-overlay-style">path,polyline,line{vector-effect:non-scaling-stroke;}</style>'
    if "<defs>" in svg_text:
        svg_text = svg_text.replace("<defs>", f"<defs>{overlay_style}", 1)
    else:
        svg_text = re.sub(r'(<svg[^>]*>)', r'\1' + overlay_style, svg_text, count=1)

    svg_text = re.sub(
        r'<rect[^>]+fill="#212830"[^>]*/>',
        '<rect fill="#f8fafc" x="0" y="0" width="100%" height="100%"/>',
        svg_text,
        count=1,
    )

    if bounds is not None and getattr(bounds, "has_data", False):
        min_x, min_y, _ = bounds.extmin
        max_x, max_y, _ = bounds.extmax
        width = max(max_x - min_x, 1.0)
        height = max(max_y - min_y, 1.0)
        pad_x = width * 0.05
        pad_y = height * 0.05
        viewbox = f"{min_x - pad_x} {min_y - pad_y} {width + 2 * pad_x} {height + 2 * pad_y}"
        if 'viewBox="' in svg_text:
            svg_text = re.sub(r'viewBox="[^"]+"', f'viewBox="{viewbox}"', svg_text, count=1)
        else:
            svg_text = re.sub(r'(<svg[^>]*?)>', r'\1 viewBox="' + viewbox + '">', svg_text, count=1)

    if 'preserveAspectRatio' not in svg_text:
        svg_text = re.sub(r'(<svg[^>]*?)>', r'\1 preserveAspectRatio="xMidYMid meet">', svg_text, count=1)

    overlay_markup = ""
    if overlay:
        overlay_markup += '<g id="plan-overlay" fill="none" stroke-linejoin="round" stroke-linecap="round">'
        boundary_pts = overlay.get("boundary_pts") or []
        building_pts = overlay.get("building_pts") or []
        highlight = overlay.get("highlight")
        highlight_label = overlay.get("highlight_label")
        labels = overlay.get("labels") or []

        def polyline(points, color, width):
            if not points:
                return ""
            pts_str = " ".join(f"{float(x):.2f},{float(y):.2f}" for x, y in points)
            return f'<polyline points="{pts_str}" stroke="{color}" stroke-width="{width}" vector-effect="non-scaling-stroke"/>'

        overlay_markup += polyline(boundary_pts, "#22c55e", 6)
        overlay_markup += polyline(building_pts, "#0ea5e9", 6)

        def draw_nodes(points, color):
            markups = []
            for x, y in points or []:
                markups.append(
                    f'<circle cx="{float(x):.2f}" cy="{float(y):.2f}" r="18" fill="{color}" stroke="#0f172a" stroke-width="4" vector-effect="non-scaling-stroke"/>'
                )
            return "".join(markups)

        overlay_markup += draw_nodes(boundary_pts, "#bbf7d0")
        overlay_markup += draw_nodes(building_pts, "#bae6fd")

        if highlight and highlight[0] and highlight[1]:
            hx1, hy1 = highlight[0]
            hx2, hy2 = highlight[1]
            overlay_markup += (
                f'<line x1="{hx1:.2f}" y1="{hy1:.2f}" x2="{hx2:.2f}" y2="{hy2:.2f}" '
                'stroke="#ef4444" stroke-width="8" vector-effect="non-scaling-stroke"/>'
                f'<circle cx="{hx1:.2f}" cy="{hy1:.2f}" r="20" fill="#ef4444" stroke="#0f172a" stroke-width="4" vector-effect="non-scaling-stroke"/>'
                f'<circle cx="{hx2:.2f}" cy="{hy2:.2f}" r="20" fill="#ef4444" stroke="#0f172a" stroke-width="4" vector-effect="non-scaling-stroke"/>'
            )
            if highlight_label:
                midx = (hx1 + hx2) / 2
                midy = (hy1 + hy2) / 2
                overlay_markup += (
                    f'<text x="{midx:.2f}" y="{midy:.2f}" fill="#0f172a" font-size="120" '
                    'font-family="Arial" text-anchor="middle" alignment-baseline="middle">'
                    f'{escape(highlight_label)}</text>'
                )

        limited_labels = labels[:80]
        for lbl in limited_labels:
            text = lbl.get("text", "").strip()
            if not text:
                continue
            x = float(lbl.get("x", 0))
            y = float(lbl.get("y", 0))
            overlay_markup += f'<circle cx="{x:.2f}" cy="{y:.2f}" r="10" fill="#1d4ed8" vector-effect="non-scaling-stroke"/>'
            overlay_markup += (
                f'<text x="{x + 60:.2f}" y="{y - 30:.2f}" fill="#111827" font-size="100" '
                'font-family="Arial">{}</text>'.format(escape(text[:40]))
            )

        measurements = overlay.get("measurements") or []
        for measurement in measurements:
            start = measurement.get("start")
            end = measurement.get("end")
            label = measurement.get("label")
            if not start or not end:
                continue
            sx, sy = float(start[0]), float(start[1])
            ex, ey = float(end[0]), float(end[1])
            overlay_markup += (
                f'<line x1="{sx:.2f}" y1="{sy:.2f}" x2="{ex:.2f}" y2="{ey:.2f}" '
                'stroke="#9333ea" stroke-width="10" stroke-dasharray="40 25" vector-effect="non-scaling-stroke"/>'
            )
            overlay_markup += f'<circle cx="{sx:.2f}" cy="{sy:.2f}" r="18" fill="#ede9fe" stroke="#6d28d9" stroke-width="6"/>'
            overlay_markup += f'<circle cx="{ex:.2f}" cy="{ey:.2f}" r="18" fill="#ede9fe" stroke="#6d28d9" stroke-width="6"/>'
            if label:
                dx = abs(ex - sx)
                dy = abs(ey - sy)
                if dx >= dy:
                    lx = (sx + ex) / 2
                    ly = min(sy, ey) - 120
                    anchor = "middle"
                else:
                    lx = max(sx, ex) + 120
                    ly = (sy + ey) / 2
                    anchor = "start"
                overlay_markup += (
                    f'<text x="{lx:.2f}" y="{ly:.2f}" fill="#4c1d95" font-size="140" '
                    f'font-family="Arial" text-anchor="{anchor}" alignment-baseline="middle">'
                    f'{escape(label)}</text>'
                )

        overlay_markup += "</g>"

    if overlay_markup:
        svg_text = svg_text.replace("</svg>", overlay_markup + "</svg>")

    try:
        svg_file.write_text(svg_text)
    except OSError:
        pass


def render_dwg_preview(doc, storage_root: Optional[Path], overlay=None) -> Optional[str]:
    overlay_dir = ensure_overlay_dir(storage_root)
    if overlay_dir is None:
        return None

    try:
        from ezdxf.addons.drawing import RenderContext, Frontend
        from ezdxf.addons.drawing.file_output import SvgFileOutput
    except ImportError:
        return None

    msp = doc.modelspace()
    svg_output = SvgFileOutput(dpi=96)
    frontend = Frontend(RenderContext(doc), svg_output.backend())
    frontend.draw_layout(msp, finalize=True)

    filename = f"dwg_preview_{uuid4().hex}.svg"
    output_path = overlay_dir / filename
    svg_output.save(output_path)
    try:
        model_bounds = ezdxf_bbox.extents(msp)
    except Exception:
        model_bounds = None
    enhance_svg_for_display(output_path, model_bounds, overlay=overlay)
    return f"overlays/{filename}"


def safe_eval(expr: str, values: dict) -> bool:
    try:
        return bool(eval(expr, {"__builtins__": {}}, values))
    except Exception:
        return False


def compute_plot_size_marla(attributes):
    area_sft = attributes.get("plot_area_sft")
    if not area_sft:
        width = attributes.get("plot_width_ft")
        depth = attributes.get("plot_depth_ft")
        if width and depth:
            area_sft = width * depth
    if not area_sft:
        return None
    return area_sft / MARLA_SQFT


def evaluate_setback_rule(rule, attributes, result):
    measured = {
        "front": result.get("front_setback_ft"),
        "rear": result.get("rear_setback_ft"),
        "left": result.get("left_setback_ft"),
        "right": result.get("right_setback_ft"),
    }
    plot_size = compute_plot_size_marla(attributes)
    if plot_size is None:
        return make_rule_entry(rule, "unknown", "Plot area not detected; cannot compute plot size.")

    missing = [name for name, value in measured.items() if value is None]
    if missing:
        return make_rule_entry(
            rule,
            "unknown",
            f"Missing setback measurements: {', '.join(missing)}.",
            {"plot_size_marla": round(plot_size, 2), "measured_setbacks_ft": measured},
        )

    selected_logic = None
    for block in rule.get("logic", []):
        condition = block.get("when")
        if condition:
            if not safe_eval(condition, {"plot_size_marla": plot_size}):
                continue
        selected_logic = block
        break

    if not selected_logic:
        return make_rule_entry(
            rule,
            "unknown",
            "No rule band matched for the detected plot size.",
            {"plot_size_marla": round(plot_size, 2), "measured_setbacks_ft": measured},
        )

    required = selected_logic.get("require", {})
    env = {
        "plot_size_marla": plot_size,
        "measured_setbacks_ft": AttrDict(measured),
        **required,
    }
    passed = safe_eval(rule.get("pass_condition", ""), env)

    template = rule.get("explain", {}).get("template", "")
    replacements = {
        "front_min_ft": required.get("front_min_ft"),
        "rear_min_ft": required.get("rear_min_ft"),
        "side_one_min_ft": required.get("side_one_min_ft"),
        "side_other_min_ft": required.get("side_other_min_ft"),
        "measured_setbacks_ft.front": measured["front"],
        "measured_setbacks_ft.rear": measured["rear"],
        "measured_setbacks_ft.left": measured["left"],
        "measured_setbacks_ft.right": measured["right"],
    }
    explanation = fill_template(template, replacements)

    return make_rule_entry(
        rule,
        "pass" if passed else "fail",
        explanation,
        {
            "note": selected_logic.get("note"),
            "requirements": required,
            "plot_size_marla": round(plot_size, 2),
            "measured_setbacks_ft": measured,
        },
    )


def evaluate_coverage_rule(rule, attributes):
    plot_size = compute_plot_size_marla(attributes)
    if plot_size is None:
        return make_rule_entry(rule, "unknown", "Plot area not detected; cannot compute plot size for coverage rule.")

    plot_area = attributes.get("plot_area_sft")
    ground_area = attributes.get("ground_floor_area_sft")
    total_area = attributes.get("total_covered_area_sft")
    floors = attributes.get("total_floors") or len(attributes.get("floors_detected", [])) or None
    height = attributes.get("building_height_ft")
    if height is None and floors:
        height = floors * 10  # approximate 10 ft per floor when not provided

    measured = {
        "coverage_pct": (ground_area / plot_area * 100) if plot_area and ground_area else None,
        "far": (total_area / plot_area) if plot_area and total_area else None,
        "floors": floors,
        "height_ft": height,
    }
    missing = [k for k, v in measured.items() if v is None]
    if missing:
        return make_rule_entry(
            rule,
            "unknown",
            f"Missing data for coverage rule: {', '.join(missing)}.",
            {"plot_size_marla": round(plot_size, 2), "measured": measured},
        )

    selected_logic = None
    for block in rule.get("logic", []):
        condition = block.get("when")
        if condition and not safe_eval(condition, {"plot_size_marla": plot_size}):
            continue
        selected_logic = block
        break

    if not selected_logic:
        return make_rule_entry(
            rule,
            "unknown",
            "No rule band matched for the detected plot size.",
            {"plot_size_marla": round(plot_size, 2), "measured": measured},
        )

    required = selected_logic.get("require", {})
    env = {
        "plot_size_marla": plot_size,
        "measured": AttrDict(measured),
        **required,
    }
    passed = safe_eval(rule.get("pass_condition", ""), env)

    template = rule.get("explain", {}).get("template", "")
    replacements = {
        "max_cov": required.get("max_cov"),
        "max_far": required.get("max_far"),
        "max_floors": required.get("max_floors"),
        "max_height_ft": required.get("max_height_ft"),
        "measured.coverage_pct": measured["coverage_pct"],
        "measured.far": measured["far"],
        "measured.floors": measured["floors"],
        "measured.height_ft": measured["height_ft"],
    }
    explanation = fill_template(template, replacements)

    return make_rule_entry(
        rule,
        "pass" if passed else "fail",
        explanation,
        {
            "note": selected_logic.get("note"),
            "requirements": required,
            "plot_size_marla": round(plot_size, 2),
            "measured": measured,
        },
    )


def evaluate_compliance_rules(attributes, result):
    if not COMPLIANCE_RULES:
        return []
    evaluations = []
    for rule in COMPLIANCE_RULES.get("rules", []):
        rule_id = rule.get("id")
        if rule_id == "AV1.RES.SETBACKS.LE1K":
            eval_result = evaluate_setback_rule(rule, attributes, result)
        elif rule_id == "AV1.RES.COV_FAR_HEIGHT.LE1K":
            eval_result = evaluate_coverage_rule(rule, attributes)
        else:
            continue

        if eval_result:
            evaluations.append(eval_result)
    return evaluations


def convert_dwg_to_dxf(dwg_path: str):
    converter = shutil.which("dwg2dxf") or "/usr/local/bin/dwg2dxf"
    if not converter or not Path(converter).exists():
        raise RuntimeError("dwg2dxf not found. Please install LibreDWG's dwg2dxf and ensure it is accessible.")
    tmp = tempfile.NamedTemporaryFile(prefix="plan_", suffix=".dxf", delete=False)
    tmp_path = tmp.name
    tmp.close()
    cmd = [converter, "-y", "-o", tmp_path, dwg_path]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(f"dwg2dxf failed: {proc.stderr or proc.stdout}")
    return tmp_path

def extract_text_objects_from_dxf(doc):
    text_objs = []
    msp = doc.modelspace()
    for entity in msp.query("TEXT MTEXT"):
        if entity.dxftype() == "MTEXT":
            content = entity.plain_text()
        else:
            content = entity.dxf.text
        insert = getattr(entity.dxf, "insert", None)
        x = insert.x if insert else 0
        y = insert.y if insert else 0
        for line in content.splitlines():
            clean = line.strip()
            if not clean:
                continue
            text_objs.append({
                "page": 0,
                "text": clean,
                "x0": x,
                "x1": x,
                "top": y,
                "bottom": y,
                "mid_x": x,
                "mid_y": y,
            })
    return text_objs


def detect_polygons_from_dxf(doc):
    msp = doc.modelspace()
    candidates = []

    def add_points(points):
        if len(points) < 3:
            return
        try:
            pts = [(float(x), float(y)) for x, y in points]
            poly = Polygon(pts)
        except Exception:
            return
        if not poly.is_valid or poly.area <= 0:
            return
        candidates.append((poly.area, pts))

    for entity in msp.query("LWPOLYLINE"):
        pts = list(entity.get_points("xy"))
        if not pts:
            continue
        if not entity.closed:
            pts.append(pts[0])
        add_points(pts)

    # fallback for POLYLINE entities
    for entity in msp.query("POLYLINE"):
        if not getattr(entity, "is_closed", False):
            continue
        pts = []
        for v in entity.vertices():
            pts.append((float(v.dxf.location.x), float(v.dxf.location.y)))
        if pts:
            pts.append(pts[0])
        add_points(pts)

    if not candidates:
        return None, None

    candidates.sort(key=lambda item: item[0], reverse=True)
    boundary_pts = candidates[0][1]
    building_pts = candidates[1][1] if len(candidates) > 1 else None
    return building_pts, boundary_pts


def estimate_units_to_feet(boundary_bounds, attributes):
    minx, miny, maxx, maxy = boundary_bounds
    width_units = maxx - minx
    depth_units = maxy - miny
    candidates = []

    plot_width_ft = attributes.get("plot_width_ft")
    plot_depth_ft = attributes.get("plot_depth_ft")

    if plot_width_ft and width_units > 0:
        candidates.append(plot_width_ft / width_units)
    if plot_depth_ft and depth_units > 0:
        candidates.append(plot_depth_ft / depth_units)

    if not candidates:
        return None
    return sum(candidates) / len(candidates)


def draw_dwg_graph(boundary_pts, building_pts, storage_root, highlight=None, highlight_label=None):
    if not boundary_pts:
        return None
    overlay_dir = ensure_overlay_dir(storage_root)
    if overlay_dir is None:
        return None

    canvas_w, canvas_h = 1200, 800
    background = np.full((canvas_h, canvas_w, 3), 250, dtype=np.uint8)

    for x in range(0, canvas_w, 80):
        cv2.line(background, (x, 0), (x, canvas_h), (228, 232, 240), 1)
    for y in range(0, canvas_h, 80):
        cv2.line(background, (0, y), (canvas_w, y), (228, 232, 240), 1)

    all_pts = boundary_pts[:]
    if building_pts:
        all_pts.extend(building_pts)

    xs = [p[0] for p in all_pts]
    ys = [p[1] for p in all_pts]
    minx, maxx = min(xs), max(xs)
    miny, maxy = min(ys), max(ys)
    width = max(maxx - minx, 1.0)
    height = max(maxy - miny, 1.0)
    margin = 80
    scale = min((canvas_w - 2 * margin) / width, (canvas_h - 2 * margin) / height)

    def to_px(pt):
        x = margin + (pt[0] - minx) * scale
        y = canvas_h - (margin + (pt[1] - miny) * scale)
        return int(round(x)), int(round(y))

    boundary_np = np.array([to_px(p) for p in boundary_pts], dtype=np.int32)
    cv2.polylines(background, [boundary_np], True, (34, 197, 94), 3)

    if building_pts:
        building_np = np.array([to_px(p) for p in building_pts], dtype=np.int32)
        cv2.polylines(background, [building_np], True, (14, 165, 233), 3)

    if highlight and all(highlight):
        pt1 = to_px(highlight[0])
        pt2 = to_px(highlight[1])
        cv2.line(background, pt1, pt2, (239, 68, 68), 3)
        cv2.circle(background, pt1, 6, (239, 68, 68), -1)
        cv2.circle(background, pt2, 6, (239, 68, 68), -1)
        if highlight_label:
            mid_point = ((pt1[0] + pt2[0]) // 2, (pt1[1] + pt2[1]) // 2)
            cv2.putText(background, highlight_label, mid_point, cv2.FONT_HERSHEY_SIMPLEX, 0.6, (15, 23, 42), 2, cv2.LINE_AA)

    legend_items = [
        ("Boundary", (34, 197, 94)),
        ("Building", (14, 165, 233)),
        ("Min distance", (239, 68, 68)),
    ]
    base_x = 40
    base_y = 40
    for idx, (label, color) in enumerate(legend_items):
        y = base_y + idx * 26
        cv2.rectangle(background, (base_x, y - 12), (base_x + 40, y), color, -1)
        cv2.putText(background, label, (base_x + 50, y), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (50, 50, 50), 1, cv2.LINE_AA)

    return save_overlay_image(background, storage_root, prefix="dwg_overlay")

def analyze_dwg(dwg_path: Path, required_setback: float, storage_root: Optional[Path]):
    dxf_path = None
    try:
        dxf_path = convert_dwg_to_dxf(str(dwg_path))
        doc = ezdxf.readfile(dxf_path)
        text_objs = extract_text_objects_from_dxf(doc)
        if not text_objs:
            raise RuntimeError("DWG text extraction failed.")
        page_texts = ["\n".join(obj["text"] for obj in text_objs)]
        summary = summarize_textual_context(text_objs, page_texts)
        area_details = extract_area_details(summary["raw_texts"])
        plot_dims = extract_plot_dimensions(summary["raw_texts"])
        setback_guess = estimate_setbacks_from_text(text_objs)

        left_ft = setback_guess["left"]
        right_ft = setback_guess["right"]
        front_ft = setback_guess["front"]
        rear_ft = setback_guess["rear"]

        global_min = None
        numeric_setbacks = [val for val in [left_ft, right_ft, front_ft, rear_ft] if val is not None]
        if numeric_setbacks:
            global_min = min(numeric_setbacks)

        building_geom_pts, boundary_geom_pts = detect_polygons_from_dxf(doc)
        geometry_attrs = {}
        geometry_visual = None
        geo_highlight = None
        geo_label = None
        units_to_ft = None
        geometry_min_ft = None
        measurement_annotations = []

        if boundary_geom_pts:
            try:
                boundary_poly_geom = Polygon(boundary_geom_pts)
                geometry_attrs["geometry_plot_width_units"] = boundary_poly_geom.bounds[2] - boundary_poly_geom.bounds[0]
                geometry_attrs["geometry_plot_depth_units"] = boundary_poly_geom.bounds[3] - boundary_poly_geom.bounds[1]
                units_to_ft = estimate_units_to_feet(boundary_poly_geom.bounds, {**summary, **area_details, **plot_dims})
                if units_to_ft:
                    geometry_attrs["dwg_units_to_feet"] = units_to_ft
            except Exception:
                boundary_poly_geom = None
        else:
            boundary_poly_geom = None

        if building_geom_pts and boundary_geom_pts:
            try:
                building_poly_geom = Polygon(building_geom_pts)
            except Exception:
                building_poly_geom = None
        else:
            building_poly_geom = None

        if building_poly_geom is not None and boundary_poly_geom is not None:
            geom_min_dist_units, pb_pt, pg_pt = measure_min_distance(building_geom_pts, boundary_geom_pts)
            if geom_min_dist_units is not None:
                geometry_min_ft = geom_min_dist_units * units_to_ft if units_to_ft else geom_min_dist_units

                def point_to_tuple(pt):
                    if pt is None:
                        return None
                    if hasattr(pt, "x") and hasattr(pt, "y"):
                        return (float(pt.x), float(pt.y))
                    if isinstance(pt, (list, tuple)) and len(pt) >= 2:
                        return (float(pt[0]), float(pt[1]))
                    return None

                geo_highlight = (
                    point_to_tuple(pb_pt),
                    point_to_tuple(pg_pt),
                )
                if units_to_ft:
                    geo_label = f"{round(geometry_min_ft, 2)} ft"
                else:
                    geo_label = f"{round(geom_min_dist_units, 2)} units"

            boundary_bounds = boundary_poly_geom.bounds
            building_bounds = building_poly_geom.bounds

            def convert_setback(val):
                if val is None:
                    return None
                if units_to_ft:
                    return val * units_to_ft
                return None

            geo_left = convert_setback(building_bounds[0] - boundary_bounds[0])
            geo_right = convert_setback(boundary_bounds[2] - building_bounds[2])
            geo_front = convert_setback(building_bounds[1] - boundary_bounds[1])
            geo_rear = convert_setback(boundary_bounds[3] - building_bounds[3])

            if geo_left is not None and geo_left >= 0:
                left_ft = geo_left
            if geo_right is not None and geo_right >= 0:
                right_ft = geo_right
            if geo_front is not None and geo_front >= 0:
                front_ft = geo_front
            if geo_rear is not None and geo_rear >= 0:
                rear_ft = geo_rear
            if geometry_min_ft is not None:
                global_min = geometry_min_ft

            if units_to_ft:
                def add_measurement(name, start, end, value):
                    measurement_annotations.append({
                        "start": start,
                        "end": end,
                        "label": name,
                        "distance_label": f"{name} {value:.2f} ft",
                        "value_ft": value,
                    })

                mid_y = (building_bounds[1] + building_bounds[3]) / 2
                mid_x = (building_bounds[0] + building_bounds[2]) / 2

                if geo_left is not None:
                    add_measurement(
                        "Left",
                        (boundary_bounds[0], mid_y),
                        (building_bounds[0], mid_y),
                        geo_left,
                    )
                if geo_right is not None:
                    add_measurement(
                        "Right",
                        (boundary_bounds[2], mid_y),
                        (building_bounds[2], mid_y),
                        geo_right,
                    )
                if geo_front is not None:
                    add_measurement(
                        "Front",
                        (mid_x, boundary_bounds[1]),
                        (mid_x, building_bounds[1]),
                        geo_front,
                    )
                if geo_rear is not None:
                    add_measurement(
                        "Rear",
                        (mid_x, boundary_bounds[3]),
                        (mid_x, building_bounds[3]),
                        geo_rear,
                    )
                if measurement_annotations:
                    geometry_attrs["measurement_annotations"] = measurement_annotations

        if boundary_geom_pts:
            geometry_visual = draw_dwg_graph(boundary_geom_pts, building_geom_pts, storage_root, highlight=geo_highlight, highlight_label=geo_label)

        attributes = {
            **summary,
            **area_details,
            **plot_dims,
            **geometry_attrs,
            "front_setback_required_ft": required_setback,
            "front_setback_measured_ft": round(front_ft, 2) if front_ft is not None else None,
            "rear_setback_measured_ft": round(rear_ft, 2) if rear_ft is not None else None,
            "left_setback_measured_ft": round(left_ft, 2) if left_ft is not None else None,
            "right_setback_measured_ft": round(right_ft, 2) if right_ft is not None else None,
            "total_floors": len(summary["floors_detected"]),
            "ground_floor_has_car_parking": any(
                floor.get("has_car_parking") for floor in summary["floors_detected"] if "GROUND" in floor["name"].upper()
            ),
            "washrooms_first_second_share_dims": determine_washroom_alignment(summary["floors_detected"]),
            "violations": [],
            "has_left_side_space": bool(left_ft and left_ft > 0.1),
            "has_right_side_space": bool(right_ft and right_ft > 0.1),
            "has_front_side_space": bool(front_ft and front_ft > 0.1),
            "has_rear_side_space": bool(rear_ft and rear_ft > 0.1),
        }

        label_points = []
        for obj in text_objs:
            text_value = obj.get("text", "").strip()
            if not text_value or len(text_value) > 40:
                continue
            if not any(ch.isalpha() for ch in text_value):
                continue
            try:
                label_points.append({
                    "text": text_value,
                    "x": float(obj.get("mid_x", obj.get("x0", 0))),
                    "y": float(obj.get("mid_y", obj.get("top", 0))),
                })
            except (TypeError, ValueError):
                continue
            if len(label_points) >= 100:
                break

        overlay_payload = {
            "boundary_pts": boundary_geom_pts or [],
            "building_pts": building_geom_pts or [],
            "highlight": geo_highlight,
            "highlight_label": geo_label,
            "labels": label_points,
            "measurements": measurement_annotations,
        }

        visualizations = []
        svg_path = render_dwg_preview(doc, storage_root, overlay=overlay_payload)
        if svg_path:
            visualizations.append({
                "type": "svg",
                "format": "svg",
                "public_path": svg_path,
                "label": "DWG preview",
            })
        if geometry_visual:
            visualizations.append({
                "type": "image",
                "format": "png",
                "public_path": geometry_visual,
                "label": "Setback graph (DXF)",
            })

        result = {
            "status": "ok",
            "required_setback_ft": required_setback,
            "global_min_setback_ft": round(global_min, 2) if global_min is not None else None,
            "left_setback_ft": round(left_ft, 2) if left_ft is not None else None,
            "right_setback_ft": round(right_ft, 2) if right_ft is not None else None,
            "front_setback_ft": round(front_ft, 2) if front_ft is not None else None,
            "rear_setback_ft": round(rear_ft, 2) if rear_ft is not None else None,
            "meets_requirement": None if global_min is None else (global_min + 0.1) >= required_setback,
            "attributes": attributes,
            "visualizations": visualizations,
            "notes": [
                "DWG parsed via dwg2dxf; textual data used for attributes. Geometric measurements are approximate."
            ],
        }
        rule_evaluations = evaluate_compliance_rules(attributes, result)
        if rule_evaluations:
            result["rule_evaluations"] = rule_evaluations
            attributes["rule_evaluations"] = rule_evaluations
        return result
    finally:
        if dxf_path and os.path.exists(dxf_path):
            try:
                os.remove(dxf_path)
            except OSError:
                pass

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('pdf', help='path to plan pdf')
    parser.add_argument('--required', type=float, default=5.0, help='required setback in feet')
    parser.add_argument('--json', action='store_true', help='output json')
    parser.add_argument(
        '--storage-root',
        help='optional root (e.g., storage/app) to resolve stored path values',
        default=None,
    )
    args = parser.parse_args()

    storage_root = (
        Path(args.storage_root).resolve()
        if args.storage_root
        else Path(__file__).resolve().parents[1] / 'storage' / 'app'
    )

    plan_path = Path(args.pdf)
    if not plan_path.exists():
        candidate = storage_root / args.pdf
        if candidate.exists():
            plan_path = candidate
    if not plan_path.exists():
        out = {"status": "error", "message": f"Plan not found: {plan_path}"}
        print(json.dumps(out))
        sys.exit(1)

    if plan_path.suffix.lower() == ".dwg":
        try:
            result = analyze_dwg(plan_path, args.required, storage_root)
        except Exception as e:
            result = {"status": "error", "message": str(e)}
        print(json.dumps(result))
        return

    try:
        text_objs = extract_text_objects(str(plan_path))
        page_texts = extract_page_texts(str(plan_path))
        img_bgr = pdf_to_image(str(plan_path))
        img_h, img_w = img_bgr.shape[:2]
        building_pts, boundary_pts = detect_polygons_from_image(img_bgr)

        if building_pts is None or boundary_pts is None:
            out = {"status": "error", "message": "Could not detect building or boundary from image."}
            print(json.dumps(out)); return

        inches_per_pixel = infer_scale_from_text(text_objs, img_w, img_h)
        if inches_per_pixel is None:
            inches_per_pixel = 1.0  # fallback; treat pixel ~ inch

        min_dist_px, p_build, p_bound = measure_min_distance(building_pts, boundary_pts)
        if min_dist_px is None:
            out = {"status": "error", "message": "Could not compute distance."}
            print(json.dumps(out)); return

        min_dist_inches = min_dist_px * inches_per_pixel
        min_dist_feet = min_dist_inches / 12.0

        # left/right split via building mid-x
        building_poly = Polygon(building_pts)
        minx, miny, maxx, maxy = building_poly.bounds
        midx = (minx + maxx) / 2
        midy = (miny + maxy) / 2
        left_boundary_pts  = [pt for pt in boundary_pts if pt[0] <  midx]
        right_boundary_pts = [pt for pt in boundary_pts if pt[0] >= midx]
        front_boundary_pts = [pt for pt in boundary_pts if pt[1] <  midy]
        rear_boundary_pts  = [pt for pt in boundary_pts if pt[1] >= midy]

        left_ft = None
        right_ft = None
        if len(left_boundary_pts) > 2:
            left_poly = Polygon(left_boundary_pts)
            left_dist = building_poly.distance(left_poly)
            left_ft = (left_dist * inches_per_pixel) / 12.0
        if len(right_boundary_pts) > 2:
            right_poly = Polygon(right_boundary_pts)
            right_dist = building_poly.distance(right_poly)
            right_ft = (right_dist * inches_per_pixel) / 12.0
        front_ft = compute_directional_setback(building_poly, front_boundary_pts, inches_per_pixel)
        rear_ft = compute_directional_setback(building_poly, rear_boundary_pts, inches_per_pixel)

        summary = summarize_textual_context(text_objs, page_texts)
        metrics = compute_plan_metrics(boundary_pts, building_pts, inches_per_pixel)
        floors = summary["floors_detected"]
        total_floors = len(floors)
        ground_floor = next((f for f in floors if "GROUND" in f["name"].upper()), None)
        ground_has_parking = ground_floor.get("has_car_parking", False) if ground_floor else False
        washroom_alignment = determine_washroom_alignment(floors)

        attributes = {
            **summary,
            **metrics,
            "front_setback_required_ft": args.required,
            "front_setback_measured_ft": round(float(front_ft), 2) if front_ft is not None else None,
            "rear_setback_measured_ft": round(float(rear_ft), 2) if rear_ft is not None else None,
            "left_setback_measured_ft": round(float(left_ft), 2) if left_ft is not None else None,
            "right_setback_measured_ft": round(float(right_ft), 2) if right_ft is not None else None,
            "total_floors": total_floors,
            "ground_floor_has_car_parking": ground_has_parking,
            "washrooms_first_second_share_dims": washroom_alignment,
            "has_left_side_space": bool(left_ft and left_ft > 0.1),
            "has_right_side_space": bool(right_ft and right_ft > 0.1),
            "has_front_side_space": bool(front_ft and front_ft > 0.1),
            "has_rear_side_space": bool(rear_ft and rear_ft > 0.1),
        }

        violations = []
        def add_violation(label, value):
            if value is None:
                return
            deficit = round(args.required - value, 2)
            if deficit > 0:
                violations.append(f"{label} setback short by {deficit} ft")

        add_violation("Front", front_ft)
        add_violation("Rear", rear_ft)
        add_violation("Left", left_ft)
        add_violation("Right", right_ft)
        attributes["violations"] = violations

        overlay_rel_path = draw_pdf_overlay(img_bgr, building_pts, boundary_pts, p_build, p_bound, storage_root)
        visualizations = []
        if overlay_rel_path:
            visualizations.append({
                "type": "image",
                "format": "png",
                "public_path": overlay_rel_path,
                "label": "Detected footprint overlay",
            })

        result = {
            "status": "ok",
            "required_setback_ft": args.required,
            "global_min_setback_ft": round(float(min_dist_feet), 2),
            "left_setback_ft": round(float(left_ft), 2) if left_ft is not None else None,
            "right_setback_ft": round(float(right_ft), 2) if right_ft is not None else None,
            "front_setback_ft": round(float(front_ft), 2) if front_ft is not None else None,
            "rear_setback_ft": round(float(rear_ft), 2) if rear_ft is not None else None,
            "meets_requirement": (min_dist_feet + 0.1) >= args.required,
            "attributes": attributes,
            "visualizations": visualizations,
            "notes": [
                "Scale inferred from text; tune approx_text_width_pixels for your drawings.",
                "Contours chosen by area (largest=boundary, second=building); adjust if needed.",
                "Left/right split via building mid-x; consider plot orientation."
            ]
        }
        rule_evaluations = evaluate_compliance_rules(attributes, result)
        if rule_evaluations:
            result["rule_evaluations"] = rule_evaluations
            attributes["rule_evaluations"] = rule_evaluations
        print(json.dumps(result))

    except Exception as e:
        out = {"status": "error", "message": str(e)}
        print(json.dumps(out))

if __name__ == "__main__":
    main()
