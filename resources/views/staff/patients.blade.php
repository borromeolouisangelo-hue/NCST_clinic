@extends('layouts.dashboard')

@section('title', 'Patients')

@section('content')
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
                        <a href="{{ route('staff.patients') }}" class="btn btn-outline-secondary text-nowrap">Reset</a>
                        <a href="{{ route('staff.patients.register') }}" class="btn btn-accent text-nowrap"><i class="bi bi-plus-lg me-1"></i> New Patient</a>
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

    {!! \App\Services\StaffHistory::table('patients', $patients, 'patientTable') !!}

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
@endsection
