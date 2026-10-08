{{--
    Sick leave details modal - feature 4.

    The review queue and the History/Dashboard sick-leave tab used to offer
    two icon buttons per row, each opening its own modal (approve/reject), so
    the Actions column was a pair of decisions rather than a place to read a
    request. Both surfaces now render ONE modal per row and the Actions
    column holds nothing but View: this file carries the whole record, and
    while the request is still pending the Accept / Decline / Cancel
    decision lives here too. Decided requests are read-only - the reviewer,
    the timestamp and the note move out of the table cell and in here, so no
    information is lost by collapsing the column.

    Props:
      leave     - the row. The Sick Leaves controller returns stdClass rows
                  and the History tab returns associative arrays, so both are
                  read through one accessor below.
      modalStem - the id shared by the row's View button and this modal. The
                  Sick Leaves queue leaves it unset ("leaveModal{n}") while
                  the History tab passes "histLeaveModal{n}", which already
                  had its own id namespace - the caller writes the same stem
                  on the button, so the two can never drift apart.

    Two details are load-bearing for the tests and for the flow itself:
      - the textarea keeps EXACTLY name/class/rows/required. Decline needs a
        reason (the browser blocks an empty submit), while Accept carries
        formnovalidate so an approving review can leave it blank - the note
        column ends up NULL either way, which is what the controller writes.
      - status travels on the submit buttons themselves (name="status"
        value="approved|rejected") rather than in a hidden input, because
        there is only one form now and the two decisions cannot both be
        pre-set. main.js copies the clicked submitter's name/value into a
        hidden field before it disables the button, so Chromium cannot drop
        it from the form data set.
--}}
@php
    // Rows arrive as stdClass (Sick Leaves queue) or as an assoc array
    // (History tab); one accessor serves both.
    $__leaveGet = function (string $key, $default = null) use ($leave) {
        return is_array($leave)
            ? ($leave[$key] ?? $default)
            : ($leave->{$key} ?? $default);
    };

    $__id     = (int) $__leaveGet('id', 0);
    $__status = (string) $__leaveGet('status', 'pending');
    $__start  = (string) $__leaveGet('start_date', '');
    $__end    = (string) $__leaveGet('end_date', '');
    $__modal  = ($modalStem ?? 'leaveModal') . $__id;

    $__badge = $__status === 'pending' ? 'bg-warning text-dark'
             : ($__status === 'approved' ? 'bg-success' : 'bg-danger');
    $__label = $__status === 'pending' ? 'Pending'
             : ($__status === 'approved' ? 'Approved' : 'Declined');
@endphp
<div class="modal fade" id="{{ $__modal }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="card mb-0 border-0">
                <div class="card-header">Sick Leave Request Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="text-muted small">Employee</div>
                            <strong>{{ $__leaveGet('employee_name') ?: '—' }}</strong>
                            <div class="text-muted">{{ (string) $__leaveGet('employee_no', '') }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Department</div>
                            <div>{{ $__leaveGet('department') ?: '—' }}</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Leave Period</div>
                            {{-- The end date is optional; only join with the en
                                 dash when there is a second date to join, so a
                                 start-only request renders start alone. --}}
                            <div>{{ $__start !== '' ? date('M d, Y', strtotime($__start)) : '—' }}@if ($__end !== '') – {{ date('M d, Y', strtotime($__end)) }}@endif</div>
                        </div>
                        <div class="col-sm-6">
                            <div class="text-muted small">Filed</div>
                            <div>{{ fmt_dt($__leaveGet('created_at'), 'M d, Y g:i A') }}</div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Reason</div>
                            <div>{{ $__leaveGet('reason') ?: '—' }}</div>
                        </div>
                        <div class="col-12">
                            <div class="text-muted small">Status</div>
                            <span class="badge {{ $__badge }}">{{ $__label }}</span>
                        </div>

                        @if ($__status !== 'pending')
                            {{-- Reviewer state used to sit in the Actions cell;
                                 it lives here now that the cell is View only. --}}
                            <div class="col-12">
                                <div class="text-muted small">Reviewed by {{ $__leaveGet('reviewer_name') ?: '—' }} on {{ fmt_dt($__leaveGet('reviewed_at'), 'M d, Y g:i A') }}</div>
                                @if ($__leaveGet('review_note'))
                                    <div class="mt-1"><span class="text-muted small">Note</span><br>{{ $__leaveGet('review_note') }}</div>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($__status === 'pending')
                        <hr class="my-3">
                        {{-- Posts to the Sick Leaves queue so its handler (CSRF,
                             feature-2 notification, redirect) is reused as-is. --}}
                        <form method="post" action="{{ url('staff/sick_leaves') }}">
                            @csrf

                            <input type="hidden" name="do" value="review">
                            <input type="hidden" name="leave_id" value="{{ $__id }}">
                            <div class="mb-3">
                                <div class="form-label mb-1">Review Note <span class="text-muted fw-normal">(required when declining)</span></div>
                                <textarea name="review_note" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                {{-- formnovalidate lets Accept submit with an
                                     empty note; Decline keeps the required
                                     check, so only a decline is blocked. --}}
                                <button type="submit" name="status" value="approved" class="btn btn-success" formnovalidate><i class="bi bi-check-lg me-1"></i> Accept</button>
                                <button type="submit" name="status" value="rejected" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i> Decline</button>
                                <button type="button" class="btn btn-outline-secondary ms-auto" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    @else
                        <hr class="my-3">
                        <div class="d-flex">
                            <button type="button" class="btn btn-outline-secondary ms-auto" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
