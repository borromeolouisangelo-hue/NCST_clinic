<?php
/**
 * Flash slot - intentionally renders NOTHING.
 *
 * The original app calls set_flash('success'|'info'|..., ...) before every
 * redirect but has NO renderer anywhere: get_flash() is never called and no
 * view reads $_SESSION['flash']. Success feedback after a redirect is
 * therefore invisible in the reference (a write-only legacy slot, verified
 * empirically: logout -> login page shows no alert, login -> dashboard shows
 * no 'Welcome back' banner, and grep finds no reader in the whole original).
 *
 * The port keeps every ->with('success', ...) call (the faithful set_flash
 * equivalent, still asserted by the tests via assertSessionHas) but this
 * partial - included at the top of the shared layout - emits no markup, so
 * first-visit pages match the original byte-for-byte. Legacy behaviour kept
 * on purpose (migration-only rule: no UI the original does not have).
 */
?>
@foreach (['success' => 'alert-success', 'info' => 'alert-info', 'warning' => 'alert-warning', 'danger' => 'alert-danger', 'error' => 'alert-danger'] as $key => $class)
@if (session()->has($key))
@endif
@endforeach
