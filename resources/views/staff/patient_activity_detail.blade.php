{{--
    Patient activity detail - the read-only View target behind the Patient
    Overview's Recent Activity list and the Patient Activity History page
    (?type= + ?id=): ONE existing record of ONE activity family, fetched
    with the feed's patient-ownership rule (role=user; sick leaves
    additionally require an employee), so a tampered or stale link
    redirects before anything renders.

    Reuses the module's established patterns throughout: the history
    page's Back button + patient summary card, and the shared details
    modal's "text-muted small label + value" field grid (long text spans
    the full row, empty fields print the usual em dash, statuses wear the
    same badge classes the feed and the history tables use). No edit,
    delete or print actions exist on this page - it only reads (medical
    certificates keep their own existing ?view= page on the certificates
    module, which renders the certificate document itself).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
    <div class="mb-3">
        <a href="{{ url('staff/patient_activities?id=' . (int) $patient->id) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to Patient Activity History</a>
    </div>

    <!-- Patient summary - same identity pattern as the history page. -->
    <div class="card mb-3">
        <div class="card-body py-3">
            <h5 class="mb-1">{{ trim(($patient->first_name ?? '') . ' ' . ($patient->last_name ?? '')) }}</h5>
            <div class="text-muted small mb-0">
                {{ ucfirst((string) $patient->account_type) }} &middot; {{ '@' . $patient->username }}
                @if ($patient->account_type === 'student')
                    &middot; Student No. {{ $patient->student_no ?: '—' }}
                @else
                    &middot; Employee No. {{ $patient->employee_no ?: '—' }}
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi {{ $activity['icon'] }} me-1"></i> {{ $title }}</div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ($activity['fields'] as $field)
                    <div class="{{ ! empty($field['full']) ? 'col-12' : 'col-sm-6' }}">
                        <div class="text-muted small">{{ $field['label'] }}</div>
                        <div>
                            @if (! empty($field['badge']))
                                <span class="badge {{ $field['badge']['badge'] }}">{{ $field['badge']['label'] }}</span>
                            @else
                                {!! nl2br(e($field['value'] ?? '—')) !!}
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endsection
