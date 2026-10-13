{{--
    Staff dashboard - exact port of staff/dashboard.php (original lines
    118-303): KPI stat cards with trend badges, the line-chart card with its
    period sidebar, the two bar-chart cards, the recent-activity preview
    cards (10 newest rows per history section, each with an above-the-card
    View All button to its dedicated page), and the inventory alert cards
    side by side: the low stock alerts table and the Medicine Expiring
    Soon table (batches expiring within the next 7 days, inclusive).

    The scripts block sits at the end of the content area exactly like the
    original (lines 267-301): charts.js then the inline init, both before
    the layout's main.js - same load order as layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="staff-dashboard-shell">
                

                <!-- KPI Stat Cards -->
                <div class="row g-3 mb-4 staff-kpi-row">
                    <div class="col-6 col-lg-3">
                        <div class="metric-card">
                            <div class="metric-icon icon-primary"><i class="bi bi-activity"></i></div>
                            <div class="metric-value">{{ $todayRequests }}</div>
                            <div class="metric-label">Requests Today</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric-card">
                            <div class="metric-icon icon-success"><i class="bi bi-heart-pulse"></i></div>
                            <div class="metric-value">{{ $todayCons }}</div>
                            <div class="metric-label">Consultations Today</div>
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric-card">
                            <div class="metric-icon icon-primary"><i class="bi bi-arrow-left-right"></i></div>
                            <div class="metric-value">{{ $activeLoans }}</div>
                            <div class="metric-label">Active Loans</div>
                            @if ($loanTrend['pct'] !== null)
                                <span class="trend-badge trend-{{ $loanTrend['dir'] }}"><i class="bi bi-arrow-{{ $loanTrend['dir'] }}"></i> {{ $loanTrend['dir'] === 'up' ? '+' : '-' }}{{ $loanTrend['pct'] }}%</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <div class="metric-card">
                            <div class="metric-icon icon-info"><i class="bi bi-clipboard2-heart"></i></div>
                            <div class="metric-value">{{ $pendingSickLeaves }}</div>
                            <div class="metric-label">Sick Leave Requests</div>
                        </div>
                    </div>
                </div>

                <!-- Overview row: line chart on the left, compact mini
                     calendar on the right. The calendar HTML is written
                     directly below (same card structure as the chart card)
                     instead of the shared layouts/partials/calendar partial,
                     but it keeps the shared calendar.js contract - one
                     #dashboardCalendar element initialized once - and the
                     View Calendar button opens route('staff.calendar'), the
                     full month/week/list page. Height matching: the mini
                     calendar has a definite compact height (calendar.js:
                     height 380 + expandRows + dayMaxEvents auto-fit), so it
                     sets the row height and can never be inflated by busy
                     months; the chart card fills the same height
                     (charts.js fitCanvasToDisplaySize + the scoped rules in
                     style.css), so both cards match exactly with neither
                     clipped nor left with dead space. Below xl the columns
                     stack. -->
                <div class="row g-2 mb-3 staff-dashboard-charts">
                    <div class="col-12 col-xl-8">
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
                    
                    <div class="col-12 col-xl-4 d-flex">
                  
                        @php
                            $dashboardEventCount = count($calendarEvents ?? []);
                        @endphp
                        @if ($dashboardEventCount > 0)
                            {{-- Both cards carry h-100: the compact
                                 calendar (content-sized, never scrolling)
                                 is the shorter side, so it stretches to the
                                 chart card's exact height in this flex
                                 column. --}}
                            <div class="card dashboard-calendar-card clinic-calendar-card clinic-calendar-card--mini h-100 w-100">
                                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                                    <div class="clinic-calendar-title">
                                        <i class="bi bi-calendar-event me-1"></i> Staff Calendar
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <small class="text-muted">{{ $dashboardEventCount }} event{{ $dashboardEventCount !== 1 ? 's' : '' }}</small>
                                        <a href="{{ route('staff.calendar') }}" class="btn btn-sm btn-outline-primary">View Calendar</a>
                                    </div>
                                </div>
                                <div class="card-body clinic-calendar-body">
                                    <div id="dashboardCalendar"
                                         class="clinic-calendar js-clinic-calendar clinic-calendar--mini"
                                         data-clinic-calendar
                                         data-mode="mini"
                                         data-events="{{ json_encode($calendarEvents ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Bottom Charts -->
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

                
                

                <!-- Inventory alerts: Low Stock Alerts and Medicine Expiring
                     Soon share one row. Both cards use col-lg-6 + h-100
                     (the same side-by-side pair pattern as the admin
                     dashboard's Low Stock / Expiring Within 90 Days cards),
                     so they split the row width evenly on lg+ and stack
                     full-width below it. -->
                <div class="row g-3">
                    <div class="col-12 col-lg-6 d-flex">
                        <div class="card h-100 w-100">
                            <div class="card-header"><i class="bi bi-exclamation-triangle text-accent me-1"></i> Low Stock Alerts</div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead><tr><th>Medicine</th><th class="text-end">Stock</th><th class="text-end">Threshold</th></tr></thead>
                                    <tbody>
                                    @if (! $lowStock)
                                        <tr><td colspan="3" class="text-center text-muted py-3">All items sufficiently stocked.</td></tr>
                                    @else
                                    @foreach ($lowStock as $l)
                                        <tr><td>{{ $l['name'] }}</td><td class="text-end"><span class="badge bg-danger">{{ (int) $l['stock'] }}</span></td><td class="text-end">{{ (int) $l['low_stock_threshold'] }}</td></tr>
                                    @endforeach
                                    @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Medicine Expiring Soon: batches (expiry is tracked
                         per batch) whose expiry date is today through the
                         next 7 days, inclusive - most urgent first. Red
                         badge = 0-3 days left, orange = 4-7 days. -->
                    <div class="col-12 col-lg-6 d-flex">
                        <div class="card h-100 w-100">
                            <div class="card-header"><i class="bi bi-calendar-x text-accent me-1"></i> Medicine Expiring Soon</div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead><tr><th>Medicine Name</th><th>Days Before Expiration</th><th>Expiration Date</th></tr></thead>
                                    <tbody>
                                    @if (! $expiringSoon)
                                        <tr><td colspan="3" class="text-center text-muted py-3">No medicines expiring within the next 7 days.</td></tr>
                                    @else
                                    @foreach ($expiringSoon as $exp)
                                        @php
                                            // 0-3 days left: red (act now); 4-7 days: orange (upcoming).
                                            $expiryBadge = $exp['days_left'] <= 3 ? 'bg-danger' : 'bg-orange';
                                        @endphp
                                        <tr>
                                            <td>{{ $exp['name'] }}@if ($exp['batch_no'] !== null) <small class="text-muted d-block">Batch: {{ $exp['batch_no'] }}</small>@endif</td>
                                            <td><span class="badge {{ $expiryBadge }}">{{ $exp['days_left'] === 0 ? 'Today' : $exp['days_left'].($exp['days_left'] === 1 ? ' day' : ' days') }}</span></td>
                                            <td>{{ fmt_dt($exp['expiry_date'], 'M d, Y') }}</td>
                                        </tr>
                                    @endforeach
                                    @endif
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                
                
            </div>

    <script src="{{ asset('assets/js/charts.js') }}"></script>
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