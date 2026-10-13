{{--
    Medicines tab - ONE ROW PER LIVE BATCH (plus one row for a medicine that
    has none), so Batch No. and Expiration Date are real per-row values and
    different batches are never merged into one record. Rows are already
    FEFO ordered by the controller.

    Lot No., Supplier and Unit Cost are NOT table columns - secondary data
    that belongs to a single batch lives behind the View button in the
    Batch Details modal, keeping the table focused on quick monitoring.

    Stock Status stays MEDICINE-level (the low_stock_threshold ladder), so a
    low-stock alert is never hidden just because one batch is large.

    Quantity / Stock is the batch's remaining quantity; the second line is
    the existing StockUnits tracking state for the medicine (package
    position / opened ratio), kept so dispensing deductions stay visible.

    The View button is an explicit <a> carrying tab/view/id, never a
    clickable row, so it cannot swallow a row action.
--}}
@php
    $rowOffset = ($pagination['current'] - 1) * $pagination['perPage'];
@endphp
<table id="inventoryTable" class="table table-hover mb-0 align-middle">
    <thead>
        <tr>
            <th>#</th>
            <th>Medicine Name</th>
            <th>Category</th>
            <th>Batch No.</th>
            <th class="text-end">Quantity / Stock</th>
            <th>Unit</th>
            <th>Expiration Date</th>
            <th>Stock Status</th>
            <th class="text-end">Actions</th>
        </tr>
    </thead>
    <tbody>
@foreach ($rows as $index => $row)
        @php
            $isExpired = $row->expiry !== null && strtotime($row->expiry) < strtotime($today);
            $isExpiringSoon = $row->expiry !== null && ! $isExpired
                && strtotime($row->expiry) <= strtotime('+30 days');
            $stockState = $stockStates[$row->medicine_id] ?? null;
            $totalPieces = $row->medicine_total;
            $viewUrl = route('staff.inventory', ['tab' => 'medicines', 'view' => $row->kind, 'id' => $row->viewId]);
        @endphp
        <tr>
            <td>{{ $rowOffset + $index + 1 }}</td>
            <td>{{ $row->name }}</td>
            <td><span class="badge bg-secondary">{{ $row->category ?: 'Uncategorised' }}</span></td>
            {{-- Written inline so the cell renders as a clean
                 <td>B-100</td> rather than padded with the Blade
                 directive's own newlines. --}}
            <td>@if ($row->batch_no){{ $row->batch_no }}@elseif ($row->kind === 'batch')—@else<span class="text-muted">No batches</span>@endif</td>
            <td class="text-end">
                <strong>{{ (int) $row->qty }}</strong>
                {{-- The existing tracking state (App\Services\StockUnits):
                     package position for counted units, opened ratio for
                     container / roll items. --}}
                @if ($stockState)
                    <small class="text-muted d-block">{{ $stockState['text'] }}</small>
                @elseif ($row->kind === 'item')
                    <small class="text-muted d-block">{{ $totalPieces }} in stock</small>
                @endif
            </td>
            <td>{{ $row->unit ?: '—' }}</td>
            <td>
                @if ($row->expiry === null)
                    —
                @else
                    {!! e(fmt_dt($row->expiry, 'M d, Y')) !!}
                    @if ($isExpired)<span class="badge bg-secondary d-block">Expired</span>
                    @elseif ($isExpiringSoon)<span class="badge bg-secondary d-block">Expiring soon</span>@endif
                @endif
            </td>
            <td><span class="badge {{ $row->status_badge }}">{{ $row->status_label }}</span></td>
            <td class="text-end">
                <a href="{{ $viewUrl }}" class="btn btn-sm btn-outline-primary" title="View {{ $row->kind === 'batch' ? 'batch' : 'item' }} details"><i class="bi bi-eye me-1"></i></a>
            </td>
        </tr>
@endforeach
    </tbody>
</table>
