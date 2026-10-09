{{--
    Staff Dispense - exact port of staff/dispense.php (original lines 147-347):
    the dispensing modal with its allergy warning and decision flow, the
    history toolbar, and the 200-row history table.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the modal form carries @csrf instead of csrf_token (Phase 6) -
    the blank line after @csrf compensates PHP eating one newline after the
    compiled directive so the following hidden input keeps the original's
    exact leading whitespace; $pt['x'] / $row['x'] become ->x (stdClass rows,
    same values); $stock/$limit move from the option loop into the controller
    (same values, same keys missing so data-limit stays 10); POST CSRF
    failure raises 419 (Phase 6); the redirect flashes (dispense outcome and
    the Mark Depleted result) are carried to the user by
    partials/flash-toast - partials/flash.blade.php itself still renders
    nothing (write-only legacy slot); a scoped rule in style.css keeps this
    dialog within the viewport (fixed card header, scrolling card body) so
    the taller form (tracking block + allergy decision state) stays
    reachable on short viewports.

    Legacy quirks kept: ?user_id= preselects the patient on GET but
    medicine_id is POST-only; qty must be exactly 1 (a missing quantity
    reports the 1-unit rule before the limit rule); an unknown ?history_status
    shows every row with no selected option; history is capped at 200 rows;
    the conflicts list, decision block and auto-open script re-render on any
    POST that reached the conflict checks.

    Tracking feature: #dispensePieceState is the "Stock: ..." line under the
    quantity help text fed by the controller's $pieceStates JSON - the exact
    piece count for Individual items ("Stock: 103 tablets"), the
    unopened/opened counts for Container + Partial / Roll + Partial items
    ("Stock: 8 unopened bottles · 1 opened bottle"), which also render the
    Dispense from radios (existing opened vs open new), the Usage radios
    (Small / Moderate / Large - a record only, never a quantity) and the
    separate Mark Depleted form. The old Full / Half Empty / Empty fill-level
    line is gone with the fill-level system. The option markup itself is
    untouched.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
@include('layouts.partials.flash-toast')
<div class="row g-3">
    <div class="col-12">
        <div class="modal fade" id="dispenseModal" tabindex="-1" aria-labelledby="dispenseModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
        <div class="card">
            <div class="card-header" id="dispenseModalLabel">Medicine Dispensing</div>
            <div class="card-body">
                @foreach ($errors as $err)
                    <div class="alert alert-danger py-2">{{ $err }}</div>
                @endforeach

                @if ($conflicts)
                    <div class="alert-allergy p-3 mb-3">
                        <strong><i class="bi bi-exclamation-triangle"></i> Allergy Warning</strong><br>
                        Selected patient is allergic to:
                        <ul class="mb-0 mt-1">
                            @foreach ($conflicts as $cf)<li>{{ $cf['allergen_name'] }} ({{ $cf['severity'] }})</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form method="post">

                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Patient *</label>
                        <input type="search" class="form-control" data-patient-combobox="#dispensePatientId" list="dispensePatientSuggestions" autocomplete="off" placeholder="Search patient name or ID..." aria-label="Search patient" required>
                        <input type="hidden" id="dispensePatientId" name="user_id" value="{{ $selPatient ?? '' }}" required>
                        <datalist id="dispensePatientSuggestions">
                            @foreach ($patients as $pt)
                                @php $patientId = $pt->student_no ?: $pt->employee_no; @endphp
                                <option value="{{ ($pt->full_name ?: ('User #' . $pt->id)) . ($patientId ? ' - ' . $patientId : '') }}" data-user-id="{{ (int) $pt->id }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Medicine / First Aid Item *</label>
                        <select name="medicine_id" id="dispenseMedicineSelect" class="form-select" required>
                            <option value="">-- select item --</option>
                            @foreach ($medicines as $m)
                                <option value="{{ (int) $m->id }}" data-stock="{{ (int) $m->stock }}" data-limit="{{ (int) $m->limit }}" {{ $selMedicine === (int) $m->id ? 'selected' : '' }}>
                                    {{ $m->name }}{{ $m->generic_name ? ' (' . $m->generic_name . ')' : '' }} — {{ $m->unit }} · stock {{ (int) $m->stock }}                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity *</label>
                        <input type="number" name="quantity" id="dispenseQuantity" class="form-control" min="1" value="1" max="1" required>
                        <div id="dispenseQuantityHelp" class="form-text">Use the item unit shown above (box, roll, tube, bottle, piece, etc.).</div>
                        <div id="dispensePieceState" class="form-text" aria-live="polite"></div>
                    </div>
                    <div class="mb-3 d-none" id="dispenseTrackingBlock">
                        <label class="form-label" id="dispenseFromLabel">Dispense from</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="dispense_from" id="dispenseFromExisting" value="existing" disabled>
                                <label class="form-check-label" for="dispenseFromExisting" id="dispenseFromExistingText">Existing opened container</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="dispense_from" id="dispenseFromNew" value="new" disabled>
                                <label class="form-check-label" for="dispenseFromNew" id="dispenseFromNewText">Open new container</label>
                            </div>
                        </div>
                        <label class="form-label mt-2 mb-1">Usage</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="usage" id="dispenseUsageSmall" value="small" disabled>
                                <label class="form-check-label" for="dispenseUsageSmall">Small</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="usage" id="dispenseUsageModerate" value="moderate" disabled>
                                <label class="form-check-label" for="dispenseUsageModerate">Moderate</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="usage" id="dispenseUsageLarge" value="large" disabled>
                                <label class="form-check-label" for="dispenseUsageLarge">Large</label>
                            </div>
                        </div>
                        <div class="form-text">Usage is a record only - it never represents percentages or exact amounts. Stock changes only when a new container/roll is opened or an opened one is marked depleted below.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                    @if ($showAllergyDecision)
                        <div class="alert alert-warning mb-3">
                            <strong>Allergy warning requires a decision.</strong>
                            Choose whether to accept or reject this request. No stock or history record is changed until you choose.
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="dispenseOverrideReason">Override reason *</label>
                            <textarea name="override_reason" id="dispenseOverrideReason" class="form-control" rows="2" required
                                placeholder="e.g. Assessed by clinic physician; risk outweighed benefit">{{ $override ?? '' }}</textarea>
                            <div class="form-text">Required when accepting an allergy conflict. It is stored with the dispensing record.</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" name="allergy_decision" value="accept" class="btn btn-danger flex-fill"><i class="bi bi-check-circle me-1"></i> Accept and Dispense</button>
                            <button type="submit" name="allergy_decision" value="reject" class="btn btn-outline-secondary flex-fill" formnovalidate><i class="bi bi-x-circle me-1"></i> Decline Request</button>
                        </div>
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    @else
                        <div class="d-flex gap-2">
                            <button type="submit"class="btn btn-accent" data-swal-add="medicine request"><i class="bi bi-prescription2 me-1"></i> Dispense Item</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    @endif
                </form>
                <div class="mt-2 d-none" id="dispenseDepleteBlock">
                    <form method="post" id="dispenseDepleteForm" onsubmit="return confirm('Mark the opened container/roll as depleted? One unit will leave stock.')">
                        @csrf

                        <input type="hidden" name="action" value="deplete">
                        <input type="hidden" name="medicine_id" id="dispenseDepleteMedicineId" value="">
                        <button type="submit" class="btn btn-outline-warning w-100"><i class="bi bi-x-octagon me-1"></i> Mark Depleted</button>
                        <div class="form-text">Use when the opened container/roll is actually finished - one unit leaves stock and the opened count drops. Opening, dispensing and depletion are recorded as separate inventory events.</div>
                    </form>
                </div>
            </div>
        </div>
            </div></div>
        </div>
    </div>

    <div class="col">
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-2 align-items-end audit-toolbar mb-2">
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input id="dispenseHistorySearch" type="search" class="form-control form-control-sm" data-live-filter="#dispenseTable tbody tr" data-live-filter-count="dispenseHistoryResultCount" placeholder="Search visible history..." aria-label="Search medicine history">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <form method="get" class="d-inline">
                            <select name="history_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="all" {{ $historyStatus === 'all' ? 'selected' : '' }}>All Status</option>
                                <option value="dispensed" {{ $historyStatus === 'dispensed' ? 'selected' : '' }}>Dispensed</option>
                                <option value="rejected" {{ $historyStatus === 'rejected' ? 'selected' : '' }}>Rejected</option>
                            </select>
                        </form>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button type="button" class="btn btn-primary text-nowrap" data-search-trigger="#dispenseHistorySearch"><i class="bi bi-search me-1"></i> Search</button>
                        <button type="button" class="btn btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#dispenseModal"><i class="bi bi-prescription2 me-1"></i> Dispense Item</button>
                        
                    </div>
                </div>
                
            </div>
        </div>
                
        <div class="card">
                <div class="table-responsive">
                    {!! \App\Services\StaffHistory::table('dispense', $historyRows, 'dispenseTable') !!}
                </div>
            </div>
        </div>

    @if ($pagination['total'] > 1)
    <nav aria-label="Dispense pagination" class="mt-3">
        <ul class="pagination justify-content-center">
            @if ($pagination['current'] > 1)
                <li class="page-item">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] - 1])) }}" aria-label="Previous">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            @endif
            @for ($i = max(1, $pagination['current'] - 2); $i <= min($pagination['total'], $pagination['current'] + 2); $i++)
                <li class="page-item {{ $i === $pagination['current'] ? 'active' : '' }}">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $i])) }}">{{ $i }}</a>
                </li>
            @endfor
            @if ($pagination['current'] < $pagination['total'])
                <li class="page-item">
                    <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] + 1])) }}" aria-label="Next">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            @endif
        </ul>
    </nav>
    @endif
</div>
</div>
</div>
@if ($errors || $conflicts || $showAllergyDecision)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('dispenseModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    var medicineSelect = document.getElementById('dispenseMedicineSelect');
    var quantityInput = document.getElementById('dispenseQuantity');
    var helpText = document.getElementById('dispenseQuantityHelp');
    var pieceStateEl = document.getElementById('dispensePieceState');
    var dispenseModal = document.getElementById('dispenseModal');
    var trackingBlock = document.getElementById('dispenseTrackingBlock');
    var depleteBlock = document.getElementById('dispenseDepleteBlock');
    var depleteMedicineId = document.getElementById('dispenseDepleteMedicineId');
    var fromExisting = document.getElementById('dispenseFromExisting');
    var fromNew = document.getElementById('dispenseFromNew');
    var fromExistingText = document.getElementById('dispenseFromExistingText');
    var fromNewText = document.getElementById('dispenseFromNewText');
    var usageRadios = Array.prototype.slice.call(document.querySelectorAll('input[name="usage"]'));
    var pieceStates = @json($pieceStates);

    // Tracking methods (state shared with the inventory page's Total
    // Pieces column, which shows the compact cell copy - "0/16 opened",
    // "11/12 deducted" - while this modal keeps the verbose label):
    // "Stock: 103 tablets" for Individual items, "Stock: 8 unopened
    // bottles · 1 opened bottle" for Container + Partial items ("4
    // unopened rolls · 1 opened roll" for Roll + Partial). Only partial
    // items get the Dispense from / Usage radios (usage is a record
    // only - never a quantity) and the Mark Depleted button; impossible
    // choices are disabled rather than guessed.
    function applyTracking() {
        if (!pieceStateEl || !medicineSelect) return;
        var st = pieceStates[medicineSelect.value] || null;
        var partial = !!st && st.method !== 'individual';

        pieceStateEl.textContent = st ? ('Stock: ' + (st.label || st.text)) : '';

        var opened = partial ? (parseInt(st.opened, 10) || 0) : 0;
        var unopened = partial ? (parseInt(st.unopened, 10) || 0) : 0;

        if (trackingBlock) {
            trackingBlock.classList.toggle('d-none', !partial);
        }
        if (fromExisting && fromNew) {
            if (partial) {
                var noun = st.method === 'roll' ? 'roll' : 'container';
                if (fromExistingText) fromExistingText.textContent = 'Existing opened ' + noun;
                if (fromNewText) fromNewText.textContent = 'Open new ' + noun;
                fromExisting.disabled = opened < 1;
                fromNew.disabled = unopened < 1;
                if (fromExisting.disabled) {
                    fromNew.checked = true;
                } else if (fromNew.disabled) {
                    fromExisting.checked = true;
                } else if (!fromExisting.checked && !fromNew.checked) {
                    fromExisting.checked = true;
                }
                usageRadios.forEach(function (radio) { radio.disabled = false; });
            } else {
                fromExisting.disabled = true;
                fromExisting.checked = false;
                fromNew.disabled = true;
                fromNew.checked = false;
                usageRadios.forEach(function (radio) { radio.disabled = true; radio.checked = false; });
            }
        }

        var showDeplete = partial && opened >= 1 && !!medicineSelect.value;
        if (depleteBlock) depleteBlock.classList.toggle('d-none', !showDeplete);
        if (depleteMedicineId) depleteMedicineId.value = showDeplete ? medicineSelect.value : '';
    }

    function applyMedicineLimit() {
        if (!medicineSelect || !quantityInput) return;
        var selected = medicineSelect.options[medicineSelect.selectedIndex];
        var stock = selected && selected.dataset.stock ? parseInt(selected.dataset.stock, 10) : 0;
        stock = Number.isFinite(stock) ? stock : 0;
        var maxQty = 1;
        quantityInput.min = '1';
        quantityInput.max = String(maxQty);
        quantityInput.setAttribute('min', '1');
        quantityInput.setAttribute('max', String(maxQty));
        quantityInput.value = '1';
        if (parseInt(quantityInput.value, 10) > maxQty) {
            quantityInput.value = String(maxQty);
        }
        if (stock <= 0) {
            helpText.textContent = 'This item is out of stock. Add stock before dispensing.';
            quantityInput.value = '0';
            quantityInput.disabled = true;
        } else {
            helpText.textContent = 'Medicine requests are limited to 1 unit per day. Use the item unit shown above.';
            quantityInput.disabled = false;
            if (parseInt(quantityInput.value, 10) < 1) {
                quantityInput.value = '1';
            }
        }
        applyTracking();
    }

    if (medicineSelect) {
        medicineSelect.addEventListener('change', applyMedicineLimit);
    }
    if (dispenseModal) {
        dispenseModal.addEventListener('hidden.bs.modal', function () {
            var form = dispenseModal.querySelector('form');
            if (form) form.reset();
            if (medicineSelect) medicineSelect.selectedIndex = 0;
            if (quantityInput) quantityInput.value = '1';
            applyMedicineLimit();
        });
    }
    applyMedicineLimit();
});
</script>
@endsection