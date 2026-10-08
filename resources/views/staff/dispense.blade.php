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
    failure raises 419 (Phase 6); the success flash is written but never
    rendered (write-only legacy slot - see partials/flash.blade.php).

    Legacy quirks kept: ?user_id= preselects the patient on GET but
    medicine_id is POST-only; qty must be exactly 1 (a missing quantity
    reports the 1-unit rule before the limit rule); an unknown ?history_status
    shows every row with no selected option; history is capped at 200 rows;
    the conflicts list, decision block and auto-open script re-render on any
    POST that reached the conflict checks.

    Post-migration feature: #dispensePieceState is a read-only line under the
    quantity help text fed by the controller's $pieceStates JSON - the piece
    state shown on the inventory page (11/12 for tablets, 49/50 for boxes) or
    the manual Full / Half Empty / Empty level. The option markup itself is
    untouched.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
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
                            <button type="submit" name="allergy_decision" value="reject" class="btn btn-outline-secondary flex-fill" formnovalidate><i class="bi bi-x-circle me-1"></i> Reject Request</button>
                        </div>
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-secondary w-100" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    @else
                        <div class="d-flex gap-2">
                            <button class="btn btn-accent flex-fill" data-swal-add="medicine request"><i class="bi bi-prescription2 me-1"></i> Dispense Item</button>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    @endif
                </form>
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
                        <input id="dispenseHistorySearch" type="search" class="form-control form-control-sm" data-live-filter="#dispenseHistoryTable tbody tr" data-live-filter-count="dispenseHistoryResultCount" placeholder="Search visible history..." aria-label="Search medicine history">
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
    var pieceStates = @json($pieceStates);

    // Post-migration feature: mirrors the inventory page's piece display in
    // the dispensing form - 11/12 for tablets (12 per package), 49/50 for
    // boxes (50 per box), or the manual Full / Half Empty / Empty level.
    function applyPieceState() {
        if (!pieceStateEl || !medicineSelect) return;
        var st = pieceStates[medicineSelect.value];
        if (!st) {
            pieceStateEl.textContent = '';
            return;
        }
        pieceStateEl.textContent = st.tracked
            ? ('Pieces in the open package: ' + st.text + ' (' + st.size + ' per ' + st.unit + ')')
            : ('Fill level: ' + st.text + ' (' + st.unit + ')');
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
        applyPieceState();
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