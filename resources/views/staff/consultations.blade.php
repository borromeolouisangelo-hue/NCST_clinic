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
                        <form method="post">
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
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Treatment</label>
                                <textarea name="treatment" class="form-control" rows="2">{{ $edit->treatment ?? '' }}</textarea>
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
                        
                        <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#consultationModal"><i class="bi bi-plus-lg me-1"></i> New Consultation</button>
                    </div>
                </div>
                
            </div>
        </div>
        <div class="card">
            <div class="table-responsive">
                {!! \App\Services\StaffHistory::table('consultations', $list, 'consultationTable') !!}
            </div>
        </div>

    @if (($pagination['total'] ?? 0) > 1)
    <nav aria-label="Consultation pagination" class="mt-3">
        <ul class="pagination justify-content-center">
            @if ($pagination['current'] > 1)
                <li class="page-item">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] - 1])) }}" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            @endif
            @for ($i = max(1, $pagination['current'] - 2); $i <= min($pagination['total'], $pagination['current'] + 2); $i++)
                <li class="page-item {{ $i === $pagination['current'] ? 'active' : '' }}">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $i])) }}">{{ $i }}</a>
                </li>
            @endfor
            @if ($pagination['current'] < $pagination['total'])
                <li class="page-item">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] + 1])) }}" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            @endif
        </ul>
    </nav>
    @endif
</div>
</div>
</div>
@if ($errors || $edit)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('consultationModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('consultationModal');
    if (!modal) return;
    modal.addEventListener('hidden.bs.modal', function () {
        var form = modal.querySelector('form');
        if (form) form.reset();
    });
});
</script>
@endsection