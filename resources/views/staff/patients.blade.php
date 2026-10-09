@extends('layouts.dashboard')

@section('title', 'Patients')

@section('content')
@if ($patient)
    {{-- Profile branch - port of staff/patients.php lines 100-218: the Back
         link and the overview cards. The original's Recent Consultations and
         Recent Dispensed Medicines cards, plus the Overview / Visits and
         dispensing pill nav that switched between them, were removed here as
         a display cleanup - those records stay on /staff/consultations and
         /staff/dispense. The list/search branch below only renders when no
         ?id= profile matched, same if/else split as the original. --}}
    <div class="mb-3">
        <a href="{{ url('staff/patients') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to patients</a>
    </div>

    <div class="row g-3 patient-profile-row">
        <div class="col-lg-4">
            <div class="card mb-3" data-profile-tab-panel="overview">
                <div class="card-header">Patient Information</div>
                <div class="card-body">
                    <h5 class="mb-1">{{ trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')) }}</h5>
                    <div class="text-muted small mb-2">{{ ucfirst($patient->account_type) }} &middot; {{ '@' . $patient->username }}</div>
                    <dl class="row mb-0 small">
                        @if ($patient->account_type === 'student')
                            <dt class="col-5">Student No.</dt><dd class="col-7">{{ $patient->student_no ?: '—' }}</dd>
                            <dt class="col-5">Course</dt><dd class="col-7">{{ $patient->course ?: '—' }}</dd>
                            <dt class="col-5">Year / Section</dt><dd class="col-7">{{ trim(($patient->year_level ?? '') . ' ' . ($patient->section ?? '')) ?: '—' }}</dd>
                        @else
                            <dt class="col-5">Employee No.</dt><dd class="col-7">{{ $patient->employee_no ?: '—' }}</dd>
                            <dt class="col-5">Department</dt><dd class="col-7">{{ $patient->department ?: '—' }}</dd>
                            <dt class="col-5">Position</dt><dd class="col-7">{{ $patient->position ?: '—' }}</dd>
                        @endif
                        <dt class="col-5">Gender</dt><dd class="col-7">{{ ucfirst((string) $patient->gender) }}</dd>
                        <dt class="col-5">Birthdate</dt><dd class="col-7">{{ $patient->birthdate ? fmt_dt($patient->birthdate, 'M d, Y') : '—' }}</dd>
                        <dt class="col-5">Contact</dt><dd class="col-7">{{ $patient->contact_no ?: '—' }}</dd>
                        <dt class="col-5">Address</dt><dd class="col-7">{{ $patient->address ?: '—' }}</dd>
                        <dt class="col-5">Emergency</dt><dd class="col-7">{{ $patient->emergency_name ?: '—' }} {{ $patient->emergency_contact ? '(' . $patient->emergency_contact . ')' : '' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card mb-3" data-profile-tab-panel="overview">
                <div class="card-header">Latest Medical Record</div>
                <div class="card-body">
                    @if (! $medRec)
                        <p class="text-muted mb-0">No medical record submitted yet.</p>
                    @else
                        <div class="row g-2 small">
                            <div class="col-md-3"><strong>Blood Type:</strong> {{ $medRec->blood_type ?: '—' }}</div>
                            <div class="col-md-3"><strong>Height:</strong> {{ $medRec->height_cm ?: '—' }} cm</div>
                            <div class="col-md-3"><strong>Weight:</strong> {{ $medRec->weight_kg ?: '—' }} kg</div>
                            <div class="col-md-3"><strong>Period:</strong> {{ $medRec->school_year . ' ' . $medRec->semester }}</div>
                            <div class="col-12 mt-2"><strong>Allergies (free text):</strong> {{ $medRec->allergies_text ?: '—' }}</div>
                            <div class="col-12"><strong>Existing Conditions:</strong> {{ $medRec->existing_conditions ?: '—' }}</div>
                            <div class="col-12"><strong>Current Medications:</strong> {{ $medRec->current_medications ?: '—' }}</div>
                            <div class="col-12"><strong>Immunizations:</strong> {{ $medRec->immunizations ?: '—' }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card mb-3" data-profile-tab-panel="overview">
                <div class="card-header"><i class="bi bi-exclamation-triangle text-accent me-1"></i> Recorded Medicine Allergies</div>
                <div class="card-body">
                    @if (! $allergies->count())
                        <p class="text-muted mb-0">No linked allergies recorded.</p>
                    @else
                        <ul class="mb-0">
                            @foreach ($allergies as $a)
                                <li>
                                    <strong>{{ $a->allergen_name }}</strong>
                                    <span class="badge bg-secondary ms-1">{{ ucfirst((string) $a->severity) }}</span>
                                    @if ($a->notes)<span class="text-muted small">— {{ $a->notes }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
@else
<div class="row g-3">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar">
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" list="patientSearchSuggestions" autocomplete="off" placeholder="Name or ID..." aria-label="Search patients">
                        <datalist id="patientSearchSuggestions">
                            @foreach ($patientSuggestions as $suggestion)
                                @php
                                    $suggestionName = trim((string) $suggestion->full_name);
                                    $suggestionId = $suggestion->student_no ?: $suggestion->employee_no;
                                    $suggestionLabel = trim($suggestionName . ($suggestionId ? ' - ' . $suggestionId : '') . ' - @' . $suggestion->username);
                                @endphp
                                <option value="{{ $suggestionLabel }}" label="{{ $suggestionLabel }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" {{ $typeFilter === 'all' ? 'selected' : '' }}>All Types</option>
                            <option value="student" {{ $typeFilter === 'student' ? 'selected' : '' }}>Student</option>
                            <option value="employee" {{ $typeFilter === 'employee' ? 'selected' : '' }}>Employee</option>
                        </select>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                        
                    </div>
                </form>
            </div>
        </div>

    @if ($patientError)
        <div class="alert alert-danger py-2 mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            {{ $patientError }}
        </div>
    @endif

    {{-- The original patients.php table echoed its own "No patients found."
         empty row; pass it through so the shared history table keeps its
         generic "No records found." label for every other page. --}}
    {!! \App\Services\StaffHistory::table('patients', $patients, 'patientTable', 'No patients found.') !!}

    @if (($pagination['total'] ?? 0) > 1)
    <nav aria-label="Patient pagination" class="mt-3">
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
@endif
@endsection
