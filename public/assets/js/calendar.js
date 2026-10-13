

(function () {
    'use strict';

    /** Local yyyy-mm-dd (the old UTC-based version highlighted the wrong
        day for viewers west of UTC after ~22:00 local time). */
    function localDateString(date) {
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + m + '-' + d;
    }

    function parseEvents(calendarEl) {
        try {
            return JSON.parse(calendarEl.dataset.events || '[]');
        } catch (e) {
            console.warn('Calendar: failed to parse events data', e);
            return [];
        }
    }

    /** Shared options for every calendar instance. */
    function baseOptions(calendarEl) {
        return {
            initialView: 'dayGridMonth',
            height: 'auto',
            events: parseEvents(calendarEl),
            eventTimeFormat: {
                hour: '2-digit',
                minute: '2-digit',
                meridiem: 'short'
            },
            eventDisplay: 'block',
            eventDidMount: function (info) {
                // Tooltip with details
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
                // Highlight today (local calendar day)
                if (info.dateStr === localDateString(new Date())) {
                    info.el.classList.add('fc-today-highlight');
                }
            },
            eventClassNames: function (info) {
                var type = info.event.extendedProps.type;
                var classes = ['fc-clinic-event'];
                if (type) classes.push('fc-type-' + type);
                return classes;
            }
        };
    }

    /** Merge the per-mode layout into the shared options. */
    function buildOptions(calendarEl) {
        var options = baseOptions(calendarEl);

        if (calendarEl.dataset.mode === 'full') {
            options.height = 'auto';

            options.headerToolbar = {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek,listWeek'
            };
            options.buttonText = {
                today: 'Today',
                month: 'Month',
                week: 'Week',
                list: 'List'
            };
        } else {
            // Compact fixed height; style.css mirrors 290px on the chart
            // card body so the dashboard pair matches exactly.
            options.height = 220;
            options.expandRows = true;
            options.headerToolbar = {
                left: 'prev today',
                center: 'title',
                right: 'next'
            };
            options.displayEventTime = false;
            options.dayMaxEvents = true;
        }

        return options;
    }

    function initCalendar(calendarEl) {
        // Init-once guard: safe under script re-evaluation and multiple inclusions.
        if (calendarEl.dataset.fcInitialized === '1') return;

        // The CDN bundle failed to load - warn instead of throwing.
        if (typeof FullCalendar === 'undefined') {
            console.warn('Calendar: FullCalendar library is not available (CDN blocked or offline?).');
            return;
        }

        calendarEl.dataset.fcInitialized = '1';

        var calendar = new FullCalendar.Calendar(calendarEl, buildOptions(calendarEl));
        calendar.render();

        // First instance keeps the global debugging handle (back-compat).
        window.clinicCalendar = window.clinicCalendar || calendar;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var calendarEls = document.querySelectorAll('.js-clinic-calendar[data-clinic-calendar]');
        calendarEls.forEach(initCalendar);
    });
})();
