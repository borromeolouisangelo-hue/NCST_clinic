{{--
    Admin - Reports overview - exact port of admin/reports.php (original
    lines 80-155): the report heading (clinic name + period line), the four
    metric cards, the GET period form (period select with selected-state,
    From/To date inputs, Print + CSV buttons), the two reason tables
    (empty-state rows, grouped SUM rows with the em-dash fallback for empty
    reasons) and the two chart cards, then the charts.js include and the
    inline drawPieChart bootstrapping.

    Emission notes:
    - $lowStock of the reference never reaches its own view (dead query,
      dropped - Phase 22 $actions / Phase 25 $categoryOptions precedent).
    - The period form is GET; there is no CSRF surface on this page.
    - The two table bodies are fused if/else/foreach one-liners in the
      reference: @if keeps its single line (the line ends in plain markup,
      as the original does), while the else+foreach+endforeach+endif line
      splits into @else at 32sp, @foreach at 0sp and @endif at 0sp - every
      split boundary is consumed by a compiled tag that eats its newline,
      so both the empty and the populated case merge byte-identically with
      the reference (Phase 23/25 split convention).
    - Chart JSON stays RAW through {!! !!}: the reference echoes
      json_encode() unescaped, and htmlspecialchars would corrupt the JS
      string literals. No JSON_HEX_* flags on either side (admin-controlled
      labels only - reference behavior kept verbatim).
    - The closers stay inside the view before endsection: the reference page
      closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div id="reportArea">
                <div class="mb-3">
                    <h5 class="text-primary mb-0">{{ get_setting('clinic_name', 'School Health Clinic') }}</h5>
                    <div class="text-muted small">Report period: {{ fmt_dt($from, 'M d, Y') }} to {{ fmt_dt($to, 'M d, Y') }}</div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-primary"><i class="bi bi-clipboard2-pulse"></i></div><div class="metric-value">{{ $consCount }}</div><div class="metric-label">Consultations</div></div></div>
                    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-warning"><i class="bi bi-capsule"></i></div><div class="metric-value">{{ $requestCount }}</div><div class="metric-label">Medicine Units Requested</div></div></div>
                    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-success"><i class="bi bi-tools"></i></div><div class="metric-value">{{ $loanCount }}</div><div class="metric-label">Equipment Loans</div></div></div>
                    <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-primary"><i class="bi bi-file-earmark-medical"></i></div><div class="metric-value">{{ $certCount }}</div><div class="metric-label">Certificates Issued</div></div></div>
                </div>
                <div class="card mb-3 no-print">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Reporting period</label>
                            <select name="period" class="form-select">
                                <option value="daily" {{ $period === 'daily' ? 'selected' : '' }}>Daily</option>
                                <option value="weekly" {{ $period === 'weekly' ? 'selected' : '' }}>Weekly</option>
                                <option value="monthly" {{ $period === 'monthly' ? 'selected' : '' }}>Monthly</option>
                                <option value="yearly" {{ $period === 'yearly' ? 'selected' : '' }}>Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">From</label>
                            <input type="date" name="from" class="form-control" value="{{ $from }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">To</label>
                            <input type="date" name="to" class="form-control" value="{{ $to }}">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="button" class="btn  btn-outline-primary" data-export-table="#adminReportTable" data-export-name="clinic-admin-report"><i class="bi bi-download me-1"></i> CSV</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-3" id="adminReportTable">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Requested Medicines and Reasons</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Medicine</th><th>Reason</th><th class="text-end">Qty</th></tr></thead>
                                <tbody>
                                @if (!$medicineRequests)<tr><td colspan="3" class="text-center text-muted py-3">No medicine requests in this period.</td></tr>
                                @else
@foreach ($medicineRequests as $request)<tr><td>{{ $request['name'] }}</td><td>{{ $request['reason'] ?: '—' }}</td><td class="text-end">{{ (int)$request['total'] }}</td></tr>@endforeach
@endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Borrowed Equipment and Reasons</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0"><thead><tr><th>Equipment</th><th>Reason</th><th class="text-end">Qty</th></tr></thead>
                                <tbody>
                                @if (!$equipmentLoans)<tr><td colspan="3" class="text-center text-muted py-3">No equipment loans in this period.</td></tr>
                                @else
@foreach ($equipmentLoans as $loan)<tr><td>{{ $loan['name'] }}</td><td>{{ $loan['reason'] ?: '—' }}</td><td class="text-end">{{ (int)$loan['total'] }}</td></tr>@endforeach
@endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6"><div class="card h-100"><div class="card-header">Medicine Request Distribution</div><div class="card-body"><canvas id="medicineRequestsChart" width="640" height="320"></canvas></div></div></div>
                <div class="col-lg-6"><div class="card h-100"><div class="card-header">Equipment Loan Distribution</div><div class="card-body"><canvas id="equipmentLoansChart" width="640" height="320"></canvas></div></div></div>
            </div>
        </div>

    <script src="{{ url('assets/js/charts.js') }}"></script>
    <script>drawPieChart('medicineRequestsChart', {!! json_encode(array_column($medicineChart, 'name')) !!}, {!! json_encode(array_map('intval', array_column($medicineChart, 'total'))) !!}); drawPieChart('equipmentLoansChart', {!! json_encode(array_column($equipmentChart, 'name')) !!}, {!! json_encode(array_map('intval', array_column($equipmentChart, 'total'))) !!});</script>

@endsection
