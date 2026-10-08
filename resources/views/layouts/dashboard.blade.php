<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Feature 10: Dashboard Calendar - FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    @php($cssVersion = @filemtime(public_path('assets/css/style.css')) ?: time())
    <link href="{{ asset('assets/css/style.css') }}?v={{ $cssVersion }}" rel="stylesheet">
</head>
<body class="app-shell-body">
<div class="app-wrapper">
    @include('layouts.partials.sidebar')
    <div class="main-wrapper">
        @include('layouts.partials.topbar')
        <main class="main-content">
            <div class="content-area">
                <div class="container-fluid">@include('layouts.partials.flash')@yield('content')
        </div>
        <!-- /.container-fluid -->
        </div>
        <!-- /.content-area -->
    </main>
    <!-- /.main-content -->
</div>
<!-- /.main-wrapper -->
</div>
<!-- /.app-wrapper -->

@include('layouts.partials.footer')

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Feature 10: Dashboard Calendar - FullCalendar JS -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script src="{{ asset('assets/js/calendar.js') }}"></script>
<script src="{{ asset('assets/js/main.js') }}"></script>
</body>
</html>