{{--
    Staff - Reports - exact port of staff/reports.php (original lines 79-226):
    the report heading (clinic name + period line), the three metric cards,
    the GET period form (period select with selected-state, From/To date
    inputs, Print + CSV buttons), the trend line card with its period
    sidebar, the two bar chart cards, the two reason tables and the inline
    IIFE that boots the charts from the trend ranges / item series.

    Emission notes:
    - The $topPatients query of the reference never reaches its view (dead
      query, dropped - Phase 27 $lowStock / Phase 22 $actions precedent).
    - The period form is GET; there is no CSRF surface on this page, so the
      reference's require block and the layout's closers carry the shell.
    - The two table bodies are fused if/else/foreach one-liners in the
      reference: @if keeps its single line (the line ends in plain markup,
      as the original does), while the else+foreach+endforeach+endif line
      splits into @else at 24sp, @foreach at 0sp and @endif at 0sp - every
      split boundary is consumed by a compiled tag that eats its newline,
      so both the empty and the populated case merge byte-identically with
      the reference (Phase 23/25 split convention).
    - Chart JSON stays RAW through {!! !!}: the reference echoes
      json_encode() unescaped (the ranges keep their JSON_HEX_TAG/JSON_HEX_AMP
      flags exactly as passed). htmlspecialchars would corrupt the JS.
    - The page never closes the shell: the layout emits the shell closers
      after this yield (reference ends at the script block, then requires
      layout_dashboard_end.php).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

<div>
    <div class="mb-3">
        <h5 class="text-primary mb-0">{{ get_setting('clinic_name', 'School Health Clinic') }}</h5>
        <div class="text-muted small">Report period: {{ fmt_dt($from, 'M d, Y') }} to {{ fmt_dt($to, 'M d, Y') }}</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-4"><div class="metric-card"><div class="metric-icon icon-primary"><i class="bi bi-clipboard2-pulse"></i></div><div class="metric-value">{{ $consCount }}</div><div class="metric-label">Consultations</div></div></div>
        <div class="col-6 col-lg-4"><div class="metric-card"><div class="metric-icon icon-warning"><i class="bi bi-capsule"></i></div><div class="metric-value">{{ $requestCount }}</div><div class="metric-label">Medicine Units Requested</div></div></div>
        <div class="col-6 col-lg-4"><div class="metric-card"><div class="metric-icon icon-success"><i class="bi bi-tools"></i></div><div class="metric-value">{{ $loanCount }}</div><div class="metric-label">Equipment Units Borrowed</div></div></div>
    </div>

<div class="card mb-3 no-print">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end" data-auto-submit>
            <div class="col-md-4"><label class="form-label">Reporting period</label><select name="period" class="form-select"><option value="daily" {{ $period === 'daily' ? 'selected' : '' }}>Daily</option><option value="weekly" {{ $period === 'weekly' ? 'selected' : '' }}>Weekly</option><option value="monthly" {{ $period === 'monthly' ? 'selected' : '' }}>Monthly</option><option value="yearly" {{ $period === 'yearly' ? 'selected' : '' }}>Yearly</option></select></div>
            <div class="col-md-4"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="{{ $from }}"></div>
            <div class="col-md-4"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="{{ $to }}"></div>
            <div class="col-md-12 d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" data-export-table="#staffReportTable" data-export-name="clinic-staff-report"><i class="bi bi-download me-1"></i> CSV</button>
            </div>
        </form>
    </div>
</div>

    <!-- Charts: same format, layout and markup as staff/dashboard.php -->
    <div class="row g-2 mb-3 staff-dashboard-charts">
        <div class="col-12">
            <div class="card chart-card staff-chart-card staff-line-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="bi bi-graph-up me-1"></i> Total requests over time
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <div class="chart-legend">
                            <span class="legend-item"><span class="legend-dot" style="background:#2f7bbd"></span> Requests</span>
                            <span class="legend-item"><span class="legend-dot" style="background:#4a9f86"></span> Consultations</span>
                        </div>
                        <select class="form-select form-select-sm chart-period-select" style="width:auto">
                            <option value="7" selected>Last 7 days</option>
                            <option value="14">Last 14 days</option>
                            <option value="30">Last 30 days</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="d-flex gap-3">
                        <div class="flex-grow-1">
                            <canvas id="requestTrendChart" class="dashboard-chart" width="1040" height="240"></canvas>
                        </div>
                        <div class="period-sidebar">
                            <div class="period-total-label">Period Total</div>
                            <div class="period-total-value" id="periodTotalValue">{{ $periodTotal }}</div>
                            <div class="period-total-sub">requests logged</div>
                            <div class="period-change {{ $periodChange >= 0 ? 'text-success' : 'text-danger' }}" id="periodChange">
                                <i class="bi bi-arrow-{{ $periodChange >= 0 ? 'up' : 'down' }}"></i> {{ abs($periodChange) }}%
                            </div>
                            <div class="period-change-label">vs previous period</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-3 staff-dashboard-charts">
        <div class="col-lg-6">
            <div class="card chart-card staff-chart-card h-100">
                <div class="card-header"><i class="bi bi-capsule me-1"></i> Frequently Requested Medicines</div>
                <div class="card-body"><canvas id="requestedItemsChart" class="dashboard-chart" width="520" height="150"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card chart-card staff-chart-card h-100">
                <div class="card-header"><i class="bi bi-tools me-1"></i> Frequently Borrowed Equipment</div>
                <div class="card-body"><canvas id="borrowedItemsChart" class="dashboard-chart" width="520" height="150"></canvas></div>
            </div>
        </div>
    </div>

    <div class="row g-3" id="staffReportTable">
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
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Equipment</th><th>Reason</th><th class="text-end">Qty</th></tr></thead>
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
    </div>
</div>
    <script src="{{ url('assets/js/charts.js') }}"></script>
    <script>
    (function () {
        var ranges = {!! json_encode($trendRanges, JSON_HEX_TAG | JSON_HEX_AMP) !!};
        var requestedLabels = {!! json_encode(array_column($requestedItems, 'name'), JSON_HEX_TAG | JSON_HEX_AMP) !!};
        var requestedTotals = {!! json_encode(array_map('intval', array_column($requestedItems, 'total_quantity'))) !!};
        var borrowedLabels  = {!! json_encode(array_column($borrowedItems, 'name'), JSON_HEX_TAG | JSON_HEX_AMP) !!};
        var borrowedTotals  = {!! json_encode(array_map('intval', array_column($borrowedItems, 'total_quantity'))) !!};

        function renderTrend(days) {
            var range = ranges[String(days)] || ranges['7'];
            if (!range) return;
            drawLineChart('requestTrendChart', range.labels, range.requests, { secondaryData: range.consultations });

            var totalEl = document.getElementById('periodTotalValue');
            if (totalEl) totalEl.textContent = range.total;

            var changeEl = document.getElementById('periodChange');
            if (changeEl) {
                var up = range.change >= 0;
                changeEl.className = 'period-change ' + (up ? 'text-success' : 'text-danger');
                changeEl.innerHTML = '<i class="bi bi-arrow-' + (up ? 'up' : 'down') + '"></i> ' + Math.abs(range.change) + '%';
            }
        }

        renderTrend({{ (int) $days }});
        drawHorizontalBarChart('requestedItemsChart', requestedLabels, requestedTotals, { barColor: '#1a56a8' });
        drawHorizontalBarChart('borrowedItemsChart', borrowedLabels, borrowedTotals, { barColor: '#7950f2' });

        var select = document.querySelector('.chart-period-select');
        if (select) {
            select.addEventListener('change', function () { renderTrend(parseInt(this.value, 10) || 7); });
        }
    })();
    </script>
@endsection
