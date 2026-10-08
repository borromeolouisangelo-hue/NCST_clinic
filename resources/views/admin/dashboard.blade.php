{{--
    Admin dashboard - exact port of admin/dashboard.php content (lines 59-170),
    including the page's own closing block (the original closes
    .content-area / main / .app-wrapper itself before requiring the shared
    layout end include). Content indents and the combined if/else/endforeach
    emission rules match the original byte-for-byte.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="metric-card">
                        <div class="metric-icon icon-primary"><i class="bi bi-people"></i></div>
                        <div class="metric-value">{{ $totalUsers }}</div>
                        <div class="metric-label">Students / Employees</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="metric-card">
                        <div class="metric-icon icon-success"><i class="bi bi-person-badge"></i></div>
                        <div class="metric-value">{{ $totalStaff }}</div>
                        <div class="metric-label">Clinic Staff</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="metric-card">
                        <div class="metric-icon icon-warning"><i class="bi bi-capsule"></i></div>
                        <div class="metric-value">{{ $totalMeds }}</div>
                        <div class="metric-label">Active Medicines</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="metric-card">
                        <div class="metric-icon icon-primary"><i class="bi bi-activity"></i></div>
                        <div class="metric-value">{{ $todayRequests }}</div>
                        <div class="metric-label">Requests Today</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-triangle text-accent me-1"></i> Low Stock</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Medicine</th><th class="text-end">Stock</th><th class="text-end">Threshold</th></tr></thead>
                                <tbody>
                                @if (! $lowStock)
                                    <tr><td colspan="3" class="text-center text-muted py-3">No low-stock items.</td></tr>
                                @else
@foreach ($lowStock as $r)
                                    <tr>
                                        <td>{{ $r['name'] }}</td>
                                        <td class="text-end"><span class="badge bg-danger">{{ (int) $r['stock'] }}</span></td>
                                        <td class="text-end">{{ (int) $r['low_stock_threshold'] }}</td>
                                    </tr>
                                @endforeach
@endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-calendar-x text-accent me-1"></i> Expiring Within 90 Days</div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="text-end">Qty</th></tr></thead>
                                <tbody>
                                @if (! $expiring)
                                    <tr><td colspan="4" class="text-center text-muted py-3">No batches expiring soon.</td></tr>
                                @else
@foreach ($expiring as $r)
                                    <tr>
                                        <td>{{ $r['medicine_name'] }}</td>
                                        <td>{{ $r['batch_no'] ?: '—' }}</td>
                                        <td>{{ fmt_dt($r['expiry_date'], 'M d, Y') }}</td>
                                        <td class="text-end">{{ (int) $r['quantity'] }}</td>
                                    </tr>
                                @endforeach
@endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-clock-history me-1"></i> Recent Activity</span>
                            <a href="{{ url('admin/audit_logs') }}" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
                                <tbody>
                                @if (! $recent)
                                    <tr><td colspan="4" class="text-center text-muted py-3">No activity yet.</td></tr>
                                @else
@foreach ($recent as $r)
                                    <tr>
                                        <td>{{ fmt_dt($r['created_at']) }}</td>
                                        <td>{{ $r['username'] ?: 'system' }}</td>
                                        <td><span class="chip">{{ $r['action'] }}</span></td>
                                        <td>{{ $r['details'] }}</td>
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

        <!-- Feature 10: Dashboard Calendar -->
        @include('layouts.partials.calendar', ['calendarEvents' => $calendarEvents ?? []])

        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection