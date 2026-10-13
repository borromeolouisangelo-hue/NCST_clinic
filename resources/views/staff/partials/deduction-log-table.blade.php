{{--
    The deduction-log table - the six figures the Inventory Deduction Logs
    feature requires, in their required order (Item, Type, Total Stock,
    Unit, Quantity Deducted, Total Pieces Remaining) plus Date and Detail,
    so each row's timestamp and its loan-vs-dispense story stay readable.

    Rendered only by the Transaction History page's Inventory section
    (/staff/history?tab=inventory), which owns the toolbar and the
    pagination nav; this partial is just the table. Every figure is
    reconstructed from the movement / loan history by
    StockLogQueries::withStockContext() - the schema stores no running
    balance - and nothing here writes.

    Rows are the union of both stock systems, newest event first:
    medicine OUT movements (what deduct_stock_trace() and
    StockUnits::depleteOpened() write, so individual-piece dispenses and
    container/roll depletions both appear and nothing else does) and
    equipment availability events (borrow as -quantity, completed return
    as +quantity, so a temporary loan is never presented as a permanent
    loss and its restoration stays visible). Stock IN is receiving, not a
    deduction, and a rejected or uncompleted request writes no movement,
    so neither can appear.
--}}
<table id="deductionLogTable" class="table table-hover mb-0 align-middle">
    <thead>
        <tr>
            <th>Item</th>
            <th>Type</th>
            <th class="text-end" title="Stock level before this event">Total Stock</th>
            <th>Unit</th>
            <th class="text-end" title="Units this event deducted (negative) or restored (positive)">Quantity Deducted</th>
            <th class="text-end" title="Stock level remaining after this event">Total Pieces Remaining</th>
            <th>Date</th>
            <th>Detail</th>
        </tr>
    </thead>
    <tbody>
    @forelse ($rows as $d)
        <tr>
            <td>{{ $d->item_name }}</td>
            <td>
                @if ($d->type_key === 'equipment')
                    <span class="badge bg-primary">Equipment</span>
                @else
                    <span class="badge bg-secondary">Medicine</span>
                @endif
            </td>
            <td class="text-end"><strong>{{ $d->stock_before }}</strong></td>
            <td>{{ $d->unit }}</td>
            <td class="text-end {{ (int) $d->delta < 0 ? 'text-danger' : 'text-success' }}">{{ (int) $d->delta > 0 ? '+' : '' }}{{ $d->delta }}</td>
            <td class="text-end"><strong>{{ $d->stock_after }}</strong></td>
            <td>
                @if ($d->event_kind === 'return')
                    {{ fmt_dt($d->event_at, 'M d, Y') }}
                @else
                    {{ fmt_dt($d->event_at, 'M d, Y g:i A') }}
                @endif
            </td>
            <td>{{ $d->detail !== '' ? $d->detail : '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-center text-muted py-4">No deduction records found.</td></tr>
    @endforelse
    </tbody>
</table>
