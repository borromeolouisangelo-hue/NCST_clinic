{{--
    Staff Deduction Logs - exact port of staff/deduction_logs.php (original
    lines 48-136): the two metric cards, the search/status toolbar, and the
    OUT stock-movements table with its status badges.

    Approved differences: row keys use -> instead of ['x'] (DB::select
    stdClass rows, same values); $m/s echoes become {{ }} (e() both sides).
    No POST, no CSRF, no audit rows exist on this page in the reference -
    it never calls log_action.

    Post-migration feature (additive): the table gained Previous / Resulting
    / Total After columns. Every figure is reconstructed from the movement
    history by DeductionLogsController::withStockContext() - previous and
    resulting batch quantity plus the pieces left in the open package (11/12
    for tablets, 49/50 for boxes; "—" for the non-piece units), and the
    medicine's total stock after the deduction. Qty Deducted / Pieces keep
    their original cells and the empty-state colspan follows the new width.

    Legacy quirks kept: the status whitelist only accepts dispensed/rejected
    (any other value - including missing - filters nothing and the select
    simply renders unselected); movements without a dispense reference show
    the 'N/A' badge through ($d->request_status ?? 'N/A'); the metric card
    wrapper keeps the reference's stray trailing space inside
    "row g-3 mb-4 "; LIMIT 200 rows; both counters are GLOBAL (they ignore
    the active search/filter).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3 mb-4 ">
    <div class="col-6 col-lg-3">
        <div class="metric-card">
            <div class="metric-icon icon-primary"><i class="bi bi-box-arrow-down"></i></div>
            <div class="metric-value">{{ $totalDeductions }}</div>
            <div class="metric-label">Total Deductions</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="metric-card">
            <div class="metric-icon icon-warning"><i class="bi bi-calendar-day"></i></div>
            <div class="metric-value">{{ $todayDeductions }}</div>
            <div class="metric-label">Today's Deductions</div>
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
                        <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Medicine, patient, or batch..." aria-label="Search deduction logs">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="dispensed" {{ $statusFilter === 'dispensed' ? 'selected' : '' }}>Dispensed</option>
                            <option value="rejected" {{ $statusFilter === 'rejected' ? 'selected' : '' }}>Declined</option>
                        </select>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Medicine</th>
                            <th>Batch</th>
                            <th>Patient</th>
                            <th title="Batch quantity and package pieces before this deduction">Previous</th>
                            <th class="text-end" title="Units this movement removed">Qty Deducted</th>
                            <th class="text-end" title="Pieces inside the deducted quantity">Pieces</th>
                            <th title="Batch quantity and package pieces after this deduction">Resulting</th>
                            <th class="text-end" title="Medicine stock remaining after this deduction">Total After</th>
                            <th>Reason</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @if (!$deductions)
                        <tr><td colspan="11" class="text-center text-muted py-4">No deduction records found.</td></tr>
                    @else
@foreach ($deductions as $d)
                        @php
                        $deductedQty = (int) $d->deducted_qty;
                        $pkg = $d->package_size ?? null;
                        $prevPieces = $d->prev_pieces ?? null;
                        $resPieces = $d->res_pieces ?? null;
                        $pieceLabel = fn (?int $pieces) => $pieces !== null && $pkg ? $pieces . '/' . $pkg : '—';
                        @endphp
                        <tr>
                            <td>{{ fmt_dt($d->deducted_at, 'M d, Y g:i A') }}</td>
                            <td>{{ $d->medicine_name }}</td>
                            <td>{{ $d->batch_no ?: '—' }}</td>
                            <td>{{ $d->patient_name ?: '—' }}</td>
                            <td>
                                @if ($d->prev_qty !== null)
                                    <strong>{{ $d->prev_qty }}</strong> {{ $d->unit }}
                                    <small class="text-muted d-block">{{ $pieceLabel($prevPieces) }} @if ($pkg) in current package @endif</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $deductedQty }} {{ $d->unit }}</td>
                            <td class="text-end">{{ $deductedQty }} pcs</td>
                            <td>
                                @if ($d->res_qty !== null)
                                    <strong>{{ $d->res_qty }}</strong> {{ $d->unit }}
                                    <small class="text-muted d-block">{{ $pieceLabel($resPieces) }} @if ($pkg) in current package @endif</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">@if ($d->total_after !== null)<strong>{{ $d->total_after }}</strong> {{ $d->unit }}@else<span class="text-muted">—</span>@endif</td>
                            <td>{{ $d->reason ?: '—' }}</td>
                            <td>
                                @if ($d->request_status === 'dispensed')
                                    <span class="badge bg-success">Dispensed</span>
                                @elseif ($d->request_status === 'rejected')
                                    <span class="badge bg-danger">Declined</span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($d->request_status ?? 'N/A') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
@endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
