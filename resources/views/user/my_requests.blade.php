{{--
    User My Activity overview - exact port of user/my_requests.php
    (original lines 1-175): the info alert, the three medicine-filter pills
    and four history cards - medicine requests (whitelisted dispensed/
    rejected status filter, generic-name sub-line, trimmed reason, "Declined"
    label for rejected), equipment loans (COALESCE(return_date, borrow_date)
    order), consultations (staff name via profiles LEFT JOIN) and the sick
    leave card that only renders when the user has any (LIMIT 50 query).

    Approved differences: the filter pill hrefs drop .php (routing
    convention since Phase 4); DB::select stdClass rows use -> where the
    original used ['x']; nested echo expressions keep e() semantics via
    {{ }} (equivalent truthiness for the ?: fallbacks).

    Legacy quirks kept: any non-whitelisted ?status= value applies no WHERE
    clause at all and leaves no pill active; the assignment-in-foreach
    ($leave = $sickLeaves as $leave) is preserved; rejected medicine rows
    read "Declined" while their badge stays bg-danger, and unknown statuses
    fall back to bg-secondary.

    Feature 8: My Activity now uses top-level tabs (Medicine/Equipment/
    Consultations/Sick Leave) per the Staff History pattern, with the
    medicine status pills inside the Medicine tab. ?tab= is the single
    source of truth, falling back to 'medicine' for unknown values.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="alert alert-info">This page combines your medicine requests, equipment loans, consultation history, and sick leave history into one activity overview. Medicine requests follow a one-request-per-tablet-or-capsule-medicine-per-day limit: other eligible medicines may be requested separately, and first-aid supplies and other non-tablet/capsule items are not subject to this restriction.</div>

<ul class="nav nav-pills mb-3" aria-label="Activity sections">
    <li class="nav-item">
        <a class="nav-link {{ $activeTab === 'medicine' ? 'active' : '' }}"
           href="{{ url('user/my_requests?tab=medicine' . ($statusFilter ? '&status=' . $statusFilter : '')) }}">
            <i class="bi bi-capsule me-1"></i> Medicine
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $activeTab === 'equipment' ? 'active' : '' }}"
           href="{{ url('user/my_requests?tab=equipment') }}">
            <i class="bi bi-tools me-1"></i> Equipment
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $activeTab === 'consultations' ? 'active' : '' }}"
           href="{{ url('user/my_requests?tab=consultations') }}">
            <i class="bi bi-clipboard2-pulse me-1"></i> Consultations
        </a>
    </li>
    @if ($isEmployee)
    <li class="nav-item">
        <a class="nav-link {{ $activeTab === 'sick-leaves' ? 'active' : '' }}"
           href="{{ url('user/my_requests?tab=sick-leaves') }}">
            <i class="bi bi-thermometer me-1"></i> Sick Leave
        </a>
    </li>
    @endif
</ul>



@if ($activeTab === 'medicine')
<div class="card mb-3">
    <div class="card-header">Medicine History</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Date</th><th>Medicine</th><th class="text-end">Qty</th><th>Reason</th><th>Status</th><th>Remarks</th></tr></thead>
            <tbody>
            @if (!$requests)
                <tr><td colspan="6" class="text-center text-muted py-4">No requests found.</td></tr>
            @else
            @foreach ($requests as $r)
                <tr>
                    <td>{{ fmt_dt($r->requested_at, 'M d, Y g:i A') }}</td>
                    <td>{{ $r->medicine_name }}@if ($r->generic_name)<div class="small text-muted">{{ $r->generic_name }}</div>@endif</td>
                    <td class="text-end">{{ (int) $r->quantity }}</td>
                    <td>{{ mb_strimwidth((string) $r->reason, 0, 40, '…') }}</td>
                    <td><span class="badge {{ $badge[$r->status] ?? 'bg-secondary' }}">{{ $r->status === 'rejected' ? 'Declined' : ucfirst($r->status) }}</span></td>
                    <td>{{ $r->remarks ?: '—' }}</td>
                </tr>
            @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>
@endif

@if ($activeTab === 'equipment')
<div class="card mb-3">
    <div class="card-header">Equipment History</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Equipment</th><th class="text-end">Qty</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr></thead>
            <tbody>
            @if (!$loans)
                <tr><td colspan="6" class="text-center text-muted py-4">No equipment history found.</td></tr>
            @else
            @foreach ($loans as $loan)
                @php
                    // Feature 3: overdue is a derived state (still out past
                    // the 24-hour timeout), so it is derived here rather than
                    // looked up in the badge map. When no due date was ever
                    // recorded - the loan form writes none - the Due column
                    // falls back to the 24-hour timeout the staff page shows,
                    // so a row that reads Overdue can say when it lapsed.
                    $overdue = (int) ($loan->is_overdue ?? 0) === 1;
                    $due = $loan->due_date;
                    if (! $due && ! empty($loan->borrowed_at)) {
                        $due = date('Y-m-d H:i:s', strtotime($loan->borrowed_at) + 86400);
                    }
                    $returned = $loan->return_date;
                    if ($overdue) {
                        $badgeClass = 'bg-danger';
                        $statusText = 'Overdue';
                    } elseif ($loan->status === 'returned') {
                        $badgeClass = 'bg-success';
                        $statusText = 'Returned';
                    } else {
                        // Feature 3: use the same orange badge as the staff page
                        // for borrowed equipment (not overdue yet).
                        $badgeClass = 'bg-orange';
                        $statusText = 'Borrowed';
                    }
                @endphp
                <tr>
                    <td>{{ $loan->equipment_name }}</td>
                    <td class="text-end">{{ (int) $loan->quantity }}</td>
                    <td>{{ $loan->borrowed_at ? fmt_dt($loan->borrowed_at, 'M d, Y g:i A') : '—' }}</td>
                    <td>{{ $due ? fmt_dt($due, 'M d, Y g:i A') : '—' }}</td>
                    <td>{{ $returned ? fmt_dt($returned, 'M d, Y') : '—' }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ $statusText }}</span></td>
                </tr>
            @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>
@endif

@if ($activeTab === 'consultations')
<div class="card mb-3">
    <div class="card-header">Consultation History</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Date</th><th>Staff</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th><th>Vitals</th></tr></thead>
            <tbody>
            @if (!$consultations)
                <tr><td colspan="6" class="text-center text-muted py-4">No consultations found.</td></tr>
            @else
            @foreach ($consultations as $c)
                <tr>
                    <td>{{ fmt_dt($c->consultation_date, 'M d, Y g:i A') }}</td>
                    <td>{{ $c->staff_name ?: '—' }}</td>
                    <td>{{ mb_strimwidth((string) $c->complaint, 0, 40, '…') }}</td>
                    <td>{{ $c->diagnosis ?: '—' }}</td>
                    <td>{{ $c->treatment ?: '—' }}</td>
                    <td>
                        @if ($c->blood_pressure || $c->temperature || $c->pulse || $c->weight_kg || $c->height_cm)
                            @if ($c->blood_pressure)<small>{{ $c->blood_pressure }}</small>@endif
                            @if ($c->temperature)<small>{{ $c->temperature }}°C</small>@endif
                            @if ($c->pulse)<small>{{ $c->pulse }} bpm</small>@endif
                            @if ($c->weight_kg)<small>{{ $c->weight_kg }} kg</small>@endif
                            @if ($c->height_cm)<small>{{ $c->height_cm }} cm</small>@endif
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
@endif

@if ($activeTab === 'sick-leaves' && $isEmployee)
<div class="card mb-3">
    <div class="card-header">Sick Leave History</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Date</th><th>Reason</th><th>Start</th><th>End</th><th>Status</th></tr></thead>
            <tbody>
            @if (!$sickLeaves)
                <tr><td colspan="5" class="text-center text-muted py-4">No sick leave records found.</td></tr>
            @else
            @foreach ($sickLeaves as $sl)
                <tr>
                    <td>{{ fmt_dt($sl->created_at, 'M d, Y g:i A') }}</td>
                    <td>{{ mb_strimwidth((string) $sl->reason, 0, 40, '…') }}</td>
                    <td>{{ $sl->start_date ? fmt_dt($sl->start_date, 'M d, Y') : '—' }}</td>
                    <td>{{ $sl->end_date ? fmt_dt($sl->end_date, 'M d, Y') : '—' }}</td>
                    <td>
                        @if ($sl->status === 'pending')
                            <span class="badge bg-warning text-dark">Pending</span>
                        @elseif ($sl->status === 'approved')
                            <span class="badge bg-success">Approved</span>
                        @else
                            <span class="badge bg-danger">Declined</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection