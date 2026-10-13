{{--
    Account Settings - Feature 5's merge of Profile + Account Settings.

    One page, two sections, one nav: the sidebar used to carry separate
    "Profile" (user/profile) and "Account Settings" (<role>/settings) items
    for every role, both landing on copies of the same account card. The
    nav pills below are the seam between them - server-rendered ?tab= links
    in the exact pattern user/my_requests.blade.php already uses, so they
    work without JavaScript and are bookmarkable.

    Only the ACTIVE section renders, so:
      * Profile  - the profile picture card + the untouched identity form
                   (byte-identical markup, pinned by ProfileTest).
      * Security - the account card + the password form the settings pages
                   used to own, now driven by the shared PasswordChange
                   rule set.

    Legacy quirks kept: only first/last name are required (both messages
    in original order), gender whitelists to male/female/other and stores
    NULL otherwise, an empty birthdate stores NULL, both detail-row UPDATEs
    run trimmed and the non-student branch is a silent no-op for accounts
    without an employee_details row, the readonly no./student no. inputs
    keep no name attribute (never posted), and a failed submit re-renders
    the STORED values (the row is read before the POST handling).

    The picture lives in its own multipart form so the identity form needs
    no enctype and keeps its exact `<form method="post"
    data-swal-save="profile">` opening. The picture card now leads with the
    avatar beside the user's name and account information (a 64-80 px
    circle with a default-avatar fallback for a missing or broken image),
    and picking a file opens an inline square crop stage - drag to
    reposition, slider to zoom, live circular preview - whose 1:1 canvas
    output is what the form posts. The server stays authoritative
    (JPG/JPEG/PNG content sniff, 2 MB ceiling, dimension band in
    ProfileService::uploadPhoto), and the script degrades to a plain
    upload when canvas APIs are unavailable.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            @foreach ($profileErrors as $err)
                <div class="alert alert-danger py-2">{{ $err }}</div>
            @endforeach
            @foreach ($settingsErrors as $err)
                <div class="alert alert-danger py-2">{{ $err }}</div>
            @endforeach

            @include('layouts.partials.flash-toast')

            <ul class="nav nav-pills mb-3" aria-label="Account settings sections">
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'profile' ? 'active' : '' }}" href="{{ url('user/profile') }}"><i class="bi bi-person me-1"></i> Profile</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $activeTab === 'security' ? 'active' : '' }}" href="{{ url('user/profile?tab=security') }}"><i class="bi bi-shield-lock me-1"></i> Security</a>
                </li>
            </ul>

            @if ($activeTab === 'profile')
            <div class="row g-3">
                <div class="col-12">
                    <div class="card mb-3">
                        <div class="card-header"><i class="bi bi-person-vcard me-1"></i> Profile Picture</div>
                        <div class="card-body">
                            @foreach ($photoErrors as $err)
                                <div class="alert alert-danger py-2">{{ $err }}</div>
                            @endforeach
                            <div id="profilePhotoIdentity" class="d-flex align-items-center gap-3">
                                @if ($photoUrl !== '')
                                    <img src="{{ $photoUrl }}" alt="{{ $shell->fullName }}" class="account-avatar account-avatar-lg" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex'">
                                    <span class="account-avatar account-avatar-lg account-avatar-empty" style="display:none"><i class="bi bi-person"></i></span>
                                @else
                                    <span class="account-avatar account-avatar-lg account-avatar-empty"><i class="bi bi-person"></i></span>
                                @endif
                                <div>
                                    <h5 class="mb-1">{{ $shell->fullName }}</h5>
                                    <div class="text-muted small">
                                        {{ '@'.($data['username'] ?? '') }} &middot; {{ $shell->roleBadge }}@if (($data['account_type'] ?? '') !== '') &middot; <span class="text-capitalize">{{ $data['account_type'] }}</span>@endif
                                        @if (($data['account_type'] ?? '') === 'student' && ($data['student_no'] ?? '') !== '')
                                            &middot; No. {{ $data['student_no'] }}
                                        @elseif (($data['account_type'] ?? '') === 'employee' && ($data['employee_no'] ?? '') !== '')
                                            &middot; No. {{ $data['employee_no'] }}
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <form method="post" enctype="multipart/form-data" id="photoForm" class="mt-1">
                                @csrf

                                <input type="hidden" name="form_action" value="photo">
                                <label class="form-label mt-2" for="profilePhotoInput">Picture</label>
                                <input type="file" id="profilePhotoInput" name="photo" class="form-control" accept="image/jpeg,image/png">
                                <div class="form-text">JPG or PNG, up to 2 MB. Drag the picture to reposition it and use the slider to zoom; the circle shows the avatar that will be saved.</div>
                                <div id="photoCropNotice" class="alert alert-danger py-2 d-none mt-2" role="alert"></div>

                                <div id="photoCropper" class="d-none mt-3">
                                    <div class="d-flex flex-wrap align-items-start gap-3">
                                        <div class="photo-crop-stage" id="photoCropStage">
                                            <img id="photoCropImage" alt="" draggable="false">
                                            <div class="photo-crop-circle"></div>
                                        </div>
                                        <div class="text-center">
                                            <div class="photo-crop-preview"><canvas id="photoCropPreview" width="144" height="144" aria-hidden="true"></canvas></div>
                                            <div class="form-text">Preview</div>
                                        </div>
                                    </div>
                                    <label class="form-label mt-3" for="photoCropZoom"><i class="bi bi-zoom-in me-1"></i> Zoom</label>
                                    <input type="range" class="form-range" id="photoCropZoom" min="1" max="4" step="0.01" value="1">
                                </div>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <button type="submit" name="photo_action" value="upload" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i> Upload Picture</button>
                                        <button type="button" id="photoCropCancel" class="btn btn-outline-secondary btn-sm d-none"><i class="bi bi-x-lg me-1"></i> Cancel</button>
                                        @if ($photoUrl !== '')
                                            <button type="submit" name="photo_action" value="remove" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i> Remove</button>
                                        @endif
                                        
                                    </div>
                                    
                                </form>
                            </div>
                            
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><i class="bi bi-person-vcard me-1"></i> Personal Information</div>
                        <div class="card-body">
                            <form method="post" data-swal-save="profile">
                                @csrf

                                <input type="hidden" name="form_action" value="profile">
                                <div class="row g-3">
                                    <div class="col-md-4"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" value="{{ $data['first_name'] ?? '' }}" required></div>
                                    <div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control" value="{{ $data['middle_name'] ?? '' }}"></div>
                                    <div class="col-md-4"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" value="{{ $data['last_name'] ?? '' }}" required></div>
                                    <div class="col-md-3"><label class="form-label">Gender</label><select name="gender" class="form-select"><option value="">--</option>@foreach (['male','female','other'] as $g)<option value="{{ $g }}" {{ ($data['gender'] ?? '') === $g ? 'selected' : '' }}>{{ ucfirst($g) }}</option>@endforeach</select></div>
                                    <div class="col-md-3"><label class="form-label">Birthdate</label><input type="date" name="birthdate" class="form-control" value="{{ $data['birthdate'] ?? '' }}"></div>
                                    <div class="col-md-3"><label class="form-label">Contact No.</label><input type="text" name="contact_no" class="form-control" value="{{ $data['contact_no'] ?? '' }}"></div>
                                    <div class="col-md-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="{{ $data['address'] ?? '' }}"></div>
                                    <div class="col-md-6"><label class="form-label">Emergency Contact Person</label><input type="text" name="emergency_name" class="form-control" value="{{ $data['emergency_name'] ?? '' }}"></div>
                                    <div class="col-md-6"><label class="form-label">Emergency Contact Number</label><input type="text" name="emergency_contact" class="form-control" value="{{ $data['emergency_contact'] ?? '' }}"></div>
                                    @if (($data['account_type'] ?? '') === 'student')
                                        <div class="col-md-4"><label class="form-label">Student No.</label><input type="text" class="form-control" value="{{ $data['student_no'] ?? '' }}" readonly></div>
                                        <div class="col-md-4"><label class="form-label">Course</label><input type="text" name="course" class="form-control" value="{{ $data['course'] ?? '' }}"></div>
                                        <div class="col-md-2"><label class="form-label">Year Level</label><input type="text" name="year_level" class="form-control" value="{{ $data['year_level'] ?? '' }}"></div>
                                        <div class="col-md-2"><label class="form-label">Section</label><input type="text" name="section" class="form-control" value="{{ $data['section'] ?? '' }}"></div>
                                    @else
                                        <div class="col-md-4"><label class="form-label">Employee No.</label><input type="text" class="form-control" value="{{ $data['employee_no'] ?? '' }}" readonly></div>
                                        <div class="col-md-4"><label class="form-label">Department</label><input type="text" name="department" class="form-control" value="{{ $data['department'] ?? '' }}"></div>
                                        <div class="col-md-4"><label class="form-label">Position</label><input type="text" name="position" class="form-control" value="{{ $data['position'] ?? '' }}"></div>
                                    @endif
                                </div>
                                <div class="d-flex gap-2 mt-3">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Profile</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
                
            <script>
            (function () {
                'use strict';

                // Square crop stage for the profile picture: drag to
                // reposition, slider to zoom, live circular preview; the
                // form then posts the 1:1 canvas output instead of the raw
                // file. Server-side rules in ProfileService::uploadPhoto
                // stay authoritative either way, and a browser without
                // canvas support simply posts the original file.
                var input = document.getElementById('profilePhotoInput');
                var form = document.getElementById('photoForm');
                var cropper = document.getElementById('photoCropper');
                var stage = document.getElementById('photoCropStage');
                var img = document.getElementById('photoCropImage');
                var zoom = document.getElementById('photoCropZoom');
                var preview = document.getElementById('photoCropPreview');
                var cancel = document.getElementById('photoCropCancel');
                var notice = document.getElementById('photoCropNotice');

                if (!input || !form || !cropper || !stage || !img || !zoom || !preview || !cancel || !notice) {
                    return;
                }

                var OK_TYPES = ['image/jpeg', 'image/png'];
                var OK_NAME = /\.(jpe?g|png)$/i;
                var MAX_BYTES = 2 * 1024 * 1024;
                var OUTPUT = 512;

                var state = { nw: 0, nh: 0, base: 1, scale: 1, dx: 0, dy: 0, side: 0, url: '' };

                function clamp(v, min, max) {
                    return Math.min(max, Math.max(min, v));
                }

                function noticeShow(text) {
                    notice.textContent = text;
                    notice.classList.remove('d-none');
                }

                function noticeClear() {
                    notice.classList.add('d-none');
                }

                function place(reset) {
                    var side = stage.clientWidth;
                    state.side = side;
                    // Base scale = the image just covers the square stage.
                    state.base = side / Math.min(state.nw, state.nh);
                    var scale = state.base * parseFloat(zoom.value);
                    state.scale = scale;
                    var w = state.nw * scale;
                    var h = state.nh * scale;
                    img.style.width = w + 'px';
                    img.style.height = h + 'px';
                    if (reset) {
                        state.dx = (side - w) / 2;
                        state.dy = (side - h) / 2;
                    }
                    state.dx = clamp(state.dx, side - w, 0);
                    state.dy = clamp(state.dy, side - h, 0);
                    img.style.left = state.dx + 'px';
                    img.style.top = state.dy + 'px';
                    render();
                }

                function drawSquare(ctx, size) {
                    var r = {
                        x: -state.dx / state.scale,
                        y: -state.dy / state.scale,
                        size: state.side / state.scale
                    };
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, size, size);
                    ctx.drawImage(img, r.x, r.y, r.size, r.size, 0, 0, size, size);
                }

                function render() {
                    drawSquare(preview.getContext('2d'), preview.width);
                }

                var grab = null;
                stage.addEventListener('pointerdown', function (e) {
                    if (!state.nw) {
                        return;
                    }
                    grab = { x: e.clientX - state.dx, y: e.clientY - state.dy };
                    stage.setPointerCapture(e.pointerId);
                    e.preventDefault();
                });
                stage.addEventListener('pointermove', function (e) {
                    if (!grab) {
                        return;
                    }
                    var w = state.nw * state.scale;
                    var h = state.nh * state.scale;
                    state.dx = clamp(e.clientX - grab.x, state.side - w, 0);
                    state.dy = clamp(e.clientY - grab.y, state.side - h, 0);
                    img.style.left = state.dx + 'px';
                    img.style.top = state.dy + 'px';
                    render();
                });
                ['pointerup', 'pointercancel'].forEach(function (name) {
                    stage.addEventListener(name, function () {
                        grab = null;
                    });
                });

                zoom.addEventListener('input', function () {
                    if (!state.nw) {
                        return;
                    }
                    // Keep the crop centre fixed while the scale changes.
                    var cx = (state.side / 2 - state.dx) / state.scale;
                    var cy = (state.side / 2 - state.dy) / state.scale;
                    var scale = state.base * parseFloat(zoom.value);
                    state.scale = scale;
                    var w = state.nw * scale;
                    var h = state.nh * scale;
                    img.style.width = w + 'px';
                    img.style.height = h + 'px';
                    state.dx = clamp(state.side / 2 - cx * scale, state.side - w, 0);
                    state.dy = clamp(state.side / 2 - cy * scale, state.side - h, 0);
                    img.style.left = state.dx + 'px';
                    img.style.top = state.dy + 'px';
                    render();
                });

                window.addEventListener('resize', function () {
                    if (!state.nw || cropper.classList.contains('d-none')) {
                        return;
                    }
                    place(false);
                });

                function resetCrop() {
                    cropper.classList.add('d-none');
                    cancel.classList.add('d-none');
                    img.removeAttribute('src');
                    zoom.value = '1';
                    state.nw = 0;
                    state.nh = 0;
                }

                input.addEventListener('change', function () {
                    var file = input.files && input.files[0];

                    resetCrop();
                    noticeClear();

                    if (!file) {
                        return;
                    }

                    if (OK_TYPES.indexOf(file.type) === -1 && !OK_NAME.test(file.name)) {
                        noticeShow('Choose a JPG or PNG picture.');
                        input.value = '';

                        return;
                    }

                    if (file.size > MAX_BYTES) {
                        noticeShow('The picture may not be greater than 2 MB.');
                        input.value = '';

                        return;
                    }

                    if (state.url) {
                        URL.revokeObjectURL(state.url);
                    }
                    state.url = URL.createObjectURL(file);

                    img.onload = function () {
                        state.nw = img.naturalWidth;
                        state.nh = img.naturalHeight;
                        zoom.value = '1';
                        cropper.classList.remove('d-none');
                        cancel.classList.remove('d-none');
                        place(true);
                    };
                    img.onerror = function () {
                        noticeShow('That file could not be read as an image.');
                        resetCrop();
                        input.value = '';
                    };
                    img.src = state.url;
                });

                cancel.addEventListener('click', function () {
                    resetCrop();
                    noticeClear();
                    input.value = '';
                });

                form.addEventListener('submit', function (e) {
                    // Remove posts no file at all - nothing to crop.
                    if (e.submitter && e.submitter.value === 'remove') {
                        return;
                    }

                    if (cropper.classList.contains('d-none') || !input.files || !input.files.length) {
                        return;
                    }

                    if (!window.URL || !URL.createObjectURL || !document.createElement('canvas').toBlob) {
                        return;
                    }

                    e.preventDefault();

                    var out = document.createElement('canvas');
                    out.width = OUTPUT;
                    out.height = OUTPUT;
                    drawSquare(out.getContext('2d'), OUTPUT);

                    out.toBlob(function (blob) {
                        if (!blob) {
                            form.submit();

                            return;
                        }

                        var transfer = new DataTransfer();
                        transfer.items.add(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
                        input.files = transfer.files;
                        form.submit(); // Native submit: skips this handler, no loop.
                    }, 'image/jpeg', 0.9);
                });
            })();
            </script>
            @else
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
                            <form method="post">
                                @csrf

                                <input type="hidden" name="form_action" value="password">
                                <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
                                <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" autocomplete="new-password"></div>
                                <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" autocomplete="new-password"></div>
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i> Update Password</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            @endif
@endsection
