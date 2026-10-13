{{--
    Patient Activity History - the dedicated full-history page behind the
    Patient Overview's "View All Activities" button (?id= + q / type / page).

    Reuses the established staff-page conventions throughout: the overview's
    Back button and patient summary card patterns, the shared audit-toolbar
    GET search form (search input + auto-submitting select, exactly like the
    patients list and the staff history page), the shared table hover style
    and the patients-list pagination nav. Rows come from the shared
    PatientActivityService (one UNION ALL, newest first, server-side page),
    so columns, status badges and record links can never drift from the
    overview preview that shows the same activities.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
    <div class="mb-3">
        <a href="{{ url('staff/patients?id=' . (int) $patient->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Patient Overview</a>
    </div>

    <!-- Patient summary - same identity patterns as the overview's
         Patient Information card (picture + name, type + @username, ID
         line): the same stored photo and default-avatar fallback the
         overview shows, so both pages always present the same patient. -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-center gap-3">
                @if ($patientPhotoUrl)
                    <img src="{{ $patientPhotoUrl }}" alt="{{ trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')) }}" class="account-avatar account-avatar-lg" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex'">
                    <span class="account-avatar account-avatar-lg account-avatar-empty" style="display:none"><i class="bi bi-person"></i></span>
                @else
                    <span class="account-avatar account-avatar-lg account-avatar-empty"><i class="bi bi-person"></i></span>
                @endif
                <div>
                    <h5 class="mb-1">{{ trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')) }}</h5>
                    <div class="text-muted small mb-0">
                        {{ ucfirst((string) $patient->account_type) }} &middot; {{ '@' . $patient->username }}
                        @if ($patient->account_type === 'student')
                            &middot; Student No. {{ $patient->student_no ?: '—' }}
                            &middot; {{ trim(($patient->course ?? '') . ' ' . ($patient->year_level ?? '') . ($patient->section ? ' - ' . $patient->section : '')) ?: '—' }}
                        @else
                            &middot; Employee No. {{ $patient->employee_no ?: '—' }}
                            &middot; {{ $patient->department ?: '—' }}
                        @endif
                        @if ($patient->contact_no)
                            &middot; {{ $patient->contact_no }}
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & filter card (values are preserved after submission and in
         every pagination link; the patient id rides along as a hidden
         input). Sick Leave is only offered for employees - the service
         never builds that source for students. -->
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end audit-toolbar">
                <input type="hidden" name="id" value="{{ (int) $patient->id }}">
                <div class="col">
                    <label class="form-label" for="activitySearch">Search</label>
                    <input type="search" id="activitySearch" name="q"
                           class="form-control form-control-sm"
                           value="{{ $search }}"
                           placeholder="Search complaint, diagnosis, medicine, equipment, purpose or reason..."
                           aria-label="Search activities">
                </div>
                <div class="col-auto">
                    <label class="form-label" for="activityType">Type</label>
                    <select id="activityType" name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        @foreach ($typeOptions as $value => $optionLabel)
                            <option value="{{ $value }}"{{ $value === $typeFilter ? ' selected' : '' }}>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col d-flex flex-wrap gap-2 justify-content-start">
                    <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Full history card - newest first, one row per activity. -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div><i class="bi bi-activity me-1"></i> All Activities</div>
            <small class="text-muted fw-normal">
                {{ $pagination['totalItems'] }} activit{{ $pagination['totalItems'] === 1 ? 'y' : 'ies' }}{!! $search !== '' ? ' matching &ldquo;' . e($search) . '&rdquo;' : '' !!}
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Date</th><th>Activity</th><th>Details</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @if (! $activities)
                    <tr><td colspan="5" class="text-center text-muted py-4">{{ ($search !== '' || $typeFilter !== 'all') ? 'No activities match your search.' : 'No activities recorded yet.' }}</td></tr>
                @else
                    @foreach ($activities as $activity)
                        <tr>
                            <td>{{ fmt_dt($activity['occurred_at'], 'M d, Y g:i A') }}</td>
                            <td><i class="bi {{ $activity['icon'] }} me-1"></i>{{ $activity['label'] }}</td>
                            <td>{{ $activity['description'] }}</td>
                            <td>@if ($activity['status'])<span class="badge {{ $activity['status']['badge'] }}">{{ $activity['status']['label'] }}</span>@else—@endif</td>
                            <td class="text-end">
                                {{-- Every family links to its read-only
                                     record: certificates reuse the
                                     certificates page (?view= renders the
                                     certificate document), the rest open
                                     the shared activity detail page. --}}
                                @if ($activity['url'])
                                    <a href="{{ $activity['url'] }}" class="btn btn-sm btn-outline-primary" title="View record"><i class="bi bi-eye"></i></a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
                </tbody>
            </table>
        </div>
    </div>

    @if (($pagination['total'] ?? 0) > 1)
    <nav aria-label="Activity pagination" class="mt-3">
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
