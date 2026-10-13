        <table{!! $tableId !== '' ? ' id="' . e($tableId) . '"' : '' !!} class="table table-hover mb-0 align-middle">
            <thead>
            {!! $hdr !!}            </thead>
            <tbody>
            @if (! $rows)
                <tr><td colspan="{{ (int) $colspan }}" class="text-center text-muted py-4">{{ $emptyText ?? 'No records found.' }}</td></tr>
            @else
@php
    // Post-migration piece state (feature 1) - one OUT query + one settings
    // query for the whole page, inventory tab only. See StockUnits.
    $stockStates = $tab === 'inventory' ? \App\Services\StockUnits::states($rows) : [];
@endphp
@foreach ($rows as $i => $r)
@switch($tab)
@case('patients')
                                        <tr>
                            <td>{{ (int) $r['id'] }}</td>
                            <td>{{ (string) ($r['full_name'] ?: $r['username']) }}</td>
                            <td>{{ (string) ($r['student_no'] ?: $r['employee_no'] ?: '—') }}</td>
                            <td><span class="badge {{ $r['account_type'] === 'student' ? 'bg-info text-dark' : 'bg-primary' }}">{{ ucfirst((string) $r['account_type']) }}</span></td>
                            <td>{{ (string) ($r['contact_no'] ?: '—') }}</td>
                            <td>{!! $r['status'] === 'active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
                            <td class="text-end">
                                <a href="{{ url('staff/patients?id=' . (int) $r['id']) }}" class="btn btn-sm btn-outline-primary" title="View patient"><i class="bi bi-eye"></i></a>
                                
                            </td>
                        </tr>
                        @break
@case('consultations')
                                        <tr>
                            <td>{{ fmt_dt($r['consultation_date'], 'M d, Y g:i A') }}</td>
                            <td>{{ (string) ($r['patient_name'] ?: '—') }}</td>
                            <td>{{ mb_strimwidth((string) ($r['complaint'] ?? ''), 0, 40, '…') }}</td>
                            <td>{{ mb_strimwidth((string) ($r['diagnosis'] ?? ''), 0, 40, '…') }}</td>
                            <td class="text-end">
                                @if (! empty($r['diagnosis_image']))<a href="{{ route('consultations.attachment', ['id' => (int) $r['id'], 'type' => 'diagnosis']) }}" class="btn btn-sm btn-outline-secondary" title="View diagnosis image" target="_blank" rel="noopener"><i class="bi bi-image-alt"></i></a>@endif
                                @if (! empty($r['treatment_image']))<a href="{{ route('consultations.attachment', ['id' => (int) $r['id'], 'type' => 'treatment']) }}" class="btn btn-sm btn-outline-secondary" title="View treatment image" target="_blank" rel="noopener"><i class="bi bi-image"></i></a>@endif
                                <a href="{{ url('staff/consultations?edit=' . (int) $r['id']) }}" class="btn btn-sm btn-outline-primary" title="Edit consultation"><i class="bi bi-pencil"></i></a>
                                <a href="{{ url('staff/consultations?action=delete&id=' . (int) $r['id']) }}" class="btn btn-sm btn-outline-danger" title="Delete consultation" data-confirm="Delete this consultation?" data-swal-delete="consultation"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        @break
@case('certificates')
                                        <tr>
                            <td>{{ (string) ($r['patient_name'] ?: '—') }}</td>
                            <td>{{ $r['gender'] ? ucfirst((string) $r['gender']) : '—' }} / {{ $r['birthdate'] ? age_from_birthdate($r['birthdate']) : '—' }}</td>
                            <td>{{ fmt_dt($r['issued_at'], 'M d, Y') }}</td>
                            <td>{{ mb_strimwidth((string) ($r['purpose'] ?? ''), 0, 30, '…') }}</td>
                            <td>{{ (string) ($r['patient_name'] ?: '—') }}</td>
                            <td class="text-end actions-cell">
                                <a href="{{ url('staff/certificates?print=' . (int) $r['id']) }}" class="btn btn-sm btn-outline-primary" title="Print certificate"><i class="bi bi-printer"></i></a>
                                <a href="{{ url('staff/certificates?action=delete&id=' . (int) $r['id']) }}" class="btn btn-sm btn-outline-danger" title="Delete certificate" data-confirm="Delete this certificate?" data-swal-delete="certificate"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        @break
@case('sick-leaves')
@php
    $sickStatus = (string) $r['status'];
    $sickLabel  = $sickStatus === 'rejected' ? 'Declined' : ucfirst($sickStatus);
    $sickClass  = $sickStatus === 'pending' ? 'bg-warning text-dark'
                : ($sickStatus === 'approved' ? 'bg-success' : 'bg-danger');
@endphp
                                        <tr>
                            <td>
                                <strong>{{ (string) ($r['employee_name'] ?: '—') }}</strong><br>
                                <small class="text-muted">{{ (string) ($r['employee_no'] ?? '') }}</small>
                            </td>
                            <td>{{ (string) ($r['department'] ?? '—') }}</td>
                            <td>{{ mb_strimwidth((string) $r['reason'], 0, 60, '…') }}</td>
                            <td>
                                {{ date('M d, Y', strtotime($r['start_date'])) }}                                @if (! empty($r['end_date'])) – {{ date('M d, Y', strtotime($r['end_date'])) }}@endif
                            </td>
                            <td>{{ fmt_dt($r['created_at'], 'M d, Y g:i A') }}</td>
                            <td><span class="badge {{ $sickClass }}">{{ $sickLabel }}</span></td>
                            <td class="text-end">
                                {{-- Feature 4: same view-only column as the Sick
                                     Leaves queue. One details modal per row -
                                     carrying the reviewer and note for decided
                                     requests, and the Accept / Decline / Cancel
                                     decision while one is still pending. --}}
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#histLeaveModal{{ (int) $r['id'] }}"><i class="bi bi-eye me-1"></i> View</button>
                            </td>
                        </tr>
                        {{-- The modal posts to the existing Sick Leaves page so its
                             handler (CSRF + feature-2 notification + redirect)
                             is reused unchanged. --}}
                        @include('staff.partials.leave-details-modal', ['leave' => $r, 'modalStem' => 'histLeaveModal'])
@break
@case('dispense')
                                        <tr>
                            <td>{{ fmt_dt($r['requested_at'], 'M d, Y g:i A') }}</td>
                            <td>{{ (string) ($r['patient_name'] ?: '—') }}</td>
                            <td>{{ (string) $r['medicine_name'] }}</td>
                            <td class="text-end">{{ (int) $r['quantity'] }}</td>
                            <td>{{ (string) ($r['reason'] ?: '—') }}</td>
                            <td>
                                <span class="badge {{ $r['status'] === 'dispensed' ? 'bg-success' : 'bg-danger' }}">{{ $r['status'] === 'rejected' ? 'Declined' : ucfirst((string) $r['status']) }}</span>
                            </td>
                            <td>{{ (string) ($r['processed_username'] ?: '—') }}</td>
                        </tr>
                        @break
@case('inventory')
@php
    $threshold = max(1, (int) ($r['low_stock_threshold'] ?? 1));
    $qty       = (int) ($r['total_qty'] ?? 0);
    $nextExp   = $r['next_expiry_date'] ?? null;
    $today     = date('Y-m-d');
    $soon      = $nextExp && strtotime($nextExp) >= strtotime($today) && strtotime($nextExp) <= strtotime('+30 days');
    $expired   = (int) ($r['has_expired'] ?? 0) === 1;
    if ($qty <= 0) {
        $stockBadge = 'bg-secondary'; $statusLabel = 'Out of stock';
    } elseif ($qty <= floor($threshold * 0.25)) {
        $stockBadge = 'bg-danger'; $statusLabel = 'Critical';
    } elseif ($qty <= floor($threshold * 0.5)) {
        $stockBadge = 'bg-warning'; $statusLabel = 'Low';
    } else {
        $stockBadge = 'bg-success'; $statusLabel = 'Healthy';
    }
    // pieces_per_unit was removed from the schema - pieces == stock units.
    $totalPieces = $qty;
    // Tracking state (App\Services\StockUnits): the package position for
    // quantitative Individual items ("11/12 deducted", "20/50 deducted";
    // plain stock count for units without a package size) and the opened
    // ratio for Container + Partial / Roll + Partial items ("0/16
    // opened", "1/4 opened" - read-only here; the Mark Depleted button
    // lives on the dispensing screen).
    $stockState = $stockStates[(int) ($r['id'] ?? 0)] ?? null;
@endphp
                                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ (string) $r['medicine_name'] }}@if (! empty($r['category_name'])) <small class="text-muted d-block">{{ (string) $r['category_name'] }}</small>@endif</td>
                            <td><span class="badge bg-secondary">{{ $r['item_type'] === 'first_aid' ? 'First Aid / Wound Care' : 'Medicine' }}</span></td>
                            <td class="text-end"><strong>{{ $qty }}</strong></td>
                            <td>{{ (string) ($r['unit'] ?: 'pcs') }}</td>
                            <td class="text-end">@if ($stockState)<span class="badge {{ $stockState['badge'] }}">{{ $stockState['text'] }}</span>@else{{ $totalPieces }}@endif</td>
                            <td>{{ $threshold }}</td>
                            <td>@if ($expired)<span class="badge bg-secondary">Expired</span>@elseif ($soon)<span class="badge bg-secondary">Expiring soon</span>@else<span class="badge {{ $stockBadge }}">{{ $statusLabel }}</span>@endif</td>
                            <td>{{ $nextExp ? fmt_dt($nextExp, 'M d, Y') : '—' }}</td>
                            <td>{{ ! empty($r['last_received']) ? fmt_dt($r['last_received'], 'M d, Y') : '—' }}</td>
                        </tr>
                        @break
@case('equipment-loans')
@php
    // Feature 3: same SQL-clock expression as this tab's own status filter
    // (StaffHistory), so the badge never contradicts the list.
    $overdue = (int) ($r['is_overdue'] ?? 0) === 1;
@endphp
                                        <tr>
                            <td>{{ (string) ($r['borrower_name'] ?: '—') }}</td>
                            <td>{{ (string) $r['equipment_name'] }}</td>
                            <td class="text-end">{{ (int) $r['quantity'] }}</td>
                            <td>{{ fmt_dt($r['borrowed_at'], 'M d, Y g:i A') }}</td>
                            <td>{{ fmt_dt(date('Y-m-d H:i:s', strtotime($r['borrowed_at']) + 86400), 'M d, Y g:i A') }}</td>
                            <td>
                                @if ($r['status'] === 'returned')
                                    <span class="badge bg-success">Returned</span>
                                @elseif ($overdue)
                                    <span class="badge bg-danger">Overdue</span>
                                @else
                                    <span class="badge bg-orange">Borrowed</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($r['status'] === 'borrowed')
                                    <!-- Posts to the existing Equipment Loans page so its
                                         handler (stock restore + audit) is reused unchanged. -->
                                    <form method="post" class="d-inline" data-confirm="Mark as returned?" action="{{ url('staff/equipment_loans') }}">
                                        @csrf

                                        <input type="hidden" name="do" value="return">
                                        <input type="hidden" name="loan_id" value="{{ (int) $r['id'] }}">
                                        <button class="btn btn-sm btn-accent"><i class="bi bi-box-arrow-in-down"></i> Return</button>
                                    </form>
                                @else
                                    <span class="text-muted small">{{ fmt_dt($r['return_date'], 'M d, Y') }}</span>
                                @endif
                            </td>
                        </tr>
                        @break
@default
                        <tr><td colspan="{{ (int) $colspan }}">Unknown section.</td></tr>
                        @endswitch
            @endforeach
@endif
            </tbody>
        </table>
        