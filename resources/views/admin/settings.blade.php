{{--
    Admin - System settings - exact port of admin/settings.php (original
    lines 43-115): the (always empty) error loop, the two-card settings
    form - School & Clinic Info inputs fed by the settings map, Rules &
    Academic Period with the semester <select> and the two number limits -
    then the save row, the form close and the page-specific shell closers.

    Emission notes:
    - $errors of the reference is $settingsErrors here (Laravel always
      injects its own $errors bag into views); the loop never renders
      anything - the reference never populates its array either.
    - The reference form carries NO token field and the page runs no CSRF
      check at all; the form carries @csrf here so it saves under the global
      middleware (approved Phase 6 strengthening - without it the Save button
      would dead-end at 419 while the reference succeeds). The blank line
      after @csrf compensates PHP eating one newline after the compiled
      field line; full_diff strips the hidden token input itself.
    - The reference's $val($k) closure pre-applies e() before a raw echo;
    {{ $settings[...] ?? ... }} runs the same e() exactly once.
    - The closers stay inside the view before endsection: the reference page
      closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
            @foreach ($settingsErrors as $err)
                <div class="alert alert-danger py-2">{{ $err }}</div>
            @endforeach

            <form method="post" data-swal-save="system settings" data-confirm="Save System Settings?">
                @csrf

                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header"><i class="bi bi-building me-1"></i> School & Clinic Info</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">School Name</label>
                                    <input type="text" name="school_name" class="form-control" value="{{ $settings['school_name'] ?? '' }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Clinic Name</label>
                                    <input type="text" name="clinic_name" class="form-control" value="{{ $settings['clinic_name'] ?? '' }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Clinic Address</label>
                                    <input type="text" name="clinic_address" class="form-control" value="{{ $settings['clinic_address'] ?? '' }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Clinic Contact</label>
                                    <input type="text" name="clinic_contact" class="form-control" value="{{ $settings['clinic_contact'] ?? '' }}">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-header"><i class="bi bi-sliders me-1"></i> Rules & Academic Period</div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Current School Year</label>
                                    <input type="text" name="current_school_year" class="form-control" value="{{ $settings['current_school_year'] ?? '' }}">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Current Semester</label>
                                    <select name="current_semester" class="form-select">
                                        @foreach (['1st','2nd','summer'] as $sem)
                                            <option value="{{ $sem }}" {{ ($settings['current_semester'] ?? '1st') === $sem ? 'selected' : '' }}>{{ ucfirst($sem) }} Semester</option>
                                        @endforeach
                                    </select>
                                    <div class="form-text">Used as the default period for the semester medical form.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Default Dispense Limit</label>
                                    <input type="number" min="1" name="default_dispense_limit" class="form-control" value="{{ $settings['default_dispense_limit'] ?? 10 }}">
                                    <div class="form-text">Default cap used when a product does not have its own stock-based limit.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Default Equipment Loan Limit</label>
                                    <input type="number" min="1" name="default_equipment_loan_limit" class="form-control" value="{{ $settings['default_equipment_loan_limit'] ?? 5 }}">
                                    <div class="form-text">Default cap for loaned equipment quantities when no custom rule applies.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Save System Settings</button>
                </div>
            </form>
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection
