{{--
    Staff Consultations - exact port of staff/consultations.php (original
    lines 104-227): the consultation modal (New/Edit states), the toolbar
    with its client-side search and server filter, and the history table
    with edit/delete actions.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the form carries @csrf instead of csrf_token (Phase 6) - the
    blank line after @csrf compensates PHP eating one newline after the
    compiled closing tag, so the Save line keeps the original's exact
    leading whitespace; $edit['x'] ?? '' becomes $edit->x ?? '' (object|null,
    same null-safe semantics); the conditional data-swal-save/data-swal-add
    attribute is echoed raw via {!! !!} like the original's unescaped
        the filter select reads filterRaw so an invalid ?filter= selects
    no option while the query already collapsed to 'all' - exactly the
    original's split between lines 74-77 and 174-177.

    Client-side bits (data-live-filter, data-search-trigger, data-confirm,
    the SweetAlert handlers and the reset-on-hide script) come from the
    shared main.js/style.css assets both apps serve.

    Image attachments (feature): below the Diagnosis and Treatment
    textareas sit one file input each (jpg/jpeg/png/webp, 5 MB), with a
    filename + small aspect-preserving preview and immediate client-side
    checks; the server re-validates and stores the files on the PRIVATE
    local disk (see ConsultationImages), never under public/.
--}}

@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col-12">
        <div class="modal fade" id="consultationModal" tabindex="-1" aria-labelledby="consultationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-md">
                <div class="modal-content">
                <div class="card">
                    <div class="card-header" id="consultationModalLabel">{{ $edit ? 'Edit Consultation' : 'New Consultation' }}</div>
                    <div class="card-body">
                        @foreach ($errors as $err)
                            <div class="alert alert-danger py-2">{{ $err }}</div>
                        @endforeach
                        <form method="post" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label">Patient *</label>
                                <input type="search" class="form-control" data-patient-combobox="#consultationPatientId" list="consultationPatientSuggestions" autocomplete="off" placeholder="Search patient name or ID..." aria-label="Search patient" required>
                                <input type="hidden" id="consultationPatientId" name="patient_id" value="{{ $patientId ?? '' }}" required>
                                <datalist id="consultationPatientSuggestions">
                                    @foreach ($patients as $pt)
                                        @php $patientId = $pt->student_no ?: $pt->employee_no; @endphp
                                        <option value="{{ ($pt->full_name ?: ('User #' . $pt->id)) . ($patientId ? ' - ' . $patientId : '') }}" data-user-id="{{ (int) $pt->id }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Chief Complaint *</label>
                                <textarea name="complaint" class="form-control" rows="2" required>{{ $edit->complaint ?? '' }}</textarea>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label">Blood Pressure</label><input type="text" name="blood_pressure" class="form-control" value="{{ $edit->blood_pressure ?? '' }}" placeholder="120/80"></div>
                                <div class="col-6"><label class="form-label">Temperature (°C)</label><input type="number" step="0.1" name="temperature" class="form-control" value="{{ $edit->temperature ?? '' }}"></div>
                                <div class="col-6"><label class="form-label">Pulse (bpm)</label><input type="number" name="pulse" class="form-control" value="{{ $edit->pulse ?? '' }}"></div>
                                <div class="col-6"><label class="form-label">Respiration</label><input type="number" name="respiration" class="form-control" value="{{ $edit->respiration ?? '' }}"></div>
                                <div class="col-6"><label class="form-label">Weight (kg)</label><input type="number" step="0.01" name="weight_kg" class="form-control" value="{{ $edit->weight_kg ?? '' }}"></div>
                                <div class="col-6"><label class="form-label">Height (cm)</label><input type="number" step="0.01" name="height_cm" class="form-control" value="{{ $edit->height_cm ?? '' }}"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Diagnosis</label>
                                <textarea name="diagnosis" class="form-control" rows="2">{{ $edit->diagnosis ?? '' }}</textarea>
                                <label class="form-label small mt-1 mb-0" for="diagnosisImage">Attach Diagnosis Image <span class="text-muted fw-normal">(JPG, JPEG, PNG or WEBP - max 5 MB)</span></label>
                                <input type="file" class="form-control form-control-sm consultation-image-input" id="diagnosisImage" name="diagnosis_image" accept="image/jpeg,image/png,image/webp">
                                <div class="consultation-image-preview mt-1 d-none" style="max-width: 220px;">
                                    <div class="small text-muted text-truncate"></div>
                                    <img alt="Diagnosis image preview" style="max-width: 100%; height: auto; max-height: 120px;">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Treatment</label>
                                <textarea name="treatment" class="form-control" rows="2">{{ $edit->treatment ?? '' }}</textarea>
                                <label class="form-label small mt-1 mb-0" for="treatmentImage">Attach Treatment Image <span class="text-muted fw-normal">(JPG, JPEG, PNG or WEBP - max 5 MB)</span></label>
                                <input type="file" class="form-control form-control-sm consultation-image-input" id="treatmentImage" name="treatment_image" accept="image/jpeg,image/png,image/webp">
                                <div class="consultation-image-preview mt-1 d-none" style="max-width: 220px;">
                                    <div class="small text-muted text-truncate"></div>
                                    <img alt="Treatment image preview" style="max-width: 100%; height: auto; max-height: 120px;">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2">{{ $edit->notes ?? '' }}</textarea>
                            </div>
                            @csrf
                                <div class="d-flex gap-2">
                            <button class="btn btn-primary" {!! $edit ? 'data-swal-save="consultation"' : 'data-swal-add="consultation"' !!}><i class="bi bi-save me-1"></i> {{ $edit ? 'Update' : 'Save Consultation' }}</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            </div>
                            @if ($edit)<a href="{{ url('staff/consultations') }}" class="btn btn-outline-secondary">Cancel</a>@endif
                                
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end audit-toolbar mb-2">
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input id="consultationsSearch" type="search" class="form-control form-control-sm" data-live-filter="#consultationTable tbody tr" data-live-filter-count="consultationsResultCount" placeholder="Search visible consultations..." aria-label="Search visible consultations">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <form method="get" class="d-inline">
                            <select name="filter" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="all" {{ $filterRaw === 'all' ? 'selected' : '' }}>All</option>
                                <option value="today" {{ $filterRaw === 'today' ? 'selected' : '' }}>Today</option>
                                <option value="week" {{ $filterRaw === 'week' ? 'selected' : '' }}>This Week</option>
                                <option value="month" {{ $filterRaw === 'month' ? 'selected' : '' }}>This Month</option>
                            </select>
                        </form>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        
                        <button type="button" class="btn btn-sm btn-primary text-nowrap" data-search-trigger="#consultationsSearch"><i class="bi bi-search me-1"></i> Search</button>
                        
                        <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#consultationModal"><i class="bi bi-plus-lg me-1"></i>Consultation</button>
                    </div>
                </div>
            </div>
        </div>
               
                    <div class="d-flex align-items-center w-100 mb-2">
                        <a href="{{ route('staff.history', ['tab' => 'consultations']) }}"
                        class="btn btn-sm btn-outline-primary text-nowrap ms-auto">
                        <i class="bi bi-list-ul me-1"></i> View All
                        </a>
                    </div>
                
                    

        <div class="card">
            <div class="table-responsive">
                {!! \App\Services\StaffHistory::table('consultations', $list, 'consultationTable', 'No consultations found.') !!}
            </div>
        </div>

</div>
</div>
</div>
@if ($errors || $edit)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('consultationModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('consultationModal');
    if (!modal) return;

    // Consultation image attachments: client-side mirror of the server
    // rules (jpg/jpeg/png/webp only, 5 MB ceiling) plus the chosen file's
    // name and a small aspect-ratio-preserving preview. The server
    // re-validates on POST - this only gives immediate feedback.
    var MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    var ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    function clearPreview(input) {
        var wrap = input.parentElement.querySelector('.consultation-image-preview');
        if (!wrap) return null;
        var img = wrap.querySelector('img');
        if (img && img.dataset.objectUrl === '1') {
            URL.revokeObjectURL(img.getAttribute('src'));
            img.removeAttribute('src');
            delete img.dataset.objectUrl;
        }
        var name = wrap.querySelector('div');
        if (name) name.textContent = '';
        wrap.classList.add('d-none');
        return wrap;
    }

    modal.querySelectorAll('.consultation-image-input').forEach(function (input) {
        input.addEventListener('change', function () {
            clearPreview(input);
            var file = input.files && input.files[0];
            if (!file) return;

            var problem = '';
            if (ALLOWED_IMAGE_TYPES.indexOf(file.type) === -1) {
                problem = 'Only JPG, JPEG, PNG or WEBP images are allowed.';
            } else if (file.size > MAX_IMAGE_BYTES) {
                problem = 'Image must not be larger than 5 MB.';
            }
            if (problem) {
                input.value = '';
                if (window.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Invalid image', text: problem });
                } else {
                    alert(problem);
                }
                return;
            }

            var wrap = clearPreview(input);
            if (!wrap) return;
            var img = wrap.querySelector('img');
            var name = wrap.querySelector('div');
            img.src = URL.createObjectURL(file);
            img.dataset.objectUrl = '1';
            name.textContent = file.name;
            wrap.classList.remove('d-none');
        });
    });

    modal.addEventListener('hidden.bs.modal', function () {
        var form = modal.querySelector('form');
        if (form) form.reset();
        modal.querySelectorAll('.consultation-image-input').forEach(clearPreview);
    });
});
</script>
@endsection