{{--
    User consultation history - exact port of user/my_consultations.php
    (original lines 21-48): the My Activity info alert, the empty state and
    the per-consultation cards with their conditional diagnosis, treatment
    and vital-sign chips.

    Approved differences: the My Activity link drops .php (routing
    convention since Phase 4). Display semantics match the original's
    conditions exactly - diagnosis/treatment/BP/pulse/weight/height are
    truthiness checks (0 hides), while temperature is a null check (0 shows).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')
<div class="alert alert-info">Consultation records are shown here for quick review. For full request and equipment activity, use <a href="{{ url('user/my_requests') }}" class="alert-link">My Activity</a>.</div>
@if (! $list)
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-clipboard2-pulse fs-1 d-block mb-2"></i>
        You have no consultation records yet.
    </div></div>
@else
    @foreach ($list as $c)
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between">
                <span><i class="bi bi-calendar3 me-1"></i> {{ fmt_dt($c->consultation_date, 'M d, Y g:i A') }}</span>
                <span class="text-muted small">Attending: {{ $c->staff_name ?: '—' }}</span>
            </div>
            <div class="card-body">
                <p class="mb-2"><strong>Complaint:</strong> {{ $c->complaint }}</p>
                @if ($c->diagnosis)<p class="mb-2"><strong>Diagnosis:</strong> {{ $c->diagnosis }}
                    @if ($c->diagnosis_image)<a href="{{ route('consultations.attachment', ['id' => $c->id, 'type' => 'diagnosis']) }}" target="_blank" rel="noopener" class="ms-1"><i class="bi bi-image-alt me-1"></i>View image</a>@endif
                </p>@endif
                @if ($c->treatment)<p class="mb-2"><strong>Treatment:</strong> {{ $c->treatment }}
                    @if ($c->treatment_image)<a href="{{ route('consultations.attachment', ['id' => $c->id, 'type' => 'treatment']) }}" target="_blank" rel="noopener" class="ms-1"><i class="bi bi-image me-1"></i>View image</a>@endif
                </p>@endif
                <div class="row g-2 small text-muted mt-2">
                    @if ($c->blood_pressure)<div class="col-auto">BP: {{ $c->blood_pressure }}</div>@endif
                    @if ($c->temperature !== null)<div class="col-auto">Temp: {{ $c->temperature }}°C</div>@endif
                    @if ($c->pulse)<div class="col-auto">Pulse: {{ $c->pulse }} bpm</div>@endif
                    @if ($c->weight_kg)<div class="col-auto">Weight: {{ $c->weight_kg }} kg</div>@endif
                    @if ($c->height_cm)<div class="col-auto">Height: {{ $c->height_cm }} cm</div>@endif
                </div>
            </div>
        </div>
    @endforeach
@endif
@endsection
