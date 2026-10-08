{{--
    Staff Inventory - exact port of staff/inventory.php (original lines
    157-287): the metrics-less toolbar (search + stock filter), the grouped
    inventory table, the Stock In modal, the error auto-open script, and the
    expiry-toggle JS.

    Approved differences: the Reset link uses route('staff.inventory') so it
    drops .php (routing convention since Phase 4); the modal form carries
    @csrf instead of csrf_token (Phase 6) - the blank line after @csrf
    compensates PHP eating one newline after the compiled directive, so the
    hidden action input keeps the original's exact leading whitespace; row
    keys use -> instead of ['x'] (DB::select stdClass rows, same values);
    POST CSRF failure raises Laravel's 419 instead of flash+redirect
    (Phase 6); the success flashes are written but never rendered
    (write-only legacy slot - see partials/flash.blade.php).

    Legacy quirks kept: ?q= and ?stock= are echoed into the toolbar but
    NEVER filter anything (dead inputs); ?item_type= filters and (as a
    post-migration addition) now also has a visible toolbar field alongside
    the new ?category= filter over the existing medicine_categories tree
    (invalid values still fall back to 'all' / no category); the empty-state
    colspan is 9 although the table has 10 columns; first aid items ignore
    the expiry field (the CASE lookup lives in the controller); the
    threshold/badge block runs in a raw PHP block and recomputes what the
    controller already computed; a delete with a missing batch id renders
    the error inside the modal with the auto-open script enabled.

    Post-migration feature: the "Total Pieces" cell now renders the derived
    piece state - 11/12 for tablets (12 per package), 49/50 for boxes (50 per
    box) - and, for the non-piece units (bottle, tube, roll, piece, ...), the
    Full / Half Empty / Empty level buttons that post action=set_level back
    to this page. See App\Services\StockUnits.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col">
        <div class="card mb-3 mt-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar mb-2">
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
                    <div class="col-auto">
                        <label class="form-label">Item Type</label>
                        <select name="item_type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="all" {{ $filterType === 'all' ? 'selected' : '' }}>All Types</option>
                            <option value="medicine" {{ $filterType === 'medicine' ? 'selected' : '' }}>Medicine</option>
                            <option value="first_aid" {{ $filterType === 'first_aid' ? 'selected' : '' }}>First Aid / Wound Care</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="0">All Categories</option>
                            @foreach (['medicine' => 'Medicine', 'first_aid' => 'First Aid / Wound Care'] as $groupType => $groupLabel)
                                @if (collect($categories)->where('type', $groupType)->isNotEmpty())
                                <optgroup label="{{ $groupLabel }}">
                                    @foreach ($categories as $cat)
                                        @if ((string) $cat->type === $groupType)
                                        <option value="{{ (int) $cat->id }}" {{ $filterCategory === (int) $cat->id ? 'selected' : '' }}>{!! $cat->parent_id !== null ? '&nbsp;&nbsp;&nbsp;&#9500;&nbsp;' : '' !!}{{ $cat->name }}</option>
                                        @endif
                                    @endforeach
                                </optgroup>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        
                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                        <a href="{{ route('staff.inventory') }}" class="btn btn-sm btn-outline-secondary text-nowrap">Reset</a>
                        <button type="button" class="btn btn-sm btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#stockInModal"><i class="bi bi-plus-lg me-1"></i> Add Stock</button>
                    </div>
                </form>
            </div>
        </div>
        @if (!empty($levelError))<div class="alert alert-danger py-2 mb-3">{{ $levelError }}</div>@endif
        <div class="card"><div class="table-responsive"><table id="inventoryTable" class="table table-hover mb-0 align-middle"><thead><tr><th>#</th><th>Item</th><th>Type</th><th class="text-end">Total Stock</th><th>Unit</th><th class="text-end">Total Pieces</th><th>Threshold</th><th>Status</th><th>Next Expiry</th><th>Last Received</th></tr></thead>
        @if (!$filteredInventory)
            <tr><td colspan="9" class="text-center text-muted py-4">No products found.</td></tr>
        @else
@foreach ($filteredInventory as $index => $row)
            @php
                $threshold = max(1, (int) ($row->low_stock_threshold ?? 1));
                $qty = (int) ($row->total_qty ?? 0);
                $nextExpiry = $row->next_expiry_date ?? null;
                $isExpiringSoon = $nextExpiry && strtotime($nextExpiry) >= strtotime($today) && strtotime($nextExpiry) <= strtotime('+30 days');
                $isExpired = (int) ($row->has_expired ?? 0) === 1;
                if ($qty <= 0) {
                    $stockBadge = 'bg-secondary';
                    $statusLabel = 'Out of stock';
                } elseif ($qty <= floor($threshold * 0.25)) {
                    $stockBadge = 'bg-danger';
                    $statusLabel = 'Critical';
                } elseif ($qty <= floor($threshold * 0.5)) {
                    $stockBadge = 'bg-warning';
                    $statusLabel = 'Low';
                } else {
                    $stockBadge = 'bg-success';
                    $statusLabel = 'Healthy';
                }
            @endphp
            @php
            $totalPieces = $qty; // Default 1 piece per unit since pieces_per_unit column removed
            $stockState = $stockStates[$row->id] ?? null;
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $row->medicine_name }}@if (!empty($row->category_name)) <small class="text-muted d-block">{{ $row->category_name }}</small>@endif</td>
                <td><span class="badge bg-secondary">{{ $row->item_type === 'first_aid' ? 'First Aid / Wound Care' : 'Medicine' }}</span></td>
                <td class="text-end"><strong>{{ $qty }}</strong></td>
                <td>{{ $row->unit ?: 'pcs' }}</td>
                <td class="text-end">
                    @if ($stockState && $stockState['tracked'])
                        <span class="badge {{ $stockState['badge'] }}" title="Pieces left in the open package - {{ $stockState['size'] }} per {{ $row->unit }}">{{ $stockState['text'] }}</span>
                    @elseif ($stockState)
                        <form method="post" class="d-inline">
                            @csrf

                            <input type="hidden" name="action" value="set_level">
                            <input type="hidden" name="medicine_id" value="{{ (int) $row->id }}">
                            <div class="btn-group btn-group-sm" role="group" aria-label="Stock level for {{ $row->medicine_name }}">
                                <button type="submit" name="level" value="full" class="btn {{ $stockState['level'] === 'full' ? 'btn-success' : 'btn-outline-success' }}" title="Full">Full</button>
                                <button type="submit" name="level" value="half" class="btn {{ $stockState['level'] === 'half' ? 'bg-orange text-white' : 'btn-outline-warning' }}" title="Half Empty">Half</button>
                                <button type="submit" name="level" value="empty" class="btn {{ $stockState['level'] === 'empty' ? 'btn-danger' : 'btn-outline-danger' }}" title="Empty">Empty</button>
                            </div>
                        </form>
                    @else
                        {{ $totalPieces }}
                    @endif
                </td>
                <td>{{ $threshold }}</td>
                <td>@if ($isExpired)<span class="badge bg-secondary">Expired</span>@elseif ($isExpiringSoon)<span class="badge bg-secondary">Expiring soon</span>@else<span class="badge {{ $stockBadge }}">{{ $statusLabel }}</span>@endif</td>
                <td>{!! $nextExpiry ? e(fmt_dt($nextExpiry, 'M d, Y')) : '—' !!}</td>
                <td>{!! !empty($row->last_received) ? e(fmt_dt($row->last_received, 'M d, Y')) : '—' !!}</td>
            </tr>
        @endforeach
        @endif
        </tbody></table></div></div>
    </div>
</div>
<div class="modal fade" id="stockInModal" tabindex="-1" aria-labelledby="stockInModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                @csrf

                <input type="hidden" name="action" value="receive">
                <div class="modal-header">
                    <h5 class="modal-title" id="stockInModalLabel"><i class="bi bi-box-arrow-in-down me-1"></i> Stock In</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body compact-modal-body">
                    @foreach ($errors as $err)<div class="alert alert-danger py-2">{{ $err }}</div>@endforeach
                    <div class="mb-3"><label class="form-label">Medicine / First Aid Item *</label><select id="stockMedicineSelect" name="medicine_id" class="form-select form-select-sm" required><option value="">-- select item --</option>@foreach ($medicines as $m)<option value="{{ (int) $m->id }}" data-item-type="{{ $m->type ?? 'medicine' }}">{{ $m->name }}{!! $m->category_name ? ' (' . e($m->category_name) . ')' : '' !!}</option>@endforeach</select></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Batch No.</label><input type="text" name="batch_no" class="form-control form-control-sm"></div><div class="col-6"><label class="form-label">Lot No.</label><input type="text" name="lot_no" class="form-control form-control-sm"></div></div>
                    <div class="mb-3"><label class="form-label">Supplier</label><select name="supplier_id" class="form-select form-select-sm"><option value="">-- none --</option>@foreach ($suppliers as $s)<option value="{{ (int) $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label">Quantity *</label><input type="number" name="quantity" class="form-control form-control-sm" min="3" value="3" required></div><div class="col-6"><label class="form-label">Unit Cost</label><input type="number" step="0.01" name="unit_cost" class="form-control form-control-sm"></div></div>
                    <div class="row g-2" id="expiryRowWrapper"><div class="col-6" id="stockExpiryField"><label class="form-label">Expiry Date</label><input type="date" id="stockExpiryInput" name="expiry_date" class="form-control form-control-sm"></div><div class="col-6"><label class="form-label">Date Received</label><input type="date" name="date_received" class="form-control form-control-sm" value="{{ date('Y-m-d') }}"></div></div>
                </div>
                <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Stock</button></div>
            </form>
        </div>
    </div>
</div>
@if ($errors)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('stockInModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
<script>
document.addEventListener('DOMContentLoaded', function () {
    var stockMedicineSelect = document.getElementById('stockMedicineSelect');
    var expiryField = document.getElementById('stockExpiryField');
    var expiryInput = document.getElementById('stockExpiryInput');
    var stockModal = document.getElementById('stockInModal');

    function toggleExpiryVisibility() {
        if (!stockMedicineSelect || !expiryField || !expiryInput) return;
        var selected = stockMedicineSelect.options[stockMedicineSelect.selectedIndex];
        var itemType = selected && selected.dataset.itemType ? selected.dataset.itemType : 'medicine';
        var isFirstAid = itemType === 'first_aid';
        expiryField.style.display = isFirstAid ? 'none' : 'block';
        if (isFirstAid) {
            expiryInput.value = '';
            expiryInput.removeAttribute('required');
        }
    }

    if (stockMedicineSelect) {
        stockMedicineSelect.addEventListener('change', toggleExpiryVisibility);
    }
    if (stockModal) {
        stockModal.addEventListener('hidden.bs.modal', function () {
            var form = stockModal.querySelector('form');
            if (form) form.reset();
            if (stockMedicineSelect) {
                stockMedicineSelect.selectedIndex = 0;
            }
            toggleExpiryVisibility();
        });
    }
    toggleExpiryVisibility();
});
</script>
@endsection
