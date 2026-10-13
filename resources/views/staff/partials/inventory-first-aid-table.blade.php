{{--
    First Aid / Wound Care tab - ONE ROW PER PRODUCT, no Expiration Date
    column at all. First aid items already ignore the posted expiry field
    (StockService::resolveExpiry's item-type CASE lookup), so none is
    displayed or required here.

    Supplier and Unit Cost are NOT table columns - per-batch source data
    lives behind the View button in the Product Details modal's stock /
    source breakdown (supplier, unit cost, remaining quantity, date
    received), so nothing needed for review is lost.

    Stock Status is the same low_stock_threshold ladder the Medicines tab
    uses, so low-stock alerts behave identically for both.
--}}
@php
    $rowOffset = ($pagination['current'] - 1) * $pagination['perPage'];
@endphp
<table id="inventoryTable" class="table table-hover mb-0 align-middle">
    <thead>
        <tr>
            <th>#</th>
            <th>Product Name</th>
            <th>Category</th>
            <th class="text-end">Quantity / Stock</th>
            <th>Unit</th>
            <th>Stock Status</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
@foreach ($rows as $index => $row)
        @php
            $stockState = $stockStates[$row->medicine_id] ?? null;
            $totalPieces = $row->medicine_total;
            $viewUrl = route('staff.inventory', ['tab' => 'first_aid', 'view' => 'product', 'id' => $row->viewId]);
        @endphp
        <tr>
            <td>{{ $rowOffset + $index + 1 }}</td>
            <td>{{ $row->name }}</td>
            <td><span class="badge bg-secondary">{{ $row->category ?: 'First Aid / Wound Care' }}</span></td>
            <td class="text-end">
                <strong>{{ (int) $row->qty }}</strong>
                @if ($stockState)
                    <small class="text-muted d-block">{{ $stockState['text'] }}</small>
                @endif
            </td>
            <td>{{ $row->unit ?: '—' }}</td>
            <td><span class="badge {{ $row->status_badge }}">{{ $row->status_label }}</span></td>
            <td class="text-end">
                <a href="{{ $viewUrl }}" class="btn btn-sm btn-outline-primary" title="View product details"><i class="bi bi-eye me-1"></i></a>
            </td>
        </tr>
@endforeach
    </tbody>
</table>
