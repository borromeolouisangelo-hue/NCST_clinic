@extends('layouts.dashboard')

@section('title', 'Calendar')

@section('content')
    {{-- Full-page calendar: the SAME partial and the SAME calendar.js
         instance the three dashboards use (one #dashboardCalendar element
         per page), rendered in full mode so the card always shows and the
         grid gets room to breathe. Events arrive already role-scoped from
         DashboardQueries::calendarEvents() via CalendarController. --}}
    <div class="row g-3">
        <div class="col-12">
            @include('layouts.partials.calendar', [
                'calendarEvents' => $calendarEvents ?? [],
                'calendarFull' => true,
            ])
        </div>
    </div>
@endsection
