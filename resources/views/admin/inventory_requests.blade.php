{{--
    Admin - Inventory Requests: the approval queue for every inventory record
    staff are not allowed to create for themselves.

    Same shape as the rest of the admin pages: one self-posting endpoint, a
    GET toolbar (status + type filters, search, Reset) and a table, with the
    full record in a details modal opened by an explicit Review button -
    never a clickable row - through ?view=request&id=.

    The review form mirrors the Sick Leaves review queue (partials/
    leave-details-modal.blade.php): a hidden do=review field plus two submit
    buttons carrying the decision, Accept with formnovalidate so it may post
    with an empty note, Decline keeping the rejection reason's required
    check. main.js copies the clicked submitter's name/value into a hidden
    field before disabling it, so both decisions survive the spinner.

    Nothing here writes inventory directly - approving calls
    InventoryRequestService::decide(), whose compare-and-set on
    status='pending' inside one transaction is what guarantees the initial
    record and its first stock quantity are created exactly once.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col">
        <div class="card mb-3 mt-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar mb-2">
                    <div class="col-auto">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach (\App\Services\InventoryRequestService::STATUS_LABELS as $key => $label)
                            <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $label }} ({{ (int) ($counts[$key] ?? 0) }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="" {{ $type === '' ? 'selected' : '' }}>All Types</option>
                            @foreach (\App\Services\InventoryRequestService::TYPE_LABELS as $key => $label)
                            <option value="{{ $key }}" {{ $type === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Search</label>
                        <input type="search" name="q" class="form-control form-control-sm" value="{{ $q }}" placeholder="Item name or requester..." aria-label="Search inventory requests">
                    </div>
                    <div class="col-auto d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                        <a href="{{ route('admin.inventory_requests', ['status' => $status]) }}" class="btn btn-sm btn-outline-secondary text-nowrap">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><i class="bi bi-inbox me-1"></i> Inventory Requests</div>
                <small class="text-muted fw-normal">
                    {{ $pagination['totalItems'] }} record{{ $pagination['totalItems'] === 1 ? '' : 's' }}{!! $q !== '' ? ' matching &ldquo;' . e($q) . '&rdquo;' : '' !!}{!! $pagination['total'] > 1 ? ', page ' . $pagination['current'] . ' of ' . $pagination['total'] : '' !!}                </small>
            </div>
            <div class="table-responsive">
                @if ($rows === [])
                    <table class="table table-hover mb-0"><tbody><tr><td class="text-center text-muted py-4">No inventory requests found.</td></tr></tbody></table>
                @else
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width:3rem">#</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Requested By</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $i => $row)
                        <tr>
                            <td>{{ ($pagination['current'] - 1) * $pagination['perPage'] + $i + 1 }}</td>
                            <td><span class="badge bg-secondary">{{ \App\Services\InventoryRequestService::typeLabel((string) $row->request_type) }}</span></td>
                            <td>{{ $row->name }}</td>
                            <td>{{ $row->full_name }}</td>
                            <td>{!! $row->created_at ? e(fmt_dt($row->created_at, 'M d, Y g:i A')) : '—' !!}</td>
                            <td><span class="badge {{ \App\Services\InventoryRequestService::statusBadge((string) $row->status) }}">{{ \App\Services\InventoryRequestService::statusLabel((string) $row->status) }}</span></td>
                            <td class="text-end">
                                {{-- Explicit button, never a clickable row, and it
                                     carries the request id so the modal can only
                                     ever show this record. --}}
                                <a href="{{ route('admin.inventory_requests', ['status' => $status, 'type' => $type, 'q' => $q, 'view' => 'request', 'id' => (int) $row->id]) }}" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-eye me-1"></i> Review</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        @if (($pagination['total'] ?? 0) > 1)
        <nav aria-label="Inventory requests pagination" class="mt-3">
            <ul class="pagination justify-content-center">
                @if ($pagination['current'] > 1)
                    <li class="page-item">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] - 1])) }}" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
                    </li>
                @endif
                @for ($i = max(1, $pagination['current'] - 2); $i <= min($pagination['total'], $pagination['current'] + 2); $i++)
                    <li class="page-item {{ $i === $pagination['current'] ? 'active' : '' }}">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $i])) }}">{{ $i }}</a>
                    </li>
                @endfor
                @if ($pagination['current'] < $pagination['total'])
                    <li class="page-item">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] + 1])) }}" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
                    </li>
                @endif
            </ul>
        </nav>
        @endif
    </div>
</div>

{{-- ---------------------------------------------------------------------
     Request details + review. Re-read by id, so the modal can only ever
     show the record its Review button belonged to.
     ------------------------------------------------------------------ --}}
@if ($details)
@php
    $r = $details;
    $d = $r->data;
    $isPending = (string) $r->status === 'pending';
    $isItem = in_array((string) $r->request_type, ['medicine', 'first_aid', 'equipment'], true);
    $catName = null;
    if ($isItem && ($d['category_id'] ?? null)) {
        try {
            $catName = \Illuminate\Support\Facades\DB::table('medicine_categories')->where('id', $d['category_id'])->value('name');
        } catch (\Throwable $e) {
            $catName = null;
        }
    }
    $supName = null;
    if ($isItem && ($d['supplier_id'] ?? null)) {
        try {
            $supName = \Illuminate\Support\Facades\DB::table('suppliers')->where('id', $d['supplier_id'])->value('name');
        } catch (\Throwable $e) {
            $supName = null;
        }
    }
@endphp
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel"><i class="bi bi-clipboard-check me-1"></i> {{ \App\Services\InventoryRequestService::typeLabel((string) $r->request_type) }} Request #{{ (int) $r->id }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body compact-modal-body">
                @foreach ($errors as $err)<div class="alert alert-danger py-2">{{ $err }}</div>@endforeach

                <dl class="row mb-0 small">
                    <dt class="col-5">Name</dt><dd class="col-7"><strong>{{ $r->name }}</strong></dd>
                    <dt class="col-5">Requested By</dt><dd class="col-7">{{ $r->full_name }}</dd>
                    <dt class="col-5">Submitted</dt><dd class="col-7">{!! $r->created_at ? e(fmt_dt($r->created_at, 'M d, Y g:i A')) : '—' !!}</dd>
                    <dt class="col-5">Status</dt><dd class="col-7"><span class="badge {{ \App\Services\InventoryRequestService::statusBadge((string) $r->status) }}">{{ \App\Services\InventoryRequestService::statusLabel((string) $r->status) }}</span></dd>

                    @if ((string) $r->request_type === 'medicine' || (string) $r->request_type === 'first_aid')
                        <dt class="col-5">Generic Name</dt><dd class="col-7">{{ ($d['generic_name'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Category</dt><dd class="col-7">{{ $catName ?: '—' }}</dd>
                        <dt class="col-5">Unit</dt><dd class="col-7">{{ ($d['unit'] ?? '') ?: 'tablet' }}</dd>
                        <dt class="col-5">Description</dt><dd class="col-7">{{ ($d['description'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Low Stock Threshold</dt><dd class="col-7">{{ (int) ($d['low_stock_threshold'] ?? 10) }}</dd>
                        <dt class="col-5">Initial Quantity</dt><dd class="col-7"><strong>{{ (int) ($d['initial_quantity'] ?? 0) }}</strong></dd>
                        <dt class="col-5">Batch No.</dt><dd class="col-7">{{ ($d['batch_no'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Lot No.</dt><dd class="col-7">{{ ($d['lot_no'] ?? '') ?: '—' }}</dd>
                        {{-- No expiry row for first aid: it is never tracked. --}}
                        @if ((string) $r->request_type === 'medicine')
                        <dt class="col-5">Expiry Date</dt><dd class="col-7">{!! ($d['expiry_date'] ?? null) ? e(fmt_dt($d['expiry_date'], 'M d, Y')) : '—' !!}</dd>
                        @endif
                    @elseif ((string) $r->request_type === 'equipment')
                        <dt class="col-5">Description / Notes</dt><dd class="col-7">{{ ($d['description'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Quantity</dt><dd class="col-7"><strong>{{ (int) ($d['quantity'] ?? 0) }}</strong></dd>
                        <dt class="col-5">Condition</dt><dd class="col-7">{{ \App\Services\EquipmentStockService::conditionLabel($d['condition'] ?? null) }}</dd>
                        <dt class="col-5">Serial Number / Asset ID</dt><dd class="col-7">{{ ($d['asset_id'] ?? '') ?: '—' }}</dd>
                    @elseif ((string) $r->request_type === 'category')
                        <dt class="col-5">Category Type</dt><dd class="col-7">{{ ($d['category_type'] ?? '') === 'first_aid' ? 'First Aid / Wound Care' : 'Medicine' }}</dd>
                        <dt class="col-5">Description</dt><dd class="col-7">{{ ($d['description'] ?? '') ?: '—' }}</dd>
                    @else
                        <dt class="col-5">Contact Person</dt><dd class="col-7">{{ ($d['contact_person'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Phone</dt><dd class="col-7">{{ ($d['phone'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Email</dt><dd class="col-7">{{ ($d['email'] ?? '') ?: '—' }}</dd>
                        <dt class="col-5">Address</dt><dd class="col-7">{{ ($d['address'] ?? '') ?: '—' }}</dd>
                    @endif

                    @if ($isItem)
                    <dt class="col-5">Supplier</dt><dd class="col-7">{{ $supName ?: '—' }}</dd>
                    <dt class="col-5">Unit Cost</dt><dd class="col-7">{!! isset($d['unit_cost']) && $d['unit_cost'] !== null ? e(number_format((float) $d['unit_cost'], 2)) : '—' !!}</dd>
                    <dt class="col-5">Date Received</dt><dd class="col-7">{!! ($d['date_received'] ?? null) ? e(fmt_dt($d['date_received'], 'M d, Y')) : '—' !!}</dd>
                    @endif
                </dl>

                @if (! $isPending)
                <hr class="my-3">
                <div class="text-muted small">
                    Reviewed by {{ $r->reviewer_name ?: '—' }} on {!! $r->reviewed_at ? e(fmt_dt($r->reviewed_at, 'M d, Y g:i A')) : '—' !!}
                    @if ($r->review_note)
                        <div class="mt-1"><span class="text-muted small">Reason</span><br>{{ $r->review_note }}</div>
                    @endif
                    @if ((string) $r->status === 'approved' && $r->record_id)
                        <div class="mt-1">Created record: #{{ (int) $r->record_id }}</div>
                    @endif
                </div>
                @else
                <hr class="my-3">
                {{-- Posts back to this same queue so the controller's decide()
                     path (CSRF, transaction, notifications, redirect) is
                     reused as-is. --}}
                <form method="post" action="{{ url('admin/inventory_requests') }}">
                    @csrf

                    <input type="hidden" name="do" value="review">
                    <input type="hidden" name="request_id" value="{{ (int) $r->id }}">
                    <div class="mb-3">
                        <div class="form-label mb-1">Rejection Reason <span class="text-muted fw-normal">(required when declining)</span></div>
                        <textarea name="review_note" class="form-control" rows="2" required></textarea>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        {{-- Approving creates the active record and its initial
                             stock exactly once, so it confirms on the form
                             level (main.js reads data-confirm off the form);
                             formnovalidate lets it post with an empty note,
                             while Decline keeps the required check. --}}
                        <button type="submit" name="status" value="approved" class="btn btn-success" formnovalidate><i class="bi bi-check-lg me-1"></i> Approve</button>
                        <button type="submit" name="status" value="rejected" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i> Decline</button>
                        <button type="button" class="btn btn-outline-secondary ms-auto" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
                @endif
            </div>
            <div class="modal-footer compact-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    var detailModal = document.getElementById('detailModal');
    if (detailModal && window.bootstrap) new bootstrap.Modal(detailModal).show();
});
</script>
@endsection
