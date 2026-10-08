{{--
    Admin - Database backup - exact port of admin/backup.php (original lines
    81-150): the Create Backup card (error loop, inline success alert, backup
    directory note, all-checked whitelist checkbox form) and the Recent
    Backups card (raw PHP glob/rsort block, empty state vs 20-entry filename
    list), plus the page-specific shell closers.

    Emission notes:
    - $errors of the reference is $backupErrors here (Laravel always
      injects its own $errors bag into views).
    - The form carries @csrf + a compensating blank line: the reference runs
      no CSRF check and prints no token field (approved Phase 6
      strengthening; the full_diff harness strips token inputs from both
      sides, and the blank line keeps the newline structure identical).
    - The glob block (original lines 121-127) stays verbatim raw PHP - Blade
      passes it through unchanged, and it is not control-flow material.
    - The raw checkbox echoes for $t become {{ $t }}: table names are
      [a-z_] only, so e() and htmlspecialchars produce identical bytes.
    - The closers stay inside the view before endsection: the reference page
      closes the shell itself before requiring layout_dashboard_end.php.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-cloud-arrow-down me-1"></i> Create Backup</div>
                        <div class="card-body">
                            @foreach ($backupErrors as $err)
                                <div class="alert alert-danger py-2">{{ $err }}</div>
                            @endforeach
                            @if ($success)
                                <div class="alert alert-success py-2">Backup created successfully.</div>
                            @endif
                            <p class="text-muted small mb-3">
                                Backups are written to <code>{{ $backupDir }}</code>, outside the web root.
                                Only whitelisted tables are exported.
                            </p>
                            <form method="post">
                                @csrf

                                <div class="mb-3">
                                    <label class="form-label">Select Tables</label>
                                    <div class="row g-2">
                                        @foreach ($allowedTables as $t)
                                            <div class="col-6 col-md-4">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tables[]" value="{{ $t }}" id="tbl_{{ $t }}" checked>
                                                    <label class="form-check-label" for="tbl_{{ $t }}">{{ $t }}</label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-download me-1"></i> Generate Backup</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-history me-1"></i> Recent Backups</div>
                        <div class="card-body">
                            <?php
                            $files = [];
                            if (is_dir($backupDir)) {
                                $files = glob($backupDir . '/*.sql') ?: [];
                                rsort($files);
                            }
                            ?>
                            @if (empty($files))
                                <div class="text-muted small">No backups yet.</div>
                            @else
                                <ul class="list-group list-group-flush">
                                    @foreach (array_slice($files, 0, 20) as $f)
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span class="text-truncate" style="max-width: 70%">{{ basename($f) }}</span>
                                            <span class="text-muted small">{{ number_format(filesize($f)) }} bytes</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.app-wrapper -->

@endsection
