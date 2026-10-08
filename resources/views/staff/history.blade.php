{{--
    Staff History / logs - exact port of staff/history.php (original lines
    101-189): the seven-section pill navigation (full page navigation per
    click, ?tab= stays the single source of truth), the query-error alert
    (shared StaffHistory::rows never throws - a failed section yields the
    message instead of a blank page), the GET search toolbar (hidden tab
    input, q box, the active section's own field/options select with
    onchange submit, Search + Reset) and the results card whose table comes
    from the same StaffHistory::table() the staff dashboard previews with,
    plus the record counter with its optional "matching "q"" suffix.

    Approved differences: the Reset link and the script's var base drop .php
    (var base is an app-origin JSON URL - the only inline-script URL in the
    codebase - masked by full_diff like href/src since it is the same
    approved app-prefix convention; tab navigation is browser-verified);
    rows come back as arrays from StaffHistory.

    Legacy quirks kept: unknown ?tab= falls back to the FIRST section
    (patients), a filter value submitted for another section falls back to
    "all" for both the dropdown and the query so they can never disagree,
    the count is unbounded (no LIMIT) and singularised ("1 record"), and
    e()'d quote wrappers inside the count line keep raw output via {!! !!}.

    Emission: line 113/115/163/164 end with an EOL echo so their next line
    merges onto the same source line (PHP eats the newline after ?>); the
    button attrs and the small/count line are therefore single Blade lines.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col-12">

        <!-- Pill navigation: same Bootstrap nav-pills pattern as the patient
             profile tabs, with its own data-attribute and its own handler so
             the patient-profile feature is untouched. -->
        <ul class="nav nav-pills mb-3 no-print staff-history-tabs" aria-label="History sections">
            @foreach ($tabs as $key => $t)
                <li class="nav-item">
                    <button type="button"
                            class="nav-link{{ $key === $activeTab ? ' active' : '' }}"
                            data-history-tab="{{ $key }}"
                            aria-current="{{ $key === $activeTab ? 'page' : 'false' }}">
                        {{ $t['label'] }}                    </button>
                </li>
            @endforeach
        </ul>

        @if ($queryError !== null)
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-triangle me-1"></i>
                That section could not be loaded. Please try again or contact an administrator.
            </div>
        @endif

        <!-- Card 1 — Search & filter (values are preserved after submission) -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end audit-toolbar">
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <div class="col">
                        <label class="form-label" for="historySearch">Search</label>
                        <input type="search" id="historySearch" name="q"
                               class="form-control form-control-sm"
                               value="{{ $filters['q'] }}"
                               placeholder="{{ $control['placeholder'] }}"
                               aria-label="Search {{ $meta['label'] }}">
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="historyFilter">{{ $control['label'] }}</label>
                        <select id="historyFilter" name="{{ $field }}"
                                class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach ($control['options'] as $value => $optionLabel)
                                <option value="{{ $value }}"{{ $value === $fieldVal ? ' selected' : '' }}>{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                        <a href="{{ url('staff/history?tab=' . $activeTab) }}" class="btn btn-outline-secondary text-nowrap">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card 2 — Full history table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><i class="bi {{ $meta['icon'] }} me-1"></i> {{ $meta['label'] }} History</div>
                <small class="text-muted fw-normal">
                    {{ count($rows) }} record{{ count($rows) === 1 ? '' : 's' }}                    {!! $filters['q'] !== '' ? ' matching &ldquo;' . e($filters['q']) . '&rdquo;' : '' !!}                </small>
            </div>
            <div class="table-responsive">
                        {!! \App\Services\StaffHistory::table($activeTab, $rows, 'staffHistoryTable') !!}            </div>
        </div>

    </div>
</div>

<script>
(function () {
    var base = {!! json_encode(url('staff/history'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    document.querySelectorAll('[data-history-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            // Full navigation rather than a client-side panel swap: ?tab= stays
            // the single source of truth and each section's filters are only
            // ever read for the section that is actually on screen.
            window.location.href = base + '?tab=' + encodeURIComponent(button.getAttribute('data-history-tab'));
        });
    });
})();
</script>

@endsection
