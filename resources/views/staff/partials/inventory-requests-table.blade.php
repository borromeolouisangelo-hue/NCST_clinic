@if ($myRequests !== [])
<div class="card mb-3">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:3rem">#</th>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($myRequests as $i => $req)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><span class="badge bg-secondary">{{ \App\Services\InventoryRequestService::typeLabel((string) $req->request_type) }}</span></td>
                    <td>{{ $req->name }}</td>
                    <td>{!! $req->created_at ? e(fmt_dt($req->created_at, 'M d, Y g:i A')) : '—' !!}</td>
                    <td><span class="badge {{ \App\Services\InventoryRequestService::statusBadge((string) $req->status) }}">{{ \App\Services\InventoryRequestService::statusLabel((string) $req->status) }}</span></td>
                    <td class="small">
                        @if ((string) $req->status === 'pending')
                            <span class="text-muted">Awaiting admin review</span>
                        @else
                            {{ $req->reviewer_name ?: 'Admin' }} on {!! $req->reviewed_at ? e(fmt_dt($req->reviewed_at, 'M d, Y g:i A')) : '—' !!}
                        @endif
                        @if ($req->review_note)
                            <div class="text-danger mt-1">{{ $req->review_note }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif