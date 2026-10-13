{{--
    Staff Sick Leave Requests - exact port of staff/sick_leaves.php
    (original lines 75-237): the three metric cards, the search/filter
    toolbar, and the review table.

    Feature 4 reworked the table's Actions column: it used to carry two icon
    buttons per row (approve/reject), each opening its own modal, and now
    carries nothing but View, which opens the shared details modal
    (staff/partials/leave-details-modal) holding the record plus - while the
    request is pending - Accept / Decline / Cancel. The column is view-only;
    nothing about the review handler below changed.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the modal forms carry @csrf instead of csrf_token (Phase 6) -
    the blank line after @csrf compensates PHP eating one newline after the
    compiled directive, so the following hidden input keeps the original's
    exact leading whitespace; $leave['x'] becomes ->x (stdClass rows, same
    values); the start/end date block (150-153) merges onto one source line
    carrying each original lead as literal spaces, like user/dashboard.

    Legacy quirks kept: an invalid ?status= is ignored by the SQL (all rows
    show) while the <select> simply renders no selected option; the review
    handler flashes success even when the UPDATE matches no row; there is
    NO log_action() on review (the original never audits this page);
    fire_event('sick_leave.reviewed') has no listener so nothing is
    written; one details modal renders for EVERY row regardless of status;
    the summary counts are always global (unfiltered).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
@include('layouts.partials.flash-toast')

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon icon-warning"><i class="bi bi-hourglass-split"></i></div>
            <div class="metric-value">{{ $pendingCount }}</div>
            <div class="metric-label">Pending Requests</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon icon-success"><i class="bi bi-check-circle"></i></div>
            <div class="metric-value">{{ $approvedCount }}</div>
            <div class="metric-label">Approved</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="metric-card">
            <div class="metric-icon icon-primary"><i class="bi bi-x-circle"></i></div>
            <div class="metric-value">{{ $rejectedCount }}</div>
            <div class="metric-label">Declined</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar">
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Name or employee ID..." aria-label="Search sick leaves">
                    </div>
                    <div class="col-auto ">
                        <label class="form-label">Filter</label>
                        <select name="status" class="form-select  form-select-sm" onchange="this.form.submit()">
                            <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>All</option>
                            <option value="pending" {{ $statusFilter === 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="approved" {{ $statusFilter === 'approved' ? 'selected' : '' }}>Approved</option>
                            <option value="rejected" {{ $statusFilter === 'rejected' ? 'selected' : '' }}>Declined</option>
                        </select>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>

                    <div class="d-flex align-items-center w-100 mb-2">
                        <a href="{{ route('staff.history', ['tab' => 'sick-leaves']) }}" class="btn btn-sm btn-outline-primary text-nowrap ms-auto"><i class="bi bi-list-ul me-1"></i> View All</a>
                    </div>


        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Reason</th>
                            <th>Leave Period</th>
                            <th>Filed</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if (!$leaves)
                            <tr><td colspan="7" class="text-center text-muted py-4">No sick leave requests found.</td></tr>
                        @else
@foreach ($leaves as $leave)
                            <tr>
                                <td>
                                    <strong>{{ $leave->employee_name ?: '—' }}</strong><br>
                                    <small class="text-muted">{{ $leave->employee_no ?? '' }}</small>
                                </td>
                                <td>{{ $leave->department ?? '—' }}</td>
                                <td>{{ mb_strimwidth((string) $leave->reason, 0, 60, '…') }}</td>
                                <td>
                                    {{ date('M d, Y', strtotime($leave->start_date)) }}                                    @if ($leave->end_date)                                        – {{ date('M d, Y', strtotime($leave->end_date)) }}                                    @endif                                </td>
                                <td>{{ fmt_dt($leave->created_at, 'M d, Y g:i A') }}</td>
                                <td>
                                    @if ($leave->status === 'pending')
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    @elseif ($leave->status === 'approved')
                                        <span class="badge bg-success">Approved</span>
                                    @else
                                        <span class="badge bg-danger">Declined</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    {{-- Feature 4: View is the row's only action.
                                         The reviewer, note and - for a pending
                                         request - the decision live in the
                                         details modal, so the cell carries
                                         nothing else. --}}
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#leaveModal{{ (int) $leave->id }}"><i class="bi bi-eye me-1"></i> View</button>
                                </td>
                            </tr>

                            @include('staff.partials.leave-details-modal', ['leave' => $leave])
                        @endforeach
@endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
