from __future__ import annotations

import json
import re
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any


class LayerResolver:
    def __init__(
        self,
        standard_layers: dict[str, Any],
        aliases: dict[str, Any] | None = None,
        layer_map: dict[str, Any] | None = None,
        tag_to_standard: dict[str, str] | None = None,
        model_path: str | None = None,
        mode: str = "hybrid",
    ):
        self.standard_layers = standard_layers or {}
        self.aliases = {self.normalize_for_compare(k): v for k, v in (aliases or {}).items()}
        self.layer_map = layer_map or {}
        self.tag_to_standard = tag_to_standard or {}
        self.mode = mode if mode in {"strict", "alias", "trained", "hybrid"} else "hybrid"
        self.model = None
        self.warnings: list[str] = []
        if model_path:
            self.model = self._load_model(model_path)
            if self.model is None and self.mode in {"trained", "hybrid"}:
                self.warnings.append("resolver_model_unavailable")

    def _strip_controls(self, value: str) -> str:
        return re.sub(r"[\u0000-\u001F\u007F-\u009F]+", "", value)

    def _clean_basic(self, raw_layer: Any) -> str:
        raw = self._strip_controls(str(raw_layer or ""))
        return re.sub(r"\s+", " ", raw).strip()

    def _remove_numeric_prefix(self, name: str) -> str:
        # "1 Plot Boundary", "01 Plot Boundary", "1. Plot Boundary", "1-Plot Boundary",
        # "1_Plot Boundary", "1) Plot Boundary", "1: Plot Boundary"
        return re.sub(r"^\d+\s*[\.\-_\):\s]+\s*", "", name).strip()

    def normalize_layer_name(self, raw_layer: Any) -> str:
        basic = self._clean_basic(raw_layer)
        no_prefix = self._remove_numeric_prefix(basic)
        normalized = re.sub(r"[-_]+", " ", no_prefix)
        normalized = re.sub(r"\s+", " ", normalized).strip()
        return normalized

    def normalize_for_compare(self, raw_layer: Any) -> str:
        return self.normalize_layer_name(raw_layer).lower()

    def _resolve_exact(self, raw_layer: str) -> str | None:
        if not raw_layer:
            return None
        basic = self._clean_basic(raw_layer)
        if basic in self.standard_layers:
            return basic
        for std in self.standard_layers:
            if self._clean_basic(std).lower() == basic.lower():
                return std
        upper = basic.upper()
        if upper in self.standard_layers:
            return upper
        lower = basic.lower()
        for std in self.standard_layers:
            if std.lower() == lower:
                return std
        return None

    def _resolve_normalized(self, raw_layer: str) -> tuple[str | None, str]:
        if not raw_layer:
            return None, "none"
        compare = self.normalize_for_compare(raw_layer)
        basic = self._clean_basic(raw_layer).lower()
        removed_prefix = self._remove_numeric_prefix(self._clean_basic(raw_layer)).lower() != basic
        for std in self.standard_layers:
            std_compare = self.normalize_for_compare(std)
            if std_compare == compare:
                return std, ("numeric_prefix_removed" if removed_prefix else "normalized_match")
        return None, "none"

    def _resolve_alias(self, raw_layer: str) -> str | None:
        if not raw_layer:
            return None
        normalized = self.normalize_for_compare(raw_layer)
        mapped = self.aliases.get(normalized)
        if mapped is None:
            return None
        if mapped in self.standard_layers:
            return mapped
        return None

    def _resolve_layer_map(self, raw_layer: str) -> str | None:
        if not raw_layer:
            return None
        normalized = self.normalize_layer_name(raw_layer)
        meta = (
            self.layer_map.get(raw_layer)
            or self.layer_map.get(raw_layer.upper())
            or self.layer_map.get(raw_layer.lower())
            or self.layer_map.get(normalized)
        )
        if not isinstance(meta, dict):
            return None
        std = meta.get("standard_layer")
        if std and std in self.standard_layers:
            return std
        tag = meta.get("tag")
        if isinstance(tag, str):
            return self.tag_to_standard.get(tag)
        return None

    def _resolve_trained(self, raw_layer: str, features: dict[str, Any] | None = None) -> tuple[str | None, float]:
        if not self.model or not raw_layer:
            return None, 0.0
        try:
            if hasattr(self.model, "predict"):
                prediction = self.model.predict([raw_layer])
                if not prediction:
                    return None, 0.0
                standard_layer = prediction[0]
                confidence = 0.0
                if hasattr(self.model, "predict_proba") and getattr(self.model, "classes_", None) is not None:
                    probs = self.model.predict_proba([raw_layer])[0]
                    try:
                        index = list(self.model.classes_).index(standard_layer)
                        confidence = float(probs[index])
                    except Exception:
                        confidence = float(max(probs))
                return standard_layer, float(confidence)
        except Exception:
            self.warnings.append("trained_model_failed")
        return None, 0.0

    def _load_model(self, model_path: str) -> Any | None:
        try:
            import joblib
        except ImportError:
            try:
                from sklearn.externals import joblib  # type: ignore
            except Exception:
                return None
        try:
            path = Path(model_path)
            if not path.exists():
                return None
            return joblib.load(str(path))
        except Exception:
            return None

    def _resolve_fuzzy(self, raw_layer: str) -> tuple[str | None, float]:
        if not raw_layer:
            return None, 0.0
        target = self.normalize_for_compare(raw_layer)
        best_std = None
        best_score = 0.0
        for std in self.standard_layers:
            score = SequenceMatcher(None, target, self.normalize_for_compare(std)).ratio()
            if score > best_score:
                best_score = score
                best_std = std
        if best_score >= 0.86:
            return best_std, float(best_score)
        return None, 0.0

    def resolve(self, raw_layer: Any, features: dict[str, Any] | None = None) -> dict[str, Any]:
        original = str(raw_layer or "")
        clean = self.normalize_layer_name(raw_layer)
        raw = self._clean_basic(raw_layer)
        if not raw:
            return {
                "standard_layer": None,
                "confidence": 0.0,
                "method": "none",
                "raw_layer": raw,
                "original_layer_name": original,
                "clean_layer_name": clean,
                "matched_schema_layer": None,
                "match_type": "none",
            }

        exact = self._resolve_exact(raw)
        if exact:
            return {
                "standard_layer": exact,
                "confidence": 1.0,
                "method": "exact",
                "raw_layer": raw,
                "original_layer_name": original,
                "clean_layer_name": clean,
                "matched_schema_layer": exact,
                "match_type": "exact_original",
            }

        normalized, norm_match_type = self._resolve_normalized(raw)
        if normalized:
            return {
                "standard_layer": normalized,
                "confidence": 0.98 if norm_match_type == "numeric_prefix_removed" else 0.95,
                "method": "normalized",
                "raw_layer": raw,
                "original_layer_name": original,
                "clean_layer_name": clean,
                "matched_schema_layer": normalized,
                "match_type": norm_match_type,
            }

        map_layer = self._resolve_layer_map(raw)
        if map_layer:
            return {
                "standard_layer": map_layer,
                "confidence": 0.95,
                "method": "layer_map",
                "raw_layer": raw,
                "original_layer_name": original,
                "clean_layer_name": clean,
                "matched_schema_layer": map_layer,
                "match_type": "layer_map",
            }

        if self.mode in {"alias", "hybrid"}:
            alias = self._resolve_alias(raw)
            if alias:
                return {
                    "standard_layer": alias,
                    "confidence": 0.9,
                    "method": "alias",
                    "raw_layer": raw,
                    "original_layer_name": original,
                    "clean_layer_name": clean,
                    "matched_schema_layer": alias,
                    "match_type": "alias",
                }
            if self.normalize_for_compare(raw) in self.aliases and self.aliases[self.normalize_for_compare(raw)] is None:
                return {
                    "standard_layer": None,
                    "confidence": 0.0,
                    "method": "alias",
                    "raw_layer": raw,
                    "original_layer_name": original,
                    "clean_layer_name": clean,
                    "matched_schema_layer": None,
                    "match_type": "alias_ignored",
                }

        fuzzy_layer, fuzzy_score = self._resolve_fuzzy(raw)
        if fuzzy_layer:
            return {
                "standard_layer": fuzzy_layer,
                "confidence": round(max(0.75, fuzzy_score), 4),
                "method": "fuzzy",
                "raw_layer": raw,
                "original_layer_name": original,
                "clean_layer_name": clean,
                "matched_schema_layer": fuzzy_layer,
                "match_type": "fuzzy",
            }

        if self.mode in {"trained", "hybrid"}:
            trained_std, trained_conf = self._resolve_trained(raw, features)
            if trained_std:
                return {
                    "standard_layer": trained_std,
                    "confidence": trained_conf if trained_conf > 0 else 0.65,
                    "method": "trained",
                    "raw_layer": raw,
                    "original_layer_name": original,
                    "clean_layer_name": clean,
                    "matched_schema_layer": trained_std,
                    "match_type": "trained",
                }

        return {
            "standard_layer": None,
            "confidence": 0.0,
            "method": "none",
            "raw_layer": raw,
            "original_layer_name": original,
            "clean_layer_name": clean,
            "matched_schema_layer": None,
            "match_type": "none",
        }
