{{--
    Admin - Audit logs - exact port of admin/audit_logs.php (original lines
    39-106): the search/filter toolbar (GET form, auto-submitting filter
    select), the audit table with its empty-state row, and the per-row action
    chip whose class/style is computed in the @php block (login -> success,
    logout -> danger, anything else -> plain chip) before the row renders.

    The closers </div><!-- /.content-area --> </main> <!-- /.main-content -->
    </div><!-- /.app-wrapper --> stay inside the view before @endsection -
    the reference page closes the shell itself before requiring
    layout_dashboard_end.php (the layout comment documents that "some admin
    pages close the shell themselves"); the browser drops the unmatched
    closers and keeps the whitespace, so the bytes must still be emitted.

    Approved differences: $rawActionFilter carries the RAW query string so
    the selected-state quirk (raw vs trimmed) is preserved; fmt_dt/e() fold
    into Blade's escaping {{ }} (same htmlspecialchars output); @php/@endphp
    render the original's inline <?php ... ?> chip computation (the 36sp
    @php lead is output, the 36sp @endphp lead is PHP code inside the block -
    neither side of the close tag emits anything, exactly like the original).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            <div class="card mt-3 mb-3">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end audit-toolbar">
                        <div class="col">
                            <label class="form-label">Search</label>
                            <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="User, action or details" aria-label="Search audit logs">
                                </div>
                                <div class="col-auto">
                                    <label class="form-label">Filter</label>
                                    <select name="action_filter" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="">All Actions</option>
                                        <option value="login" {{ $rawActionFilter === 'login' ? 'selected' : '' }}>Login</option>
                                        <option value="logout" {{ $rawActionFilter === 'logout' ? 'selected' : '' }}>Logout</option>
                                        <option value="create" {{ $rawActionFilter === 'create' ? 'selected' : '' }}>Create</option>
                                        <option value="update" {{ $rawActionFilter === 'update' ? 'selected' : '' }}>Update</option>
                                        <option value="delete" {{ $rawActionFilter === 'delete' ? 'selected' : '' }}>Delete</option>
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
                            <table id="auditLogsTable" class="table table-hover mb-0 align-middle">
                                <thead><tr><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
                                <tbody>
                                @if (!$logs)
                                    <tr><td colspan="7" class="text-center text-muted py-4">No logs found.</td></tr>
                                @else
@foreach ($logs as $l)
                                    @php
                                        $actionName = (string)($l['action'] ?? '');
                                        $actionLower = strtolower($actionName);
                                        $actionClass = 'chip';
                                        $actionStyle = '';
                                        if (strpos($actionLower, 'login') !== false) {
                                            $actionClass = 'chip chip-success';
                                            $actionStyle = 'background: rgba(25, 135, 84, 0.12); color: #146c43; border: 1px solid rgba(25, 135, 84, 0.25);';
                                        } elseif (strpos($actionLower, 'logout') !== false) {
                                            $actionClass = 'chip chip-danger';
                                            $actionStyle = 'background: rgba(220, 53, 69, 0.12); color: #b02a37; border: 1px solid rgba(220, 53, 69, 0.25);';
                                        }
                                    @endphp
                                    <tr>
                                        <td>{{ fmt_dt($l['created_at']) }}</td>
                                        <td>{{ $l['username'] ?: 'system' }}</td>
                                        <td><span class="{{ $actionClass }}" style="{{ $actionStyle }}">{{ $l['action'] }}</span></td>
                                        <td>{{ $l['table_name'] ?: '—' }}</td>
                                        <td>{{ $l['record_id'] !== null ? (int) $l['record_id'] : '—' }}</td>
                                        <td>{{ $l['details'] }}</td>
                                        <td>{{ $l['ip_address'] }}</td>
                                    </tr>
                                @endforeach
@endif
                                </tbody>
                            </table>
                        </div>
                    </div>
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection
