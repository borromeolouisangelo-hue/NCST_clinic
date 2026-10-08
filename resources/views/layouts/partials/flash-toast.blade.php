{{--
    Server-flash -> SweetAlert toast carrier (Features 4 and 5).

    partials/flash.blade.php deliberately renders nothing, so a redirect
    whose flash is the ONLY feedback (sick-leave Accept/Decline, Account
    Settings saves) used to succeed silently. This hands the message the
    SERVER flashed to main.js, which turns it into a toast.

    Because the carrier only exists on the request that followed a completed
    POST, success can never be announced before it actually happened, and
    never twice - no client-side guesswork, no premature confirmation.

    Included by any surface whose only success signal is a redirect flash.
--}}
@php
    $flashToast = session('success')
        ? ['icon' => 'success', 'text' => (string) session('success')]
        : (session('danger') ? ['icon' => 'error', 'text' => (string) session('danger')] : null);
@endphp
@if ($flashToast)
    <div id="flashNotice" class="d-none" data-flash-icon="{{ $flashToast['icon'] }}" data-flash-text="{{ $flashToast['text'] }}"></div>
@endif
