{{--
    Self-registration - exact port of auth/register.php (markup lines 92-301):
    the standalone auth document (this page does NOT use the dashboard
    layout - the original prints its own DOCTYPE/head/body, like login), the
    auth-brand header, the .auth-alerts slot fed by the plain $errors array,
    the account-type select toggling the two detail blocks, the credential /
    personal / emergency sections, both detail blocks, the Register and
    Back to Login buttons and the inline toggle/age script.

    Preserved verbatim: required attributes on the six validated inputs
    ONLY (student_no / employee_no show a * label but carry NO required
    attr - native validation therefore never blocks them), the readonly Age
    field, the employee block's display:none default, the $old[...]
    repopulation everywhere (default 'student' account type selected), the
    raw type keys, and the stylesheet WITHOUT a cache-busting ?v= (the
    original register page has none; the dashboard layout adds one).

    Deliberate, reported differences from the original:
      - a csrf token tag right after the form open plus a compensating
        blank line: the original form carries NO token and runs NO CSRF
        check; Laravel's middleware requires one (approved Phase 6
        strengthening, the same divergence the login form already
        carries - a token-less POST raises 419 here instead of silently
        processing).
      - Back to Login uses route('login'): the original links
        auth/login.php, whose path normalizes to the port's canonical
        /login exactly like the harness maps the navigation path.
    The success flash is rendered by the login page after the PRG redirect
    (original lines 83-84); this page never renders a flash of its own.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register &middot; {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-shell">
    <div class="auth-card auth-card-wide">
        <div class="auth-brand">
            <img src="{{ asset('assets/images/ncst.png') }}" alt="NCST logo" class="auth-logo-image">
            <h3 class="mb-0">Create an Account</h3>
            <p>Students & Employees of the school</p>
        </div>

        <div class="auth-register-shell">
            @if ($errors)
                <div class="auth-alerts" id="registerAlerts">
                    @foreach ($errors as $err)
                        <div class="alert alert-danger py-2 mb-2 d-none" data-alert="{{ $err }}"></div>
                    @endforeach
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        @foreach ($errors as $err)
                        Swal.fire({
                            title: 'Validation Error',
                            text: '{{ $err }}',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                        @endforeach
                    });
                </script>
            @endif

            <form method="post" class="auth-register-form auth-form">
                @csrf

                <div class="section-title">Account Type</div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <select name="account_type" id="account_type" class="form-select">
                            <option value="student" {{ $old['account_type'] === 'student' ? 'selected' : '' }}>Student</option>
                            <option value="employee" {{ $old['account_type'] === 'employee' ? 'selected' : '' }}>Employee</option>
                        </select>
                    </div>
                </div>

                <div class="section-title">Login Credentials</div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" value="{{ $old['username'] }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-control" value="{{ $old['email'] }}" required>
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

                <div class="section-title">Location Information</div>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="position-relative mb-2">
                            <label class="form-label" for="region_search">Region *</label>
                            <input type="text" id="region_search" class="form-control form-control-sm d-none" placeholder="Search region..." aria-label="Search region">
                            <select name="region" id="region" class="form-select" required>
                                <option value="">-- Select Region --</option>
                                @foreach (\App\Support\PhilippineLocations::regionsWithProvinces() as $regionName => $regionProvinces)
                                    <option value="{{ $regionName }}" {{ ($old['region'] ?? '') === $regionName ? 'selected' : '' }}>{{ $regionName }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="position-relative mb-2">
                            <label class="form-label" for="province_search">Province *</label>
                            <input type="text" id="province_search" class="form-control form-control-sm d-none" placeholder="Search province..." aria-label="Search province">
                            <select name="province" id="province" class="form-select" required>
                                <option value="">-- Select Province --</option>
                                @if (old('region'))
                                    @foreach (\App\Support\PhilippineLocations::provinces(old('region')) as $province)
                                        <option value="{{ $province }}" {{ ($old['province'] ?? '') === $province ? 'selected' : '' }}>{{ $province }}</option>
                                    @endforeach
                                @else
                                    @foreach (\App\Support\PhilippineLocations::provinces() as $province)
                                        <option value="{{ $province }}" {{ ($old['province'] ?? '') === $province ? 'selected' : '' }}>{{ $province }}</option>
                                    @endforeach
                                @endif
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="position-relative mb-2">
                            <label class="form-label" for="city_search">City/Municipality *</label>
                            <input type="text" id="city_search" class="form-control form-control-sm d-none" placeholder="Search city/municipality..." aria-label="Search city/municipality">
                            <select name="city_municipality" id="city_municipality" class="form-select" required>
                                <option value="">-- Select City/Municipality --</option>
                                @if (old('province'))
                                    @foreach (\App\Support\PhilippineLocations::cities(old('province')) as $city)
                                        <option value="{{ $city }}" {{ ($old['city_municipality'] ?? '') === $city ? 'selected' : '' }}>{{ $city }}</option>
                                    @endforeach
                                @else
                                    <option value="" disabled>Select a province first</option>
                                @endif
                            </select>
                        </div>
                    </div>
                </div>

                <div class="section-title">Emergency Contact</div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="emergency_name" class="form-control" value="{{ $old['emergency_name'] }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="emergency_contact" class="form-control" value="{{ $old['emergency_contact'] }}">
                    </div>
                </div>

                <div id="studentFields">
                    <div class="section-title">Student Details</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Student No. *</label>
                            <input type="text" name="student_no" class="form-control" value="{{ $old['student_no'] }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Course</label>
                            <input type="text" name="course" class="form-control" value="{{ $old['course'] }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year Level</label>
                            <input type="text" name="year_level" class="form-control" value="{{ $old['year_level'] }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Section</label>
                            <input type="text" name="section" class="form-control" value="{{ $old['section'] }}">
                        </div>
                    </div>
                </div>

                <div id="employeeFields" style="display:none;">
                    <div class="section-title">Employee Details</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Employee No. *</label>
                            <input type="text" name="employee_no" class="form-control" value="{{ $old['employee_no'] }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <input type="text" name="department" class="form-control" value="{{ $old['department'] }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Position</label>
                            <input type="text" name="position" class="form-control" value="{{ $old['position'] }}">
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i> Register</button>
                    <a href="{{ route('login') }}" class="btn btn-outline-secondary">Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var sel = document.getElementById('account_type');
    var sF = document.getElementById('studentFields');
    var eF = document.getElementById('employeeFields');
    function toggle() {
        if (sel.value === 'employee') { sF.style.display = 'none'; eF.style.display = ''; }
        else { sF.style.display = ''; eF.style.display = 'none'; }
    }
    sel.addEventListener('change', toggle);
    toggle();

    var birthdate = document.getElementById('birthdate');
    var ageField = document.getElementById('ageField');
    function updateAge() {
        if (!birthdate.value) { ageField.value = ''; return; }
        var birth = new Date(birthdate.value + 'T00:00:00');
        var today = new Date();
        var age = today.getFullYear() - birth.getFullYear();
        var month = today.getMonth() - birth.getMonth();
        if (month < 0 || (month === 0 && today.getDate() < birth.getDate())) age--;
        ageField.value = age >= 0 ? age : '';
    }
    birthdate.addEventListener('change', updateAge);
    updateAge();
})

// <searchable-dropdowns-start>
var locationData = <?php 
$provincesWithCities = [];
foreach (\App\Support\PhilippineLocations::provinces() as $province) {
    $cities = \App\Support\PhilippineLocations::cities($province);
    if ($cities) {
        $provincesWithCities[$province] = $cities;
    }
}
echo json_encode($provincesWithCities, JSON_UNESCAPED_UNICODE);
?>;

// Initialize searchable dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Province search functionality
    var provinceInput = document.getElementById('province_search');
    var provinceSelect = document.getElementById('province');
    var provinceOptions = provinceSelect.options;

    // City search functionality
    var cityInput = document.getElementById('city_search');
    var citySelect = document.getElementById('city_municipality');
    var cityOptions = citySelect.options;

    // Show/hide search inputs on focus
    provinceInput.addEventListener('focus', function() {
        provinceSelect.classList.add('with-search');
    });
    provinceInput.addEventListener('blur', function() {
        // Check if there's text, if not remove the class
        if (!provinceInput.value) {
            provinceSelect.classList.remove('with-search');
        }
    });

    cityInput.addEventListener('focus', function() {
        citySelect.classList.add('with-search');
    });
    cityInput.addEventListener('blur', function() {
        if (!cityInput.value) {
            citySelect.classList.remove('with-search');
        }
    });

    // Filter province options on input
    provinceInput.addEventListener('input', function() {
        var filter = provinceInput.value.toLowerCase().trim();
        for (var i = 0; i < provinceOptions.length; i++) {
            var optionText = provinceOptions[i].text.toLowerCase();
            provinceOptions[i].style.display = optionText.includes(filter) ? '' : 'none';
        }
        // Show first matching option or reset
        if (filter) {
            var firstMatch = null;
            for (var i = 0; i < provinceOptions.length; i++) {
                if (provinceOptions[i].style.display !== 'none') {
                    firstMatch = provinceOptions[i];
                    break;
                }
            }
            if (firstMatch) {
                provinceSelect.value = firstMatch.value;
                provinceInput.value = firstMatch.text;
                provinceSelect.classList.add('is-focused');
            }
        } else {
            provinceSelect.value = '';
            provinceSelect.classList.remove('is-focused');
        }
    });

    // Filter city options on input
    cityInput.addEventListener('input', function() {
        var filter = cityInput.value.toLowerCase().trim();
        for (var i = 0; i < cityOptions.length; i++) {
            var optionText = cityOptions[i].text.toLowerCase();
            cityOptions[i].style.display = optionText.includes(filter) ? '' : 'none';
        }
        // Show first matching option or reset
        if (filter) {
            var firstMatch = null;
            for (var i = 0; i < cityOptions.length; i++) {
                if (cityOptions[i].style.display !== 'none') {
                    firstMatch = cityOptions[i];
                    break;
                }
            }
            if (firstMatch) {
                citySelect.value = firstMatch.value;
                cityInput.value = firstMatch.text;
                citySelect.classList.add('is-focused');
            }
        } else {
            citySelect.value = '';
            citySelect.classList.remove('is-focused');
        }
    });

    // When province changes, update city dropdown
    provinceSelect.addEventListener('change', function() {
        var selectedProvince = this.value;
        // Hide search inputs
        provinceInput.value = '';
        cityInput.value = '';
        provinceSelect.classList.remove('with-search', 'is-focused');
        citySelect.classList.remove('with-search', 'is-focused');

        // Update city options based on selected province
        var provinceCities = locationData[selectedProvince] || [];
        var currentCity = citySelect.value;

        // Clear and update city options
        citySelect.innerHTML = '<option value="">-- Select City/Municipality --</option>';

        provinceCities.forEach(function(city) {
            var option = document.createElement('option');
            option.value = city;
            option.text = city;
            if (city === currentCity) {
                option.selected = true;
            }
            citySelect.appendChild(option);
        });

        // Show the city dropdown and focus the search input
        citySelect.classList.add('with-search');
        cityInput.focus();
    });

    // Restore old province on page load (from $old data)
    {% if old('province') %}
    var oldProvince = "<?php echo htmlspecialchars(old('province')); ?>";
    provinceSelect.value = oldProvince;
    {% if old('city_municipality') %}
    var oldCity = "<?php echo htmlspecialchars(old('city_municipality')); ?>";
    {% endif %}
    {% endif %}

    // Initialize city dropdown based on restored province
    {% if old('province') && old('city_municipality') %}
    setTimeout(function() {
        var oldProvince = "<?php echo htmlspecialchars(old('province')); ?>";
        var oldCity = "<?php echo htmlspecialchars(old('city_municipality')); ?>";
        // Find and select the old city in the updated province
        var provOption = provinceSelect.options[provinceSelect.selectedIndex];
        if (provOption.value) {
            var cities = locationData[oldProvince] || [];
            var citySelect = document.getElementById('city_municipality');
            for (var i = 0; i < citySelect.options.length; i++) {
                if (citySelect.options[i].value === oldCity) {
                    citySelect.selectedIndex = i;
                    break;
                }
            }
        }
    }, 100);
    {% endif %}
});
// <searchable-dropdowns-end>