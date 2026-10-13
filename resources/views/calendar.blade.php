@extends('layouts.dashboard')

@section('title', 'Calendar')

@section('content')
    
    <div class="row g-3">
        <div class="col-12">
            @include('layouts.partials.calendar', [
                'calendarEvents' => $calendarEvents ?? [],
                'calendarFull' => true,
            ])
        </div>
    </div>
@endsection
