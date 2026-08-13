@extends('layouts.app')

@section('title', 'AD ePermit Review')

@section('content')
<h1 class="h4 mb-3">AD ePermit Review: {{ $application->application_no }}</h1>
@if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
@if($errors->has('dfps_push'))<div class="alert alert-danger">{{ $errors->first('dfps_push') }}</div>@endif
@if($errors->has('cad_analysis'))<div class="alert alert-danger">{{ $errors->first('cad_analysis') }}</div>@endif
@if($errors->has('remarks'))<div class="alert alert-danger">{{ $errors->first('remarks') }}</div>@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header">1. Application Summary</div>
            <div class="card-body row g-2">
                <div class="col-md-4"><strong>Application No:</strong> {{ $application->application_no }}</div>
                <div class="col-md-4"><strong>Status:</strong> {{ $application->current_status ?: $application->status }}</div>
                <div class="col-md-4"><strong>Submitted:</strong> {{ optional($application->submitted_at)->format('Y-m-d H:i') ?: '-' }}</div>
                <div class="col-md-4"><strong>AI Status:</strong> {{ $application->ai_status }}</div>
                <div class="col-md-8"><strong>QR:</strong> @if($application->qr_code_path)<a href="{{ $application->qr_code_path }}" target="_blank">Open</a>@else-@endif</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">2. Applicant Information</div>
            <div class="card-body row g-2">
                <div class="col-md-6"><strong>Name:</strong> {{ $application->applicant_name }}</div>
                <div class="col-md-6"><strong>CNIC:</strong> {{ $application->applicant_cnic }}</div>
                <div class="col-md-6"><strong>Phone:</strong> {{ $application->applicant_phone }}</div>
                <div class="col-md-6"><strong>Email:</strong> {{ $application->applicant_email }}</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">3. Plot / Scheme / Block Information</div>
            <div class="card-body row g-2">
                <div class="col-md-4"><strong>Scheme:</strong> {{ $application->scheme_name ?: $application->scheme }}</div>
                <div class="col-md-4"><strong>Block:</strong> {{ $application->block_name ?: $application->block }}</div>
                <div class="col-md-4"><strong>Plot:</strong> {{ $application->plot_no ?: $application->plot_ref }}</div>
                <div class="col-md-4"><strong>Plot Area:</strong> {{ $application->plot_area ?: '-' }}</div>
                <div class="col-md-8"><strong>Address:</strong> {{ $application->plot_address ?: $application->selected_address }}</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">4. AI Map Report</div>
            <div class="card-body">
                <div class="mb-2"><strong>Report file:</strong> @if($application->ai_report_path)<a href="{{ route('public.bp.applications.download-report', $application->id) }}" target="_blank">Download JSON</a>@else - @endif</div>
                @if($legacyApplication)
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.plan.bp.report.show', $legacyApplication) }}" target="_blank">Open Full AI Report</a>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">4.1 Decision Comparison</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold">AI Recommendation</div>
                            <div>{{ data_get($decisionComparison, 'ai.recommendation', 'AI response not available yet') }}</div>
                            <div class="small text-muted mt-2">{{ data_get($decisionComparison, 'ai.reasoning', 'AI response not available yet') }}</div>
                            <div class="small text-muted mt-2">
                                <div><strong>Confidence:</strong> {{ number_format((float) data_get($decisionComparison, 'ai.confidence_score', 0), 2) }}% ({{ strtoupper((string) data_get($decisionComparison, 'ai.confidence_level', 'unknown')) }})</div>
                                <div><strong>DXF Pattern:</strong> {{ data_get($decisionComparison, 'ai.pattern_family', 'generic_dxf') }} ({{ number_format((float) data_get($decisionComparison, 'ai.pattern_strength', 0), 2) }})</div>
                                <div><strong>Dimension source:</strong> {{ data_get($decisionComparison, 'ai.dimension_source', 'unknown') }}</div>
                                <div><strong>Fallback method:</strong> {{ data_get($decisionComparison, 'ai.fallback_method_used', 'unknown') }}</div>
                                <div><strong>Missing layers:</strong> {{ implode(', ', (array) data_get($decisionComparison, 'ai.missing_layers', [])) ?: '-' }}</div>
                                @if(!empty(data_get($decisionComparison, 'ai.warnings', [])))
                                    <div class="mt-2"><strong>Warnings:</strong></div>
                                    <ul class="mb-0">
                                        @foreach((array) data_get($decisionComparison, 'ai.warnings', []) as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold">AD ePermit Recommendation</div>
                            <div>{{ data_get($decisionComparison, 'ad.decision', 'AD decision not submitted yet') }}</div>
                            <div class="small text-muted mt-2">{{ data_get($decisionComparison, 'ad.comments', 'AD comments not submitted yet') }}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold">Comparison Status</div>
                            <div>{{ data_get($decisionComparison, 'comparison.label', 'Decision comparison not available yet') }}</div>
                            <div class="small text-muted mt-2">{{ data_get($decisionComparison, 'comparison.note', '') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">5. CAD File Viewer</div>
            <div class="card-body">
                <div><strong>Original CAD:</strong> {{ basename((string)($application->cad_file_path ?: $application->plan_file_path)) ?: '-' }}</div>
                <div class="small text-muted mb-2">AI analysis status: {{ $application->ai_status }}</div>
                <div class="d-flex flex-wrap gap-2">
                    @if($legacyApplication?->cad_submission_id)
                        <a class="btn btn-outline-primary" href="{{ route('admin.plan.cad-layer-viewer', ['id' => $legacyApplication->cad_submission_id, 'map_drawing_id' => $legacyApplication->map_drawing_id]) }}" target="_blank">View CAD File</a>
                        <a class="btn btn-outline-secondary" href="{{ route('admin.plan.cad-planner-review', ['id' => $legacyApplication->cad_submission_id, 'map_drawing_id' => $legacyApplication->map_drawing_id]) }}" target="_blank">Planner Review</a>
                    @endif
                    <a class="btn btn-outline-secondary" href="{{ route('public.bp.applications.document', [$application->id, $application->documents->first()->id ?? 0]) }}" @if(!$application->documents->first()) style="pointer-events:none;opacity:.5" @endif>Download a document</a>
                    <form method="POST" action="{{ route('admin.plan.bp.ad.generate-cad-analysis', $application) }}" class="d-inline">
                        @csrf
                        <button class="btn btn-success" type="submit">Generate/Refresh CAD Analysis</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">6. Google Satellite Site Review</div>
            <div class="card-body">
                <div class="mb-2"><button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#siteReviewPanel">View Satellite Site</button></div>
                <div class="mb-2">
                    <a id="open_google_satellite"
                       class="btn btn-sm btn-outline-primary"
                       href="https://www.google.com/maps/search/?api=1&query={{ urlencode((string)($application->plot_address ?: $application->selected_address ?: 'Lahore')) }}&basemap=satellite"
                       target="_blank" rel="noopener">
                        Open in Google Maps (Satellite)
                    </a>
                </div>
                <div id="siteReviewPanel" class="collapse show">
                    @php
                        $sitePlaceSearchValue = old(
                            'site_place_search',
                            data_get(optional($application->siteReview)->site_review_json ?? [], 'formatted_address')
                            ?: ($application->plot_address ?: $application->selected_address)
                        );
                    @endphp
                    <div class="row g-2 mb-2">
                        <div class="col-md-8">
                            <input type="text"
                                   class="form-control"
                                   id="site_place_search"
                                   placeholder="Search plot or location with Google Places autocomplete"
                                   value="{{ $sitePlaceSearchValue }}">
                        </div>
                        <div class="col-md-4">
                            <div class="small text-muted pt-2">Use search if signal, current location, or coordinates are unavailable.</div>
                        </div>
                    </div>
                    <div id="adSiteMap"
                         data-plot-address="{{ $application->plot_address ?: $application->selected_address }}"
                         style="height:320px;" class="border rounded mb-2"></div>
                    <form method="POST" action="{{ route('admin.plan.bp.ad.site-review', $application) }}" class="row g-2">
                        @csrf
                        <div class="col-md-3"><input class="form-control" name="latitude" id="site_lat" placeholder="Latitude" value="{{ old('latitude', $application->siteReview->latitude ?? '') }}"></div>
                        <div class="col-md-3"><input class="form-control" name="longitude" id="site_lng" placeholder="Longitude" value="{{ old('longitude', $application->siteReview->longitude ?? '') }}"></div>
                        <div class="col-md-3">
                            <select class="form-select" name="site_condition" id="site_condition" required>
                                @foreach(['vacant','constructed','partially_constructed','unclear'] as $cond)
                                    <option value="{{ $cond }}" @selected(old('site_condition', $application->siteReview->site_condition ?? 'unclear')===$cond)>{{ $cond }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3"><input class="form-control" name="remarks" placeholder="Site remarks" value="{{ old('remarks', $application->siteReview->remarks ?? '') }}"></div>
                        <div class="col-md-3"><label class="form-check"><input type="hidden" name="front_road_detected" value="0"><input class="form-check-input" type="checkbox" name="front_road_detected" value="1" @checked(old('front_road_detected', $application->siteReview->front_road_detected ?? false))> Front road</label></div>
                        <div class="col-md-3"><label class="form-check"><input type="hidden" name="side_road_detected" value="0"><input class="form-check-input" type="checkbox" name="side_road_detected" value="1" @checked(old('side_road_detected', $application->siteReview->side_road_detected ?? false))> Side road</label></div>
                        <div class="col-md-3"><label class="form-check"><input type="hidden" name="corner_plot" value="0"><input class="form-check-input" type="checkbox" name="corner_plot" value="1" @checked(old('corner_plot', $application->siteReview->corner_plot ?? false))> Corner plot</label></div>
                        <div class="col-md-3"></div>
                        <div class="col-12">
                            <textarea name="site_review_json" id="site_review_json" class="form-control" rows="5" required>{{ old('site_review_json', json_encode($application->siteReview->site_review_json ?? [
                                'map_provider' => 'google_maps',
                                'view_type' => 'satellite',
                                'latitude' => $application->siteReview->latitude ?? null,
                                'longitude' => $application->siteReview->longitude ?? null,
                                'place_id' => data_get(optional($application->siteReview)->site_review_json ?? [], 'place_id', ''),
                                'place_name' => data_get(optional($application->siteReview)->site_review_json ?? [], 'place_name', ''),
                                'formatted_address' => data_get(optional($application->siteReview)->site_review_json ?? [], 'formatted_address', ''),
                                'place_types' => data_get(optional($application->siteReview)->site_review_json ?? [], 'place_types', []),
                                'plot_polygon' => [],
                                'road_sides' => [],
                                'site_condition' => $application->siteReview->site_condition ?? 'unclear',
                                'front_road_detected' => (bool)($application->siteReview->front_road_detected ?? false),
                                'side_road_detected' => (bool)($application->siteReview->side_road_detected ?? false),
                                'corner_plot' => (bool)($application->siteReview->corner_plot ?? false),
                                'remarks' => $application->siteReview->remarks ?? '',
                                'marked_by' => '',
                                'marked_at' => '',
                            ], JSON_PRETTY_PRINT)) }}</textarea>
                        </div>
                        <div class="col-12"><button class="btn btn-primary" type="submit">Save Site Marking JSON</button></div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">7. Attachments</div>
            <div class="card-body">
                <ul class="mb-0">
                    @forelse($application->documents as $doc)
                        <li>{{ $doc->attachment_type ?: $doc->document_type }} - {{ $doc->original_name ?: basename($doc->file_path) }}
                            (<a href="{{ route('public.bp.applications.document', [$application->id, $doc->id]) }}" target="_blank">download</a>)
                        </li>
                    @empty
                        <li class="text-muted">No attachments found.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">8. Decision Panel</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.plan.bp.ad.update', $application) }}" class="row g-2">
                    @csrf
                    <div class="col-md-4">
                        <select name="action" class="form-select" required>
                            <option value="under_review">Mark Under Review</option>
                            <option value="observation">Mark Observation</option>
                            <option value="reject">Reject Case</option>
                            <option value="approve">Approve Case</option>
                        </select>
                    </div>
                    <div class="col-md-8"><input class="form-control" type="text" name="remarks" placeholder="Remarks (required for reject/observation)"></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Save Decision</button></div>
                </form>
                <hr class="my-4">
                <form method="POST" action="{{ route('admin.plan.bp.ad.push-dfps', $application) }}" class="row g-2" onsubmit="return confirm('Push this case to DFPS/internal system now?');">
                    @csrf
                    <div class="col-12">
                        <label class="form-label fw-semibold">Push Remarks</label>
                        <textarea class="form-control" name="remarks" rows="3" required placeholder="Enter the remarks that must be pushed to DFPS">{{ old('remarks', $application->ad_epermit_remarks ?? '') }}</textarea>
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success" type="submit">Push To DFPS/Internal</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">9. DFPS Push Status</div>
            <div class="card-body">
                @php $push = $application->dfpsPushLogs->first(); @endphp
                @if($push)
                    <div><strong>Latest:</strong> <span class="badge {{ $push->success ? 'text-bg-success' : 'text-bg-danger' }}">{{ $push->success ? 'Success' : 'Failed' }}</span></div>
                    <div class="small text-muted">{{ $push->created_at }}</div>
                    @if($push->error_message)<div class="text-danger small">{{ $push->error_message }}</div>@endif
                @else
                    <div class="text-muted">No DFPS push attempt yet.</div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">10. Status History / Noting Timeline</div>
            <div class="card-body">
                <ul class="mb-0">
                    @forelse($application->statusLogs as $log)
                        <li>
                            <strong>{{ $log->new_status }}</strong>
                            <span class="text-muted small">({{ $log->created_at }})</span>
                            <div class="small">from {{ $log->old_status ?: '-' }} by {{ $log->action_by_role ?: '-' }}</div>
                            @if($log->remarks)<div>{{ $log->remarks }}</div>@endif
                        </li>
                    @empty
                        <li class="text-muted">No status log entries yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        @if($application)
            @include('admin.building-plan.partials-applicant-chat', ['application' => $application])
        @endif
    </div>
    <div class="col-lg-4">
        @if($legacyApplication)
            @include('admin.building-plan.partials-chatbot', ['application' => $legacyApplication])
        @else
            <div class="alert alert-warning">Legacy AI chat is unavailable because no legacy application link was found.</div>
        @endif
    </div>
</div>
@endsection

@push('footer_scripts_inline')
<script>
(function() {
    const openBtn = document.querySelector('[data-bs-target="#siteReviewPanel"]');
    const mapEl = document.getElementById('adSiteMap');
    if (!mapEl) return;
    const latInput = document.getElementById('site_lat');
    const lngInput = document.getElementById('site_lng');
    const placeSearchInput = document.getElementById('site_place_search');
    const reviewJsonEl = document.getElementById('site_review_json');
    const openSatelliteLink = document.getElementById('open_google_satellite');
    const address = (mapEl.getAttribute('data-plot-address') || '').trim();
    const fallback = { lat: 31.5204, lng: 74.3587 };
    let mapRef = null;
    let markerRef = null;
    let pendingInit = false;
    let placesAutocomplete = null;

    function patchJson(extra = {}) {
        if (!reviewJsonEl) return;
        try {
            const json = JSON.parse(reviewJsonEl.value || '{}');
            Object.assign(json, extra);
            reviewJsonEl.value = JSON.stringify(json, null, 2);
        } catch (e) {}
    }

    function setJsonLatLng(lat, lng) {
        patchJson({ latitude: lat, longitude: lng });
    }

    function setJsonPlace(place) {
        if (!place) return;
        patchJson({
            place_id: place.place_id || '',
            place_name: place.name || '',
            formatted_address: place.formatted_address || '',
            place_types: Array.isArray(place.types) ? place.types : [],
            search_source: 'google_places',
        });
    }

    function refreshSatelliteLink(lat, lng) {
        if (!openSatelliteLink) return;
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            openSatelliteLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(lat + ',' + lng)}&basemap=satellite`;
            return;
        }
        if (address !== '') {
            openSatelliteLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}&basemap=satellite`;
        }
    }

    function getSeedCoords() {
        const lat = parseFloat(latInput?.value || '');
        const lng = parseFloat(lngInput?.value || '');
        if (Number.isFinite(lat) && Number.isFinite(lng)) return { lat, lng };
        return null;
    }

    function attachMarker(map, pos) {
        if (markerRef) markerRef.setMap(null);
        markerRef = new google.maps.Marker({ map, position: pos, draggable: true });
        markerRef.addListener('dragend', function(evt) {
            const p = evt.latLng;
            if (!p) return;
            const la = p.lat();
            const ln = p.lng();
            latInput.value = la.toFixed(7);
            lngInput.value = ln.toFixed(7);
            setJsonLatLng(la, ln);
            refreshSatelliteLink(la, ln);
        });
    }

    function applyLocation(pos, place = null) {
        if (!mapRef) return;
        mapRef.setCenter(pos);
        mapRef.setZoom(18);
        attachMarker(mapRef, pos);
        latInput.value = pos.lat.toFixed(7);
        lngInput.value = pos.lng.toFixed(7);
        setJsonLatLng(pos.lat, pos.lng);
        if (place) {
            setJsonPlace(place);
            if (placeSearchInput) {
                placeSearchInput.value = place.formatted_address || place.name || placeSearchInput.value;
            }
        }
        refreshSatelliteLink(pos.lat, pos.lng);
    }

    function initMapAt(pos) {
        if (!(window.google && window.google.maps)) return;
        mapRef = new google.maps.Map(mapEl, {
            center: pos,
            zoom: 18,
            mapTypeId: 'satellite',
            streetViewControl: false,
        });
        attachMarker(mapRef, pos);
        latInput.value = pos.lat.toFixed(7);
        lngInput.value = pos.lng.toFixed(7);
        setJsonLatLng(pos.lat, pos.lng);
        refreshSatelliteLink(pos.lat, pos.lng);
        if (placeSearchInput && window.google.maps.places && !placesAutocomplete) {
            placesAutocomplete = new google.maps.places.Autocomplete(placeSearchInput, {
                fields: ['place_id', 'name', 'formatted_address', 'geometry', 'types'],
            });
            placesAutocomplete.addListener('place_changed', function() {
                const place = placesAutocomplete.getPlace();
                if (!place || !place.geometry || !place.geometry.location) {
                    return;
                }
                const location = place.geometry.location;
                applyLocation({ lat: location.lat(), lng: location.lng() }, place);
            });
        }
    }

    function init() {
        if (!(window.google && window.google.maps)) return;
        const seeded = getSeedCoords();
        if (seeded) {
            initMapAt(seeded);
            return;
        }

        if (address !== '') {
            const geocoder = new google.maps.Geocoder();
            geocoder.geocode({ address }, function(results, status) {
                if (status === 'OK' && results && results[0] && results[0].geometry && results[0].geometry.location) {
                    const loc = results[0].geometry.location;
                    initMapAt({ lat: loc.lat(), lng: loc.lng() });
                    if (placeSearchInput && results[0]) {
                        placeSearchInput.value = results[0].formatted_address || address;
                        setJsonPlace({
                            place_id: results[0].place_id || '',
                            name: results[0].formatted_address || address,
                            formatted_address: results[0].formatted_address || address,
                            types: results[0].types || [],
                        });
                    }
                    return;
                }
                initMapAt(fallback);
            });
            return;
        }

        initMapAt(fallback);
    }

    window.initAdSiteMapAPI = function() {
        if (pendingInit || !openBtn) {
            init();
            pendingInit = false;
        }
    };

    if (openBtn) {
        openBtn.addEventListener('click', function() {
            if (window.google && window.google.maps) {
                setTimeout(init, 80);
            } else {
                pendingInit = true;
            }
        });
    } else {
        init();
    }

    const seeded = getSeedCoords();
    refreshSatelliteLink(seeded?.lat, seeded?.lng);
})();
</script>
@php $mapsApiKey = (string) config('services.google.maps_api_key'); @endphp
@if($mapsApiKey !== '')
<script async defer src="https://maps.googleapis.com/maps/api/js?key={{ urlencode($mapsApiKey) }}&libraries=places,drawing&callback=initAdSiteMapAPI"></script>
@endif
@endpush
