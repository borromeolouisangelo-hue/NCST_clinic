{{--
    Admin - User account management - exact port of admin/users.php
    (original lines 164-341): the edit-form branch (back link, error list,
    card form with role/account-type/status selects and conditional password
    label) and the list branch (GET search/filter toolbar, users table with
    per-row chips/badges/actions, the New User modal with its error loop and
    posted-value prefills, the modal auto-reopen script and its reset
    script), then the page-specific shell closers.

    Emission notes:
    - The csrf_token hidden input becomes the framework field (Phase 6); the
      blank line after it compensates PHP eating one newline after the
      compiled field line, exactly like the reference's inline echo.
    - $errors of the reference is $userErrors here (Laravel always injects
      its own $errors bag into views), $_POST prefills are $oldInput, and
      $_GET['role'] is $rawRole - the RAW query value, preserving the
      reference's split where SQL filters on the whitelisted raw value while
      the options' selected-state also compare it raw.
    - The toggle and New User modal forms carry no token field in the
      reference either (main.js injects none) - markup kept verbatim; the
      global CSRF middleware answers 419 on those POSTs where the reference
      soft-failed into flash + redirect (approved Phase 6 strengthening).
    - The closers stay inside the view before endsection: the reference page
      closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            @if ($action === 'edit')
                <div class="mb-3">
                    <a href="{{ url('admin/users') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back to list</a>
                </div>

                @foreach ($userErrors as $err)
                    <div class="alert alert-danger py-2">{{ $err }}</div>
                @endforeach

                <div class="card">
                    <div class="card-header">{{ $action === 'edit' ? 'Edit User' : 'Create User' }}</div>
                    <div class="card-body">
                        <form method="post" data-confirm="Save this user?">
                            <input type="hidden" name="form_action" value="save">
                            <input type="hidden" name="id" value="{{ (int)($editUser['id'] ?? 0) }}">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" name="first_name" class="form-control" value="{{ $editUser['first_name'] ?? '' }}" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" value="{{ $editUser['last_name'] ?? '' }}" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-control" value="{{ $editUser['username'] ?? '' }}" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-control" value="{{ $editUser['email'] ?? '' }}" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Role *</label>
                                    <select name="role" class="form-select">
                                        @foreach (['user' => 'User', 'staff' => 'Clinic Staff', 'admin' => 'Administrator'] as $val => $lbl)
                                            <option value="{{ $val }}" {{ ($editUser['role'] ?? 'user') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Account Type</label>
                                    <select name="account_type" class="form-select">
                                        <option value="student" {{ ($editUser['account_type'] ?? 'student') === 'student' ? 'selected' : '' }}>Student</option>
                                        <option value="employee" {{ ($editUser['account_type'] ?? '') === 'employee' ? 'selected' : '' }}>Employee</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Status</label>
                                    <select name="status" class="form-select">
                                        <option value="active" {{ ($editUser['status'] ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                                        <option value="inactive" {{ ($editUser['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Password {{ $action === 'edit' ? '(leave blank to keep)' : '*' }}</label>
                                    <input type="password" name="password" class="form-control" {{ $action === 'new' ? 'required' : '' }}>
                                </div>
                            </div>
                            @csrf

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save</button>
                                <a href="{{ url('admin/users') }}" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

            @else

            <div class="row">
                <div class="col-12">
                    <div class="card mb-3 mt-3">
                        <div class="card-body">
                            <form method="get" class="row g-2 align-items-end audit-toolbar">
                            <div class="col">
                                <label class="form-label">Search</label>
                                <input type="search" name="q" class="form-control form-control-sm" value="{{ $search }}" placeholder="Name, username or email" aria-label="Search users">
                            </div>
                            <div class="col-auto">
                                <label class="form-label">Filter</label>
                                <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">All Roles</option>
                                    <option value="user" {{ $rawRole === 'user' ? 'selected' : '' }}>User</option>
                                    <option value="staff" {{ $rawRole === 'staff' ? 'selected' : '' }}>Clinic Staff</option>
                                    <option value="admin" {{ $rawRole === 'admin' ? 'selected' : '' }}>Administrator</option>
                                </select>
                            </div>
                            <div class="col d-flex flex-wrap gap-2 justify-content-start">
                                <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>

                                <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#newUserModal"><i class="bi bi-plus-lg me-1"></i> New User</button>
                            </div>
                        </form> 
                    </div>
                </div>
                <div class="card">
                    <div class="table-responsive">
                        <table id="usersTable" class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr><th>#</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Type</th><th>Status</th><th>Session</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            @if (!$users)
                                <tr><td colspan="9" class="text-center text-muted py-4">No users found.</td></tr>
                            @else
@foreach ($users as $usr)
                                <tr>
                                    <td>{{ (int)$usr['id'] }}</td>
                                    <td>{{ trim(($usr['first_name'] ?? '') . ' ' . ($usr['last_name'] ?? '')) ?: '—' }}</td>
                                    <td>{{ $usr['username'] }}</td>
                                    <td>{{ $usr['email'] }}</td>
                                    <td><span class="chip">{{ role_label($usr['role']) }}</span></td>
                                    <td>{{ ucfirst($usr['account_type']) }}</td>
                                    <td>
                                        @if ($usr['status'] === 'active')
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($usr['is_logged_in'] ?? 0)
                                            <span class="badge bg-success">Logged In</span>
                                        @else
                                            <span class="badge bg-danger">Logged Out</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ url('admin/users?action=edit&id=' . $usr['id']) }}" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_action" value="toggle">
                                            <input type="hidden" name="id" value="{{ (int)$usr['id'] }}">
                                            <button class="btn btn-sm btn-outline-secondary" title="Toggle status"><i class="bi bi-power"></i></button>
                                        </form>
                                        <a href="{{ url('admin/users?action=delete&id=' . $usr['id']) }}" class="btn btn-sm btn-outline-danger" data-confirm="Delete this user? This cannot be undone." data-swal-delete="user"><i class="bi bi-trash"></i></a>
                                    </td>
                                </tr>
                            @endforeach
@endif
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal fade" id="newUserModal" tabindex="-1" aria-labelledby="newUserModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title" id="newUserModalLabel"><i class="bi bi-person-plus me-1"></i> Create User</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <form method="post" data-confirm="Save this user?">
                            <input type="hidden" name="form_action" value="save">
                            <input type="hidden" name="id" value="0">
                            <div class="modal-body">
                                @foreach ($userErrors as $err)<div class="alert alert-danger py-2">{{ $err }}</div>@endforeach
                                <div class="row g-2">
                                    <div class="col-md-6"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" value="{{ $oldInput['first_name'] ?? '' }}" required></div>
                                    <div class="col-md-6"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" value="{{ $oldInput['last_name'] ?? '' }}" required></div>
                                    <div class="col-md-6"><label class="form-label">Username *</label><input type="text" name="username" class="form-control" value="{{ $oldInput['username'] ?? '' }}" required></div>
                                    <div class="col-md-6"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" value="{{ $oldInput['email'] ?? '' }}" required></div>
                                    <div class="col-md-4"><label class="form-label">Role *</label><select name="role" class="form-select"><option value="user" {{ ($oldInput['role'] ?? 'user') === 'user' ? 'selected' : '' }}>User</option><option value="staff" {{ ($oldInput['role'] ?? '') === 'staff' ? 'selected' : '' }}>Clinic Staff</option><option value="admin" {{ ($oldInput['role'] ?? '') === 'admin' ? 'selected' : '' }}>Administrator</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Account Type</label><select name="account_type" class="form-select"><option value="student" {{ ($oldInput['account_type'] ?? 'student') === 'student' ? 'selected' : '' }}>Student</option><option value="employee" {{ ($oldInput['account_type'] ?? '') === 'employee' ? 'selected' : '' }}>Employee</option></select></div>
                                    <div class="col-md-4"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" {{ ($oldInput['status'] ?? 'active') === 'active' ? 'selected' : '' }}>Active</option><option value="inactive" {{ ($oldInput['status'] ?? '') === 'inactive' ? 'selected' : '' }}>Inactive</option></select></div>
                                    <div class="col-12"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" minlength="6" required></div>
                                </div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" data-swal-save="user"><i class="bi bi-save me-1"></i>Save User</button></div>
                        </form>
                    </div></div>
                </div>
                @if ($action === 'new' || $userErrors)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('newUserModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modal = document.getElementById('newUserModal');
                    if (!modal) return;
                    modal.addEventListener('hidden.bs.modal', function () {
                        var form = modal.querySelector('form');
                        if (form) form.reset();
                    });
                });
                </script>
            @endif
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection
