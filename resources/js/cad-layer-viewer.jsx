import React, { useEffect, useMemo, useRef, useState, useTransition } from "react";
import { createRoot } from "react-dom/client";
import * as THREE from "three";
import { OrbitControls } from "three/examples/jsm/controls/OrbitControls.js";

const DxfParserCtor = (window.DxfParser && window.DxfParser.DxfParser)
  ? window.DxfParser.DxfParser
  : (window.DxfParser || (window.dxf && window.dxf.Parser) || window.dxf);

const DEFAULT_TAG_OPTIONS = [
  { value: "", label: "(unassigned)", aliases: [] },
  { value: "plot_boundary", label: "Plot boundary / site / boundary wall", aliases: ["plot", "site", "boundary", "plot line", "boundary wall", "site-pl", "site-bw"] },
  { value: "ground_floor", label: "Ground floor footprint / GF / external walls", aliases: ["gf", "ground", "ground floor", "footprint", "gf-we", "external walls"] },
  { value: "floor_1", label: "First floor footprint / FF", aliases: ["ff", "first", "first floor", "floor 1", "ff-we"] },
  { value: "floor_2", label: "Second floor footprint / SF", aliases: ["sf", "second", "second floor", "floor 2", "sf-we"] },
  { value: "stairs_ramp", label: "Stairs / ramp / staircase", aliases: ["stair", "stairs", "ramp", "staircase"] },
  { value: "setback_lines", label: "Setback / offset / building line", aliases: ["setback", "offset", "building line", "fbl", "site-sb", "site-fbl"] },
  { value: "dimensions", label: "Dimensions / dim / size", aliases: ["dim", "dimension", "dimensions", "size", "width", "length", "ref-dim", "ref-dims"] },
  { value: "text", label: "Text / notes / labels", aliases: ["text", "note", "notes", "label", "annotation", "ref-txt"] },
  { value: "hatching", label: "Hatching / fill / pattern", aliases: ["hatch", "fill", "pattern"] },
  { value: "other", label: "Other", aliases: [] },
];

const PLANNER_TAG_VALUES = [
  "",
  "plot_boundary",
  "boundary_wall",
  "plot_line",
  "front_building_line",
  "side_building_line",
  "rear_building_line",
  "external_walls",
  "internal_walls",
  "door",
  "windows",
  "ventilator",
  "stairs",
  "porch",
  "services",
  "ramp",
  "landscape",
  "dimensions",
  "measurement_text",
  "text_general",
  "floor_level",
  "sewer_line",
  "water_tank",
  "rain_water_tank",
  "terrace",
  "ots_patio",
];

const PLANNER_TAG_LABELS = {
  "": "(unassigned)",
  plot_boundary: "Plot boundary",
  boundary_wall: "Boundary wall",
  plot_line: "Plot line",
  front_building_line: "Front building line",
  side_building_line: "Side building line",
  rear_building_line: "Rear building line",
  external_walls: "External walls / covered footprint",
  internal_walls: "Internal walls",
  door: "Doors",
  windows: "Windows",
  ventilator: "Ventilator",
  stairs: "Stairs",
  porch: "Porch",
  services: "Services",
  ramp: "Ramp",
  landscape: "Landscape/open space",
  dimensions: "Dimensions",
  measurement_text: "Measurement text",
  text_general: "General text/notes",
  floor_level: "Floor level",
  sewer_line: "Sewer line",
  water_tank: "Water tank",
  rain_water_tank: "Rain water tank",
  terrace: "Terrace",
  ots_patio: "OTS / patio",
};

const SEGMENTS_PER_TICK = 2000;
const MAX_TIME_PER_TICK_MS = 8;
const LOAD_MESSAGE_INTERVAL_MS = 250;
const MAX_SAMPLE_POINTS_PER_LAYER = 1200;
const TRIM_PERCENTILE = 0.02;
const OUTLIER_RATIO_THRESHOLD = 8;
const DOMINANT_LAYER_COVERAGE = 0.9;
const MAX_DOMINANT_LAYERS = 8;
const MIN_CLUSTER_POINTS = 60;
const DENSE_DISTANCE_PERCENTILE = 0.85;
const MAX_DENSE_SAMPLE_POINTS = 12000;
const FIT_ZOOM = 6;
const MAX_TEXT_ITEMS = 250;
const MAX_TEXT_OVERLAY_ITEMS = 120;
const FLOOR_OFFICIAL_SUGGESTIONS = {
  ground_floor: [
    { tag: "plot_boundary", label: "Plot boundary" },
    { tag: "front_setback", label: "Front setback" },
    { tag: "setback", label: "Setback lines" },
    { tag: "ground_external_walls", label: "Ground external walls" },
    { tag: "ground_internal_walls", label: "Ground internal walls" },
    { tag: "ground_doors", label: "Ground doors" },
    { tag: "ground_windows", label: "Ground windows" },
    { tag: "ground_stairs", label: "Ground stairs" },
    { tag: "ground_porch", label: "Ground porch" },
    { tag: "ground_terrace", label: "Ground terrace" },
    { tag: "ground_text", label: "Ground text" },
    { tag: "ground_services", label: "Ground services" },
    { tag: "dimension", label: "Dimensions" },
    { tag: "text", label: "Text / notes" },
  ],
  basement: [
    { tag: "plot_boundary", label: "Plot boundary" },
    { tag: "setback", label: "Setback lines" },
    { tag: "basement_external_walls", label: "Basement walls" },
    { tag: "basement_internal_walls", label: "Basement internal walls" },
    { tag: "basement_doors", label: "Basement doors" },
    { tag: "basement_windows", label: "Basement windows" },
    { tag: "basement_stairs", label: "Basement stairs" },
    { tag: "basement_text", label: "Basement text" },
    { tag: "basement_services", label: "Basement services" },
    { tag: "dimension", label: "Dimensions" },
    { tag: "text", label: "Text / notes" },
  ],
  first_floor: [
    { tag: "plot_boundary", label: "Plot boundary" },
    { tag: "first_external_walls", label: "First floor walls" },
    { tag: "first_internal_walls", label: "First floor internal walls" },
    { tag: "first_doors", label: "First floor doors" },
    { tag: "first_windows", label: "First floor windows" },
    { tag: "first_stairs", label: "First floor stairs" },
    { tag: "first_terrace", label: "First floor terrace" },
    { tag: "first_balcony", label: "First floor balcony" },
    { tag: "first_text", label: "First floor text" },
    { tag: "first_services", label: "First floor services" },
    { tag: "dimension", label: "Dimensions" },
    { tag: "text", label: "Text / notes" },
  ],
  second_floor: [
    { tag: "plot_boundary", label: "Plot boundary" },
    { tag: "second_external_walls", label: "Second floor walls" },
    { tag: "second_internal_walls", label: "Second floor internal walls" },
    { tag: "second_doors", label: "Second floor doors" },
    { tag: "second_windows", label: "Second floor windows" },
    { tag: "second_stairs", label: "Second floor stairs" },
    { tag: "second_terrace", label: "Second floor terrace" },
    { tag: "second_balcony", label: "Second floor balcony" },
    { tag: "second_text", label: "Second floor text" },
    { tag: "second_services", label: "Second floor services" },
    { tag: "dimension", label: "Dimensions" },
    { tag: "text", label: "Text / notes" },
  ],
  roof: [
    { tag: "roof_parapet_wall", label: "Roof parapet wall" },
    { tag: "mumty", label: "Mumty" },
    { tag: "water_tank", label: "Water tank" },
    { tag: "solar", label: "Solar" },
    { tag: "roof_text", label: "Roof text" },
    { tag: "dimension", label: "Dimensions" },
    { tag: "text", label: "Text / notes" },
  ],
};

const MAPPING_STATUS_COLORS = {
  auto_mapped: 0x1d9b5f,
  needs_expert_review: 0xe1ad01,
  expert_verified: 0x1f6feb,
  manual_mapped: 0x1f6feb,
  ignored: 0x8e9399,
  unmapped: 0x4a5560,
};

function normalizeLayerLabel(value) {
  return String(value || "")
    .replace(/[\u0000-\u001F\u007F-\u009F]/g, "")
    .trim()
    .replace(/^\d+\s*[\.\-_\):\s]+\s*/g, "")
    .replace(/[-_]+/g, " ")
    .replace(/\s+/g, " ")
    .toLowerCase();
}

function isReferenceLayer(layerName) {
  const layer = normalizeLayerLabel(layerName);
  return [
    "dim", "dimension", "demention", "dimenion", "measurement", "text", "hatch",
    "section", "elevation", "detail", "det", "gwr", "defpoints", "floor level",
    "window", "win", "door", "stair", "stairs",
  ].some((token) => layer === token || layer.includes(token));
}

function isApprovalLayer(layerName) {
  const layer = normalizeLayerLabel(layerName);
  return [
    "plot", "boundary", "building line", "external wall", "external walls",
    "ground floor external", "first floor external", "second floor external",
    "basement external", "porch", "services", "road", "setback", "front building",
    "side building", "rear building",
  ].some((token) => layer.includes(token));
}

function isMappedApprovalInfo(info) {
  return !!(
    info?.hasSemantic ||
    info?.hasVerified ||
    info?.hasAutoMapped ||
    info?.hasReviewCandidate ||
    isApprovalLayer(info?.layer)
  );
}

function normalizePersistedLayerMap(layerMap) {
  if (!layerMap || typeof layerMap !== "object" || Array.isArray(layerMap)) return {};
  const normalized = {};
  for (const [key, value] of Object.entries(layerMap)) {
    if (value && typeof value === "object" && !Array.isArray(value)) {
      normalized[key] = value;
      continue;
    }
    if (typeof value !== "string" || !value.trim()) continue;
    normalized[value] = { layer: value, visible: true, tag: key };
  }
  return normalized;
}

function visibleForViewMode(layer, info, mode) {
  if (mode === "all") return true;
  if (mode === "reference") return isReferenceLayer(layer);
  if (mode === "floor") return isMappedApprovalInfo({ ...(info || {}), layer }) || (isApprovalLayer(layer) && !isReferenceLayer(layer));
  return isMappedApprovalInfo({ ...(info || {}), layer }) || (isApprovalLayer(layer) && !isReferenceLayer(layer));
}

function visibleForFloorContext(layer, info, floorContext) {
  const layerText = normalizeLayerLabel(layer);
  const tagText = normalizeLayerLabel(info?.tag || "");
  const detectedFloor = detectFloorToken(layerText || tagText);
  const currentFloor = floorContext || "ground_floor";
  const commonLayer = isReferenceLayer(layerText) || isApprovalLayer(layerText) || isMappedApprovalInfo({ ...(info || {}), layer });

  if (!detectedFloor) {
    return true;
  }

  if (detectedFloor === currentFloor) {
    return true;
  }

  // Allow generic non-floor reference layers, but hide explicit other-floor layers.
  const currentToken = detectFloorToken(currentFloor);
  if (currentToken && detectedFloor !== currentToken) {
    return false;
  }

  return commonLayer;
}

function entityVisibleForViewMode(entity, mode) {
  const layer = entity?.layer_name || entity?.layer || "";
  const info = {
    layer,
    hasSemantic: !!entity?.semantic_entity,
    hasVerified: ["expert_verified", "manual_mapped"].includes(entity?.mapping_status),
    hasAutoMapped: entity?.mapping_status === "auto_mapped",
    hasReviewCandidate: entity?.mapping_status === "needs_expert_review",
  };

  return visibleForViewMode(layer, info, mode);
}

function entityVisibleForFloorContext(entity, floorContext) {
  const layer = entity?.layer_name || entity?.layer || "";
  const info = {
    layer,
    tag: entity?.semantic_entity || entity?.processingRole || entity?.mapping_status || "",
    hasSemantic: !!entity?.semantic_entity,
    hasVerified: ["expert_verified", "manual_mapped"].includes(entity?.mapping_status),
    hasAutoMapped: entity?.mapping_status === "auto_mapped",
    hasReviewCandidate: entity?.mapping_status === "needs_expert_review",
  };

  return visibleForFloorContext(layer, info, floorContext);
}

function floorRegexForContext(floorContext) {
  switch (floorContext) {
    case "basement":
      return /\b(basement|bsm|basm|b\/m)\b/i;
    case "first_floor":
      return /\b(first\s*floor|first floor plan|\bff\b|\b1st\b)\b/i;
    case "second_floor":
      return /\b(second\s*floor|second floor plan|\bsf\b|\b2nd\b)\b/i;
    case "roof":
      return /\b(roof|terrace|mumty)\b/i;
    case "ground_floor":
    default:
      return /\b(ground\s*floor|ground floor plan|\bgf\b|\bg\.?\s*f\.?)\b/i;
  }
}

function floorLabelFromContext(floorContext) {
  const regex = floorRegexForContext(floorContext);
  return regex;
}

function getTagOption(tagValue, options = DEFAULT_TAG_OPTIONS) {
  return options.find((option) => option.value === tagValue) || options[0];
}

function humanizeTagValue(value) {
  return String(value || "")
    .replace(/_/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function floorTagPrefix(floorContext) {
  switch (floorContext) {
    case "basement":
      return "basement";
    case "first_floor":
      return "first";
    case "second_floor":
      return "second";
    case "roof":
      return "roof";
    case "ground_floor":
    default:
      return "ground";
  }
}

function resolveTagLabel(tagValue, options = DEFAULT_TAG_OPTIONS, suggestions = []) {
  if (!tagValue) return "(unassigned)";
  const option = options.find((item) => item.value === tagValue);
  if (option?.label) return option.label;
  const suggestion = suggestions.find((item) => item.tag === tagValue);
  if (suggestion?.label) return suggestion.label;
  return humanizeTagValue(tagValue);
}

function inferLayerTag(layerName, options = []) {
  const layer = normalizeLayerLabel(layerName);
  if (!layer) return "";

  const matchers = [
    { tokens: ["door", "doors", "dr"], label: "Doors" },
    { tokens: ["window", "windows", "wn"], label: "Windows" },
    { tokens: ["stair", "stairs", "st"], label: "Stairs" },
    { tokens: ["porch", "car porch", "pr"], label: "Porch" },
    { tokens: ["terrace", "balcony", "tr", "bl"], label: "Terrace / Balcony" },
    { tokens: ["setback", "building line", "fbl", "sb"], label: "Setback" },
    { tokens: ["plot", "boundary", "site pl", "a wall"], label: "Plot Boundary" },
    { tokens: ["external wall", "external walls", "we"], label: "Building Footprint" },
    { tokens: ["internal wall", "internal walls", "wi"], label: "Internal Walls" },
    { tokens: ["road", "frontage"], label: "Road / Frontage" },
    { tokens: ["parking", "car"], label: "Parking" },
    { tokens: ["green", "lawn", "landscape", "ls"], label: "Green Area" },
    { tokens: ["dimension", "dimensions", "dim", "ref dims"], label: "Dimensions" },
    { tokens: ["text", "txt", "note", "notes"], label: "Text / Notes" },
  ];

  const hit = matchers.find((matcher) => matcher.tokens.some((token) => layer.includes(token)));
  if (!hit) return "";
  return options.find((option) => option.groupLabel === hit.label)?.value || "";
}

function buildSelectionKeywords(selectedLayer, selectedTag, options = DEFAULT_TAG_OPTIONS) {
  const option = getTagOption(selectedTag, options);
  return [...new Set(
    [
      selectedLayer || "",
      selectedTag || "",
      option.label || "",
      ...(option.aliases || []),
    ]
      .filter(Boolean)
      .flatMap((text) => text.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean))
  )];
}

function buildSelectionKeywordsFromLayers(layerNames = [], layerMeta = {}, options = DEFAULT_TAG_OPTIONS) {
  const keywords = new Set();
  for (const layer of Array.isArray(layerNames) ? layerNames : []) {
    const tag = layerMeta?.[layer]?.tag || "";
    const option = getTagOption(tag, options);
    for (const token of buildSelectionKeywords(layer, tag, options)) {
      keywords.add(token);
    }
    for (const token of [tag, option.label || "", ...(option.aliases || [])]) {
      String(token || "")
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter(Boolean)
        .forEach((tokenPart) => keywords.add(tokenPart));
    }
  }
  return Array.from(keywords);
}

const FLOOR_SEQUENCE = ["basement", "ground_floor", "first_floor", "second_floor", "roof"];

function detectFloorToken(value) {
  const text = normalizeLayerLabel(value);
  if (!text) return "";
  if (/(basement|bsm|b\/m|\bbm\b)/.test(text)) return "basement";
  if (/(ground floor|groundfloor|\bgf\b|^\bg\b|\bground\b)/.test(text)) return "ground_floor";
  if (/(first floor|firstfloor|\bff\b|\b1st\b|\bone\b|\bfirst\b)/.test(text)) return "first_floor";
  if (/(second floor|secondfloor|\bsf\b|\b2nd\b|\btwo\b|\bsecond\b)/.test(text)) return "second_floor";
  if (/(roof|\broof slab\b|\bterrace\b|\bmumty\b)/.test(text)) return "roof";
  return "";
}

function floorAwareBaseKey(value) {
  return normalizeLayerLabel(value)
    .replace(/\b(?:basement|bsm|ground|gf|first|ff|second|sf|roof|terrace|mumty|floor|level|lvl)\b/g, " ")
    .replace(/\b\d+(?:st|nd|rd|th)?\b/g, " ")
    .replace(/\b(?:b\/m|b-m|g\/f|g-f|f\/f|s\/f)\b/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function scoreLayerSimilarity(referenceLayer, candidateLayer) {
  const refBase = floorAwareBaseKey(referenceLayer);
  const candBase = floorAwareBaseKey(candidateLayer);
  if (!refBase || !candBase) return 0;
  if (referenceLayer === candidateLayer) return 0;

  const refTokens = new Set(refBase.split(" ").filter(Boolean));
  const candTokens = new Set(candBase.split(" ").filter(Boolean));
  if (!refTokens.size || !candTokens.size) return 0;

  let intersection = 0;
  for (const token of refTokens) {
    if (candTokens.has(token)) intersection += 1;
  }
  const union = new Set([...refTokens, ...candTokens]).size || 1;
  const jaccard = intersection / union;
  const sameBase = refBase === candBase;
  const refFloor = detectFloorToken(referenceLayer);
  const candFloor = detectFloorToken(candidateLayer);
  let score = jaccard;
  if (sameBase) score += 1.5;
  if (refFloor && candFloor && refFloor !== candFloor) score += 0.35;
  if (refFloor && candFloor && refFloor === candFloor) score += 0.15;
  if (refFloor && !candFloor) score += 0.05;
  return score;
}

function resolvePickableHit(hit) {
  if (!hit) return { object: null, layer: "", handle: "", expertMarkingId: "" };
  let object = hit.object || null;
  while (object) {
    const userData = object.userData || {};
    const layer = userData.layer || userData.layer_name || object.name || "";
    const handle = userData.handle || "";
    const expertMarkingId = userData.expertMarkingId || "";
    if (layer || handle || expertMarkingId) {
      return { object, layer, handle, expertMarkingId };
    }
    object = object.parent || null;
  }
  return { object: hit.object || null, layer: "", handle: "", expertMarkingId: "" };
}

function normalizeCadPoint(point) {
  if (Array.isArray(point) && point.length >= 2) {
    const x = Number(point[0]);
    const y = Number(point[1]);
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
  }
  if (point && typeof point === "object") {
    const x = Number(point.x ?? point[0]);
    const y = Number(point.y ?? point[1]);
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
  }
  return null;
}

function finiteCadCoordinate(value) {
  if (value === null || value === undefined || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

function entityPoints(entity) {
  const raw = entity?.geometry_json?.points || entity?.points || [];
  return Array.isArray(raw) ? raw.map(normalizeCadPoint).filter(Boolean) : [];
}

function entityBounds(entity) {
  const bbox = entity?.bbox_json || entity?.bounding_box_json || entity?.bbox || {};
  const minX = Number(bbox.min_x ?? bbox.minX);
  const minY = Number(bbox.min_y ?? bbox.minY);
  const maxX = Number(bbox.max_x ?? bbox.maxX);
  const maxY = Number(bbox.max_y ?? bbox.maxY);
  if ([minX, minY, maxX, maxY].every(Number.isFinite) && maxX >= minX && maxY >= minY) {
    return { minX, minY, maxX, maxY };
  }
  const pts = entityPoints(entity);
  if (!pts.length) return null;
  return pts.reduce((bounds, p) => ({
    minX: Math.min(bounds.minX, p.x),
    minY: Math.min(bounds.minY, p.y),
    maxX: Math.max(bounds.maxX, p.x),
    maxY: Math.max(bounds.maxY, p.y),
  }), { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity });
}

function pointInBounds(point, bounds, tolerance = 0) {
  return !!bounds &&
    point.x >= bounds.minX - tolerance &&
    point.x <= bounds.maxX + tolerance &&
    point.y >= bounds.minY - tolerance &&
    point.y <= bounds.maxY + tolerance;
}

function pointInPolygon(point, polygon) {
  if (!Array.isArray(polygon) || polygon.length < 3) return false;
  let inside = false;
  for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i, i += 1) {
    const pi = polygon[i];
    const pj = polygon[j];
    const intersect = ((pi.y > point.y) !== (pj.y > point.y)) &&
      (point.x < ((pj.x - pi.x) * (point.y - pi.y)) / ((pj.y - pi.y) || 1e-9) + pi.x);
    if (intersect) inside = !inside;
  }
  return inside;
}

function distanceToSegment(point, a, b) {
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  const lengthSq = dx * dx + dy * dy;
  if (lengthSq <= 0) return Math.hypot(point.x - a.x, point.y - a.y);
  const t = Math.max(0, Math.min(1, ((point.x - a.x) * dx + (point.y - a.y) * dy) / lengthSq));
  const px = a.x + t * dx;
  const py = a.y + t * dy;
  return Math.hypot(point.x - px, point.y - py);
}

function nearestPolylineDistance(point, points, closed = false) {
  if (!Array.isArray(points) || points.length < 2) return Infinity;
  let best = Infinity;
  for (let i = 1; i < points.length; i += 1) {
    best = Math.min(best, distanceToSegment(point, points[i - 1], points[i]));
  }
  if (closed && points.length > 2) {
    best = Math.min(best, distanceToSegment(point, points[points.length - 1], points[0]));
  }
  return best;
}

function createBounds() {
  return { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
}

function hasBounds(b) {
  return b && Number.isFinite(b.minX) && Number.isFinite(b.minY) && Number.isFinite(b.maxX) && Number.isFinite(b.maxY);
}

function updateBounds(b, p) {
  if (!b || !isValidPoint(p)) return;
  b.minX = Math.min(b.minX, p.x);
  b.minY = Math.min(b.minY, p.y);
  b.maxX = Math.max(b.maxX, p.x);
  b.maxY = Math.max(b.maxY, p.y);
}

function mergeBounds(a, b) {
  if (!hasBounds(a)) return hasBounds(b) ? { ...b } : null;
  if (!hasBounds(b)) return { ...a };
  return {
    minX: Math.min(a.minX, b.minX),
    minY: Math.min(a.minY, b.minY),
    maxX: Math.max(a.maxX, b.maxX),
    maxY: Math.max(a.maxY, b.maxY),
  };
}

function percentile(sorted, p) {
  if (!sorted.length) return null;
  const idx = Math.min(sorted.length - 1, Math.max(0, Math.floor(sorted.length * p)));
  return sorted[idx];
}

function median(sorted) {
  if (!sorted.length) return null;
  const mid = Math.floor(sorted.length / 2);
  if (sorted.length % 2) return sorted[mid];
  return (sorted[mid - 1] + sorted[mid]) / 2;
}

function isValidPoint(p) {
  return p && Number.isFinite(p.x) && Number.isFinite(p.y);
}

function coercePoint(p) {
  if (!p) return null;
  if (Array.isArray(p) && p.length >= 2) {
    const x = Number(p[0]);
    const y = Number(p[1]);
    return Number.isFinite(x) && Number.isFinite(y) ? { x, y } : null;
  }
  if (Number.isFinite(p.x) && Number.isFinite(p.y)) {
    return { x: Number(p.x), y: Number(p.y) };
  }
  if (p.location && Number.isFinite(p.location.x) && Number.isFinite(p.location.y)) {
    return { x: Number(p.location.x), y: Number(p.location.y) };
  }
  return null;
}

function coercePoints(points) {
  const out = [];
  for (const p of (points || [])) {
    const pt = coercePoint(p);
    if (pt) out.push(pt);
  }
  return out;
}

function circlePoints(center, radius, segments = 96) {
  const pts = [];
  for (let i = 0; i <= segments; i += 1) {
    const a = (i / segments) * Math.PI * 2;
    pts.push({ x: center.x + Math.cos(a) * radius, y: center.y + Math.sin(a) * radius });
  }
  return pts;
}

function normalizeAngle(angle) {
  if (!Number.isFinite(angle)) return 0;
  if (Math.abs(angle) > Math.PI * 2 + 0.01) {
    return (angle * Math.PI) / 180;
  }
  return angle;
}

function arcPoints(center, radius, startAngle, endAngle, segments = 96) {
  let start = normalizeAngle(startAngle);
  let end = normalizeAngle(endAngle);
  if (end < start) end += Math.PI * 2;
  const sweep = end - start;
  const steps = Math.max(8, Math.round(segments * (sweep / (Math.PI * 2))));
  const pts = [];
  for (let i = 0; i <= steps; i += 1) {
    const a = start + (sweep * (i / steps));
    pts.push({ x: center.x + Math.cos(a) * radius, y: center.y + Math.sin(a) * radius });
  }
  return pts;
}

function ellipsePoints(center, majorAxisEndPoint, axisRatio, startAngle, endAngle, segments = 96) {
  const majorRadius = Math.hypot(majorAxisEndPoint.x, majorAxisEndPoint.y);
  if (!Number.isFinite(majorRadius) || majorRadius <= 0) return [];
  const minorRadius = majorRadius * (Number.isFinite(axisRatio) ? axisRatio : 1);
  const axisAngle = Math.atan2(majorAxisEndPoint.y, majorAxisEndPoint.x);
  let start = normalizeAngle(startAngle);
  let end = normalizeAngle(endAngle);
  if (!Number.isFinite(end) || end === 0) end = Math.PI * 2;
  if (end < start) end += Math.PI * 2;
  const sweep = end - start;
  const steps = Math.max(12, Math.round(segments * (sweep / (Math.PI * 2))));
  const pts = [];
  const cosA = Math.cos(axisAngle);
  const sinA = Math.sin(axisAngle);
  for (let i = 0; i <= steps; i += 1) {
    const t = start + (sweep * (i / steps));
    const lx = Math.cos(t) * majorRadius;
    const ly = Math.sin(t) * minorRadius;
    const rx = (lx * cosA) - (ly * sinA);
    const ry = (lx * sinA) + (ly * cosA);
    pts.push({ x: center.x + rx, y: center.y + ry });
  }
  return pts;
}

function applyTransform(pt, tr) {
  let x = pt.x;
  let y = pt.y;
  if (tr.base) {
    x -= tr.base.x || 0;
    y -= tr.base.y || 0;
  }
  const sx = Number.isFinite(tr.xScale) ? tr.xScale : 1;
  const sy = Number.isFinite(tr.yScale) ? tr.yScale : 1;
  x *= sx;
  y *= sy;
  const ang = (tr.rotation || 0) * Math.PI / 180;
  const cos = Math.cos(ang);
  const sin = Math.sin(ang);
  const rx = x * cos - y * sin;
  const ry = x * sin + y * cos;
  const tx = (tr.position && Number.isFinite(tr.position.x)) ? tr.position.x : 0;
  const ty = (tr.position && Number.isFinite(tr.position.y)) ? tr.position.y : 0;
  return { x: rx + tx, y: ry + ty };
}

function transformPoints(points, transforms) {
  return (points || []).map((p) => transforms.reduce((acc, tr) => applyTransform(acc, tr), p));
}

function normalizeDxfText(raw) {
  if (raw == null) return "";
  const text = Array.isArray(raw) ? raw.join(" ") : String(raw);
  return text
    .replace(/\\P/g, " ")
    .replace(/\\~/g, " ")
    .replace(/\\X/g, " ")
    .replace(/\\[A-Za-z][^;]*;/g, " ")
    .replace(/\{|\}/g, " ")
    .replace(/\^\s*/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function extractEntityText(ent) {
  if (!ent) return "";
  return normalizeDxfText(
    ent.text ?? ent.string ?? ent.value ?? ent.rawText ?? ent.plainText ?? ent.content ?? ""
  );
}

function isNoisyCadText(text) {
  const t = String(text || "").trim();
  if (!t) return true;
  if (t.length < 2) return true;
  if (/[{}\[\]]/.test(t)) return true;
  if (/%%|\\[A-Za-z]/.test(t)) return true;
  if (/[;|]/.test(t) && t.length > 14) return true;
  if (/^[\d\W_]+$/.test(t)) return true;
  return false;
}

function cadTextScore(text) {
  const t = String(text || "");
  let score = 0;
  if (/[A-Za-z]/.test(t)) score += 4;
  if (/\b(ft|wide|room|setback|passage|porch|bed|kitchen|bath|drawing|scale|road|line|boundary|wall)\b/i.test(t)) score += 4;
  if (/\d+\s*'\s*[- ]?\s*\d+\s*(?:\"|in)?/i.test(t)) score += 2;
  if (t.length >= 6 && t.length <= 64) score += 2;
  if (/%%|\\[A-Za-z]|\{|\}|\[|\]/.test(t)) score -= 4;
  return score;
}

function extractScaleCandidates(text) {
  if (!text) return [];
  const results = [];
  const regex = /(?:scale\s*)?(?:1\s*[:/=]\s*(\d+(?:\.\d+)?))/gi;
  let match = regex.exec(text);
  while (match) {
    const multiplier = Number(match[1]);
    if (Number.isFinite(multiplier) && multiplier > 0) {
      results.push({
        multiplier,
        label: `1:${multiplier}`,
      });
    }
    match = regex.exec(text);
  }
  return results;
}

function parseFeetInchesToFeet(text) {
  if (!text) return null;
  const cleaned = String(text).replace(/[’′]/g, "'").replace(/[“”″]/g, '"').replace(/\s+/g, " ");
  const fractionalNumber = (value) => {
    const source = String(value || "").trim();
    if (!source) return 0;
    const mixed = source.match(/^(\d+(?:\.\d+)?)?\s*(\d+)\s*\/\s*(\d+)$/);
    if (mixed) {
      const whole = Number(mixed[1] || 0);
      const numerator = Number(mixed[2]);
      const denominator = Number(mixed[3]);
      return denominator > 0 ? whole + (numerator / denominator) : null;
    }
    const number = Number(source);
    return Number.isFinite(number) ? number : null;
  };
  let match = cleaned.match(/(\d+(?:\.\d+)?)\s*'\s*[- ]?\s*((?:\d+(?:\.\d+)?)?(?:\s+)?\d+\s*\/\s*\d+|\d+(?:\.\d+)?)?\s*(?:"|in)?/i);
  if (match) {
    const ft = Number(match[1]);
    const inches = fractionalNumber(match[2]);
    if (Number.isFinite(ft) && Number.isFinite(inches)) {
      return ft + (inches / 12);
    }
  }
  match = cleaned.match(/(\d+(?:\.\d+)?)\s*'\s*(?:\"|”)?/i);
  if (match) {
    const ft = Number(match[1]);
    if (Number.isFinite(ft)) return ft;
  }
  match = cleaned.match(/(\d+(?:\.\d+)?)\s*ft\b/i);
  if (match) {
    const ft = Number(match[1]);
    if (Number.isFinite(ft)) return ft;
  }
  return null;
}

function parseDimensionPairToFeet(text) {
  const parts = String(text || "").split(/\s*[x×]\s*/i);
  if (parts.length < 2) return null;
  for (let index = 0; index < parts.length - 1; index += 1) {
    const widthFt = parseFeetInchesToFeet(parts[index]);
    const lengthFt = parseFeetInchesToFeet(parts[index + 1]);
    if (Number.isFinite(widthFt) && widthFt > 0 && Number.isFinite(lengthFt) && lengthFt > 0) {
      return {
        width_ft: Number(widthFt.toFixed(3)),
        length_ft: Number(lengthFt.toFixed(3)),
        area_sq_ft: Number((widthFt * lengthFt).toFixed(2)),
      };
    }
  }
  return null;
}

function nearbyDimensionSuggestion(textItem, textItems, roomEntity) {
  const direct = parseDimensionPairToFeet(textItem?.text);
  if (direct) return { ...direct, source_text: textItem.text, source_handle: textItem.handle || null };
  const origin = { x: finiteCadCoordinate(textItem?.x), y: finiteCadCoordinate(textItem?.y) };
  const roomBounds = entityBounds(roomEntity);
  const candidates = (textItems || []).map((item) => {
    const dimensions = parseDimensionPairToFeet(item?.text);
    const x = finiteCadCoordinate(item?.x);
    const y = finiteCadCoordinate(item?.y);
    if (!dimensions || !Number.isFinite(x) || !Number.isFinite(y)) return null;
    if (roomBounds && !pointInBounds({ x, y }, roomBounds)) return null;
    return {
      ...dimensions,
      source_text: item.text,
      source_handle: item.handle || null,
      distance: Number.isFinite(origin.x) && Number.isFinite(origin.y) ? Math.hypot(x - origin.x, y - origin.y) : Infinity,
    };
  }).filter(Boolean).sort((a, b) => a.distance - b.distance);
  return candidates[0] || null;
}

function detectRepeatedParallelStairLines(textItem, entities, roomEntity, scaleMultiplier = 1, unitConfirmed = false) {
  const x = finiteCadCoordinate(textItem?.x);
  const y = finiteCadCoordinate(textItem?.y);
  if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
  const allBounds = (entities || []).map(entityBounds).filter(Boolean);
  const drawingSpan = allBounds.length ? Math.max(
    Math.max(...allBounds.map((bounds) => bounds.maxX)) - Math.min(...allBounds.map((bounds) => bounds.minX)),
    Math.max(...allBounds.map((bounds) => bounds.maxY)) - Math.min(...allBounds.map((bounds) => bounds.minY))
  ) : 1000;
  const roomBounds = entityBounds(roomEntity);
  const radius = Math.max(Number(textItem?.height || 0) * 24, drawingSpan * 0.045, 12);
  const segments = [];
  for (const entity of entities || []) {
    if (/(?:text|hatch|insert|circle|arc)/i.test(entity?.entity_type || "")) continue;
    const points = entityPoints(entity);
    for (let index = 1; index < points.length; index += 1) {
      const start = points[index - 1];
      const end = points[index];
      const dx = end.x - start.x;
      const dy = end.y - start.y;
      const length = Math.hypot(dx, dy);
      if (!(length > 0)) continue;
      const midpoint = { x: (start.x + end.x) / 2, y: (start.y + end.y) / 2 };
      if (roomBounds ? !pointInBounds(midpoint, roomBounds, drawingSpan * 0.002) : Math.hypot(midpoint.x - x, midpoint.y - y) > radius) continue;
      let angle = Math.atan2(dy, dx);
      if (angle < 0) angle += Math.PI;
      if (angle >= Math.PI) angle -= Math.PI;
      segments.push({ start, end, midpoint, length, angle, handle: entity.handle || null });
    }
  }
  const groups = new Map();
  const angleStep = Math.PI / 36;
  for (const segment of segments) {
    const key = Math.round(segment.angle / angleStep);
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(segment);
  }
  const patterns = [];
  for (const group of groups.values()) {
    if (group.length < 4) continue;
    const lengths = group.map((segment) => segment.length).sort((a, b) => a - b);
    const medianLength = lengths[Math.floor(lengths.length / 2)];
    const comparable = group.filter((segment) => segment.length >= medianLength * 0.55 && segment.length <= medianLength * 1.8);
    if (comparable.length < 4) continue;
    const angle = comparable.reduce((sum, segment) => sum + segment.angle, 0) / comparable.length;
    const direction = { x: Math.cos(angle), y: Math.sin(angle) };
    const normal = { x: -direction.y, y: direction.x };
    const offsetTolerance = Math.max(medianLength * 0.018, drawingSpan * 0.00025, 0.01);
    const unique = [];
    for (const segment of comparable.sort((a, b) => (
      (a.midpoint.x * normal.x + a.midpoint.y * normal.y) - (b.midpoint.x * normal.x + b.midpoint.y * normal.y)
    ))) {
      const offset = segment.midpoint.x * normal.x + segment.midpoint.y * normal.y;
      if (!unique.length || Math.abs(offset - unique[unique.length - 1].offset) > offsetTolerance) unique.push({ ...segment, offset });
    }
    if (unique.length < 4) continue;
    const gaps = unique.slice(1).map((segment, index) => segment.offset - unique[index].offset).filter((gap) => gap > offsetTolerance);
    const sortedGaps = gaps.slice().sort((a, b) => a - b);
    const medianGap = sortedGaps[Math.floor(sortedGaps.length / 2)] || 0;
    const sequences = [];
    let sequence = [unique[0]];
    for (let index = 1; index < unique.length; index += 1) {
      if (!medianGap || unique[index].offset - unique[index - 1].offset <= medianGap * 2.5) sequence.push(unique[index]);
      else { sequences.push(sequence); sequence = [unique[index]]; }
    }
    sequences.push(sequence);
    const repeated = sequences.sort((a, b) => b.length - a.length)[0];
    if (repeated.length < 4) continue;
    const along = repeated.flatMap((segment) => [segment.start, segment.end]).map((point) => point.x * direction.x + point.y * direction.y);
    const across = repeated.map((segment) => segment.offset);
    const widthCad = Math.max(...along) - Math.min(...along);
    const lengthCad = Math.max(...across) - Math.min(...across) + medianGap;
    const factor = Number.isFinite(Number(scaleMultiplier)) && Number(scaleMultiplier) > 0 ? Number(scaleMultiplier) : 1;
    patterns.push({
      observed_count: repeated.length,
      width_ft: Number((widthCad * factor).toFixed(3)),
      length_ft: Number((lengthCad * factor).toFixed(3)),
      area_sq_ft: Number((widthCad * lengthCad * factor * factor).toFixed(2)),
      source_handles: [...new Set(repeated.map((segment) => segment.handle).filter(Boolean))],
      spacing_consistency: medianGap > 0 ? Number((1 - Math.min(1, gaps.reduce((sum, gap) => sum + Math.abs(gap - medianGap), 0) / (gaps.length * medianGap))).toFixed(3)) : 0,
      unit_confirmed: !!unitConfirmed,
    });
  }
  return patterns.sort((a, b) => b.observed_count - a.observed_count || b.spacing_consistency - a.spacing_consistency)[0] || null;
}

function semanticHintsFromText(text) {
  const low = String(text || "").toLowerCase();
  const hints = [];
  const add = (v) => { if (!hints.includes(v)) hints.push(v); };
  if (/(front).*(setback|building line|fbl)|\bfbl\b/.test(low)) add("front_building_line");
  if (/(rear).*(setback|passage|building line)|\brbl\b/.test(low)) add("rear_building_line");
  if (/(side).*(setback|passage|building line)|\bsbl\b/.test(low)) add("side_building_line");
  if (/(porch|car porch|ramp)/.test(low)) add("porch");
  if (/(stair|stairs|staircase|\bup\b|\bdn\b)/.test(low)) add("stairs");
  if (/(bed\s*room|bedroom)/.test(low)) add("room");
  if (/(kitchen)/.test(low)) add("kitchen");
  if (/(bath|toilet|washroom|w\.c)/.test(low)) add("bathroom");
  if (/(plot\s*(area|size|no)|site\s*area)/.test(low)) add("plot_boundary");
  if (/(ground floor|first floor|second floor|basement|roof plan)/.test(low)) add("floor_label");
  if (/(passage|corridor)/.test(low) && !hints.length) add("setback_reference");
  if (/(dimension|dim|wide|width|long|length|size)/.test(low)) add("dimensions");
  return hints;
}

function nativeSpaceLabelFromText(text) {
  const normalized = String(text || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  if (!normalized) return null;

  const definitions = [
    { pattern: /\brear\s+(?:passage|setback|building\s+line)\b/, labelKey: "rear_building_line", labelName: "Rear passage", confidence: 0.98 },
    { pattern: /\bfront\s+(?:passage|setback|building\s+line)\b/, labelKey: "front_building_line", labelName: "Front passage", confidence: 0.98 },
    { pattern: /\bside\s+(?:passage|setback|building\s+line)\b/, labelKey: "side_building_line", labelName: "Side passage", confidence: 0.97 },
    { pattern: /\b(?:bed\s*room|bedroom|bed)\b/, labelKey: "bedroom", labelName: "Bedroom", confidence: 0.97 },
    { pattern: /\bkitchen\b/, labelKey: "kitchen", labelName: "Kitchen", confidence: 0.97 },
    { pattern: /\b(?:bath(?:room)?|toilet|washroom|w\s*c)\b/, labelKey: "bathroom", labelName: "Bathroom", confidence: 0.96 },
    { pattern: /\b(?:porch|car\s+porch)\b/, labelKey: "porch", labelName: "Porch", confidence: 0.96 },
    { pattern: /\b(?:terrace|balcony)\b/, labelKey: "terrace", labelName: "Terrace", confidence: 0.94 },
    { pattern: /\b(?:stair|stairs|staircase)\b/, labelKey: "staircase", labelName: "Staircase", confidence: 0.94 },
    { pattern: /\b(?:drawing|living|t\s*v\s+lounge|tv\s+lounge|lounge|dining|store|lobby)\b/, labelKey: "room", labelName: "Room", confidence: 0.9 },
  ];

  return definitions.find((definition) => definition.pattern.test(normalized)) || null;
}

function surroundingRoomEntity(point, entities) {
  if (!point) return null;
  const candidates = (entities || []).filter((entity) => {
    const points = entityPoints(entity);
    const closed = entity?.geometry_type === "polygon"
      || !!entity?.is_closed
      || !!entity?.geometry_json?.is_closed
      || !!entity?.measurement_json?.closed;
    return closed && points.length >= 3 && pointInBounds(point, entityBounds(entity)) && pointInPolygon(point, points);
  });
  if (!candidates.length) return null;

  const layerPenalty = (entity) => /(?:furniture|fixture|sanitary|door|window|text|dimension|hatch)/i.test(entity?.layer_name || "") ? 1 : 0;
  return candidates.sort((a, b) => {
    const penalty = layerPenalty(a) - layerPenalty(b);
    if (penalty !== 0) return penalty;
    const aBounds = entityBounds(a);
    const bBounds = entityBounds(b);
    const aArea = aBounds ? (aBounds.maxX - aBounds.minX) * (aBounds.maxY - aBounds.minY) : Infinity;
    const bArea = bBounds ? (bBounds.maxX - bBounds.minX) * (bBounds.maxY - bBounds.minY) : Infinity;
    return aArea - bArea;
  })[0];
}

function floorFromCadText(text) {
  const normalized = String(text || "").toLowerCase().replace(/[^a-z0-9]+/g, " ").replace(/\s+/g, " ").trim();
  if (/^(?:proposed\s+)?(?:basement(?:\s+floor)?|bsm)(?:\s+plan)?$/.test(normalized)) return "basement";
  if (/^(?:proposed\s+)?(?:ground(?:\s+floor)?|g\s*f)(?:\s+plan)?$/.test(normalized)) return "ground_floor";
  if (/^(?:proposed\s+)?(?:first\s+floor|1st\s+floor|f\s*f)(?:\s+plan)?$/.test(normalized)) return "first_floor";
  if (/^(?:proposed\s+)?(?:second\s+floor|2nd\s+floor|s\s*f)(?:\s+plan)?$/.test(normalized)) return "second_floor";
  if (/^(?:proposed\s+)?(?:roof|roof\s+floor|terrace)(?:\s+plan)?$/.test(normalized)) return "roof";
  return "";
}

function nativeFloorPrefix(floor) {
  return ({
    basement: "B1",
    ground_floor: "G1",
    first_floor: "F1",
    second_floor: "F2",
    roof: "R1",
  })[floor] || "U1";
}

function nativeSpaceToken(labelKey) {
  return ({
    bedroom: "bed",
    bathroom: "bath",
    staircase: "stair",
    rear_building_line: "rear_passage",
    front_building_line: "front_passage",
    side_building_line: "side_passage",
  })[labelKey] || String(labelKey || "space").replace(/[^a-z0-9]+/g, "_");
}

function nativeTextEvidenceIdentity(textItem) {
  const x = finiteCadCoordinate(textItem?.x);
  const y = finiteCadCoordinate(textItem?.y);
  const handle = String(textItem?.handle || "");
  return handle || `${String(textItem?.layer || "")}:${Number(x || 0).toFixed(4)}:${Number(y || 0).toFixed(4)}:${normalizeDxfText(textItem?.text)}`;
}

function nativeSpacePrediction(textItem, textItems, entities, floor, instanceKey, scaleMultiplier = 1, unitConfirmed = false) {
  const detected = nativeSpaceLabelFromText(textItem?.text);
  const x = finiteCadCoordinate(textItem?.x);
  const y = finiteCadCoordinate(textItem?.y);
  if (!detected || !Number.isFinite(x) || !Number.isFinite(y)) return null;

  const roomEntity = surroundingRoomEntity({ x, y }, entities);
  const roomPoints = roomEntity ? entityPoints(roomEntity) : [];
  const geometry = roomPoints.length >= 3
    ? { type: "polygon", points: roomPoints.map((point) => [point.x, point.y]) }
    : { type: "point", points: [[x, y]] };
  const evidenceHandle = String(textItem.handle || "");
  const identity = nativeTextEvidenceIdentity(textItem);
  const sourceKey = `native-cad-text:${floor || "unknown"}:${identity}:${detected.labelKey}`;
  const dimensionSuggestion = detected.labelKey === "staircase"
    ? null
    : nearbyDimensionSuggestion(textItem, textItems, roomEntity);
  const stairPattern = detected.labelKey === "staircase"
    ? detectRepeatedParallelStairLines(textItem, entities, roomEntity, scaleMultiplier, unitConfirmed)
    : null;
  const measurementSuggestion = stairPattern ? {
    method: "repeated_parallel_lines",
    observed_count: stairPattern.observed_count,
    width_ft: stairPattern.width_ft,
    length_ft: stairPattern.length_ft,
    area_sq_ft: stairPattern.area_sq_ft,
    source_handles: stairPattern.source_handles,
    spacing_consistency: stairPattern.spacing_consistency,
    unit: "sq_ft",
    unit_confirmed: stairPattern.unit_confirmed,
    officer_editable: true,
  } : dimensionSuggestion ? {
    method: "native_text_dimensions",
    width_ft: dimensionSuggestion.width_ft,
    length_ft: dimensionSuggestion.length_ft,
    area_sq_ft: dimensionSuggestion.area_sq_ft,
    source_text: dimensionSuggestion.source_text,
    source_handle: dimensionSuggestion.source_handle,
    unit: "sq_ft",
    unit_confirmed: true,
    officer_editable: true,
  } : null;

  return {
    source_key: sourceKey,
    instance_key: instanceKey,
    label_key: detected.labelKey,
    label_name: instanceKey || detected.labelName,
    confidence: roomEntity ? Math.min(0.99, detected.confidence + 0.01) : detected.confidence,
    geometry,
    model_version: "native-cad-text-v1",
    cad_handle: roomEntity?.handle || evidenceHandle || null,
    cad_layer: roomEntity?.layer_name || textItem.layer || null,
    floor: floor || null,
    metadata: {
      source: "native_cad_text",
      source_key: sourceKey,
      instance_key: instanceKey,
      plan_floor: floor || null,
      association: roomEntity ? "containing_closed_entity" : "text_location",
      measurement_suggestion: measurementSuggestion,
      cad_text_evidence: {
        raw_text: textItem.text,
        cad_layer: textItem.layer || null,
        cad_handle: evidenceHandle || null,
        x,
        y,
        semantic_hints: [detected.labelKey],
      },
    },
    finding: {
      text: textItem.text,
      layer: textItem.layer || "",
      handle: evidenceHandle || null,
      x,
      y,
      semantic_hints: [detected.labelKey],
      measurement_suggestion: measurementSuggestion,
    },
  };
}

function buildNativeSpacePredictions(textItems, entities, fallbackFloor, scaleMultiplier = 1, unitConfirmed = false) {
  const candidates = (textItems || [])
    .map((item) => {
      const detected = nativeSpaceLabelFromText(item?.text);
      const x = finiteCadCoordinate(item?.x);
      const y = finiteCadCoordinate(item?.y);
      return detected && Number.isFinite(x) && Number.isFinite(y) ? { item, detected, x, y } : null;
    })
    .filter(Boolean);
  if (!candidates.length) return [];

  const floorAnchors = (textItems || [])
    .map((item) => {
      const floor = floorFromCadText(item?.text);
      const x = finiteCadCoordinate(item?.x);
      const y = finiteCadCoordinate(item?.y);
      return floor && Number.isFinite(x) && Number.isFinite(y) ? { floor, x, y } : null;
    })
    .filter(Boolean);
  const xs = candidates.map((candidate) => candidate.x);
  const ys = candidates.map((candidate) => candidate.y);
  const drawingSpan = Math.max(
    xs.length ? Math.max(...xs) - Math.min(...xs) : 0,
    ys.length ? Math.max(...ys) - Math.min(...ys) : 0
  );
  const textHeights = (textItems || []).map((item) => Number(item?.height)).filter((height) => Number.isFinite(height) && height > 0).sort((a, b) => a - b);
  const medianTextHeight = textHeights.length ? textHeights[Math.floor(textHeights.length / 2)] : 0;
  const duplicateTolerance = Math.max(0.1, medianTextHeight * 2, drawingSpan / 1000);

  const assigned = candidates.map((candidate) => {
    const layerFloor = detectFloorToken(candidate.item.layer || "");
    const nearestAnchor = floorAnchors.slice().sort((a, b) => (
      Math.hypot(candidate.x - a.x, candidate.y - a.y) - Math.hypot(candidate.x - b.x, candidate.y - b.y)
    ))[0];
    return { ...candidate, floor: layerFloor || nearestAnchor?.floor || fallbackFloor || "unknown" };
  }).sort((a, b) => (
    String(a.floor).localeCompare(String(b.floor))
    || String(a.detected.labelKey).localeCompare(String(b.detected.labelKey))
    || b.y - a.y
    || a.x - b.x
  ));

  const deduplicated = [];
  for (const candidate of assigned) {
    const duplicate = deduplicated.some((existing) => (
      existing.floor === candidate.floor
      && existing.detected.labelKey === candidate.detected.labelKey
      && Math.hypot(existing.x - candidate.x, existing.y - candidate.y) <= duplicateTolerance
    ));
    if (!duplicate) deduplicated.push(candidate);
  }

  const counters = new Map();
  return deduplicated.map((candidate) => {
    const counterKey = `${candidate.floor}:${candidate.detected.labelKey}`;
    const ordinal = (counters.get(counterKey) || 0) + 1;
    counters.set(counterKey, ordinal);
    const instanceKey = `${nativeFloorPrefix(candidate.floor)}_${nativeSpaceToken(candidate.detected.labelKey)}_${ordinal}`;
    return nativeSpacePrediction(candidate.item, textItems, entities, candidate.floor, instanceKey, scaleMultiplier, unitConfirmed);
  }).filter(Boolean);
}

function extractCadTextMeasurements(rows) {
  const out = [];
  for (const row of rows || []) {
    const text = normalizeDxfText(row?.text || "");
    if (!text) continue;
    const valueFt = parseFeetInchesToFeet(text);
    const hints = semanticHintsFromText(text);
    if (!Number.isFinite(valueFt) && hints.length === 0) continue;
    out.push({
      layer: row?.layer || "",
      text,
      value_ft: Number.isFinite(valueFt) ? Number(valueFt.toFixed(3)) : null,
      semantic_hints: hints,
      x: Number.isFinite(row?.x) ? Number(row.x) : null,
      y: Number.isFinite(row?.y) ? Number(row.y) : null,
      handle: row?.handle || null,
    });
  }
  return out;
}

function prettyJson(value) {
  try {
    return JSON.stringify(value ?? {}, null, 2);
  } catch {
    return String(value ?? "");
  }
}

function humanFloorContext(value) {
  switch (value) {
    case "ground_floor":
      return "Ground floor";
    case "first_floor":
      return "First floor";
    case "second_floor":
      return "Second floor";
    case "basement":
      return "Basement";
    case "roof":
      return "Roof";
    default:
      return "Floor";
  }
}

function App() {
  const config = window.__cadViewerConfig || {};
  const tagOptions = useMemo(() => {
    const incoming = Array.isArray(config.tagOptions) ? config.tagOptions : [];
    return incoming.length ? incoming : DEFAULT_TAG_OPTIONS;
  }, [config.tagOptions]);
  const [showAdvancedTagOptions, setShowAdvancedTagOptions] = useState(false);
  const plannerTagOptions = useMemo(() => {
    const byValue = new Map(tagOptions.map((option) => [option.value, option]));
    return PLANNER_TAG_VALUES
      .map((value) => byValue.get(value))
      .filter(Boolean)
      .map((option) => ({
        ...option,
        label: PLANNER_TAG_LABELS[option.value] || option.label,
      }));
  }, [tagOptions]);
  const visibleTagOptions = showAdvancedTagOptions ? tagOptions : plannerTagOptions;
  const optionsForCurrentValue = (value) => {
    if (!value || visibleTagOptions.some((option) => option.value === value)) {
      return visibleTagOptions;
    }
    const selected = tagOptions.find((option) => option.value === value);
    return selected ? [...visibleTagOptions, selected] : visibleTagOptions;
  };
  const analysisResult = config.analysisResult || {};
  const trainingLabel = config.trainingLabel || {};
  const entitySummary = config.entitySummary || {};
  const rulesetOverview = config.rulesetOverview || {};
  const rulesMetadata = config.rulesMetadata || {};
  const cadTextReport = config.cadTextReport || {};
  const floorContext = config.floorContext || "ground_floor";
  const canvasRef = useRef(null);
  const layerInfoPopupRef = useRef(null);
  const rendererRef = useRef(null);
  const sceneRef = useRef(null);
  const cameraRef = useRef(null);
  const controlsRef = useRef(null);
  const raycasterRef = useRef(null);
  const layerGroupsRef = useRef({});
  const layerSegmentsRef = useRef({});
  const layerBoundsRef = useRef({});
  const statsRef = useRef({ entities: 0, lines: 0, polylines: 0 });
  const lastSizeRef = useRef({ w: 0, h: 0 });
  const resizeObserverRef = useRef(null);
  const resizeFnRef = useRef(null);
  const resizeRafRef = useRef(0);
  const layerMetaRef = useRef({});
  const fitInfoRef = useRef({ source: "full", fullSpan: null, dominantSpan: null, trimmedSpan: null });
  const dxfBytesRef = useRef(0);
  const textEntitiesRef = useRef([]);
  const measureLineRef = useRef(null);
  const measureModeRef = useRef(false);
  const measurePointsRef = useRef([]);
  const drawingModeRef = useRef("select");
  const currentPointsRef = useRef([]);
  const activeLabelKeyRef = useRef("");
  const drawingPreviewRef = useRef(null);
  const drawingCursorRef = useRef(null);
  const markingOverlaysRef = useRef([]);
  const selectedEntityOverlaysRef = useRef([]);
  const pickableObjectsRef = useRef([]);
  const highlightStateRef = useRef({ selected: "", related: new Set() });
  const textOverlayObjectsRef = useRef([]);
  const textOverlayGroupRef = useRef(null);
  const autoAppliedSuggestionRowsRef = useRef(new Set());
  const applyingQuickMarkRef = useRef(false);
  const nativePredictionSyncRef = useRef("");
  const nativeSuggestionActionRef = useRef(null);
  const nativeSuggestionRowRefs = useRef({});
  const nativeSuggestionVisualStateRef = useRef(new Map());

  const [layerMeta, setLayerMeta] = useState({});
  const [layerOrder, setLayerOrder] = useState([]);
  const [hoverText, setHoverText] = useState("");
  const [summaryText, setSummaryText] = useState("");
  const [selectedLayer, setSelectedLayer] = useState("");
  const [selectedLayers, setSelectedLayers] = useState([]);
  const [quickMarkTag, setQuickMarkTag] = useState("");
  const [textEntities, setTextEntities] = useState([]);
  const [textFilter, setTextFilter] = useState("");
  const [measureMode, setMeasureMode] = useState(false);
  const [measurePoints, setMeasurePoints] = useState([]);
  const [measureDistance, setMeasureDistance] = useState(null);
  const [scaleMultiplier, setScaleMultiplier] = useState(1);
  const [scaleLabel, setScaleLabel] = useState("1:1");
  const [scaleTouched, setScaleTouched] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadingMessage, setLoadingMessage] = useState("Loading DXF...");
  const loadingUpdateRef = useRef(0);
  const [rules] = useState(() => (Array.isArray(config.rules) ? config.rules : []));
  const [selectedRuleId, setSelectedRuleId] = useState(() => (config.rules?.[0]?.id || ""));
  const [measuredValue, setMeasuredValue] = useState("");
  const [notes, setNotes] = useState("");
  const [expertResults, setExpertResults] = useState(() => (Array.isArray(config.expertResults) ? config.expertResults : []));
  const [statusMessage, setStatusMessage] = useState(() => (config.statusMessage || ""));
  const [acceptedSuggestionState, setAcceptedSuggestionState] = useState({});
  const [savingResult, setSavingResult] = useState(false);
  const [viewMode, setViewMode] = useState("approval");
  const [mapSummary, setMapSummary] = useState(null);
  const [runningValidation, setRunningValidation] = useState(false);
  const [validationReport, setValidationReport] = useState(null);
  const [bulkTag, setBulkTag] = useState("");
  const [layerFilter, setLayerFilter] = useState("");
  const [bulkIncludeHidden, setBulkIncludeHidden] = useState(false);
  const [selectedEntityHandle, setSelectedEntityHandle] = useState("");
  const [layerSuggestions, setLayerSuggestions] = useState({});
  const [skippedOptionalLayers, setSkippedOptionalLayers] = useState({});
  const [chatbotStatus, setChatbotStatus] = useState("");
  const [chatInput, setChatInput] = useState("");
  const [chatMessages, setChatMessages] = useState(() => ([
    {
      role: "assistant",
      text: "Ask about current rule, mapped labels, or measured distance. I will answer from this drawing session.",
    },
  ]));
  const [labelSearch, setLabelSearch] = useState("");
  const [entitySearch, setEntitySearch] = useState("");
  const [labelsCatalog, setLabelsCatalog] = useState([]);
  const [activeLabelKey, setActiveLabelKey] = useState("");
  const [selectedEntityHandles, setSelectedEntityHandles] = useState([]);
  const [cadEntities, setCadEntities] = useState([]);
  const [mappingReport, setMappingReport] = useState(null);
  const [loadingLabels, setLoadingLabels] = useState(false);
  const [mappingBusy, setMappingBusy] = useState(false);
  const [drawingMode, setDrawingMode] = useState("select");
  const [currentPoints, setCurrentPoints] = useState([]);
  const [currentMeasurement, setCurrentMeasurement] = useState(null);
  const [expertMarkings, setExpertMarkings] = useState([]);
  const [selectedMarkingId, setSelectedMarkingId] = useState(null);
  const [expertReport, setExpertReport] = useState(null);
  const [loadingExpertReport, setLoadingExpertReport] = useState(false);
  const [showCadText, setShowCadText] = useState(true);
  const lastTextRefSyncRef = useRef("");
  const [, startUiTransition] = useTransition();
  const [topbarHeight, setTopbarHeight] = useState(72);
  const entityObjectsRef = useRef({});
  const cadEntitiesRef = useRef([]);
  const entityRowRefs = useRef({});
  const layerRowRefs = useRef({});
  const shiftPanRef = useRef(null);
  const centerTopbarRef = useRef(null);
  const autoMapBootstrappedRef = useRef(false);
  const hoveredEntityHandleRef = useRef("");
  const [hoveredEntityHandle, setHoveredEntityHandle] = useState("");
  const [pickCandidates, setPickCandidates] = useState(null);
  const [applyingQuickMark, setApplyingQuickMark] = useState(false);
  const [quickMarkFeedback, setQuickMarkFeedback] = useState(null);
  const [layerIdentificationReport, setLayerIdentificationReport] = useState(() => (
    config.layerIdentificationReport && typeof config.layerIdentificationReport === "object"
      ? config.layerIdentificationReport
      : { status: "awaiting_officer_marking", object_count: 0, objects: [] }
  ));
  const [taggingWorkspace, setTaggingWorkspace] = useState({ predictions: [], tags: [], rules: [], progress: { reviewed: 0, total: 0, percent: 0 } });
  const [selectedPredictionId, setSelectedPredictionId] = useState(null);
  const [predictionStatusFilter, setPredictionStatusFilter] = useState("unreviewed");
  const [predictionConfidence, setPredictionConfidence] = useState(0.75);
  const [predictionBusy, setPredictionBusy] = useState(false);
  const [correctedPredictionLabel, setCorrectedPredictionLabel] = useState("");
  const [predictionRemarks, setPredictionRemarks] = useState("");
  const [nativeSuggestionCorrections, setNativeSuggestionCorrections] = useState({});
  const [nativeSuggestionMeasurements, setNativeSuggestionMeasurements] = useState({});
  const [nativePredictionSyncing, setNativePredictionSyncing] = useState(false);
  const [focusedNativeSourceKey, setFocusedNativeSourceKey] = useState("");
  const [evaluationSummary, setEvaluationSummary] = useState(null);
  const [showAdvancedReviewTools, setShowAdvancedReviewTools] = useState(false);
  const [learningLabel, setLearningLabel] = useState("stairs");
  const [learningCount, setLearningCount] = useState("");
  const [learningMeasuredValue, setLearningMeasuredValue] = useState("");
  const [learningUnit, setLearningUnit] = useState("count");
  const [learningExpectedValue, setLearningExpectedValue] = useState("");
  const [learningRuleCode, setLearningRuleCode] = useState("");
  const [learningCompliance, setLearningCompliance] = useState("needs_review");
  const [learningNotes, setLearningNotes] = useState("");
  const [learningSnapshot, setLearningSnapshot] = useState("");
  const [learningSourceText, setLearningSourceText] = useState(null);
  const [learningRegionPoints, setLearningRegionPoints] = useState([]);
  const [savingLearningExample, setSavingLearningExample] = useState(false);

  useEffect(() => {
    layerMetaRef.current = layerMeta;
  }, [layerMeta]);

  useEffect(() => {
    cadEntitiesRef.current = cadEntities;
  }, [cadEntities]);

  useEffect(() => {
    hoveredEntityHandleRef.current = hoveredEntityHandle;
  }, [hoveredEntityHandle]);

  useEffect(() => {
    const firstHandle = selectedEntityHandles[0];
    if (!firstHandle) return;
    const node = entityRowRefs.current[firstHandle];
    if (node?.scrollIntoView) {
      node.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }, [selectedEntityHandles]);

  useEffect(() => {
    measureModeRef.current = measureMode;
    if (canvasRef.current) {
      canvasRef.current.classList.toggle("measuring", measureMode);
    }
  }, [measureMode]);
  useEffect(() => {
    drawingModeRef.current = drawingMode;
  }, [drawingMode]);
  useEffect(() => {
    currentPointsRef.current = currentPoints;
  }, [currentPoints]);
  useEffect(() => {
    activeLabelKeyRef.current = activeLabelKey;
  }, [activeLabelKey]);

  useEffect(() => {
    if (!selectedLayer) return;
    const node = layerRowRefs.current[selectedLayer];
    if (node?.scrollIntoView) {
      node.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }, [selectedLayer]);

  const selectedLayerSet = useMemo(() => new Set(selectedLayers), [selectedLayers]);
  const savedFloorTemplates = useMemo(() => {
    const raw = config.trainingLabel?.floor_templates;
    return raw && typeof raw === "object" && !Array.isArray(raw) ? raw : {};
  }, [config.trainingLabel]);
  const floorWizard = useMemo(() => {
    const activeLayers = (selectedLayers.length ? selectedLayers : [selectedLayer]).filter(Boolean);
    const activeFloor = floorContext || detectFloorToken(activeLayers[0] || "") || "ground_floor";
    const activeFloorIndex = Math.max(FLOOR_SEQUENCE.indexOf(activeFloor), 0);
    const nextFloor = FLOOR_SEQUENCE[Math.min(activeFloorIndex + 1, FLOOR_SEQUENCE.length - 1)] || activeFloor;
    const previousFloor = FLOOR_SEQUENCE[Math.max(activeFloorIndex - 1, 0)] || activeFloor;
    const activeBases = new Map();
    for (const layer of activeLayers) {
      const base = floorAwareBaseKey(layer);
      if (base) activeBases.set(base, layer);
    }

    const collected = new Map();
    for (const candidate of layerOrder) {
      if (!candidate || activeLayers.includes(candidate)) continue;
      const candidateFloor = detectFloorToken(candidate);
      const candidateBase = floorAwareBaseKey(candidate);
      if (!candidateBase || !activeBases.has(candidateBase)) continue;
      let bestScore = 0;
      for (const sourceLayer of activeLayers) {
        bestScore = Math.max(bestScore, scoreLayerSimilarity(sourceLayer, candidate));
      }
      if (bestScore < 0.6) continue;
      const existing = collected.get(candidate) || { layer: candidate, score: 0, floor: candidateFloor };
      collected.set(candidate, {
        ...existing,
        score: Math.max(existing.score || 0, bestScore),
        floor: candidateFloor || existing.floor || "",
      });
    }

    const matches = Array.from(collected.values()).sort((a, b) => b.score - a.score || a.layer.localeCompare(b.layer));
    const crossFloorMatches = matches.filter((item) => item.floor && item.floor !== activeFloor);
    const sameFloorMatches = matches.filter((item) => !item.floor || item.floor === activeFloor);

    return {
      activeFloor,
      activeFloorIndex,
      nextFloor,
      previousFloor,
      activeLayers,
      crossFloorMatches,
      sameFloorMatches,
      hasGroup: selectedLayers.length > 1 || !!selectedLayer,
      savedTemplate: savedFloorTemplates[activeFloor] || null,
      previousSavedTemplate: savedFloorTemplates[previousFloor] || null,
      nextSavedTemplate: savedFloorTemplates[nextFloor] || null,
    };
  }, [floorContext, layerOrder, selectedLayer, selectedLayers, savedFloorTemplates]);
  const hasFloorSpecificLayers = useMemo(
    () => layerOrder.some((layer) => !!detectFloorToken(layer)),
    [layerOrder]
  );

  useEffect(() => {
    const onKeyDown = (event) => {
      const editable = ["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement?.tagName);
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "z" && !editable) {
        event.preventDefault();
        if (!event.shiftKey) undoLastPoint();
        return;
      }
      if (event.key === "Escape") {
        if (pickCandidates) {
          setPickCandidates(null);
          return;
        }
        if (selectedLayer && drawingMode === "select") {
          clearLayerSelection();
        }
        setSelectedEntityHandles([]);
        setSelectedEntityHandle("");
        setSelectedPredictionId(null);
        return;
      }
      if (event.key === "Enter" && drawingMode !== "select") {
        event.preventDefault();
        finishCurrentShape();
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [drawingMode, currentPoints, pickCandidates, selectedLayer]);

  useEffect(() => {
    if (!selectedLayer || drawingMode !== "select") return undefined;

    const closeOnOutsidePointer = (event) => {
      if (layerInfoPopupRef.current?.contains(event.target)) return;
      clearLayerSelection();
    };

    document.addEventListener("pointerdown", closeOnOutsidePointer, true);
    return () => document.removeEventListener("pointerdown", closeOnOutsidePointer, true);
  }, [selectedLayer, drawingMode]);

  useEffect(() => {
    const measureTopbar = () => {
      const h = centerTopbarRef.current?.getBoundingClientRect?.().height;
      if (Number.isFinite(h) && h > 0) {
        setTopbarHeight(Math.ceil(h));
      }
    };
    measureTopbar();
    window.addEventListener("resize", measureTopbar);
    return () => window.removeEventListener("resize", measureTopbar);
  }, [drawingMode, activeLabelKey, currentPoints.length, measureMode]);

  useEffect(() => {
    if (!config.hasDxf) {
      setLoading(false);
      setLoadingMessage("DXF missing for this submission.");
      return undefined;
    }

    const cleanup = initThree();
    loadMappedOrDxf();
    return cleanup;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function loadMappedOrDxf() {
    // The main planner viewer must show the real drawing, not only semantic
    // map_entities. Semantic entities can be sparse/synthetic and are kept for
    // validation, suggestions, and reports rather than as the primary viewport.
    await Promise.allSettled([
      loadMapSummaryAndSuggestions(),
      loadExistingValidationReport(),
      loadCadEntities(),
      loadLabelsCatalog(),
      loadMappingReport(),
      loadExpertMarkings(),
      loadExpertMarkingReport(),
      loadTaggingWorkspace(),
    ]);
    if (config.autoMapOnLoad && !autoMapBootstrappedRef.current) {
      autoMapBootstrappedRef.current = true;
      await runAutoSuggestMappings();
      await Promise.allSettled([loadMappingReport(), loadExpertMarkingReport(), loadLabelsCatalog()]);
    }
    loadDxf();
  }

  async function loadTaggingWorkspace() {
    if (!config.taggingWorkspaceUrl) return;
    try {
      const response = await fetch(config.taggingWorkspaceUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) throw new Error(`Tagging workspace failed (${response.status})`);
      const payload = await response.json();
      setTaggingWorkspace(payload);
      setSelectedPredictionId((current) => current || payload.predictions?.find((row) => ["unreviewed", "ai_suggested"].includes(row.status))?.id || payload.predictions?.[0]?.id || null);
    } catch (error) {
      setStatusMessage(error.message || "Could not load the AI review queue.");
    }
  }

  async function reviewPrediction(action, prediction = selectedPrediction) {
    if (!prediction || predictionBusy) return;
    const correctionLabel = prediction.__correctedLabel || correctedPredictionLabel;
    if (action === "correct" && !correctionLabel) {
      setStatusMessage("Choose the corrected label first.");
      return;
    }
    setPredictionBusy(true);
    try {
      const url = config.predictionReviewUrlTemplate.replace("__PREDICTION_ID__", prediction.id);
      const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({
          action,
          label_key: action === "correct" ? correctionLabel : undefined,
          label_name: prediction.__labelName || undefined,
          floor: prediction.__floor || undefined,
          unit: scaleLabel.includes("inch") ? "IN" : "FT",
          scale: scaleMultiplier,
          unit_confirmed: prediction.__unitConfirmed ?? (scaleTouched || !!autoScaleFromPlotBoundary),
          observed_count: Object.prototype.hasOwnProperty.call(prediction, "__observedCount") ? prediction.__observedCount : undefined,
          area_sq_ft: Object.prototype.hasOwnProperty.call(prediction, "__areaSqFt") ? prediction.__areaSqFt : undefined,
          measurement_method: prediction.__measurementMethod || undefined,
          remarks: predictionRemarks || undefined,
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Review failed (${response.status})`);
      await loadTaggingWorkspace();
      setPredictionRemarks("");
      setCorrectedPredictionLabel("");
      const next = taggingWorkspace.predictions.find((row) => row.id !== prediction.id && ["unreviewed", "ai_suggested"].includes(row.status));
      setSelectedPredictionId(next?.id || null);
      if (next?.cad_handle) {
        const entity = cadEntitiesRef.current.find((row) => row.handle === next.cad_handle);
        if (entity) zoomToEntity(entity);
      }
      setStatusMessage(`${action === "confirm" ? "Confirmed" : action === "correct" ? "Corrected" : action === "reject" ? "Rejected" : "Marked uncertain"} prediction #${prediction.id}.`);
    } catch (error) {
      setStatusMessage(error.message || "Prediction review failed.");
    } finally {
      setPredictionBusy(false);
    }
  }

  function selectPredictionForReview(prediction, options = {}) {
    if (!prediction) return;
    setSelectedPredictionId(prediction.id);
    setCorrectedPredictionLabel(prediction.final_label_key || prediction.label_key || "");
    const evidence = prediction?.metadata?.cad_text_evidence;
    if (evidence) {
      highlightCadTextFinding({
        text: evidence.raw_text || prediction.label_name || prediction.label_key,
        layer: evidence.cad_layer || prediction.cad_layer || "",
        handle: evidence.cad_handle || null,
        x: evidence.x,
        y: evidence.y,
        semantic_hints: [prediction.label_key].filter(Boolean),
      }, options);
      return;
    }
    if (prediction.cad_handle) {
      const entity = cadEntitiesRef.current.find((row) => row.handle === prediction.cad_handle);
      if (entity) {
        selectEntityCandidate(candidateFromHandle(entity.handle, "prediction", 0));
        zoomToEntity(entity);
      }
    }
  }

  async function reviewNativeSpaceSuggestion(prediction, suggestedLabel) {
    if (!prediction) return;
    const chosenLabel = nativeSuggestionCorrections[prediction.id] || suggestedLabel || prediction.label_key;
    const row = nativeSpaceRows.find((item) => item.prediction?.id === prediction.id);
    const suggestion = row?.suggestion;
    const editedMeasurements = nativeSuggestionMeasurements[prediction.id] || {};
    const proposedMeasurements = suggestion?.metadata?.measurement_suggestion || {};
    const areaValue = editedMeasurements.area_sq_ft ?? proposedMeasurements.area_sq_ft;
    const countValue = editedMeasurements.observed_count ?? proposedMeasurements.observed_count;
    setSelectedPredictionId(prediction.id);
    setCorrectedPredictionLabel(chosenLabel);
    await reviewPrediction(chosenLabel === prediction.label_key ? "confirm" : "correct", {
      ...prediction,
      __correctedLabel: chosenLabel,
      __labelName: suggestion?.instance_key,
      __floor: suggestion?.floor,
      __areaSqFt: areaValue === "" || areaValue == null ? null : Number(areaValue),
      __observedCount: countValue === "" || countValue == null ? null : Number(countValue),
      __measurementMethod: proposedMeasurements.method || "officer_entered",
      __unitConfirmed: proposedMeasurements.unit_confirmed || editedMeasurements.area_sq_ft !== undefined,
    });
  }

  async function bulkConfirmPredictions() {
    if (!config.predictionBulkReviewUrl || predictionBusy) return;
    setPredictionBusy(true);
    try {
      const response = await fetch(config.predictionBulkReviewUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({ action: "confirm", confidence_threshold: Number(predictionConfidence), unit_confirmed: false }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Bulk review failed (${response.status})`);
      await loadTaggingWorkspace();
      setStatusMessage(`Confirmed ${payload.reviewed || 0} high-confidence predictions. Measurements remain provisional until unit/scale is confirmed.`);
    } catch (error) {
      setStatusMessage(error.message || "Bulk review failed.");
    } finally {
      setPredictionBusy(false);
    }
  }

  async function bulkConfirmNativeSpaces() {
    if (!config.predictionBulkReviewUrl || predictionBusy) return;
    const predictionIds = nativeSpaceRows
      .map((row) => row.prediction)
      .filter((prediction) => prediction && ["unreviewed", "ai_suggested", "uncertain"].includes(prediction.status))
      .map((prediction) => prediction.id);
    if (!predictionIds.length) return;
    setPredictionBusy(true);
    try {
      const response = await fetch(config.predictionBulkReviewUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({ action: "confirm", prediction_ids: predictionIds, unit_confirmed: false }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Bulk review failed (${response.status})`);
      await Promise.all([loadTaggingWorkspace(), loadLabelsCatalog()]);
      setStatusMessage(`Confirmed ${payload.reviewed || 0} native CAD text suggestions. Measurements remain provisional until unit/scale is confirmed.`);
    } catch (error) {
      setStatusMessage(error.message || "Native CAD text review failed.");
    } finally {
      setPredictionBusy(false);
    }
  }

  async function submitVerifiedTrainingData() {
    if (!config.submitVerifiedTagsUrl || predictionBusy) return;
    setPredictionBusy(true);
    try {
      const response = await fetch(config.submitVerifiedTagsUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({}),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Verification failed (${response.status})`);
      await loadTaggingWorkspace();
      setStatusMessage(`Submitted ${payload.verified || 0} tags as expert-verified training data.`);
    } catch (error) {
      setStatusMessage(error.message || "Training-data submission failed.");
    } finally {
      setPredictionBusy(false);
    }
  }

  async function runTagEvaluation() {
    if (!config.evaluateTagsUrl || predictionBusy) return;
    setPredictionBusy(true);
    try {
      const response = await fetch(config.evaluateTagsUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({ iou_threshold: 0.75, dataset_split: "review" }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Evaluation failed (${response.status})`);
      setEvaluationSummary(payload.run?.summary || null);
      setStatusMessage("Evaluation completed against expert-verified tags.");
    } catch (error) {
      setStatusMessage(error.message || "Evaluation failed.");
    } finally {
      setPredictionBusy(false);
    }
  }

  async function loadExpertMarkings() {
    if (!config.expertMarkingsUrl) return;
    try {
      const response = await fetch(config.expertMarkingsUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) {
        setStatusMessage("Expert markings API failed. Run migrations and refresh.");
        return;
      }
      const payload = await response.json();
      setExpertMarkings(Array.isArray(payload?.markings) ? payload.markings : []);
    } catch {
      // optional
    }
  }

  async function loadExpertMarkingReport() {
    if (!config.expertMarkingReportUrl) return;
    if (loadingExpertReport) return;
    setLoadingExpertReport(true);
    try {
      const response = await fetch(config.expertMarkingReportUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) {
        setStatusMessage("Expert marking report API failed. Run migrations and refresh.");
        return;
      }
      const payload = await response.json();
      const compact = {
        submission_id: payload?.submission_id,
        missing_required_labels: Array.isArray(payload?.missing_required_labels) ? payload.missing_required_labels : [],
        messages: Array.isArray(payload?.messages) ? payload.messages : [],
        labels: Array.isArray(payload?.labels) ? payload.labels : [],
      };
      // Yield one frame before committing large state to reduce main-thread spikes.
      requestAnimationFrame(() => setExpertReport(compact));
    } catch {
      // optional
    } finally {
      setLoadingExpertReport(false);
    }
  }

  async function loadCadEntities() {
    if (!config.cadEntitiesUrl) return;
    try {
      const url = new URL(config.cadEntitiesUrl, window.location.origin);
      if (config.mapDrawingId) url.searchParams.set("map_drawing_id", String(config.mapDrawingId));
      url.searchParams.set("per_page", "5000");
      const response = await fetch(url.toString(), { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const payload = await response.json();
      const rows = Array.isArray(payload?.data) ? payload.data : [];
      setCadEntities(rows);
    } catch {
      // optional
    }
  }

  async function loadLabelsCatalog() {
    if (!config.cadLabelsUrl) return;
    setLoadingLabels(true);
    try {
      const response = await fetch(config.cadLabelsUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const payload = await response.json();
      const rows = Array.isArray(payload?.labels) ? payload.labels : [];
      setLabelsCatalog(rows);
      if (!activeLabelKey && rows[0]?.label_key) {
        setActiveLabelKey(rows[0].label_key);
      }
    } catch {
      // optional
    } finally {
      setLoadingLabels(false);
    }
  }

  async function loadMappingReport() {
    if (!config.cadMappingReportUrl) return;
    try {
      const response = await fetch(config.cadMappingReportUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const payload = await response.json();
      setMappingReport(payload);
    } catch {
      // optional
    }
  }

  function resetDrawingScene() {
    for (const group of Object.values(layerGroupsRef.current || {})) {
      sceneRef.current?.remove(group);
    }
    if (textOverlayGroupRef.current) {
      sceneRef.current?.remove(textOverlayGroupRef.current);
      textOverlayGroupRef.current = null;
    }
    layerGroupsRef.current = {};
    layerSegmentsRef.current = {};
    layerBoundsRef.current = {};
    entityObjectsRef.current = {};
    pickableObjectsRef.current = [];
    textOverlayObjectsRef.current = [];
    statsRef.current = { entities: 0, lines: 0, polylines: 0 };
  }

function makeTextSprite(text, color = "#315b86", appearance = {}) {
  const canvas = document.createElement("canvas");
  const ctx = canvas.getContext("2d");
  if (!ctx) return null;
  const fontSize = Number(appearance.fontSize) || 12;
  const padding = Number(appearance.padding) || 3;
  const readableText = String(text || "").trim().replace(/\s+/g, " ");
  const displayText = readableText.length > 42 ? `${readableText.slice(0, 39)}…` : readableText;
  const fontWeight = appearance.fontWeight || 500;
  ctx.font = `${fontWeight} ${fontSize}px Manrope, sans-serif`;
  const width = Math.min(320, Math.max(30, Math.ceil(ctx.measureText(displayText).width) + (padding * 2)));
  const height = fontSize + (padding * 2);
    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);
    ctx.font = `${fontWeight} ${fontSize}px Manrope, sans-serif`;
  ctx.fillStyle = appearance.background || "rgba(255,255,255,0.68)";
  ctx.fillRect(0, 0, width, height);
  ctx.strokeStyle = appearance.border || "rgba(49,91,134,0.12)";
  ctx.lineWidth = Number(appearance.borderWidth) || 1;
  ctx.strokeRect(0, 0, width, height);
    ctx.fillStyle = color;
    ctx.textBaseline = "middle";
    ctx.fillText(displayText, padding, height / 2);
    const texture = new THREE.CanvasTexture(canvas);
    texture.minFilter = THREE.LinearFilter;
    const material = new THREE.SpriteMaterial({ map: texture, transparent: true, depthTest: false, depthWrite: false });
    const sprite = new THREE.Sprite(material);
  sprite.userData.textAspect = width / height;
  sprite.scale.set((width / height) * 4, 4, 1);
  return sprite;
}

  function resizeCadTextOverlays() {
    const camera = cameraRef.current;
    const viewportHeight = lastSizeRef.current.h || 600;
    if (!camera || viewportHeight <= 0) return;
    const visibleWorldHeight = Math.abs(camera.top - camera.bottom) / Math.max(camera.zoom || 1, 0.0001);
    const worldPerPixel = visibleWorldHeight / viewportHeight;
    const textHeight = worldPerPixel * 15;
    for (const sprite of textOverlayObjectsRef.current) {
      const aspect = Number(sprite.userData?.textAspect) || 2;
      const height = sprite.userData?.isNativeSpaceSuggestion ? worldPerPixel * 19 : textHeight;
      sprite.scale.set(aspect * height, height, 1);
    }
    for (const sprite of selectedEntityOverlaysRef.current) {
      if (!sprite.userData?.isCadTextHighlight) continue;
      const aspect = Number(sprite.userData?.textAspect) || 2;
      const highlightHeight = worldPerPixel * 26;
      sprite.scale.set(aspect * highlightHeight, highlightHeight, 1);
    }
  }

  function renderCadTextOverlays(rows) {
    const scene = sceneRef.current;
    if (!scene) return;
    if (!textOverlayGroupRef.current) {
      const g = new THREE.Group();
      g.name = "__cad_text_overlays__";
      g.renderOrder = 999;
      scene.add(g);
      textOverlayGroupRef.current = g;
    }
    const overlayGroup = textOverlayGroupRef.current;
    for (const obj of textOverlayObjectsRef.current) {
      overlayGroup.remove(obj);
      obj.material?.map?.dispose?.();
      obj.material?.dispose?.();
    }
    textOverlayObjectsRef.current = [];
    if (!showCadText) {
      render();
      return;
    }
    const limit = MAX_TEXT_OVERLAY_ITEMS;
    const fitInfo = fitInfoRef.current || {};
    const referenceSpan = [fitInfo.denseSpan, fitInfo.trimmedSpan, fitInfo.dominantSpan, fitInfo.fullSpan]
      .find((span) => Number.isFinite(span) && span > 0) || 1000;
    const cell = Math.max(12, Math.min(80, referenceSpan / 35));
    const occupied = new Set();
    const dedupe = new Set();
    const weightedRows = (rows || [])
      .filter((item) => item && item.text && Number.isFinite(item.x) && Number.isFinite(item.y))
      .filter((item) => !isNoisyCadText(item.text))
      .map((item) => ({ ...item, __score: cadTextScore(item.text) }))
      .filter((item) => item.__score >= 2)
      .sort((a, b) => b.__score - a.__score || String(a.text).length - String(b.text).length);
    const indexedSpaces = new Map(
      buildNativeSpacePredictions(rows, cadEntitiesRef.current, floorContext, scaleMultiplier, scaleTouched || !!autoScaleFromPlotBoundary)
        .map((suggestion) => [nativeTextEvidenceIdentity(suggestion.finding), suggestion])
    );
    let count = 0;
    for (const item of weightedRows) {
      if (count >= limit) break;
      const normText = String(item.text || "").toLowerCase().replace(/\s+/g, " ").trim();
      const gx = Math.floor(Number(item.x) / cell);
      const gy = Math.floor(Number(item.y) / cell);
      const bucket = `${gx}:${gy}`;
      const detectedSpace = indexedSpaces.get(nativeTextEvidenceIdentity(item));
      const visualState = detectedSpace
        ? nativeSuggestionVisualStateRef.current.get(nativeTextEvidenceIdentity(detectedSpace.finding))
        : null;
      const isMarked = !!visualState?.marked;
      const dedupeKey = detectedSpace ? detectedSpace.source_key : normText;
      if (dedupe.has(dedupeKey)) continue;
      if (occupied.has(bucket)) continue;
      const sprite = detectedSpace
        ? makeTextSprite(
          `${detectedSpace.instance_key} • ${item.text}`,
          isMarked ? "#075f54" : "#6b4f00",
          {
            background: isMarked ? "rgba(214, 250, 237, 0.94)" : "rgba(255, 244, 176, 0.96)",
            border: isMarked ? "rgba(15, 107, 95, 0.9)" : "rgba(202, 138, 4, 0.95)",
            borderWidth: 2,
            fontWeight: 800,
            padding: 5,
          }
        )
        : makeTextSprite(item.text);
      if (!sprite) continue;
      sprite.position.set(Number(item.x), Number(item.y), 5);
      sprite.visible = true;
      sprite.userData.isNativeSpaceSuggestion = !!detectedSpace;
      sprite.userData.nativeSpaceSourceKey = detectedSpace?.source_key || "";
      overlayGroup.add(sprite);
      textOverlayObjectsRef.current.push(sprite);
      occupied.add(bucket);
      dedupe.add(dedupeKey);
      count += 1;
    }
    resizeCadTextOverlays();
    render();
  }

  async function loadMapSummaryAndSuggestions() {
    if (config.mapSummaryUrl) {
      try {
        const summaryResponse = await fetch(config.mapSummaryUrl, { headers: { Accept: "application/json" } });
        if (summaryResponse.ok) {
          const summaryPayload = await summaryResponse.json();
          setMapSummary(summaryPayload.summary || null);
        }
      } catch {
        // Summary is helpful but not required for rendering.
      }
    }
    await loadLayerSuggestions();
  }

  function zoomAwareTolerance() {
    const canvas = canvasRef.current;
    const camera = cameraRef.current;
    if (!canvas || !camera) return 10;
    const rect = canvas.getBoundingClientRect();
    const viewWidth = Math.abs((camera.right - camera.left) / (camera.zoom || 1));
    const unitsPerPixel = rect.width > 0 ? viewWidth / rect.width : 1;
    return Math.max(4, unitsPerPixel * 12);
  }

  function candidateFromHandle(handle, source = "raycast", score = 0) {
    if (!handle) return null;
    const entity = cadEntitiesRef.current.find((item) => item.handle === handle);
    const object = entityObjectsRef.current[handle];
    const layer = entity?.layer_name || object?.userData?.layer || object?.userData?.layer_name || "";
    return {
      handle,
      layer,
      entityType: entity?.entity_type || object?.userData?.entityType || "CAD",
      area: Number(entity?.measurement_json?.measured_area ?? entity?.area ?? object?.userData?.area ?? 0),
      length: Number(entity?.measurement_json?.measured_length ?? entity?.perimeter ?? object?.userData?.perimeter ?? 0),
      source,
      score,
    };
  }

  function geometryCandidatesAt(point) {
    if (!point) return [];
    const tolerance = zoomAwareTolerance();
    const candidates = [];
    for (const entity of cadEntitiesRef.current || []) {
      const handle = entity?.handle;
      if (!handle) continue;
      const bounds = entityBounds(entity);
      if (bounds && !pointInBounds(point, bounds, tolerance)) continue;
      const points = entityPoints(entity);
      const closed = !!(entity?.is_closed || entity?.geometry_json?.is_closed);
      let score = Infinity;
      let matched = false;
      if (closed && points.length >= 3 && pointInPolygon(point, points)) {
        matched = true;
        score = 0;
      }
      const lineDistance = nearestPolylineDistance(point, points, closed);
      if (lineDistance <= tolerance) {
        matched = true;
        score = Math.min(score, lineDistance);
      }
      if (!matched && bounds && pointInBounds(point, bounds, tolerance / 2)) {
        matched = true;
        score = tolerance / 2;
      }
      if (!matched) continue;
      const candidate = candidateFromHandle(handle, "geometry", score);
      if (candidate) candidates.push(candidate);
    }
    return candidates.sort((a, b) => a.score - b.score).slice(0, 8);
  }

  function mergePickCandidates(rayCandidates, geoCandidates) {
    const byHandle = new Map();
    for (const candidate of [...rayCandidates, ...geoCandidates]) {
      if (!candidate?.handle) continue;
      const prev = byHandle.get(candidate.handle);
      if (!prev || candidate.score < prev.score || prev.source !== "raycast") {
        byHandle.set(candidate.handle, { ...prev, ...candidate });
      }
    }
    return Array.from(byHandle.values()).sort((a, b) => a.score - b.score);
  }

  function selectEntityCandidate(candidate, options = {}) {
    if (!candidate?.handle) return;
    const additive = !!options.additive;
    setSelectedEntityHandle(candidate.handle);
    setSelectedEntityHandles((prev) => {
      if (!additive) return [candidate.handle];
      if (prev.includes(candidate.handle)) {
        return prev;
      }
      return [...prev, candidate.handle];
    });
    if (candidate.layer) {
      selectLayer(candidate.layer, { additive });
    }
    setPickCandidates(null);
    setStatusMessage(`Selected ${candidate.handle}${candidate.layer ? ` on ${candidate.layer}` : ""}.`);
  }

  function unselectEntity(handle) {
    if (!handle) return;
    setSelectedEntityHandles((prev) => prev.filter((item) => item !== handle));
    setSelectedEntityHandle((prev) => (prev === handle ? "" : prev));
  }

  function zoomToEntity(entity) {
    const bounds = entityBounds(entity);
    if (!bounds) return;
    const camera = cameraRef.current;
    const controls = controlsRef.current;
    if (!camera || !controls) return;
    const width = Math.max(bounds.maxX - bounds.minX, 1);
    const height = Math.max(bounds.maxY - bounds.minY, 1);
    const centerX = (bounds.minX + bounds.maxX) / 2;
    const centerY = (bounds.minY + bounds.maxY) / 2;
    const aspect = lastSizeRef.current.h > 0 ? lastSizeRef.current.w / lastSizeRef.current.h : 1;
    const viewW = Math.max(width * 1.6, height * aspect * 1.6);
    const viewH = Math.max(height * 1.6, width / aspect * 1.6);
    camera.left = -viewW / 2;
    camera.right = viewW / 2;
    camera.top = viewH / 2;
    camera.bottom = -viewH / 2;
    camera.position.set(centerX, centerY, Math.max(1000, width, height));
    camera.updateProjectionMatrix();
    controls.target.set(centerX, centerY, 0);
    controls.update();
    render();
  }

  function highlightCadTextFinding(finding, options = {}) {
    if (!finding) return;
    const preserveDrawing = !!options.preserveDrawing;
    const findingX = finiteCadCoordinate(finding.x);
    const findingY = finiteCadCoordinate(finding.y);
    const findingHandle = String(finding.handle || "");
    const normalizedText = normalizeDxfText(finding.text);
    const normalizedLayer = String(finding.layer || "").toLowerCase();
    const textMatches = cadEntitiesRef.current.filter((entity) => (
      entity.text_content && normalizeDxfText(entity.text_content) === normalizedText
    ));
    const matchedEntity = cadEntitiesRef.current.find((entity) => (
      findingHandle && String(entity.handle || "") === findingHandle
    )) || textMatches.find((entity) => (
      normalizedLayer && String(entity.layer_name || "").toLowerCase() === normalizedLayer
    )) || textMatches.sort((a, b) => {
      if (!Number.isFinite(findingX) || !Number.isFinite(findingY)) return 0;
      const aBounds = entityBounds(a);
      const bBounds = entityBounds(b);
      const distance = (bounds) => bounds
        ? Math.hypot(((bounds.minX + bounds.maxX) / 2) - findingX, ((bounds.minY + bounds.maxY) / 2) - findingY)
        : Infinity;
      return distance(aBounds) - distance(bBounds);
    })[0];
    if (preserveDrawing) {
      setPickCandidates(null);
      setSelectedEntityHandle("");
      setSelectedEntityHandles([]);
      clearLayerSelection();
      setLearningSourceText(null);
      setLearningRegionPoints([]);
      setStatusMessage("CAD suggestion selected. Confirm, change, or reject it in the right panel.");
      return;
    } else if (matchedEntity) {
      selectEntityCandidate(candidateFromHandle(matchedEntity.handle, "ai-text-report", 0));
      zoomToEntity(matchedEntity);
    } else if (Number.isFinite(findingX) && Number.isFinite(findingY)) {
      const camera = cameraRef.current;
      const controls = controlsRef.current;
      if (camera && controls) {
        const visibleHeight = Math.max(80, Math.abs(camera.top - camera.bottom) / Math.max(camera.zoom || 1, 0.0001));
        const aspect = lastSizeRef.current.h ? lastSizeRef.current.w / lastSizeRef.current.h : 1;
        camera.left = -(visibleHeight * aspect) / 2; camera.right = (visibleHeight * aspect) / 2;
        camera.top = visibleHeight / 2; camera.bottom = -visibleHeight / 2;
        camera.position.set(findingX, findingY, camera.position.z);
        camera.updateProjectionMatrix();
        controls.target.set(findingX, findingY, 0); controls.update(); render();
      }
    }
    const suggestedLabel = finding.semantic_hints?.[0];
    if (suggestedLabel) {
      const supported = tagOptions.some((option) => option.value === suggestedLabel);
      const fallback = suggestedLabel === "room" && tagOptions.some((option) => option.value === "text") ? "text" : learningLabel;
      setLearningLabel(supported ? suggestedLabel : fallback);
    }
    if (finding.value_ft != null) {
      setLearningMeasuredValue(String(finding.value_ft));
      setLearningUnit("ft");
    }
    setLearningSourceText(finding);
    setLearningNotes(`CAD text evidence: "${finding.text}". Officer verification: `);
    setStatusMessage(preserveDrawing
      ? "CAD suggestion highlighted without changing the drawing view. Confirm, change, or reject it in the right panel."
      : "CAD text highlighted. Verify it on the drawing, capture the region, and complete the officer note.");
  }

  function resetView() {
    applyViewMode(floorContext ? "floor" : "approval");
    requestAnimationFrame(() => fitView());
  }

  function toggleFullscreenViewer() {
    const node = canvasRef.current?.parentElement;
    if (!node) return;
    if (document.fullscreenElement) {
      document.exitFullscreen?.();
    } else {
      node.requestFullscreen?.();
    }
  }

  function initThree() {
    const canvas = canvasRef.current;
    if (!canvas) return () => {};

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, preserveDrawingBuffer: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    rendererRef.current = renderer;

    const scene = new THREE.Scene();
    scene.background = new THREE.Color(0xffffff);
    sceneRef.current = scene;

    const camera = new THREE.OrthographicCamera(-100, 100, 100, -100, 0.1, 10000000);
    camera.position.set(0, 0, 1000);
    camera.lookAt(0, 0, 0);
    cameraRef.current = camera;

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableRotate = false;
    controls.enablePan = true;
    controls.screenSpacePanning = true;
    controls.zoomSpeed = 1.0;
    controls.addEventListener("change", render);
    controlsRef.current = controls;

    const light = new THREE.AmbientLight(0xffffff, 1);
    scene.add(light);

    const raycaster = new THREE.Raycaster();
    raycaster.params.Line.threshold = 10;
    raycasterRef.current = raycaster;

    const applyResize = () => {
      const target = canvas.parentElement || canvas;
      const rect = target.getBoundingClientRect();
      const w = rect.width || window.innerWidth || 800;
      const h = rect.height || window.innerHeight || 600;
      lastSizeRef.current = { w: Math.round(w), h: Math.round(h) };
      renderer.setSize(w, h, false);
      render();
    };

    const scheduleResize = () => {
      if (resizeRafRef.current) return;
      resizeRafRef.current = requestAnimationFrame(() => {
        resizeRafRef.current = 0;
        applyResize();
      });
    };

    const beginShiftPan = (event) => {
      const shiftLeftDrag = event.button === 0 && event.shiftKey;
      const middleDrag = event.button === 1;
      if ((!shiftLeftDrag && !middleDrag) || drawingModeRef.current !== "select" || measureModeRef.current) {
        return false;
      }
      event.preventDefault();
      event.stopPropagation();
      setPickCandidates(null);
      shiftPanRef.current = {
        startX: event.clientX,
        startY: event.clientY,
        targetX: controls.target.x,
        targetY: controls.target.y,
        moved: false,
      };
      canvas.style.cursor = "grabbing";
      try {
        canvas.setPointerCapture?.(event.pointerId);
      } catch {
        // ignore pointer capture failures
      }
      return true;
    };

    const updateShiftPan = (event) => {
      const pan = shiftPanRef.current;
      if (!pan) return false;
      event.preventDefault();
      event.stopPropagation();
      const rect = canvas.getBoundingClientRect();
      if (!rect.width || !rect.height) return false;
      const cameraWidth = (camera.right - camera.left) / camera.zoom;
      const cameraHeight = (camera.top - camera.bottom) / camera.zoom;
      const dx = event.clientX - pan.startX;
      const dy = event.clientY - pan.startY;
      pan.moved = pan.moved || Math.abs(dx) > 2 || Math.abs(dy) > 2;
      controls.target.set(
        pan.targetX - (dx / rect.width) * cameraWidth,
        pan.targetY + (dy / rect.height) * cameraHeight,
        controls.target.z,
      );
      controls.update();
      render();
      return true;
    };

    const endShiftPan = (event) => {
      if (!shiftPanRef.current) return;
      event?.preventDefault?.();
      event?.stopPropagation?.();
      try {
        canvas.releasePointerCapture?.(event?.pointerId);
      } catch {
        // ignore pointer capture failures
      }
      shiftPanRef.current = null;
      canvas.style.cursor = "";
    };

    resizeFnRef.current = applyResize;

    const onPointerDown = (event) => {
      if (beginShiftPan(event)) {
        return;
      }
      const worldPoint = getWorldPoint(event);
      const mode = drawingModeRef.current;
      const activeLabel = activeLabelKeyRef.current;
      const pointsNow = currentPointsRef.current || [];
      if (mode !== "select" && worldPoint) {
        if (!activeLabel) {
          setStatusMessage("Select a label first.");
          return;
        }
        if (mode === "point") {
          const pts = [worldPoint];
          setCurrentPoints(pts);
          setCurrentMeasurement(measurementForDrawing("point", pts));
          updateDrawingPreview(pts, mode);
          return;
        }
        if (mode === "rectangle") {
          if (pointsNow.length === 0) {
            const pts = [worldPoint];
            setCurrentPoints(pts);
            updateDrawingPreview(pts, mode);
          } else {
            const p0 = pointsNow[0];
            const p2 = worldPoint;
            const rect = [
              { x: p0.x, y: p0.y },
              { x: p2.x, y: p0.y },
              { x: p2.x, y: p2.y },
              { x: p0.x, y: p2.y },
            ];
            setCurrentPoints(rect);
            setCurrentMeasurement(measurementForDrawing("rectangle", rect));
            updateDrawingPreview(rect, "rectangle");
          }
          return;
        }

        const next = [...pointsNow, worldPoint];
        setCurrentPoints(next);
        setCurrentMeasurement(measurementForDrawing(mode, next));
        updateDrawingPreview(next, mode);
        if ((event.detail || 0) >= 2 && (mode === "polygon" || mode === "polyline")) {
          finishCurrentShape(next, mode);
          saveCurrentDrawing(true, next, mode);
        }
        return;
      }
      if (measureModeRef.current && worldPoint) {
        const next = [...measurePointsRef.current, worldPoint].slice(-2);
        measurePointsRef.current = next;
        setMeasurePoints(next);
        if (next.length === 2) {
          const dx = next[1].x - next[0].x;
          const dy = next[1].y - next[0].y;
          setMeasureDistance(Math.hypot(dx, dy));
        } else {
          setMeasureDistance(null);
        }
        updateMeasureLine(next);
        return;
      }

      const { left, top, width, height } = canvas.getBoundingClientRect();
      const x = ((event.clientX - left) / width) * 2 - 1;
      const y = -((event.clientY - top) / height) * 2 + 1;
      raycaster.setFromCamera({ x, y }, camera);
      const nativeSuggestionHit = raycaster
        .intersectObjects(textOverlayObjectsRef.current.filter((object) => object.userData?.nativeSpaceSourceKey), true)
        .find((hit) => hit.object?.userData?.nativeSpaceSourceKey);
      if (nativeSuggestionHit) {
        event.preventDefault();
        event.stopPropagation();
        setPickCandidates(null);
        nativeSuggestionActionRef.current?.(nativeSuggestionHit.object.userData.nativeSpaceSourceKey);
        return;
      }
      const pickables = pickableObjectsRef.current.length
        ? pickableObjectsRef.current
        : Object.values(entityObjectsRef.current || {});
      const hits = raycaster.intersectObjects(pickables, true);
      if (hits.length) {
        const resolvedHits = hits.map((hit) => ({ hit, meta: resolvePickableHit(hit) }));
        const rayCandidates = resolvedHits
          .filter(({ meta }) => meta.handle)
          .map(({ meta }, idx) => candidateFromHandle(meta.handle, "raycast", idx));
        const geometryCandidates = geometryCandidatesAt(worldPoint);
        const cadCandidates = mergePickCandidates(rayCandidates, geometryCandidates);
        if (cadCandidates.length > 1 && !event.ctrlKey && !event.metaKey) {
          setPickCandidates({
            x: event.clientX - left,
            y: event.clientY - top,
            candidates: cadCandidates.slice(0, 6),
          });
          return;
        }
        if (cadCandidates.length) {
          selectEntityCandidate(cadCandidates[0], { additive: event.ctrlKey || event.metaKey });
          return;
        }
        const markingHit = resolvedHits.find(({ meta }) => meta.expertMarkingId);
        const selectedHit = markingHit || resolvedHits[0];
        const meta = selectedHit?.meta || { layer: "", handle: "", expertMarkingId: "" };
        if (meta.expertMarkingId && !meta.handle && !meta.layer) {
          setSelectedMarkingId(meta.expertMarkingId);
          const marking = expertMarkings.find((m) => Number(m.id) === Number(meta.expertMarkingId));
          if (marking?.label_key) {
            selectActiveLabel(marking.label_key);
          }
          setStatusMessage(`Selected marking #${meta.expertMarkingId}`);
          return;
        }
        const pickedHandle = meta.handle || "";
        const layer = meta.layer || "";
        if (pickedHandle) {
          selectEntityCandidate(candidateFromHandle(pickedHandle, "raycast", 0), { additive: event.ctrlKey || event.metaKey });
        }
        if (layer) {
          selectLayer(layer);
        }
      } else {
        const geometryCandidates = geometryCandidatesAt(worldPoint);
        if (geometryCandidates.length > 1 && !event.ctrlKey && !event.metaKey) {
          setPickCandidates({
            x: event.clientX - left,
            y: event.clientY - top,
            candidates: geometryCandidates.slice(0, 6),
          });
          return;
        }
        if (geometryCandidates.length) {
          selectEntityCandidate(geometryCandidates[0], { additive: event.ctrlKey || event.metaKey });
          return;
        }
        setPickCandidates(null);
      }
    };

    const onPointerMove = (event) => {
      if (updateShiftPan(event)) {
        return;
      }
      const mode = drawingModeRef.current;
      if (mode === "select") {
        const world = getWorldPoint(event);
        const hoverCandidate = world ? geometryCandidatesAt(world)[0] : null;
        const nextHandle = hoverCandidate?.handle || "";
        if (nextHandle !== hoveredEntityHandleRef.current) {
          setHoveredEntityHandle(nextHandle);
        }
        canvas.style.cursor = nextHandle ? "pointer" : "";
        return;
      }
      if (!["polygon", "polyline", "rectangle"].includes(mode)) return;
      const worldPoint = getWorldPoint(event);
      if (!worldPoint) return;
      updateDrawingCursor(worldPoint);
    };

    const onPointerUp = (event) => {
      endShiftPan(event);
    };

    const onDblClick = () => {
      const mode = drawingModeRef.current;
      if (mode === "polygon" || mode === "polyline") {
        finishCurrentShape();
      }
    };
    const onContextMenu = (event) => event.preventDefault();

    window.addEventListener("resize", scheduleResize);
    canvas.addEventListener("pointerdown", onPointerDown);
    canvas.addEventListener("pointermove", onPointerMove);
    canvas.addEventListener("pointerup", onPointerUp);
    canvas.addEventListener("pointercancel", onPointerUp);
    canvas.addEventListener("dblclick", onDblClick);
    canvas.addEventListener("contextmenu", onContextMenu);
    if (canvas.parentElement && typeof ResizeObserver !== "undefined") {
      const observer = new ResizeObserver(() => scheduleResize());
      observer.observe(canvas.parentElement);
      resizeObserverRef.current = observer;
    }
    scheduleResize();

    return () => {
      window.removeEventListener("resize", scheduleResize);
      canvas.removeEventListener("pointerdown", onPointerDown);
      canvas.removeEventListener("pointermove", onPointerMove);
      canvas.removeEventListener("pointerup", onPointerUp);
      canvas.removeEventListener("pointercancel", onPointerUp);
      canvas.removeEventListener("dblclick", onDblClick);
      canvas.removeEventListener("contextmenu", onContextMenu);
      if (resizeRafRef.current) cancelAnimationFrame(resizeRafRef.current);
      resizeRafRef.current = 0;
      resizeObserverRef.current?.disconnect();
      resizeObserverRef.current = null;
      controls.dispose();
      renderer.dispose();
    };
  }

  function getWorldPoint(event) {
    const canvas = canvasRef.current;
    const camera = cameraRef.current;
    const raycaster = raycasterRef.current;
    if (!canvas || !camera || !raycaster) return null;
    const { left, top, width, height } = canvas.getBoundingClientRect();
    const x = ((event.clientX - left) / width) * 2 - 1;
    const y = -((event.clientY - top) / height) * 2 + 1;
    raycaster.setFromCamera({ x, y }, camera);
    const plane = new THREE.Plane(new THREE.Vector3(0, 0, 1), 0);
    const hit = new THREE.Vector3();
    const hasHit = raycaster.ray.intersectPlane(plane, hit);
    if (!hasHit) return null;
    return { x: hit.x, y: hit.y };
  }

  function calculateDistance(a, b) {
    if (!a || !b) return 0;
    return Math.hypot((b.x || 0) - (a.x || 0), (b.y || 0) - (a.y || 0));
  }

  function calculatePolylineLength(points) {
    if (!Array.isArray(points) || points.length < 2) return 0;
    let sum = 0;
    for (let i = 1; i < points.length; i += 1) {
      sum += calculateDistance(points[i - 1], points[i]);
    }
    return sum;
  }

  function calculatePolygonArea(points) {
    if (!Array.isArray(points) || points.length < 3) return 0;
    let s = 0;
    for (let i = 0; i < points.length; i += 1) {
      const j = (i + 1) % points.length;
      s += ((points[i].x || 0) * (points[j].y || 0)) - ((points[j].x || 0) * (points[i].y || 0));
    }
    return Math.abs(s) / 2;
  }

  function calculatePolygonPerimeter(points) {
    if (!Array.isArray(points) || points.length < 3) return 0;
    return calculatePolylineLength(points) + calculateDistance(points[points.length - 1], points[0]);
  }

  function calculateBBox(points) {
    if (!Array.isArray(points) || !points.length) return { minX: 0, minY: 0, maxX: 0, maxY: 0, width: 0, height: 0 };
    const xs = points.map((p) => Number(p.x || 0));
    const ys = points.map((p) => Number(p.y || 0));
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    const minY = Math.min(...ys);
    const maxY = Math.max(...ys);
    return { minX, minY, maxX, maxY, width: maxX - minX, height: maxY - minY };
  }

  function calculateRectangleMeasurement(points) {
    const bbox = calculateBBox(points);
    return {
      width: bbox.width,
      height: bbox.height,
      area: bbox.width * bbox.height,
      perimeter: (2 * bbox.width) + (2 * bbox.height),
    };
  }

  function isPolygonClosed(points) {
    return Array.isArray(points) && points.length >= 3;
  }

  function measurementForDrawing(mode, points) {
    const bbox = calculateBBox(points);
    const out = {
      area: null,
      perimeter: null,
      length: null,
      width: bbox.width,
      height: bbox.height,
      unit: "drawing_units",
      point_count: points.length,
    };
    if (mode === "polygon") {
      out.area = calculatePolygonArea(points);
      out.perimeter = calculatePolygonPerimeter(points);
    } else if (mode === "polyline") {
      out.length = calculatePolylineLength(points);
    } else if (mode === "rectangle") {
      const m = calculateRectangleMeasurement(points);
      out.area = m.area;
      out.perimeter = m.perimeter;
      out.width = m.width;
      out.height = m.height;
    }
    return out;
  }

  function render() {
    const renderer = rendererRef.current;
    const scene = sceneRef.current;
    const camera = cameraRef.current;
    if (renderer && scene && camera) {
      resizeCadTextOverlays();
      renderer.render(scene, camera);
    }
  }

  function ensureLayerData(layer) {
    if (!layerSegmentsRef.current[layer]) {
      layerSegmentsRef.current[layer] = [];
      layerBoundsRef.current[layer] = createBounds();
    }
    if (!layerGroupsRef.current[layer]) {
      const g = new THREE.Group();
      g.name = layer;
      g.userData.layer = layer;
      layerGroupsRef.current[layer] = g;
      sceneRef.current.add(g);
    }
  }

  function pushSegment(layer, p1, p2) {
    if (!isValidPoint(p1) || !isValidPoint(p2)) return 0;
    ensureLayerData(layer);
    const arr = layerSegmentsRef.current[layer];
    arr.push(p1.x, p1.y, 0, p2.x, p2.y, 0);
    updateBounds(layerBoundsRef.current[layer], p1);
    updateBounds(layerBoundsRef.current[layer], p2);
    return 1;
  }

  function addLine(layer, p1, p2) {
    const added = pushSegment(layer, p1, p2);
    if (added) statsRef.current.lines += 1;
    return added;
  }

  function addPolyline(layer, pts, closed) {
    const filtered = coercePoints(pts).filter(isValidPoint);
    if (filtered.length < 2) return 0;
    let count = 0;
    for (let i = 0; i < filtered.length - 1; i += 1) {
      count += pushSegment(layer, filtered[i], filtered[i + 1]);
    }
    if (closed) {
      count += pushSegment(layer, filtered[filtered.length - 1], filtered[0]);
    }
    statsRef.current.polylines += 1;
    return count;
  }

  function computeBbox() {
    const objectBbox = computeVisibleEntityBbox();
    if (objectBbox) return objectBbox;

    let merged = null;
    for (const [layer, bounds] of Object.entries(layerBoundsRef.current)) {
      const group = layerGroupsRef.current[layer];
      const meta = layerMetaRef.current[layer] || { visible: true };
      const visible = group ? group.visible : meta.visible;
      if (!visible) continue;
      merged = mergeBounds(merged, bounds);
    }
    if (!merged) return null;
    return new THREE.Box3(
      new THREE.Vector3(merged.minX, merged.minY, 0),
      new THREE.Vector3(merged.maxX, merged.maxY, 0)
    );
  }

  function visibleEntityCount() {
    let count = 0;
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      if (obj?.visible) count += 1;
    }

    return count;
  }

  function forceMappedEntitiesVisible() {
    let count = 0;
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      const mapped = !!(
        obj?.userData?.semanticEntity ||
        obj?.userData?.semantic_entity ||
        ["expert_verified", "manual_mapped", "auto_mapped"].includes(obj?.userData?.mappingStatus) ||
        ["expert_verified", "manual_mapped", "auto_mapped"].includes(obj?.userData?.mapping_status)
      );
      obj.visible = mapped;
      if (mapped) {
        count += 1;
        if (obj.parent) obj.parent.visible = true;
      }
    }

    return count;
  }

  function computeVisibleEntityBbox() {
    let merged = null;
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      if (!obj?.visible || !obj.geometry) continue;
      obj.geometry.computeBoundingBox();
      const bbox = obj.geometry.boundingBox;
      if (!bbox || !Number.isFinite(bbox.min.x) || !Number.isFinite(bbox.max.x)) continue;
      const bounds = {
        minX: bbox.min.x,
        minY: bbox.min.y,
        maxX: bbox.max.x,
        maxY: bbox.max.y,
      };
      merged = mergeBounds(merged, bounds);
    }

    if (!merged) return null;
    return new THREE.Box3(
      new THREE.Vector3(merged.minX, merged.minY, 0),
      new THREE.Vector3(merged.maxX, merged.maxY, 0)
    );
  }

  function computeTrimmedBbox() {
    const xs = [];
    const ys = [];
    for (const [layer, positions] of Object.entries(layerSegmentsRef.current)) {
      const group = layerGroupsRef.current[layer];
      const meta = layerMetaRef.current[layer] || { visible: true };
      const visible = group ? group.visible : meta.visible;
      if (!visible || !positions || positions.length < 6) continue;
      const pointCount = Math.floor(positions.length / 3);
      if (!pointCount) continue;
      const stride = Math.max(1, Math.floor(pointCount / MAX_SAMPLE_POINTS_PER_LAYER));
      for (let i = 0; i < pointCount; i += stride) {
        const idx = i * 3;
        const x = positions[idx];
        const y = positions[idx + 1];
        if (Number.isFinite(x) && Number.isFinite(y)) {
          xs.push(x);
          ys.push(y);
        }
      }
    }
    if (xs.length < 8 || ys.length < 8) return null;
    xs.sort((a, b) => a - b);
    ys.sort((a, b) => a - b);
    const minX = percentile(xs, TRIM_PERCENTILE);
    const maxX = percentile(xs, 1 - TRIM_PERCENTILE);
    const minY = percentile(ys, TRIM_PERCENTILE);
    const maxY = percentile(ys, 1 - TRIM_PERCENTILE);
    if (![minX, maxX, minY, maxY].every(Number.isFinite)) return null;
    if (minX === maxX || minY === maxY) return null;
    return new THREE.Box3(
      new THREE.Vector3(minX, minY, 0),
      new THREE.Vector3(maxX, maxY, 0)
    );
  }

  function computeDenseBbox() {
    const xs = [];
    const ys = [];
    const points = [];
    for (const [layer, positions] of Object.entries(layerSegmentsRef.current)) {
      const group = layerGroupsRef.current[layer];
      const meta = layerMetaRef.current[layer] || { visible: true };
      const visible = group ? group.visible : meta.visible;
      if (!visible || !positions || positions.length < 6) continue;
      const pointCount = Math.floor(positions.length / 3);
      if (!pointCount) continue;
      const stride = Math.max(1, Math.floor(pointCount / MAX_SAMPLE_POINTS_PER_LAYER));
      for (let i = 0; i < pointCount; i += stride) {
        const idx = i * 3;
        const x = positions[idx];
        const y = positions[idx + 1];
        if (Number.isFinite(x) && Number.isFinite(y)) {
          xs.push(x);
          ys.push(y);
          if (points.length < MAX_DENSE_SAMPLE_POINTS) {
            points.push({ x, y });
          }
        }
      }
    }
    if (xs.length < MIN_CLUSTER_POINTS || ys.length < MIN_CLUSTER_POINTS) return null;
    xs.sort((a, b) => a - b);
    ys.sort((a, b) => a - b);
    const medX = median(xs);
    const medY = median(ys);
    if (!Number.isFinite(medX) || !Number.isFinite(medY)) return null;
    const distances = [];
    for (const p of points) {
      const dx = p.x - medX;
      const dy = p.y - medY;
      distances.push(dx * dx + dy * dy);
    }
    const sortedDistances = [...distances].sort((a, b) => a - b);
    const cutoff = percentile(sortedDistances, DENSE_DISTANCE_PERCENTILE);
    if (!Number.isFinite(cutoff) || cutoff <= 0) return null;
    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;
    let inliers = 0;
    for (let i = 0; i < points.length; i += 1) {
      if (distances[i] > cutoff) continue;
      const p = points[i];
      minX = Math.min(minX, p.x);
      minY = Math.min(minY, p.y);
      maxX = Math.max(maxX, p.x);
      maxY = Math.max(maxY, p.y);
      inliers += 1;
    }
    if (inliers < MIN_CLUSTER_POINTS) return null;
    return new THREE.Box3(
      new THREE.Vector3(minX, minY, 0),
      new THREE.Vector3(maxX, maxY, 0)
    );
  }

  function computeDominantLayerBbox() {
    const layers = [];
    let totalSegments = 0;
    for (const [layer, positions] of Object.entries(layerSegmentsRef.current)) {
      const group = layerGroupsRef.current[layer];
      const meta = layerMetaRef.current[layer] || { visible: true };
      const visible = group ? group.visible : meta.visible;
      if (!visible || !positions || positions.length < 6) continue;
      const segments = Math.floor(positions.length / 6);
      if (segments <= 0) continue;
      totalSegments += segments;
      layers.push({ layer, segments });
    }
    if (!layers.length || totalSegments <= 0) return null;
    layers.sort((a, b) => b.segments - a.segments);
    let covered = 0;
    let added = 0;
    let merged = null;
    for (const item of layers) {
      const bounds = layerBoundsRef.current[item.layer];
      if (!hasBounds(bounds)) continue;
      merged = mergeBounds(merged, bounds);
      covered += item.segments;
      added += 1;
      if (covered / totalSegments >= DOMINANT_LAYER_COVERAGE) break;
      if (added >= MAX_DOMINANT_LAYERS && covered > 0) break;
    }
    if (!merged) return null;
    return new THREE.Box3(
      new THREE.Vector3(merged.minX, merged.minY, 0),
      new THREE.Vector3(merged.maxX, merged.maxY, 0)
    );
  }

  function computeFloorFocusBbox(floorContext) {
    const textMatches = (textEntitiesRef.current || []).filter((item) => {
      const text = normalizeDxfText(item?.text || "");
      return text && floorRegexForContext(floorContext).test(text);
    });
    if (!textMatches.length) return null;

    const textPoints = textMatches
      .filter((item) => Number.isFinite(item.x) && Number.isFinite(item.y))
      .map((item) => ({ x: item.x, y: item.y }));
    if (!textPoints.length) return null;

    let minX = Infinity;
    let minY = Infinity;
    let maxX = -Infinity;
    let maxY = -Infinity;

    const allBounds = [];
    for (const [layer, bounds] of Object.entries(layerBoundsRef.current || {})) {
      if (!hasBounds(bounds)) continue;
      const centerX = (bounds.minX + bounds.maxX) / 2;
      const centerY = (bounds.minY + bounds.maxY) / 2;
      let bestDistance = Infinity;
      for (const point of textPoints) {
        const dx = centerX - point.x;
        const dy = centerY - point.y;
        const distance = Math.sqrt((dx * dx) + (dy * dy));
        if (distance < bestDistance) bestDistance = distance;
      }
      allBounds.push({ layer, bounds, distance: bestDistance });
    }

    if (!allBounds.length) return null;
    allBounds.sort((a, b) => a.distance - b.distance);

    const baseDistance = Number.isFinite(allBounds[0].distance) ? allBounds[0].distance : 0;
    const radius = Math.max(baseDistance * 1.75, 5000);

    for (const item of allBounds) {
      if (!Number.isFinite(item.distance) || item.distance > radius) continue;
      minX = Math.min(minX, item.bounds.minX);
      minY = Math.min(minY, item.bounds.minY);
      maxX = Math.max(maxX, item.bounds.maxX);
      maxY = Math.max(maxY, item.bounds.maxY);
    }

    if (![minX, minY, maxX, maxY].every(Number.isFinite)) return null;
    if (minX >= maxX || minY >= maxY) return null;

    return new THREE.Box3(
      new THREE.Vector3(minX, minY, 0),
      new THREE.Vector3(maxX, maxY, 0)
    );
  }

  function fitView() {
    if (!visibleEntityCount() && forceMappedEntitiesVisible() > 0) {
      setStatusMessage("Showing mapped approval geometry. Raw CAD layers are hidden.");
    }
    const fullBbox = computeBbox();
    if (!fullBbox || !Number.isFinite(fullBbox.min.x) || !Number.isFinite(fullBbox.max.x)) return;
    const floorFocusBbox = floorContext ? computeFloorFocusBbox(floorContext) : null;
    const trimmedBbox = computeTrimmedBbox();
    const denseBbox = computeDenseBbox();
    const dominantBbox = computeDominantLayerBbox();
    let bbox = floorFocusBbox || fullBbox;
    let source = floorFocusBbox ? "floor-focus" : "full";
    const fullSize = new THREE.Vector3();
    fullBbox.getSize(fullSize);
    const fullSpan = Math.max(fullSize.x, fullSize.y);
    let floorSpan = null;
    let dominantSpan = null;
    let trimmedSpan = null;
    let denseSpan = null;
    if (floorFocusBbox) {
      const floorSize = new THREE.Vector3();
      floorFocusBbox.getSize(floorSize);
      floorSpan = Math.max(floorSize.x, floorSize.y);
    }
    if (dominantBbox) {
      const dominantSize = new THREE.Vector3();
      dominantBbox.getSize(dominantSize);
      dominantSpan = Math.max(dominantSize.x, dominantSize.y);
    }
    if (trimmedBbox) {
      const trimmedSize = new THREE.Vector3();
      trimmedBbox.getSize(trimmedSize);
      trimmedSpan = Math.max(trimmedSize.x, trimmedSize.y);
    }
    if (denseBbox) {
      const denseSize = new THREE.Vector3();
      denseBbox.getSize(denseSize);
      denseSpan = Math.max(denseSize.x, denseSize.y);
    }
    if (denseBbox && Number.isFinite(denseSpan)) {
      bbox = denseBbox;
      source = "dense";
    } else if (trimmedBbox && Number.isFinite(trimmedSpan)) {
      bbox = trimmedBbox;
      source = "trimmed";
    } else if (dominantBbox && Number.isFinite(dominantSpan)) {
      bbox = dominantBbox;
      source = "dominant";
    } else if (Number.isFinite(dominantSpan) && dominantSpan > 0 && (fullSpan / dominantSpan) > OUTLIER_RATIO_THRESHOLD) {
      bbox = dominantBbox;
      source = "dominant";
    } else if (floorFocusBbox && Number.isFinite(floorSpan)) {
      bbox = floorFocusBbox;
      source = "floor-focus";
    }
    fitInfoRef.current = { source, fullSpan, dominantSpan, trimmedSpan, denseSpan, floorSpan };
    if (lastSizeRef.current.w <= 0 || lastSizeRef.current.h <= 0) {
      resizeFnRef.current?.();
    }
    if (lastSizeRef.current.w <= 0 || lastSizeRef.current.h <= 0) return;
    const size = new THREE.Vector3();
    const center = new THREE.Vector3();
    bbox.getSize(size);
    bbox.getCenter(center);

    const margin = 1.02;
    const w = size.x * margin;
    const h = size.y * margin;
    const aspect = lastSizeRef.current.h > 0
      ? (lastSizeRef.current.w / lastSizeRef.current.h)
      : 1;
    const viewW = Math.max(w, h * aspect) / FIT_ZOOM;
    const viewH = Math.max(h, w / aspect) / FIT_ZOOM;
    const camera = cameraRef.current;
    if (!camera) return;
    camera.left = -viewW / 2;
    camera.right = viewW / 2;
    camera.top = viewH / 2;
    camera.bottom = -viewH / 2;
    camera.near = 0.1;
    camera.far = 10000000;
    camera.position.set(center.x, center.y, Math.max(1000, size.x, size.y));
    camera.updateProjectionMatrix();
    camera.lookAt(center.x, center.y, 0);
    camera.updateMatrixWorld();
    controlsRef.current?.target.set(center.x, center.y, 0);
    controlsRef.current?.update();
    render();
    if (dxfBytesRef.current) {
      const fitInfo = fitInfoRef.current || {};
      const spanText = Number.isFinite(fitInfo.fullSpan)
        ? ` · Fit: ${fitInfo.source} (full ${Math.round(fitInfo.fullSpan)}${Number.isFinite(fitInfo.denseSpan) ? `, dense ${Math.round(fitInfo.denseSpan)}` : ""}${Number.isFinite(fitInfo.dominantSpan) ? `, dom ${Math.round(fitInfo.dominantSpan)}` : ""}${Number.isFinite(fitInfo.trimmedSpan) ? `, trim ${Math.round(fitInfo.trimmedSpan)}` : ""})`
        : "";
      setSummaryText(
        `Entities: ${statsRef.current.entities} · Lines: ${statsRef.current.lines} · Polylines: ${statsRef.current.polylines} · Text: ${textEntitiesRef.current.length} · Size: ${lastSizeRef.current.w}x${lastSizeRef.current.h} · DXF bytes: ${dxfBytesRef.current}${spanText}`
      );
    }
  }

  function zoomBy(factor) {
    const camera = cameraRef.current;
    if (!camera) return;
    const nextZoom = Math.min(20, Math.max(0.2, camera.zoom * factor));
    camera.zoom = nextZoom;
    camera.updateProjectionMatrix();
    controlsRef.current?.update();
    render();
  }

  function resetMeasure() {
    measurePointsRef.current = [];
    setMeasurePoints([]);
    setMeasureDistance(null);
    updateMeasureLine([]);
  }

  function toggleMeasureMode() {
    const next = !measureMode;
    setMeasureMode(next);
    resetMeasure();
  }

  function useMeasuredDistanceForRule() {
    if (!Number.isFinite(scaledDistance)) return;
    setMeasuredValue(scaledDistance.toFixed(3));
    setNotes((existing) => {
      const suffix = `Measured in CAD viewer: raw ${rawDistanceLabel}, scaled ${scaledDistance.toFixed(3)} (${scaleLabel}).`;
      return existing ? `${existing}\n${suffix}` : suffix;
    });
    setChatbotStatus("Measured value copied to the selected rule.");
  }

  function updateMeasureLine(points) {
    const scene = sceneRef.current;
    if (!scene) return;
    if (!points || points.length < 2) {
      if (measureLineRef.current) {
        scene.remove(measureLineRef.current);
        measureLineRef.current.geometry?.dispose();
        measureLineRef.current.material?.dispose();
        measureLineRef.current = null;
        render();
      }
      return;
    }
    const positions = new Float32Array([
      points[0].x, points[0].y, 0,
      points[1].x, points[1].y, 0,
    ]);
    if (!measureLineRef.current) {
      const geom = new THREE.BufferGeometry();
      geom.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
      const mat = new THREE.LineBasicMaterial({ color: 0xe0483f, linewidth: 3 });
      const line = new THREE.Line(geom, mat);
      line.frustumCulled = false;
      line.userData.measure = true;
      measureLineRef.current = line;
      scene.add(line);
    } else {
      const geom = measureLineRef.current.geometry;
      geom.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
      geom.attributes.position.needsUpdate = true;
      geom.computeBoundingSphere();
    }
    render();
  }

  function clearDrawingPreview() {
    const scene = sceneRef.current;
    if (!scene) return;
    if (drawingPreviewRef.current) {
      scene.remove(drawingPreviewRef.current);
      drawingPreviewRef.current.geometry?.dispose();
      drawingPreviewRef.current.material?.dispose();
      drawingPreviewRef.current = null;
    }
    if (drawingCursorRef.current) {
      scene.remove(drawingCursorRef.current);
      drawingCursorRef.current.geometry?.dispose();
      drawingCursorRef.current.material?.dispose();
      drawingCursorRef.current = null;
    }
    render();
  }

  function updateDrawingCursor(point) {
    const scene = sceneRef.current;
    if (!scene || !point) return;
    const geom = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(point.x, point.y, 0)]);
    if (!drawingCursorRef.current) {
      const mat = new THREE.PointsMaterial({ color: 0xe0483f, size: 4, sizeAttenuation: false });
      drawingCursorRef.current = new THREE.Points(geom, mat);
      scene.add(drawingCursorRef.current);
    } else {
      drawingCursorRef.current.geometry.dispose();
      drawingCursorRef.current.geometry = geom;
    }
    render();
  }

  function updateDrawingPreview(points, mode) {
    const scene = sceneRef.current;
    if (!scene) return;
    if (drawingPreviewRef.current) {
      scene.remove(drawingPreviewRef.current);
      drawingPreviewRef.current.geometry?.dispose();
      drawingPreviewRef.current.material?.dispose();
      drawingPreviewRef.current = null;
    }
    if (!points?.length) return;
    if (mode === "point") {
      const geom = new THREE.BufferGeometry().setFromPoints(points.map((p) => new THREE.Vector3(p.x, p.y, 0)));
      const mat = new THREE.PointsMaterial({ color: 0x0b3d91, size: 5, sizeAttenuation: false });
      drawingPreviewRef.current = new THREE.Points(geom, mat);
      scene.add(drawingPreviewRef.current);
      render();
      return;
    }
    const work = [...points];
    if (mode === "polygon" && points.length > 2) work.push(points[0]);
    const geom = new THREE.BufferGeometry().setFromPoints(work.map((p) => new THREE.Vector3(p.x, p.y, 0)));
    const mat = new THREE.LineBasicMaterial({ color: 0x0b3d91 });
    drawingPreviewRef.current = new THREE.Line(geom, mat);
    scene.add(drawingPreviewRef.current);
    render();
  }

  function undoLastPoint() {
    if (!currentPoints.length) return;
    const next = currentPoints.slice(0, -1);
    setCurrentPoints(next);
    setCurrentMeasurement(next.length ? measurementForDrawing(drawingMode, next) : null);
    if (!next.length) clearDrawingPreview();
    else updateDrawingPreview(next, drawingMode);
  }

  function clearCurrentDrawing() {
    setCurrentPoints([]);
    setCurrentMeasurement(null);
    clearDrawingPreview();
  }

  function finishCurrentShape(pointsArg = null, modeArg = null) {
    const mode = modeArg || drawingModeRef.current;
    const points = pointsArg || currentPointsRef.current || [];
    if (mode === "polygon" && points.length < 3) {
      setStatusMessage("Polygon needs at least 3 points.");
      return;
    }
    if (mode === "polyline" && points.length < 2) {
      setStatusMessage("Polyline needs at least 2 points.");
      return;
    }
    if (mode === "rectangle" && points.length < 4) {
      setStatusMessage("Rectangle needs 2 clicks.");
      return;
    }
    if (mode === "point" && points.length < 1) {
      setStatusMessage("Point mode needs one click.");
      return;
    }
    setCurrentPoints(points);
    setCurrentMeasurement(measurementForDrawing(mode, points));
    updateDrawingPreview(points, mode);
    setStatusMessage("Shape finished. Click Save draft or Save/Confirm label.");
  }

  async function saveCurrentDrawing(confirmNow = false, pointsArg = null, modeArg = null) {
    const points = pointsArg || currentPointsRef.current || [];
    const mode = modeArg || drawingModeRef.current;
    const labelKey = activeLabelKeyRef.current;
    if (!labelKey || !mode || !points.length || !config.expertMarkingsStoreUrl) return;
    const body = {
      label_key: labelKey,
      label_name: (labelsCatalog.find((l) => l.label_key === labelKey)?.label_name) || labelKey,
      geometry_type: mode === "select" ? "polygon" : mode,
      points_json: points,
      measurement_json: currentMeasurement || measurementForDrawing(mode, points),
      status: confirmNow ? "confirmed" : "draft",
    };
    try {
      const response = await fetch(config.expertMarkingsStoreUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify(body),
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Failed to save expert marking.");
        return;
      }
      setStatusMessage(payload.message || "Expert marking saved.");
      clearCurrentDrawing();
      await Promise.all([loadExpertMarkings(), loadExpertMarkingReport()]);
    } catch {
      setStatusMessage("Failed to save expert marking.");
    }
  }

  function selectedLearningBounds() {
    const boundsList = selectedCadEntities.map(entityBounds).filter(Boolean);
    if (boundsList.length) {
      return boundsList.reduce((all, bounds) => ({
        minX: Math.min(all.minX, bounds.minX), minY: Math.min(all.minY, bounds.minY),
        maxX: Math.max(all.maxX, bounds.maxX), maxY: Math.max(all.maxY, bounds.maxY),
      }), { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity });
    }
    if (currentPoints.length) {
      return currentPoints.reduce((all, point) => ({
        minX: Math.min(all.minX, point.x), minY: Math.min(all.minY, point.y),
        maxX: Math.max(all.maxX, point.x), maxY: Math.max(all.maxY, point.y),
      }), { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity });
    }
    return null;
  }

  function captureLearningRegion() {
    const canvas = canvasRef.current;
    const camera = cameraRef.current;
    const bounds = selectedLearningBounds();
    if (!canvas || !camera || !bounds) {
      setStatusMessage("Select one or more CAD entities, or draw a rectangle around the region first.");
      return;
    }
    render();
    camera.updateMatrixWorld();
    const rect = canvas.getBoundingClientRect();
    const corners = [
      new THREE.Vector3(bounds.minX, bounds.minY, 0), new THREE.Vector3(bounds.maxX, bounds.minY, 0),
      new THREE.Vector3(bounds.maxX, bounds.maxY, 0), new THREE.Vector3(bounds.minX, bounds.maxY, 0),
    ].map((point) => {
      point.project(camera);
      return { x: ((point.x + 1) / 2) * rect.width, y: ((1 - point.y) / 2) * rect.height };
    });
    const margin = 28;
    const left = Math.max(0, Math.min(...corners.map((point) => point.x)) - margin);
    const top = Math.max(0, Math.min(...corners.map((point) => point.y)) - margin);
    const right = Math.min(rect.width, Math.max(...corners.map((point) => point.x)) + margin);
    const bottom = Math.min(rect.height, Math.max(...corners.map((point) => point.y)) + margin);
    if (right - left < 4 || bottom - top < 4) {
      setStatusMessage("The selected region is too small to capture. Zoom closer or select a larger region.");
      return;
    }
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    const crop = document.createElement("canvas");
    crop.width = Math.max(1, Math.round((right - left) * scaleX));
    crop.height = Math.max(1, Math.round((bottom - top) * scaleY));
    crop.getContext("2d").drawImage(
      canvas, left * scaleX, top * scaleY, crop.width, crop.height,
      0, 0, crop.width, crop.height
    );
    setLearningSnapshot(crop.toDataURL("image/png"));
    setLearningRegionPoints([
      { x: bounds.minX, y: bounds.minY }, { x: bounds.maxX, y: bounds.minY },
      { x: bounds.maxX, y: bounds.maxY }, { x: bounds.minX, y: bounds.maxY },
    ]);
    if (!learningMeasuredValue && Number(selectedMeasurementSummary.length) > 0) {
      setLearningMeasuredValue(Number(selectedMeasurementSummary.length).toFixed(2));
    }
    setStatusMessage("Selected CAD region captured as a PNG preview. Add the observation and save it.");
  }

  async function saveLearningExample() {
    if (!config.expertMarkingsStoreUrl || savingLearningExample) return;
    if (!learningSnapshot || !learningRegionPoints.length) {
      setStatusMessage("Capture the selected CAD region before saving the learning example.");
      return;
    }
    if (!learningLabel || !learningNotes.trim()) {
      setStatusMessage("Choose what the region represents and add an officer note.");
      return;
    }
    setSavingLearningExample(true);
    const selectedLayersForExample = [...new Set(selectedCadEntities.map((entity) => entity.layer_name).filter(Boolean))];
    const measurement = measurementForDrawing("rectangle", learningRegionPoints);
    try {
      const response = await fetch(config.expertMarkingsStoreUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
        body: JSON.stringify({
          label_key: learningLabel,
          label_name: resolveTagLabel(learningLabel, tagOptions),
          geometry_type: "rectangle",
          points_json: learningRegionPoints,
          measurement_json: measurement,
          status: "confirmed",
          snapshot_data_url: learningSnapshot,
          selected_handles_json: selectedEntityHandles,
          facts_json: {
            observation_type: learningLabel,
            count: learningCount === "" ? null : Number(learningCount),
            measured_value: learningMeasuredValue === "" ? null : Number(learningMeasuredValue),
            unit: learningUnit || null,
            expected_value: learningExpectedValue || null,
            selected_layers: selectedLayersForExample,
            floor: floorContext,
            map_drawing_id: config.mapDrawingId || null,
            ai_text_evidence: learningSourceText ? {
              raw_text: learningSourceText.text,
              cad_layer: learningSourceText.layer,
              cad_handle: learningSourceText.handle,
              x: learningSourceText.x,
              y: learningSourceText.y,
              parsed_value_ft: learningSourceText.value_ft,
              semantic_hints: learningSourceText.semantic_hints || [],
              officer_verified: true,
            } : null,
          },
          rule_code: learningRuleCode || null,
          compliance_status: learningCompliance,
          remarks: learningNotes.trim(),
        }),
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || `Save failed (${response.status})`);
      await Promise.all([loadExpertMarkings(), loadExpertMarkingReport()]);
      setLearningSnapshot(""); setLearningRegionPoints([]); setLearningCount("");
      setLearningMeasuredValue(""); setLearningExpectedValue(""); setLearningRuleCode("");
      setLearningCompliance("needs_review"); setLearningNotes("");
      setLearningSourceText(null);
      setStatusMessage("Learning example saved with its PNG region, structured facts, rule status, and officer note.");
    } catch (error) {
      setStatusMessage(error.message || "Could not save the learning example.");
    } finally {
      setSavingLearningExample(false);
    }
  }

  async function deleteExpertMarking(markingId) {
    if (!markingId || !config.expertMarkingsDeleteUrlTemplate) return;
    const url = config.expertMarkingsDeleteUrlTemplate.replace("__MARKING_ID__", String(markingId));
    const response = await fetch(url, { method: "DELETE", headers: { Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken } });
    if (response.ok) {
      await Promise.all([loadExpertMarkings(), loadExpertMarkingReport()]);
    }
  }

  async function confirmExpertMarking(markingId) {
    if (!markingId || !config.expertMarkingsConfirmUrlTemplate) return;
    const url = config.expertMarkingsConfirmUrlTemplate.replace("__MARKING_ID__", String(markingId));
    const response = await fetch(url, { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken } });
    if (response.ok) {
      await Promise.all([loadExpertMarkings(), loadExpertMarkingReport()]);
    }
  }

  function evaluateRule(rule, measured) {
    if (!rule || measured === "" || measured == null) return null;
    const required = Number(rule.required_value);
    const measuredNum = Number(measured);
    if (!Number.isFinite(required) || !Number.isFinite(measuredNum)) return null;
    switch (rule.operator) {
      case ">=":
        return measuredNum >= required;
      case "<=":
        return measuredNum <= required;
      case ">":
        return measuredNum > required;
      case "<":
        return measuredNum < required;
      case "==":
        return measuredNum === required;
      default:
        return null;
    }
  }

  async function saveExpertResult(event = null) {
    if (event?.preventDefault) event.preventDefault();
    if (!selectedRuleId || measuredValue === "") return;
    if (measurePoints.length !== 2 || !Number.isFinite(measureDistance) || !Number.isFinite(scaledDistance)) {
      setStatusMessage("Measure two points in the CAD view before saving an expert result.");
      return;
    }
    setSavingResult(true);
    setStatusMessage("");
    try {
      const response = await fetch(config.storeExpertResultUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
        body: JSON.stringify({
          rule_id: selectedRuleId,
          measured_value: measuredValue,
          system_measured_value: selectedSystemValue !== "" && Number.isFinite(Number(selectedSystemValue)) ? Number(selectedSystemValue) : null,
          measurement_points: measurePoints,
          raw_distance: measureDistance,
          scale_multiplier: Number.isFinite(scaleMultiplier) ? scaleMultiplier : 1,
          scale_label: scaleLabel,
          notes,
        }),
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Failed to save expert result.");
      } else {
        setStatusMessage(payload.message || "Expert result saved.");
        setChatbotStatus("Confirmed and saved to expert results.");
        if (payload.result) {
          setExpertResults((prev) => {
            const filtered = prev.filter((item) => item.rule_id !== payload.result.rule_id);
            const next = [...filtered, payload.result];
            return next.sort((a, b) => (a.id || 0) - (b.id || 0));
          });
        }
      }
    } catch (error) {
      setStatusMessage("Failed to save expert result.");
    } finally {
      setSavingResult(false);
    }
  }

  function applyAssistantSuggestion(kind) {
    if (!selectedRule) return;

    if (kind === "measured" && Number.isFinite(scaledDistance)) {
      const next = scaledDistance.toFixed(3);
      setMeasuredValue(next);
      setNotes((existing) => {
        const entry = `Assistant confirmed measured value ${next}${selectedRule.unit ? ` ${selectedRule.unit}` : ""} from CAD two-point measurement.`;
        return existing ? `${existing}\n${entry}` : entry;
      });
      setChatbotStatus("Suggestion confirmed: using measured CAD value.");
      return;
    }

    if (kind === "system") {
      const parsedSystem = Number(selectedSystemValue);
      if (Number.isFinite(parsedSystem)) {
        const next = parsedSystem.toFixed(3);
        setMeasuredValue(next);
        setNotes((existing) => {
          const entry = `Assistant confirmed system value ${next}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}.`;
          return existing ? `${existing}\n${entry}` : entry;
        });
        setChatbotStatus("Suggestion confirmed: using system-calculated value.");
      }
      return;
    }

    if (kind === "note") {
      const measured = Number(measuredValue);
      const decision = previewPass == null ? "manual review" : (previewPass ? "PASS" : "FAIL");
      const line = Number.isFinite(measured)
        ? `Assistant recommendation: ${decision}. Measured ${measured.toFixed(3)}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}; required ${selectedRule.operator} ${selectedRule.required_value}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}.`
        : `Assistant recommendation: ${decision}.`;
      setNotes((existing) => (existing ? `${existing}\n${line}` : line));
      setChatbotStatus("Suggestion note applied. You can now save the expert result.");
    }
  }

  async function askRuleAssistant() {
    const question = String(chatInput || "").trim();
    if (!question) return;
    const lower = question.toLowerCase();
    const selectedLabelName = labelsCatalog.find((l) => l.label_key === activeLabelKey)?.label_name || activeLabelKey || "None";
    const missing = Array.isArray(expertReport?.missing_required_label_details)
      ? expertReport.missing_required_label_details.map((r) => r.label_name)
      : [];
    const measuredNow = Number(measuredValue);
    const hasMeasuredNow = Number.isFinite(measuredNow);
    const hasScaledDistance = Number.isFinite(scaledDistance);
    const mappedCount = selectedEntityHandles.length;

    let reply = "I can help with rule check, mapped labels, and CAD measurement from this drawing.";

    if (lower.includes("distance") || lower.includes("measure")) {
      if (hasScaledDistance) {
        reply = `Auto distance is ${scaledDistance.toFixed(3)} (${scaleLabel}). Raw CAD distance is ${rawDistanceLabel}. Use \"Use value\" to send it to the selected rule.`;
      } else if (currentMeasurement) {
        reply = `Live geometry measurement: area ${Number(currentMeasurement.area || 0).toFixed(2)}, perimeter ${Number(currentMeasurement.perimeter || 0).toFixed(2)}, length ${Number(currentMeasurement.length || 0).toFixed(2)}.`;
      } else {
        reply = "No active two-point measurement yet. Turn on Measure and click two points on CAD.";
      }
    } else if (lower.includes("rule") || lower.includes("pass") || lower.includes("fail")) {
      if (!selectedRule) {
        reply = "Select a rule first in Expert measurement to evaluate pass/fail.";
      } else if (hasMeasuredNow) {
        const pass = evaluateRule(selectedRule, measuredNow);
        const status = pass == null ? "NEEDS REVIEW" : (pass ? "PASS" : "FAIL");
        reply = `${selectedRule.id}: ${status}. Required ${selectedRule.operator} ${selectedRule.required_value}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}, measured ${measuredNow.toFixed(3)}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}.`;
      } else if (hasScaledDistance) {
        reply = `${selectedRule.id} is selected. Auto measured distance ${scaledDistance.toFixed(3)} is ready; click "Use value" to evaluate pass/fail.`;
      } else {
        reply = `${selectedRule.id} is selected. Add measured value or run distance measurement first.`;
      }
    } else if (lower.includes("label") || lower.includes("mapping") || lower.includes("map")) {
      reply = `Active label: ${selectedLabelName}. Selected entities: ${mappedCount}. Selected summary length ${selectedMeasurementSummary.length.toFixed(2)}, area ${selectedMeasurementSummary.area.toFixed(2)}.`;
    } else if (lower.includes("missing") || lower.includes("issue") || lower.includes("required")) {
      reply = missing.length
        ? `Missing required labels: ${missing.slice(0, 8).join(", ")}.`
        : "No missing required labels in the latest report.";
    } else if (lower.includes("recommend")) {
      reply = `${assistantInsight.headline} ${assistantInsight.detail}`;
    }

    try {
      if (config.cadAssistantChatUrl) {
        const response = await fetch(config.cadAssistantChatUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": config.csrfToken,
          },
          body: JSON.stringify({
            question,
            map_drawing_id: config.mapDrawingId || null,
            context: {
              selected_label: selectedLabelName,
              selected_rule: selectedRule || null,
              selected_system_value: selectedSystemValue,
              measured_value: measuredValue,
              scaled_distance: Number.isFinite(scaledDistance) ? Number(scaledDistance) : null,
              selected_measurement_summary: selectedMeasurementSummary,
              chat_history: chatMessages.slice(-12),
            },
          }),
        });
        if (response.ok) {
          const payload = await response.json();
          if (payload?.reply) {
            reply = payload.reply;
          }
        }
      }
    } catch {
      // keep local fallback reply
    } finally {
      setChatMessages((prev) => ([
        ...prev,
        { role: "user", text: question },
        { role: "assistant", text: reply },
      ]));
      setChatInput("");
    }
  }

  async function runServerValidation() {
    if (!config.mapValidationUrl) return;
    setRunningValidation(true);
    setStatusMessage("");
    try {
      const response = await fetch(config.mapValidationUrl, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Validation failed.");
        setValidationReport(null);
      } else {
        setStatusMessage(`Validation completed. Status: ${payload.status || "validated"}`);
        setValidationReport(payload);
        if (config.mapSummaryUrl) {
          const summaryResponse = await fetch(config.mapSummaryUrl, { headers: { Accept: "application/json" } });
          if (summaryResponse.ok) {
            const summaryPayload = await summaryResponse.json();
            setMapSummary(summaryPayload.summary || null);
          }
        }
      }
    } catch {
      setStatusMessage("Validation failed.");
    } finally {
      setRunningValidation(false);
    }
  }

  async function mapSelectedEntitiesToActiveLabel() {
    if (!activeLabelKey || !selectedEntityHandles.length || !config.cadLabelMappingsStoreUrl) return;
    const selected = cadEntities.filter((item) => selectedEntityHandles.includes(item.handle));
    const ids = selected.map((item) => item.id).filter((id) => Number.isFinite(Number(id)));
    if (!ids.length) {
      setStatusMessage("Selected CAD handles are not available in cad_entities. Run semantic mapping first.");
      return;
    }
    setMappingBusy(true);
    setStatusMessage("");
    try {
      const activeLabel = labelsCatalog.find((item) => item.label_key === activeLabelKey);
      const response = await fetch(config.cadLabelMappingsStoreUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
        body: JSON.stringify({
          label_key: activeLabelKey,
          label_name: activeLabel?.label_name || activeLabelKey,
          cad_entity_ids: ids,
          source: "manual",
        }),
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Failed to map selected entities.");
      } else {
        setStatusMessage(payload.message || "Mapped selected entities.");
        await Promise.all([loadLabelsCatalog(), loadMappingReport()]);
      }
    } catch {
      setStatusMessage("Failed to map selected entities.");
    } finally {
      setMappingBusy(false);
    }
  }

  async function unmapMapping(mappingId) {
    if (!mappingId || !config.cadLabelMappingsDeleteUrlTemplate) return;
    const url = config.cadLabelMappingsDeleteUrlTemplate.replace("__MAPPING_ID__", String(mappingId));
    try {
      const response = await fetch(url, {
        method: "DELETE",
        headers: {
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Failed to remove mapping.");
      } else {
        setStatusMessage(payload.message || "Mapping removed.");
        await Promise.all([loadLabelsCatalog(), loadMappingReport()]);
      }
    } catch {
      setStatusMessage("Failed to remove mapping.");
    }
  }

  async function runAutoSuggestMappings() {
    if (!config.cadAutoSuggestMappingsUrl) return;
    setMappingBusy(true);
    try {
      const url = new URL(config.cadAutoSuggestMappingsUrl, window.location.origin);
      if (config.mapDrawingId) url.searchParams.set("map_drawing_id", String(config.mapDrawingId));
      const requestUrl = url.toString();
      const response = await fetch(requestUrl, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
      });
      let payload = {};
      try {
        payload = await response.json();
      } catch {
        payload = {};
      }
      if (!response.ok) {
        setStatusMessage(payload.message || `Auto-suggest failed (${response.status})`);
        return;
      }
      setStatusMessage(payload.message || "Auto-suggest completed.");
      await Promise.all([loadLabelsCatalog(), loadMappingReport()]);
    } catch {
      setStatusMessage("Auto-suggest failed.");
    } finally {
      setMappingBusy(false);
    }
  }

  async function loadExistingValidationReport() {
    if (!config.mapReportUrl) return;
    try {
      const response = await fetch(config.mapReportUrl, { headers: { Accept: "application/json" } });
      if (response.ok) {
        const payload = await response.json();
        if (payload && Array.isArray(payload.rules)) {
          setValidationReport(payload);
        }
      }
    } catch {
      // The viewer can still work without a preloaded report.
    }
  }

  async function loadLayerSuggestions() {
    if (!config.mapSuggestionsUrl) return;
    try {
      const response = await fetch(config.mapSuggestionsUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return;
      const payload = await response.json();
      const layers = payload.layers || {};
      setLayerSuggestions(layers);
      highlightTopSuggestions(layers);
      await autoApplyRequiredSuggestionRows(layers);
    } catch {
      // ignore
    }
  }

  async function autoApplyRequiredSuggestionRows(layers) {
    if (!layers || !config.mapManualMapUrl) return;
    const requiredRows = ["PLOT_BOUNDARY", "GROUND_FLOOR_FOOTPRINT"];
    for (const rowName of requiredRows) {
      if (autoAppliedSuggestionRowsRef.current.has(rowName)) continue;
      const top = layers?.[rowName]?.top_suggestion;
      if (!top?.entity_handle) continue;
      if (Number(top.confidence_score || 0) < 50) continue;
      autoAppliedSuggestionRowsRef.current.add(rowName);
      await saveSemanticLayerMapping(
        rowName,
        top.entity_handle,
        "auto_suggested",
        Number(top.confidence_score || 0),
        { silent: true }
      );
    }
  }

  function highlightTopSuggestions(suggestions) {
    for (const line of Object.values(entityObjectsRef.current || {})) {
      if (line?.material) {
        const colorHex = MAPPING_STATUS_COLORS[line.userData?.mappingStatus] || MAPPING_STATUS_COLORS.unmapped;
        line.material.color.set(colorHex);
      }
    }
    for (const entry of Object.values(suggestions || {})) {
      const handle = entry?.top_suggestion?.entity_handle;
      if (!handle) continue;
      const obj = entityObjectsRef.current[handle];
      if (obj?.material) {
        obj.material.color.set(0xff5a36);
      }
    }
    render();
  }

  async function saveSemanticLayerMapping(semanticLayerName, entityHandle, source = "user_selected", confidenceScore = null, options = {}) {
    if (!config.mapManualMapUrl || !semanticLayerName || !entityHandle) return;
    const silent = !!options.silent;
    try {
      const payload = {
        semantic_layer_name: semanticLayerName,
        entity_handle: entityHandle,
        source,
      };
      if (confidenceScore != null) payload.confidence_score = confidenceScore;

      const response = await fetch(config.mapManualMapUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
        body: JSON.stringify(payload),
      });
      const result = await response.json();
      if (!response.ok) {
        setStatusMessage(result.message || "Failed to save semantic mapping.");
        return;
      }
      if (!silent) {
        setStatusMessage(`Accepted system configuration: ${semanticLayerName} mapped to ${entityHandle}`);
      }
      setAcceptedSuggestionState((prev) => ({
        ...prev,
        [semanticLayerName]: {
          handle: entityHandle,
          source,
          confidence: confidenceScore,
          at: Date.now(),
        },
      }));
      // Keep accepted suggestion visually focused so the user sees what got mapped.
      setSelectedEntityHandle(entityHandle);
      setSelectedEntityHandles([entityHandle]);
      await loadMappedEntities();
    } catch {
      setStatusMessage("Failed to save semantic mapping.");
    }
  }

  function buildLayerGeometry() {
    for (const [layer, positions] of Object.entries(layerSegmentsRef.current)) {
      if (!positions || positions.length < 6) continue;
      const geom = new THREE.BufferGeometry();
      geom.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
      geom.computeBoundingBox();
      geom.computeBoundingSphere();
      const mat = new THREE.LineBasicMaterial({ color: 0x111111 });
      const lines = new THREE.LineSegments(geom, mat);
      lines.frustumCulled = false;
      lines.userData = {
        handle: null,
        layer,
        entityType: "LINE_SEGMENTS",
        semanticEntity: layerMetaRef.current[layer]?.tag || null,
        processingRole: null,
        mappingStatus: "unmapped",
        confidenceScore: null,
        area: null,
        perimeter: null,
        bbox: layerBoundsRef.current[layer] || null,
      };
      const group = layerGroupsRef.current[layer];
      if (group) {
        group.add(lines);
        group.userData.lineObject = lines;
      }
    }
    refreshLayerHighlight();
    render();
  }

  async function loadMappedEntities() {
    if (!config.mapEntitiesUrl) return false;
    try {
      const response = await fetch(config.mapEntitiesUrl, { headers: { Accept: "application/json" } });
      if (!response.ok) return false;
      const payload = await response.json();
      const entities = Array.isArray(payload.entities) ? payload.entities : [];
      if (!entities.length) return false;

      resetDrawingScene();

      const layerSeen = {};
      for (const ent of entities) {
        const layer = ent.layer_name || "(none)";
        if (!layerGroupsRef.current[layer]) {
          const g = new THREE.Group();
          g.name = layer;
          g.visible = true;
          sceneRef.current.add(g);
          layerGroupsRef.current[layer] = g;
          layerBoundsRef.current[layer] = createBounds();
          layerSeen[layer] = {
            count: 0,
            visible: true,
            tag: layerMetaRef.current[layer]?.tag || "",
            layer,
            hasSemantic: false,
            hasVerified: false,
            hasAutoMapped: false,
            hasReviewCandidate: false,
          };
        }
        layerSeen[layer] = layerSeen[layer] || {
          count: 0,
          visible: true,
          tag: layerMetaRef.current[layer]?.tag || "",
          layer,
          hasSemantic: false,
          hasVerified: false,
          hasAutoMapped: false,
          hasReviewCandidate: false,
        };
        if (ent.semantic_entity) layerSeen[layer].hasSemantic = true;
        if (["expert_verified", "manual_mapped"].includes(ent.mapping_status)) layerSeen[layer].hasVerified = true;
        if (ent.mapping_status === "auto_mapped") layerSeen[layer].hasAutoMapped = true;
        if (ent.mapping_status === "needs_expert_review") layerSeen[layer].hasReviewCandidate = true;

        const points = (ent.geometry_json && Array.isArray(ent.geometry_json.points)) ? ent.geometry_json.points : [];
        if (points.length < 2) continue;
        const flat = [];
        for (let i = 0; i < points.length - 1; i += 1) {
          const a = points[i];
          const b = points[i + 1];
          if (!Array.isArray(a) || !Array.isArray(b)) continue;
          flat.push(Number(a[0]) || 0, Number(a[1]) || 0, 0, Number(b[0]) || 0, Number(b[1]) || 0, 0);
        }
        if (ent.is_closed && points.length > 2) {
          const first = points[0];
          const last = points[points.length - 1];
          flat.push(Number(last[0]) || 0, Number(last[1]) || 0, 0, Number(first[0]) || 0, Number(first[1]) || 0, 0);
        }
        if (flat.length < 6) continue;

        const geom = new THREE.BufferGeometry();
        geom.setAttribute("position", new THREE.Float32BufferAttribute(flat, 3));
        const colorHex = MAPPING_STATUS_COLORS[ent.mapping_status] || MAPPING_STATUS_COLORS.unmapped;
        const mat = new THREE.LineBasicMaterial({ color: colorHex });
        const line = new THREE.LineSegments(geom, mat);
        line.frustumCulled = false;
          line.userData = {
            handle: ent.handle || null,
            layer,
            layer_name: layer,
            entityType: ent.entity_type || "UNKNOWN",
            semanticEntity: ent.semantic_entity || null,
            semantic_entity: ent.semantic_entity || null,
            processingRole: ent.processing_role || null,
            mappingStatus: ent.mapping_status || "unmapped",
            mapping_status: ent.mapping_status || "unmapped",
            confidenceScore: ent.confidence_score ?? null,
            area: ent.area ?? null,
            perimeter: ent.perimeter ?? null,
            bbox: ent.bbox_json || null,
          };
          line.visible = entityVisibleForViewMode(ent, "approval");
        if (ent.handle) {
          entityObjectsRef.current[ent.handle] = line;
          pickableObjectsRef.current.push(line);
        }
        layerGroupsRef.current[layer].add(line);
        layerSeen[layer].count += 1;

        for (const pt of points) {
          if (!Array.isArray(pt)) continue;
          updateBounds(layerBoundsRef.current[layer], { x: Number(pt[0]) || 0, y: Number(pt[1]) || 0 });
        }
      }

      setLayerOrder(Object.keys(layerGroupsRef.current).sort((a, b) => a.localeCompare(b)));
      setLayerMeta((prev) => {
        const next = { ...prev };
        for (const [layer, info] of Object.entries(layerSeen)) {
          const merged = { ...(next[layer] || {}), ...info, tag: next[layer]?.tag || info.tag || "", count: info.count };
          merged.visible = floorContext
            ? visibleForFloorContext(layer, merged, floorContext)
            : visibleForViewMode(layer, merged, "approval");
          next[layer] = merged;
          if (layerGroupsRef.current[layer]) {
            layerGroupsRef.current[layer].visible = true;
          }
        }
        return next;
      });
      setViewMode(floorContext ? "floor" : "approval");
      if (!visibleEntityCount()) {
        forceMappedEntitiesVisible();
      }
      requestAnimationFrame(() => fitView());
      render();

      await loadMapSummaryAndSuggestions();
      return true;
    } catch {
      return false;
    }
  }

  function refreshLayerHighlight(layerName = selectedLayer, relatedLayers = []) {
    const relatedSet = new Set(relatedLayers || []);
    const allLayers = Object.keys(layerGroupsRef.current || {});

    for (const layer of allLayers) {
      const group = layerGroupsRef.current[layer];
      const lineObject = group?.userData?.lineObject;
      if (!lineObject || !lineObject.material) continue;
      const isSelected = selectedLayerSet.has(layer) || (layerName && layer === layerName);
      const isRuleRelated = relatedSet.has(layer);
      const color = isSelected ? 0x0b3d91 : isRuleRelated ? 0x2c5fb8 : 0x111111;
      lineObject.material.color.set(color);
      lineObject.material.opacity = isSelected ? 1.0 : isRuleRelated ? 0.95 : 0.65;
      lineObject.material.transparent = !isSelected;
      lineObject.material.needsUpdate = true;
    }
    highlightStateRef.current = { selected: layerName || "", related: relatedSet };
    render();
  }

  function selectLayer(layer, options = {}) {
    if (!layer) return;
    setQuickMarkFeedback(null);
    const additive = !!options.additive;
    startUiTransition(() => {
      setSelectedLayer(layer);
      setSelectedLayers((prev) => {
        if (!additive) return [layer];
        if (prev.includes(layer)) {
          return prev.filter((item) => item !== layer);
        }
        return [...prev, layer];
      });
    });
    setHoverText(`Layer: ${layer}`);
  }

  function addLayersToSelection(layerNames = []) {
    const nextLayers = Array.isArray(layerNames) ? layerNames.filter(Boolean) : [];
    if (!nextLayers.length) return;
    startUiTransition(() => {
      setSelectedLayers((prev) => Array.from(new Set([...prev, ...nextLayers])));
      if (!selectedLayer && nextLayers[0]) {
        setSelectedLayer(nextLayers[0]);
      }
    });
    setHoverText(`Added ${nextLayers.join(", ")} to the selected group.`);
  }

  function captureCurrentFloorTemplate() {
    const activeLayers = (selectedLayers.length ? selectedLayers : [selectedLayer]).filter(Boolean);
    const messageTarget = window.opener || window.parent;
    const payload = {
      type: "cad-floor-template-suggestion",
      payload: {
        floorContext: floorWizard.activeFloor,
        floorLabel: humanFloorContext(floorWizard.activeFloor),
        source: "viewer",
        template: {
          layer_names: activeLayers,
          entity_handles: selectedEntityHandles,
          selected_layer: selectedLayer || activeLayers[0] || "",
          active_label_key: activeLabelKey,
        },
      },
    };
    messageTarget?.postMessage(payload, window.location.origin);
    setStatusMessage(`Captured ${humanFloorContext(floorWizard.activeFloor)} template for the parent form.`);
  }

  function applySavedFloorTemplate(template, sourceFloor) {
    if (!template || typeof template !== "object") return;
    const layerNames = Array.isArray(template.layer_names) ? template.layer_names.filter(Boolean) : [];
    const handles = Array.isArray(template.entity_handles) ? template.entity_handles.filter(Boolean) : [];
    if (layerNames.length) {
      addLayersToSelection(layerNames);
    }
    if (handles.length) {
      startUiTransition(() => {
        setSelectedEntityHandles((prev) => Array.from(new Set([...prev, ...handles])));
      });
    }
    setStatusMessage(`Applied saved ${humanFloorContext(sourceFloor)} template to the current selection.`);
  }

  function clearLayerSelection() {
    startUiTransition(() => {
      setSelectedLayer("");
      setSelectedLayers([]);
    });
    setHoverText("");
  }

  function buildFloorContextUrl(targetFloor) {
    const url = new URL(window.location.href);
    if (targetFloor) {
      url.searchParams.set("floor_context", targetFloor);
    } else {
      url.searchParams.delete("floor_context");
    }
    return url.toString();
  }

  function selectActiveLabel(labelKey) {
    if (!labelKey) return;
    startUiTransition(() => {
      setActiveLabelKey(labelKey);
    });
  }

  async function loadDxf() {
    if (!DxfParserCtor) {
      setLoading(false);
      setLoadingMessage("DXF parser library did not load.");
      return;
    }

    setLoading(true);
    setLoadingMessage("Loading DXF...");
    resetDrawingScene();
    setLayerMeta({});
    setLayerOrder([]);
    setSelectedLayer("");
    setSelectedLayers([]);
    setHoverText("");

    let text = "";
    try {
      text = await fetch(config.dxfUrl).then((r) => r.text());
      dxfBytesRef.current = text.length;
    } catch (err) {
      setLoading(false);
      setLoadingMessage("Failed to fetch DXF.");
      return;
    }

    let dxf;
    try {
      const parser = new DxfParserCtor();
      dxf = parser.parseSync(text);
    } catch (err) {
      setLoading(false);
      setLoadingMessage("Failed to parse DXF.");
      return;
    }

    const blocks = dxf.blocks || {};
    const rootEntities = Array.isArray(dxf.entities) && dxf.entities.length
      ? dxf.entities
      : (blocks["*Model_Space"] && Array.isArray(blocks["*Model_Space"].entities) ? blocks["*Model_Space"].entities
        : (blocks["Model_Space"] && Array.isArray(blocks["Model_Space"].entities) ? blocks["Model_Space"].entities : []));

    const queue = rootEntities.map((ent) => ({ ent, transforms: [], activeLayer: null }));
    let processed = 0;
    textEntitiesRef.current = [];

    function enqueueEntities(entities, transforms, activeLayer) {
      if (!Array.isArray(entities)) return;
      for (const ent of entities) {
        if (!ent) continue;
        queue.push({ ent, transforms, activeLayer });
      }
    }

    function processQueue() {
      let segments = 0;
      const startTick = performance.now();
      while (
        queue.length &&
        segments < SEGMENTS_PER_TICK &&
        (performance.now() - startTick) < MAX_TIME_PER_TICK_MS
      ) {
        const task = queue.shift();
        const ent = task.ent;
        processed += 1;
        statsRef.current.entities += 1;

        if (ent.type === "INSERT" && ent.name && blocks[ent.name] && blocks[ent.name].entities) {
          const block = blocks[ent.name];
          const rows = Number.isFinite(ent.rowCount) ? ent.rowCount : 1;
          const cols = Number.isFinite(ent.columnCount) ? ent.columnCount : 1;
          const rowSpacing = Number.isFinite(ent.rowSpacing) ? ent.rowSpacing : 0;
          const colSpacing = Number.isFinite(ent.columnSpacing) ? ent.columnSpacing : 0;
          const base = coercePoint(block.position) || { x: 0, y: 0 };
          const layer = (ent.layer && ent.layer !== "0") ? ent.layer : task.activeLayer;

          for (let r = 0; r < rows; r += 1) {
            for (let c = 0; c < cols; c += 1) {
              const basePos = coercePoint(ent.position) || { x: 0, y: 0 };
              const pos = { x: basePos.x + (c * colSpacing), y: basePos.y + (r * rowSpacing) };
              const tr = {
                position: pos,
                rotation: Number.isFinite(ent.rotation) ? ent.rotation : 0,
                xScale: Number.isFinite(ent.xScale) ? ent.xScale : 1,
                yScale: Number.isFinite(ent.yScale) ? ent.yScale : 1,
                base: base,
              };
              enqueueEntities(block.entities, task.transforms.concat([tr]), layer);
            }
          }
          continue;
        }

        const transforms = task.transforms || [];
        const layer = (ent.layer && ent.layer !== "0") ? ent.layer : (task.activeLayer || ent.layer || "0");
        const closed = !!(ent.shape || ent.closed);

        if (ent.type === "LINE") {
          const verts = ent.vertices || [];
          const p1 = coercePoint(verts[0]) || coercePoint(ent.start);
          const p2 = coercePoint(verts[1]) || coercePoint(ent.end);
          if (p1 && p2) {
            const [tp1, tp2] = transformPoints([p1, p2], transforms);
            segments += addLine(layer, tp1, tp2);
          }
        } else if (ent.type === "LWPOLYLINE" || ent.type === "POLYLINE") {
          const pts = transformPoints(coercePoints(ent.vertices), transforms);
          segments += addPolyline(layer, pts, closed);
        } else if (ent.type === "CIRCLE") {
          const center = coercePoint(ent.center);
          if (center && Number.isFinite(ent.radius)) {
            const pts = transformPoints(circlePoints(center, ent.radius), transforms);
            segments += addPolyline(layer, pts, true);
          }
        } else if (ent.type === "ARC") {
          const center = coercePoint(ent.center);
          if (center && Number.isFinite(ent.radius)) {
            const pts = transformPoints(arcPoints(center, ent.radius, ent.startAngle, ent.endAngle), transforms);
            segments += addPolyline(layer, pts, false);
          }
        } else if (ent.type === "SPLINE") {
          const pts = transformPoints(coercePoints(ent.fitPoints || ent.controlPoints), transforms);
          segments += addPolyline(layer, pts, false);
        } else if (ent.type === "ELLIPSE") {
          const center = coercePoint(ent.center);
          const major = coercePoint(ent.majorAxisEndPoint);
          if (center && major) {
            const pts = transformPoints(
              ellipsePoints(center, major, ent.axisRatio, ent.startAngle, ent.endAngle),
              transforms
            );
            segments += addPolyline(layer, pts, false);
          }
        } else if (ent.type === "SOLID") {
          const pts = transformPoints(coercePoints(ent.points), transforms);
          segments += addPolyline(layer, pts, true);
        } else if (ent.type === "TEXT" || ent.type === "MTEXT") {
          const raw = extractEntityText(ent);
          if (raw) {
            const pos = coercePoint(ent.position || ent.startPoint || ent.location || ent.insert);
            const tpos = pos ? transformPoints([pos], transforms)[0] : null;
            textEntitiesRef.current.push({
              layer,
              handle: ent.handle || null,
              text: raw,
              x: tpos ? tpos.x : null,
              y: tpos ? tpos.y : null,
              rotation: Number.isFinite(ent.rotation) ? ent.rotation : null,
              height: Number.isFinite(ent.height) ? ent.height : null,
            });
          }
        }
      }

      const now = performance.now();
      if (now - loadingUpdateRef.current > LOAD_MESSAGE_INTERVAL_MS) {
        loadingUpdateRef.current = now;
        setLoadingMessage(`Loading... ${processed} entities`);
      }

      if (queue.length) {
        requestAnimationFrame(processQueue);
      } else {
        buildLayerGeometry();
        const nextMeta = {};
        const existing = normalizePersistedLayerMap(config.layerMap || {});
        for (const layer of Object.keys(layerGroupsRef.current)) {
          const saved = existing[layer] || {};
          const defaultVisible = true;
          const visible = typeof saved.visible === "boolean" ? saved.visible : defaultVisible;
          const tag = typeof saved.tag === "string" ? saved.tag : "";
          nextMeta[layer] = { visible, tag, layer };
          layerGroupsRef.current[layer].visible = visible;
        }
        const anyVisible = Object.values(nextMeta).some((meta) => meta.visible);
        if (!anyVisible) {
          for (const layer of Object.keys(nextMeta)) {
            nextMeta[layer].visible = true;
            if (layerGroupsRef.current[layer]) {
              layerGroupsRef.current[layer].visible = true;
            }
          }
        }
        const order = Object.keys(nextMeta).sort((a, b) => a.localeCompare(b));
        setLayerMeta(nextMeta);
        setLayerOrder(order);
        setSelectedLayer("");
        setViewMode("all");

        resizeFnRef.current?.();
        const fitInfo = fitInfoRef.current || {};
        const spanText = Number.isFinite(fitInfo.fullSpan)
          ? ` · Fit: ${fitInfo.source} (full ${Math.round(fitInfo.fullSpan)}${Number.isFinite(fitInfo.denseSpan) ? `, dense ${Math.round(fitInfo.denseSpan)}` : ""}${Number.isFinite(fitInfo.dominantSpan) ? `, dom ${Math.round(fitInfo.dominantSpan)}` : ""}${Number.isFinite(fitInfo.trimmedSpan) ? `, trim ${Math.round(fitInfo.trimmedSpan)}` : ""})`
          : "";
        const summary = `Entities: ${statsRef.current.entities} · Lines: ${statsRef.current.lines} · Polylines: ${statsRef.current.polylines} · Text: ${textEntitiesRef.current.length} · Size: ${lastSizeRef.current.w}x${lastSizeRef.current.h} · DXF bytes: ${text.length}${spanText}`;
        setSummaryText(summary);
        const textList = [...textEntitiesRef.current].sort((a, b) => {
          if (a.layer === b.layer) return a.text.localeCompare(b.text);
          return a.layer.localeCompare(b.layer);
        });
        setTextEntities(textList);
        renderCadTextOverlays(textList);
        window.__cadDebug = {
          dxf,
          layerBounds: layerBoundsRef.current,
          stats: statsRef.current,
          layerGroups: layerGroupsRef.current,
          textEntities: textEntitiesRef.current,
        };

        setLoading(false);
        requestAnimationFrame(() => fitView());
      }
    }

    processQueue();
  }

  useEffect(() => {
    renderCadTextOverlays(textEntities);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [showCadText]);

  function updateLayerMeta(layer, updates) {
    setLayerMeta((prev) => {
      const next = { ...prev, [layer]: { ...prev[layer], ...updates } };
      if (layerGroupsRef.current[layer] && typeof updates.visible === "boolean") {
        layerGroupsRef.current[layer].visible = updates.visible;
        for (const obj of Object.values(entityObjectsRef.current || {})) {
          if (obj.userData?.layer === layer) {
            obj.visible = updates.visible;
          }
        }
      }
      return next;
    });
    render();
  }

  function applyBulkTagToLayers() {
    if (!bulkTag) return;
    const needle = (layerFilter || "").trim().toLowerCase();
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of layerOrder) {
        const layerName = String(layer || "");
        const visible = !!next[layerName]?.visible;
        if (!bulkIncludeHidden && !visible) continue;
        if (needle && !layerName.toLowerCase().includes(needle)) continue;
        next[layerName] = { ...next[layerName], tag: bulkTag };
      }
      return next;
    });
  }

  function applyViewMode(mode) {
    setViewMode(mode);
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of layerOrder) {
        const visible = mode === "floor"
          ? visibleForFloorContext(layer, next[layer], floorContext)
          : visibleForViewMode(layer, next[layer], mode);
        next[layer] = { ...(next[layer] || {}), visible };
        if (layerGroupsRef.current[layer]) layerGroupsRef.current[layer].visible = true;
      }
      return next;
    });
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      obj.visible = mode === "floor"
        ? entityVisibleForFloorContext(obj.userData || {}, floorContext)
        : entityVisibleForViewMode(obj.userData || {}, mode);
    }
    requestAnimationFrame(() => fitView());
  }

  function showAll() {
    setViewMode("all");
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        next[layer] = { ...next[layer], visible: true };
        if (layerGroupsRef.current[layer]) layerGroupsRef.current[layer].visible = true;
      }
      return next;
    });
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      obj.visible = true;
    }
    render();
  }

  function hideAll() {
    setViewMode("custom");
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        next[layer] = { ...next[layer], visible: false };
        if (layerGroupsRef.current[layer]) layerGroupsRef.current[layer].visible = false;
      }
      return next;
    });
    for (const obj of Object.values(entityObjectsRef.current || {})) {
      obj.visible = false;
    }
    render();
  }

  function clearTags() {
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        next[layer] = { ...next[layer], tag: "" };
      }
      return next;
    });
  }

  function preset5m() {
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        const low = layer.toLowerCase();
        let tag = next[layer].tag || "";
        if (low.includes("plot") || low.includes("boundary")) tag = "plot_boundary";
        if (low.includes("gf") || low.includes("ground")) tag = "ground_floor";
        if (low.includes("1st") || low.includes("ff") || low.includes("first")) tag = "floor_1";
        if (low.includes("2nd") || low.includes("sf") || low.includes("second")) tag = "floor_2";
        if (low.includes("dim")) tag = "dimensions";
        if (low.includes("text") || low.includes("note")) tag = "text";
        next[layer] = { ...next[layer], tag };
      }
      return next;
    });
  }

  function snapshot() {
    const renderer = rendererRef.current;
    if (!renderer) return;
    const dataUrl = renderer.domElement.toDataURL("image/png");
    const w = window.open();
    if (w) {
      w.document.write(`<title>Layer Snapshot</title><img src="${dataUrl}" style="width:100%;height:auto;" />`);
    }
  }

  const layerMapJson = useMemo(() => JSON.stringify(layerMeta), [layerMeta]);
  const scaleOptions = useMemo(() => {
    const seen = new Map();
    for (const item of textEntities) {
      for (const opt of extractScaleCandidates(item.text)) {
        if (!seen.has(opt.label)) seen.set(opt.label, opt);
      }
    }
    return Array.from(seen.values());
  }, [textEntities]);
  const autoScaleFromPlotBoundary = useMemo(() => {
    const front = Number(rulesMetadata?.plot_dimensions_ft?.front);
    const depth = Number(rulesMetadata?.plot_dimensions_ft?.depth);
    if (!Number.isFinite(front) || !Number.isFinite(depth) || front <= 0 || depth <= 0) {
      return null;
    }
    const plotRow = (mappingReport?.labels || []).find((row) => row.label_key === "plot_boundary");
    if (!plotRow || !Array.isArray(plotRow.entities) || !plotRow.entities.length) {
      return null;
    }
    const ranked = [...plotRow.entities].sort((a, b) => {
      const areaA = Number(a?.measurement?.measured_area || 0);
      const areaB = Number(b?.measurement?.measured_area || 0);
      return areaB - areaA;
    });
    const best = ranked[0];
    const width = Number(best?.bbox?.width ?? best?.measurement?.measured_width ?? 0);
    const height = Number(best?.bbox?.height ?? best?.measurement?.measured_height ?? 0);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
      return null;
    }

    // Compare both orientations against expected 25x45 plot geometry.
    const s1w = front / width;
    const s1h = depth / height;
    const s2w = depth / width;
    const s2h = front / height;
    const mismatch1 = Math.abs(s1w - s1h);
    const mismatch2 = Math.abs(s2w - s2h);
    const chosen = mismatch1 <= mismatch2 ? [(s1w + s1h) / 2, mismatch1] : [(s2w + s2h) / 2, mismatch2];
    const multiplier = Number(chosen[0]);
    if (!Number.isFinite(multiplier) || multiplier <= 0) {
      return null;
    }
    return {
      multiplier,
      label: `Auto from plot (${front}x${depth} ft)`,
      mismatch: Number(chosen[1] || 0),
    };
  }, [mappingReport, rulesMetadata]);
  useEffect(() => {
    if (scaleTouched) return;
    if (!autoScaleFromPlotBoundary) return;
    setScaleMultiplier(autoScaleFromPlotBoundary.multiplier);
    setScaleLabel(autoScaleFromPlotBoundary.label);
  }, [autoScaleFromPlotBoundary, scaleTouched]);
  const filteredText = useMemo(() => {
    const selectionKeywords = buildSelectionKeywordsFromLayers(selectedLayers.length ? selectedLayers : [selectedLayer].filter(Boolean), layerMeta, tagOptions);

    let scopedText = textEntities;
    if (selectionKeywords.length) {
      scopedText = textEntities.filter((item) => {
        const itemTag = layerMeta[item.layer]?.tag || "";
        const haystack = `${item.layer} ${itemTag} ${item.text}`.toLowerCase();
        return selectedLayerSet.has(item.layer) || selectionKeywords.some((keyword) => haystack.includes(keyword));
      });
    }

    if (!textFilter) return scopedText.slice(0, MAX_TEXT_ITEMS);
    const needle = textFilter.toLowerCase();
    return scopedText
      .filter((item) => item.text.toLowerCase().includes(needle) || item.layer.toLowerCase().includes(needle))
      .slice(0, MAX_TEXT_ITEMS);
  }, [textEntities, textFilter, selectedLayer, selectedLayerSet, selectedLayers, layerMeta, tagOptions]);
  const scaledDistance = Number.isFinite(measureDistance)
    ? measureDistance * (Number.isFinite(scaleMultiplier) ? scaleMultiplier : 1)
    : null;
  const rawDistanceLabel = Number.isFinite(measureDistance) ? measureDistance.toFixed(2) : "—";
  const scaledDistanceLabel = Number.isFinite(scaledDistance) ? scaledDistance.toFixed(2) : "—";
  const selectedRule = rules.find((rule) => rule.id === selectedRuleId);
  const selectedSystemRuleResult = useMemo(() => {
    const rows = Array.isArray(validationReport?.rules) ? validationReport.rules : [];
    return rows.find((row) => row.rule_code === selectedRuleId || row.rule_id === selectedRuleId) || null;
  }, [validationReport, selectedRuleId]);
  const selectedSystemValue = selectedSystemRuleResult?.actual ?? "";
  const previewPass = evaluateRule(selectedRule, measuredValue);
  const assistantInsight = useMemo(() => {
    if (!selectedRule) {
      return {
        headline: "Select a rule to start suggestions.",
        detail: "Pick a rule in Expert measurement and then measure two points in CAD.",
        recommendation: null,
      };
    }

    const measured = Number(measuredValue);
    const measuredOk = Number.isFinite(measured);
    const system = Number(selectedSystemValue);
    const systemOk = Number.isFinite(system);
    const hasDistance = Number.isFinite(scaledDistance);

    if (!hasDistance && !measuredOk) {
      return {
        headline: "Need measurement input.",
        detail: "Turn on Measure, click two points, then use the measured value for this rule.",
        recommendation: "measure-first",
      };
    }

    if (!measuredOk && hasDistance) {
      return {
        headline: "Measured distance is ready.",
        detail: `Use ${scaledDistance.toFixed(3)}${selectedRule.unit ? ` ${selectedRule.unit}` : ""} for ${selectedRule.id}.`,
        recommendation: "use-measured",
      };
    }

    const pass = evaluateRule(selectedRule, measured);
    const ruleText = `Required ${selectedRule.operator} ${selectedRule.required_value}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}, measured ${measured.toFixed(3)}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}.`;
    if (!systemOk) {
      return {
        headline: pass ? "Recommendation: PASS" : "Recommendation: FAIL",
        detail: `${ruleText} System value not available; rely on expert CAD measurement.`,
        recommendation: "confirm-note",
      };
    }

    const delta = Math.abs(system - measured);
    const pct = measured === 0 ? delta : (delta / Math.max(Math.abs(measured), 1)) * 100;
    const consistency = pct <= 5 ? "high" : pct <= 12 ? "medium" : "low";
    const decision = pass ? "PASS" : "FAIL";
    return {
      headline: `Recommendation: ${decision} (${consistency} confidence)`,
      detail: `${ruleText} System ${system.toFixed(3)}${selectedRule.unit ? ` ${selectedRule.unit}` : ""}; difference ${delta.toFixed(3)} (${pct.toFixed(1)}%).`,
      recommendation: "confirm-note",
    };
  }, [selectedRule, measuredValue, selectedSystemValue, scaledDistance]);
  const textualRecord = {
    status: analysisResult.status || "unknown",
    message: analysisResult.message || null,
    plot_handle_used: analysisResult?.polygon_discovery?.plot_handle_used || null,
    floor_handles_used: analysisResult?.polygon_discovery?.floor_handles_used || [],
    plot_layer_used: analysisResult?.polygon_discovery?.plot_layer_used || null,
    building_layer_used: analysisResult?.polygon_discovery?.building_layer_used || null,
    resolver: analysisResult?.resolver || {},
    warnings: analysisResult?.warnings || [],
  };
  const measurableRecord = {
    areas: analysisResult?.areas || {},
    setbacks_ft: analysisResult?.setbacks_ft || {},
    dimensions: analysisResult?.dimensions || [],
  };
  const trainingRecord = {
    training_label: trainingLabel,
    training_events: analysisResult?.training_events || [],
    entity_summary: entitySummary,
  };
  const selectedLayerRules = useMemo(() => {
    const activeLayers = selectedLayers.length ? selectedLayers : [selectedLayer].filter(Boolean);
    if (!activeLayers.length) return [];
    const keywords = buildSelectionKeywordsFromLayers(activeLayers, layerMeta, tagOptions);

    if (!keywords.length) return [];
    return rules.filter((rule) => {
      const content = `${rule.title || ""} ${rule.description || ""}`.toLowerCase();
      return keywords.some((keyword) => content.includes(keyword));
    }).slice(0, 8);
  }, [selectedLayer, selectedLayers, layerMeta, rules, tagOptions]);
  const selectedRuleRelatedLayers = useMemo(() => {
    const rule = rules.find((item) => item.id === selectedRuleId);
    if (!rule) return [];
    const text = `${rule.id || ""} ${rule.title || ""} ${rule.description || ""}`.toLowerCase();
    return Object.entries(layerMeta)
      .filter(([, meta]) => {
        const tag = String(meta?.tag || "").toLowerCase();
        return tag && text.includes(tag.replace(/_/g, " "));
      })
      .map(([name]) => name);
  }, [layerMeta, rules, selectedRuleId]);
  const selectedLayerTextMatches = useMemo(() => {
    const activeLayers = selectedLayers.length ? selectedLayers : [selectedLayer].filter(Boolean);
    if (!activeLayers.length) return [];
    const keywords = buildSelectionKeywordsFromLayers(activeLayers, layerMeta, tagOptions);
    if (!keywords.length) return [];

    return textEntities
      .filter((item) => {
        const haystack = `${item.layer} ${item.text}`.toLowerCase();
        return selectedLayerSet.has(item.layer) || keywords.some((keyword) => haystack.includes(keyword));
      })
      .slice(0, 8);
  }, [selectedLayer, selectedLayers, selectedLayerSet, layerMeta, textEntities, tagOptions]);
  const cadTextMeasurements = useMemo(() => extractCadTextMeasurements(textEntities), [textEntities]);
  const nativeTextSuggestions = useMemo(
    () => buildNativeSpacePredictions(
      textEntities,
      cadEntities,
      floorContext,
      scaleMultiplier,
      scaleTouched || !!autoScaleFromPlotBoundary
    ).slice(0, 200),
    [autoScaleFromPlotBoundary, cadEntities, floorContext, scaleMultiplier, scaleTouched, textEntities]
  );
  const nativeCorrectionOptions = useMemo(() => {
    const options = new Map(tagOptions.filter((option) => option.value).map((option) => [option.value, option]));
    for (const label of labelsCatalog) {
      if (!label?.label_key || options.has(label.label_key)) continue;
      options.set(label.label_key, { value: label.label_key, label: label.label_name || humanizeTagValue(label.label_key) });
    }
    return Array.from(options.values());
  }, [labelsCatalog, tagOptions]);
  const nativePredictionsBySourceKey = useMemo(() => {
    const rows = new Map();
    for (const prediction of taggingWorkspace.predictions || []) {
      const sourceKey = prediction?.metadata?.source_key;
      if (prediction?.metadata?.source === "native_cad_text" && sourceKey) {
        rows.set(sourceKey, prediction);
      }
    }
    return rows;
  }, [taggingWorkspace.predictions]);
  const nativePredictionsByEvidence = useMemo(() => {
    const rows = new Map();
    for (const prediction of taggingWorkspace.predictions || []) {
      const evidence = prediction?.metadata?.cad_text_evidence;
      if (prediction?.metadata?.source !== "native_cad_text" || !evidence) continue;
      const identity = nativeTextEvidenceIdentity({
        handle: evidence.cad_handle || prediction.cad_handle,
        layer: evidence.cad_layer || prediction.cad_layer,
        text: evidence.raw_text,
        x: evidence.x,
        y: evidence.y,
      });
      if (identity) rows.set(identity, prediction);
    }
    return rows;
  }, [taggingWorkspace.predictions]);
  const nativeSpaceRows = useMemo(() => nativeTextSuggestions.map((suggestion) => ({
    suggestion,
    prediction: nativePredictionsBySourceKey.get(suggestion.source_key)
      || nativePredictionsByEvidence.get(nativeTextEvidenceIdentity(suggestion.finding))
      || null,
  })), [nativePredictionsByEvidence, nativePredictionsBySourceKey, nativeTextSuggestions]);

  useEffect(() => {
    const visualStates = new Map();
    for (const { suggestion, prediction } of nativeSpaceRows) {
      const classifierMismatch = !!prediction && (
        (prediction.final_label_key || prediction.label_key) !== suggestion.label_key
        || prediction.floor !== suggestion.floor
        || prediction.label_name !== suggestion.instance_key
      );
      visualStates.set(nativeTextEvidenceIdentity(suggestion.finding), {
        marked: !!prediction
          && ["confirmed", "corrected", "verified"].includes(prediction.status)
          && !classifierMismatch,
      });
    }
    nativeSuggestionVisualStateRef.current = visualStates;
    renderCadTextOverlays(textEntities);
    // Repaint the existing marker when its review status changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [nativeSpaceRows, textEntities]);

  useEffect(() => {
    nativeSuggestionActionRef.current = (sourceKey) => {
      const row = nativeSpaceRows.find((item) => item.suggestion.source_key === sourceKey);
      if (!row) return;
      setFocusedNativeSourceKey(sourceKey);
      if (row.prediction) selectPredictionForReview(row.prediction, { preserveDrawing: true });
      else highlightCadTextFinding(row.suggestion.finding, { preserveDrawing: true });
      requestAnimationFrame(() => {
        const node = nativeSuggestionRowRefs.current[sourceKey];
        node?.scrollIntoView?.({ behavior: "smooth", block: "center" });
        node?.focus?.({ preventScroll: true });
      });
      setStatusMessage(`${row.suggestion.instance_key} selected. Confirm, change, or reject it in the right panel.`);
    };
    return () => {
      nativeSuggestionActionRef.current = null;
    };
    // The current rows contain the matching prediction and native text evidence.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [nativeSpaceRows]);

  useEffect(() => {
    if (!config.predictionImportUrl || nativeTextSuggestions.length === 0) return undefined;
    const predictions = nativeTextSuggestions.map((suggestion) => ({
      label_key: suggestion.label_key,
      label_name: suggestion.label_name,
      confidence: suggestion.confidence,
      geometry: suggestion.geometry,
      model_version: suggestion.model_version,
      cad_handle: suggestion.cad_handle,
      cad_layer: suggestion.cad_layer,
      floor: suggestion.floor,
      metadata: suggestion.metadata,
    }));
    const hash = JSON.stringify(predictions);
    if (hash === nativePredictionSyncRef.current) return undefined;
    nativePredictionSyncRef.current = hash;
    let cancelled = false;

    const timer = setTimeout(async () => {
      setNativePredictionSyncing(true);
      try {
        const response = await fetch(config.predictionImportUrl, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": config.csrfToken },
          body: JSON.stringify({ predictions }),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `Native text suggestion import failed (${response.status})`);
        if (!cancelled) {
          await Promise.all([loadTaggingWorkspace(), loadLabelsCatalog()]);
          setStatusMessage(`Found ${nativeTextSuggestions.length} space labels from native CAD text. Review or correct them in the panel.`);
        }
      } catch (error) {
        nativePredictionSyncRef.current = "";
        if (!cancelled) setStatusMessage(error.message || "Could not create suggestions from native CAD text.");
      } finally {
        if (!cancelled) setNativePredictionSyncing(false);
      }
    }, 500);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
    // The URL and extracted suggestions fully describe this idempotent sync.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config.predictionImportUrl, nativeTextSuggestions]);
  const activeLabelTextReferences = useMemo(() => {
    if (!activeLabelKey) return [];
    const label = String(activeLabelKey).toLowerCase();
    const alias = {
      plot_boundary: ["plot_boundary", "setback_reference", "dimensions"],
      front_building_line: ["front_building_line", "dimensions"],
      rear_building_line: ["rear_building_line", "dimensions"],
      side_building_line: ["side_building_line", "dimensions"],
      external_walls: ["dimensions"],
      porch: ["porch", "dimensions"],
      dimensions: ["dimensions", "setback_reference"],
      text: ["dimensions", "setback_reference"],
    }[label] || [label, "dimensions"];
    return cadTextMeasurements
      .filter((row) => row.semantic_hints.some((hint) => alias.includes(hint)))
      .slice(0, 12);
  }, [activeLabelKey, cadTextMeasurements]);
  useEffect(() => {
    if (!config.cadTextReferencesStoreUrl) return;
    if (!Array.isArray(cadTextMeasurements) || cadTextMeasurements.length === 0) return;
    const payload = cadTextMeasurements.slice(0, 400).map((row) => ({
      text: row.text,
      layer: row.layer,
      value_ft: row.value_ft,
      semantic_hints: row.semantic_hints,
    }));
    const hash = JSON.stringify(payload);
    if (hash === lastTextRefSyncRef.current) return;
    const timer = setTimeout(async () => {
      try {
        const response = await fetch(config.cadTextReferencesStoreUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": config.csrfToken,
          },
          body: JSON.stringify({
            map_drawing_id: config.mapDrawingId || null,
            references: payload,
          }),
        });
        if (response.ok) {
          lastTextRefSyncRef.current = hash;
        }
      } catch {
        // non-blocking
      }
    }, 800);
    return () => clearTimeout(timer);
  }, [cadTextMeasurements, config.cadTextReferencesStoreUrl, config.csrfToken, config.mapDrawingId]);

  const suggestedOfficialMappings = useMemo(() => {
    const base = FLOOR_OFFICIAL_SUGGESTIONS[floorContext] || FLOOR_OFFICIAL_SUGGESTIONS.ground_floor;
    const activeLayers = selectedLayers.length ? selectedLayers : [selectedLayer].filter(Boolean);
    const keywords = buildSelectionKeywordsFromLayers(activeLayers, layerMeta, tagOptions);

    return base
      .map((item) => {
        const haystack = `${item.tag} ${item.label}`.toLowerCase();
        const score = keywords.reduce((acc, keyword) => acc + (haystack.includes(keyword) ? 1 : 0), 0);
        return { ...item, score };
      })
      .sort((a, b) => b.score - a.score || a.label.localeCompare(b.label))
      .slice(0, 6);
  }, [floorContext, layerMeta, selectedLayer, selectedLayers, tagOptions]);
  const quickMarkOptions = useMemo(() => {
    const prefix = floorTagPrefix(floorContext);
    const suggestions = FLOOR_OFFICIAL_SUGGESTIONS[floorContext] || FLOOR_OFFICIAL_SUGGESTIONS.ground_floor;
    const suggestionByTag = new Map(suggestions.map((item) => [item.tag, item]));
    const optionByValue = new Map(tagOptions.map((item) => [item.value, item]));
    const items = [];
    const seen = new Set();

    const add = (value, label, groupLabel = label) => {
      if (!value || seen.has(value)) return;
      const option = optionByValue.get(value);
      const suggestion = suggestionByTag.get(value);
      items.push({
        value,
        label: label || option?.label || suggestion?.label || humanizeTagValue(value),
        groupLabel,
        aliases: option?.aliases || [],
      });
      seen.add(value);
    };

    const firstAvailable = (values) => {
      const exact = values.find((value) => optionByValue.has(value) || suggestionByTag.has(value));
      return exact || values[0] || "";
    };

    [
      { label: "Doors", values: [`${prefix}_doors`, "door", "doors", "ground_doors"] },
      { label: "Plot Boundary", values: ["plot_boundary", "boundary_wall", "plot_line"] },
      { label: "Building Footprint", values: [`${prefix}_external_walls`, "ground_external_walls", "external_walls", "building_footprint"] },
      { label: "Internal Walls", values: [`${prefix}_internal_walls`, "ground_internal_walls", "internal_walls"] },
      { label: "Setback", values: ["setback", "setback_lines", "front_setback", "front_building_line", "side_building_line", "rear_building_line"] },
      { label: "Road / Frontage", values: ["road_frontage_line", "road_frontage", "front_road", "road"] },
      { label: "Windows", values: [`${prefix}_windows`, "ground_windows", "windows", "window"] },
      { label: "Stairs", values: [`${prefix}_stairs`, "ground_stairs", "stairs", "stairs_ramp"] },
      { label: "Porch", values: [`${prefix}_porch`, "ground_porch", "porch"] },
      { label: "Parking", values: [`${prefix}_parking`, "parking", "car_parking"] },
      { label: "Green Area", values: ["landscape", "green_area", "lawn", "open_space"] },
      { label: "Dimensions", values: ["dimension", "dimensions", "measurement_text"] },
      { label: "Text / Notes", values: [`${prefix}_text`, "text", "text_general"] },
      { label: "Other", values: ["other"] },
    ].forEach((group) => add(firstAvailable(group.values), group.label, group.label));

    suggestions.forEach((item) => add(item.tag, item.label, item.label));
    tagOptions
      .filter((option) => option.value)
      .forEach((option) => add(option.value, option.label, option.label));

    return [{ value: "", label: "Choose layer type...", groupLabel: "" }, ...items];
  }, [floorContext, tagOptions]);
  const quickMarkSelectedLayers = useMemo(
    () => (selectedLayers.length ? selectedLayers : [selectedLayer]).filter(Boolean),
    [selectedLayer, selectedLayers]
  );
  const quickMarkAssignedLabel = layerMeta[selectedLayer]?.tag
    ? resolveTagLabel(selectedLayer ? layerMeta[selectedLayer]?.tag : "", tagOptions, suggestedOfficialMappings)
    : "";

  useEffect(() => {
    if (!selectedLayer) {
      setQuickMarkTag("");
      return;
    }
    const existingTag = layerMeta[selectedLayer]?.tag || "";
    setQuickMarkTag(existingTag || inferLayerTag(selectedLayer, quickMarkOptions));
  }, [selectedLayer, layerMeta, quickMarkOptions]);
  const validationCounts = useMemo(() => {
    const rows = Array.isArray(validationReport?.rules) ? validationReport.rules : [];
    const out = { pass: 0, fail: 0, warn: 0, needs_review: 0, not_applicable: 0 };
    for (const row of rows) {
      const key = row?.status;
      if (Object.prototype.hasOwnProperty.call(out, key)) out[key] += 1;
    }
    return out;
  }, [validationReport]);
  const filteredLabels = useMemo(() => {
    const needle = labelSearch.trim().toLowerCase();
    const deduped = [];
    const seen = new Set();
    for (const item of labelsCatalog) {
      const k = String(item?.label_key || "");
      if (!k || seen.has(k)) continue;
      seen.add(k);
      deduped.push(item);
    }
    if (!needle) return deduped;
    return deduped.filter((item) => (`${item.label_key} ${item.label_name}`).toLowerCase().includes(needle));
  }, [labelsCatalog, labelSearch]);
  const labelReportRows = useMemo(() => {
    return Array.isArray(mappingReport?.labels) ? mappingReport.labels : [];
  }, [mappingReport]);
  useEffect(() => {
    const next = {};
    for (const row of (mappingReport?.labels || [])) {
      const first = Array.isArray(row?.entities) ? row.entities[0] : null;
      if (!row?.label_key || !first?.cad_handle) continue;
      next[row.label_key] = {
        handle: first.cad_handle,
        source: "persisted",
        confidence: first.confidence ?? null,
        at: Date.now(),
      };
    }
    if (Object.keys(next).length) {
      setAcceptedSuggestionState((prev) => ({ ...next, ...prev }));
    }
  }, [mappingReport]);
  const activeLabelReport = useMemo(() => {
    return labelReportRows.find((row) => row.label_key === activeLabelKey) || null;
  }, [labelReportRows, activeLabelKey]);
  const filteredCadEntities = useMemo(() => {
    const needle = entitySearch.trim().toLowerCase();
    if (!needle) return cadEntities.slice(0, 500);
    return cadEntities.filter((entity) => {
      const text = `${entity.handle || ""} ${entity.layer_name || ""} ${entity.entity_type || ""} ${entity.text_content || ""}`.toLowerCase();
      return text.includes(needle);
    }).slice(0, 500);
  }, [cadEntities, entitySearch]);
  const selectedHandleSet = useMemo(() => new Set(selectedEntityHandles), [selectedEntityHandles]);
  const selectedCadEntities = useMemo(
    () => cadEntities.filter((item) => selectedHandleSet.has(item.handle)),
    [cadEntities, selectedHandleSet]
  );
  const selectedMeasurementSummary = useMemo(() => {
    const totals = { length: 0, area: 0, width: 0, height: 0 };
    for (const entity of selectedCadEntities) {
      const m = entity.measurement_json || {};
      totals.length += Number(m.measured_length || 0);
      totals.area += Number(m.measured_area || 0);
      totals.width += Number(m.measured_width || 0);
      totals.height += Number(m.measured_height || 0);
    }
    return totals;
  }, [selectedCadEntities]);
  const expertLabelSummaryByKey = useMemo(() => {
    const out = {};
    for (const row of (expertReport?.labels || [])) {
      out[row.label_key] = row;
    }
    return out;
  }, [expertReport]);
  useEffect(() => {
    refreshLayerHighlight(selectedLayer, selectedRuleRelatedLayers);
  }, [selectedLayer, selectedLayerSet, selectedRuleRelatedLayers]);
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene) return;
    pickableObjectsRef.current = Object.values(entityObjectsRef.current || {});
    for (const obj of markingOverlaysRef.current) {
      scene.remove(obj);
      obj.geometry?.dispose?.();
      obj.material?.dispose?.();
    }
    markingOverlaysRef.current = [];

    for (const marking of expertMarkings) {
      const pts = Array.isArray(marking?.points_json) ? marking.points_json : [];
      if (!pts.length) continue;
      const isSelectedMarking = Number(marking.id) === Number(selectedMarkingId);
      const color = isSelectedMarking ? 0x0b3d91 : (marking.status === "confirmed" ? 0x0f6b5f : 0xe1ad01);
      const vectors = pts.map((p) => new THREE.Vector3(Number(p.x || 0), Number(p.y || 0), 0));
      let obj = null;
      if (marking.geometry_type === "point") {
        obj = new THREE.Points(
          new THREE.BufferGeometry().setFromPoints(vectors),
          new THREE.PointsMaterial({ color, size: isSelectedMarking ? 8 : 5, sizeAttenuation: false })
        );
      } else {
        const linePts = marking.geometry_type === "polygon" || marking.geometry_type === "rectangle"
          ? [...vectors, vectors[0]]
          : vectors;
        obj = new THREE.Line(
          new THREE.BufferGeometry().setFromPoints(linePts),
          new THREE.LineBasicMaterial({ color, linewidth: isSelectedMarking ? 4 : 2 })
        );
      }
      obj.userData = { expertMarkingId: marking.id };
      scene.add(obj);
      markingOverlaysRef.current.push(obj);
      pickableObjectsRef.current.push(obj);
    }
    render();
  }, [expertMarkings, selectedMarkingId]);
  useEffect(() => {
    const mappedHandles = new Set();
    const activeMappedHandles = new Set();
    for (const row of (mappingReport?.labels || [])) {
      for (const entity of (row.entities || [])) {
        if (!entity?.cad_handle) continue;
        mappedHandles.add(entity.cad_handle);
        if (row.label_key === activeLabelKey) {
          activeMappedHandles.add(entity.cad_handle);
        }
      }
    }
    for (const [handle, obj] of Object.entries(entityObjectsRef.current || {})) {
      if (!obj?.material) continue;
      const selected = selectedEntityHandles.includes(handle);
      if (selected) {
        obj.material.color.set(0x0b3d91);
        obj.material.opacity = 1;
      } else if (activeMappedHandles.has(handle)) {
        obj.material.color.set(0x0f6b5f);
        obj.material.opacity = 1;
      } else if (handle === hoveredEntityHandle) {
        obj.material.color.set(0x44546a);
        obj.material.opacity = 0.95;
      } else if (mappedHandles.has(handle)) {
        obj.material.color.set(0x1f6feb);
        obj.material.opacity = 0.9;
      } else {
        obj.material.color.set(0xa8b0ba);
        obj.material.opacity = 0.65;
      }
      obj.material.transparent = true;
      obj.material.needsUpdate = true;
    }
    render();
  }, [activeLabelKey, hoveredEntityHandle, mappingReport, selectedEntityHandles]);
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene) return;
    for (const obj of selectedEntityOverlaysRef.current) {
      scene.remove(obj);
      obj.geometry?.dispose?.();
      obj.material?.map?.dispose?.();
      obj.material?.dispose?.();
    }
    selectedEntityOverlaysRef.current = [];

    for (const entity of selectedCadEntities) {
      let points = Array.isArray(entity?.geometry_json?.points) ? entity.geometry_json.points : [];
      const bbox = entity?.bbox_json || null;
      if ((!Array.isArray(points) || points.length < 3) && bbox) {
        const minX = Number(bbox.min_x ?? bbox.minX);
        const maxX = Number(bbox.max_x ?? bbox.maxX);
        const minY = Number(bbox.min_y ?? bbox.minY);
        const maxY = Number(bbox.max_y ?? bbox.maxY);
        if (Number.isFinite(minX) && Number.isFinite(maxX) && Number.isFinite(minY) && Number.isFinite(maxY) && maxX > minX && maxY > minY) {
          points = [[minX, minY], [maxX, minY], [maxX, maxY], [minX, maxY]];
        }
      }
      if (!Array.isArray(points) || points.length < 2) continue;
      const vectors = points
        .map((p) => (Array.isArray(p) && p.length >= 2 ? new THREE.Vector3(Number(p[0]) || 0, Number(p[1]) || 0, 0) : null))
        .filter(Boolean);
      if (vectors.length < 2) continue;

      const isClosed = !!entity?.is_closed && vectors.length >= 3;
      if (isClosed) {
        const shape = new THREE.Shape(vectors.map((v) => new THREE.Vector2(v.x, v.y)));
        const fill = new THREE.Mesh(
          new THREE.ShapeGeometry(shape),
          new THREE.MeshBasicMaterial({
            color: 0x0b3d91,
            transparent: true,
            opacity: 0.2,
            depthWrite: false,
            side: THREE.DoubleSide,
          })
        );
        fill.position.z = 0.02;
        scene.add(fill);
        selectedEntityOverlaysRef.current.push(fill);

        const outlinePts = [...vectors, vectors[0]];
        const outline = new THREE.Line(
          new THREE.BufferGeometry().setFromPoints(outlinePts),
          new THREE.LineBasicMaterial({ color: 0x0b3d91, transparent: true, opacity: 1 })
        );
        outline.position.z = 0.03;
        scene.add(outline);
        selectedEntityOverlaysRef.current.push(outline);
      } else {
        const thickLine = new THREE.Line(
          new THREE.BufferGeometry().setFromPoints(vectors),
          new THREE.LineBasicMaterial({ color: 0x0b3d91, transparent: true, opacity: 1 })
        );
        thickLine.position.z = 0.03;
        scene.add(thickLine);
        selectedEntityOverlaysRef.current.push(thickLine);
      }
    }

    if (learningSourceText) {
      const sourceHandle = String(learningSourceText.handle || "");
      const sourceText = normalizeDxfText(learningSourceText.text);
      const sourceLayer = String(learningSourceText.layer || "").toLowerCase();
      const sourceEntity = cadEntities.find((entity) => (
        sourceHandle && String(entity.handle || "") === sourceHandle
      )) || cadEntities.find((entity) => (
        sourceText
        && normalizeDxfText(entity.text_content) === sourceText
        && (!sourceLayer || String(entity.layer_name || "").toLowerCase() === sourceLayer)
      ));
      const sourceBounds = sourceEntity ? entityBounds(sourceEntity) : null;
      const sourceX = finiteCadCoordinate(learningSourceText.x);
      const sourceY = finiteCadCoordinate(learningSourceText.y);
      const x = Number.isFinite(sourceX)
        ? sourceX
        : (sourceBounds ? (sourceBounds.minX + sourceBounds.maxX) / 2 : null);
      const y = Number.isFinite(sourceY)
        ? sourceY
        : (sourceBounds ? (sourceBounds.minY + sourceBounds.maxY) / 2 : null);

      if (Number.isFinite(x) && Number.isFinite(y)) {
        const highlight = makeTextSprite(
          `SELECTED TEXT • ${learningSourceText.text}`,
          "#6b2600",
          {
            background: "rgba(255, 232, 92, 0.96)",
            border: "rgba(198, 40, 40, 1)",
            borderWidth: 2,
            fontWeight: 800,
            padding: 5,
          }
        );
        if (highlight) {
          highlight.position.set(x, y, 12);
          highlight.renderOrder = 1200;
          highlight.userData.isCadTextHighlight = true;
          scene.add(highlight);
          selectedEntityOverlaysRef.current.push(highlight);
        }
      }
    }

    render();
  }, [cadEntities, learningSourceText, selectedCadEntities]);
  const flowStatus = useMemo(() => {
    const hasUpload = !!config.submissionId;
    const hasDxf = !!config.hasDxf;
    const hasMapped = !!config.mapDrawingId;
    const hasValidation = !!validationReport;
    const vStatus = validationReport?.status || null;
    const needsReview = mapSummary?.needs_review_entities > 0 || (validationCounts.needs_review || 0) > 0;
    const hasFail = (validationCounts.fail || 0) > 0;

    return [
      { step: "Applicant uploads DWG plans", status: hasUpload ? "done" : "pending" },
      { step: "System converts DWG to DXF", status: hasDxf ? "done" : "needs_review" },
      { step: "CAD engine extracts plot/floor/setbacks", status: hasMapped ? "done" : "pending" },
      { step: "Rules engine checks bylaws", status: hasValidation ? "done" : "pending" },
      {
        step: "Confidence decision",
        status: !hasValidation ? "pending" : (vStatus === "ready_for_submission" ? "auto_pass" : hasFail ? "needs_correction" : "needs_expert_review"),
      },
      { step: "Report generated", status: hasValidation ? "done" : "pending" },
      { step: "Chatbot explains report", status: "pending" },
      { step: "Expert corrections saved as training data", status: needsReview || hasFail ? "in_progress" : "done" },
      { step: "Model improves over time", status: "future" },
    ];
  }, [config.submissionId, config.hasDxf, config.mapDrawingId, validationReport, mapSummary, validationCounts]);
  function sendLayerMappingSuggestion(officialTag) {
    const layerNames = (selectedLayers.length ? selectedLayers : [selectedLayer].filter(Boolean)).filter(Boolean);
    if (!layerNames.length || !officialTag) return;
    const primaryLayer = layerNames[0];
    const messageTarget = window.opener || window.parent;

    messageTarget?.postMessage(
      {
        type: "cad-layer-map-suggestion",
        payload: {
          floorContext,
          officialTag,
          layerName: primaryLayer,
          layerNames,
          layerLabel: layerMeta[primaryLayer]?.tag || "",
        },
      },
      window.location.origin
    );

    setStatusMessage(`Sent ${layerNames.join(", ")} to the parent mapper as ${officialTag}.`);
  }

  async function applyQuickLayerMark(tagValue = quickMarkTag) {
    if (applyingQuickMarkRef.current) return;
    const tag = String(tagValue || "").trim();
    if (!tag) {
      const message = "Choose a layer type before applying the mapping.";
      setQuickMarkFeedback({ type: "error", message });
      setStatusMessage(message);
      return;
    }
    if (!quickMarkOptions.some((option) => option.value === tag)) {
      const message = "Choose a valid layer type before applying the mapping.";
      setQuickMarkFeedback({ type: "error", message });
      setStatusMessage(message);
      return;
    }

    const layerNames = quickMarkSelectedLayers.length ? quickMarkSelectedLayers : [selectedLayer].filter(Boolean);
    if (!layerNames.length || layerNames.some((layerName) => !layerMeta[layerName])) {
      const message = "Select a valid CAD layer before applying the mapping.";
      setQuickMarkFeedback({ type: "error", message });
      setStatusMessage(message);
      return;
    }
    if (!config.storeUrl) {
      const message = "The layer mapping endpoint is unavailable. Reload the CAD view and try again.";
      setQuickMarkFeedback({ type: "error", message });
      setStatusMessage(message);
      return;
    }

    const nextLayerMeta = { ...layerMeta };
    layerNames.forEach((layerName) => {
      nextLayerMeta[layerName] = { ...nextLayerMeta[layerName], tag };
    });

    applyingQuickMarkRef.current = true;
    setApplyingQuickMark(true);
    setQuickMarkFeedback(null);
    try {
      const response = await fetch(config.storeUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "X-CSRF-TOKEN": config.csrfToken,
        },
        body: JSON.stringify({ layer_map_json: JSON.stringify(nextLayerMeta) }),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) {
        throw new Error(result.message || result.errors?.layer_map_json?.[0] || "The layer mapping could not be saved.");
      }

      const refreshedLayerMap = normalizePersistedLayerMap(result.layer_map || nextLayerMeta);
      setLayerMeta(refreshedLayerMap);
      layerMetaRef.current = refreshedLayerMap;
      if (result.identification_report) {
        setLayerIdentificationReport(result.identification_report);
      }
      setQuickMarkTag(tag);
      const label = resolveTagLabel(tag, tagOptions, suggestedOfficialMappings);
      const message = result.message || `Mapped ${layerNames.join(", ")} as ${label}.`;
      setQuickMarkFeedback({ type: "success", message });
      setStatusMessage(message);
      render();
      resizeFnRef.current?.();
    } catch (error) {
      const message = error?.message || "The layer mapping could not be saved. Please try again.";
      setQuickMarkFeedback({ type: "error", message });
      setStatusMessage(message);
    } finally {
      applyingQuickMarkRef.current = false;
      setApplyingQuickMark(false);
    }
  }

  const selectedPrediction = taggingWorkspace.predictions.find((row) => Number(row.id) === Number(selectedPredictionId)) || null;
  const visiblePredictions = taggingWorkspace.predictions.filter((row) => {
    if (predictionStatusFilter === "all") return true;
    if (predictionStatusFilter === "unreviewed") return ["unreviewed", "ai_suggested"].includes(row.status);
    return row.status === predictionStatusFilter;
  });
  const aiTextMetricRows = Object.entries(cadTextReport.metrics || {})
    .filter(([, value]) => value !== null && value !== "" && (typeof value === "number" || typeof value === "string"))
    .slice(0, 14);
  const aiTextFindings = cadTextMeasurements
    .filter((row) => row.value_ft != null || row.semantic_hints.length)
    .slice(0, 30);

  return (
    <div className={`layout${showAdvancedReviewTools ? "" : " officer-simple-layout"}`}>
      <div className="sidebar" style={{ width: "25%", minWidth: 320 }}>
        <div className="card" style={{ border: "1px solid #dfe7ef", borderRadius: 12, padding: 10, marginBottom: 10, background: "#f8fbff", position: "sticky", top: 8, zIndex: 2 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 8, flexWrap: "wrap", alignItems: "center", marginBottom: 8 }}>
            <div>
              <div style={{ fontWeight: 700 }}>Floor marking wizard</div>
              <div className="muted">Start at ground floor, capture the template, then move floor by floor.</div>
            </div>
            <span className="pill">{humanFloorContext(floorWizard.activeFloor)} context</span>
          </div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 8 }}>
            {FLOOR_SEQUENCE.map((floor) => (
              <a
                key={floor}
                className={`pill${floor === floorWizard.activeFloor ? " active" : ""}`}
                href={buildFloorContextUrl(floor)}
                style={{ textDecoration: "none" }}
                title={`Open ${humanFloorContext(floor)} context`}
              >
                {humanFloorContext(floor)}
              </a>
            ))}
          </div>
          <div className="muted" style={{ marginBottom: 8 }}>
            Current step {floorWizard.activeFloorIndex + 1} of {FLOOR_SEQUENCE.length}. Next floor: <b>{humanFloorContext(floorWizard.nextFloor)}</b>.
          </div>
          {!hasFloorSpecificLayers ? (
            <div style={{ border: "1px solid #f2c94c", background: "#fff8df", borderRadius: 8, padding: 8, marginBottom: 8, fontSize: 12 }}>
              Floor-specific CAD layers were not detected. Manually select entities; confidence is reduced and based on geometry/text references.
            </div>
          ) : null}
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
            <button type="button" onClick={() => captureCurrentFloorTemplate()}>
              Capture template
            </button>
            <a href={buildFloorContextUrl(floorWizard.nextFloor)} style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 10px", borderRadius: 8, border: "1px solid #d0d7de", textDecoration: "none", color: "#111" }}>
              Go to next floor
            </a>
          </div>
        </div>

        <h3 style={{ margin: "0 0 6px 0" }}>Rule Labels</h3>
        <div className="muted">Each label can map to multiple CAD entities by handle.</div>
        <input
          type="text"
          value={labelSearch}
          onChange={(e) => setLabelSearch(e.target.value)}
          placeholder="Search labels"
          style={{ width: "100%", marginTop: 8, marginBottom: 8 }}
        />
        <div style={{ maxHeight: 220, overflow: "auto", border: "1px solid #eee", borderRadius: 10, padding: 6, marginBottom: 8 }}>
          {loadingLabels ? <div className="muted">Loading labels...</div> : filteredLabels.map((item, idx) => {
            const expertRow = expertLabelSummaryByKey[item.label_key];
            const status = expertRow?.status || "not_marked";
            const totals = expertRow?.totals || {};
            return (
            <div
              key={`${item.label_key}-${idx}`}
              onClick={() => selectActiveLabel(item.label_key)}
              style={{
                padding: 8,
                borderRadius: 8,
                marginBottom: 6,
                border: activeLabelKey === item.label_key ? "1px solid #0b3d91" : "1px solid #eee",
                background: activeLabelKey === item.label_key ? "rgba(11,61,145,0.06)" : "#fff",
                cursor: "pointer",
              }}
            >
              <div style={{ display: "flex", alignItems: "center", gap: 7, fontWeight: 600 }}>
                <span style={{ width: 10, height: 10, borderRadius: "50%", background: item.colour || "#64748b", flex: "0 0 auto" }} />
                {item.label_name}
              </div>
              <div className="muted">{item.category || "Other"} · {item.label_key}</div>
              <div style={{ display: "flex", gap: 6, marginTop: 4, alignItems: "center" }}>
                <span className="pill" style={{ background: item.required ? "rgba(178,28,28,0.1)" : "rgba(15,107,95,0.12)", color: item.required ? "#b21c1c" : "#0f6b5f" }}>
                  {item.required ? "Required" : "Optional"}
                </span>
                <span className="muted">
                  {status === "confirmed" ? "Confirmed" : status === "draft" ? "Draft" : "Not marked"}
                </span>
                <span className="muted">Tags {item.tagged_count || 0} · AI {item.unverified_count || 0}{item.average_confidence != null ? ` · ${Math.round(Number(item.average_confidence) * 100)}%` : ""}</span>
              </div>
              {expertRow ? (
                <div className="muted" style={{ marginTop: 4 }}>
                  Area {Number(totals.area || 0).toFixed(2)} • Perimeter {Number(totals.perimeter || 0).toFixed(2)} • Length {Number(totals.length || 0).toFixed(2)}
                </div>
              ) : null}
            </div>
          );})}
        </div>
        <input
          type="text"
          value={entitySearch}
          onChange={(e) => setEntitySearch(e.target.value)}
          placeholder="Search CAD entities (layer/handle/type/text)"
          style={{ width: "100%", marginBottom: 8 }}
        />
        <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 10 }}>
          <button type="button" onClick={mapSelectedEntitiesToActiveLabel} disabled={!activeLabelKey || !selectedEntityHandles.length || mappingBusy}>
            Map Selected to Active Label
          </button>
          <button type="button" onClick={runAutoSuggestMappings} disabled={mappingBusy}>
            Auto-suggest mappings
          </button>
        </div>
        <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
          <div style={{ fontWeight: 600, marginBottom: 6 }}>Selected entities</div>
          <div className="muted" style={{ marginBottom: 6 }}>
            {selectedEntityHandles.length} selected {selectedEntityHandles.length ? `• length ${selectedMeasurementSummary.length.toFixed(2)} • area ${selectedMeasurementSummary.area.toFixed(2)}` : ""}
          </div>
          <div style={{ maxHeight: 150, overflow: "auto", display: "grid", gap: 6 }}>
            {selectedCadEntities.slice(0, 20).map((entity) => (
              <div key={entity.id} style={{ fontSize: 12, border: "1px solid #dfe7ef", borderRadius: 8, padding: 6, background: "#f8fbff" }}>
                <div style={{ display: "flex", gap: 6, justifyContent: "space-between", alignItems: "center" }}>
                  <button
                    type="button"
                    onClick={() => zoomToEntity(entity)}
                    style={{ padding: 0, border: 0, background: "transparent", fontWeight: 700, textAlign: "left", cursor: "pointer" }}
                    title="Zoom to selected entity"
                  >
                    {entity.handle}
                  </button>
                  <button type="button" onClick={() => unselectEntity(entity.handle)} title="Unselect entity" style={{ padding: "1px 6px" }}>
                    x
                  </button>
                </div>
                <div className="muted">{entity.layer_name} · {entity.entity_type}</div>
                <div className="muted">
                  Area {Number(entity?.measurement_json?.measured_area || entity?.area || 0).toFixed(2)}
                  {" "}· Length {Number(entity?.measurement_json?.measured_length || entity?.perimeter || 0).toFixed(2)}
                </div>
              </div>
            ))}
          </div>
        </div>
        {activeLabelReport ? (
          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Mapped handles for active label</div>
            <div style={{ maxHeight: 140, overflow: "auto", display: "grid", gap: 6 }}>
              {(activeLabelReport.entities || []).map((entity) => (
                <div key={`${entity.mapping_id}-${entity.cad_handle}`} style={{ border: "1px solid #eee", borderRadius: 8, padding: 6 }}>
                  <div style={{ fontWeight: 600 }}>{entity.cad_handle}</div>
                  <div className="muted">{entity.cad_layer} · {entity.entity_type}</div>
                  <button type="button" onClick={() => unmapMapping(entity.mapping_id)} style={{ marginTop: 6 }}>Unmap</button>
                </div>
              ))}
            </div>
          </div>
        ) : null}
        <div className="muted" style={{ marginBottom: 6 }}>Entity search results: {filteredCadEntities.length}</div>
        <div style={{ maxHeight: 220, overflow: "auto", border: "1px solid #eee", borderRadius: 10, padding: 6, marginBottom: 10, background: "#fff" }}>
          {filteredCadEntities.slice(0, 120).map((entity) => {
            const selected = selectedHandleSet.has(entity.handle);
            const activeMapped = (activeLabelReport?.entities || []).some((row) => row.cad_handle === entity.handle);
            return (
              <div
                key={`entity-row-${entity.handle || entity.id}`}
                ref={(node) => {
                  if (node && entity.handle) {
                    entityRowRefs.current[entity.handle] = node;
                  } else if (entity.handle) {
                    delete entityRowRefs.current[entity.handle];
                  }
                }}
                onClick={(event) => {
                  selectEntityCandidate({
                    handle: entity.handle,
                    layer: entity.layer_name,
                    entityType: entity.entity_type,
                    source: "panel",
                    score: 0,
                  }, { additive: event.ctrlKey || event.metaKey });
                  if (entity.layer_name) selectLayer(entity.layer_name, { additive: event.ctrlKey || event.metaKey });
                }}
                onDoubleClick={() => zoomToEntity(entity)}
                style={{
                  border: selected ? "1px solid #0b3d91" : activeMapped ? "1px solid #0f6b5f" : "1px solid #eee",
                  background: selected ? "rgba(11,61,145,0.08)" : activeMapped ? "rgba(15,107,95,0.08)" : "#fff",
                  borderRadius: 8,
                  padding: 7,
                  marginBottom: 6,
                  cursor: "pointer",
                  fontSize: 12,
                }}
              >
                <div style={{ display: "flex", justifyContent: "space-between", gap: 6 }}>
                  <strong>{entity.handle || "no-handle"}</strong>
                  <span className="muted">{entity.entity_type}</span>
                </div>
                <div className="muted">{entity.layer_name || "Unknown layer"}</div>
                {activeMapped ? <div style={{ color: "#0f6b5f", fontWeight: 700 }}>Mapped to active label</div> : null}
              </div>
            );
          })}
        </div>
        <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 10 }}>
          <div style={{ fontWeight: 600, marginBottom: 6 }}>View mode</div>
          <div className="muted" style={{ marginBottom: 8 }}>
            Default hides dimensions, sections, elevations, hatches, and reference clutter.
          </div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            <button
              type="button"
              onClick={() => applyViewMode("approval")}
              style={{ fontWeight: viewMode === "approval" ? 700 : 500 }}
            >
              Mapped only
            </button>
            <button
              type="button"
              onClick={() => applyViewMode("floor")}
              style={{ fontWeight: viewMode === "floor" ? 700 : 500 }}
            >
              Floor geometry
            </button>
            <button
              type="button"
              onClick={() => applyViewMode("reference")}
              style={{ fontWeight: viewMode === "reference" ? 700 : 500 }}
            >
              Reference only
            </button>
            <button
              type="button"
              onClick={() => applyViewMode("all")}
              style={{ fontWeight: viewMode === "all" ? 700 : 500 }}
            >
              All CAD
            </button>
            <button
              type="button"
              onClick={() => {
                setViewMode("approval");
                forceMappedEntitiesVisible();
                requestAnimationFrame(() => fitView());
              }}
            >
              Reset mapped view
            </button>
          </div>
        </div>
        <div className="card" style={{ border: measureMode ? "1px solid #0f6b5f" : "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 10 }}>
          <div style={{ fontWeight: 600, marginBottom: 6 }}>Planner measuring tool</div>
          <div className="muted" style={{ marginBottom: 8 }}>
            Turn on measure, click two points on the plan, then send the value to the selected rule.
          </div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
            <button type="button" onClick={toggleMeasureMode}>
              {measureMode ? "Stop measuring" : "Start measuring"}
            </button>
            <button type="button" onClick={resetMeasure}>Clear</button>
            <button type="button" onClick={useMeasuredDistanceForRule} disabled={!Number.isFinite(scaledDistance)}>
              Use for rule
            </button>
          </div>
          <div style={{ marginTop: 8, display: "grid", gap: 4 }}>
            <div>Distance: <strong>{scaledDistanceLabel}</strong></div>
            <div className="muted">Clicks: {measurePoints.length}/2 · Scale: {scaleLabel}</div>
          </div>
          <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: 8 }}>
            <button type="button" onClick={() => { setScaleTouched(true); setScaleMultiplier(1); setScaleLabel("CAD units x1"); }}>CAD units</button>
            <button type="button" onClick={() => { setScaleTouched(true); setScaleMultiplier(1 / 12); setScaleLabel("inches → feet"); }}>Inches to feet</button>
          </div>
        </div>
        {floorContext ? (
          <div className="card" style={{ marginTop: 8, border: "1px solid #dfe7ef", borderRadius: 12, padding: 10, background: "#f8fbff" }}>
            <div style={{ display: "flex", justifyContent: "space-between", gap: 8, flexWrap: "wrap", alignItems: "center", marginBottom: 8 }}>
              <div>
                <div style={{ fontWeight: 700 }}>Repeated floor guidance</div>
                <div className="muted">Use this if the same stair, wall, door, or slab pattern repeats on the next floor.</div>
              </div>
              <span className="pill">{humanFloorContext(floorContext)} context</span>
            </div>
            {floorWizard.hasGroup ? (
              <>
                <div style={{ fontWeight: 600, marginBottom: 6 }}>Repeated layers across floors</div>
                {floorWizard.crossFloorMatches.length ? (
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 8 }}>
                    {floorWizard.crossFloorMatches.slice(0, 8).map((item) => (
                      <button
                        key={item.layer}
                        type="button"
                        onClick={() => addLayersToSelection([item.layer])}
                        title={`Add ${item.layer} to the current group`}
                      >
                        {item.layer}
                      </button>
                    ))}
                  </div>
                ) : (
                  <div className="muted" style={{ marginBottom: 8 }}>No strong cross-floor repeats found for the current selection yet.</div>
                )}
                {floorWizard.sameFloorMatches.length ? (
                  <div className="muted" style={{ marginBottom: 8 }}>
                    Same-floor companions: {floorWizard.sameFloorMatches.slice(0, 4).map((item) => item.layer).join(", ")}
                  </div>
                ) : null}
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  <button type="button" onClick={() => addLayersToSelection(floorWizard.crossFloorMatches.map((item) => item.layer))} disabled={!floorWizard.crossFloorMatches.length}>
                    Add all repeats
                  </button>
                  <a href={buildFloorContextUrl(floorWizard.nextFloor)} style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "7px 10px", borderRadius: 8, border: "1px solid #d0d7de", textDecoration: "none", color: "#111" }}>
                    Continue to next floor
                  </a>
                </div>
              </>
            ) : (
              <div className="muted">Pick a layer or select a layer group to see repeated components on other floors.</div>
            )}
            <div style={{ marginTop: 12, paddingTop: 12, borderTop: "1px dashed #d7dde5" }}>
              <div style={{ fontWeight: 600, marginBottom: 6 }}>Persistent floor template</div>
              {floorWizard.savedTemplate ? (
                <div style={{ display: "grid", gap: 8 }}>
                  <div className="muted">
                    Saved template for this floor. Layers: {Array.isArray(floorWizard.savedTemplate.layer_names) ? floorWizard.savedTemplate.layer_names.length : 0}
                    {Array.isArray(floorWizard.savedTemplate.entity_handles) ? ` · Handles: ${floorWizard.savedTemplate.entity_handles.length}` : ""}
                  </div>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    <button type="button" onClick={() => applySavedFloorTemplate(floorWizard.savedTemplate, floorWizard.activeFloor)}>
                      Apply saved template
                    </button>
                    <button type="button" onClick={captureCurrentFloorTemplate}>
                      Update template from current selection
                    </button>
                  </div>
                </div>
              ) : (
                <div style={{ display: "grid", gap: 8 }}>
                  <div className="muted">
                    No saved template for {humanFloorContext(floorWizard.activeFloor)} yet. Capture the current selection so it can be reused on the next floor.
                  </div>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    <button type="button" onClick={captureCurrentFloorTemplate}>
                      Capture current floor template
                    </button>
                    {floorWizard.previousSavedTemplate ? (
                      <button
                        type="button"
                        onClick={() => applySavedFloorTemplate(floorWizard.previousSavedTemplate, floorWizard.previousFloor)}
                      >
                        Apply previous floor template
                      </button>
                    ) : null}
                  </div>
                </div>
              )}
            </div>
          </div>
        ) : null}

        <div style={{ margin: "10px 0", display: "flex", gap: 8, flexWrap: "wrap" }}>
          <button type="button" onClick={showAll}>Show all</button>
          <button type="button" onClick={hideAll}>Hide all</button>
          <button type="button" onClick={fitView}>Fit view</button>
          <button type="button" onClick={() => zoomBy(1.15)}>Zoom in</button>
          <button type="button" onClick={() => zoomBy(0.87)}>Zoom out</button>
          <button type="button" onClick={snapshot}>Snapshot</button>
        </div>

        {selectedLayer ? (
          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Selected layer</div>
            <div style={{ marginBottom: 6 }}>{selectedLayer}</div>
            <div className="muted" style={{ marginBottom: 8 }}>
              {layerMeta[selectedLayer]?.tag
                ? `Tag: ${resolveTagLabel(layerMeta[selectedLayer].tag, tagOptions, suggestedOfficialMappings)}`
                : "No tag assigned."}
            </div>
            {suggestedOfficialMappings.length ? (
              <>
                <div style={{ fontWeight: 600, marginBottom: 6 }}>Quick map to official layer</div>
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 10 }}>
                  {suggestedOfficialMappings.map((item) => (
                    <button
                      key={item.tag}
                      type="button"
                      onClick={() => sendLayerMappingSuggestion(item.tag)}
                      title={`Map ${selectedLayer} to ${item.tag}`}
                      style={{ padding: "7px 10px", fontSize: 12 }}
                    >
                      {item.label}
                    </button>
                  ))}
                </div>
              </>
            ) : null}
            {layerMeta[selectedLayer]?.tag ? (
              <div className="muted" style={{ marginBottom: 8 }}>
                Semantic match: {resolveTagLabel(layerMeta[selectedLayer].tag, tagOptions, suggestedOfficialMappings)}
                {getTagOption(layerMeta[selectedLayer].tag, tagOptions).aliases.length ? ` • aliases: ${getTagOption(layerMeta[selectedLayer].tag, tagOptions).aliases.join(", ")}` : ""}
              </div>
            ) : null}
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Related rules</div>
            {selectedLayerRules.length ? (
              <div style={{ display: "grid", gap: 8 }}>
                {selectedLayerRules.map((rule) => (
                  <div key={rule.id} style={{ padding: 8, borderRadius: 10, background: "#fafafa", border: "1px solid #eee" }}>
                    <div style={{ fontWeight: 600 }}>{rule.id} — {rule.title}</div>
                    <div className="muted">Required: {rule.operator} {rule.required_value} {rule.unit || ""}</div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="muted">No related numeric rules found for this layer.</div>
            )}
            <div style={{ fontWeight: 600, margin: "12px 0 6px" }}>Matched textual values</div>
            {selectedLayerTextMatches.length ? (
              <div style={{ display: "grid", gap: 6 }}>
                {selectedLayerTextMatches.map((item, idx) => (
                  <div key={`${item.layer}-${idx}`} style={{ padding: 8, borderRadius: 10, background: "#fafafa", border: "1px solid #eee" }}>
                    <div style={{ fontSize: 13 }}>{item.text}</div>
                    <div className="muted">Layer: {item.layer}</div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="muted">No textual values matched the current dropdown option yet.</div>
            )}
          </div>
        ) : null}

        <div className="card" style={{ border: "1px solid #b9dfd7", borderRadius: 10, padding: 10, marginBottom: 10, background: "#f3fbf9" }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 8, alignItems: "center", marginBottom: 6 }}>
            <div style={{ fontWeight: 700 }}>Updated AI object identification</div>
            <span className="pill">{Number(layerIdentificationReport.object_count || 0)} identified</span>
          </div>
          <div className="muted" style={{ marginBottom: 8 }}>
            This report updates after the officer applies a layer mark. Confirmed mappings are captured as expert training labels; model retraining remains a separate governed step.
          </div>
          {(layerIdentificationReport.objects || []).length ? (
            <div style={{ maxHeight: 220, overflow: "auto", border: "1px solid #d9ebe7", borderRadius: 8, background: "#fff" }}>
              <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12 }}>
                <thead><tr style={{ background: "#edf8f5" }}><th style={{ textAlign: "left", padding: 7 }}>CAD layer</th><th style={{ textAlign: "left", padding: 7 }}>Identified object</th><th style={{ textAlign: "left", padding: 7 }}>Status</th></tr></thead>
                <tbody>
                  {(layerIdentificationReport.objects || []).map((row) => (
                    <tr key={`${row.cad_layer}-${row.object_key}`}>
                      <td style={{ padding: 7, borderTop: "1px solid #edf1f0" }}>{row.cad_layer}</td>
                      <td style={{ padding: 7, borderTop: "1px solid #edf1f0", fontWeight: 700 }}>{row.object_name || row.object_key}</td>
                      <td style={{ padding: 7, borderTop: "1px solid #edf1f0" }}>Officer verified / training saved</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="muted">No officer-verified object identifications have been saved yet.</div>
          )}
        </div>

        <form method="POST" action={config.storeUrl}>
          <input type="hidden" name="_token" value={config.csrfToken} />
          <input type="hidden" name="layer_map_json" value={layerMapJson} readOnly />

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Layer dropdown mode</div>
            <div className="muted" style={{ marginBottom: 8 }}>
              Planner mode hides roof, structural, hatch, and AutoCAD reference options unless you need them.
            </div>
            <button type="button" onClick={() => setShowAdvancedTagOptions((value) => !value)}>
              {showAdvancedTagOptions ? "Use planner options" : "Show advanced layer options"}
            </button>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div className="muted">Quick preset:</div>
            <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
              <button type="button" onClick={preset5m}>5 Marla preset</button>
              <button type="button" onClick={clearTags}>Clear tags</button>
            </div>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Bulk map multiple CAD layers</div>
            <div className="muted" style={{ marginBottom: 8 }}>
              Assign one semantic layer to many CAD layers in one step.
            </div>
            <input
              type="text"
              value={layerFilter}
              onChange={(e) => setLayerFilter(e.target.value)}
              placeholder="Optional filter (e.g. wall, GF-, EXT)"
              style={{ width: "100%", marginBottom: 8 }}
            />
            <select
              value={bulkTag}
              onChange={(e) => setBulkTag(e.target.value)}
              style={{ width: "100%", marginBottom: 8 }}
            >
              {visibleTagOptions.map((opt) => (
                <option key={`bulk-${opt.value}`} value={opt.value}>{opt.label}</option>
              ))}
            </select>
            <label className="muted" style={{ display: "flex", gap: 8, alignItems: "center", marginBottom: 8 }}>
              <input
                type="checkbox"
                checked={bulkIncludeHidden}
                onChange={(e) => setBulkIncludeHidden(e.target.checked)}
              />
              Include hidden layers
            </label>
            <button type="button" onClick={applyBulkTagToLayers}>Apply tag to matching layers</button>
          </div>

          <div>
            {selectedLayers.length ? (
              <div className="card" style={{ border: "1px solid #dfe7ef", borderRadius: 10, padding: 10, marginBottom: 10, background: "#f8fbff" }}>
                <div style={{ fontWeight: 600, marginBottom: 4 }}>Selected layer group</div>
                <div className="muted" style={{ marginBottom: 8 }}>
                  {selectedLayers.length} layer{selectedLayers.length === 1 ? "" : "s"} selected. Use this when one entity is drawn across multiple CAD layers.
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 8 }}>
                  {selectedLayers.map((layer) => (
                    <span key={layer} className="pill">{layer}</span>
                  ))}
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  <button type="button" onClick={() => clearLayerSelection()}>Clear group</button>
                </div>
              </div>
            ) : null}
            {layerOrder.map((name) => (
              <div
                className={`layer-row${selectedLayerSet.has(name) ? " selected" : ""}`}
                key={name}
                ref={(node) => {
                  if (node) {
                    layerRowRefs.current[name] = node;
                  } else {
                    delete layerRowRefs.current[name];
                  }
                }}
                onClick={(event) => selectLayer(name, { additive: event.ctrlKey || event.metaKey })}
                style={{
                  borderLeft: selectedRuleRelatedLayers.includes(name) ? "3px solid #0b3d91" : undefined,
                  paddingLeft: selectedRuleRelatedLayers.includes(name) ? 10 : undefined,
                  background: selectedRuleRelatedLayers.includes(name) || selectedLayerSet.has(name) ? "rgba(11,61,145,0.06)" : undefined,
                }}
              >
                <input
                  type="checkbox"
                  checked={selectedLayerSet.has(name)}
                  onClick={(e) => e.stopPropagation()}
                  onChange={(e) => {
                    e.stopPropagation();
                    const checked = e.target.checked;
                    startUiTransition(() => {
                      setSelectedLayers((prev) => {
                        if (checked) {
                          if (prev.includes(name)) return prev;
                          const next = [...prev, name];
                          if (!selectedLayer) {
                            setSelectedLayer(name);
                          }
                          return next;
                        }
                        const next = prev.filter((layer) => layer !== name);
                        if (selectedLayer === name) {
                          setSelectedLayer(next[0] || "");
                        }
                        return next;
                      });
                    });
                  }}
                />
                <input
                  type="checkbox"
                  checked={!!layerMeta[name]?.visible}
                  onClick={(e) => e.stopPropagation()}
                  onChange={(e) => updateLayerMeta(name, { visible: e.target.checked })}
                />
                <div
                  className="layer-name"
                  title={name}
                  style={{ fontWeight: selectedRuleRelatedLayers.includes(name) ? 700 : 500, color: selectedRuleRelatedLayers.includes(name) ? "#0b3d91" : undefined }}
                >
                  {name}
                </div>
                <select
                  value={layerMeta[name]?.tag || ""}
                  onClick={(e) => e.stopPropagation()}
                  onChange={(e) => {
                    updateLayerMeta(name, { tag: e.target.value });
                    selectLayer(name);
                  }}
                >
                  {optionsForCurrentValue(layerMeta[name]?.tag || "").map((opt) => (
                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                  ))}
                </select>
              </div>
            ))}
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Expert measurement</div>
            <div className="muted">
              Capture measured values per rule and save PASS/FAIL results for training.
            </div>
            {statusMessage ? (
              <div style={{ marginTop: 8, fontSize: 12, color: "#0f6b5f" }}>{statusMessage}</div>
            ) : null}
            {rules.length ? (
              <div style={{ marginTop: 8, display: "grid", gap: 8 }}>
                <label className="muted">Rule</label>
                <select
                  value={selectedRuleId}
                  onChange={(event) => setSelectedRuleId(event.target.value)}
                >
                  {rules.map((rule) => (
                    <option key={rule.id} value={rule.id}>
                      {rule.id} — {rule.title}
                    </option>
                  ))}
                </select>
                {selectedRule ? (
                  <div className="muted">
                    Required: {selectedRule.operator} {selectedRule.required_value}
                    {selectedRule.unit ? ` ${selectedRule.unit}` : ""}
                  </div>
                ) : null}
                <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 8, background: "#fbfaf8" }}>
                  <div className="muted">System calculated value</div>
                  <div style={{ fontWeight: 700 }}>
                    {selectedSystemValue !== "" && selectedSystemValue !== null ? selectedSystemValue : "Run validation to show system value"}
                    {selectedSystemRuleResult?.unit ? ` ${selectedSystemRuleResult.unit}` : ""}
                  </div>
                  {selectedSystemRuleResult?.status ? (
                    <div className="muted">System status: {selectedSystemRuleResult.status}</div>
                  ) : null}
                </div>
                <label className="muted">Measured value</label>
                <input
                  type="number"
                  step="0.01"
                  value={measuredValue}
                  readOnly
                  placeholder="Use the CAD measuring tool"
                />
                <div className="muted">
                  Expert values can only be saved after measuring two points in the CAD viewer. Coordinates are saved for audit and training.
                </div>
                <label className="muted">Notes (optional)</label>
                <textarea
                  rows={2}
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  placeholder="Context for this measurement"
                />
                <div className="muted">
                  Result:{" "}
                  {previewPass == null ? (
                    <span className="muted">—</span>
                  ) : previewPass ? (
                    <span style={{ color: "#0f6b5f", fontWeight: 600 }}>PASS</span>
                  ) : (
                    <span style={{ color: "#b21c1c", fontWeight: 600 }}>FAIL</span>
                  )}
                </div>
                <button type="button" onClick={() => saveExpertResult()} disabled={savingResult || measurePoints.length !== 2 || !Number.isFinite(scaledDistance)}>
                  {savingResult ? "Saving..." : "Save expert result"}
                </button>
              </div>
            ) : (
              <div className="muted" style={{ marginTop: 8 }}>
                No numeric rules available for this submission.
              </div>
            )}
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Saved expert results</div>
            {expertResults.length ? (
              <table>
                <thead>
                  <tr>
                    <th>Rule</th>
                    <th>Required</th>
                    <th>System</th>
                    <th>Measured</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {expertResults.map((result) => (
                    <tr key={result.id || `${result.rule_id}-manual`}>
                      <td>
                        <div style={{ fontWeight: 600 }}>{result.rule_id}</div>
                        <div className="muted">{result.title}</div>
                      </td>
                      <td>
                        {result.operator} {result.required_value} {result.unit || ""}
                      </td>
                      <td>{result.system_measured_value || "-"}</td>
                      <td>{result.measured_value} {result.unit || ""}</td>
                      <td>
                        {result.is_compliant === null ? (
                          <span className="muted">Manual</span>
                        ) : result.is_compliant ? (
                          <span style={{ color: "#0f6b5f", fontWeight: 600 }}>PASS</span>
                        ) : (
                          <span style={{ color: "#b21c1c", fontWeight: 600 }}>FAIL</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <div className="muted">No expert results saved yet.</div>
            )}
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>System textual record</div>
            <div className="muted">Structured audit record for training and review.</div>
            <pre style={{ marginTop: 8, maxHeight: 220, overflow: "auto", fontSize: 12, background: "#fafafa", padding: 10, borderRadius: 8 }}>
              {prettyJson(textualRecord)}
            </pre>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Measurable record</div>
            <div className="muted">Computed areas, setbacks, dimensions, and measurable outputs.</div>
            <pre style={{ marginTop: 8, maxHeight: 220, overflow: "auto", fontSize: 12, background: "#fafafa", padding: 10, borderRadius: 8 }}>
              {prettyJson(measurableRecord)}
            </pre>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Training record</div>
            <div className="muted">Labeled handles, extracted entities, and generated training events.</div>
            <pre style={{ marginTop: 8, maxHeight: 220, overflow: "auto", fontSize: 12, background: "#fafafa", padding: 10, borderRadius: 8 }}>
              {prettyJson(trainingRecord)}
            </pre>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Text from CAD</div>
            <div className="muted">
              {selectedLayer
                ? `Showing textual elements matched to ${selectedLayer}${layerMeta[selectedLayer]?.tag ? ` (${layerMeta[selectedLayer].tag})` : ""}.`
                : `Showing ${Math.min(textEntities.length, MAX_TEXT_ITEMS)} of ${textEntities.length} text entities.`}
            </div>
            <input
              type="text"
              value={textFilter}
              onChange={(e) => setTextFilter(e.target.value)}
              placeholder={selectedLayer ? "Filter within the current selection" : "Filter text or layer"}
              style={{ width: "100%", marginTop: 8, padding: 6 }}
            />
            <div style={{ marginTop: 8, maxHeight: 220, overflow: "auto", borderTop: "1px dashed #eee", paddingTop: 6 }}>
              {filteredText.length ? filteredText.map((item, idx) => (
                <button
                  key={`${item.layer}-${item.handle || idx}`}
                  type="button"
                  onClick={() => highlightCadTextFinding({ ...item, semantic_hints: [] })}
                  style={{
                    display: "block",
                    width: "100%",
                    marginBottom: 8,
                    padding: 7,
                    textAlign: "left",
                    border: "1px solid #e0e7ef",
                    borderRadius: 7,
                    background: learningSourceText?.handle === item.handle && learningSourceText?.text === item.text ? "#fff3a6" : "#fff",
                  }}
                  title="Locate and highlight this text in the CAD view"
                >
                  <div style={{ fontSize: 13 }}>{item.text}</div>
                  <div className="muted">Layer: {item.layer}</div>
                </button>
              )) : (
                <div className="muted">
                  {selectedLayer ? "No textual elements matched the current selection." : "No text entities found."}
                </div>
              )}
            </div>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Scale measure</div>
            <div className="muted">Pick two points in the drawing to measure distance. Scale is auto-derived from Plot Boundary + 5M rules when available.</div>
            <div style={{ marginTop: 8, display: "flex", gap: 8, flexWrap: "wrap" }}>
              <button
                type="button"
                onClick={toggleMeasureMode}
              >
                {measureMode ? "Exit measure mode" : "Start measure mode"}
              </button>
              <button
                type="button"
                onClick={resetMeasure}
              >
                Clear measure
              </button>
              <button
                type="button"
                onClick={useMeasuredDistanceForRule}
                disabled={!Number.isFinite(scaledDistance)}
              >
                Use distance for selected rule
              </button>
            </div>
            <div style={{ marginTop: 8, display: "grid", gap: 6 }}>
              <div className="muted">Clicks: {measurePoints.length}/2</div>
              <div>Raw distance: <strong>{rawDistanceLabel}</strong></div>
              <div>Scaled distance ({scaleLabel}): <strong>{scaledDistanceLabel}</strong></div>
              {autoScaleFromPlotBoundary ? (
                <div className="muted">
                  Auto scale: {autoScaleFromPlotBoundary.multiplier.toFixed(6)} ft/unit (plot mismatch {autoScaleFromPlotBoundary.mismatch.toFixed(4)})
                </div>
              ) : null}
            </div>
            {scaleOptions.length ? (
              <div style={{ marginTop: 10 }}>
                <div className="muted" style={{ marginBottom: 6 }}>Detected scales from text:</div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {scaleOptions.map((opt) => (
                    <button
                      key={opt.label}
                      type="button"
                      onClick={() => {
                        setScaleTouched(true);
                        setScaleMultiplier(opt.multiplier);
                        setScaleLabel(opt.label);
                      }}
                    >
                      {opt.label}
                    </button>
                  ))}
                </div>
              </div>
            ) : null}
            <div style={{ marginTop: 10 }}>
              <label className="muted" htmlFor="scale-input">Manual scale (1:x)</label>
              <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
                <button
                  type="button"
                  onClick={() => {
                    if (!autoScaleFromPlotBoundary) return;
                    setScaleTouched(false);
                    setScaleMultiplier(autoScaleFromPlotBoundary.multiplier);
                    setScaleLabel(autoScaleFromPlotBoundary.label);
                  }}
                  disabled={!autoScaleFromPlotBoundary}
                >
                  Use auto scale
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setScaleTouched(true);
                    setScaleMultiplier(1);
                    setScaleLabel("CAD units x1");
                  }}
                >
                  CAD units
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setScaleTouched(true);
                    setScaleMultiplier(1 / 12);
                    setScaleLabel("inches → feet");
                  }}
                >
                  Inches to feet
                </button>
                <input
                  id="scale-input"
                  type="number"
                  min="0"
                  step="0.01"
                  value={Number.isFinite(scaleMultiplier) ? scaleMultiplier : ""}
                  onChange={(e) => {
                    const next = Number(e.target.value);
                    if (!Number.isFinite(next) || next <= 0) {
                      setScaleTouched(true);
                      setScaleMultiplier(1);
                      setScaleLabel("1:1");
                    } else {
                      setScaleTouched(true);
                      setScaleMultiplier(next);
                      setScaleLabel(`${next} ft/unit`);
                    }
                  }}
                  style={{ flex: "1 1 auto", padding: 6 }}
                />
                <button
                  type="button"
                  onClick={() => {
                    setScaleTouched(true);
                    setScaleMultiplier(1);
                    setScaleLabel("1:1");
                  }}
                >
                  Reset
                </button>
              </div>
            </div>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Finalized system</div>
            <div className="muted">Ruleset hierarchy, canonical units, and evaluation flow from the attached regulations document.</div>
            <pre style={{ marginTop: 8, maxHeight: 220, overflow: "auto", fontSize: 12, background: "#fafafa", padding: 10, borderRadius: 8 }}>
              {prettyJson({
                source_documents: rulesetOverview.source_documents || [],
                applicability_scope: rulesetOverview.applicability_scope || {},
                canonical_units: rulesetOverview.canonical_units || {},
                evaluation_flow: rulesetOverview.evaluation_flow || [],
                implementation_assumptions: rulesetOverview.implementation_assumptions || [],
              })}
            </pre>
          </div>

          {mapSummary ? (
            <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
              <div style={{ fontWeight: 600, marginBottom: 6 }}>Semantic mapping summary</div>
              <pre style={{ marginTop: 8, maxHeight: 180, overflow: "auto", fontSize: 12, background: "#fafafa", padding: 10, borderRadius: 8 }}>
                {prettyJson(mapSummary)}
              </pre>
            </div>
          ) : null}

          {validationReport ? (
            <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
              <div style={{ fontWeight: 600, marginBottom: 6 }}>Validation rule results</div>
              <div className="muted" style={{ marginBottom: 8 }}>
                Status: <strong>{validationReport.status || "unknown"}</strong>
              </div>
              <div className="muted" style={{ marginBottom: 8 }}>
                Pass: {validationCounts.pass} | Fail: {validationCounts.fail} | Warn: {validationCounts.warn} | Needs review: {validationCounts.needs_review}
              </div>
              <div style={{ maxHeight: 260, overflow: "auto", border: "1px solid #eee", borderRadius: 8 }}>
                <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12 }}>
                  <thead>
                    <tr style={{ background: "#fafafa" }}>
                      <th style={{ textAlign: "left", padding: 8, borderBottom: "1px solid #eee" }}>Rule</th>
                      <th style={{ textAlign: "left", padding: 8, borderBottom: "1px solid #eee" }}>Required</th>
                      <th style={{ textAlign: "left", padding: 8, borderBottom: "1px solid #eee" }}>Actual</th>
                      <th style={{ textAlign: "left", padding: 8, borderBottom: "1px solid #eee" }}>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(validationReport.rules || []).map((row, idx) => (
                      <tr key={`${row.rule_code || "rule"}-${idx}`}>
                        <td style={{ padding: 8, borderBottom: "1px solid #f1f1f1" }}>
                          <div style={{ fontWeight: 600 }}>{row.rule_code}</div>
                          {row.message ? <div className="muted">{row.message}</div> : null}
                        </td>
                        <td style={{ padding: 8, borderBottom: "1px solid #f1f1f1" }}>{row.required ?? "-"}</td>
                        <td style={{ padding: 8, borderBottom: "1px solid #f1f1f1" }}>{row.actual ?? "-"}</td>
                        <td style={{ padding: 8, borderBottom: "1px solid #f1f1f1", fontWeight: 600 }}>{row.status || "-"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Pipeline flow status</div>
            <div style={{ display: "grid", gap: 6 }}>
              {flowStatus.map((item, idx) => (
                <div key={`${item.step}-${idx}`} style={{ display: "flex", justifyContent: "space-between", gap: 8, borderBottom: "1px dashed #eee", paddingBottom: 4 }}>
                  <div style={{ fontSize: 12 }}>{item.step}</div>
                  <div style={{ fontSize: 12, fontWeight: 700 }}>{item.status}</div>
                </div>
              ))}
            </div>
          </div>

          <div style={{ position: "sticky", bottom: 0, background: "#fff", paddingTop: 10, borderTop: "1px solid #eee", marginTop: 10 }}>
            <button type="submit">Save mapping</button>
            {config.mapValidationUrl ? (
              <button
                type="button"
                onClick={runServerValidation}
                disabled={runningValidation || ((mapSummary?.missing_required_entities || []).length > 0)}
                style={{ marginLeft: 8 }}
                title={((mapSummary?.missing_required_entities || []).length > 0)
                  ? `Missing mandatory mappings: ${(mapSummary?.missing_required_entities || []).join(", ")}`
                  : ""}
              >
                {runningValidation ? "Running validation..." : "Run validation"}
              </button>
            ) : null}
            <a href={config.backToLabelUrl || `/admin/plan/cad-submissions/${config.submissionId}/labeling`} style={{ marginLeft: 10 }}>
              Back to labeling
            </a>
            <div className="muted" style={{ marginTop: 8 }}>
              Saved mapping will be used by the Python pipeline to compute multi-storey areas (FAR) and select the correct footprints.
            </div>
          </div>
        </form>
      </div>

      <div className="main" style={{ display: "flex", minWidth: 0 }}>
        <div className="cad-view-column">
        <div
          className="topbar"
          ref={centerTopbarRef}
          style={{ flexWrap: "wrap", alignItems: "center", rowGap: 8, columnGap: 8, padding: "10px 10px" }}
        >
          <span className="pill">Submission #{config.submissionId}</span>
          <span className="pill">DXF: {config.hasDxf ? "available" : "missing"}</span>
          {config.activeLayerConfig ? <span className="pill">Layer config: {config.activeLayerConfig}</span> : null}
          {floorContext ? <span className="pill">{humanFloorContext(floorContext)}</span> : null}
          {!showAdvancedReviewTools ? (
            <>
              <strong style={{ color: "#0f6b5f" }}>Click selects · Ctrl/Cmd+click adds more · Shift+drag moves the view</strong>
              <button type="button" onClick={() => { setDrawingMode(drawingMode === "rectangle" ? "select" : "rectangle"); clearCurrentDrawing(); }} style={{ fontWeight: drawingMode === "rectangle" ? 800 : 500 }}>
                {drawingMode === "rectangle" ? "Drawing region…" : "Draw region"}
              </button>
              <button type="button" onClick={() => { setSelectedEntityHandles([]); setSelectedEntityHandle(""); }}>Clear selection</button>
              <button type="button" onClick={() => setShowCadText((value) => !value)} style={{ fontWeight: showCadText ? 700 : 500 }}>
                {showCadText ? "Hide text" : "Show text"}
              </button>
              <button type="button" onClick={fitView}>Fit drawing</button>
              <button type="button" onClick={toggleFullscreenViewer}>Fullscreen</button>
              <span className="muted" style={{ marginLeft: "auto" }}>{selectedEntityHandles.length} selected</span>
            </>
          ) : (
          <>
          <span className="muted">Drawing mode:</span>
          <select value={drawingMode} onChange={(e) => { setDrawingMode(e.target.value); clearCurrentDrawing(); }}>
            <option value="select">Select</option>
            <option value="polygon">Polygon</option>
            <option value="polyline">Polyline</option>
            <option value="rectangle">Rectangle</option>
            <option value="point">Point</option>
          </select>
          <button type="button" onClick={undoLastPoint} disabled={!currentPoints.length}>Undo point</button>
          <button type="button" onClick={finishCurrentShape} disabled={!currentPoints.length}>Finish shape</button>
          <button type="button" onClick={clearCurrentDrawing} disabled={!currentPoints.length}>Clear drawing</button>
          <button type="button" onClick={() => saveCurrentDrawing(false)} disabled={!currentPoints.length || !activeLabelKey}>Save draft</button>
          <button type="button" onClick={() => saveCurrentDrawing(true)} disabled={!currentPoints.length || !activeLabelKey}>Save/Confirm label</button>
          <button type="button" onClick={toggleMeasureMode} style={{ marginLeft: 6, fontWeight: measureMode ? 700 : 500 }}>
            {measureMode ? "Measuring: click 2 points" : "Measure"}
          </button>
          <span className="pill">Distance: {scaledDistanceLabel}</span>
          <button type="button" onClick={useMeasuredDistanceForRule} disabled={!Number.isFinite(scaledDistance)}>
            Use value
          </button>
          <button type="button" onClick={() => setShowCadText((v) => !v)}>
            {showCadText ? "Hide CAD text" : "Show CAD text"}
          </button>
          <button type="button" onClick={fitView}>Fit to screen</button>
          <button type="button" onClick={resetView}>Reset view</button>
          <button type="button" onClick={toggleFullscreenViewer}>Fullscreen</button>
          <span className="pill">CAD text: {showCadText ? `ON (${Math.min(textEntities.length, 1200)})` : "OFF"}</span>
          <span className="muted" style={{ marginLeft: "auto" }}>{hoverText}</span>
          </>
          )}
        </div>
        <div
          className="canvas-wrap"
          style={{ inset: `${topbarHeight}px 0 0 0` }}
          tabIndex={0}
          aria-label="Scrollable CAD drawing viewport"
        >
          <div className="cad-canvas-stage">
            {loading && (
              <div className="loading-overlay">
                <div className="loading-box">
                  <div style={{ fontWeight: 600 }}>Preparing drawing…</div>
                  <div className="muted" style={{ marginTop: 6 }}>{loadingMessage}</div>
                  <div className="loading-bar"><span /></div>
                </div>
              </div>
            )}
            <canvas id="cad-canvas" ref={canvasRef} />
            {pickCandidates?.candidates?.length ? (
              <div
                style={{
                  position: "absolute",
                  left: Math.max(8, Math.min(pickCandidates.x, (lastSizeRef.current.w || 800) - 300)),
                  top: Math.max(8, Math.min(pickCandidates.y, (lastSizeRef.current.h || 600) - 220)),
                  width: 292,
                  background: "#fff",
                  border: "1px solid #d0d7de",
                  borderRadius: 8,
                  boxShadow: "0 12px 30px rgba(16,20,24,0.18)",
                  padding: 8,
                  zIndex: 20,
                }}
              >
                <div style={{ display: "flex", justifyContent: "space-between", gap: 8, marginBottom: 6 }}>
                  <strong style={{ fontSize: 13 }}>Select CAD entity</strong>
                  <button type="button" onClick={() => setPickCandidates(null)} aria-label="Close entity choices" style={{ padding: "1px 6px" }}>&times;</button>
                </div>
                <div style={{ display: "grid", gap: 6, maxHeight: 180, overflow: "auto" }}>
                  {pickCandidates.candidates.map((candidate) => (
                    <button
                      key={`${candidate.handle}-${candidate.layer}`}
                      type="button"
                      onClick={() => selectEntityCandidate(candidate, { additive: false })}
                      style={{
                        textAlign: "left",
                        border: "1px solid #eee",
                        borderRadius: 6,
                        padding: 7,
                        background: "#f8fbff",
                      }}
                    >
                      <div style={{ fontWeight: 700 }}>{candidate.handle}</div>
                      <div className="muted">{candidate.layer || "Unknown layer"} · {candidate.entityType}</div>
                      <div className="muted">Area {Number(candidate.area || 0).toFixed(2)} · Length {Number(candidate.length || 0).toFixed(2)}</div>
                    </button>
                  ))}
                </div>
              </div>
            ) : null}
          </div>
        </div>
        {selectedLayer && drawingMode === "select" ? (
          <div className="layer-info-popup" ref={layerInfoPopupRef} role="dialog" aria-modal="false" aria-labelledby="layer-info-title">
            <div className="layer-info-header">
              <div id="layer-info-title" style={{ fontWeight: 600 }}>Selected layer: {selectedLayer}</div>
              <button
                type="button"
                className="layer-info-close"
                onClick={clearLayerSelection}
                aria-label="Close selected layer panel"
                title="Close"
              >
                &times;
              </button>
            </div>
            <div className="muted" style={{ marginBottom: 8 }}>
              {quickMarkAssignedLabel ? `Assigned: ${quickMarkAssignedLabel}` : "No tag assigned."}
            </div>
            <div style={{ marginBottom: 10 }}>
              <label className="muted" style={{ display: "block", marginBottom: 6 }} htmlFor="quick-layer-mark">
                Mark selected layer as
              </label>
              <div style={{ display: "flex", gap: 6, alignItems: "center" }}>
                <select
                  id="quick-layer-mark"
                  value={quickMarkTag || ""}
                  onChange={(event) => setQuickMarkTag(event.target.value)}
                  style={{
                    flex: 1,
                    minWidth: 190,
                    border: "1px solid #d7dde5",
                    borderRadius: 10,
                    padding: "8px 10px",
                    background: "#fff",
                    fontSize: 13,
                  }}
                >
                  {quickMarkOptions.map((option) => (
                    <option key={option.value || "none"} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
                <button
                  type="button"
                  onClick={() => applyQuickLayerMark()}
                  disabled={!quickMarkTag || applyingQuickMark}
                  style={{ padding: "8px 10px", fontSize: 12, fontWeight: 700 }}
                >
                  {applyingQuickMark ? "Applying..." : "Apply"}
                </button>
              </div>
              {quickMarkFeedback ? (
                <div
                  className={`layer-info-feedback ${quickMarkFeedback.type}`}
                  role={quickMarkFeedback.type === "error" ? "alert" : "status"}
                >
                  {quickMarkFeedback.message}
                </div>
              ) : null}
              {quickMarkSelectedLayers.length > 1 ? (
                <div className="muted" style={{ marginTop: 6 }}>
                  Applies to {quickMarkSelectedLayers.length} selected layers.
                </div>
              ) : null}
            </div>
            {quickMarkOptions.some((item) => item.groupLabel === "Doors") ? (
              <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 10 }}>
                {quickMarkOptions
                  .filter((item) => ["Doors", "Plot Boundary", "Building Footprint", "Setback", "Windows", "Stairs"].includes(item.groupLabel))
                  .slice(0, 6)
                  .map((item) => (
                    <button
                      key={`quick-${item.value}`}
                      type="button"
                      onClick={() => applyQuickLayerMark(item.value)}
                      disabled={applyingQuickMark}
                      style={{ padding: "6px 8px", fontSize: 11 }}
                    >
                      {item.groupLabel}
                    </button>
                  ))}
              </div>
            ) : null}
            {suggestedOfficialMappings.length ? (
              <div className="muted" style={{ marginBottom: 10 }}>
                Suggested: {suggestedOfficialMappings.map((item) => item.label).join(", ")}
              </div>
            ) : null}
            {selectedLayerRules.length ? (
              <div style={{ display: "grid", gap: 6 }}>
                {selectedLayerRules.slice(0, 4).map((rule) => (
                  <div key={rule.id} style={{ padding: 8, borderRadius: 10, background: "rgba(255,255,255,0.92)", border: "1px solid rgba(16,20,24,0.08)" }}>
                    <div style={{ fontWeight: 600 }}>{rule.id}</div>
                    <div className="muted" style={{ fontSize: 11 }}>
                      {rule.title} • {rule.operator} {rule.required_value} {rule.unit || ""}
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="muted">No related numeric rules found.</div>
            )}
          </div>
        ) : null}
        <div className="muted" style={{ position: "absolute", bottom: 8, right: 12 }}>{summaryText}</div>
        </div>
        <div className={`cad-details-panel${showAdvancedReviewTools ? "" : " officer-simple"}`}>
          <div className="officer-workflow">
            <div className="card" style={{ border: "2px solid #0f6b5f", borderRadius: 12, padding: 12, marginBottom: 10, background: "#f4fffa" }}>
              <div style={{ display: "flex", justifyContent: "space-between", gap: 8, alignItems: "flex-start" }}>
                <div>
                  <div style={{ fontWeight: 800, fontSize: 18 }}>Auto-detected spaces</div>
                  <div className="muted">Native CAD room text is pre-marked. Locate, confirm, correct, or reject each suggestion.</div>
                </div>
                <span className="pill">{nativeTextSuggestions.length} found</span>
              </div>
              {nativePredictionSyncing ? <div style={{ marginTop: 8, color: "#0f6b5f", fontWeight: 700 }}>Preparing suggestions…</div> : null}
              {nativeSpaceRows.length ? (
                <>
                  <div style={{ maxHeight: 300, overflow: "auto", display: "grid", gap: 7, marginTop: 10 }}>
                    {nativeSpaceRows.map(({ suggestion, prediction }) => {
                      const status = prediction?.status || "preparing";
                      const classifierMismatch = !!prediction && (
                        (prediction.final_label_key || prediction.label_key) !== suggestion.label_key
                        || prediction.floor !== suggestion.floor
                        || prediction.label_name !== suggestion.instance_key
                      );
                      const reviewable = !!prediction && status !== "verified";
                      const selectedLabel = nativeSuggestionCorrections[prediction?.id]
                        || (classifierMismatch ? suggestion.label_key : null)
                        || prediction?.final_label_key
                        || prediction?.label_key
                        || suggestion.label_key;
                      const proposedMeasurement = suggestion.metadata?.measurement_suggestion || {};
                      const editedMeasurement = nativeSuggestionMeasurements[prediction?.id] || {};
                      const savedMeasurement = prediction?.metadata?.reviewed_measurements || {};
                      const areaValue = editedMeasurement.area_sq_ft
                        ?? savedMeasurement.area_sq_ft
                        ?? prediction?.tag?.area_sq_ft
                        ?? proposedMeasurement.area_sq_ft
                        ?? "";
                      const countValue = editedMeasurement.observed_count
                        ?? savedMeasurement.observed_count
                        ?? prediction?.tag?.attributes?.observed_count
                        ?? proposedMeasurement.observed_count
                        ?? "";
                      const isStairSuggestion = suggestion.label_key === "staircase";
                      const acceptsArea = !["rear_building_line", "front_building_line", "side_building_line"].includes(suggestion.label_key);
                      return (
                        <div
                          key={suggestion.source_key}
                          ref={(node) => {
                            if (node) nativeSuggestionRowRefs.current[suggestion.source_key] = node;
                            else delete nativeSuggestionRowRefs.current[suggestion.source_key];
                          }}
                          tabIndex={-1}
                          style={{
                            border: focusedNativeSourceKey === suggestion.source_key ? "2px solid #0f6b5f" : "1px solid #b9d7cf",
                            borderRadius: 9,
                            padding: 8,
                            background: focusedNativeSourceKey === suggestion.source_key ? "#e7fff4" : "#fff",
                            outline: "none",
                          }}
                        >
                          <button
                            type="button"
                            onClick={() => nativeSuggestionActionRef.current?.(suggestion.source_key)}
                            style={{ display: "block", width: "100%", padding: 0, border: 0, background: "transparent", textAlign: "left", cursor: "pointer" }}
                            title="Locate this native text marker in the CAD drawing"
                          >
                            <div style={{ display: "flex", justifyContent: "space-between", gap: 6 }}>
                              <strong>{suggestion.instance_key}</strong>
                              <span style={{ color: classifierMismatch ? "#b45309" : status === "confirmed" || status === "corrected" || status === "verified" ? "#0f6b5f" : "#805b00", fontSize: 12, fontWeight: 700 }}>{classifierMismatch ? "Needs correction" : humanizeTagValue(status)}</span>
                            </div>
                            <div>{suggestion.finding.text}</div>
                            <div className="muted">{humanFloorContext(suggestion.floor)} · {suggestion.finding.layer || "Unknown layer"} · {Math.round(Number(suggestion.confidence) * 100)}% · click to locate</div>
                          </button>
                          {isStairSuggestion || acceptsArea ? (
                            <div style={{ display: "grid", gridTemplateColumns: isStairSuggestion ? "1fr 1fr" : "1fr", gap: 6, marginTop: 7, padding: 7, borderRadius: 7, background: "#fff9dc" }}>
                              {isStairSuggestion ? (
                                <label className="muted">
                                  Stair/riser count
                                  <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    value={countValue}
                                    disabled={!reviewable || predictionBusy}
                                    onChange={(event) => prediction && setNativeSuggestionMeasurements((current) => ({
                                      ...current,
                                      [prediction.id]: { ...(current[prediction.id] || {}), observed_count: event.target.value },
                                    }))}
                                    placeholder="4 or more parallel lines"
                                    style={{ width: "100%", marginTop: 3 }}
                                  />
                                </label>
                              ) : null}
                              {acceptsArea ? (
                                <label className="muted">
                                  Area (sq ft)
                                  <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={areaValue}
                                    disabled={!reviewable || predictionBusy}
                                    onChange={(event) => prediction && setNativeSuggestionMeasurements((current) => ({
                                      ...current,
                                      [prediction.id]: { ...(current[prediction.id] || {}), area_sq_ft: event.target.value },
                                    }))}
                                    placeholder="Calculated or enter area"
                                    style={{ width: "100%", marginTop: 3 }}
                                  />
                                </label>
                              ) : null}
                              <div className="muted" style={{ gridColumn: "1 / -1", fontSize: 11 }}>
                                {proposedMeasurement.method === "repeated_parallel_lines"
                                  ? `${proposedMeasurement.observed_count} repeating parallel lines detected${proposedMeasurement.unit_confirmed ? "" : "; area remains provisional until scale is confirmed"}.`
                                  : proposedMeasurement.method === "native_text_dimensions"
                                    ? `Calculated from ${proposedMeasurement.width_ft} ft × ${proposedMeasurement.length_ft} ft found in CAD text.`
                                    : isStairSuggestion
                                      ? "No reliable 4+ parallel-line stair pattern found; enter the count and area for learning."
                                      : "No reliable dimension pair found; enter the room area for learning."}
                              </div>
                            </div>
                          ) : null}
                          <div style={{ display: "grid", gridTemplateColumns: "minmax(0, 1fr) auto auto", gap: 6, marginTop: 7 }}>
                            <select
                              value={selectedLabel}
                              disabled={!reviewable || predictionBusy}
                              onChange={(event) => {
                                if (!prediction) return;
                                setNativeSuggestionCorrections((current) => ({ ...current, [prediction.id]: event.target.value }));
                              }}
                              aria-label={`Label for ${suggestion.finding.text}`}
                            >
                              {nativeCorrectionOptions.map((option) => <option key={`${suggestion.source_key}-${option.value}`} value={option.value}>{option.label}</option>)}
                            </select>
                            <button type="button" disabled={!reviewable || predictionBusy} onClick={() => reviewNativeSpaceSuggestion(prediction, suggestion.label_key)} style={{ fontWeight: 700 }}>{classifierMismatch ? "Correct" : "Confirm"}</button>
                            <button type="button" disabled={!reviewable || predictionBusy} onClick={() => reviewPrediction("reject", prediction)}>Reject</button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                  <button type="button" onClick={bulkConfirmNativeSpaces} disabled={predictionBusy || nativePredictionSyncing || !nativeSpaceRows.some((row) => row.prediction && ["unreviewed", "ai_suggested", "uncertain"].includes(row.prediction.status))} style={{ width: "100%", marginTop: 9, background: "#0f6b5f", color: "#fff", fontWeight: 800 }}>
                    Confirm all remaining text suggestions
                  </button>
                  <div className="muted" style={{ marginTop: 6 }}>Confirmation is still required before these suggestions can become verified training data.</div>
                </>
              ) : <div className="muted" style={{ marginTop: 8 }}>No room-name clues were found in native CAD text.</div>}
            </div>
            <div className="card" style={{ border: "2px solid #0f6b5f", borderRadius: 12, padding: 14, marginBottom: 10, background: "#f7fffc" }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 8 }}>
                <div><div style={{ fontWeight: 800, fontSize: 18 }}>Add a CAD learning example</div><div className="muted">Select → capture PNG → describe → save.</div></div>
                <button type="button" onClick={() => setShowAdvancedReviewTools((value) => !value)}>{showAdvancedReviewTools ? "Simple view" : "Advanced tools"}</button>
              </div>
              <ol style={{ margin: "12px 0", paddingLeft: 20, fontSize: 13 }}>
                <li>Select CAD entities using click or Ctrl/Cmd+click. Use Shift+drag to move the drawing.</li>
                <li>Choose what the region represents and capture it.</li>
                <li>Add the observed fact, rule result, and officer note.</li>
              </ol>
              <div style={{ padding: 9, borderRadius: 8, background: selectedEntityHandles.length ? "#e9f8f1" : "#fff8df", marginBottom: 10 }}>
                <strong>{selectedEntityHandles.length} CAD {selectedEntityHandles.length === 1 ? "entity" : "entities"} selected</strong>
                <div className="muted">{selectedEntityHandles.length ? selectedEntityHandles.slice(0, 8).join(", ") : "Click an entity. Use Ctrl/Cmd+click to add more, or Shift+drag to move the view."}</div>
              </div>
              <label className="muted">This region is</label>
              <select value={learningLabel} onChange={(event) => { setLearningLabel(event.target.value); selectActiveLabel(event.target.value); }} style={{ width: "100%", marginTop: 4, marginBottom: 8 }}>
                {tagOptions.filter((item) => item.value).map((item) => <option key={`learning-${item.value}`} value={item.value}>{item.label}</option>)}
              </select>
              <button type="button" onClick={captureLearningRegion} disabled={!selectedEntityHandles.length && !currentPoints.length} style={{ width: "100%", fontWeight: 700 }}>
                Capture selected region as PNG
              </button>
              {learningSnapshot ? <img className="learning-snapshot" src={learningSnapshot} alt="Selected CAD learning region" style={{ marginTop: 9 }} /> : null}
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: 10 }}>
                <div><label className="muted">Observed count</label><input type="number" min="0" value={learningCount} onChange={(event) => setLearningCount(event.target.value)} placeholder="e.g. 20" style={{ width: "100%" }} /></div>
                <div><label className="muted">Measured value</label><input type="number" step="0.01" value={learningMeasuredValue} onChange={(event) => setLearningMeasuredValue(event.target.value)} placeholder="Optional" style={{ width: "100%" }} /></div>
                <div><label className="muted">Unit</label><select value={learningUnit} onChange={(event) => setLearningUnit(event.target.value)} style={{ width: "100%" }}><option value="count">Count</option><option value="ft">Feet</option><option value="sq_ft">Square feet</option><option value="m">Metres</option><option value="sq_m">Square metres</option></select></div>
                <div><label className="muted">Required by rule</label><input type="text" value={learningExpectedValue} onChange={(event) => setLearningExpectedValue(event.target.value)} placeholder="e.g. 20 stairs" style={{ width: "100%" }} /></div>
              </div>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginTop: 8 }}>
                <div><label className="muted">Rule code/reference</label><input type="text" value={learningRuleCode} onChange={(event) => setLearningRuleCode(event.target.value)} placeholder="Rule book reference" style={{ width: "100%" }} /></div>
                <div><label className="muted">Officer finding</label><select value={learningCompliance} onChange={(event) => setLearningCompliance(event.target.value)} style={{ width: "100%" }}><option value="compliant">Compliant</option><option value="non_compliant">Non-compliant</option><option value="needs_review">Needs review</option><option value="not_applicable">Not applicable</option></select></div>
              </div>
              <label className="muted" style={{ display: "block", marginTop: 8 }}>Officer note</label>
              <textarea value={learningNotes} onChange={(event) => setLearningNotes(event.target.value)} rows={3} placeholder="Example: This region contains 20 stair risers, which meets rule XYZ." style={{ width: "100%" }} />
              <button type="button" onClick={saveLearningExample} disabled={savingLearningExample || !learningSnapshot || !learningNotes.trim()} style={{ width: "100%", marginTop: 9, background: "#0f6b5f", color: "#fff", fontWeight: 800 }}>
                {savingLearningExample ? "Saving learning example…" : "Save learning example"}
              </button>
              <div className="muted" style={{ marginTop: 7 }}>Saved examples include the PNG crop, CAD coordinates, entity handles, floor, layers, facts, rule result, reviewer, and timestamp.</div>
            </div>
            <div className="card" style={{ border: "1px solid #b9d7cf", borderRadius: 10, padding: 10, marginBottom: 10, background: "#fbfffd" }}>
              <div style={{ display: "flex", justifyContent: "space-between", gap: 8, alignItems: "center" }}>
                <div><div style={{ fontWeight: 700 }}>AI text report from CAD</div><div className="muted">Structured values read from native DXF text and their drawing positions.</div></div>
                <span className="pill">{textEntities.length} text items</span>
              </div>
              {aiTextMetricRows.length ? (
                <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 6, marginTop: 9 }}>
                  {aiTextMetricRows.map(([key, value]) => (
                    <div key={`ai-metric-${key}`} style={{ border: "1px solid #e0ebe7", borderRadius: 7, padding: 7, background: "#fff" }}>
                      <div className="muted">{humanizeTagValue(key)}</div><strong>{String(value)}</strong>
                    </div>
                  ))}
                </div>
              ) : <div className="muted" style={{ marginTop: 8 }}>No structured measurement table was detected; individual text findings are still available below.</div>}
              <div style={{ fontWeight: 700, marginTop: 10, marginBottom: 5 }}>Click a finding to locate and verify it</div>
              <div style={{ maxHeight: 210, overflow: "auto", display: "grid", gap: 5 }}>
                {aiTextFindings.map((finding, index) => (
                  <button key={`ai-text-finding-${index}`} type="button" onClick={() => highlightCadTextFinding(finding)} style={{ textAlign: "left", padding: 7, border: "1px solid #e0e7ef", borderRadius: 7, background: learningSourceText?.text === finding.text ? "#e8f5ff" : "#fff" }}>
                    <div style={{ fontWeight: 600 }}>{finding.text}</div>
                    <div className="muted">{finding.layer || "Unknown layer"}{finding.value_ft != null ? ` · ${finding.value_ft} ft` : ""}{finding.semantic_hints.length ? ` · ${finding.semantic_hints.map(humanizeTagValue).join(", ")}` : ""}</div>
                  </button>
                ))}
                {!aiTextFindings.length ? <div className="muted">No measurement or semantic text findings were detected in this drawing.</div> : null}
              </div>
              {learningSourceText ? <div style={{ marginTop: 7, padding: 7, borderRadius: 7, background: "#e9f8f1", color: "#0f6b5f", fontWeight: 600 }}>Selected as evidence: {learningSourceText.text}</div> : null}
            </div>
            {expertMarkings.filter((marking) => marking.snapshot_url).length ? (
              <div className="card" style={{ border: "1px solid #dfe7ef", borderRadius: 10, padding: 10 }}>
                <div style={{ fontWeight: 700, marginBottom: 7 }}>Saved learning examples</div>
                {expertMarkings.filter((marking) => marking.snapshot_url).slice().reverse().slice(0, 8).map((marking) => (
                  <div key={`learning-saved-${marking.id}`} style={{ display: "grid", gridTemplateColumns: "72px 1fr", gap: 8, padding: "7px 0", borderBottom: "1px dashed #e5e7eb" }}>
                    <img src={marking.snapshot_url} alt={marking.label_name || marking.label_key} style={{ width: 72, height: 58, objectFit: "cover", borderRadius: 6, border: "1px solid #ddd" }} />
                    <div><strong>{marking.label_name || marking.label_key}</strong><div className="muted">{marking.facts_json?.count != null ? `${marking.facts_json.count} ${marking.facts_json.unit || ""}` : "Structured observation"} · {String(marking.compliance_status || "needs_review").replace(/_/g, " ")}</div><div className="muted">{marking.remarks || "No note"}</div></div>
                  </div>
                ))}
              </div>
            ) : null}
          </div>
          <div style={{ fontWeight: 700, marginBottom: 8 }}>Selected Tag &amp; Validation</div>
          <div className="card" style={{ border: "1px solid #cbd9e8", borderRadius: 10, padding: 10, marginBottom: 10, background: "#f8fbff" }}>
            <div style={{ display: "flex", justifyContent: "space-between", gap: 8, alignItems: "center" }}>
              <div style={{ fontWeight: 700 }}>AI prediction review</div>
              <span className="pill">{taggingWorkspace.progress?.reviewed || 0} / {taggingWorkspace.progress?.total || 0}</span>
            </div>
            <div className="muted" style={{ marginTop: 4 }}>
              {Number(taggingWorkspace.progress?.percent || 0).toFixed(1)}% reviewed. AI output is not training data until expert verification.
            </div>
            <div style={{ height: 6, background: "#e8edf3", borderRadius: 999, overflow: "hidden", margin: "8px 0" }}>
              <div style={{ height: "100%", width: `${Math.min(100, Number(taggingWorkspace.progress?.percent || 0))}%`, background: "#0f6b5f" }} />
            </div>
            <div style={{ display: "flex", gap: 6, marginBottom: 8 }}>
              <select value={predictionStatusFilter} onChange={(event) => setPredictionStatusFilter(event.target.value)} style={{ flex: 1 }}>
                <option value="unreviewed">Unreviewed</option><option value="all">All</option>
                <option value="confirmed">Confirmed</option><option value="corrected">Corrected</option>
                <option value="rejected">Rejected</option><option value="uncertain">Uncertain</option><option value="verified">Verified</option>
              </select>
              <button type="button" onClick={loadTaggingWorkspace}>Refresh</button>
            </div>
            <div style={{ maxHeight: 150, overflow: "auto", display: "grid", gap: 5 }}>
              {visiblePredictions.map((prediction) => (
                <button
                  key={`prediction-${prediction.id}`}
                  type="button"
                  onClick={() => selectPredictionForReview(prediction)}
                  style={{ textAlign: "left", border: Number(selectedPredictionId) === Number(prediction.id) ? "1px solid #0b3d91" : "1px solid #dde4ec", borderRadius: 7, padding: 7, background: Number(selectedPredictionId) === Number(prediction.id) ? "#eaf2ff" : "#fff" }}
                >
                  <div style={{ display: "flex", justifyContent: "space-between", gap: 6 }}>
                    <strong>{resolveTagLabel(prediction.label_key, tagOptions)}</strong>
                    <span>{prediction.confidence == null ? "—" : `${Math.round(Number(prediction.confidence) * 100)}%`}</span>
                  </div>
                  <div className="muted">#{prediction.id} · {prediction.cad_layer || "No layer"} · {prediction.floor || floorContext} · {prediction.status}</div>
                </button>
              ))}
              {!visiblePredictions.length ? <div className="muted">No predictions match this filter.</div> : null}
            </div>
            {selectedPrediction ? (
              <div style={{ borderTop: "1px dashed #cad5e1", marginTop: 9, paddingTop: 9 }}>
                <div style={{ fontWeight: 700 }}>{resolveTagLabel(selectedPrediction.label_key, tagOptions)}</div>
                {selectedPrediction.metadata?.instance_key ? <div style={{ color: "#0f6b5f", fontWeight: 800 }}>{selectedPrediction.metadata.instance_key}</div> : null}
                <div className="muted">Handle: {selectedPrediction.cad_handle || "—"} · Layer: {selectedPrediction.cad_layer || "—"}</div>
                <div className="muted">Geometry: {selectedPrediction.geometry_type || "—"} · Model: {selectedPrediction.model_version || "—"}</div>
                <div className="muted">Confidence: {selectedPrediction.confidence == null ? "—" : Number(selectedPrediction.confidence).toFixed(3)}</div>
                {selectedPrediction.tag ? (
                  <div style={{ marginTop: 6, padding: 7, borderRadius: 7, background: selectedPrediction.tag.validation_messages?.some((row) => row.status === "violation") ? "#fff0f0" : "#f2fbf7" }}>
                    <div className="muted">Area: {selectedPrediction.tag.unit_confirmed ? `${Number(selectedPrediction.tag.area_sq_ft || 0).toFixed(2)} sq ft / ${Number(selectedPrediction.tag.area_sq_m || 0).toFixed(2)} sq m` : "Provisional — confirm unit/scale"}</div>
                    <div className="muted">Width: {Number(selectedPrediction.tag.width || 0).toFixed(2)} ft · Length: {Number(selectedPrediction.tag.length || 0).toFixed(2)} ft · Perimeter: {Number(selectedPrediction.tag.perimeter || 0).toFixed(2)} ft</div>
                    <div className="muted">Closure: {selectedPrediction.tag.is_closed ? "Closed" : "Open / not applicable"} · Verification: {selectedPrediction.tag.verification_level}</div>
                    {(selectedPrediction.tag.validation_messages || []).map((message) => (
                      <div key={`${message.rule_code}-${message.status}`} style={{ color: message.status === "violation" ? "#b21c1c" : message.status === "pass" ? "#0f6b5f" : "#946200", fontWeight: 600, marginTop: 4 }}>{message.message}</div>
                    ))}
                  </div>
                ) : null}
                <select value={correctedPredictionLabel} onChange={(event) => setCorrectedPredictionLabel(event.target.value)} style={{ width: "100%", marginTop: 7 }}>
                  <option value="">Change label…</option>
                  {tagOptions.filter((item) => item.value).map((item) => <option key={`correction-${item.value}`} value={item.value}>{item.label}</option>)}
                </select>
                <textarea value={predictionRemarks} onChange={(event) => setPredictionRemarks(event.target.value)} placeholder="Validation remarks" rows={2} style={{ width: "100%", marginTop: 7 }} />
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: 7 }}>
                  <button type="button" onClick={() => reviewPrediction("confirm")} disabled={predictionBusy}>Confirm AI Tag</button>
                  <button type="button" onClick={() => reviewPrediction("correct")} disabled={predictionBusy || !correctedPredictionLabel}>Change Label</button>
                  <button type="button" onClick={() => reviewPrediction("reject")} disabled={predictionBusy}>Reject</button>
                  <button type="button" onClick={() => reviewPrediction("uncertain")} disabled={predictionBusy}>Uncertain</button>
                  <button type="button" onClick={() => reviewPrediction("confirm")} disabled={predictionBusy}>Save &amp; Next</button>
                </div>
              </div>
            ) : null}
            <div style={{ borderTop: "1px dashed #cad5e1", marginTop: 9, paddingTop: 9 }}>
              <div className="muted">Bulk confirm threshold</div>
              <div style={{ display: "flex", gap: 6, alignItems: "center", marginTop: 5 }}>
                <input type="number" min="0" max="1" step="0.05" value={predictionConfidence} onChange={(event) => setPredictionConfidence(event.target.value)} style={{ width: 75 }} />
                <button type="button" onClick={bulkConfirmPredictions} disabled={predictionBusy}>Confirm above threshold</button>
              </div>
              <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginTop: 7 }}>
                <button type="button" onClick={submitVerifiedTrainingData} disabled={predictionBusy}>Submit Verified Training Data</button>
                <button type="button" onClick={runTagEvaluation} disabled={predictionBusy}>Calculate Accuracy</button>
              </div>
              {evaluationSummary ? (
                <div className="muted" style={{ marginTop: 7 }}>
                  Micro F1 {Number(evaluationSummary.micro_f1 || 0).toFixed(3)} · Macro F1 {Number(evaluationSummary.macro_f1 || 0).toFixed(3)} · IoU {Number(evaluationSummary.average_polygon_iou || 0).toFixed(3)}
                </div>
              ) : null}
            </div>
          </div>
          <div style={{ fontWeight: 700, marginBottom: 8 }}>AI/Chat Mapping Panel</div>
          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 700, marginBottom: 6, color: "#0b3d91" }}>Rule Assistant</div>
            <div className="muted" style={{ marginBottom: 8 }}>
              Uses selected rule + measured CAD value + system value to suggest what to confirm.
            </div>
            <div style={{ fontWeight: 600, marginBottom: 4 }}>{assistantInsight.headline}</div>
            <div className="muted" style={{ marginBottom: 10 }}>{assistantInsight.detail}</div>
            <div style={{ display: "flex", gap: 6, flexWrap: "wrap", marginBottom: 8 }}>
              <button type="button" onClick={() => applyAssistantSuggestion("measured")} disabled={!Number.isFinite(scaledDistance)}>
                Confirm measured
              </button>
              <button type="button" onClick={() => applyAssistantSuggestion("system")} disabled={!Number.isFinite(Number(selectedSystemValue))}>
                Confirm system
              </button>
              <button type="button" onClick={() => applyAssistantSuggestion("note")} disabled={!selectedRule}>
                Confirm suggestion
              </button>
            </div>
            {chatbotStatus ? <div style={{ fontSize: 12, color: "#0f6b5f" }}>{chatbotStatus}</div> : null}
            <div className="muted" style={{ marginTop: 8 }}>
              After confirmation, click <strong>Save expert result</strong> to persist.
            </div>
            <div style={{ marginTop: 10, borderTop: "1px dashed #eee", paddingTop: 10 }}>
              <div style={{ fontWeight: 600, marginBottom: 6 }}>Interactive chat</div>
              <div style={{ maxHeight: 170, overflow: "auto", border: "1px solid #eee", borderRadius: 8, padding: 8, background: "#fff" }}>
                {chatMessages.map((m, idx) => (
                  <div key={`chat-msg-${idx}`} style={{ marginBottom: 6 }}>
                    <span style={{ fontWeight: 700, color: m.role === "assistant" ? "#0b3d91" : "#333" }}>
                      {m.role === "assistant" ? "AI" : "You"}:
                    </span>{" "}
                    <span className="muted" style={{ color: "#3f4a55" }}>{m.text}</span>
                  </div>
                ))}
              </div>
              <div style={{ display: "flex", gap: 6, marginTop: 8 }}>
                <input
                  type="text"
                  value={chatInput}
                  onChange={(e) => setChatInput(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") {
                      e.preventDefault();
                      askRuleAssistant();
                    }
                  }}
                  placeholder="Ask: distance, pass/fail, mapping, missing required..."
                  style={{ flex: 1, padding: "8px 10px", border: "1px solid #ddd", borderRadius: 8 }}
                />
                <button type="button" onClick={askRuleAssistant}>Send</button>
              </div>
            </div>
          </div>
          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600 }}>Selected label</div>
            <div>{labelsCatalog.find((l) => l.label_key === activeLabelKey)?.label_name || activeLabelKey || "None"}</div>
            <div className="muted" style={{ marginTop: 4 }}>
              Selected entities: {selectedEntityHandles.length}
            </div>
            <div className="muted">
              Length: {selectedMeasurementSummary.length.toFixed(2)} | Area: {selectedMeasurementSummary.area.toFixed(2)}
            </div>
            <div className="muted" style={{ marginTop: 4 }}>
              Current drawing points: {currentPoints.length}
            </div>
            {currentMeasurement ? (
              <div className="muted">
                Live draw: area {Number(currentMeasurement.area || 0).toFixed(2)} | perimeter {Number(currentMeasurement.perimeter || 0).toFixed(2)} | length {Number(currentMeasurement.length || 0).toFixed(2)}
              </div>
            ) : null}
            {!currentMeasurement && selectedEntityHandles.length === 0 ? (
              <div className="muted" style={{ marginTop: 6 }}>
                Next step: select CAD entities and click <b>Map Selected to Active Label</b>, or draw geometry and click <b>Save/Confirm label</b>.
              </div>
            ) : null}
            {activeLabelTextReferences.length ? (
              <div style={{ marginTop: 8, borderTop: "1px dashed #eee", paddingTop: 8 }}>
                <div style={{ fontWeight: 600, fontSize: 12, marginBottom: 4 }}>Text-derived references</div>
                {activeLabelTextReferences.map((row, idx) => (
                  <div key={`txt-ref-${idx}`} className="muted" style={{ marginBottom: 4 }}>
                    {row.value_ft != null ? `${row.value_ft} ft` : "text"} · {row.text}
                  </div>
                ))}
              </div>
            ) : null}
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Detected issues</div>
            {(expertReport?.missing_required_labels || []).length ? (
              <ul style={{ margin: 0, paddingLeft: 16 }}>
                {(expertReport?.missing_required_label_details || []).map((row, idx) => (
                  <li key={`${row.label_key}-${idx}`} className="muted">{row.label_name} needs layer mapping, textual evidence, or officer marking.</li>
                ))}
              </ul>
            ) : (
              <div style={{ color: "#0f6b5f", fontWeight: 600 }}>
                No blocking missing labels. Matched layer/text evidence is available for preliminary review.
              </div>
            )}
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>What to do next</div>
            {expertReport?.approval_readiness?.status === "preliminary_clear" || expertReport?.approval_readiness?.status === "layer_text_available" ? (
              <div style={{ color: "#0f6b5f", fontWeight: 600 }}>
                Layer mapping and textual measurements are available. Review the report, answer applicant chat if needed, then continue AD ePermit decision flow.
              </div>
            ) : (
              <div className="muted">
                Resolve only the listed missing items. If the official text table already contains the value, regenerate the mapping report so it can be used as textual evidence.
              </div>
            )}
            {(expertReport?.approval_readiness?.messages || []).length ? (
              <div style={{ marginTop: 8, borderTop: "1px dashed #eee", paddingTop: 8 }}>
                <div style={{ fontWeight: 600, fontSize: 12, marginBottom: 4 }}>Preliminary approval evidence</div>
                {expertReport.approval_readiness.messages.map((message, idx) => (
                  <div key={`ready-${idx}`} className="muted" style={{ marginBottom: 4 }}>
                    {message}
                  </div>
                ))}
              </div>
            ) : null}
            {(expertReport?.text_reference_hints || []).length ? (
              <div style={{ marginTop: 8 }}>
                <div style={{ fontWeight: 600, fontSize: 12, marginBottom: 4 }}>CAD text references detected</div>
                {expertReport.text_reference_hints.slice(0, 6).map((hint, idx) => (
                  <div key={`hint-${idx}`} className="muted" style={{ marginBottom: 4 }}>
                    {hint.label_name}: {hint.count} text match(es){Array.isArray(hint.sample) && hint.sample[0] ? ` · e.g. ${hint.sample[0]}` : ""}
                  </div>
                ))}
              </div>
            ) : null}
            {(expertReport?.text_vs_geometry_comparisons || []).length ? (
              <div style={{ marginTop: 8 }}>
                <div style={{ fontWeight: 600, fontSize: 12, marginBottom: 4 }}>Text vs geometry confidence</div>
                {expertReport.text_vs_geometry_comparisons.slice(0, 8).map((row, idx) => (
                  <div key={`cmp-${idx}`} className="muted" style={{ marginBottom: 4 }}>
                    {row.semantic_hint}: text {row.text_value_ft ?? "-"} ft vs geometry {row.geometry_value_ft ?? "-"} ft
                    {row.delta_ft != null ? ` · Δ ${row.delta_ft} ft (${row.delta_percent ?? "-"}%)` : ""}
                    {` · confidence ${row.confidence}`}
                  </div>
                ))}
              </div>
            ) : null}
            {expertReport?.fast_track ? (
              <div style={{ marginTop: 8, borderTop: "1px dashed #eee", paddingTop: 8 }}>
                <div style={{ fontWeight: 600, fontSize: 12, marginBottom: 4 }}>Fast-track status</div>
                <div className="muted">
                  Threshold: {Number(expertReport.fast_track.threshold_percent || 0).toFixed(1)}% ·
                  In-threshold: {Number(expertReport.fast_track.eligible_count || 0)}/{Number(expertReport.fast_track.total_compared || 0)}
                </div>
                <div style={{ marginTop: 4, fontWeight: 600, color: expertReport.fast_track.eligible ? "#0f6b5f" : "#946200" }}>
                  {expertReport.fast_track.eligible ? "PASS (fast-track eligible)" : "Review required (outside threshold)"}
                </div>
              </div>
            ) : null}
            {(expertReport?.messages || []).length ? (
              <div style={{ marginTop: 8 }}>
                {expertReport.messages.slice(0, 8).map((warning, idx) => (
                  <div key={`warn-${idx}`} className="muted" style={{ color: "#4e5a66", marginBottom: 4 }}>{warning}</div>
                ))}
              </div>
            ) : null}
          </div>

          <button type="button" onClick={loadExpertMarkingReport} disabled={loadingExpertReport} style={{ width: "100%", marginBottom: 10 }}>
            {loadingExpertReport ? "Generating..." : "Generate Mapping Report"}
          </button>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Live summary</div>
            <div style={{ maxHeight: 280, overflow: "auto" }}>
              {(expertReport?.labels || []).map((row, idx) => (
                <div key={`${row.label_key}-${idx}`} style={{ marginBottom: 8, borderBottom: "1px dashed #eee", paddingBottom: 6 }}>
                  <div style={{ fontWeight: 600 }}>{row.label_name} ({(row.markings || []).length})</div>
                  <div className="muted">
                    Area {Number(row?.totals?.area || 0).toFixed(2)} | Length {Number(row?.totals?.length || 0).toFixed(2)}
                  </div>
                  <div className="muted">Status: {row.status} | Source: {row.source_state || "missing"}</div>
                  {(row.markings || []).slice(0, 3).map((mk) => (
                    <div key={`mk-${mk.id}`} style={{ marginTop: 4, display: "flex", gap: 6, alignItems: "center" }}>
                      <span className="muted">#{mk.id} {mk.geometry_type} ({mk.status})</span>
                      <button type="button" onClick={() => setSelectedMarkingId(mk.id)}>Select</button>
                      <button type="button" onClick={() => confirmExpertMarking(mk.id)} disabled={mk.status === "confirmed"}>Confirm</button>
                      <button type="button" onClick={() => deleteExpertMarking(mk.id)}>Delete</button>
                    </div>
                  ))}
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

class ViewerErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, message: "" };
  }

  static getDerivedStateFromError(error) {
    return {
      hasError: true,
      message: error?.message || "Unknown viewer error",
    };
  }

  componentDidCatch(error) {
    // Keep a visible clue in console for local debugging.
    // eslint-disable-next-line no-console
    console.error("CAD viewer crashed:", error);
  }

  render() {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 24 }}>
          <div style={{ fontSize: 18, fontWeight: 700, marginBottom: 8 }}>Viewer could not render</div>
          <div style={{ color: "#4e5a66", marginBottom: 8 }}>
            A runtime error occurred in the CAD viewer. Reload the page, then check browser console for details.
          </div>
          <code style={{ fontSize: 12, color: "#b21c1c" }}>{this.state.message}</code>
        </div>
      );
    }
    return this.props.children;
  }
}

const mount = document.getElementById("cad-layer-viewer-root");
if (mount) {
  if (!mount.__cadViewerRoot) {
    mount.__cadViewerRoot = createRoot(mount);
  }
  mount.__cadViewerRoot.render(
    <ViewerErrorBoundary>
      <App />
    </ViewerErrorBoundary>
  );
}
