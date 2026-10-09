{{--
    Dashboard Calendar Partial (Feature 10)

    Renders a compact FullCalendar instance populated with clinic events.
    Controllers should pass an $calendarEvents array of event objects:
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

    The partial is designed to be compact (max-height 350px) and fit in
    dashboard sidebars or below the KPI cards.

    Two optional variables reuse it elsewhere without a second calendar:
      $calendarFull      - full-page mode (resources/views/calendar.blade.php):
                           always render the card and give the grid room;
                           calendar.js still initializes the one
                           #dashboardCalendar element on the page.
      $calendarViewAllUrl - renders a "View All" button in the card header
                           (the staff dashboard passes route('staff.calendar')).
    When neither is passed - the admin and user dashboards - the compact card
    renders exactly as it always has.
--}}
@php
    $calendarFull = !empty($calendarFull);
    $calendarViewAllUrl = $calendarViewAllUrl ?? null;
    $eventCount = count($calendarEvents ?? []);
@endphp
@if ($calendarFull || $eventCount > 0)
    <div class="card mb-2 dashboard-calendar-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-calendar-event me-1"></i> Clinic Calendar</div>
            @if ($calendarViewAllUrl)
                <div class="d-flex align-items-center gap-2">
                    <a href="{{ $calendarViewAllUrl }}" class="btn btn-sm btn-outline-primary">View All</a>
                    <small class="text-muted">{{ $eventCount }} event{{ $eventCount !== 1 ? 's' : '' }}</small>
                </div>
            @else
                <small class="text-muted">{{ $eventCount }} event{{ $eventCount !== 1 ? 's' : '' }}</small>
            @endif
        </div>
        <div class="card-body p-1">
            <div id="dashboardCalendar"
                 data-events="{{ json_encode($calendarEvents ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                 style="max-height: {{ $calendarFull ? '70vh' : '200px' }};"></div>
        </div>
    </div>
@endif
