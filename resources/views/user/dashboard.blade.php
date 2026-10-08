{{--
    User (student / employee) dashboard - exact port of user/dashboard.php
    (original lines 80-193): medical-form and unread-notification alerts, the
    four personal metric cards, My Tasks, the employee-only Recent Sick Leave
    list and the sick-leave modal whose form self-posts to this same page.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the modal form carries @csrf instead of csrf_token (Phase 6
    convention); $_POST[...] repopulation uses request()->post(...) so only
    the request body is read, exactly like the original's $_POST access;
    the original's set_flash() success text - never rendered by the original
    because get_flash() has no callers - surfaces through the shared flash
    partial, the same convention logout/register follow since Phase 5/6.

    Task links (medical_form / my_requests / my_consultations) and the
    notifications View All target 404 until their feature phases.

    Feature 3: Added personal statistics cards below the main metric cards.
    Feature 6: Added Quick Actions grid with responsive layout.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            @if (!$hasMedRec)
                <div class="alert alert-warning d-flex align-items-center justify-content-between">
                    <div><i class="bi bi-exclamation-triangle me-1"></i> You have not submitted your medical form yet.</div>
                    <a href="{{ url('user/medical_form') }}" class="btn btn-sm btn-primary">Complete Now</a>
                </div>
            @endif
            @if ($unreadNotifCount > 0)
                <div class="alert alert-info d-flex align-items-center justify-content-between">
                    <div><i class="bi bi-bell me-1"></i> You have {{ $unreadNotifCount }} unread notification{{ $unreadNotifCount > 1 ? 's' : '' }}.</div>
                    <a href="{{ url('notifications') }}" class="btn btn-sm btn-primary">View All</a>
                </div>
            @endif

            <div class="row g-3 mb-4">
                <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-primary"><i class="bi bi-activity"></i></div><div class="metric-value">{{ $reqCount }}</div><div class="metric-label">Total Requests</div></div></div>
                <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-warning"><i class="bi bi-tools"></i></div><div class="metric-value">{{ $loanCount }}</div><div class="metric-label">Total Loans</div></div></div>
                <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-success"><i class="bi bi-clipboard2-pulse"></i></div><div class="metric-value">{{ $consultationCount }}</div><div class="metric-label">Consultations</div></div></div>
                <div class="col-6 col-lg-3"><div class="metric-card"><div class="metric-icon icon-primary"><i class="bi bi-arrow-left-right"></i></div><div class="metric-value">{{ $activeLoanCount }}</div><div class="metric-label">Active Loans</div></div></div>
            </div>

            <!-- Feature 3: Additional Personal Statistics -->
            

            <!-- Sick Leave Statistics (for employees) -->
            

            <!-- Feature 6: Quick Actions Grid -->
            

            <div class="row g-3">
                <div class="{{ ($isEmployee && $recentSickLeaves->isNotEmpty()) ? 'col-lg-6' : 'col-12' }}">
                    <div class="card h-100">
                        <div class="card-header">My Tasks</div>
                        <div class="card-body d-grid gap-2">
                            <a href="{{ url('user/medical_form') }}" class="btn btn-outline-primary"><i class="bi bi-file-medical me-1"></i> Update Medical Form</a>
                            <a href="{{ url('user/my_requests') }}" class="btn btn-outline-primary"><i class="bi bi-activity me-1"></i> Open My Activity</a>
                            
                            @if ($isEmployee)
                                <button type="button" class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#sickLeaveModal"><i class="bi bi-thermometer me-1"></i> Notify Staff (Sick Leave)</button>
                            @endif
                            <div class="alert alert-info small mb-0">Medicine requests are entered by clinic staff when you visit the clinic in person.</div>
                            @if ($allergyCount === 0)
                                <div class="alert-allergy p-2 small mb-0">
                                    <i class="bi bi-info-circle"></i> You have no recorded allergies. Add them in your Medical Form so staff can be alerted before dispensing.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($isEmployee && $recentSickLeaves->isNotEmpty())
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-clock-history me-1"></i> Recent Sick Leave</div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                @foreach ($recentSickLeaves as $sl)
                                    <li class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <small class="text-muted">{{ fmt_dt($sl->created_at, 'M d, Y g:i A') }}</small>
                                            <div>{{ mb_strimwidth((string) $sl->reason, 0, 60, '…') }}</div>
                                            <small class="text-muted">
                                                {{ date('M d, Y', strtotime($sl->start_date)) }}                                                @if ($sl->end_date) – {{ date('M d, Y', strtotime($sl->end_date)) }}                                                @endif                                            </small>
                                        </div>
                                        @if ($sl->status === 'pending')
                                            <span class="badge bg-warning text-dark">Pending</span>
                                        @elseif ($sl->status === 'approved')
                                            <span class="badge bg-success">Approved</span>
                                        @else
                                            <span class="badge bg-danger">Declined</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            <a href="{{ url('user/my_requests') }}" class="btn btn-sm btn-outline-primary mt-2">View Full History</a>
                        </div>
                    </div>
                </div>
                @endif
            </div>

            <!-- Sick Leave Modal -->
            @if ($isEmployee)
            <div class="modal fade" id="sickLeaveModal" tabindex="-1" aria-labelledby="sickLeaveModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-md">
                    <div class="modal-content">
                        <div class="card">
                            <div class="card-header" id="sickLeaveModalHeader"><i class="bi bi-thermometer me-1"></i> Notify Staff — Sick Leave</div>
                            <div class="card-body">
                                @foreach ($sickLeaveErrors as $err)
                                    <div class="alert alert-danger py-2">{{ $err }}</div>
                                @endforeach
                                <form method="post">
                                    @csrf

                                    <input type="hidden" name="form_action" value="sick_leave">
                                    <div class="mb-3">
                                        <label class="form-label">Reason for Leave *</label>
                                        <textarea name="reason" class="form-control" rows="4" required placeholder="Describe your symptoms or reason for sick leave...">{{ request()->post('reason') }}</textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Start Date *</label>
                                        <input type="date" name="start_date" class="form-control" value="{{ request()->post('start_date') }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">End Date <span class="text-muted">(optional)</span></label>
                                        <input type="date" name="end_date" class="form-control" value="{{ request()->post('end_date') }}">
                                        <div class="form-text">Leave blank if returning the same day.</div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-accent" data-swal-add="sick leave"><i class="bi bi-send me-1"></i> Notify Staff</button>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @if ($sickLeaveErrors)<script>document.addEventListener('DOMContentLoaded', function() { var modal = document.getElementById('sickLeaveModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
            @endif

        <!-- Feature 10: Dashboard Calendar -->
        @include('layouts.partials.calendar', ['calendarEvents' => $calendarEvents ?? []])

@endsection