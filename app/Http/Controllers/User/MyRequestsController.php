<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Support\ShellContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MyRequestsController extends Controller
{
    /**
     * GET|POST /user/my_requests - user/my_requests.php ("My Activity").
     *
     * Four read-only history queries behind require_login(['user']): medicine
     * requests JOIN medicines (optional dispensed/rejected status whitelist -
     * anything else adds no WHERE clause at all), equipment loans ordered by
     * COALESCE(return_date, borrow_date) DESC, consultations with the staff
     * name via a profiles LEFT JOIN, and the latest 50 sick leaves. The
     * reference page never writes, so neither does this endpoint.
     *
     * Feature 8: My Activity now uses top-level tabs (Medicine/Equipment/
     * Consultations/Sick Leave) per the Staff History pattern, with the
     * medicine status pills inside the Medicine tab. ?tab= is the single
     * source of truth, falling back to 'medicine' for unknown values.
     */
    public function index(Request $request): View
    {
        $uid = (int) $request->user()->id;
        $isEmployee = (string) $request->user()->account_type === 'employee';

        // Top-level tab (?tab=medicine|equipment|consultations|sick-leaves).
        $activeTab = $request->query('tab') ?? 'medicine';
        $validTabs = ['medicine', 'equipment', 'consultations', 'sick-leaves'];
        if (! in_array($activeTab, $validTabs, true)) {
            $activeTab = 'medicine';
        }

        // Medicine status filter (only applies when medicine tab is active).
        $statusFilter = $request->query('status') ?? '';

        // --- Medicine requests (always fetched for badge count, filtered when tab active) ---
        $sql = 'SELECT r.*, m.name AS medicine_name, m.generic_name
                FROM medicine_requests r JOIN medicines m ON m.id = r.medicine_id
                WHERE r.user_id = ?';
        $params = [$uid];
        if (in_array($statusFilter, ['dispensed', 'rejected'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $statusFilter;
        }
        $sql .= ' ORDER BY r.requested_at DESC';
        $requests = DB::select($sql, $params);

        $badge = ['dispensed' => 'bg-success', 'rejected' => 'bg-danger'];

        // --- Equipment loans ---
        $loans = DB::select(
            "SELECT l.*, e.name AS equipment_name,
                    CASE WHEN l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)
                         THEN 1 ELSE 0 END AS is_overdue
             FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id
             WHERE l.user_id = ? ORDER BY COALESCE(l.return_date, l.borrow_date) DESC",
            [$uid],
        );
        $loanBadge = ['borrowed' => 'bg-orange', 'returned' => 'bg-success'];

        // --- Consultations ---
        $consultations = DB::select(
            "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS staff_name
             FROM consultations c LEFT JOIN profiles p ON p.user_id = c.staff_id
             WHERE c.patient_id = ? ORDER BY c.consultation_date DESC",
            [$uid],
        );

        // --- Sick leaves ---
        $sickLeaves = DB::select(
            'SELECT * FROM sick_leaves WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
            [$uid],
        );

        return view('user.my_requests', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/my_requests'),
            'title' => 'My Activity',
            'activeTab' => $activeTab,
            'statusFilter' => $statusFilter,
            'requests' => $requests,
            'badge' => $badge,
            'loans' => $loans,
            'loanBadge' => $loanBadge,
            'consultations' => $consultations,
            'sickLeaves' => $sickLeaves,
            'isEmployee' => $isEmployee,
        ]);
    }
}
