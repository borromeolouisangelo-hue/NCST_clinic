{{--
    Staff History / logs - the ONE destination every Staff module's View All
    action opens (/staff/history?tab=<section>). Port of staff/history.php
    (original lines 101-189): the seven-section pill navigation (full page
    navigation per click, ?tab= stays the single source of truth), the
    query-error alert (shared StaffHistory::rows never throws - a failed
    section yields the message instead of a blank page), the GET search
    toolbar (hidden tab input, q box, the active section's own field/options
    select with onchange submit, Search + Reset) and the results card whose
    table comes from the same StaffHistory::table() the staff dashboard
    previews with, plus the record counter with its optional "matching
    "q"" suffix.

    Approved differences: the Reset link and the script's var base drop .php
    (var base is an app-origin JSON URL - the only inline-script URL in the
    codebase - masked by full_diff like href/src since it is the same
    approved app-prefix convention; tab navigation is browser-verified);
    rows come back as arrays from StaffHistory.

    Module histories feature: every section is now paginated server-side
    ($pagination, built by StaffHistoryController from ?page= and
    StaffHistory::perPage), so the counter shows the FILTERED TOTAL rather
    than the rows on screen and appends ", page A of B" once more than one
    page exists. The nav below the card carries only the active section's
    own values, so search terms and filters survive paging and never leak
    into another section's links.

    Inventory Deduction Logs feature: the Inventory section renders the
    deduction log (the partial in staff/partials/deduction-log-table) in
    place of the stock snapshot the dashboard previews, and it is the one
    section with a SECOND dropdown (the date window) beside the Type one -
    see the 'second' key in StaffHistory::filterControls(). It keeps the
    log's own 20-row page size (StockLogQueries::PER_PAGE).

    Legacy quirks kept: unknown ?tab= falls back to the FIRST section
    (patients), a filter value submitted for another section falls back to
    "all" for both the dropdown and the query so they can never disagree,
    and singularised ("1 record") with e()'d quote wrappers inside the
    count line keeping raw output via {!! !!}.

    Emission: line 113/115/163/164 end with an EOL echo so their next line
    merges onto the same source line (PHP eats the newline after ?>); the
    button attrs and the small/count line are therefore single Blade lines.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="row g-3">
    <div class="col-12">

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
                    @if ($second !== null)
                        {{-- The Inventory section is the deduction log and carries a
                             second control (the all|today|week|month date window)
                             beside the Type one; every other section has exactly one. --}}
                        <div class="col-auto">
                            <label class="form-label" for="historyFilter2">{{ $second['label'] }}</label>
                            <select id="historyFilter2" name="{{ $secondField }}"
                                    class="form-select form-select-sm" onchange="this.form.submit()">
                                @foreach ($second['options'] as $value => $optionLabel)
                                    <option value="{{ $value }}"{{ $value === $secondVal ? ' selected' : '' }}>{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col d-flex flex-wrap gap-2 justify-content-start">
                        <button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>
                        {{-- Reset drops q and the active section's own filter
                             fields (and any page number) but keeps the tab, so
                             it clears the results without leaving the section. --}}
                        <a href="{{ route('staff.history', ['tab' => $activeTab]) }}" class="btn btn-outline-secondary text-nowrap">Reset</a>
                    </div>
                </form>
            </div>
        </div>

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

        <!-- Card 2 — Full history table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div><i class="bi {{ $meta['icon'] }} me-1"></i> {{ $meta['label'] }} History</div>
                <small class="text-muted fw-normal">
                    {{ $pagination['totalItems'] }} record{{ $pagination['totalItems'] === 1 ? '' : 's' }}{!! $filters['q'] !== '' ? ' matching &ldquo;' . e($filters['q']) . '&rdquo;' : '' !!}{!! $pagination['total'] > 1 ? ', page ' . $pagination['current'] . ' of ' . $pagination['total'] : '' !!}                </small>
            </div>
            <div class="table-responsive">
                @if ($activeTab === 'inventory')
                    {{-- The Inventory section is the deduction log, so it renders its
                         own table (six required figures + Date + Detail) instead of
                         the stock snapshot the dashboard previews. --}}
                    @include('staff.partials.deduction-log-table')
                @else
                    {!! \App\Services\StaffHistory::table($activeTab, $rows, 'staffHistoryTable') !!}                @endif            </div>
        </div>

        @if (($pagination['total'] ?? 0) > 1)
        <nav aria-label="{{ $meta['label'] }} history pagination" class="mt-3">
            <ul class="pagination justify-content-center">
                @if ($pagination['current'] > 1)
                    <li class="page-item">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] - 1])) }}" aria-label="Previous">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                @endif
                @for ($i = max(1, $pagination['current'] - 2); $i <= min($pagination['total'], $pagination['current'] + 2); $i++)
                    <li class="page-item {{ $i === $pagination['current'] ? 'active' : '' }}">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $i])) }}">{{ $i }}</a>
                    </li>
                @endfor
                @if ($pagination['current'] < $pagination['total'])
                    <li class="page-item">
                        <a class="page-link" href="{{ $pagination['baseUrl'] }}?{{ http_build_query(array_merge($pagination['query'], ['page' => $pagination['current'] + 1])) }}" aria-label="Next">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                @endif
            </ul>
        </nav>
        @endif

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
