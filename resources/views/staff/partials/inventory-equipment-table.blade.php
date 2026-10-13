{{--
    Equipment tab - ONE ROW PER ITEM off the existing equipment table, the
    very counters EquipmentLoanService moves on issue and return.

    Serial Number replaces Batch No. Equipment here is quantity-tracked
    (total_qty / available_qty), so an item without an asset id renders a
    dash - a serial is never invented for it.

    Status keeps the four availability labels the old Equipment card used
    (Inactive / Unavailable / On loan / Available); there is no
    low_stock_threshold on equipment, so the medicine ladder does not apply.

    Supplier, Unit Cost and Date Received are NOT table columns - purchase
    details live behind the View button in the Equipment Details modal
    (with the loan history), keeping the table readable.
--}}
@php
    $rowOffset = ($pagination['current'] - 1) * $pagination['perPage'];
@endphp
<table id="inventoryTable" class="table table-hover mb-0 align-middle">
    <thead>
        <tr>
            <th>#</th>
            <th>Equipment Name</th>
            <th>Category / Type</th>
            <th>Serial Number</th>
            <th class="text-end">Quantity / Available</th>
            <th>Condition</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
@foreach ($rows as $index => $row)
        @php
            $viewUrl = route('staff.inventory', ['tab' => 'equipment', 'view' => 'equipment', 'id' => $row->viewId]);
        @endphp
        <tr>
            <td>{{ $rowOffset + $index + 1 }}</td>
            <td>{{ $row->name }}</td>
            <td><span class="badge bg-secondary">{{ $row->type }}</span></td>
            <td>{{ $row->serial ?: '—' }}</td>
            <td class="text-end">
                <strong>{{ (int) $row->qty }}</strong>
                <small class="text-muted d-block">{{ (int) $row->available }} available</small>
            </td>
            <td>{{ \App\Services\EquipmentStockService::conditionLabel($row->condition) }}</td>
            <td><span class="badge {{ $row->status_badge }}">{{ $row->status_label }}</span></td>
            <td class="text-end">
                <a href="{{ $viewUrl }}" class="btn btn-sm btn-outline-primary" title="View equipment details"><i class="bi bi-eye me-1"></i></a>
            </td>
        </tr>
@endforeach
    </tbody>
</table>
