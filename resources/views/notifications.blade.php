{{--
    Notification centre - exact port of notifications.php (original lines
    55-125): the All/Unread filter buttons (raw $filter string, so junk
    values leave both buttons in their outline state), the conditional Mark
    All Read form (only while unread > 0), the empty-state card and the
    notification cards (severity icon with the default-icon match fallback,
    unread border + Mark-as-read form, action_url View link).

    Emission notes:
    - The two reference csrf_token inputs become a @csrf tag at the input's
      original lead plus a compensating blank line (approved Phase 6
      strengthening; the full_diff harness strips token inputs from both
      sides, and the blank keeps the newline structure identical).
    - The fused else+foreach control line (orig L82) and the fused
      endforeach+endif line (orig L123) split 1 -> 2 (first tag at its
      original 4sp lead, second tag at 0sp); every split newline is eaten
      by a compiled tag, so both the empty and the populated case merge
      byte-identically with the reference (Phase 23/25 split convention).
    - The default-icon PHP island (match on severity, orig L88-95) becomes
      the directive block with the same indentation - it outputs nothing,
      exactly like the reference's raw PHP block.
    - $totalCount of the reference never reaches its view (dead query,
      Phase 22/27 precedent). The page never closes the shell: the layout
      emits the closers after this yield.
--}}
@extends('layouts.dashboard')

@section('title', $title)

@section('content')

<div class="row g-3">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div class="d-flex gap-2">
                <a href="{{ url('notifications') }}?filter=all" class="btn btn-sm {{ $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">All</a>
                <a href="{{ url('notifications') }}?filter=unread" class="btn btn-sm {{ $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary' }}">Unread ({{ $unreadCount }})</a>
            </div>
            @if ($unreadCount > 0)
                <form method="post" class="d-inline">
                    @csrf

                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-check-all me-1"></i> Mark All Read</button>
                </form>
            @endif
        </div>
    </div>

    @if (!$notifications)
        <div class="col-12">
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-bell-slash display-4 text-muted"></i>
                    <p class="text-muted mt-3">No notifications to display.</p>
                </div>
            </div>
        </div>
    @else
@foreach ($notifications as $n)
        <div class="col-12">
            <div class="card {{ $n['is_read'] ? '' : 'border-start border-4 border-primary' }} notification-card" data-id="{{ (int)$n['id'] }}">
                <div class="card-body py-3">
                    <div class="d-flex align-items-start gap-3">
                        <div class="notification-icon {{ severity_text_class($n['severity']) }} flex-shrink-0">
                            @php
                            $defaultIcon = match($n['severity']) {
                                'success' => 'bi-check-circle-fill',
                                'warning' => 'bi-exclamation-triangle-fill',
                                'danger'  => 'bi-x-octagon-fill',
                                default   => 'bi-bell-fill',
                            };
                            @endphp
                            <i class="bi {{ $n['icon'] ?? $defaultIcon }} fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="mb-1 {{ $n['is_read'] ? 'text-muted' : 'fw-bold' }}">{{ $n['title'] }}</h6>
                                <small class="text-muted flex-shrink-0 ms-2">{{ fmt_dt($n['created_at'], 'M d, Y g:i A') }}</small>
                            </div>
                            <p class="mb-1 {{ $n['is_read'] ? 'text-muted' : '' }}">{{ $n['message'] }}</p>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <span class="badge {{ severity_badge_class($n['severity']) }}">{{ ucfirst($n['severity']) }}</span>
                                @if ($n['action_url'])
                                    <a href="{{ url($n['action_url']) }}" class="btn btn-sm btn-outline-primary py-0 px-2">View</a>
                                @endif
                                @if (!$n['is_read'])
                                    <form method="post" class="d-inline">
                                        @csrf

                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="id" value="{{ (int)$n['id'] }}">
                                        <button type="submit" class="btn btn-sm btn-link p-0 text-muted">Mark as read</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
@endif
</div>

@endsection
