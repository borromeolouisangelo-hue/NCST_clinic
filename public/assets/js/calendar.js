/* ========================================================================
   School Clinic IMS - Dashboard Calendar (Feature 10)
   ========================================================================
   Compact interactive calendar showing clinic-related activities:
   - Consultations (staff sees all, patients see own)
   - Sick leaves (employees see own, staff/admin see all pending)
   - Equipment return deadlines (borrowers see own, staff see all)
   - Medicine dispensing (staff sees all)
   ======================================================================== */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var calendarEl = document.getElementById('dashboardCalendar');
        if (!calendarEl) return;

        // Build the events array from data attributes on the calendar element
        // Data is injected by the controller via the calendar partial
        var events = [];
        try {
            events = JSON.parse(calendarEl.dataset.events || '[]');
        } catch (e) {
            console.warn('Calendar: failed to parse events data', e);
        }

        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek,listWeek'
            },
            buttonText: {
                today: 'Today',
                month: 'Month',
                week: 'Week',
                list: 'List'
            },
            height: 'auto',
            events: events,
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                meridiem: 'short'
            },
            eventDisplay: 'block',
            eventDidMount: function (info) {
                // Add tooltip with details
                var el = info.el;
                var title = info.event.title;
                var desc = info.event.extendedProps.description || '';
                var url = info.event.url;

                el.setAttribute('title', title + (desc ? ': ' + desc : ''));

                // Make events clickable to view details
                if (url) {
                    el.style.cursor = 'pointer';
                    el.addEventListener('click', function () {
                        window.location.href = url;
                    });
                }
            },
            dayCellDidMount: function (info) {
                // Highlight today
                if (info.dateStr === new Date().toISOString().split('T')[0]) {
                    info.el.classList.add('fc-today-highlight');
                }
            },
            eventClassNames: function (info) {
                var type = info.event.extendedProps.type;
                var classes = ['fc-clinic-event'];
                if (type) classes.push('fc-type-' + type);
                return classes;
            }
        });

        calendar.render();

        // Expose calendar globally for debugging
        window.clinicCalendar = calendar;
    });
})();