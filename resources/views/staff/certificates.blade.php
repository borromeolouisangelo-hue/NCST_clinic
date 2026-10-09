{{--
    Staff Medical Certificates - exact port of staff/certificates.php
    (original lines 86-297): the printable certificate branch, the issue
    modal with its two-rule validation, and the list table with the dead
    ?type= filter kept verbatim.

    Approved differences: action URLs drop .php (routing convention since
    Phase 4); the form carries @csrf instead of csrf_token (Phase 6) - the
    blank line after @csrf compensates PHP eating one newline after the
    compiled closing tag, so the buttons line keeps the original's exact
    leading whitespace; $cert['x']/$c['x'] become ->x (stdClass rows, same
    values); get_setting() drops the unused $pdo handle; the line-109 PHP
    statement stays a single-line @php/@endphp so its lead (8sp) plus the
    eaten newline still merge into the section line exactly like the
    original <?php $patientAge = ...; ?>; line 129's end-of-line echo is
    merged with line 130 on one source line (Blade would emit one newline
    too many) carrying line 130's 20sp lead between the echo and @if.

    Legacy quirks kept: the Filter <select> submits ?type= that the SQL
    never reads; generate_cert_no() numbers excuse slips and fit notes as
    MED; ?action=delete is a plain GET with no ownership check; "Issued
    Today" runs on the PHP app clock (Europe/Berlin, php.ini parity).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

<div class="registry-shell">
@if ($cert)
    <div class="mb-3 no-print">
        <a href="{{ route('staff.certificates') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
    <main class="certificate-page" id="certificateArea">
        <header class="certificate-header">
            <div class="ncst-logo-wrap" aria-label="NCST logo">
                <img src="{{ url('assets/images/ncst.png') }}" alt="NCST Logo" class="ncst-logo-image" onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=\'logo-placeholder\'>NCST</div>';" />
            </div>
            <div class="header-text">
                <div class="certificate-school-name">{{ get_setting('certificate_school_name', 'NATIONAL COLLEGE OF SCIENCE & TECHNOLOGY') }}</div>
                <div class="certificate-school-address">{{ get_setting('certificate_school_address', 'Amafel Building, Aguinaldo Highway, Dasmariñas, Cavite 4114') }}</div>
                <div class="certificate-office">HEALTH SERVICES OFFICE</div>
            </div>
            <div aria-hidden="true"></div>
        </header>
        <hr class="header-rule">
        <h1 class="document-title">MEDICAL CERTIFICATE</h1>

        @php $patientAge = age_from_birthdate($cert->birthdate ?? null); @endphp
        <section class="certificate-form">
            <p class="certificate-introduction">
                This is to certify that <span class="inline-field patient-name">{{ $cert->patient_name }}</span>
            </p>
            <p class="certificate-introduction">
                <span class="inline-field age">{!! $patientAge !== null ? (int) $patientAge : '' !!}</span> y/o, ( <span class="inline-field sex">{{ $cert->gender ? strtoupper($cert->gender) : '' }}</span> ) consulted on <span class="inline-field date">{{ fmt_dt($cert->issued_at, 'F d, Y') }}</span>
            </p>
            <p class="certificate-introduction certificate-introduction-last">
                Because of <span class="inline-field reason">{{ $cert->purpose }}</span>
            </p>

            <section class="section">
                <h2 class="section-heading">DIAGNOSIS:</h2>
                <div class="editable-box diagnosis-box">{!! $cert->findings ? nl2br(e($cert->findings)) : '' !!}</div>
            </section>

            <section class="section">
                <h2 class="section-heading">REMARKS/ RECOMMENDATION:</h2>
                <div class="editable-box remarks-box">
                    {!! nl2br(e($cert->recommendation ?: 'Essentially, normal PE findings during the time of consultation. No comorbidities found.')) !!}                    @if ($cert->rest_days)<div>Recommended rest: {{ (int) $cert->rest_days }} day(s).</div>@endif
                </div>
            </section>

            <footer class="certificate-footer">
                <div class="issuance-text">
                    This certification is issued upon the request of Mr./ Ms <span class="inline-field requestor-name">{{ $cert->patient_name }}</span>
                    <div class="purpose-note">Medical purpose only.</div>
                </div>

                <div class="signature-block">
                    <div class="signature-space"></div>
                    <p class="physician-name">{{ get_setting('certificate_physician_name', 'GERALINE S. GABUTERO, M.D.') }}</p>
                    <p class="physician-title">School Physician</p>
                </div>
            </footer>
        </section>
    </main>
    <script>
    window.addEventListener('load', function () {
        window.print();
    });
    </script>

@else

    <div class="registry-stats row g-3 mb-4">
        <div class="col-md-4">
            <div class="metric-card">
                <div class="metric-icon icon-primary"><i class="bi bi-file-earmark-medical"></i></div>
                <div class="metric-value">{{ count($certs) }}</div>
                <div class="metric-label">Total Certificates</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="metric-card">
                <div class="metric-icon icon-success"><i class="bi bi-calendar-check"></i></div>
                <div class="metric-value">{{ count(array_filter($certs, fn ($c) => ! empty($c->issued_at) && date('Y-m-d', strtotime($c->issued_at)) === date('Y-m-d'))) }}</div>
                <div class="metric-label">Issued Today</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="metric-card metric-card-compact">
                <div class="metric-icon icon-warning"><i class="bi bi-person-badge"></i></div>
                <div class="metric-value metric-value--text">Dr. Gabutero</div>
                <div class="metric-label">Physician on Duty</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12">
            <div class="modal fade" id="certificateModal" tabindex="-1" aria-labelledby="certificateModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-md"><div class="modal-content">
            <div class="card">
                <div class="card-header" id="certificateModalLabel">Issue Certificate</div>
                <div class="card-body">
                    @foreach ($errors as $err)
                        <div class="alert alert-danger py-2">{{ $err }}</div>
                    @endforeach
                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label">Patient *</label>
                            <select name="patient_id" class="form-select" required>
                                <option value="">-- select --</option>
                                @foreach ($patients as $pt)
                                    <option value="{{ (int) $pt->id }}">{{ $pt->full_name ?: ('User #' . $pt->id) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="cert_type" class="form-select">
                                <option value="medical">Medical Certificate</option>
                                <option value="excuse">Excuse Slip</option>
                                <option value="fit">Fit to Work / Study</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Purpose *</label>
                            <input type="text" name="purpose" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Findings</label>
                            <textarea name="findings" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Recommendation</label>
                            <textarea name="recommendation" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rest Days</label>
                            <input type="number" name="rest_days" class="form-control" min="0">
                        </div>
                        @csrf

                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" data-swal-add="certificate"><i class="bi bi-file-earmark-plus me-1"></i> Issue Certificate</button>
                        
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    </form>
                </div>
            </div>
                </div></div>
            </div>
        </div>

        <div class="col-12">
            <div class="table-panel card mb-3">
                <div class="card-body">
                    <div class="row g-2 align-items-end audit-toolbar mb-2">
                        <div class="col">
                            <label class="form-label">Search</label>
                            <input id="certificatesSearch" type="search" class="form-control form-control-sm" data-live-filter="#certificatesTable tbody tr" data-live-filter-count="certificatesResultCount" placeholder="Search patient name..." aria-label="Search certificates">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Filter</label>
                            <form method="get" class="d-inline">
                                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="all" {{ $typeRaw === 'all' ? 'selected' : '' }}>All Types</option>
                                    <option value="medical" {{ $typeRaw === 'medical' ? 'selected' : '' }}>Medical Certificate</option>
                                    <option value="excuse" {{ $typeRaw === 'excuse' ? 'selected' : '' }}>Excuse Slip</option>
                                    <option value="fit" {{ $typeRaw === 'fit' ? 'selected' : '' }}>Fit to Work / Study</option>
                                </select>
                            </form>
                        </div>
                        <div class="col d-flex flex-wrap gap-2 justify-content-start">
                            
                            <button type="button" class="btn  btn-primary text-nowrap" data-search-trigger="#certificatesSearch"><i class="bi bi-search me-1"></i> Search</button>
                            
                            <button type="button" class="btn  btn-accent text-nowrap" data-bs-toggle="modal" data-bs-target="#certificateModal"><i class="bi bi-file-earmark-plus me-1"></i> Issue</button>
                        </div>
                    </div>
                 </div>
                </div>
                <div class="table-responsive">
                    <table id="certificatesTable" class="table table-hover mb-0 align-middle">
                        <thead><tr><th>PATIENT NAME</th><th>SEX / AGE</th><th>CONSULT DATE</th><th>DIAGNOSIS</th><th>REQUESTOR</th><th>ACTIONS</th></tr></thead>
                        <tbody>
                        @if (!$certs)
                            <tr><td colspan="6" class="empty-state-cell"><div class="empty-state"><div class="empty-state-icon"><i class="bi bi-file-earmark-text"></i></div><div>No medical certificates created yet.</div></div></td></tr>
                        @else
@foreach ($certs as $c)
                            <tr>
                                <td>{{ $c->patient_name ?: '—' }}</td>
                                <td>{{ $c->gender ? ucfirst($c->gender) : '—' }} / {!! $c->birthdate ? age_from_birthdate($c->birthdate) : '—' !!}</td>
                                <td>{{ fmt_dt($c->issued_at, 'M d, Y') }}</td>
                                <td>{{ mb_strimwidth((string) $c->purpose, 0, 30, '…') }}</td>
                                <td>{{ $c->patient_name ?: '—' }}</td>
                                <td class="text-end actions-cell">
                                    <a href="{{ route('staff.certificates') }}?print={{ $c->id }}" class="btn btn-sm btn-outline-primary" title="Print certificate"><i class="bi bi-printer"></i></a>
                                    <a href="{{ route('staff.certificates') }}?action=delete&id={{ $c->id }}" class="btn btn-sm btn-outline-danger" title="Delete certificate" data-confirm="Delete this certificate?" data-swal-delete="certificate"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        @endforeach
@endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @if ($errors)<script>document.addEventListener('DOMContentLoaded', function () { var modal = document.getElementById('certificateModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>@endif
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var certificateModal = document.getElementById('certificateModal');
        if (!certificateModal) return;
        certificateModal.addEventListener('hidden.bs.modal', function () {
            var form = certificateModal.querySelector('form');
            if (form) form.reset();
        });
    });
    </script>
@endif

@endsection
