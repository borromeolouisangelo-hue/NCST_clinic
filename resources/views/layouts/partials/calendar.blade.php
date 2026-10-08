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
--}}
@if (!empty($calendarEvents))
    <div class="card mb-2 dashboard-calendar-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div><i class="bi bi-calendar-event me-1"></i> Clinic Calendar</div>
            <small class="text-muted">{{ count($calendarEvents) }} event{{ count($calendarEvents) !== 1 ? 's' : '' }}</small>
        </div>
        <div class="card-body p-1">
            <div id="dashboardCalendar"
                 data-events="{{ json_encode($calendarEvents, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) }}"
                 style="max-height: 200px;"></div>
        </div>
    </div>
@endif