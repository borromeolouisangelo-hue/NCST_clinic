{{--
    Staff Inventory - exact port of staff/inventory.php (original lines
    157-287): the toolbar, the inventory table, the Stock In modal, the
    error auto-open script, and the expiry-toggle JS.

    Approved differences: the Reset link uses route('staff.inventory') so it
    drops .php (routing convention since Phase 4); the modal form carries
    @csrf instead of csrf_token (Phase 6) - the blank line after @csrf
    compensates PHP eating one newline after the compiled directive, so the
    hidden action input keeps the original's exact leading whitespace; row
    keys use -> instead of ['x'] (DB::select stdClass rows, same values);
    POST CSRF failure raises Laravel's 419 instead of flash+redirect
    (Phase 6); the success flashes are written but never rendered
    (write-only legacy slot - see partials/flash.blade.php).

    Legacy quirks kept: ?q= and ?stock= are echoed into the toolbar - both
    dead in the reference, wired up now (?q= LIKE-matches the item and
    category names, ?stock= keeps one in/low/out bucket over the total,
    unknown stock values fall back to no filtering); the threshold/badge
    block runs in a raw PHP block and recomputes what the controller already
    computed; a delete with a missing batch id renders the error inside the
    modal with the auto-open script enabled.

    THREE SEPARATE TABLES: Medicines, First Aid / Wound Care and Equipment
    are three dedicated tables behind navigation pills - the same Bootstrap
    nav-pills + full-navigation-on-?tab= pattern the Transaction History
    page uses (see staff/history.blade.php and its .staff-history-tabs CSS).
    ?tab= is the single source of truth, so each tab owns its own ?q=,
    ?stock=, ?category= and ?page= state and only the active tab's query
    ever runs. The tables stay separate because the data model does:
    medicines and first aid items are one `medicines` table split by
    medicine_categories.type, batches live in medicine_batches, and
    equipment has its own table with its own counters.

      Medicines   - ONE ROW PER LIVE BATCH (FEFO ordered) plus one row for a
                    medicine that has none, so Batch No. and Expiration
                    Date are real per-row values and batches are never
                    merged. Lot No., Supplier and Unit Cost moved to the
                    Batch Details modal. Stock Status stays medicine-level
                    so low-stock alerts are unchanged.
      First Aid   - ONE ROW PER PRODUCT with no Expiration Date column or
                    field at all, matching the StockService expiry bypass;
                    Supplier and Unit Cost moved to the Product Details
                    modal's source breakdown.
      Equipment   - ONE ROW PER ITEM with Serial Number instead of a batch
                    number; quantity-tracked items render a dash rather than
                    an invented serial. Supplier, Unit Cost and Date
                    Received moved to the Equipment Details modal.

    View buttons are explicit <a> buttons (never clickable rows, so they
    cannot swallow a row action) and each carries ?tab=&view=&id= so the
    details modal always shows the record the button belonged to.

    The Threshold column is gone; low_stock_threshold itself is untouched
    and still drives every status badge and ?stock= bucket.

    The Stock In modal keeps its design and its single Category dropdown
    (Medicine / First Aid / Wound Care / Equipment), swapping the field
    groups below it. Supplier, Quantity, Unit Cost and Date Received stay
    outside both groups so they are shared and never duplicated in the
    posted form. It RESTOCKS - the "-- add new equipment --" option is gone
    and re-enforced server-side, because a new record needs admin approval.

    APPROVAL WORKFLOW: a second modal ("New Request") covers everything that
    does not exist yet - a new medicine / first aid item, new equipment, a
    new category or a new supplier - and posts action=request, which parks a
    pending inventory_requests row and notifies the admins. It writes no
    inventory. "My Requests" below the table shows the requester their own
    submissions, the decision and, when declined, the reason. Every Stock In
    submission also carries a per-render restock_token so a double submit
    cannot increase the stock twice.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col">
        <div class="card mb-3 mt-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar mb-2">
                    {{-- The active tab always travels with the search so a
                         query never lands on a different table. --}}
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <div class="col">
                        <label class="form-label">Search inventory</label>
                        <input type="search" name="q" class="form-control form-control-sm" value="{{ $q }}" placeholder="Name or category..." aria-label="Search inventory items">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Filter</label>
                        <select name="stock" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Stock</option>
                            <option value="in" {{ $stock === 'in' ? 'selected' : '' }}>In Stock</option>
                            <option value="low" {{ $stock === 'low' ? 'selected' : '' }}>Low Stock</option>
                            <option value="out" {{ $stock === 'out' ? 'selected' : '' }}>Out of Stock</option>
                        </select>
                    </div>
                        
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
        
                        <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#stockInModal"><i class="bi bi-plus-lg me-1"></i> Add Stock</button>
                        {{-- Add Stock only ever RESTOCKS something that already
                             exists; anything new goes through an admin
                             approval, so it has its own button and modal. --}}
                        <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#requestModal"><i class="bi bi-inbox me-1"></i> New Request</button>
                    </div>
                </form>
                
            </div>
        </div>


        {{-- Navigation pills - the Transaction History pattern. Full
             navigation on click rather than a client-side panel swap, so
             ?tab= stays the single source of truth and each tab's filters
             are only ever read for the table actually on screen. --}}
             

        <ul class="nav nav-pills mb-3 no-print staff-history-tabs" aria-label="Inventory sections">
            @foreach ($tabs as $key => $label)
                <li class="nav-item">
                    <button type="button"
                            class="nav-link{{ $key === $tab ? ' active' : '' }}"
                            data-inventory-tab="{{ $key }}"
                            aria-current="{{ $key === $tab ? 'page' : 'false' }}">
                        {{ $label }}                    </button>
                </li>
            @endforeach

            <div class="d-flex align-items-center w-100">
                    <a href="{{ route('staff.history', ['tab' => 'inventory']) }}" class="btn btn-sm btn-outline-primary text-nowrap ms-auto"><i class="bi bi-receipt me-1"></i> View Deduction Logs</a>
                </div>
        </ul>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><i class="bi {{ $tab === 'equipment' ? 'bi-tools' : ($tab === 'first_aid' ? 'bi-bandaid' : 'bi-capsule') }} me-1"></i> {{ $tabs[$tab] }}</div>
                <small class="text-muted fw-normal">
                    {{ $pagination['totalItems'] }} record{{ $pagination['totalItems'] === 1 ? '' : 's' }}{!! $q !== '' ? ' matching &ldquo;' . e($q) . '&rdquo;' : '' !!}{!! $pagination['total'] > 1 ? ', page ' . $pagination['current'] . ' of ' . $pagination['total'] : '' !!}                </small>
            </div>
            <div class="table-responsive">
            @if ($tab === 'requests')
                @include('staff.partials.inventory-requests-table')
            @elseif ($rows === [])
                <table class="table table-hover mb-0"><tbody><tr><td class="text-center text-muted py-4">No products found.</td></tr></tbody></table>
            @elseif ($tab === 'medicines')
                @include('staff.partials.inventory-medicines-table')
            @elseif ($tab === 'first_aid')
                @include('staff.partials.inventory-first-aid-table')
            @else
                @include('staff.partials.inventory-equipment-table')
            @endif
            </div>
        </div>

        @if (($pagination['total'] ?? 0) > 1)
        <nav aria-label="{{ $tabs[$tab] }} pagination" class="mt-3">
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

{{-- ---------------------------------------------------------------------
     My Requests - this staff member's own submissions and where they got
     to. The record itself is only ever created by an admin approval, so
     this is the requester's view of the approval status and, when a request
     was declined, the reason.
     ------------------------------------------------------------------ --}}


{{-- ---------------------------------------------------------------------
     Stock In - one modal, one Category dropdown, three field groups.
     ------------------------------------------------------------------ --}}
<div class="modal fade" id="stockInModal" tabindex="-1" aria-labelledby="stockInModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                @csrf

                <input type="hidden" name="action" value="receive">
                {{-- One-shot idempotency token, regenerated on every render:
                     a double submit or a browser retry of the SAME form is
                     caught server-side and cannot increase the stock twice. --}}
                <input type="hidden" name="restock_token" value="{{ $restockToken }}">
                <div class="modal-header">
                    <h5 class="modal-title" id="stockInModalLabel"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-modal-body">
                    @foreach ($errors as $err)<div class="alert alert-danger py-2">{{ $err }}</div>@endforeach
                    {{-- One Category dropdown drives which field group shows. --}}
                    <div class="mb-3"><label class="form-label">Category *</label><select id="stockCategorySelect" name="category" class="form-select form-select-sm"><option value="medicine">Medicine</option><option value="first_aid">First Aid / Wound Care</option><option value="equipment">Equipment</option></select></div>

                    {{-- Medicine half - the original fields, unchanged. --}}
                    <div id="stockMedicineFields">
                        <div class="mb-3"><label class="form-label">Medicine / First Aid Item *</label><select id="stockMedicineSelect" name="medicine_id" class="form-select form-select-sm" required><option value="">-- select item --</option>@foreach ($medicines as $m)<option value="{{ (int) $m->id }}" data-item-type="{{ $m->type ?? 'medicine' }}">{{ $m->name }}{!! $m->category_name ? ' (' . e($m->category_name) . ')' : '' !!}</option>@endforeach</select></div>
                        <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Batch No.</label><input type="text" name="batch_no" class="form-control form-control-sm"></div><div class="col-6"><label class="form-label">Lot No.</label><input type="text" name="lot_no" class="form-control form-control-sm"></div></div>
                    </div>

                    {{-- Equipment half: RESTOCKING an existing item only.
                         There is no "-- add new equipment --" path here any
                         more - a new equipment record needs admin approval,
                         so it is requested from the New Request modal and the
                         controller re-enforces the same rule server-side. --}}
                    <div id="stockEquipmentFields" style="display:none">
                        <div class="mb-3"><label class="form-label">Equipment Item *</label><select id="stockEquipmentSelect" name="equipment_id" class="form-select form-select-sm" required><option value="">-- select item --</option>@foreach ($equipmentItems as $eq)<option value="{{ (int) $eq->id }}">{{ $eq->name }}</option>@endforeach</select></div>
                        <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Condition</label><select name="condition" class="form-select form-select-sm"><option value="">— keep current —</option><option value="good">Good</option><option value="needs_repair">Needs Repair</option><option value="damaged">Damaged</option></select></div><div class="col-6"><label class="form-label">Serial Number / Asset ID</label><input type="text" name="asset_id" class="form-control form-control-sm" placeholder="Optional"></div></div>
                        <div class="mb-3"><label class="form-label">Notes</label><input type="text" name="equipment_notes" class="form-control form-control-sm" placeholder="Brand, model or other details (optional)"></div>
                    </div>

                    {{-- Shared by both halves - never duplicated in the form. --}}
                    <div class="mb-3"><label class="form-label">Supplier</label><select name="supplier_id" class="form-select form-select-sm"><option value="">-- none --</option>@foreach ($suppliers as $s)<option value="{{ (int) $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Quantity *</label><input type="number" name="quantity" class="form-control form-control-sm" min="3" value="3" required></div><div class="col-6"><label class="form-label">Unit Cost</label><input type="number" step="0.01" name="unit_cost" class="form-control form-control-sm"></div></div>
                    <div class="row g-2" id="expiryRowWrapper"><div class="col-6" id="stockExpiryField"><label class="form-label">Expiry Date</label><input type="date" id="stockExpiryInput" name="expiry_date" class="form-control form-control-sm"></div><div class="col-6"><label class="form-label">Date Received</label><input type="date" name="date_received" class="form-control form-control-sm" value="{{ date('Y-m-d') }}"></div></div>
                </div>
                <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Stock</button></div>
            </form>
        </div>
    </div>
</div>

{{-- ---------------------------------------------------------------------
     New Request - everything that does not exist yet goes through admin
     approval, so this modal never writes inventory. One Type dropdown
     drives which field group shows, exactly like the Stock In modal.
     ------------------------------------------------------------------ --}}
<div class="modal fade" id="requestModal" tabindex="-1" aria-labelledby="requestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                @csrf

                <input type="hidden" name="action" value="request">
                <div class="modal-header">
                    <h5 class="modal-title" id="requestModalLabel"><i class="bi bi-inbox me-1"></i> Request New Inventory Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-modal-body">
                    @foreach ($errors as $err)<div class="alert alert-danger py-2">{{ $err }}</div>@endforeach
                    <p class="text-muted small mb-3">New records are created once an admin approves this request. To add stock to something that already exists, use <strong>Add Stock</strong> instead.</p>

                    <div class="mb-3"><label class="form-label">Request Type *</label>
                        <select id="requestTypeSelect" name="request_type" class="form-select form-select-sm" required>
                            @foreach ($requestTypes as $key)
                            <option value="{{ $key }}">{{ $requestTypeLabels[$key] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Shared by every type. --}}
                    <div class="mb-3"><label class="form-label">Name *</label><input type="text" name="request_name" class="form-control form-control-sm" maxlength="150" placeholder="Item, category or supplier name" required></div>

                    {{-- Medicine / First Aid / Wound Care item. --}}
                    <div id="reqMedicineFields">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Generic Name</label><input type="text" name="request_generic_name" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label">Unit</label><input type="text" name="request_unit" class="form-control form-control-sm" placeholder="tablet, bottle, pack..."></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Category</label>
                                <select name="request_category_id" class="form-select form-select-sm req-category-select">
                                    <option value="">-- none --</option>
                                    @foreach ($requestCategories as $rc)
                                    <option value="{{ (int) $rc->id }}" data-type="{{ $rc->type }}">{{ $rc->type === 'first_aid' ? 'First Aid / Wound Care' : 'Medicine' }}: {{ $rc->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-6"><label class="form-label">Description</label><input type="text" name="request_description" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-4"><label class="form-label">Initial Quantity *</label><input type="number" name="request_quantity" class="form-control form-control-sm" min="3" value="3" required></div>
                            <div class="col-4"><label class="form-label">Low Stock Threshold</label><input type="number" name="request_threshold" class="form-control form-control-sm" min="0" value="10"></div>
                            <div class="col-4"><label class="form-label">Unit Cost</label><input type="number" step="0.01" name="request_unit_cost" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Batch No.</label><input type="text" name="request_batch_no" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label">Lot No.</label><input type="text" name="request_lot_no" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6" id="reqExpiryRow"><label class="form-label">Expiry Date *</label><input type="date" id="reqExpiryInput" name="request_expiry_date" class="form-control form-control-sm" required></div>
                            <div class="col-6"><label class="form-label">Date Received</label><input type="date" name="request_date_received" class="form-control form-control-sm" value="{{ date('Y-m-d') }}"></div>
                        </div>
                    </div>

                    {{-- Equipment. --}}
                    <div id="reqEquipmentFields" style="display:none">
                        <div class="mb-3"><label class="form-label">Description / Notes</label><input type="text" name="request_description_eq" class="form-control form-control-sm" placeholder="Brand, model or other details"></div>
                        <div class="row g-2 mb-3">
                            <div class="col-4"><label class="form-label">Quantity *</label><input type="number" name="request_quantity_eq" class="form-control form-control-sm" min="1" value="1"></div>
                            <div class="col-4"><label class="form-label">Condition</label>
                                <select name="request_condition" class="form-select form-select-sm">
                                    @foreach ($conditionOptions as $cond)
                                    <option value="{{ $cond }}">{{ \App\Services\EquipmentStockService::conditionLabel($cond) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-4"><label class="form-label">Unit Cost</label><input type="number" step="0.01" name="request_unit_cost_eq" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Serial Number / Asset ID</label><input type="text" name="request_asset_id" class="form-control form-control-sm" placeholder="Optional"></div>
                            <div class="col-6"><label class="form-label">Date Received</label><input type="date" name="request_date_received_eq" class="form-control form-control-sm" value="{{ date('Y-m-d') }}"></div>
                        </div>
                    </div>

                    {{-- Category. --}}
                    <div id="reqCategoryFields" style="display:none">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Category Type *</label>
                                <select name="request_category_type" class="form-select form-select-sm">
                                    <option value="medicine">Medicine</option>
                                    <option value="first_aid">First Aid / Wound Care</option>
                                </select>
                            </div>
                            <div class="col-6"><label class="form-label">Description</label><input type="text" name="request_description_cat" class="form-control form-control-sm"></div>
                        </div>
                    </div>

                    {{-- Supplier. --}}
                    <div id="reqSupplierFields" style="display:none">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="form-label">Contact Person</label><input type="text" name="request_contact_person" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label">Phone</label><input type="text" name="request_phone" class="form-control form-control-sm"></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label">Email</label><input type="email" name="request_email" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label">Address</label><input type="text" name="request_address" class="form-control form-control-sm"></div>
                        </div>
                    </div>

                    {{-- Shared by the two item types. --}}
                    <div class="mt-3" id="reqSupplierRow"><label class="form-label">Supplier</label>
                        <select name="request_supplier_id" class="form-select form-select-sm"><option value="">-- none --</option>@foreach ($suppliers as $s)<option value="{{ (int) $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    </div>
                </div>
                <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Request</button></div>
            </form>
        </div>
    </div>
</div>

{{-- ---------------------------------------------------------------------
     Detail modals. Each one is rendered from the record re-read by id
     (?view=&id=), so it can never show a different record than the button
     that opened it.
     ------------------------------------------------------------------ --}}
@if ($details && $details['kind'] === 'batch')
@php $b = $details['data']; @endphp
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel"><i class="bi bi-upc-scan me-1"></i> Batch Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body compact-modal-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Medicine</dt><dd class="col-7">{{ $b->medicine_name }}</dd>
                    <dt class="col-5">Category</dt><dd class="col-7">{{ $b->category_name ?: '—' }}</dd>
                    <dt class="col-5">Batch No.</dt><dd class="col-7">{{ $b->batch_no ?: '—' }}</dd>
                    <dt class="col-5">Lot No.</dt><dd class="col-7">{{ $b->lot_no ?: '—' }}</dd>
                    <dt class="col-5">Remaining Quantity</dt><dd class="col-7"><strong>{{ (int) $b->quantity }}</strong> {{ $b->unit }}</dd>
                    <dt class="col-5">Expiration Date</dt><dd class="col-7">{!! $b->expiry_date ? e(fmt_dt($b->expiry_date, 'M d, Y')) : '—' !!}</dd>
                    <dt class="col-5">Date Received</dt><dd class="col-7">{!! $b->date_received ? e(fmt_dt($b->date_received, 'M d, Y')) : '—' !!}</dd>
                    <dt class="col-5">Supplier</dt><dd class="col-7">{{ $b->supplier_name ?: '—' }}</dd>
                    <dt class="col-5">Unit Cost</dt><dd class="col-7">{!! $b->unit_cost !== null ? e(number_format((float) $b->unit_cost, 2)) : '—' !!}</dd>
                </dl>
            </div>
            <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endif

@if ($details && $details['kind'] === 'item')
@php $it = $details['data']['medicine']; $itBatches = $details['data']['batches']; @endphp
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel"><i class="bi bi-capsule me-1"></i> Item Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body compact-modal-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Medicine Name</dt><dd class="col-7">{{ $it->name }}</dd>
                    <dt class="col-5">Generic Name</dt><dd class="col-7">{{ $it->generic_name ?: '—' }}</dd>
                    <dt class="col-5">Category</dt><dd class="col-7">{{ $it->category_name ?: '—' }}</dd>
                    <dt class="col-5">Unit</dt><dd class="col-7">{{ $it->unit ?: '—' }}</dd>
                    <dt class="col-5">Description</dt><dd class="col-7">{{ $it->description ?: '—' }}</dd>
                    <dt class="col-5">Low Stock Threshold</dt><dd class="col-7">{{ (int) $it->low_stock_threshold }}</dd>
                    <dt class="col-5">Total Stock</dt><dd class="col-7"><strong>{{ (int) $details['data']['total'] }}</strong></dd>
                </dl>
                <p class="text-muted small mb-2 mt-3">This item has no separately tracked batches.</p>
                @if ($itBatches !== [])
                <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Batch No.</th><th class="text-end">Remaining</th></tr></thead>
                    <tbody>@foreach ($itBatches as $ib)<tr><td>{{ $ib->batch_no ?: '—' }}</td><td class="text-end">{{ (int) $ib->quantity }}</td></tr>@endforeach</tbody>
                </table></div>
                @endif
            </div>
            
            <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endif

@if ($details && $details['kind'] === 'product')
@php $p = $details['data']['medicine']; $pBatches = $details['data']['batches']; @endphp
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel"><i class="bi bi-bandaid me-1"></i> Product Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body compact-modal-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Product Name</dt><dd class="col-7">{{ $p->name }}</dd>
                    <dt class="col-5">Category</dt><dd class="col-7">{{ $p->category_name ?: '—' }}</dd>
                    <dt class="col-5">Unit</dt><dd class="col-7">{{ $p->unit ?: '—' }}</dd>
                    <dt class="col-5">Description</dt><dd class="col-7">{{ $p->description ?: '—' }}</dd>
                    <dt class="col-5">Total Stock</dt><dd class="col-7"><strong>{{ (int) $details['data']['total'] }}</strong></dd>
                    {{-- No expiry row: first aid items ignore the expiry field
                         (StockService::resolveExpiry), so none is shown. --}}
                </dl>
                @if ($pBatches !== [])
                <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Supplier</th><th class="text-end">Unit Cost</th><th class="text-end">Remaining</th><th>Date Received</th></tr></thead>
                    <tbody>
                    @foreach ($pBatches as $pb)
                        <tr>
                            <td>{{ $pb->supplier_name ?: '—' }}</td>
                            <td class="text-end">{!! $pb->unit_cost !== null ? e(number_format((float) $pb->unit_cost, 2)) : '—' !!}</td>
                            <td class="text-end"><strong>{{ (int) $pb->quantity }}</strong></td>
                            <td>{!! $pb->date_received ? e(fmt_dt($pb->date_received, 'M d, Y')) : '—' !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @endif
            </div>
            <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endif

@if ($details && $details['kind'] === 'equipment')
@php $eq = $details['data']['equipment']; $eqLoans = $details['data']['loans']; @endphp
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel"><i class="bi bi-tools me-1"></i> Equipment Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body compact-modal-body">
                <dl class="row mb-0 small">
                    <dt class="col-5">Equipment Name</dt><dd class="col-7">{{ $eq->name }}</dd>
                    <dt class="col-5">Description</dt><dd class="col-7">{{ $eq->description ?: '—' }}</dd>
                    <dt class="col-5">Serial Number / Asset ID</dt><dd class="col-7">{{ $eq->asset_id ?: '—' }}</dd>
                    <dt class="col-5">Condition</dt><dd class="col-7">{{ \App\Services\EquipmentStockService::conditionLabel($eq->condition_note) }}</dd>
                    <dt class="col-5">Total Units</dt><dd class="col-7"><strong>{{ (int) $eq->total_qty }}</strong></dd>
                    <dt class="col-5">Available Units</dt><dd class="col-7"><strong>{{ (int) $eq->available_qty }}</strong></dd>
                    <dt class="col-5">Supplier</dt><dd class="col-7">{{ $eq->supplier_name ?: '—' }}</dd>
                    <dt class="col-5">Unit Cost</dt><dd class="col-7">{!! $eq->unit_cost !== null ? e(number_format((float) $eq->unit_cost, 2)) : '—' !!}</dd>
                    <dt class="col-5">Date Received</dt><dd class="col-7">{!! $eq->last_received ? e(fmt_dt($eq->last_received, 'M d, Y')) : '—' !!}</dd>
                </dl>
                <p class="text-muted small mb-2 mt-3">Loan history</p>
                @if ($eqLoans === [])
                    <p class="text-muted small mb-0">No loans recorded for this item.</p>
                @else
                <div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>Borrowed</th><th>Returned</th><th class="text-end">Qty</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($eqLoans as $loan)
                        <tr>
                            <td>{!! $loan->borrow_date ? e(fmt_dt($loan->borrow_date, 'M d, Y')) : '—' !!}</td>
                            <td>{!! $loan->return_date ? e(fmt_dt($loan->return_date, 'M d, Y')) : '—' !!}</td>
                            <td class="text-end">{{ (int) $loan->quantity }}</td>
                            <td><span class="badge {{ $loan->status === 'returned' ? 'bg-success' : 'bg-warning' }}">{{ $loan->status === 'returned' ? 'Returned' : 'Borrowed' }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @endif
            </div>
            <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endif

{{-- A failed POST reopens the modal it came from, so the errors are read
     where they were typed rather than in an unrelated form. --}}
@if ($openModal)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('{{ $openModal }}'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Pill navigation: full navigation on click, matching the Transaction
    // History handler, so ?tab= stays the single source of truth.
    var inventoryBase = {!! json_encode(url('staff/inventory'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    document.querySelectorAll('[data-inventory-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.location.href = inventoryBase + '?tab=' + encodeURIComponent(button.getAttribute('data-inventory-tab'));
        });
    });

    var stockCategorySelect = document.getElementById('stockCategorySelect');
    var medicineFields = document.getElementById('stockMedicineFields');
    var equipmentFields = document.getElementById('stockEquipmentFields');
    var stockMedicineSelect = document.getElementById('stockMedicineSelect');
    var stockEquipmentSelect = document.getElementById('stockEquipmentSelect');
    var expiryField = document.getElementById('stockExpiryField');
    var expiryInput = document.getElementById('stockExpiryInput');
    var stockModal = document.getElementById('stockInModal');

    // The item selector keeps every option in the page (the server renders
    // the full active list); the category filter only narrows which of them
    // stay selectable.
    var medicineOptions = [];
    if (stockMedicineSelect) {
        for (var i = 0; i < stockMedicineSelect.options.length; i++) {
            medicineOptions.push(stockMedicineSelect.options[i].cloneNode(true));
        }
    }

    function selectedCategory() {
        return stockCategorySelect && stockCategorySelect.value ? stockCategorySelect.value : 'medicine';
    }

    // Swap the field groups: medicine / first aid share one half, equipment
    // shows its own. The hidden half's inputs must not stay required, or
    // HTML5 validation blocks the visible one.
    function toggleCategoryFields() {
        var category = selectedCategory();
        var isEquipment = category === 'equipment';

        if (medicineFields) medicineFields.style.display = isEquipment ? 'none' : 'block';
        if (equipmentFields) equipmentFields.style.display = isEquipment ? 'block' : 'none';

        if (stockMedicineSelect) {
            if (isEquipment) stockMedicineSelect.removeAttribute('required');
            else stockMedicineSelect.setAttribute('required', 'required');
        }
        if (stockEquipmentSelect) {
            if (isEquipment) stockEquipmentSelect.setAttribute('required', 'required');
            else stockEquipmentSelect.removeAttribute('required');
        }

        // Medicine selector narrowed to the chosen category's items.
        if (stockMedicineSelect && medicineOptions.length) {
            var previous = stockMedicineSelect.value;
            while (stockMedicineSelect.options.length) stockMedicineSelect.remove(0);
            stockMedicineSelect.add(medicineOptions[0].cloneNode(true));
            for (var j = 1; j < medicineOptions.length; j++) {
                if (medicineOptions[j].getAttribute('data-item-type') === category) {
                    stockMedicineSelect.add(medicineOptions[j].cloneNode(true));
                }
            }
            stockMedicineSelect.value = previous;
            if (stockMedicineSelect.selectedIndex < 0) stockMedicineSelect.selectedIndex = 0;
        }

        toggleExpiryVisibility();
    }

    // Equipment is not expiry tracked - same rule the first aid branch uses.
    function toggleExpiryVisibility() {
        if (!stockMedicineSelect || !expiryField || !expiryInput) return;
        var selected = stockMedicineSelect.options[stockMedicineSelect.selectedIndex];
        var itemType = selected && selected.dataset.itemType ? selected.dataset.itemType : 'medicine';
        var isFirstAid = itemType === 'first_aid';
        var hidden = isFirstAid || selectedCategory() === 'equipment';
        expiryField.style.display = hidden ? 'none' : 'block';
        if (hidden) {
            expiryInput.value = '';
            expiryInput.removeAttribute('required');
        }
    }

    // "-- add new equipment --" is gone: new equipment needs admin approval,
    // so the selector only ever lists records that already exist.
    if (stockCategorySelect) {
        stockCategorySelect.addEventListener('change', toggleCategoryFields);
    }
    if (stockMedicineSelect) {
        stockMedicineSelect.addEventListener('change', toggleExpiryVisibility);
    }
    if (stockModal) {
        stockModal.addEventListener('hidden.bs.modal', function () {
            var form = stockModal.querySelector('form');
            if (form) form.reset();
            if (stockCategorySelect) {
                stockCategorySelect.selectedIndex = 0;
            }
            if (stockMedicineSelect) {
                stockMedicineSelect.selectedIndex = 0;
            }
            if (stockEquipmentSelect) {
                stockEquipmentSelect.selectedIndex = 0;
            }
            toggleCategoryFields();
        });
    }
    toggleCategoryFields();

    // ------------------------------------------------------------------
    // New Request modal: one Type dropdown swaps the field groups. The
    // hidden groups' inputs keep their own names (one form cannot carry
    // two request_quantity fields), so nothing needs to be disabled for
    // the POST to be unambiguous - the server reads the right set for the
    // posted type.
    // ------------------------------------------------------------------
    var requestTypeSelect = document.getElementById('requestTypeSelect');
    var requestGroups = {
        medicine: document.getElementById('reqMedicineFields'),
        first_aid: document.getElementById('reqMedicineFields'),
        equipment: document.getElementById('reqEquipmentFields'),
        category: document.getElementById('reqCategoryFields'),
        supplier: document.getElementById('reqSupplierFields')
    };
    var reqExpiryRow = document.getElementById('reqExpiryRow');
    var reqExpiryInput = document.getElementById('reqExpiryInput');
    var requestModal = document.getElementById('requestModal');
    var requestNameInput = requestModal ? requestModal.querySelector('input[name="request_name"]') : null;

    function selectedRequestType() {
        var value = requestTypeSelect ? requestTypeSelect.value : '';
        // Anything the server never offered falls back to the first group.
        return (value && requestGroups[value]) ? value : 'medicine';
    }

    function toggleRequestFields() {
        var type = selectedRequestType();
        // Medicine and First Aid share ONE field group (the backend treats
        // them identically), so visibility must be decided per ELEMENT: a
        // key-based test would let the 'first_aid' pass hide the very element
        // the 'medicine' pass just showed, leaving the group invisible.
        var activeGroup = requestGroups[type] || requestGroups.medicine;
        Object.keys(requestGroups).forEach(function (key) {
            var group = requestGroups[key];
            if (group) group.style.display = (group === activeGroup) ? 'block' : 'none';
        });

        // First aid items never track expiry - the same rule the Stock In
        // modal applies - so the row is removed from the request entirely.
        // Medicine batches always carry one, so the field stays required.
        var hideExpiry = (type !== 'medicine');
        if (reqExpiryRow) reqExpiryRow.style.display = hideExpiry ? 'none' : 'block';
        if (reqExpiryInput) {
            if (hideExpiry) {
                reqExpiryInput.value = '';
                reqExpiryInput.removeAttribute('required');
            } else {
                reqExpiryInput.setAttribute('required', 'required');
            }
        }

        if (requestNameInput) {
            requestNameInput.placeholder = ({
                medicine: 'Medicine name',
                first_aid: 'First aid / wound care product name',
                equipment: 'Equipment name',
                category: 'Category name',
                supplier: 'Supplier name'
            })[type] || 'Item, category or supplier name';
        }
    }

    if (requestTypeSelect) {
        requestTypeSelect.addEventListener('change', toggleRequestFields);
    }
    if (requestModal) {
        requestModal.addEventListener('hidden.bs.modal', function () {
            var form = requestModal.querySelector('form');
            if (form) form.reset();
            if (requestTypeSelect) requestTypeSelect.selectedIndex = 0;
            toggleRequestFields();
        });
    }
    toggleRequestFields();

    // A View button navigated to ?view=&id=, so the matching details modal
    // shows itself once the page loads.
    var detailModal = document.getElementById('detailModal');
    if (detailModal && window.bootstrap) new bootstrap.Modal(detailModal).show();
});
</script>
@endsection
