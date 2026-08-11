@extends('public.building-plan.layout')
@section('title', 'Submit New Building Plan')

@section('head')
<style>
    .wiz-shell{border:1px solid #d9e2ec;border-radius:16px;background:#fff;box-shadow:0 14px 30px rgba(8,33,74,.08)}
    .wiz-side{background:linear-gradient(180deg,#0b2f66,#0a2a58);color:#fff;border-radius:16px 0 0 16px;padding:1.25rem}
    .wiz-step{display:flex;gap:.75rem;align-items:flex-start;padding:.55rem .45rem;border-radius:10px;opacity:.75;cursor:pointer}
    .wiz-step.active{background:rgba(255,255,255,.14);opacity:1}
    .wiz-step .dot{width:26px;height:26px;border-radius:999px;border:2px solid #b7cae7;display:grid;place-items:center;font-size:.8rem;font-weight:700}
    .wiz-step.active .dot{border-color:#f0c55d;background:#f0c55d;color:#12223e}
    .wiz-step h6{margin:0;font-size:.95rem}
    .wiz-step p{margin:0;font-size:.8rem;color:#d2e0f4}
    .wiz-main{padding:1.2rem}
    .wiz-progress{height:10px;background:#edf2f8;border-radius:999px;overflow:hidden}
    .wiz-progress-bar{height:100%;background:linear-gradient(90deg,#0f8f88,#0c7f79);transition:width .25s ease}
    .step-pane{display:none}
    .step-pane.active{display:block}
    .drop-box{border:1px dashed #98b5d9;border-radius:12px;padding:.85rem;background:#f8fbff}
    .helper{font-size:.85rem;color:#66758b}
    .review-item{border:1px solid #dbe6f3;border-radius:10px;padding:.65rem .8rem;background:#fbfdff}
</style>
@endsection

@section('content')
<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold">New Building Plan Submission</h4>
        <div class="text-secondary">Start with your DWG/CAD upload for faster auto-fill.</div>
    </div>
    <a href="{{ route('public.bp.dashboard') }}" class="btn btn-outline-secondary btn-sm">Back to Dashboard</a>
</div>

<form method="POST" action="{{ route('public.bp.applications.store') }}" enctype="multipart/form-data" id="wizardForm">@csrf
    <div class="wiz-shell row g-0">
        <div class="col-lg-3 wiz-side">
            <h6 class="mb-3 fw-bold">Submission Wizard</h6>
            <div class="d-grid gap-2" id="stepNav">
                <div class="wiz-step active" data-step-target="1"><span class="dot">1</span><div><h6>Plan Upload</h6><p>DWG / DXF / CAD / PDF</p></div></div>
                <div class="wiz-step" data-step-target="2"><span class="dot">2</span><div><h6>Property Info</h6><p>Auto-filled from file name</p></div></div>
                <div class="wiz-step" data-step-target="3"><span class="dot">3</span><div><h6>Applicant Info</h6><p>Name, CNIC, contact</p></div></div>
                <div class="wiz-step" data-step-target="4"><span class="dot">4</span><div><h6>Documents</h6><p>CNIC and ownership files</p></div></div>
                <div class="wiz-step" data-step-target="5"><span class="dot">5</span><div><h6>AI Scrutiny</h6><p>Review and submit</p></div></div>
            </div>
        </div>

        <div class="col-lg-9 wiz-main">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="fw-semibold" id="stepTitle">Step 1 of 5 - Plan Upload</div>
                <div class="small text-secondary" id="stepCount">20%</div>
            </div>
            <div class="wiz-progress mb-3"><div class="wiz-progress-bar" id="wizBar" style="width:20%"></div></div>

            <div class="step-pane active" data-step="1">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Plan File (DWG / DXF / CAD / PDF)</label>
                        <input type="file" name="plan_file" id="planFile" class="form-control" accept=".dwg,.dxf,.cad,.pdf" required>
                        <div class="helper mt-1">Maximum size 50MB. DWG file name helps auto-fill plot, block and scheme.</div>
                        <div class="small text-muted mt-1" id="planMeta"></div>
                    </div>
                    <div class="col-md-4">
                        <div class="alert alert-info py-2 mb-0">After upload, Step 2 will be prefilled where possible from plan file name.</div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-warning py-2 mb-0">AI scrutiny is advisory. Final approval remains subject to authority review.</div>
                    </div>
                </div>
            </div>

            <div class="step-pane" data-step="2">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Scheme</label><input name="scheme" id="schemeField" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Phase</label><input name="phase" id="phaseField" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Block</label><input name="block" id="blockField" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Plot Number / Reference</label><input name="plot_ref" id="plotRefField" class="form-control" required></div>
                    <div class="col-12"><label class="form-label fw-semibold">Address</label><input name="selected_address" id="addressField" class="form-control" required></div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Property Signal</label>
                        <select name="property_signal" class="form-select">
                            @foreach($geoStatusOptions as $opt)<option>{{ $opt }}</option>@endforeach
                        </select>
                        <div class="helper">Use geo verification result status.</div>
                    </div>
                    <div class="col-md-6 d-flex align-items-end"><button type="button" class="btn btn-outline-primary w-100" onclick="openGeoVerificationPanel()">Verify Property Signal</button></div>
                </div>
            </div>

            <div class="step-pane" data-step="3">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold">Full Name</label><input name="applicant_name" class="form-control" value="{{ old('applicant_name', $applicant->name) }}" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">CNIC</label><input name="applicant_cnic" class="form-control" value="{{ old('applicant_cnic', $applicant->cnic) }}" readonly required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Mobile</label><input name="applicant_phone" class="form-control" value="{{ old('applicant_phone', $applicant->mobile) }}" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="applicant_email" class="form-control" value="{{ old('applicant_email', $applicant->email) }}" required></div>
                </div>
            </div>

            <div class="step-pane" data-step="4">
                <div class="row g-3">
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">CNIC Front Image</label><input type="file" name="cnic_front" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf" required><div class="helper mt-1">JPG/PNG/PDF up to 5MB.</div><div class="preview small text-muted mt-1"></div></div></div>
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">CNIC Back Image</label><input type="file" name="cnic_back" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf" required><div class="helper mt-1">JPG/PNG/PDF up to 5MB.</div><div class="preview small text-muted mt-1"></div></div></div>
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">Ownership Document</label><input type="file" name="ownership_document" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf" required><div class="helper mt-1">JPG/PNG/PDF up to 10MB.</div><div class="preview small text-muted mt-1"></div></div></div>
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">List Document (optional)</label><input type="file" name="list_document" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf"><div class="helper mt-1">Optional.</div><div class="preview small text-muted mt-1"></div></div></div>
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">Affidavit (optional)</label><input type="file" name="affidavit" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf"><div class="helper mt-1">Optional.</div><div class="preview small text-muted mt-1"></div></div></div>
                    <div class="col-md-6"><div class="drop-box"><label class="form-label fw-semibold">Supporting Documents</label><input type="file" name="supporting_documents[]" class="form-control doc-file" accept=".jpg,.jpeg,.png,.pdf" multiple><div class="helper mt-1">Any extra supporting files.</div><div class="preview small text-muted mt-1"></div></div></div>
                </div>
            </div>

            <div class="step-pane" data-step="5">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="review-item"><strong>Plan File:</strong> <span id="reviewPlan">-</span></div>
                        <div class="review-item mt-2"><strong>Applicant:</strong> <span data-review="applicant_name">-</span></div>
                        <div class="review-item mt-2"><strong>CNIC:</strong> <span data-review="applicant_cnic">-</span></div>
                        <div class="review-item mt-2"><strong>Contact:</strong> <span data-review="applicant_phone">-</span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="review-item"><strong>Plot:</strong> <span data-review="plot_ref">-</span></div>
                        <div class="review-item mt-2"><strong>Scheme/Phase/Block:</strong> <span data-review="spb">-</span></div>
                        <div class="review-item mt-2"><strong>Property Signal:</strong> <span data-review="property_signal">-</span></div>
                    </div>
                </div>

                <div class="mt-3" id="progressBox" style="display:none;">
                    <div class="small mb-2" id="progressText">Starting...</div>
                    <div class="progress"><div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <button type="button" class="btn btn-outline-secondary" id="prevBtn" disabled>Back</button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" id="nextBtn">Next</button>
                    <button class="btn btn-success" id="submitBtn" type="submit" style="display:none;">Generate AI Scrutiny Report</button>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="precheckModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Plan Verification & Pre-Scrutiny</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="small text-secondary mb-2" id="precheckText">Preparing verification...</div>
                <div class="progress mb-3">
                    <div id="precheckBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <div class="border rounded p-2 bg-light">
                    <div><strong>Status:</strong> <span id="precheckStatus">Pending</span></div>
                    <div><strong>AI Confidence:</strong> <span id="precheckConfidence">0%</span></div>
                    <div><strong>Confidence Source:</strong> <span id="precheckSource">-</span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="precheckContinueBtn" disabled>Proceed to Next Step</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="geoVerifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Geo Verification Panel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 small text-secondary">Address being verified:</div>
                <div class="fw-semibold mb-3" id="geoVerifyAddress">-</div>
                <div class="ratio ratio-16x9 border rounded mb-3">
                    <iframe id="geoMapFrame" src="about:blank" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
                <div class="small text-secondary mb-2" id="geoVerifyText">Initializing verification...</div>
                <div class="progress">
                    <div id="geoVerifyBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <div class="mt-3 border rounded p-2 bg-light">
                    <strong>Result:</strong> <span id="geoVerifyResult">Pending</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="geoApplyBtn" disabled>Use Verified Signal</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
const totalSteps = 5;
let currentStep = 1;
const titles = {
  1:'Step 1 of 5 - Plan Upload',
  2:'Step 2 of 5 - Property Information',
  3:'Step 3 of 5 - Applicant Information',
  4:'Step 4 of 5 - Document Upload',
  5:'Step 5 of 5 - AI Scrutiny & Submit'
};

const form = document.getElementById('wizardForm');
const panes = [...document.querySelectorAll('.step-pane')];
const navSteps = [...document.querySelectorAll('.wiz-step')];
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const submitBtn = document.getElementById('submitBtn');
const bar = document.getElementById('wizBar');
const stepTitle = document.getElementById('stepTitle');
const stepCount = document.getElementById('stepCount');
const planFileInput = document.getElementById('planFile');
const planMeta = document.getElementById('planMeta');
const precheckEndpoint = @json(route('public.bp.applications.precheck'));
const precheckModalEl = document.getElementById('precheckModal');
const geoVerifyModalEl = document.getElementById('geoVerifyModal');
function createModalController(el){
  if (!el) return null;
  if (window.bootstrap?.Modal) {
    return bootstrap.Modal.getOrCreateInstance(el);
  }
  return {
    show() {
      el.classList.add('show');
      el.style.display = 'block';
      el.removeAttribute('aria-hidden');
      el.setAttribute('aria-modal', 'true');
      document.body.classList.add('modal-open');
      if (!document.querySelector('.modal-backdrop[data-fallback-modal="true"]')) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.dataset.fallbackModal = 'true';
        document.body.appendChild(backdrop);
      }
    },
    hide() {
      el.classList.remove('show');
      el.style.display = 'none';
      el.setAttribute('aria-hidden', 'true');
      el.removeAttribute('aria-modal');
      const backdrop = document.querySelector('.modal-backdrop[data-fallback-modal="true"]');
      if (backdrop) backdrop.remove();
      if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
      }
    }
  };
}
const precheckModal = createModalController(precheckModalEl);
const precheckText = document.getElementById('precheckText');
const precheckBar = document.getElementById('precheckBar');
const precheckStatus = document.getElementById('precheckStatus');
const precheckConfidence = document.getElementById('precheckConfidence');
const precheckSource = document.getElementById('precheckSource');
const precheckContinueBtn = document.getElementById('precheckContinueBtn');
const geoVerifyModal = createModalController(geoVerifyModalEl);
const geoVerifyAddress = document.getElementById('geoVerifyAddress');
const geoMapFrame = document.getElementById('geoMapFrame');
const geoVerifyText = document.getElementById('geoVerifyText');
document.querySelectorAll('[data-bs-dismiss="modal"]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const modalEl = btn.closest('.modal');
    if (modalEl && !window.bootstrap?.Modal) {
      createModalController(modalEl)?.hide();
    }
  });
});
const geoVerifyBar = document.getElementById('geoVerifyBar');
const geoVerifyResult = document.getElementById('geoVerifyResult');
const geoApplyBtn = document.getElementById('geoApplyBtn');
let step1PrecheckPassed = false;
let geoSignalResult = 'Not evaluated';
let precheckLoadingTimer = null;
let precheckLoadingValue = 0;

function renderStep(){
  panes.forEach(p => p.classList.toggle('active', Number(p.dataset.step) === currentStep));
  navSteps.forEach(s => s.classList.toggle('active', Number(s.dataset.stepTarget) === currentStep));
  const percent = Math.round((currentStep / totalSteps) * 100);
  bar.style.width = percent + '%';
  stepCount.textContent = percent + '%';
  stepTitle.textContent = titles[currentStep];
  prevBtn.disabled = currentStep === 1;
  nextBtn.style.display = currentStep === totalSteps ? 'none' : 'inline-block';
  submitBtn.style.display = currentStep === totalSteps ? 'inline-block' : 'none';

  if(currentStep === 5){
    const get = (name) => form.querySelector(`[name="${name}"]`)?.value || '-';
    document.querySelector('[data-review="applicant_name"]').textContent = get('applicant_name');
    document.querySelector('[data-review="applicant_cnic"]').textContent = get('applicant_cnic');
    document.querySelector('[data-review="applicant_phone"]').textContent = get('applicant_phone');
    document.querySelector('[data-review="plot_ref"]').textContent = get('plot_ref');
    document.querySelector('[data-review="spb"]').textContent = `${get('scheme')} / ${get('phase')} / ${get('block')}`;
    document.querySelector('[data-review="property_signal"]').textContent = get('property_signal');
    document.getElementById('reviewPlan').textContent = planMeta.textContent || '-';
  }
}

function validateCurrentStep(){
  const pane = panes.find(p => Number(p.dataset.step) === currentStep);
  if(!pane) return true;
  const fields = pane.querySelectorAll('input, select, textarea');
  for(const field of fields){
    if(field.hasAttribute('required') && !field.checkValidity()){
      field.reportValidity();
      return false;
    }
  }
  return true;
}

function fillIfEmpty(name, value){
  if(!value) return;
  const el = form.querySelector(`[name="${name}"]`);
  if(!el) return;
  if(String(el.value || '').trim() === '') {
    el.value = value;
  }
}

function autofillFromFilename(fileName){
  const plain = fileName.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
  if(!plain) return;

  let plotRef = '';
  let block = '';
  let scheme = '';

  const m1 = plain.match(/^(\d+[A-Za-z0-9\/-]*)\s+([A-Za-z])\s+(.+)$/i);
  if(m1){
    plotRef = m1[1].trim();
    block = m1[2].toUpperCase();
    scheme = m1[3].trim();
  }

  const mPlot = plain.match(/\bplot\s*(?:no\.?|number)?\s*([A-Za-z0-9\/-]+)/i);
  if(!plotRef && mPlot) plotRef = mPlot[1].trim();

  const mBlock = plain.match(/\bblock\s*([A-Za-z0-9\/-]+)/i);
  if(!block && mBlock) block = mBlock[1].trim().toUpperCase();

  const mPhase = plain.match(/\bphase\s*([A-Za-z0-9\/-]+)/i);
  if(mPhase) fillIfEmpty('phase', mPhase[1].trim());

  const mScheme = plain.match(/\b(society|town|city|scheme)\b/i);
  if(!scheme && mScheme){
    scheme = plain;
  }

  fillIfEmpty('plot_ref', plotRef);
  fillIfEmpty('block', block);
  fillIfEmpty('scheme', scheme);

  const schemeVal = form.querySelector('[name="scheme"]')?.value || scheme;
  const blockVal = form.querySelector('[name="block"]')?.value || block;
  const plotVal = form.querySelector('[name="plot_ref"]')?.value || plotRef;
  const composed = [plotVal ? `Plot ${plotVal}` : '', blockVal ? `Block ${blockVal}` : '', schemeVal, 'Lahore, Pakistan'].filter(Boolean).join(', ');
  fillIfEmpty('selected_address', composed);
}

function openGeoVerificationPanel(){
  const addressField = document.getElementById('addressField');
  const signalField = form.querySelector('[name="property_signal"]');
  const address = (addressField?.value || '').trim();
  if(!address){
    alert('Please enter address first.');
    addressField?.focus();
    return;
  }

  geoSignalResult = 'Not evaluated';
  geoApplyBtn.disabled = true;
  geoVerifyResult.textContent = 'Pending';
  geoVerifyResult.className = '';
  geoVerifyBar.style.width = '0%';
  geoVerifyText.textContent = 'Initializing verification...';
  geoVerifyAddress.textContent = address;
  geoMapFrame.src = `https://maps.google.com/maps?q=${encodeURIComponent(address)}&z=17&output=embed`;
  geoVerifyModal.show();

  const stages = ['Locating address', 'Checking locality context', 'Cross-checking plot signal', 'Finalizing verification'];
  let i = 0;
  const timer = setInterval(() => {
    if(i >= stages.length){
      clearInterval(timer);
      const scheme = (document.getElementById('schemeField')?.value || '').trim().toLowerCase();
      const block = (document.getElementById('blockField')?.value || '').trim().toLowerCase();
      const plot = (document.getElementById('plotRefField')?.value || '').trim().toLowerCase();
      const hay = address.toLowerCase();
      const hasMatch = (!scheme || hay.includes(scheme)) && (!block || hay.includes(block)) && (!plot || hay.includes(plot));
      geoSignalResult = hasMatch ? 'Matched' : 'Needs manual verification';
      geoVerifyResult.textContent = geoSignalResult;
      geoVerifyResult.className = hasMatch ? 'text-success fw-semibold' : 'text-warning fw-semibold';
      geoVerifyText.textContent = hasMatch ? 'Geo verification completed with matched locality signal.' : 'Geo signal needs manual verification. You can still continue.';
      geoApplyBtn.disabled = false;
      return;
    }
    geoVerifyText.textContent = stages[i];
    geoVerifyBar.style.width = `${Math.round(((i + 1) / stages.length) * 100)}%`;
    i++;
  }, 550);

  geoApplyBtn.onclick = () => {
    if(signalField){
      signalField.value = geoSignalResult;
    }
    geoVerifyModal.hide();
  };
}

function startPrecheckLoading(){
  clearInterval(precheckLoadingTimer);
  precheckLoadingValue = 12;
  precheckBar.style.width = `${precheckLoadingValue}%`;
  precheckText.textContent = 'Uploading plan for verification...';

  const messages = [
    'Uploading plan for verification...',
    'Reading CAD metadata...',
    'Running AI pre-scrutiny...',
    'Evaluating confidence and text mappings...'
  ];
  let messageIndex = 0;

  precheckLoadingTimer = setInterval(() => {
    if (precheckLoadingValue < 88) {
      precheckLoadingValue += precheckLoadingValue < 50 ? 9 : 4;
      if (precheckLoadingValue > 88) precheckLoadingValue = 88;
      precheckBar.style.width = `${precheckLoadingValue}%`;
    }
    if (messageIndex < messages.length - 1) {
      messageIndex += 1;
      precheckText.textContent = messages[messageIndex];
    }
  }, 450);
}

function stopPrecheckLoading(finalMessage, finalPercent = 100){
  if (precheckLoadingTimer) {
    clearInterval(precheckLoadingTimer);
    precheckLoadingTimer = null;
  }
  precheckLoadingValue = finalPercent;
  precheckBar.style.width = `${finalPercent}%`;
  if (finalMessage) {
    precheckText.textContent = finalMessage;
  }
}

function buildApprovalTimelineMessage(confidence, isClear){
  if (!isClear) {
    return 'Confidence is below 80%. It may take more than 1 week due to manual review.';
  }
  return confidence >= 80
    ? 'Confidence is 80% or above. You should get approval within 1 week, subject to authority review.'
    : 'Confidence is below 80%. It may take more than 1 week due to manual review.';
}

nextBtn.addEventListener('click', () => {
  if(!validateCurrentStep()) return;
  if(currentStep === 1){
    runStep1Precheck();
    return;
  }
  currentStep = Math.min(totalSteps, currentStep + 1);
  renderStep();
});

prevBtn.addEventListener('click', () => {
  currentStep = Math.max(1, currentStep - 1);
  renderStep();
});

navSteps.forEach(s => {
  s.addEventListener('click', () => {
    const target = Number(s.dataset.stepTarget);
    if(target <= currentStep){
      currentStep = target;
      renderStep();
      return;
    }
    if(validateCurrentStep()){
      currentStep = target;
      renderStep();
    }
  });
});

const docInputs = document.querySelectorAll('.doc-file');
docInputs.forEach(input => {
  input.addEventListener('change', () => {
    const box = input.closest('.drop-box')?.querySelector('.preview') || input.parentElement.querySelector('.preview');
    if (!box) return;
    if (!input.files || !input.files.length) { box.textContent = ''; return; }
    const names = Array.from(input.files).map(f => `${f.name} (${(f.size/1024/1024).toFixed(2)} MB)`);
    box.textContent = names.join(', ');
  });
});

planFileInput.addEventListener('change', (e) => {
  const f = e.target.files?.[0];
  planMeta.textContent = f ? `${f.name} (${(f.size/1024/1024).toFixed(2)} MB)` : '';
  step1PrecheckPassed = false;
  if(f){
    autofillFromFilename(f.name);
  }
});

function runStep1Precheck(){
  const file = planFileInput.files?.[0];
  if(!file){
    alert('Please upload a plan file first.');
    return;
  }

  precheckContinueBtn.disabled = true;
  precheckStatus.textContent = 'Pending';
  precheckConfidence.textContent = '0%';
  precheckSource.textContent = '-';
  precheckText.textContent = 'Preparing verification...';
  precheckBar.style.width = '0%';
  precheckContinueBtn.textContent = 'Proceed to Next Step';
  precheckContinueBtn.disabled = true;
  precheckModal.show();
  startPrecheckLoading();
  void finalizeStep1Precheck(file);
}

async function finalizeStep1Precheck(file){
  const fallback = computeLocalPrecheck(file);
  try {
    const formData = new FormData();
    formData.append('plan_file', file);

    const response = await fetch(precheckEndpoint, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        'Accept': 'application/json',
      },
      body: formData,
    });

    const payload = await response.json().catch(() => null);
    const responseConfidence = selectPreviewConfidence(payload, fallback.confidence);
    const confidence = Number(responseConfidence.score);
    const status = String(payload?.recommendation || (confidence >= 80 ? 'Clear' : 'Needs Review'));
    const isClear = response.ok && confidence >= 80 && !String(payload?.status || '').toLowerCase().includes('error');
    const canProceed = response.ok && !String(payload?.status || '').toLowerCase().includes('error');
    const source = responseConfidence.source || payload?.confidence_source || 'unknown';

    stopPrecheckLoading(buildApprovalTimelineMessage(confidence, isClear));

    precheckStatus.textContent = status === 'Needs Expert Review' ? 'Needs Review' : (isClear ? 'Clear' : 'Needs Review');
    precheckStatus.className = isClear ? 'text-success fw-semibold' : 'text-danger fw-semibold';
    precheckConfidence.textContent = `${confidence.toFixed(2)}%`;
    precheckSource.textContent = source;
    precheckContinueBtn.disabled = !canProceed;
    precheckContinueBtn.textContent = isClear ? 'Proceed to Next Step' : 'Proceed Anyway';
    precheckContinueBtn.classList.remove('btn-primary', 'btn-warning', 'btn-outline-warning');
    precheckContinueBtn.classList.add(isClear ? 'btn-primary' : 'btn-warning');
    step1PrecheckPassed = canProceed;

    if (Array.isArray(payload?.warnings) && payload.warnings.length) {
      console.warn('Precheck warnings:', payload.warnings);
    }
    return;
  } catch (error) {
    console.warn('Server precheck failed, falling back to local heuristic:', error);
  }

  stopPrecheckLoading(
    fallback.isClear
      ? 'Verification clear. You can proceed to next step.'
      : 'Verification did not pass threshold. Manual review is recommended.'
  );

  const confidence = fallback.confidence;
  const isClear = fallback.isClear;
  const canProceed = true;
  const timelineMessage = buildApprovalTimelineMessage(confidence, isClear);
  precheckStatus.textContent = isClear ? 'Clear' : 'Needs Review';
  precheckStatus.className = isClear ? 'text-success fw-semibold' : 'text-danger fw-semibold';
  precheckConfidence.textContent = confidence + '%';
  precheckSource.textContent = 'local_heuristic';
  precheckContinueBtn.disabled = !canProceed;
  precheckContinueBtn.textContent = isClear ? 'Proceed to Next Step' : 'Proceed Anyway';
  precheckContinueBtn.classList.remove('btn-primary', 'btn-warning', 'btn-outline-warning');
  precheckContinueBtn.classList.add(isClear ? 'btn-primary' : 'btn-warning');
  step1PrecheckPassed = canProceed;
  stopPrecheckLoading(timelineMessage);
}

function selectPreviewConfidence(payload, fallbackConfidence){
  const candidates = [
    ['cad_confidence_assessment.final_confidence_score', payload?.cad_confidence_assessment?.final_confidence_score],
    ['cad_confidence_assessment.confidence_score', payload?.cad_confidence_assessment?.confidence_score],
    ['analysis_json.cad_confidence_assessment.final_confidence_score', payload?.analysis_json?.cad_confidence_assessment?.final_confidence_score],
    ['analysis_json.cad_confidence_assessment.confidence_score', payload?.analysis_json?.cad_confidence_assessment?.confidence_score],
    ['analysis_confidence_score', payload?.analysis_confidence_score],
    ['analysis.confidence_score', payload?.confidence_score],
  ];

  for (const [source, value] of candidates) {
    const num = Number(value);
    if (Number.isFinite(num)) {
      return { score: num, source };
    }
  }

  return { score: Number(fallbackConfidence) || 0, source: 'local_heuristic' };
}

function computeLocalPrecheck(file){
  const ext = file.name.split('.').pop().toLowerCase();
  const sizeMb = file.size / (1024 * 1024);
  const validExt = ['dwg','dxf','cad','pdf'].includes(ext);
  let confidence = validExt ? 84 : 40;
  if (sizeMb <= 20) confidence += 8;
  if (sizeMb > 45) confidence -= 10;
  if (ext === 'dwg' || ext === 'dxf') confidence += 4;
  confidence = Math.max(0, Math.min(99, Math.round(confidence)));
  return {
    confidence,
    isClear: validExt && confidence >= 80,
  };
}

precheckContinueBtn.addEventListener('click', () => {
  if (!step1PrecheckPassed) return;
  precheckModal.hide();
  currentStep = 2;
  renderStep();
});

form.addEventListener('submit', function (e) {
  const allowedDoc = ['jpg','jpeg','png','pdf'];
  const allowedPlan = ['dwg','dxf','cad','pdf'];

  for (const input of document.querySelectorAll('.doc-file')) {
    for (const file of (input.files || [])) {
      const ext = file.name.split('.').pop().toLowerCase();
      if (!allowedDoc.includes(ext)) {
        e.preventDefault();
        alert('Invalid document type: ' + file.name);
        return;
      }
    }
  }

  const planFile = planFileInput.files[0];
  if (planFile) {
    const ext = planFile.name.split('.').pop().toLowerCase();
    if (!allowedPlan.includes(ext)) {
      e.preventDefault();
      alert('Plan file must be DWG, DXF, CAD, or PDF.');
      return;
    }
  }

  const progressBox = document.getElementById('progressBox');
  const progressBar = document.getElementById('progressBar');
  const progressText = document.getElementById('progressText');
  submitBtn.disabled = true;
  nextBtn.disabled = true;
  prevBtn.disabled = true;
  progressBox.style.display = 'block';

  const stages = ['Uploading documents','Validating documents','Reading plan file','Checking rules','Generating report','Routing for review'];
  let i = 0;
  const timer = setInterval(() => {
    if (i >= stages.length) { clearInterval(timer); return; }
    progressText.textContent = stages[i];
    progressBar.style.width = `${Math.round(((i+1)/stages.length)*100)}%`;
    i++;
  }, 500);
});

renderStep();
</script>
@endsection
