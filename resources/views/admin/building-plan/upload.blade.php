@extends('layouts.app')

@section('title', 'Building Plan AI Applications')

@section('content')
@php
    $totalApps = $applications->count();
    $needsReview = $applications->where('status', 'Needs Expert Review')->count();
    $submittedToAd = $applications->filter(fn ($app) => in_array($app->status, ['Submitted to AD ePermit', 'Under AD ePermit Review', 'Forwarded to DDTP', 'Under DDTP Review', 'Approved', 'Rejected'], true))->count();
    $googleMapsApiKey = (string) config('services.google.maps_api_key', '');

    $statusClass = function (string $status): string {
        return match ($status) {
            'Approved' => 'is-approved',
            'Rejected' => 'is-rejected',
            'Needs Expert Review', 'Under AD ePermit Review', 'Under DDTP Review' => 'is-review',
            'Submitted to AD ePermit', 'Forwarded to DDTP' => 'is-routed',
            default => 'is-default',
        };
    };
@endphp

<style>
.bp-hero{position:relative;border-radius:18px;padding:28px;background:linear-gradient(130deg,#0f172a 0%,#102a43 45%,#0f766e 100%);color:#f8fafc;overflow:hidden;box-shadow:0 24px 60px rgba(15,23,42,.22);margin-bottom:18px}
.bp-hero:before,.bp-hero:after{content:"";position:absolute;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,.22),rgba(255,255,255,0));pointer-events:none}
.bp-hero:before{width:280px;height:280px;right:-120px;top:-120px}.bp-hero:after{width:220px;height:220px;left:-90px;bottom:-90px}
.bp-hero-title{font-size:clamp(1.45rem,2.3vw,2rem);line-height:1.15;font-weight:800;letter-spacing:-.02em;margin-bottom:8px;position:relative;z-index:1}
.bp-hero-subtitle{color:rgba(241,245,249,.9);max-width:760px;position:relative;z-index:1}
.bp-kpis{margin-top:18px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;position:relative;z-index:1}
.bp-kpi{border:1px solid rgba(255,255,255,.18);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.08)}
.bp-kpi-label{font-size:12px;color:rgba(241,245,249,.85)}.bp-kpi-value{font-size:1.35rem;font-weight:800;line-height:1.1;margin-top:4px}
.bp-panel{border-radius:14px;border:1px solid rgba(148,163,184,.24);box-shadow:0 18px 36px rgba(15,23,42,.07)}
.bp-panel .card-header{border-radius:14px 14px 0 0;background:linear-gradient(180deg,#fff 0%,#f8fafc 100%);font-weight:700;font-size:.95rem}
.bp-upload-form .form-label{font-weight:700;color:#334155;margin-bottom:.32rem}
.bp-upload-form .form-control{border-radius:10px;border-color:#d6dde6;padding-top:.62rem;padding-bottom:.62rem}
.bp-upload-form .form-control:focus{border-color:#0f766e;box-shadow:0 0 0 .2rem rgba(15,118,110,.12)}
.bp-upload-note{border-radius:10px;background:#f8fafc;border:1px dashed #cbd5e1;padding:10px 12px;color:#475569;font-size:12px}
.bp-btn-primary{border:0;border-radius:10px;padding:10px 14px;font-weight:700;background:linear-gradient(135deg,#0f766e,#0b5f59);color:#fff;box-shadow:0 10px 20px rgba(15,118,110,.24)}
.bp-btn-primary:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(15,118,110,.3);color:#fff}
.bp-table thead th{background:#f8fafc;border-bottom-color:#e2e8f0;font-size:12px;font-weight:800;letter-spacing:.02em;text-transform:uppercase;color:#475569}
.bp-app-no{font-weight:700;color:#0f172a;font-size:.89rem}
.bp-status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:800;letter-spacing:.01em;border:1px solid transparent}
.bp-status.is-approved{background:#dcfce7;color:#166534;border-color:#86efac}.bp-status.is-rejected{background:#fee2e2;color:#991b1b;border-color:#fecaca}.bp-status.is-review{background:#fef9c3;color:#854d0e;border-color:#fde68a}.bp-status.is-routed{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd}.bp-status.is-default{background:#e2e8f0;color:#334155;border-color:#cbd5e1}
.bp-enter{animation:bpEnter .35s ease both}@keyframes bpEnter{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.bp-map-box{border-radius:12px;border:1px solid #dbe4ee;background:#fbfdff;padding:12px}
.bp-map-meta{margin-top:10px;border-radius:10px;border:1px dashed #cbd5e1;padding:10px;font-size:12px;color:#475569;background:#fff}
.bp-site-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:800;background:#e2e8f0;color:#334155}
.bp-site-chip.is-open{background:#dcfce7;color:#166534}
.bp-site-chip.is-mixed{background:#fef3c7;color:#92400e}
.bp-site-chip.is-built{background:#fee2e2;color:#991b1b}
.bp-map-search{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}
.bp-map-search-wrap{position:relative}
.bp-map-suggest{position:absolute;left:0;right:0;top:100%;z-index:30;background:#fff;border:1px solid #d6dde6;border-radius:8px;box-shadow:0 10px 24px rgba(15,23,42,.12);margin-top:4px;max-height:220px;overflow:auto;display:none}
.bp-map-suggest button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 10px;font-size:12px;color:#1f2937}
.bp-map-suggest button:hover{background:#f1f5f9}
.bp-map-actions{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.bp-map-canvas{width:100%;height:260px;border-radius:10px;border:1px solid #d6dde6;background:linear-gradient(180deg,#dbeafe,#f8fafc)}
.bp-geocode-debug{margin-top:10px;border-radius:10px;border:1px solid #dbe4ee;background:#f8fafc;padding:10px}
.bp-geocode-debug pre{margin:0;white-space:pre-wrap;word-break:break-word;font-size:11px;line-height:1.45;color:#334155;max-height:180px;overflow:auto}
.bp-geo-modal{position:fixed;inset:0;z-index:5100;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.68);backdrop-filter:blur(3px)}
.bp-geo-card{width:min(920px,calc(100vw - 24px));max-height:calc(100vh - 24px);overflow:auto;background:#fff;border-radius:14px;border:1px solid #dbe4ee;box-shadow:0 30px 80px rgba(15,23,42,.32)}
.bp-geo-head{padding:14px 16px;background:linear-gradient(135deg,#0f766e,#113f67);color:#fff;display:flex;align-items:center;justify-content:space-between}
.bp-geo-close{border:0;background:rgba(255,255,255,.16);color:#fff;width:32px;height:32px;border-radius:50%;font-size:20px;line-height:1}
.bp-geo-body{padding:14px 16px}
.bp-geo-step{font-size:12px;color:#475569;margin-bottom:8px}
.bp-geo-progress{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:10px 0 12px}
.bp-geo-progress-fill{height:100%;width:0;background:linear-gradient(135deg,#0f766e,#22d3ee);transition:width .25s ease}
.bp-processing-overlay{position:fixed;inset:0;z-index:5000;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,.62);backdrop-filter:blur(2px)}
.bp-processing-card{width:min(560px,calc(100vw - 24px));background:#fff;border-radius:14px;border:1px solid #dbe4ee;box-shadow:0 30px 80px rgba(15,23,42,.28);overflow:hidden}
.bp-processing-head{padding:14px 16px;background:linear-gradient(135deg,#0f766e,#113f67);color:#fff;font-weight:800}.bp-processing-body{padding:14px 16px}
.bp-step{display:flex;align-items:center;justify-content:space-between;border-radius:10px;padding:8px 10px;margin-bottom:8px;border:1px solid #e2e8f0;background:#f8fafc;font-size:13px}
.bp-step.is-active{border-color:#99f6e4;background:#ecfeff;color:#0f766e;font-weight:700}.bp-step.is-done{border-color:#86efac;background:#f0fdf4;color:#166534}
@media(max-width:991px){.bp-kpis{grid-template-columns:1fr}}
</style>

<div class="bp-hero bp-enter">
    <div class="bp-hero-title">Building Plan AI Approval Workspace</div>
    <div class="bp-hero-subtitle">Upload plan files, trigger AI scrutiny, and route vetted cases to AD ePermit/DDTP with one professional workflow.</div>
    <div class="bp-kpis">
        <div class="bp-kpi"><div class="bp-kpi-label">Recent Applications</div><div class="bp-kpi-value">{{ $totalApps }}</div></div>
        <div class="bp-kpi"><div class="bp-kpi-label">Needs Expert Review</div><div class="bp-kpi-value">{{ $needsReview }}</div></div>
        <div class="bp-kpi"><div class="bp-kpi-label">Routed To Authorities</div><div class="bp-kpi-value">{{ $submittedToAd }}</div></div>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success bp-enter">{{ session('status') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-5 bp-enter">
        <div class="card bp-panel h-100">
            <div class="card-header">New Application Upload</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.plan.bp.store') }}" enctype="multipart/form-data" class="row g-3 bp-upload-form" id="bp-upload-form">
                    @csrf
                    <div class="col-12"><label class="form-label">Applicant Name</label><input class="form-control" type="text" name="applicant_name" value="{{ old('applicant_name') }}" placeholder="Enter applicant full name"></div>
                    <div class="col-md-6"><label class="form-label">Applicant Email</label><input class="form-control" type="email" name="applicant_email" value="{{ old('applicant_email') }}" placeholder="name@email.com"></div>
                    <div class="col-md-6"><label class="form-label">Applicant Phone</label><input class="form-control" type="text" name="applicant_phone" value="{{ old('applicant_phone') }}" placeholder="03xx xxxxxxx"></div>
                    <div class="col-12"><label class="form-label">Plan File (DWG / DXF / CAD / PDF)</label><input class="form-control" type="file" name="map_file" required></div>
                    <div class="col-12"><label class="form-label">List Document (DOCX, optional)</label><input class="form-control" type="file" name="list_document" accept=".docx"><div class="bp-upload-note mt-2">Auto-extracts applicant details, plot metadata, and layer-table signals to speed up verification.</div></div>

                    <div class="col-12">
                        <div class="bp-map-box">
                            <div class="fw-bold mb-2">Property Verification (Google Satellite)</div>
                            @if($googleMapsApiKey !== '')
                                <div class="bp-map-meta">
                                    <div><strong>Selected Address:</strong> <span id="bp-map-address">Not selected</span></div>
                                    <div class="mt-1"><strong>Plot Number:</strong> <span id="bp-map-plot-number">Not available</span></div>
                                    <div class="mt-1"><strong>Coordinates:</strong> <span id="bp-map-coords">-</span></div>
                                    <div class="mt-1"><strong>Site Signal:</strong> <span id="bp-site-signal" class="bp-site-chip">Not evaluated</span></div>
                                    <div class="small text-muted mt-2">Signal is heuristic from nearby structure density. Final field verification remains mandatory.</div>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="text" id="bp-map-scheme" name="map_scheme" placeholder="Scheme"></div>
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="text" id="bp-map-phase" name="map_phase" placeholder="Phase"></div>
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="text" id="bp-map-block" name="map_block" placeholder="Block"></div>
                                    <div class="col-md-3"><input class="form-control form-control-sm" type="text" id="bp-map-plot-ref" name="map_plot_ref" placeholder="Plot Ref"></div>
                                </div>
                                <div class="mt-2 small text-muted">Geo verification starts automatically when you click <strong>Upload &amp; Generate AI Report</strong>.</div>
                                <input type="hidden" name="map_lat" id="bp-map-lat">
                                <input type="hidden" name="map_lng" id="bp-map-lng">
                                <input type="hidden" name="map_place_id" id="bp-map-place-id">
                                <input type="hidden" name="map_formatted_address" id="bp-map-formatted-address">
                                <input type="hidden" name="map_plot_number" id="bp-map-plot-number-input">
                                <input type="hidden" name="map_site_signal" id="bp-map-site-signal">
                                <input type="hidden" name="map_geocode_json" id="bp-map-geocode-json">
                            @else
                                <div class="alert alert-warning mb-0">Google Maps is not configured. Add <code>GOOGLE_MAPS_API_KEY</code> in your environment.</div>
                            @endif
                        </div>
                    </div>

                    <div class="col-12 d-grid"><button class="bp-btn-primary" type="submit">Upload & Generate AI Report</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7 bp-enter" style="animation-delay:.06s;">
        <div class="card bp-panel">
            <div class="card-header d-flex justify-content-between align-items-center"><span>Recent Applications</span><span class="text-muted small">Latest {{ $applications->count() }} records</span></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 bp-table">
                        <thead><tr><th>No</th><th>Applicant</th><th>Status</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                        @forelse($applications as $app)
                            <tr>
                                <td><span class="bp-app-no">{{ $app->application_number }}</span></td>
                                <td>{{ $app->applicant_name ?: '-' }}</td>
                                <td><span class="bp-status {{ $statusClass((string) $app->status) }}">{{ $app->status }}</span></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.plan.bp.portal', $app) }}">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No applications yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="bp-geo-modal" id="bp-geo-modal" aria-hidden="true">
    <div class="bp-geo-card">
        <div class="bp-geo-head"><div><div class="fw-bold">Geo Verification Wizard</div><div class="small">Search property, zoom to plot, and verify site signal.</div></div><button type="button" class="bp-geo-close" id="bp-close-geo-modal">&times;</button></div>
        <div class="bp-geo-body">
            <div class="bp-geo-step" id="bp-geo-step-label">Step 1/3: Search and locate property.</div>
            <div class="bp-geo-progress"><div class="bp-geo-progress-fill" id="bp-geo-progress-fill"></div></div>
            <div class="bp-map-search">
                <div class="bp-map-search-wrap">
                    <input type="text" id="bp-map-search-input" class="form-control" placeholder="Search property address or drop a pin location">
                    <div class="bp-map-suggest" id="bp-map-suggest"></div>
                </div>
                <button type="button" class="btn btn-outline-secondary" id="bp-map-search-btn">Locate</button>
            </div>
            <div class="bp-map-actions"><button type="button" class="btn btn-outline-dark btn-sm" id="bp-map-zoom-btn">Zoom In To Plot</button><button type="button" class="btn btn-success btn-sm" id="bp-geo-done-btn">Use This Location</button></div>
            <div id="bp-map-canvas" class="bp-map-canvas"></div>
            <div class="bp-geocode-debug" id="bp-geocode-debug">
                <div class="fw-bold mb-1">Google Geocode Details</div>
                <pre id="bp-geocode-debug-text">No geocode response yet.</pre>
            </div>
            <div class="bp-processing-wizard d-none" id="bp-processing-wizard">
                <div class="bp-step" data-step="1"><span>1. Uploading CAD file</span><span>…</span></div>
                <div class="bp-step" data-step="2"><span>2. Extracting plot details from document</span><span>…</span></div>
                <div class="bp-step" data-step="3"><span>3. Geo verification and site signal</span><span>…</span></div>
                <div class="bp-step" data-step="4"><span>4. Running AI compliance checks</span><span>…</span></div>
                <div class="bp-step" data-step="5"><span>5. Preparing authority-ready report</span><span>…</span></div>
                <div class="small text-muted mt-2">Please wait while the application is processed.</div>
            </div>
        </div>
    </div>
</div>

@if($googleMapsApiKey !== '')
<script async defer src="https://maps.googleapis.com/maps/api/js?key={{ $googleMapsApiKey }}&libraries=places&callback=initBpPropertyMap"></script>
<script>
window.initBpPropertyMap = function () {
    const mapEl = document.getElementById('bp-map-canvas');
    if (!mapEl || typeof google === 'undefined' || !google.maps) return;
    const defaultCenter = { lat: 31.5204, lng: 74.3587 };
    const lahoreBounds = new google.maps.LatLngBounds(
        { lat: 31.3000, lng: 74.1200 },
        { lat: 31.7200, lng: 74.5500 }
    );
    const map = new google.maps.Map(mapEl, { center: defaultCenter, zoom: 15, mapTypeId: 'satellite', tilt: 45, streetViewControl: false, fullscreenControl: true, restriction: { latLngBounds: lahoreBounds, strictBounds: false } });
    const marker = new google.maps.Marker({ map, position: defaultCenter, draggable: true });
    const geocoder = new google.maps.Geocoder();
    const places = new google.maps.places.PlacesService(map);
    const autocompleteService = new google.maps.places.AutocompleteService();
    const infoWindow = new google.maps.InfoWindow();

    const searchInput = document.getElementById('bp-map-search-input');
    const suggestBox = document.getElementById('bp-map-suggest');
    const searchBtn = document.getElementById('bp-map-search-btn');
    const zoomBtn = document.getElementById('bp-map-zoom-btn');
    const closeGeoBtn = document.getElementById('bp-close-geo-modal');
    const geoDoneBtn = document.getElementById('bp-geo-done-btn');
    const geoModal = document.getElementById('bp-geo-modal');
    const geoStepLabel = document.getElementById('bp-geo-step-label');
    const geoProgressFill = document.getElementById('bp-geo-progress-fill');
    const mapSearchWrap = document.querySelector('.bp-map-search');
    const mapActionWrap = document.querySelector('.bp-map-actions');
    const mapCanvas = document.getElementById('bp-map-canvas');
    const processingWizard = document.getElementById('bp-processing-wizard');
    const geocodeDebugWrap = document.getElementById('bp-geocode-debug');
    const geocodeDebugText = document.getElementById('bp-geocode-debug-text');
    const form = document.getElementById('bp-upload-form');
    const cadFileInput = document.querySelector('input[name="map_file"]');
    const schemeInput = document.getElementById('bp-map-scheme');
    const phaseInput = document.getElementById('bp-map-phase');
    const blockInput = document.getElementById('bp-map-block');
    const plotRefInput = document.getElementById('bp-map-plot-ref');
    let geoVerified = false;
    let bypassGeoGate = false;

    function openGeoWizard() {
        applyCadContextAutofill();
        geoModal.style.display = 'flex';
        geoModal.setAttribute('aria-hidden', 'false');
        geoStepLabel.textContent = 'Step 1/3: Search and locate property.';
        geoProgressFill.style.width = '33%';
        if (mapSearchWrap) mapSearchWrap.classList.remove('d-none');
        if (mapActionWrap) mapActionWrap.classList.remove('d-none');
        if (mapCanvas) mapCanvas.classList.remove('d-none');
        if (geocodeDebugWrap) geocodeDebugWrap.classList.remove('d-none');
        if (processingWizard) processingWizard.classList.add('d-none');
        google.maps.event.trigger(map, 'resize');
        map.setCenter(marker.getPosition() || defaultCenter);
    }

    function showProcessingInSamePopup() {
        geoModal.style.display = 'flex';
        geoModal.setAttribute('aria-hidden', 'false');
        geoStepLabel.textContent = 'Finalizing: AI analysis and report generation.';
        geoProgressFill.style.width = '100%';
        if (mapSearchWrap) mapSearchWrap.classList.add('d-none');
        if (mapActionWrap) mapActionWrap.classList.add('d-none');
        if (mapCanvas) mapCanvas.classList.add('d-none');
        if (geocodeDebugWrap) geocodeDebugWrap.classList.add('d-none');
        if (processingWizard) processingWizard.classList.remove('d-none');
    }

    const autocomplete = new google.maps.places.Autocomplete(searchInput, {
        fields: ['geometry', 'formatted_address', 'place_id', 'name', 'address_components'],
        bounds: lahoreBounds,
        strictBounds: true,
        componentRestrictions: { country: 'pk' },
    });
    let suggestions = [];
    let suggestTimer = null;

    function hideSuggestions() {
        if (!suggestBox) return;
        suggestBox.style.display = 'none';
        suggestBox.innerHTML = '';
    }

    function contextTokens() {
        return [
            String(plotRefInput?.value || '').trim(),
            String(blockInput?.value || '').trim(),
            String(phaseInput?.value || '').trim(),
            String(schemeInput?.value || '').trim(),
        ].filter(Boolean);
    }

    function inferContextFromCadFilename(filename) {
        const base = String(filename || '').replace(/\.[^/.]+$/, '').trim();
        if (!base) return null;
        const cleaned = base.replace(/\s+/g, ' ');
        const p1 = cleaned.match(/(?:plot\s*)?(\d+[A-Z0-9\-\/]*)\s*[, ]+\s*(?:block\s*)?([A-Z]\d?)\s+(.+)/i);
        if (p1) {
            return {
                plot: String(p1[1] || '').trim(),
                block: String(p1[2] || '').trim().toUpperCase(),
                scheme: String(p1[3] || '').trim(),
            };
        }
        const p2 = cleaned.match(/(?:plot\s*)?(\d+[A-Z0-9\-\/]*)\s+(.+)/i);
        if (p2) {
            return {
                plot: String(p2[1] || '').trim(),
                block: '',
                scheme: String(p2[2] || '').trim(),
            };
        }
        return null;
    }

    function applyCadContextAutofill() {
        const file = cadFileInput?.files?.[0];
        const parsed = inferContextFromCadFilename(file?.name || '');
        if (!parsed) return;
        if (plotRefInput && !plotRefInput.value.trim()) plotRefInput.value = parsed.plot || '';
        if (blockInput && !blockInput.value.trim()) blockInput.value = parsed.block || '';
        if (schemeInput && !schemeInput.value.trim()) schemeInput.value = parsed.scheme || '';
        if (searchInput && !searchInput.value.trim()) {
            const parts = [];
            if (parsed.plot) parts.push(`Plot ${parsed.plot}`);
            if (parsed.block) parts.push(`Block ${parsed.block}`);
            if (parsed.scheme) parts.push(parsed.scheme);
            const seed = parts.join(', ');
            searchInput.value = seed !== '' ? `${seed}, Lahore, Pakistan` : 'Lahore, Pakistan';
        }
    }

    function buildContextQuery(base) {
        const tokens = contextTokens();
        const suffix = tokens.length ? (', ' + tokens.join(', ')) : '';
        return `${String(base || '').trim()}${suffix}, Lahore, Pakistan`;
    }

    function tokenMatchStats(text) {
        const tokens = contextTokens().map((t) => t.toLowerCase()).filter(Boolean);
        const hay = String(text || '').toLowerCase();
        if (tokens.length === 0) {
            return { ok: true, hits: 0, total: 0, ratio: 1 };
        }
        const hits = tokens.reduce((n, t) => n + (hay.includes(t) ? 1 : 0), 0);
        const ratio = hits / tokens.length;
        // relaxed rule: at least 1 hit OR >= 40% token match
        return {
            ok: hits >= 1 || ratio >= 0.4,
            hits,
            total: tokens.length,
            ratio,
        };
    }

    function renderSuggestions(predictions) {
        if (!suggestBox) return;
        if (!Array.isArray(predictions) || predictions.length === 0) {
            hideSuggestions();
            return;
        }
        suggestBox.innerHTML = predictions.map((p, idx) =>
            `<button type="button" data-idx="${idx}">${String(p.description || '').replace(/</g, '&lt;')}</button>`
        ).join('');
        suggestBox.style.display = 'block';
    }

    function selectPrediction(prediction) {
        if (!prediction || !prediction.place_id) return;
        const predStats = tokenMatchStats(prediction.description || '');
        if (!predStats.ok) {
            alert('Selected suggestion does not match Scheme/Phase/Block/Plot context. Please choose a matching Lahore location.');
            return;
        }
        geocoder.geocode({ placeId: prediction.place_id }, (results, status) => {
            if (status !== 'OK' || !results || !results[0]) return;
            const top = results[0];
            if (!lahoreBounds.contains(top.geometry.location)) {
                alert('Please select a location within Lahore only.');
                return;
            }
            const addrStats = tokenMatchStats(top.formatted_address || prediction.description || '');
            if (!addrStats.ok) {
                alert('Resolved address has weak context match. Please refine Scheme/Phase/Block/Plot or select another suggestion.');
                return;
            }
            searchInput.value = top.formatted_address || prediction.description || searchInput.value;
            currentPlotNumber = derivePlotNumber(top.address_components || [], top.formatted_address || '', currentPlotNumber);
            plotNumberInput.value = currentPlotNumber;
            plotNumberLabel.textContent = currentPlotNumber || 'Not available';
            setLocation(top.geometry.location, top.formatted_address || prediction.description || '', top.place_id || prediction.place_id);
            hideSuggestions();
        });
    }

    const latInput = document.getElementById('bp-map-lat');
    const lngInput = document.getElementById('bp-map-lng');
    const placeIdInput = document.getElementById('bp-map-place-id');
    const addressInput = document.getElementById('bp-map-formatted-address');
    const plotNumberInput = document.getElementById('bp-map-plot-number-input');
    const siteSignalInput = document.getElementById('bp-map-site-signal');
    const geocodeJsonInput = document.getElementById('bp-map-geocode-json');
    const addressLabel = document.getElementById('bp-map-address');
    const plotNumberLabel = document.getElementById('bp-map-plot-number');
    const coordsLabel = document.getElementById('bp-map-coords');
    const signalChip = document.getElementById('bp-site-signal');
    let currentSiteSignal = 'Not evaluated';
    let currentPlotNumber = '';

    function extractPlotNumberFromComponents(components) {
        if (!Array.isArray(components)) return '';
        const byType = (type) => {
            const hit = components.find((c) => Array.isArray(c.types) && c.types.includes(type));
            return hit?.long_name || hit?.short_name || '';
        };
        return byType('street_number') || byType('premise') || byType('subpremise') || '';
    }

    function extractPlotNumberFromAddress(address) {
        const text = String(address || '');
        if (!text) return '';
        const patterns = [
            /(?:plot|plt|house|h\.?\s*no|no\.?)\s*[:#-]?\s*([a-z0-9\-\/]+)/i,
            /^([a-z0-9\-\/]+)\s*,/i,
        ];
        for (const p of patterns) {
            const m = text.match(p);
            if (m && m[1]) return String(m[1]).trim();
        }
        return '';
    }

    function derivePlotNumber(components, address, fallback = '') {
        return extractPlotNumberFromComponents(components)
            || extractPlotNumberFromAddress(address)
            || String(fallback || '').trim();
    }

    function showGeocodeDebug(data) {
        if (!geocodeDebugText) return;
        geocodeDebugText.textContent = JSON.stringify(data, null, 2);
        if (geocodeJsonInput) {
            geocodeJsonInput.value = JSON.stringify(data);
        }
    }

    function currentDebugObject() {
        if (!geocodeDebugText) return {};
        try {
            return JSON.parse(geocodeDebugText.textContent || '{}');
        } catch (_) {
            return {};
        }
    }

    function renderInfoWindow(addressText, latLng, signalText) {
        const lat = latLng?.lat ? latLng.lat() : null;
        const lng = latLng?.lng ? latLng.lng() : null;
        const html = `
            <div style="min-width:240px;font-size:12px;line-height:1.45;">
                <div style="font-weight:700;margin-bottom:4px;">Selected Plot</div>
                <div><strong>Address:</strong> ${addressText || 'Pinned map location'}</div>
                <div><strong>Plot Number:</strong> ${currentPlotNumber || 'Not available'}</div>
                <div><strong>Coordinates:</strong> ${lat !== null && lng !== null ? `${lat.toFixed(6)}, ${lng.toFixed(6)}` : '-'}</div>
                <div><strong>Site Signal:</strong> ${signalText || 'Not evaluated'}</div>
                <div style="margin-top:6px;color:#475569;">Tip: Drag marker to fine-tune exact plot location.</div>
            </div>
        `;
        infoWindow.setContent(html);
        infoWindow.open({ map, anchor: marker });
    }

    function resolvePlotMeta(latLng, fallbackAddress = '', fallbackPlaceId = '') {
        geocoder.geocode({ location: latLng }, (results, status) => {
            if (status !== 'OK' || !results || !results[0]) {
                showGeocodeDebug({
                    status,
                    note: 'No reverse geocode match returned by Google for this location.',
                    query: { lat: latLng.lat(), lng: latLng.lng() },
                });
                return;
            }
            const top = results[0];
            const derivedAddress = top.formatted_address || fallbackAddress || '';
            const derivedPlaceId = top.place_id || fallbackPlaceId || '';
            const derivedPlot = derivePlotNumber(top.address_components || [], derivedAddress, currentPlotNumber);

            if (derivedAddress) {
                addressInput.value = derivedAddress;
                addressLabel.textContent = derivedAddress;
            }
            if (derivedPlaceId) {
                placeIdInput.value = derivedPlaceId;
            }
            currentPlotNumber = derivedPlot || currentPlotNumber || '';
            plotNumberInput.value = currentPlotNumber;
            plotNumberLabel.textContent = currentPlotNumber || 'Not available';
            showGeocodeDebug({
                status,
                query: { lat: latLng.lat(), lng: latLng.lng() },
                result: {
                    formatted_address: derivedAddress,
                    place_id: derivedPlaceId,
                    detected_plot_number: currentPlotNumber || null,
                    address_components: (top.address_components || []).map((c) => ({
                        long_name: c.long_name,
                        short_name: c.short_name,
                        types: c.types,
                    })),
                },
            });
        });
    }

    function nearbyCount(latLng, type, radius = 120) {
        return new Promise((resolve) => {
            places.nearbySearch({ location: latLng, radius, type }, (results, status) => {
                if (status !== google.maps.places.PlacesServiceStatus.OK || !Array.isArray(results)) {
                    resolve(0);
                    return;
                }
                resolve(results.length);
            });
        });
    }

    function reverseGeocodeSignals(latLng) {
        return new Promise((resolve) => {
            geocoder.geocode({ location: latLng }, (results, status) => {
                if (status !== 'OK' || !results || !results[0]) {
                    resolve({ hasStreetNumber: false, hasPremise: false, localityText: '' });
                    return;
                }
                const top = results[0];
                const comps = Array.isArray(top.address_components) ? top.address_components : [];
                const hasType = (t) => comps.some((c) => Array.isArray(c.types) && c.types.includes(t));
                resolve({
                    hasStreetNumber: hasType('street_number'),
                    hasPremise: hasType('premise') || hasType('subpremise'),
                    localityText: String(top.formatted_address || ''),
                });
            });
        });
    }

    async function updateSignal(latLng, addressText = '') {
        const [premiseCount, poiCount, establishmentCount, geocodeSig] = await Promise.all([
            nearbyCount(latLng, 'premise', 140),
            nearbyCount(latLng, 'point_of_interest', 170),
            nearbyCount(latLng, 'establishment', 170),
            reverseGeocodeSignals(latLng),
        ]);

        const strongBuilt = geocodeSig.hasPremise;
        const weightedScore = (premiseCount * 2.2) + (poiCount * 0.8) + (establishmentCount * 1.0);
        const normalizedScore = Math.min(100, Math.max(0, Math.round((weightedScore / 18) * 100)));

        let signal = 'Mixed context / verify on site';
        let cls = 'is-mixed';
        if ((premiseCount >= 3) || (strongBuilt && weightedScore >= 9)) {
            signal = 'Likely built-up / structure present nearby';
            cls = 'is-built';
        } else if (premiseCount === 0 && establishmentCount <= 1 && poiCount <= 1) {
            signal = 'Likely open / low built-up context nearby';
            cls = 'is-open';
        } else if (weightedScore <= 5 && !strongBuilt) {
            signal = 'Mostly open context (low structure signal)';
            cls = 'is-open';
        }

        signalChip.className = 'bp-site-chip ' + cls;
        signalChip.textContent = signal;
        currentSiteSignal = signal;
        siteSignalInput.value = signal;
        geoStepLabel.textContent = 'Step 3/3: Review site signal and confirm location.';
        geoProgressFill.style.width = '100%';
        renderInfoWindow(addressText || addressInput.value || 'Pinned map location', latLng, signal);

        showGeocodeDebug({
            ...currentDebugObject(),
            signal_diagnostics: {
                premise_count_140m: premiseCount,
                poi_count_170m: poiCount,
                establishment_count_170m: establishmentCount,
                has_street_number: geocodeSig.hasStreetNumber,
                has_premise: geocodeSig.hasPremise,
                weighted_score: Number(weightedScore.toFixed(2)),
                normalized_score_0_100: normalizedScore,
                final_signal: signal,
            },
        });
    }

    function setLocation(latLng, address, placeId) {
        map.setCenter(latLng);
        map.setZoom(16);
        marker.setPosition(latLng);
        latInput.value = latLng.lat(); lngInput.value = latLng.lng(); placeIdInput.value = placeId || ''; addressInput.value = address || '';
        addressLabel.textContent = address || 'Selected location';
        coordsLabel.textContent = latLng.lat().toFixed(6) + ', ' + latLng.lng().toFixed(6);
        plotNumberLabel.textContent = currentPlotNumber ? currentPlotNumber : 'Detecting...';
        geoStepLabel.textContent = 'Step 2/3: Zoom in to validate exact plot.';
        geoProgressFill.style.width = '66%';
        resolvePlotMeta(latLng, address || '', placeId || '');
        updateSignal(latLng, address || 'Pinned map location');
        setTimeout(() => cinematicZoomToPlot(true), 260);
    }

    function cinematicZoomToPlot(forceReplay = false) {
        const pos = marker.getPosition();
        if (!pos) return;
        const target = 20;
        let current = map.getZoom() || 15;
        if (forceReplay || current >= (target - 1)) {
            map.setZoom(14);
            current = 14;
        }
        map.panTo(pos);
        const timer = setInterval(() => {
            current += 1;
            map.setZoom(current);
            map.panTo(pos);
            if (current >= target) clearInterval(timer);
        }, 140);
        geoStepLabel.textContent = 'Step 3/3: Confirm this focused plot view.';
        geoProgressFill.style.width = '85%';
    }

    autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (!place || !place.geometry || !place.geometry.location) return;
        currentPlotNumber = derivePlotNumber(place.address_components || [], place.formatted_address || place.name || '', currentPlotNumber);
        plotNumberInput.value = currentPlotNumber;
        plotNumberLabel.textContent = currentPlotNumber || 'Not available';
        setLocation(place.geometry.location, place.formatted_address || place.name || '', place.place_id || '');
        hideSuggestions();
    });

    searchInput?.addEventListener('input', () => {
        const q = String(searchInput.value || '').trim();
        if (suggestTimer) clearTimeout(suggestTimer);
        if (q.length < 3) {
            suggestions = [];
            hideSuggestions();
            return;
        }
        suggestTimer = setTimeout(() => {
            autocompleteService.getPlacePredictions({ input: buildContextQuery(q), componentRestrictions: { country: 'pk' } }, (predictions, status) => {
                if (status !== google.maps.places.PlacesServiceStatus.OK || !Array.isArray(predictions)) {
                    suggestions = [];
                    hideSuggestions();
                    return;
                }
                const tokens = contextTokens().map((t) => t.toLowerCase());
                suggestions = predictions
                    .filter((p) => (p.description || '').toLowerCase().includes('lahore'))
                    .filter((p) => tokens.length === 0 || tokens.some((t) => String(p.description || '').toLowerCase().includes(t)))
                    .map((p) => {
                        const d = String(p.description || '').toLowerCase();
                        const score = tokens.reduce((s, t) => s + (t && d.includes(t) ? 1 : 0), 0);
                        return { p, score };
                    })
                    .sort((a, b) => b.score - a.score)
                    .map((x) => x.p)
                    .slice(0, 6);
                renderSuggestions(suggestions);
            });
        }, 160);
    });

    searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && suggestions.length > 0) {
            e.preventDefault();
            selectPrediction(suggestions[0]);
        }
    });

    suggestBox?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-idx]');
        if (!btn) return;
        const idx = Number(btn.getAttribute('data-idx'));
        if (Number.isNaN(idx) || !suggestions[idx]) return;
        selectPrediction(suggestions[idx]);
    });

    document.addEventListener('click', (e) => {
        if (!suggestBox || !searchInput) return;
        if (e.target === searchInput || suggestBox.contains(e.target)) return;
        hideSuggestions();
    });

    cadFileInput?.addEventListener('change', () => {
        applyCadContextAutofill();
    });

    searchBtn?.addEventListener('click', () => {
        const q = String(searchInput.value || '').trim();
        if (!q) return;
        geocoder.geocode({ address: buildContextQuery(q), componentRestrictions: { country: 'PK' } }, (results, status) => {
            if (status !== 'OK' || !results || !results[0]) return;
            const top = results[0];
            if (!lahoreBounds.contains(top.geometry.location)) {
                alert('Please select a location within Lahore only.');
                return;
            }
            const addrStats = tokenMatchStats(top.formatted_address || q);
            if (!addrStats.ok) {
                alert('Located address has weak context match. Please refine Scheme/Phase/Block/Plot or query.');
                return;
            }
            setLocation(top.geometry.location, top.formatted_address || q, top.place_id || '');
        });
    });

    map.addListener('click', (e) => {
        if (!e || !e.latLng) return;
        if (!lahoreBounds.contains(e.latLng)) {
            alert('Please select a location within Lahore only.');
            return;
        }
        setLocation(e.latLng, 'Pinned map location', '');
    });
    marker.addListener('dragend', (e) => {
        if (!e || !e.latLng) return;
        setLocation(e.latLng, 'Dragged marker location', placeIdInput.value || '');
    });
    marker.addListener('click', () => {
        const pos = marker.getPosition();
        if (!pos) return;
        renderInfoWindow(addressInput.value || 'Pinned map location', pos, currentSiteSignal);
    });

    zoomBtn?.addEventListener('click', () => {
        const q = String(searchInput.value || '').trim();
        if (q !== '') {
            geocoder.geocode({ address: buildContextQuery(q), componentRestrictions: { country: 'PK' } }, (results, status) => {
                if (status === 'OK' && results && results[0]) {
                    const top = results[0];
                    if (lahoreBounds.contains(top.geometry.location) && tokensMatchText(top.formatted_address || q)) {
                        setLocation(top.geometry.location, top.formatted_address || q, top.place_id || '');
                        return;
                    }
                }
                // fallback to current marker position if geocode does not match context
                cinematicZoomToPlot(true);
            });
            return;
        }
        cinematicZoomToPlot(true);
    });
    closeGeoBtn?.addEventListener('click', () => { geoModal.style.display = 'none'; geoModal.setAttribute('aria-hidden', 'true'); });
    geoDoneBtn?.addEventListener('click', () => {
        geoVerified = true;
        geoModal.style.display = 'none';
        geoModal.setAttribute('aria-hidden', 'true');
        if (form && !bypassGeoGate) {
            bypassGeoGate = true;
            form.requestSubmit();
        }
    });

    form?.addEventListener('submit', (e) => {
        if (!geoVerified) {
            e.preventDefault();
            openGeoWizard();
            return;
        }

        showProcessingInSamePopup();
        const steps = [...(processingWizard?.querySelectorAll('[data-step]') || [])];
        let idx = 0;
        const activateStep = (index) => {
            steps.forEach((step, i) => {
                step.classList.remove('is-active', 'is-done');
                if (i < index) step.classList.add('is-done');
                if (i === index) step.classList.add('is-active');
            });
        };
        activateStep(idx);
        const timer = setInterval(() => {
            idx = Math.min(idx + 1, steps.length - 1);
            activateStep(idx);
            if (idx === steps.length - 1) clearInterval(timer);
        }, 900);
    });
};
</script>
@endif
@endsection
