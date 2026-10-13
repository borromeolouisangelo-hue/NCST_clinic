{{--
    Clinic Calendar Partial (Feature 10)

    One calendar implementation for the whole app: a FullCalendar instance
    populated with role-scoped clinic events, rendered in one of two modes.

    Controllers include it with an $calendarEvents array of event objects:
      [
        {
          title: 'Consultation: Juan Dela Cruz',
          start: '2026-10-05 10:00',
          end: '2026-10-05 10:30',
          type: 'consultation',
          color: '#2f7bbd',
          url: '/staff/consultations?edit=123',
          description: 'Consultation for Juan Dela Cruz (Student)'
        },
        ...
      ]

    Optional variables:
      $calendarFull        - full-page mode (resources/views/calendar.blade.php):
                             the card always renders and the grid gets a tall,
                             fixed viewport-height area (month/week/list views).
      $calendarViewAllUrl  - renders the "View Calendar" button in the card
                             header (the staff dashboard passes
                             route('staff.calendar')). Its label is fixed so
                             dashboard and page headers stay consistent.
      $calendarId          - DOM id for the calendar element; defaults to
                             'dashboardCalendar' (calendar.js back-compat and
                             the single-instance contract the tests pin).
                             Pass a unique id when a second instance is ever
                             needed - calendar.js initializes every element
                             carrying the js-clinic-calendar class, once each.
      $calendarClass       - extra classes for the card section (e.g. 'h-100'
                             to match a sibling chart card's height).

    Modes:
      compact (default) - month view, prev/today/next only, no event times,
                          at most 2 events per day; fits dashboard sidebars.
      full              - month/week/list views with prev/next/today
                          navigation and event times shown.

    Markup, styles and behaviour are separated: this file is Blade markup
    only; styling lives in assets/css/style.css (scoped under .clinic-calendar)
    and behaviour in assets/js/calendar.js (multi-instance, init-once).
--}}
@php
    $calendarFull = !empty($calendarFull);
    $calendarViewAllUrl = $calendarViewAllUrl ?? null;
    $calendarId = $calendarId ?? 'dashboardCalendar';
    $calendarClass = trim((string) ($calendarClass ?? ''));
    $eventCount = count($calendarEvents ?? []);
@endphp
@if ($calendarFull || $eventCount > 0)
    <section class="card mb-2 dashboard-calendar-card clinic-calendar-card {{ $calendarFull ? 'clinic-calendar-card--full' : 'clinic-calendar-card--mini' }} {{ $calendarClass }}">

        {{-- Card header: title left, event count + full-page link right --}}
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="clinic-calendar-title">
                <i class="bi bi-calendar-event me-1"></i> Clinic Calendar
            </div>
            <div class="d-flex align-items-center gap-2">
                <small class="text-muted">{{ $eventCount }} event{{ $eventCount !== 1 ? 's' : '' }}</small>
                @if ($calendarViewAllUrl)
                    <a href="{{ $calendarViewAllUrl }}" class="btn btn-sm btn-outline-primary">View Calendar</a>
                @endif
            </div>
        </div>

        {{-- Calendar body: the element calendar.js initializes. The JSON
             event payload rides on data-events (HTML-escaped for safety in
             attribute context). Mode tells calendar.js which config to
             build; styling differences live in CSS only. --}}
        <div class="card-body clinic-calendar-body">
            <div id="{{ $calendarId }}"
                 class="clinic-calendar js-clinic-calendar {{ $calendarFull ? 'clinic-calendar--full' : 'clinic-calendar--mini' }}"
                 data-clinic-calendar
                 data-mode="{{ $calendarFull ? 'full' : 'mini' }}"
                 data-events="{{ json_encode($calendarEvents ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"></div>
        </div>
    </section>
@endif
