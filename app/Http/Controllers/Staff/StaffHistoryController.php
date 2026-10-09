<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffHistoryController extends Controller
{
    /**
     * GET|POST /staff/history - staff/history.php ("History / logs").
     *
     * The full, unbounded seven-section history the staff dashboard previews
     * with LIMIT 10: ?tab= picks the section (unknown keys fall back to the
     * first - patients), all six filter keys are initialised before the view
     * reads them, and the active section's own field/options control decides
     * both the dropdown and the query value. A value submitted for another
     * section falls back to "all" for both so they can never disagree
     * (original lines 21-94). Rows and error come from the shared
     * StaffHistory builder the dashboard already uses, so columns, badges
     * and actions can never drift between preview and full history.
     * Read-only: the page never writes, and its form is a GET form.
     */
    public function index(Request $request): View
    {
        $tabs = StaffHistory::tabs();

        $activeTab = (string) ($request->query('tab') ?? '');
        if (! isset($tabs[$activeTab])) {
            $activeTab = (string) array_key_first($tabs);
        }
        $meta = $tabs[$activeTab];

        // Every key initialised before the HTML reads it. Each builder
        // validates its own values, so a value belonging to a different
        // section (both patients and certificates use "type"; both
        // sick-leaves and equipment-loans use "status") can never produce
        // invalid SQL.
        $filters = [
            'q' => trim((string) ($request->query('q') ?? '')),
            'type' => (string) ($request->query('type') ?? 'all'),
            'status' => (string) ($request->query('status') ?? 'all'),
            'filter' => (string) ($request->query('filter') ?? 'all'),
            'item_type' => (string) ($request->query('item_type') ?? 'all'),
            'history_status' => (string) ($request->query('history_status') ?? 'all'),
        ];

        $filterControls = [
            'patients' => [
                'field' => 'type',
                'label' => 'Filter',
                'placeholder' => 'Search name, username, student no. or employee no...',
                'options' => ['all' => 'All Types', 'student' => 'Student', 'employee' => 'Employee'],
            ],
            'consultations' => [
                'field' => 'filter',
                'label' => 'Date',
                'placeholder' => 'Search patient, complaint or diagnosis...',
                'options' => ['all' => 'All Dates', 'today' => 'Today', 'week' => 'This Week', 'month' => 'This Month'],
            ],
            'certificates' => [
                'field' => 'type',
                'label' => 'Type',
                'placeholder' => 'Search patient, certificate no. or purpose...',
                'options' => ['all' => 'All Types', 'medical' => 'Medical Certificate', 'excuse' => 'Excuse Slip', 'fit' => 'Fit to Work / Study'],
            ],
            'sick-leaves' => [
                'field' => 'status',
                'label' => 'Status',
                'placeholder' => 'Search employee name or employee no...',
                'options' => ['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined'],
            ],
            'dispense' => [
                'field' => 'history_status',
                'label' => 'Status',
                'placeholder' => 'Search patient, medicine or reason...',
                'options' => ['all' => 'All Status', 'dispensed' => 'Dispensed', 'rejected' => 'Declined'],
            ],
            'inventory' => [
                'field' => 'item_type',
                'label' => 'Item Type',
                'placeholder' => 'Search medicine, generic name or category...',
                'options' => ['all' => 'All Items', 'medicine' => 'Medicine', 'first_aid' => 'First Aid'],
            ],
            'equipment-loans' => [
                'field' => 'status',
                'label' => 'Loan Status',
                'placeholder' => 'Search borrower or equipment...',
                'options' => ['all' => 'All Loans', 'borrowed' => 'Borrowed', 'returned' => 'Returned', 'overdue' => 'Overdue'],
            ],
        ];

        $control = $filterControls[$activeTab];
        $field = $control['field'];
        $fieldVal = $filters[$field];
        if (! isset($control['options'][$fieldVal])) {
            // A value submitted for another section: fall back to "all" for
            // both the dropdown and the query so they can never disagree.
            $fieldVal = 'all';
            $filters[$field] = 'all';
        }

        // Full history for the active section (no LIMIT).
        $result = StaffHistory::rows($activeTab, $filters, null);
        $rows = $result['rows'];
        $queryError = $result['error'];

        return view('staff.history', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/history'),
            'title' => 'Transaction History',
            'tabs' => $tabs,
            'activeTab' => $activeTab,
            'meta' => $meta,
            'filters' => $filters,
            'control' => $control,
            'field' => $field,
            'fieldVal' => $fieldVal,
            'rows' => $rows,
            'queryError' => $queryError,
        ]);
    }
}
