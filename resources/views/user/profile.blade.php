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
    data-swal-save="profile">` opening.
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
                    <div class="card">
                        <div class="card-header"><i class="bi bi-person-circle me-1"></i> Personal Information </div>
                        <div class="card-body">
                            @foreach ($photoErrors as $err)
                                <div class="alert alert-danger py-2">{{ $err }}</div>
                            @endforeach
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                @if ($photoUrl !== '')
                                    <img src="{{ $photoUrl }}" alt="{{ $shell->fullName }}" class="account-avatar account-avatar-lg">
                                @else
                                    <span class="account-avatar account-avatar-lg account-avatar-empty"><i class="bi bi-person"></i></span>
                                @endif
                                <form method="post" enctype="multipart/form-data" class="flex-grow-1" style="min-width: 260px;">
                                    @csrf

                                    <input type="hidden" name="form_action" value="photo">
                                    <label class="form-label" for="profilePhotoInput">Picture</label>
                                    <input type="file" id="profilePhotoInput" name="photo" class="form-control" accept="image/*">
                                    <div class="form-text">JPG, PNG, GIF or WebP, up to 2 MB.</div>
                                    <div class="d-flex gap-2 mt-2">
                                        <button type="submit" name="photo_action" value="upload" class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i> Upload Picture</button>
                                        @if ($photoUrl !== '')
                                            <button type="submit" name="photo_action" value="remove" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i> Remove</button>
                                        @endif
                                        
                                    </div>
                                    
                                </form>
                            </div>
                            
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
                </div>
                
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
