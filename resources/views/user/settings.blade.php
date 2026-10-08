{{--
    Account Settings - identity + password security, exact port of
    user/settings.php (original lines 52-131): the top-level alert block
    (rendered ONLY when the submit did not come from the modal), the
    Account card (username/email/role badge/account type/status read
    straight from users.*), the Security card that opens the password
    modal, the modal's own error block + change-password form, and the
    bottom script that reopens the modal after a failed submit.

    Approved differences: the original soft-failed an invalid CSRF into
    $errors (lines 21-23, which also short-circuited the current-password
    verify at line 34) - the port's global middleware answers the same
    token-less POST with 419 (Phase 6 strengthening); password_hash()
    becomes Hash::make() and password_verify() becomes Hash::check();
    the $errors array renders as $settingsErrors so the shared
    ViewErrorBag stays intact; role_badge_label() (session-based) is
    $shell->roleBadge (ShellContext::badgeFor is the byte-for-byte port).

    Legacy quirks kept: only the password handler ever collects errors
    (form_action=password), so the top block never renders and the modal
    block + reopen script always do; the three validation messages append
    in the original's exact order; password fields are read RAW (no trim)
    and an empty/short new password still runs the mismatch comparison;
    the reopen condition is the original's in_array('password',
    [form_action]) idiom over $formAction.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
@if (!$reopen)
@foreach ($settingsErrors as $err)
        <div class="alert alert-danger py-2">{{ $err }}</div>
    @endforeach
@endif

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
                <p class="text-muted">Your password is the only credential used to sign in to this account. Change it regularly and do not reuse it elsewhere.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#passwordModal"><i class="bi bi-key me-1"></i> Change Password</button>
            </div>
        </div>
    </div>
</div>

<!-- Password Reset Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="card">
                <div class="card-header" id="passwordModalHeader"><i class="bi bi-shield-lock me-1"></i> Reset Password</div>
                <div class="card-body">
                    @if (!empty($settingsErrors))
                        @foreach ($settingsErrors as $err)
                            <div class="alert alert-danger py-2">{{ $err }}</div>
                        @endforeach
                    @endif
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
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Update Password</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@if ($settingsErrors && in_array('password', [$formAction]))
<script>document.addEventListener('DOMContentLoaded', function() { var modal = document.getElementById('passwordModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>
@endif
@endsection
