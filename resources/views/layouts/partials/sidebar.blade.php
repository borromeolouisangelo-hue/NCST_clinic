@php $role = auth()->user()->role ?? ''; $isEmployee = (auth()->user()->account_type ?? '') === 'employee'; @endphp
<aside class="sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="brand">
        <img src="{{ asset('assets/images/ncst.png') }}" alt="NCST logo" class="brand-image">
        <span class="brand-text">{{ config('app.name') }}</span>
        <!-- The ONE collapse control for every role. It lives at the right of
             the brand row so it stays reachable when the rail is collapsed;
             below 992px it is lifted over the topbar while the drawer is shut
             (see style.css) so it also opens/closes the off-canvas drawer. -->
        <button class="sidebar-brand-control" id="sidebarBrandControl" type="button"
                aria-label="Collapse navigation" aria-expanded="true" title="Collapse navigation"
                aria-controls="appSidebar">
            <i class="bi bi-chevron-double-right bc-icon-expand"></i><i class="bi bi-chevron-double-left bc-icon-collapse"></i>
        </button>
    </div>

    <nav class="sidebar-nav flex-grow-1 overflow-auto">
        @if ($role === 'admin')
            <!-- Admin Navigation -->
            <div class="nav-section">Dashboard</div>
            <a href="{{ url('admin/dashboard') }}" class="nav-link {{ request()->is('admin/dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <div class="nav-section">Patients</div>
            <a href="{{ url('admin/users') }}" class="nav-link {{ request()->is('admin/users') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Patients
            </a>


            <div class="nav-section">Inventory</div>
            {{-- Staff own every inventory record: they stock the existing
                 items from the staff Inventory page and submit a REQUEST for
                 anything new (medicine / first aid item, equipment, category,
                 supplier). This queue is where those requests are approved or
                 declined - the retired Medicines / Categories / Suppliers /
                 Equipment Catalog admin pages no longer have routes. --}}
            <a href="{{ url('admin/inventory_requests') }}" class="nav-link {{ request()->is('admin/inventory_requests') ? 'active' : '' }}">
                <i class="bi bi-inbox"></i> Inventory Requests
            </a>

            <div class="nav-section">Records</div>
            
            <a href="{{ url('admin/audit_logs') }}" class="nav-link {{ request()->is('admin/audit_logs') ? 'active' : '' }}">
                <i class="bi bi-journal-text"></i> Audit Logs
            </a>
            <a href="{{ url('admin/backup') }}" class="nav-link {{ request()->is('admin/backup') ? 'active' : '' }}">
                <i class="bi bi-download"></i> Backup

            <a href="{{ url('admin/reports') }}" class="nav-link {{ request()->is('admin/reports') ? 'active' : '' }}">
                <i class="bi bi-graph-up"></i> Reports
            </a>
            </a>
            

            <div class="nav-section">Calendar</div>
            <a href="{{ url('admin/calendar') }}" class="nav-link {{ request()->is('admin/calendar') ? 'active' : '' }}">
                <i class="bi bi-calendar"></i> Calendar
            </a>

            <div class="nav-section">Notifications</div>
            <a href="{{ url('notifications') }}" class="nav-link {{ request()->is('notifications') ? 'active' : '' }}">
                <i class="bi bi-bell"></i> Notifications
            </a>

            <div class="nav-section">Settings</div>
            <a href="{{ url('user/profile') }}" class="nav-link {{ request()->is('user/profile') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account Settings
            </a>
            <a href="{{ url('admin/settings') }}" class="nav-link {{ request()->is('admin/settings') ? 'active' : '' }}">
                <i class="bi bi-sliders"></i> System Settings
            </a>

        @elseif ($role === 'staff')
            <!-- Staff Navigation -->
            <div class="nav-section">Dashboard</div>
            <a href="{{ url('staff/dashboard') }}" class="nav-link {{ request()->is('staff/dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <div class="nav-section">Patients</div>
            <a href="{{ url('staff/patients') }}" class="nav-link {{ request()->is('staff/patients') ? 'active' : '' }}">
                <i class="bi bi-people"></i> Patients
            </a>

            <div class="nav-section">Clinical Operations</div>
            <a href="{{ url('staff/consultations') }}" class="nav-link {{ request()->is('staff/consultations') ? 'active' : '' }}">
                <i class="bi bi-clipboard2-pulse"></i> Medical Consultations
            
            <a href="{{ url('staff/dispense') }}" class="nav-link {{ request()->is('staff/dispense') ? 'active' : '' }}">
                <i class="bi bi-capsule"></i> Dispense
            </a>


            <a href="{{ url('staff/equipment_loans') }}" class="nav-link {{ request()->is('staff/equipment_loans') ? 'active' : '' }}">
                <i class="bi bi-tools"></i> Equipment Loans
            </a>

            <a href="{{ url('staff/certificates') }}" class="nav-link {{ request()->is('staff/certificates') ? 'active' : '' }}">
                <i class="bi bi-file-medical"></i> Medical Certificate
            </a>    
            </a>

            <a href="{{ url('staff/sick_leaves') }}" class="nav-link {{ request()->is('staff/sick_leaves') ? 'active' : '' }}">
                <i class="bi bi-file-medical"></i> Sick Leaves
            </a>

            <div class="nav-section">Inventory</div>
            <a href="{{ url('staff/inventory') }}" class="nav-link {{ request()->is('staff/inventory') ? 'active' : '' }}">
                <i class="bi bi-box-seam"></i> Inventory
            </a>



            <div class="nav-section">Records</div>
            <a href="{{ url('staff/history') }}" class="nav-link {{ request()->is('staff/history') ? 'active' : '' }}">
                <i class="bi bi-card-list"></i> Transaction History
            </a>
            
            <a href="{{ url('staff/reports') }}" class="nav-link {{ request()->is('staff/reports') ? 'active' : '' }}">
                <i class="bi bi-graph-up"></i> Reports
            </a>

            <div class="nav-section">Calendar</div>
            <a href="{{ url('staff/calendar') }}" class="nav-link {{ request()->is('staff/calendar') ? 'active' : '' }}">
                <i class="bi bi-calendar"></i> Calendar
            </a>

            <div class="nav-section">Notifications</div>
            <a href="{{ url('notifications') }}" class="nav-link {{ request()->is('notifications') ? 'active' : '' }}">
                <i class="bi bi-bell"></i> Notifications
            </a>

            <div class="nav-section">Settings</div>
            <a href="{{ url('user/profile') }}" class="nav-link {{ request()->is('user/profile') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account Settings
            </a>

        @else
            <!-- User/Student/Employee Navigation -->
            <div class="nav-section">Dashboard</div>
            <a href="{{ url('user/dashboard') }}" class="nav-link {{ request()->is('user/dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>

            <div class="nav-section">Health</div>
            <a href="{{ url('user/medical_form') }}" class="nav-link {{ request()->is('user/medical_form') ? 'active' : '' }}">
                <i class="bi bi-file-medical"></i> Medical Form
            </a>
            @if ($isEmployee)
            <a href="{{ url('user/sick_leave') }}" class="nav-link {{ request()->is('user/sick_leave') ? 'active' : '' }}">
                <i class="bi bi-thermometer"></i> Sick Leave
            </a>
            @endif

            <div class="nav-section">My Activity</div>
            <a href="{{ url('user/my_requests') }}" class="nav-link {{ request()->is('user/my_requests') ? 'active' : '' }}">
                <i class="bi bi-activity"></i> My Activity
            </a>
            @if ($isEmployee)
            <a href="{{ url('user/my_requests?tab=sick-leaves') }}" class="nav-link {{ request()->is('user/my_requests*') && request()->query('tab') === 'sick-leaves' ? 'active' : '' }}">
                <i class="bi bi-thermometer"></i> Sick Leave
            </a>
            @endif
            <a href="{{ url('user/my_certificates') }}" class="nav-link {{ request()->is('user/my_certificates') ? 'active' : '' }}">
                <i class="bi bi-file-medical"></i> Certificates
            </a>

            <div class="nav-section">Calendar</div>
            <a href="{{ url('user/calendar') }}" class="nav-link {{ request()->is('user/calendar') ? 'active' : '' }}">
                <i class="bi bi-calendar"></i> Calendar
            </a>


            <div class="nav-section">Settings</div>
            <a href="{{ url('user/profile') }}" class="nav-link {{ request()->is('user/profile') ? 'active' : '' }}">
                <i class="bi bi-gear"></i> Account Settings
            </a>
            <a href="{{ url('notifications') }}" class="nav-link {{ request()->is('notifications') ? 'active' : '' }}">
                <i class="bi bi-bell"></i> Notifications
            </a>
        @endif
    </nav>

    <div class="sidebar-footer">
        <small>&copy; {{ date('Y') }} {{ config('app.name') }}</small>
    </div>
</aside>

<!-- Sidebar backdrop for mobile -->
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>