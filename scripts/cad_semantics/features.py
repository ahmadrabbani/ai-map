from __future__ import annotations

from typing import Any, Dict


def normalize_layer(raw_layer: Any) -> str:
    return str(raw_layer or "").strip()


def build_candidate_features(candidate: dict[str, Any]) -> dict[str, Any]:
    return {
        "raw_layer": normalize_layer(candidate.get("raw_layer")),
        "standard_layer": candidate.get("standard_layer"),
        "confidence": candidate.get("confidence"),
        "num_vertices": candidate.get("num_vertices"),
        "area": candidate.get("area"),
        "bbox_w": candidate.get("bbox_w"),
        "bbox_h": candidate.get("bbox_h"),
        "rectangularity": candidate.get("rectangularity"),
        "centroid": candidate.get("centroid"),
    }
