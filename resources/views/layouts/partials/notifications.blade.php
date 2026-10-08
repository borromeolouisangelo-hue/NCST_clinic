<div class="dropdown">
            <button class="btn dropdown-toggle" type="button" id="notifBellBtn" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell"></i>
                <?php /* Always rendered: main.js captures #notifBadge at load and
                         toggles d-none itself (updateNotifBadge), so the span must
                         exist even when the unread count is 0. */ ?>
                <span class="badge rounded-pill bg-danger{{ $shell->unreadNotifications > 0 ? '' : ' d-none' }}" id="notifBadge">{{ $shell->unreadNotifications > 99 ? '99+' : $shell->unreadNotifications }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notifBellBtn">
                <div class="notification-list" id="notifList">
                    @if ($shell->unreadNotifications === 0)
                        <div class="text-muted py-3 text-center">No notifications</div>
                    @else
                        <!-- Notifications loaded via SSE -->
                    @endif
                </div>
            </div>
        </div>

