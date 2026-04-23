import React, { useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import * as THREE from "three";
import { OrbitControls } from "three/examples/jsm/controls/OrbitControls.js";

const DxfParserCtor = (window.DxfParser && window.DxfParser.DxfParser)
  ? window.DxfParser.DxfParser
  : (window.DxfParser || (window.dxf && window.dxf.Parser) || window.dxf);

const TAG_OPTIONS = [
  { value: "", label: "(unassigned)" },
  { value: "plot_boundary", label: "Plot boundary" },
  { value: "ground_floor", label: "Ground floor footprint" },
  { value: "floor_1", label: "First floor footprint" },
  { value: "floor_2", label: "Second floor footprint" },
  { value: "stairs_ramp", label: "Stairs/Ramp" },
  { value: "setback_lines", label: "Setback/offset lines" },
  { value: "dimensions", label: "Dimensions" },
  { value: "text", label: "Text/notes" },
  { value: "hatching", label: "Hatching" },
  { value: "other", label: "Other" },
];

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
    .replace(/\s+/g, " ")
    .trim();
}

function extractEntityText(ent) {
  if (!ent) return "";
  return normalizeDxfText(
    ent.text ?? ent.string ?? ent.value ?? ent.rawText ?? ent.plainText ?? ent.content ?? ""
  );
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

function App() {
  const config = window.__cadViewerConfig || {};
  const canvasRef = useRef(null);
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

  const [layerMeta, setLayerMeta] = useState({});
  const [layerOrder, setLayerOrder] = useState([]);
  const [hoverText, setHoverText] = useState("");
  const [summaryText, setSummaryText] = useState("");
  const [textEntities, setTextEntities] = useState([]);
  const [textFilter, setTextFilter] = useState("");
  const [measureMode, setMeasureMode] = useState(false);
  const [measurePoints, setMeasurePoints] = useState([]);
  const [measureDistance, setMeasureDistance] = useState(null);
  const [scaleMultiplier, setScaleMultiplier] = useState(1);
  const [scaleLabel, setScaleLabel] = useState("1:1");
  const [loading, setLoading] = useState(true);
  const [loadingMessage, setLoadingMessage] = useState("Loading DXF...");
  const loadingUpdateRef = useRef(0);
  const [rules] = useState(() => (Array.isArray(config.rules) ? config.rules : []));
  const [selectedRuleId, setSelectedRuleId] = useState(() => (config.rules?.[0]?.id || ""));
  const [measuredValue, setMeasuredValue] = useState("");
  const [notes, setNotes] = useState("");
  const [expertResults, setExpertResults] = useState(() => (Array.isArray(config.expertResults) ? config.expertResults : []));
  const [statusMessage, setStatusMessage] = useState(() => (config.statusMessage || ""));
  const [savingResult, setSavingResult] = useState(false);

  useEffect(() => {
    layerMetaRef.current = layerMeta;
  }, [layerMeta]);

  useEffect(() => {
    measureModeRef.current = measureMode;
  }, [measureMode]);

  useEffect(() => {
    if (!config.hasDxf) {
      setLoading(false);
      setLoadingMessage("DXF missing for this submission.");
      return undefined;
    }

    const cleanup = initThree();
    loadDxf();
    return cleanup;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function initThree() {
    const canvas = canvasRef.current;
    if (!canvas) return () => {};

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
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

    resizeFnRef.current = applyResize;

    const onPointerDown = (event) => {
      const worldPoint = getWorldPoint(event);
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
      const hits = raycaster.intersectObjects(scene.children, true);
      if (hits.length) {
        let obj = hits[0].object;
        let layer = obj.userData.layer;
        while (!layer && obj.parent) {
          obj = obj.parent;
          layer = obj.userData.layer || obj.name;
        }
        if (layer) setHoverText(`Layer: ${layer}`);
      }
    };

    window.addEventListener("resize", scheduleResize);
    canvas.addEventListener("pointerdown", onPointerDown);
    if (canvas.parentElement && typeof ResizeObserver !== "undefined") {
      const observer = new ResizeObserver(() => scheduleResize());
      observer.observe(canvas.parentElement);
      resizeObserverRef.current = observer;
    }
    scheduleResize();

    return () => {
      window.removeEventListener("resize", scheduleResize);
      canvas.removeEventListener("pointerdown", onPointerDown);
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

  function render() {
    const renderer = rendererRef.current;
    const scene = sceneRef.current;
    const camera = cameraRef.current;
    if (renderer && scene && camera) {
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

  function fitView() {
    const fullBbox = computeBbox();
    if (!fullBbox || !Number.isFinite(fullBbox.min.x) || !Number.isFinite(fullBbox.max.x)) return;
    const trimmedBbox = computeTrimmedBbox();
    const denseBbox = computeDenseBbox();
    const dominantBbox = computeDominantLayerBbox();
    let bbox = fullBbox;
    let source = "full";
    const fullSize = new THREE.Vector3();
    fullBbox.getSize(fullSize);
    const fullSpan = Math.max(fullSize.x, fullSize.y);
    let dominantSpan = null;
    let trimmedSpan = null;
    let denseSpan = null;
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
    }
    fitInfoRef.current = { source, fullSpan, dominantSpan, trimmedSpan, denseSpan };
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
      const mat = new THREE.LineBasicMaterial({ color: 0xe0483f });
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

  async function saveExpertResult(event) {
    event.preventDefault();
    if (!selectedRuleId || measuredValue === "") return;
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
          notes,
        }),
      });
      const payload = await response.json();
      if (!response.ok) {
        setStatusMessage(payload.message || "Failed to save expert result.");
      } else {
        setStatusMessage(payload.message || "Expert result saved.");
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

  function buildLayerGeometry() {
    const mat = new THREE.LineBasicMaterial({ color: 0x111111 });
    for (const [layer, positions] of Object.entries(layerSegmentsRef.current)) {
      if (!positions || positions.length < 6) continue;
      const geom = new THREE.BufferGeometry();
      geom.setAttribute("position", new THREE.Float32BufferAttribute(positions, 3));
      geom.computeBoundingBox();
      geom.computeBoundingSphere();
      const lines = new THREE.LineSegments(geom, mat);
      lines.frustumCulled = false;
      lines.userData.layer = layer;
      const group = layerGroupsRef.current[layer];
      if (group) group.add(lines);
    }
    render();
  }

  async function loadDxf() {
    if (!DxfParserCtor) {
      setLoading(false);
      setLoadingMessage("DXF parser library did not load.");
      return;
    }

    setLoading(true);
    setLoadingMessage("Loading DXF...");

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
        const existing = config.layerMap || {};
        for (const layer of Object.keys(layerGroupsRef.current)) {
          const saved = existing[layer] || {};
          const visible = typeof saved.visible === "boolean" ? saved.visible : true;
          const tag = typeof saved.tag === "string" ? saved.tag : "";
          nextMeta[layer] = { visible, tag };
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

  function updateLayerMeta(layer, updates) {
    setLayerMeta((prev) => {
      const next = { ...prev, [layer]: { ...prev[layer], ...updates } };
      if (layerGroupsRef.current[layer] && typeof updates.visible === "boolean") {
        layerGroupsRef.current[layer].visible = updates.visible;
      }
      return next;
    });
    render();
  }

  function showAll() {
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        next[layer] = { ...next[layer], visible: true };
        if (layerGroupsRef.current[layer]) layerGroupsRef.current[layer].visible = true;
      }
      return next;
    });
    render();
  }

  function hideAll() {
    setLayerMeta((prev) => {
      const next = { ...prev };
      for (const layer of Object.keys(next)) {
        next[layer] = { ...next[layer], visible: false };
        if (layerGroupsRef.current[layer]) layerGroupsRef.current[layer].visible = false;
      }
      return next;
    });
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
  const filteredText = useMemo(() => {
    if (!textFilter) return textEntities.slice(0, MAX_TEXT_ITEMS);
    const needle = textFilter.toLowerCase();
    return textEntities
      .filter((item) => item.text.toLowerCase().includes(needle) || item.layer.toLowerCase().includes(needle))
      .slice(0, MAX_TEXT_ITEMS);
  }, [textEntities, textFilter]);
  const scaledDistance = Number.isFinite(measureDistance)
    ? measureDistance * (Number.isFinite(scaleMultiplier) ? scaleMultiplier : 1)
    : null;
  const rawDistanceLabel = Number.isFinite(measureDistance) ? measureDistance.toFixed(2) : "—";
  const scaledDistanceLabel = Number.isFinite(scaledDistance) ? scaledDistance.toFixed(2) : "—";
  const selectedRule = rules.find((rule) => rule.id === selectedRuleId);
  const previewPass = evaluateRule(selectedRule, measuredValue);

  return (
    <div className="layout">
      <div className="sidebar">
        <h3 style={{ margin: "0 0 6px 0" }}>Layer mapping</h3>
        <div className="muted">Toggle visibility and tag layers for: plot boundary, floor footprints, dimensions, text, etc.</div>

        <div style={{ margin: "10px 0", display: "flex", gap: 8, flexWrap: "wrap" }}>
          <button type="button" onClick={showAll}>Show all</button>
          <button type="button" onClick={hideAll}>Hide all</button>
          <button type="button" onClick={fitView}>Fit view</button>
          <button type="button" onClick={() => zoomBy(1.15)}>Zoom in</button>
          <button type="button" onClick={() => zoomBy(0.87)}>Zoom out</button>
          <button type="button" onClick={snapshot}>Snapshot</button>
        </div>

        <form method="POST" action={config.storeUrl}>
          <input type="hidden" name="_token" value={config.csrfToken} />
          <input type="hidden" name="layer_map_json" value={layerMapJson} readOnly />

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginBottom: 10 }}>
            <div className="muted">Quick preset:</div>
            <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
              <button type="button" onClick={preset5m}>5 Marla preset</button>
              <button type="button" onClick={clearTags}>Clear tags</button>
            </div>
          </div>

          <div>
            {layerOrder.map((name) => (
              <div className="layer-row" key={name}>
                <input
                  type="checkbox"
                  checked={!!layerMeta[name]?.visible}
                  onChange={(e) => updateLayerMeta(name, { visible: e.target.checked })}
                />
                <div className="layer-name" title={name}>{name}</div>
                <select
                  value={layerMeta[name]?.tag || ""}
                  onChange={(e) => updateLayerMeta(name, { tag: e.target.value })}
                >
                  {TAG_OPTIONS.map((opt) => (
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
              <form onSubmit={saveExpertResult} style={{ marginTop: 8, display: "grid", gap: 8 }}>
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
                <label className="muted">Measured value</label>
                <input
                  type="number"
                  step="0.01"
                  value={measuredValue}
                  onChange={(event) => setMeasuredValue(event.target.value)}
                />
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
                <button type="submit" disabled={savingResult}>
                  {savingResult ? "Saving..." : "Save expert result"}
                </button>
              </form>
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
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Text from CAD</div>
            <div className="muted">Showing {Math.min(textEntities.length, MAX_TEXT_ITEMS)} of {textEntities.length} text entities.</div>
            <input
              type="text"
              value={textFilter}
              onChange={(e) => setTextFilter(e.target.value)}
              placeholder="Filter text or layer"
              style={{ width: "100%", marginTop: 8, padding: 6 }}
            />
            <div style={{ marginTop: 8, maxHeight: 220, overflow: "auto", borderTop: "1px dashed #eee", paddingTop: 6 }}>
              {filteredText.length ? filteredText.map((item, idx) => (
                <div key={`${item.layer}-${idx}`} style={{ marginBottom: 8 }}>
                  <div style={{ fontSize: 13 }}>{item.text}</div>
                  <div className="muted">Layer: {item.layer}</div>
                </div>
              )) : (
                <div className="muted">No text entities found.</div>
              )}
            </div>
          </div>

          <div className="card" style={{ border: "1px solid #eee", borderRadius: 10, padding: 10, marginTop: 14 }}>
            <div style={{ fontWeight: 600, marginBottom: 6 }}>Scale measure</div>
            <div className="muted">Pick two points in the drawing to measure distance. Use a detected scale or enter one manually.</div>
            <div style={{ marginTop: 8, display: "flex", gap: 8, flexWrap: "wrap" }}>
              <button
                type="button"
                onClick={() => {
                  const next = !measureMode;
                  setMeasureMode(next);
                  measurePointsRef.current = [];
                  setMeasurePoints([]);
                  setMeasureDistance(null);
                  if (!next) updateMeasureLine([]);
                }}
              >
                {measureMode ? "Exit measure mode" : "Start measure mode"}
              </button>
              <button
                type="button"
                onClick={() => {
                  measurePointsRef.current = [];
                  setMeasurePoints([]);
                  setMeasureDistance(null);
                  updateMeasureLine([]);
                }}
              >
                Clear measure
              </button>
            </div>
            <div style={{ marginTop: 8, display: "grid", gap: 6 }}>
              <div className="muted">Clicks: {measurePoints.length}/2</div>
              <div>Raw distance: <strong>{rawDistanceLabel}</strong></div>
              <div>Scaled distance ({scaleLabel}): <strong>{scaledDistanceLabel}</strong></div>
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
                <input
                  id="scale-input"
                  type="number"
                  min="0"
                  step="0.01"
                  value={Number.isFinite(scaleMultiplier) ? scaleMultiplier : ""}
                  onChange={(e) => {
                    const next = Number(e.target.value);
                    if (!Number.isFinite(next) || next <= 0) {
                      setScaleMultiplier(1);
                      setScaleLabel("1:1");
                    } else {
                      setScaleMultiplier(next);
                      setScaleLabel(`1:${next}`);
                    }
                  }}
                  style={{ flex: "1 1 auto", padding: 6 }}
                />
                <button
                  type="button"
                  onClick={() => {
                    setScaleMultiplier(1);
                    setScaleLabel("1:1");
                  }}
                >
                  Reset
                </button>
              </div>
            </div>
          </div>

          <div style={{ position: "sticky", bottom: 0, background: "#fff", paddingTop: 10, borderTop: "1px solid #eee", marginTop: 10 }}>
            <button type="submit">Save mapping</button>
            <a href={config.backToLabelUrl || `/admin/plan/cad-submissions/${config.submissionId}/labeling`} style={{ marginLeft: 10 }}>
              Back to labeling
            </a>
            <div className="muted" style={{ marginTop: 8 }}>
              Saved mapping will be used by the Python pipeline to compute multi-storey areas (FAR) and select the correct footprints.
            </div>
          </div>
        </form>
      </div>

      <div className="main">
        <div className="topbar">
          <span className="pill">Submission #{config.submissionId}</span>
          <span className="pill">DXF: {config.hasDxf ? "available" : "missing"}</span>
          <span className="muted">Tip: click lines to see their layer.</span>
          <span className="muted" style={{ marginLeft: "auto" }}>{hoverText}</span>
        </div>
        <div className="canvas-wrap">
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
        </div>
        <div className="muted" style={{ position: "absolute", bottom: 8, right: 12 }}>{summaryText}</div>
      </div>
    </div>
  );
}

const mount = document.getElementById("cad-layer-viewer-root");
if (mount) {
  if (!mount.__cadViewerRoot) {
    mount.__cadViewerRoot = createRoot(mount);
  }
  mount.__cadViewerRoot.render(<App />);
}
