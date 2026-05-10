<div class="col-md-6">
    <label class="form-label">Applicant Name</label>
    <input type="text" name="applicant_name" class="form-control @error('applicant_name') is-invalid @enderror" value="{{ old('applicant_name', $application?->applicant_name) }}" required>
    @error('applicant_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">CNIC / Identification Number</label>
    <input type="text" name="identification_number" class="form-control @error('identification_number') is-invalid @enderror" value="{{ old('identification_number', $application?->identification_number) }}">
    @error('identification_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Contact Number</label>
    <input type="text" name="contact_number" class="form-control @error('contact_number') is-invalid @enderror" value="{{ old('contact_number', $application?->contact_number) }}" required>
    @error('contact_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Mobile Number</label>
    <input type="text" name="mobile_number" class="form-control @error('mobile_number') is-invalid @enderror" value="{{ old('mobile_number', $application?->mobile_number) }}">
    @error('mobile_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $application?->email) }}">
    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Application Type</label>
    <input type="text" name="application_type" class="form-control @error('application_type') is-invalid @enderror" value="{{ old('application_type', $application?->application_type ?? 'Building Plan Approval') }}">
    @error('application_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Plot Number</label>
    <input type="text" name="plot_number" class="form-control @error('plot_number') is-invalid @enderror" value="{{ old('plot_number', $application?->plot_number) }}" required>
    @error('plot_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Scheme</label>
    <input type="text" name="scheme" class="form-control @error('scheme') is-invalid @enderror" value="{{ old('scheme', $application?->scheme) }}">
    @error('scheme')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Phase</label>
    <input type="text" name="phase" class="form-control @error('phase') is-invalid @enderror" value="{{ old('phase', $application?->phase) }}">
    @error('phase')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Block</label>
    <input type="text" name="block" class="form-control @error('block') is-invalid @enderror" value="{{ old('block', $application?->block) }}">
    @error('block')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Plot Size Category</label>
    <select name="plot_size_category" class="form-select @error('plot_size_category') is-invalid @enderror" required>
        @foreach ($plotSizeOptions as $key => $label)
            <option value="{{ $key }}" @selected(old('plot_size_category', $application?->plot_size_category) === $key)>{{ $label }}</option>
        @endforeach
    </select>
    @error('plot_size_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-4">
    <label class="form-label">Plot Area (sq ft)</label>
    <input type="number" step="0.01" min="0" name="plot_area_sqft" class="form-control @error('plot_area_sqft') is-invalid @enderror" value="{{ old('plot_area_sqft', $application?->plot_area_sqft) }}">
    @error('plot_area_sqft')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Building Type</label>
    <input type="text" name="building_type" class="form-control @error('building_type') is-invalid @enderror" value="{{ old('building_type', $application?->building_type ?? 'residential') }}">
    @error('building_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Property Type</label>
    <input type="text" name="property_type" class="form-control @error('property_type') is-invalid @enderror" value="{{ old('property_type', $application?->property_type ?? 'Residential House') }}">
    @error('property_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label">Ruleset</label>
    <input type="text" name="ruleset" class="form-control @error('ruleset') is-invalid @enderror" value="{{ old('ruleset', $application?->ruleset ?? 'residential_building_approval') }}">
    @error('ruleset')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-md-6">
    <label class="form-label d-block">Floors Being Submitted</label>
    @php($selectedFloors = old('submitted_floors', $application?->submitted_floors ?? ['ground']))
    @foreach (($floorOptions ?? ['basement','ground','first','second','roof','site','services']) as $floor)
        <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" name="submitted_floors[]" value="{{ $floor }}" id="floor_{{ $floor }}" @checked(in_array($floor, $selectedFloors, true))>
            <label class="form-check-label" for="floor_{{ $floor }}">{{ ucfirst($floor) }}</label>
        </div>
    @endforeach
</div>
<div class="col-md-6">
    <label class="form-label">Is Basement Included?</label>
    <select name="has_basement" class="form-select @error('has_basement') is-invalid @enderror">
        <option value="0" @selected((string) old('has_basement', $application?->has_basement) === '0')>No</option>
        <option value="1" @selected((string) old('has_basement', $application?->has_basement) === '1')>Yes</option>
    </select>
    @error('has_basement')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="col-12">
    <label class="form-label">Remarks / Additional Information</label>
    <textarea name="remarks" rows="3" class="form-control @error('remarks') is-invalid @enderror">{{ old('remarks', $application?->remarks) }}</textarea>
    @error('remarks')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
