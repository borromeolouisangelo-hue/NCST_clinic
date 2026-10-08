{{--
    Notification preferences - exact port of notifications_preferences.php
    (original lines 54-91): the intro card, the POST form with
    data-swal-save, the three display groups (Medical Services / Sick Leave /
    System (Staff)) rendered from the raw type keys, the eleven
    form-switch checkboxes and the Save / Back buttons.

    Emission notes:
    - The reference csrf_token input (original line 63) becomes a @csrf tag
      at the input's original 20sp lead plus a compensating blank line
      (approved Phase 6 strengthening; the full_diff harness strips token
      inputs from both sides, and the blank keeps the newline structure
      identical).
    - Every control tag in the reference sits on its own line (two outer /
      one inner foreach), so the mapping is pure 1:1 - no fusion splits on
      this page.
    - The checked echo stays a Blade echo (original line 75 is a RAW echo
      with no e()): escaping 'checked' / '' is a no-op, so the bytes match.
    - The switch shows the raw type key (original line 70 renders $type,
      the machine name - the pretty labels of the POST block are values-dead
      in the reference and stay unrendered here).
    - $prefs[$type] ?? true default: an absent row reads as enabled.
    - The page never closes the shell: the layout emits the closers after
      this yield (the reference ends at its own </div> block, then requires
      layout_dashboard_end.php).
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-bell me-1"></i> Notification Preferences</div>
            <div class="card-body">
                <p class="text-muted">Choose which notifications you want to receive. Disabled notifications will not appear in your notification center.</p>

                <form method="post" data-swal-save="notification preferences">
                    @csrf


                    @foreach ($groups as $groupLabel => $types)
                        <h6 class="mt-4 mb-3 fw-bold text-primary">{{ $groupLabel }}</h6>
                        <div class="list-group mb-3">
                            @foreach ($types as $type)
                                <label class="list-group-item d-flex align-items-center justify-content-between">
                                    <span>{{ $type }}</span>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               name="pref_{{ $type }}" value="1"
                                               id="pref_{{ $type }}"
                                               {{ ($prefs[$type] ?? true) ? 'checked' : '' }}>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endforeach

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Save Preferences</button>
                        <a href="{{ url('notifications') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to Notifications</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
