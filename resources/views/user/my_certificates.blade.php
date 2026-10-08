{{--
    My Certificates - exact port of user/my_certificates.php (original
    lines 31-85): the printable own-certificate branch and the list table.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); $cert['x']/$c['x'] become ->x (stdClass rows, same values);
    get_setting() drops the unused $pdo handle; line 50 keeps its inline
    if/elseif/endif on one source line so the lead structure (16sp, and
    the 16+16 merge into "was seen" when neither number exists) renders
    exactly like the original; the print link prints only own rows because
    the controller's SQL carries `AND c.user_id = ?` like the original.

    Legacy quirk kept: `<?php if ($cert['findings']): ?>`-style guards stay
    single-line @if...@endif, so three skipped guards still contribute
    their 12sp leads before the signature row, exactly as PHP emits them.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

@if ($cert)
    <div class="mb-3 no-print">
        <a href="{{ route('user.my_certificates') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
    <div class="card">
        <div class="card-body p-5">
            <div class="text-center mb-4">
                <h4 class="text-primary mb-0">{{ get_setting('school_name', 'School') }}</h4>
                <div>{{ get_setting('clinic_name', 'School Health Clinic') }}</div>
                <div class="text-muted small">{{ get_setting('clinic_address', '') }} {{ get_setting('clinic_contact', '') }}</div>
                <hr>
                <h5 class="text-uppercase">{{ ucfirst($cert->cert_type) }} Certificate</h5>
            </div>
            <p>Date: <strong>{{ fmt_dt($cert->issued_at, 'F d, Y') }}</strong></p>
            <p class="mb-2">To Whom It May Concern:</p>
            <p>This is to certify that <strong>{{ $cert->patient_name }}</strong>
                @if ($cert->student_no)(Student No. {{ $cert->student_no }})@elseif ($cert->employee_no)(Employee No. {{ $cert->employee_no }})@endif
                was seen at the school clinic on <strong>{{ fmt_dt($cert->issued_at, 'F d, Y') }}</strong>.</p>
            <p><strong>Purpose:</strong> {{ $cert->purpose }}</p>
            @if ($cert->findings)<p><strong>Findings:</strong> {!! nl2br(e($cert->findings)) !!}</p>@endif
            @if ($cert->recommendation)<p><strong>Recommendation:</strong> {!! nl2br(e($cert->recommendation)) !!}</p>@endif
            @if ($cert->rest_days)<p><strong>Recommended rest:</strong> {{ (int) $cert->rest_days }} day(s)</p>@endif
            <div class="row mt-5">
                <div class="col-6"></div>
                <div class="col-6 text-center"><div class="border-top pt-1">Attending Clinic Personnel</div></div>
            </div>
        </div>
    </div>
@else
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>#</th><th>Type</th><th>Purpose</th><th>Issued</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @if (!$certs)
                    <tr><td colspan="5" class="text-center text-muted py-4">You have no certificates yet.</td></tr>
                @else
@foreach ($certs as $c)
                    <tr>
                        <td>{{ (int) $c->id }}</td>
                        <td><span class="chip">{!! ucfirst($c->cert_type) !!}</span></td>
                        <td>{{ mb_strimwidth((string) $c->purpose, 0, 40, '…') }}</td>
                        <td>{{ fmt_dt($c->issued_at, 'M d, Y') }}</td>
                        <td class="text-end">
                            <a href="{{ route('user.my_certificates') }}?print={{ $c->id }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> Print</a>
                        </td>
                    </tr>
                @endforeach
@endif
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
