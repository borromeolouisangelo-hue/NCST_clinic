{{--
    Staff - Account Settings - exact port of staff/settings.php (original
    lines 51-100): the UNCONDITIONAL top-level error block (the staff page
    has no modal - errors render exactly once right under the topbar), the
    Account card (username/email/role badge/account type/status read
    straight from users.*), the Security card with the INLINE password form
    (form_action=password, three password fields, the bi-save Update
    Password button). The page never closes the shell itself - the shared
    layout emits the closers after the yield, exactly like the reference's
    layout_dashboard_end.php include.

    Approved differences (Phase 20 conventions): the original soft-fails an
    invalid CSRF into $errors (line 22-24) - the port's global middleware
    answers the token-less POST with 419; password_hash()/password_verify()
    become Hash::make()/Hash::check(); $errors renders as $settingsErrors so
    the shared ViewErrorBag stays intact; role_badge_label() (session-based)
    is $shell->roleBadge (ShellContext::badgeFor is the byte-for-byte port);
    the reference's csrf_token input becomes @csrf + a compensating blank
    line (full_diff strips token inputs from both sides).

    Legacy quirks kept: only form_action=password runs the handler, the
    three validation messages append in the original's exact order, password
    fields are read RAW (no trim) and an empty/short new password still runs
    the mismatch comparison.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
@foreach ($settingsErrors as $err)
    <div class="alert alert-danger py-2">{{ $err }}</div>
@endforeach

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-person-badge me-1"></i> Account</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Username</dt>
                    <dd class="col-sm-8">{{ $data['username'] ?? '' }}</dd>
                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">{{ $data['email'] ?? '' }}</dd>
                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8">{{ $shell->roleBadge }}</dd>
                    <dt class="col-sm-4">Account Type</dt>
                    <dd class="col-sm-8 text-capitalize">{{ $data['account_type'] ?? '' }}</dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8 text-capitalize">{{ $data['status'] ?? '' }}</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-shield-lock me-1"></i> Security</div>
            <div class="card-body">
                <form method="post" data-swal-save="password">
                    @csrf

                    <input type="hidden" name="form_action" value="password">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" minlength="6" required>
                        <div class="form-text">Must be at least 6 characters.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
