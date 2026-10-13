{{--
    Staff Equipment Loans - exact port of staff/equipment_loans.php
    (original lines 112-286): the issue modal (borrower combobox datalist,
    equipment select with data-available/data-limit), the search/status
    toolbar, the loan table with 24-hour timeout badges, the return form
    per borrowed row, and the quantity-limit JS.

    Approved differences: the issue and return forms carry @csrf at the
    original csrf_token lines (Phase 6) - the blank line after each @csrf
    compensates PHP eating one newline after the compiled directive, so
    the following hidden input keeps the original's exact leading
    whitespace; row keys use -> instead of ['x'] (DB::select stdClass
    rows); status options compare against the controller's statusFilter
    (identical results for absent/present/junk values); the default loan
    limit calls get_setting_int without the legacy $pdo argument; success
    flashes are written but never rendered (write-only legacy slot - see
    partials/flash.blade.php).

    Legacy quirks kept: the status filter defaults to 'borrowed', treats
    'overdue' as borrowed within the last 24 hours, whitelists
    borrowed/returned, and any other value renders ALL rows; the ?q= box
    is a client-side live filter only; quantity is locked to 1 server-side
    while the JS still manages the max; the whitespace-only lines inside
    the toolbar match the reference byte-for-byte.

    Post-migration "All" filter: the select gained an explicit All option
    (status=all - the backend drops the status WHERE clause only, the same
    path the legacy junk values take, while q/page/status pagination keeps
    working) and the GET form carries an active ?q= through a hidden input
    so switching status never drops the search.

    Post-migration feature 18: History pagination and overdue notifications.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col-12">
        <div class="modal fade" id="issueEquipmentModal" tabindex="-1" aria-labelledby="issueEquipmentModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-md">
                <div class="modal-content">
                    <div class="card">
            <div class="card-header" id="issueEquipmentModalLabel">Issue Equipment</div>
            <div class="card-body">
                @foreach ($errors as $err)
                    <div class="alert alert-danger py-2">{{ $err }}</div>
                @endforeach
                <form method="post">
                    @csrf

                    <input type="hidden" name="do" value="issue">

                    <div class="mb-3">
                        <label class="form-label">Borrower *</label>
                        <input type="search" class="form-control" data-patient-combobox="#loanPatientId" list="loanPatientSuggestions" autocomplete="off" placeholder="Search borrower name or ID..." aria-label="Search borrower" required>
                        <input type="hidden" id="loanPatientId" name="user_id" value="" required>
                        <datalist id="loanPatientSuggestions">
                            @foreach ($patients as $pt)
                                @php $patientId = $pt->student_no ?: $pt->employee_no; @endphp
                                <option value="{{ ($pt->full_name ?: ('User #' . $pt->id)) . ($patientId ? ' - ' . $patientId : '') }}" data-user-id="{{ (int) $pt->id }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Equipment *</label>
                        <select name="equipment_id" id="loanEquipmentSelect" class="form-select" required>
                            <option value="">-- select --</option>
                            @php $defaultLoanLimit = get_setting_int('default_equipment_loan_limit', 5); @endphp
                            @foreach ($equipment as $eq)@php $limit = min((int) $eq->total_qty, $defaultLoanLimit); @endphp
                                <option value="{{ (int) $eq->id }}" data-available="{{ (int) $eq->available_qty }}" data-limit="{{ (int) $limit }}">{{ $eq->name }} ({{ (int) $eq->available_qty }} available)</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" name="quantity" id="loanQuantity" class="form-control" min="1" value="1">
                        <div id="loanQuantityHelp" class="form-text">Maximum available units for the selected equipment will be applied automatically.</div>
                    </div>
                    <div class="alert alert-info small py-2">Equipment must be returned within 24 hours. A warning will be sent to the borrower after the timeout.</div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                     
                
                <div class="d-flex gap-2">
    <button type="submit" class="btn btn-accent "
        data-swal-add="equipment loan">
        <i class="bi bi-box-arrow-up me-1"></i> Issue Equipment
    </button>

    <button type="button" class="btn btn-outline-secondary "
        data-bs-dismiss="modal">
        Cancel
    </button>
</div>
            </form>
            </div>
            
        </div>
        
            </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card mb-3 mt-3 ">
            <div class="card-body">
                <div class="row g-2 align-items-end audit-toolbar mb-2">
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input id="loanSearch" type="search" class="form-control form-control-sm" data-live-filter="#loanTable tbody tr" data-live-filter-count="loanResultCount" placeholder="Search visible loans..." aria-label="Search loans">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <form method="get" class="d-inline">
                            <input type="hidden" name="q" value="{{ request('q') }}">
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>All</option>
                                <option value="borrowed" {{ $statusFilter === 'borrowed' ? 'selected' : '' }}>Borrowed</option>
                                <option value="returned" {{ $statusFilter === 'returned' ? 'selected' : '' }}>Returned</option>
                                <option value="overdue" {{ $statusFilter === 'overdue' ? 'selected' : '' }}>Overdue</option>
                            </select>
                        </form>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        
                        <button type="button" class="btn  btn-primary text-nowrap" data-search-trigger="#loanSearch"><i class="bi bi-search me-1"></i> Search</button>

                        <button type="button" class="btn btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#issueEquipmentModal"><i class="bi bi-box-arrow-up me-1"></i> Issue Equipment</button>
                    </div>
                </div>
                
            </div>
        </div>

                    <div class="d-flex align-items-center w-100 mb-2">
                        <a href="{{ route('staff.history', ['tab' => 'equipment-loans']) }}" class="btn btn-sm btn-outline-primary text-nowrap ms-auto"><i class="bi bi-list-ul me-1"></i> View All</a>
                    </div>

        <div class="card">
            <div class="table-responsive">
                {!! \App\Services\StaffHistory::table('equipment-loans', $loans, 'loanTable', 'No loans in this view.') !!}
            </div>
        </div>
</div>
</div>
@if ($errors)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('issueEquipmentModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    var equipmentSelect = document.getElementById('loanEquipmentSelect');
    var quantityInput = document.getElementById('loanQuantity');
    var helpText = document.getElementById('loanQuantityHelp');
    var equipmentModal = document.getElementById('issueEquipmentModal');

    function applyEquipmentLimit() {
        if (!equipmentSelect || !quantityInput) return;
        var selected = equipmentSelect.options[equipmentSelect.selectedIndex];
        var available = selected && selected.dataset.available ? parseInt(selected.dataset.available, 10) : 0;
        available = Number.isFinite(available) ? available : 0;
        var maxQty = 1;
        quantityInput.min = '1';
        quantityInput.max = String(maxQty);
        quantityInput.setAttribute('min', '1');
        quantityInput.setAttribute('max', String(maxQty));
        quantityInput.value = '1';
        if (parseInt(quantityInput.value, 10) > maxQty) {
            quantityInput.value = String(maxQty);
        }
        if (available <= 0) {
            helpText.textContent = 'This equipment is currently unavailable for loan.';
            quantityInput.value = '0';
            quantityInput.disabled = true;
        } else {
            helpText.textContent = 'Equipment requests are limited to 1 item per request.';
            quantityInput.disabled = false;
            if (parseInt(quantityInput.value, 10) < 1) {
                quantityInput.value = '1';
            }
        }
    }

    if (equipmentSelect) {
        equipmentSelect.addEventListener('change', applyEquipmentLimit);
    }
    if (equipmentModal) {
        equipmentModal.addEventListener('hidden.bs.modal', function () {
            var form = equipmentModal.querySelector('form');
            if (form) form.reset();
            if (equipmentSelect) equipmentSelect.selectedIndex = 0;
            if (quantityInput) quantityInput.value = '1';
            applyEquipmentLimit();
        });
    }
    applyEquipmentLimit();
});
</script>
@endsection