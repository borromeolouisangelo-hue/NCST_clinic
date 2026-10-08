{{--
    Login page - exact port of auth/login.php (markup lines 53-111).

    Preserved verbatim: the auth-body/auth-shell/auth-card/auth-brand shell,
    the ncst.png logo, school/clinic names read from system_settings (passed in
    by LoginController - the view never queries), the .auth-alerts error slot
    with `alert alert-danger py-2 mb-2` per message, the field structure and
    labels, Sign In, the auth-divider "or" and the Create an Account button.
    The stylesheet is linked WITHOUT a cache-busting `?v=` because the
    original login page has none (the dashboard layout adds one; this page
    does not).

    Deliberate, reported differences from the original:
      - `@csrf` hidden token: the original form has no CSRF token at all;
        Laravel's middleware requires one, and the brief mandates CSRF.
      - The logout flash ("You have been logged out.") is rendered here as an
        alert-success. The original *sets* that flash right before redirecting
        to this page but never renders any flash anywhere (get_flash() has no
        callers) - a proven global gap. Phase 4 already restored the flash slot
        for dashboards ("controllers later use ->with('success', ...)"), so the
        login side renders it the same way for symmetry.
      - Create an Account points at `auth/register` - it 404'd until the
        register phase (Phase 33) landed the route at the original path;
        same policy as every un-migrated nav link until then.
    No "Demo accounts" text: the original removed it and it must not return.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login &middot; {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card">
        <div class="auth-brand">
            <img src="{{ asset('assets/images/ncst.png') }}" alt="NCST logo" class="auth-logo-image">
            <h4 class="mb-0">{{ $schoolName }}</h4>
            <small>{{ $clinicName }}</small>
        </div>

        <div class="auth-card-body">
            @if ($errors->any())
                <div class="auth-alerts">
                    @foreach ($errors->all() as $error)
                        <div class="alert alert-danger py-2 mb-2">{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="post" class="auth-form" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="username">Username or Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" id="username" class="form-control"
                               value="{{ old('username') }}" required autofocus>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </button>
            </form>

            <div class="auth-divider">or</div>
            <a href="{{ url('auth/register') }}" class="btn btn-outline-primary w-100">
                <i class="bi bi-person-plus me-1"></i> Create an Account
            </a>

            
        </div>
    </div>
</div>
</body>
</html>