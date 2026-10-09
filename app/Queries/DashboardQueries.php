<?php

namespace App\Queries;

use App\Services\ClinicTrend;
use App\Services\StaffHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard read queries and calendar event assembly - extracted verbatim
 * from App\Http\Controllers\DashboardController (Phase 3, Step 3).
 *
 * Every statement here is a byte-identical move of the controller's
 * original data-gathering code, kept in the original execution order so the
 * query sequence per request is unchanged:
 *
 *  - adminData(): the four stat counters and the three data cards (Low
 *    Stock, Expiring Within 90 Days, Recent Activity) of the admin
 *    dashboard.
 *
 *  - staffData(): the clinic dashboard's KPI stats, trend badges,
 *    frequently-requested/borrowed lists, 7/14/30-day trend ranges, period
 *    totals, low-stock alerts and recent-history sections. The values the
 *    page does not render ($overdue, $overdueTrend, $yesterdayRequests,
 *    $yesterdayCons, $yesterdayLoans) are still computed here because the
 *    original computes them - their queries keep running in the same
 *    positions. trendInfo() moved along as their private helper.
 *
 *  - userData(): the user dashboard's counters and Feature 3 statistics,
 *    including the try/catch $safeCount shims for tables that may be
 *    missing on the isolated test connection.
 *
 *  - calendarEvents(): buildCalendarEvents() verbatim (Feature 10) - the
 *    role-scoped FullCalendar event assembly with its safeQuery/safeCount
 *    closures, sort and 20-event slice.
 *
 * The controller keeps request parameters, ShellContext, Notify/redirect
 * composition and view rendering; only $request->user()->id was passed in
 * as the already-resolved $uid for userData().
 */
class DashboardQueries
{
    /**
     * Admin dashboard data (DashboardController::admin, original lines 15-54).
     *
     * SQL parity: every predicate is the original one, spelled with plain
     * function calls so the identical statement also runs on the isolated
     * SQLite test connection (curdate()/adddate() are registered there the
     * same way PatientTest registers concat()). ADDDATE(CURDATE(), 90) is
     * the same MySQL day arithmetic as DATE_ADD(CURDATE(), INTERVAL 90
     * DAY), same DB clock.
     *
     * @return array{totalUsers: int, totalStaff: int, totalMeds: int, todayRequests: int, lowStock: array<int, array>, expiring: array<int, array>, recent: array<int, array>}
     */
    public function adminData(): array
    {
        // Stats (original lines 15-18).
        $totalUsers = (int) DB::table('users')->where('role', 'user')->count();
        $totalStaff = (int) DB::table('users')->where('role', 'staff')->count();
        $totalMeds = (int) DB::table('medicines')->where('is_active', 1)->count();
        $todayRequests = (int) DB::table('medicine_requests')
            ->whereRaw('DATE(requested_at) = CURDATE()')
            ->count();

        // Low stock medicines - non-expired stock only (original lines 22-33).
        // GROUP BY additionally lists m.name/m.low_stock_threshold: they are
        // functionally dependent on the PK m.id, so the rows are identical,
        // but listing them keeps the statement valid under the ONLY_FULL_GROUP_BY
        // session mode Laravel opens MySQL with (the original PDO connection
        // runs under the server default, which MariaDB does not relax for
        // functional dependencies).
        $lowStock = DB::table('medicines as m')
            ->selectRaw('m.id, m.name, m.low_stock_threshold, COALESCE(SUM(b.quantity),0) AS stock')
            ->leftJoin('medicine_batches as b', function ($join) {
                $join->on('b.medicine_id', '=', 'm.id')
                    ->where('b.quantity', '>', 0)
                    ->whereRaw('(b.expiry_date IS NULL OR b.expiry_date >= CURDATE())');
            })
            ->where('m.is_active', 1)
            ->groupBy('m.id', 'm.name', 'm.low_stock_threshold')
            ->havingRaw('stock <= m.low_stock_threshold')
            ->orderByRaw('stock ASC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        // Expiring within 90 days, non-expired batches only (lines 36-45).
        $expiring = DB::table('medicine_batches as b')
            ->selectRaw('b.*, m.name AS medicine_name')
            ->join('medicines as m', 'm.id', '=', 'b.medicine_id')
            ->whereRaw(
                'b.quantity > 0 AND b.expiry_date IS NOT NULL'
                .' AND b.expiry_date >= CURDATE()'
                .' AND b.expiry_date BETWEEN CURDATE() AND ADDDATE(CURDATE(), 90)'
            )
            ->orderByRaw('b.expiry_date ASC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        // Recent activity - last 8 audit rows with their username (lines 48-54).
        $recent = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.*', 'u.username')
            ->orderByRaw('a.created_at DESC')
            ->limit(8)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'totalUsers' => $totalUsers,
            'totalStaff' => $totalStaff,
            'totalMeds' => $totalMeds,
            'todayRequests' => $todayRequests,
            'lowStock' => $lowStock,
            'expiring' => $expiring,
            'recent' => $recent,
        ];
    }

    /**
     * Clinic (staff) dashboard data (DashboardController::staff, original
     * lines 11-113 of the port).
     *
     * @return array{todayRequests: int, todayCons: int, activeLoans: int, pendingSickLeaves: int, loanTrend: array{pct: float|null, dir: string}, requestedItems: array<int, array>, borrowedItems: array<int, array>, trendRanges: array<string, array>, days: int, periodTotal: int, periodChange: float|int, lowStock: array<int, array>, historySections: array, recent: array}
     */
    public function staffData(): array
    {
        // --- Today's stats (original lines 11-14). SUBDATE(d, 1) is the
        // plain-function spelling of DATE_SUB(d, INTERVAL 1 DAY / 24 HOUR). ---
        $todayRequests = (int) DB::table('medicine_requests')
            ->whereRaw('DATE(requested_at) = CURDATE()')->count();
        $todayCons = (int) DB::table('consultations')
            ->whereRaw('DATE(consultation_date) = CURDATE()')->count();
        $activeLoans = (int) DB::table('equipment_loans')->where('status', 'borrowed')->count();
        $overdue = (int) DB::table('equipment_loans')
            ->whereRaw("status = 'borrowed' AND borrowed_at <= SUBDATE(NOW(), 1)")->count();

        // --- Feature 6: Pending sick leave requests (replaces Overdue Loans
        // in the KPI row - overdue loans are still tracked for the equipment
        // module but don't need a dedicated stat card here). ---
        $pendingSickLeaves = (int) DB::table('sick_leaves')
            ->where('status', 'pending')->count();

        // --- Yesterday's stats for trend comparison (lines 17-19). ---
        $yesterdayRequests = (int) DB::table('medicine_requests')
            ->whereRaw('DATE(requested_at) = SUBDATE(CURDATE(), 1)')->count();
        $yesterdayCons = (int) DB::table('consultations')
            ->whereRaw('DATE(consultation_date) = SUBDATE(CURDATE(), 1)')->count();
        $yesterdayLoans = (int) DB::table('equipment_loans')
            ->whereRaw("status = 'borrowed' AND DATE(borrow_date) = SUBDATE(CURDATE(), 1)")->count();

        // --- Previous period loans (2 days ago) for trend (line 22). ---
        $prevActiveLoans = (int) DB::table('equipment_loans')
            ->whereRaw("status = 'borrowed' AND DATE(borrow_date) <= SUBDATE(CURDATE(), 2)")->count();

        // Trend badges: only the two KPI cards that render them (lines 35
        // and 152-155; $reqTrend/$consTrend are computed but never shown in
        // the original, so they are not ported). $yesterdayLoans feeds no
        // rendered badge either - kept here because the original computes it.
        $loanTrend = $this->trendInfo($activeLoans, $prevActiveLoans);
        $yesterdayOverdue = (int) DB::table('equipment_loans')->whereRaw(
            "status = 'borrowed' AND borrowed_at <= SUBDATE(NOW(), 1) AND borrowed_at > SUBDATE(NOW(), 2)"
        )->count();
        $overdueTrend = $this->trendInfo($overdue, $yesterdayOverdue);

        // --- Frequently requested / borrowed (lines 38-54, verbatim SQL). ---
        $requestedItems = array_map(
            fn ($row) => (array) $row,
            DB::select(
                'SELECT m.name, SUM(mr.quantity) AS total_quantity
                 FROM medicine_requests mr
                 JOIN medicines m ON m.id = mr.medicine_id
                 GROUP BY mr.medicine_id, m.name
                 ORDER BY total_quantity DESC, m.name ASC
                 LIMIT 6'
            ),
        );
        $borrowedItems = array_map(
            fn ($row) => (array) $row,
            DB::select(
                'SELECT e.name, SUM(el.quantity) AS total_quantity
                 FROM equipment_loans el
                 JOIN equipment e ON e.id = el.equipment_id
                 GROUP BY el.equipment_id, e.name
                 ORDER BY total_quantity DESC, e.name ASC
                 LIMIT 6'
            ),
        );

        // --- 7/14/30-day trend ranges (lines 86-90) for the chart script. ---
        $trendRanges = [
            '7' => ClinicTrend::range(7),
            '14' => ClinicTrend::range(14),
            '30' => ClinicTrend::range(30),
        ];

        // --- Period totals (original lines 74-82): per-day COUNT queries on
        // the literal dates seed the sidebar before the chart script runs.
        // (The trendLabels/consultationValues loops of lines 58-71 are dead
        // code - nothing on the page reads them - and are not ported. Note
        // the sidebar values here are NOT ranges['7']: the range query only
        // fetches the current window, so its change field is always 0, while
        // these per-day queries see the previous period - exactly as the
        // original computes them.) ---
        $days = 7;
        $trendValues = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $trendValues[] = (int) DB::table('medicine_requests')
                ->whereRaw('DATE(requested_at) = ?', [date('Y-m-d', strtotime("-$i days"))])
                ->count();
        }
        $periodTotal = array_sum($trendValues);
        $prevPeriodTotal = 0;
        for ($i = $days * 2 - 1; $i >= $days; $i--) {
            $prevPeriodTotal += (int) DB::table('medicine_requests')
                ->whereRaw('DATE(requested_at) = ?', [date('Y-m-d', strtotime("-$i days"))])
                ->count();
        }
        $periodChange = $prevPeriodTotal > 0 ? round((($periodTotal - $prevPeriodTotal) / $prevPeriodTotal) * 100, 1) : 0;

        // --- Low stock alerts (lines 92-97): NO expiry filter here (unlike
        // the admin card), LIMIT 6. GROUP BY extended with the selected
        // functionally-dependent columns for ONLY_FULL_GROUP_BY - same note
        // as DashboardController::admin(). ---
        $lowStock = array_map(
            fn ($row) => (array) $row,
            DB::select(
                'SELECT m.name, m.low_stock_threshold, COALESCE(SUM(b.quantity),0) AS stock
                 FROM medicines m LEFT JOIN medicine_batches b ON b.medicine_id = m.id
                    AND b.quantity > 0
                 WHERE m.is_active = 1 GROUP BY m.id, m.name, m.low_stock_threshold
                 HAVING stock <= m.low_stock_threshold ORDER BY stock ASC LIMIT 6'
            ),
        );

        // --- Recent activity: 10 newest records per history section
        // (lines 104-113) through the shared builder. ---
        $historySections = StaffHistory::tabs();
        $recent = [];
        foreach (array_keys($historySections) as $sectionKey) {
            $recent[$sectionKey] = StaffHistory::rows($sectionKey, [], 10);
        }

        return [
            'todayRequests' => $todayRequests,
            'todayCons' => $todayCons,
            'activeLoans' => $activeLoans,
            'pendingSickLeaves' => $pendingSickLeaves,
            'loanTrend' => $loanTrend,
            'requestedItems' => $requestedItems,
            'borrowedItems' => $borrowedItems,
            'trendRanges' => $trendRanges,
            'days' => $days,
            'periodTotal' => $periodTotal,
            'periodChange' => $periodChange,
            'lowStock' => $lowStock,
            'historySections' => $historySections,
            'recent' => $recent,
        ];
    }

    /**
     * trend_info() from staff/dashboard.php:24-31 - percentage direction for
     * a today-vs-yesterday KPI comparison. No badge when yesterday was 0.
     */
    private function trendInfo(int $today, int $yesterday): array
    {
        if ($yesterday === 0) {
            return ['pct' => null, 'dir' => 'up'];
        }

        $pct = round((($today - $yesterday) / $yesterday) * 100, 1);

        return ['pct' => abs($pct), 'dir' => $pct >= 0 ? 'up' : 'down'];
    }

    /**
     * User dashboard data (DashboardController::renderUser).
     *
     * @param  int  $uid  dashboard user id ($request->user()->id, already
     *                    resolved by the controller)
     * @return array{unreadNotifCount: int, recentSickLeaves: mixed, reqCount: int, loanCount: int, consultationCount: int, activeLoanCount: int, hasMedRec: bool, allergyCount: int, dispensedCount: int, overdueLoanCount: int, pendingSickLeaves: int, approvedSickLeaves: int, rejectedSickLeaves: int, activityCount: int, certificateCount: int}
     */
    public function userData(int $uid): array
    {
        // Raw, uncapped unread count for the page-level alert - the shell's
        // own count is clamped to 999 for the topbar badge only.
        $unreadNotifCount = (int) DB::table('notifications')
            ->where('user_id', $uid)
            ->where('is_read', 0)
            ->count();

        $recentSickLeaves = DB::table('sick_leaves')
            ->where('user_id', $uid)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $reqCount = (int) DB::table('medicine_requests')->where('user_id', $uid)->count();
        $loanCount = (int) DB::table('equipment_loans')->where('user_id', $uid)->count();
        $consultationCount = (int) DB::table('consultations')->where('patient_id', $uid)->count();
        $activeLoanCount = (int) DB::table('equipment_loans')->where('user_id', $uid)->where('status', 'borrowed')->count();

        // Feature 3: Additional personal statistics (with safe queries for tests)
        $safeCount = function (callable $query) {
            try {
                return (int) $query();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        $dispensedCount = $safeCount(function () use ($uid) {
            return DB::table('medicine_requests')
                ->where('user_id', $uid)
                ->where('status', 'dispensed')
                ->count();
        });

        $overdueLoanCount = $safeCount(function () use ($uid) {
            return DB::table('equipment_loans')
                ->where('user_id', $uid)
                ->where('status', 'borrowed')
                ->whereRaw('borrowed_at <= SUBDATE(NOW(), 1)')
                ->count();
        });

        $pendingSickLeaves = $safeCount(function () use ($uid) {
            return DB::table('sick_leaves')
                ->where('user_id', $uid)
                ->where('status', 'pending')
                ->count();
        });

        $approvedSickLeaves = $safeCount(function () use ($uid) {
            return DB::table('sick_leaves')
                ->where('user_id', $uid)
                ->where('status', 'approved')
                ->count();
        });

        $rejectedSickLeaves = $safeCount(function () use ($uid) {
            return DB::table('sick_leaves')
                ->where('user_id', $uid)
                ->where('status', 'rejected')
                ->count();
        });

        // Recent activity count (total across medicine requests, equipment loans, consultations, sick leaves)
        $activityCount = (int) DB::table('medicine_requests')->where('user_id', $uid)->count()
            + (int) DB::table('equipment_loans')->where('user_id', $uid)->count()
            + (int) DB::table('consultations')->where('patient_id', $uid)->count()
            + $safeCount(function () use ($uid) {
                return DB::table('sick_leaves')->where('user_id', $uid)->count();
            });

        $certificateCount = $safeCount(function () use ($uid) {
            return DB::table('medical_certificates')->where('patient_id', $uid)->count();
        });

        $hasMedRec = DB::table('medical_records')->where('user_id', $uid)->exists();
        $allergyCount = (int) DB::table('user_allergies')->where('user_id', $uid)->count();

        return [
            'unreadNotifCount' => $unreadNotifCount,
            'recentSickLeaves' => $recentSickLeaves,
            'reqCount' => $reqCount,
            'loanCount' => $loanCount,
            'consultationCount' => $consultationCount,
            'activeLoanCount' => $activeLoanCount,
            'hasMedRec' => $hasMedRec,
            'allergyCount' => $allergyCount,
            'dispensedCount' => $dispensedCount,
            'overdueLoanCount' => $overdueLoanCount,
            'pendingSickLeaves' => $pendingSickLeaves,
            'approvedSickLeaves' => $approvedSickLeaves,
            'rejectedSickLeaves' => $rejectedSickLeaves,
            'activityCount' => $activityCount,
            'certificateCount' => $certificateCount,
        ];
    }

    /**
     * Build calendar events array for the dashboard calendar (Feature 10).
     *
     * Returns an array of FullCalendar-compatible event objects tailored
     * to the user's role and identity.
     *
     * @return array<int, array>
     */
    public function calendarEvents(Request $request): array
    {
        $user = $request->user();
        $role = $user->role;
        $uid = (int) $user->id;
        $accountType = (string) $user->account_type;
        $events = [];

        // Helper to safely execute a query and return empty array on failure
        $safeQuery = function (callable $query) {
            try {
                return $query();
            } catch (\Throwable $e) {
                // Table doesn't exist in test database - return empty collection
                return collect();
            }
        };

        // Helper to safely execute a count query and return 0 on failure
        $safeCount = function (callable $query) {
            try {
                return (int) $query();
            } catch (\Throwable $e) {
                return 0;
            }
        };

        // Admin sees everything: pending sick leaves, low stock, expiring meds, overdue equipment
        if ($role === 'admin') {
            // Pending sick leave requests
            $pendingLeaves = $safeQuery(function () {
                return DB::table('sick_leaves as sl')
                    ->join('users as u', 'u.id', '=', 'sl.user_id')
                    ->join('profiles as p', 'p.user_id', '=', 'u.id')
                    ->where('sl.status', 'pending')
                    ->select('sl.id', 'sl.reason', 'sl.start_date', 'sl.end_date', 'sl.created_at', 'u.username', 'p.first_name', 'p.last_name')
                    ->orderBy('sl.created_at')
                    ->limit(10)
                    ->get();
            });

            foreach ($pendingLeaves as $l) {
                $events[] = [
                    'title' => 'Sick Leave: '.trim(($l->first_name ?? '').' '.($l->last_name ?? '')).' ('.$l->username.')',
                    'start' => $l->start_date.' 00:00',
                    'end' => $l->end_date ? $l->end_date.' 23:59' : null,
                    'type' => 'sick_leave',
                    'color' => '#fd7e14', // orange
                    'url' => url('staff/sick_leaves'),
                    'description' => "Sick leave request: {$l->reason}",
                ];
            }

            // Low stock medicines
            $lowStock = $safeQuery(function () {
                return DB::table('medicines as m')
                    ->selectRaw('m.id, m.name, m.low_stock_threshold, COALESCE(SUM(b.quantity),0) AS stock')
                    ->leftJoin('medicine_batches as b', function ($join) {
                        $join->on('b.medicine_id', '=', 'm.id')
                            ->where('b.quantity', '>', 0)
                            ->whereRaw('(b.expiry_date IS NULL OR b.expiry_date >= CURDATE())');
                    })
                    ->where('m.is_active', 1)
                    ->groupBy('m.id', 'm.name', 'm.low_stock_threshold')
                    ->havingRaw('stock <= m.low_stock_threshold')
                    ->orderByRaw('stock ASC')
                    ->limit(5)
                    ->get();
            });

            foreach ($lowStock as $m) {
                $events[] = [
                    'title' => 'Low Stock: '.$m->name,
                    'start' => date('Y-m-d').' 00:00',
                    'type' => 'low_stock',
                    'color' => '#dc3545', // danger red
                    'url' => url('admin/medicines'),
                    'description' => "Stock: {$m->stock} (threshold: {$m->low_stock_threshold})",
                ];
            }
        }

        // Staff sees: today's consultations, pending sick leaves, equipment due/overdue, low stock
        elseif ($role === 'staff') {
            // Today's consultations
            $todayConsultations = $safeQuery(function () {
                return DB::table('consultations as c')
                    ->join('users as u', 'u.id', '=', 'c.patient_id')
                    ->join('profiles as p', 'p.user_id', '=', 'u.id')
                    ->whereRaw('DATE(c.consultation_date) = CURDATE()')
                    ->select('c.id', 'c.complaint', 'c.consultation_date', 'u.username', 'p.first_name', 'p.last_name')
                    ->orderBy('c.consultation_date')
                    ->limit(8)
                    ->get();
            });

            foreach ($todayConsultations as $c) {
                $events[] = [
                    'title' => 'Consultation: '.trim(($c->first_name ?? '').' '.($c->last_name ?? '')).' ('.$c->username.')',
                    'start' => $c->consultation_date,
                    'type' => 'consultation',
                    'color' => '#2f7bbd', // blue
                    'url' => url('staff/consultations?edit='.$c->id),
                    'description' => "Chief complaint: {$c->complaint}",
                ];
            }

            // Pending sick leaves
            $pendingLeaves = $safeQuery(function () {
                return DB::table('sick_leaves as sl')
                    ->join('users as u', 'u.id', '=', 'sl.user_id')
                    ->join('profiles as p', 'p.user_id', '=', 'u.id')
                    ->where('sl.status', 'pending')
                    ->select('sl.id', 'sl.reason', 'sl.start_date', 'sl.end_date', 'sl.created_at', 'u.username', 'p.first_name', 'p.last_name')
                    ->orderBy('sl.created_at')
                    ->limit(5)
                    ->get();
            });

            foreach ($pendingLeaves as $l) {
                $events[] = [
                    'title' => 'Sick Leave: '.trim(($l->first_name ?? '').' '.($l->last_name ?? '')).' ('.$l->username.')',
                    'start' => $l->start_date.' 00:00',
                    'end' => $l->end_date ? $l->end_date.' 23:59' : null,
                    'type' => 'sick_leave',
                    'color' => '#fd7e14',
                    'url' => url('staff/sick_leaves'),
                    'description' => "Sick leave request: {$l->reason}",
                ];
            }

            // Equipment due today or overdue
            $dueEquipment = $safeQuery(function () {
                return DB::table('equipment_loans as l')
                    ->join('equipment as e', 'e.id', '=', 'l.equipment_id')
                    ->join('users as u', 'u.id', '=', 'l.user_id')
                    ->join('profiles as p', 'p.user_id', '=', 'u.id')
                    ->where('l.status', 'borrowed')
                    ->whereRaw('l.borrowed_at <= SUBDATE(NOW(), 1)')
                    ->select('l.id', 'e.name as equipment_name', 'l.borrowed_at', 'u.username', 'p.first_name', 'p.last_name')
                    ->orderBy('l.borrowed_at')
                    ->limit(5)
                    ->get();
            });

            foreach ($dueEquipment as $e) {
                $events[] = [
                    'title' => 'Overdue: '.$e->equipment_name.' ('.trim(($e->first_name ?? '').' '.($e->last_name ?? '')).')',
                    'start' => date('Y-m-d', strtotime($e->borrowed_at)).' 00:00',
                    'type' => 'equipment_overdue',
                    'color' => '#dc3545',
                    'url' => url('staff/equipment_loans'),
                    'description' => 'Borrowed on '.date('M d, Y', strtotime($e->borrowed_at)),
                ];
            }

            // Low stock
            $lowStock = $safeQuery(function () {
                return DB::table('medicines as m')
                    ->selectRaw('m.id, m.name, m.low_stock_threshold, COALESCE(SUM(b.quantity),0) AS stock')
                    ->leftJoin('medicine_batches as b', function ($join) {
                        $join->on('b.medicine_id', '=', 'm.id')
                            ->where('b.quantity', '>', 0)
                            ->whereRaw('(b.expiry_date IS NULL OR b.expiry_date >= CURDATE())');
                    })
                    ->where('m.is_active', 1)
                    ->groupBy('m.id', 'm.name', 'm.low_stock_threshold')
                    ->havingRaw('stock <= m.low_stock_threshold')
                    ->orderByRaw('stock ASC')
                    ->limit(5)
                    ->get();
            });

            foreach ($lowStock as $m) {
                $events[] = [
                    'title' => 'Low Stock: '.$m->name,
                    'start' => date('Y-m-d').' 00:00',
                    'type' => 'low_stock',
                    'color' => '#dc3545',
                    'url' => url('staff/inventory'),
                    'description' => "Stock: {$m->stock} (threshold: {$m->low_stock_threshold})",
                ];
            }
        }

        // User (student/employee) sees: own consultations, sick leaves, equipment due, dispensing
        else {
            $uid = $request->user()->id;

            // Own consultations (next 30 days)
            $consultations = $safeQuery(function () use ($uid) {
                return DB::table('consultations')
                    ->where('patient_id', $uid)
                    ->whereRaw('DATE(consultation_date) >= CURDATE()')
                    ->whereRaw('DATE(consultation_date) <= ADDDATE(CURDATE(), 30)')
                    ->select('id', 'complaint', 'consultation_date', 'diagnosis')
                    ->orderBy('consultation_date')
                    ->limit(5)
                    ->get();
            });

            foreach ($consultations as $c) {
                $events[] = [
                    'title' => 'Consultation: '.$c->complaint,
                    'start' => $c->consultation_date,
                    'type' => 'consultation',
                    'color' => '#2f7bbd',
                    'url' => url('user/my_consultations'),
                    'description' => $c->diagnosis ? 'Diagnosis: '.$c->diagnosis : 'Scheduled consultation',
                ];
            }

            // Own sick leaves
            $sickLeaves = $safeQuery(function () use ($uid) {
                return DB::table('sick_leaves')
                    ->where('user_id', $uid)
                    ->whereRaw('(DATE(start_date) >= CURDATE() OR DATE(end_date) >= CURDATE())')
                    ->select('id', 'reason', 'start_date', 'end_date', 'status')
                    ->orderBy('start_date')
                    ->limit(5)
                    ->get();
            });

            foreach ($sickLeaves as $l) {
                $statusColor = $l->status === 'pending' ? '#fd7e14' : ($l->status === 'approved' ? '#198754' : '#dc3545');
                $events[] = [
                    'title' => 'Sick Leave: '.ucfirst($l->status),
                    'start' => $l->start_date.' 00:00',
                    'end' => $l->end_date ? $l->end_date.' 23:59' : null,
                    'type' => 'sick_leave',
                    'color' => $statusColor,
                    'url' => url('user/my_requests?tab=sick-leaves'),
                    'description' => "Reason: {$l->reason}",
                ];
            }

            // Equipment loans due/overdue
            $loans = $safeQuery(function () use ($uid) {
                return DB::table('equipment_loans as l')
                    ->join('equipment as e', 'e.id', '=', 'l.equipment_id')
                    ->where('l.user_id', $uid)
                    ->where('l.status', 'borrowed')
                    ->select('l.id', 'e.name as equipment_name', 'l.borrowed_at')
                    ->orderBy('l.borrowed_at')
                    ->limit(5)
                    ->get();
            });

            foreach ($loans as $l) {
                $overdue = strtotime($l->borrowed_at) <= strtotime('-1 day');
                $events[] = [
                    'title' => ($overdue ? 'Overdue: ' : 'Due: ').$l->equipment_name,
                    'start' => date('Y-m-d', strtotime($l->borrowed_at.' +1 day')).' 00:00',
                    'type' => $overdue ? 'equipment_overdue' : 'equipment_due',
                    'color' => $overdue ? '#dc3545' : '#ffc107',
                    'url' => url('user/my_requests?tab=equipment'),
                    'description' => 'Borrowed on '.date('M d, Y', strtotime($l->borrowed_at)),
                ];
            }

            // Upcoming dispensing / medicine requests (dispensed)
            $dispensed = $safeQuery(function () use ($uid) {
                return DB::table('medicine_requests as r')
                    ->join('medicines as m', 'm.id', '=', 'r.medicine_id')
                    ->where('r.user_id', $uid)
                    ->where('r.status', 'dispensed')
                    ->whereRaw('DATE(r.requested_at) >= SUBDATE(CURDATE(), 7)')
                    ->select('r.id', 'm.name as medicine_name', 'r.requested_at', 'r.quantity', 'r.reason')
                    ->orderByDesc('r.requested_at')
                    ->limit(3)
                    ->get();
            });

            foreach ($dispensed as $d) {
                $events[] = [
                    'title' => 'Dispensed: '.$d->medicine_name,
                    'start' => $d->requested_at,
                    'type' => 'dispensed',
                    'color' => '#198754',
                    'url' => url('user/my_requests?tab=medicine'),
                    'description' => "Qty: {$d->quantity} - {$d->reason}",
                ];
            }
        }

        // Sort events by start date
        usort($events, function ($a, $b) {
            return strtotime($a['start']) - strtotime($b['start']);
        });

        // Limit to reasonable number
        return array_slice($events, 0, 20);
    }
}
