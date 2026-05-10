from __future__ import annotations

import json
from pathlib import Path
from typing import Any


def append_training_events(path: str | Path, events: list[dict[str, Any]]) -> None:
    path_obj = Path(path)
    path_obj.parent.mkdir(parents=True, exist_ok=True)
    with path_obj.open("a", encoding="utf-8") as fh:
        for event in events:
            fh.write(json.dumps(event, default=str) + "\n")


def build_training_event(
    source_file: str,
    candidate: dict[str, Any],
    selected_role: str,
    label: str | None = None,
) -> dict[str, Any]:
    event: dict[str, Any] = {
        "source_file": source_file,
        "entity_handle": candidate.get("handle"),
        "raw_layer": candidate.get("raw_layer"),
        "predicted_standard_layer": candidate.get("standard_layer"),
        "confidence": candidate.get("confidence"),
        "selected_role": selected_role,
        "ignored_reason": candidate.get("ignored_reason"),
        "features": {
            "area": candidate.get("area"),
            "bbox_w": candidate.get("bbox_w"),
            "bbox_h": candidate.get("bbox_h"),
            "rectangularity": candidate.get("rectangularity"),
            "num_vertices": candidate.get("num_vertices"),
            "centroid": candidate.get("centroid"),
        },
    }
    if label is not None:
        event["label"] = label
    return event
