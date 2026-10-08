{{--
    Medical Form + Allergy Registry - exact port of user/medical_form.php
    (original lines 100-231): the unconditional top-level error block, the
    static info banner, the Health Information form (school year, semester
    select, blood/vitals, five textareas, save + add-allergy buttons), the
    Allergy Registry card with its empty/else branch, the Add Allergy modal
    (its error block renders unconditionally too, so failed submits show the
    message twice - top AND modal - and the bottom script reopens the
    allergy modal for ANY failed submit, even the record form's), and the
    closing reopen script.

    Approved differences: the original hard-redirected a bad CSRF with
    header()+exit - the global middleware answers token-less POSTs with 419
    (Phase 6 strengthening); $errors renders as $medicalErrors so the
    shared ViewErrorBag stays intact; the delete URL drops the reference's
    .php suffix (route user.medical_form).

    Legacy quirks kept: errors double-render (both blocks unconditioned);
    the reopen script fires for every error source; the modal's allergen
    input is duplicated verbatim (original lines 205-206); the allergy
    delete link is a GET with only the JS data-confirm guard; a notes value
    renders its own inline block via the single-line @if.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
@foreach ($medicalErrors as $err)
    <div class="alert alert-danger py-2">{{ $err }}</div>
@endforeach

<div class="alert alert-info py-2">
    <i class="bi bi-info-circle me-1"></i> Complete this form each semester. Allergies recorded here are used to warn clinic staff before dispensing medicine.
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Health Information</div>
            <div class="card-body">
                <form method="post" data-autosave="medical-form">
                    @csrf

                    <input type="hidden" name="form_action" value="record">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">School Year</label>
                            <input type="text" name="school_year" class="form-control" value="{{ $year }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Semester</label>
                            <select name="semester" class="form-select">
                                @foreach (['1st','2nd','summer'] as $s)
                                    <option value="{{ $s }}" {{ $sem === $s ? 'selected' : '' }}>{{ ucfirst($s) }} Semester</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Blood Type</label>
                            <select name="blood_type" class="form-select">
                                <option value="">-- select --</option>
                                @foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-','O'] as $bt)
                                    <option value="{{ $bt }}" {{ ($rec['blood_type'] ?? '') === $bt ? 'selected' : '' }}>{{ $bt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Height (cm)</label><input type="number" step="0.01" name="height_cm" class="form-control" value="{{ $rec['height_cm'] ?? '' }}"></div>
                        <div class="col-md-3"><label class="form-label">Weight (kg)</label><input type="number" step="0.01" name="weight_kg" class="form-control" value="{{ $rec['weight_kg'] ?? '' }}"></div>
                        <div class="col-12">
                            <label class="form-label">Allergies (free text)</label>
                            <textarea name="allergies_text" class="form-control" rows="2" placeholder="e.g. penicillin, peanuts, dust">{{ $rec['allergies_text'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Existing Conditions</label>
                            <textarea name="existing_conditions" class="form-control" rows="2">{{ $rec['existing_conditions'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Current Medications</label>
                            <textarea name="current_medications" class="form-control" rows="2">{{ $rec['current_medications'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Immunizations</label>
                            <textarea name="immunizations" class="form-control" rows="2">{{ $rec['immunizations'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Current Symptoms (if any)</label>
                            <textarea name="current_symptoms" class="form-control" rows="2">{{ $rec['current_symptoms'] ?? '' }}</textarea>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Medical Form</button>
                        <button type="button" class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#allergyModal"><i class="bi bi-plus-lg me-1"></i> Add Allergy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-triangle text-accent me-1"></i> Allergy Registry</div>
            <div class="card-body">
                @if (!$allergies)
                    <p class="text-muted small">No allergies recorded.</p>
                @else
                    <ul class="list-unstyled">
                        @foreach ($allergies as $a)
                            <li class="d-flex justify-content-between align-items-start mb-2">
                                <span>
                                    <strong>{{ $a['allergen_name'] }}</strong>
                                    @php
                                        $sev = $a['severity'] ?? 'mild';
                                        $badge = $sev === 'severe' ? 'bg-danger' : ($sev === 'moderate' ? 'bg-warning text-dark' : 'bg-success');
                                    @endphp
                                    <span class="badge {{ $badge }}">{{ ucfirst($sev) }}</span>
                                    @if ($a['notes'])<div class="small text-muted">{{ $a['notes'] }}</div>@endif
                                </span>
                                <a href="{{ url('user/medical_form?action=delete_allergy&id=' . $a['id']) }}" class="btn btn-sm btn-outline-danger" data-confirm="Remove this allergy?" data-swal-delete="allergy"><i class="bi bi-trash"></i></a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Add Allergy Modal -->
<div class="modal fade" id="allergyModal" tabindex="-1" aria-labelledby="allergyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content">
            <div class="card">
                <div class="card-header" id="allergyModalLabel"><i class="bi bi-plus-lg me-1"></i> Add Allergy</div>
                <div class="card-body">
                    @foreach ($medicalErrors as $err)
                        <div class="alert alert-danger py-2">{{ $err }}</div>
                    @endforeach
                    <form method="post">
                        @csrf

                        <input type="hidden" name="form_action" value="add_allergy">
                        <div class="mb-3">
                            <label class="form-label">Allergen *</label>
                            <input type="text" name="allergen_name" class="form-control" required placeholder="e.g. Penicillin" list="commonAllergens">
                            <datalist id="commonAllergens">
                                <option value="Penicillin">Penicillin</option>
                                <option value="Amoxicillin">Amoxicillin</option>
                                <option value="Sulfa drugs">Sulfa drugs</option>
                                <option value="Aspirin">Aspirin</option>
                                <option value="Ibuprofen">Ibuprofen</option>
                                <option value="Peanuts">Peanuts</option>
                                <option value="Tree nuts">Tree nuts</option>
                                <option value="Shellfish">Shellfish</option>
                                <option value="Eggs">Eggs</option>
                                <option value="Milk">Milk</option>
                                <option value="Soy">Soy</option>
                                <option value="Wheat">Wheat</option>
                                <option value="Latex">Latex</option>
                                <option value="Insect stings">Insect stings</option>
                            </datalist>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Severity</label>
                            <select name="severity" class="form-select">
                                <option value="mild">Mild <span class="badge bg-success">●</span></option>
                                <option value="moderate">Moderate <span class="badge bg-warning text-dark">●</span></option>
                                <option value="severe">Severe <span class="badge bg-danger">●</span></option>
                            </select>
                            <div class="form-text">Mild = localized reaction, Moderate = systemic symptoms, Severe = anaphylaxis risk</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes...">
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> Add Allergy</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@if ($medicalErrors)<script>document.addEventListener('DOMContentLoaded', function() { var modal = document.getElementById('allergyModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
@endsection
