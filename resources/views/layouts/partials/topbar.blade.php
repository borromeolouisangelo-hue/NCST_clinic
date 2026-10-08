<header class="topbar">
    <div class="d-flex align-items-center">
        <!-- The sidebar collapse control lives in the sidebar header, so the
             topbar deliberately has no hamburger of its own. -->
        <h1 class="page-title mb-0">@yield('title', 'Dashboard')</h1>
    </div>

    <div class="topbar-actions" style="display: flex; align-items: center;">
        @include('layouts.partials.notifications')
        @include('layouts.partials.account-dropdown')
    </div>
</header>