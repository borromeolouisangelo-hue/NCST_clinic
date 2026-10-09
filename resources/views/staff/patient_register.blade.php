{{--
    Staff - Register new patient (student/employee).
    Exact port of staff/patient_register.php (lines 82-184): same card, same
    section titles, same fields, same toggle script. Like the original, a
    failed POST re-renders this template with the error list and does NOT
    repopulate the inputs.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">{{ $accountType === 'employee' ? 'Register Employee' : 'Register Student' }}</div>
            <div class="card-body">
                @foreach ($errors->all() as $err)
                    <div class="alert alert-danger py-2">{{ $err }}</div>
                @endforeach
                <form method="post">
                    @csrf

                    <div class="section-title">Account Type</div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <select name="account_type" id="account_type" class="form-select" onchange="toggleAccountType()">
                                <option value="student" {{ $accountType === 'student' ? 'selected' : '' }}>Student</option>
                                <option value="employee" {{ $accountType === 'employee' ? 'selected' : '' }}>Employee</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-title">Login Credentials</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Confirm *</label>
                            <input type="password" name="password_confirm" class="form-control" required>
                        </div>
                    </div>

                    <div class="section-title">Personal Information</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3"><label class="form-label">First Name *</label><input type="text" name="first_name" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Last Name *</label><input type="text" name="last_name" class="form-control" required></div>
                        <div class="col-md-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select"><option value="">--</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Birthdate</label><input type="date" name="birthdate" id="birthdate" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Age</label><input type="text" id="ageField" class="form-control" readonly></div>
                        <div class="col-md-3"><label class="form-label">Contact No.</label><input type="text" name="contact_no" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Lot/Block</label><input type="text" name="lot_block" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Street</label><input type="text" name="street" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Barangay</label><input type="text" name="barangay" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Postal/ZIP Code</label><input type="text" name="postal_code" class="form-control" inputmode="numeric" maxlength="10"></div>
                        <div class="col-md-4"><label class="form-label">City/Municipality *</label>
                        <select name="city_municipality" class="form-select" required>
                            <option value="">-- Select City/Municipality --</option>
                            @foreach (\App\Support\PhilippineLocations::cities($old['province'] ?? '') as $city)
                                <option value="{{ $city }}" {{ ($old['city_municipality'] ?? '') === $city ? 'selected' : '' }}>{{ $city }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5"><label class="form-label">Province *</label>
                        <select name="province" class="form-select" required onchange="updateCities()">
                            <option value="">-- Select Province --</option>
                            @foreach (\App\Support\PhilippineLocations::provinces() as $province)
                                <option value="{{ $province }}" {{ ($old['province'] ?? '') === $province ? 'selected' : '' }}>{{ $province }}</option>
                            @endforeach
                        </select>
                    </div>
                    </div>

                    <div class="section-title">Emergency Contact</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label class="form-label">Contact Person</label><input type="text" name="emergency_name" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Contact Number</label><input type="text" name="emergency_contact" class="form-control"></div>
                    </div>

                    <div id="studentFields">
                        <div class="section-title">Student Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><label class="form-label">Student No. *</label><input type="text" name="student_no" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label">Course</label><input type="text" name="course" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label">Year Level</label><input type="text" name="year_level" class="form-control"></div>
                            <div class="col-md-3"><label class="form-label">Section</label><input type="text" name="section" class="form-control"></div>
                        </div>
                    </div>
                    <div id="employeeFields" style="display:none;">
                        <div class="section-title">Employee Details</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><label class="form-label">Employee No. *</label><input type="text" name="employee_no" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">Department</label><input type="text" name="department" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">Position</label><input type="text" name="position" class="form-control"></div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> Register</button>
                        <a href="{{ route('staff.patients') }}" class="btn btn-outline-secondary">Back to Patients</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
var patientLocationData = <?php
$provincesWithCities = [];
foreach (\App\Support\PhilippineLocations::provinces() as $province) {
    $cities = \App\Support\PhilippineLocations::cities($province);
    if ($cities) {
        $provincesWithCities[$province] = $cities;
    }
}
echo json_encode($provincesWithCities, JSON_UNESCAPED_UNICODE);
?>;

// Populate the City/Municipality dropdown for the selected province (a
// fresh GET renders it empty - same gap the register form's cascade
// fixes). The Barangay field stays a free-text input here.
function updateCities() {
    var provinceSelect = document.querySelector('select[name="province"]');
    var citySelect = document.querySelector('select[name="city_municipality"]');
    var currentCity = citySelect.value;
    var cities = patientLocationData[provinceSelect.value] || [];

    citySelect.innerHTML = '<option value="">-- Select City/Municipality --</option>';
    cities.forEach(function(city) {
        var option = document.createElement('option');
        option.value = city;
        option.text = city;
        if (city === currentCity) {
            option.selected = true;
        }
        citySelect.appendChild(option);
    });
}

function toggleAccountType() {
    var sel = document.getElementById('account_type');
    var sF = document.getElementById('studentFields');
    var eF = document.getElementById('employeeFields');
    if (sel.value === 'employee') { sF.style.display = 'none'; eF.style.display = ''; }
    else { sF.style.display = ''; eF.style.display = 'none'; }
}
toggleAccountType();
</script>
@endsection
