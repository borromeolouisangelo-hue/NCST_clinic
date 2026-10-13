<div class="dropdown ms-2">
            <button class="btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                @if ($shell->photoUrl !== '')
                    <img src="{{ $shell->photoUrl }}" alt="{{ $shell->fullName }}" class="account-avatar" onerror="this.outerHTML='<i class=\'bi bi-person-circle\'></i>'">
                @else
                    <i class="bi bi-person-circle"></i>
                @endif
                <span class="btn-label">{{ $shell->fullName }}</span><span class="role-badge text-capitalize fw-bolder">{{ $shell->roleBadge }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><h6 class="dropdown-header">{{ $shell->fullName }}</h6></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ $shell->settingsUrl }}"><i class="bi bi-gear me-2"></i> Account Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="{{ $shell->logoutUrl }}"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>
