<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Queries\StockLogQueries;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffHistoryController extends Controller
{
    public function __construct(protected StockLogQueries $stockLogQueries) {}

    /**
     * GET|POST /staff/history - staff/history.php ("History / logs").
     *
     * The single destination for every module's complete history: each
     * module page's View All action links here with ?tab= naming its
     * section, so the page is reached both directly and from a module and
     * behaves identically either way. Unknown ?tab= keys fall back to the
     * first section (patients), and the seven pills switch sections with a
     * full navigation so ?tab= stays the single source of truth.
     *
     * All six filter keys are initialised before the view reads them, and
     * the active section's own field/options control decides both the
     * dropdown and the query value. A value submitted for another section
     * falls back to "all" for both so they can never disagree (original
     * lines 21-94). Rows and error come from the shared StaffHistory
     * builder the dashboard already uses, so columns, badges and actions
     * can never drift between preview and full history.
     *
     * Every section is paginated server-side (StaffHistory::perPage - the
     * sizes the five module View All screens used before they were folded
     * in), with ?page= read here and LIMIT/OFFSET applied in SQL. The
     * pagination links carry only the ACTIVE section's own values, so a
     * foreign ?type= can never leak into another section's URLs.
     *
     * The Inventory section is the deduction log rather than the stock
     * snapshot the dashboard previews: the Inventory screen's View
     * Deduction Logs action lands here, and StockLogQueries serves its
     * rows through the same pagination contract. Read-only throughout -
     * the page never writes, and its form is a GET form.
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

        $filterControls = StaffHistory::filterControls();

        $control = $filterControls[$activeTab];
        $field = $control['field'];
        $fieldVal = $this->whitelisted($control, $filters[$field]);
        $filters[$field] = $fieldVal;

        // The inventory section is the deduction log, so it is the one
        // section with a SECOND dropdown (the date window) beside the Type
        // one. Same whitelist collapse, for the same reason.
        $second = $control['second'] ?? null;
        $secondField = $second['field'] ?? null;
        $secondVal = null;
        if ($second !== null) {
            $secondVal = $this->whitelisted($second, $filters[$secondField]);
            $filters[$secondField] = $secondVal;
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = StaffHistory::perPage($activeTab);

        if ($activeTab === 'inventory') {
            // The Inventory section is the deduction log: every real stock
            // deduction from both systems (medicine OUT movements plus the
            // equipment borrow / completed-return availability events),
            // newest first, with the before/after stock figures rebuilt by
            // StockLogQueries. Loans stay temporary; a rejected or
            // uncompleted request writes no movement and so never appears.
            $log = $this->stockLogQueries->events($filters['q'], $filters['type'], $filters['filter'], $page);
            $rows = $log['events'];
            $totalItems = $log['total'];
            $queryError = null;
        } else {
            $result = StaffHistory::rows($activeTab, $filters, $perPage, ($page - 1) * $perPage);
            $rows = $result['rows'];
            $totalItems = $result['total'];
            $queryError = $result['error'];
        }

        // Only the active section's own values ride along in the page links,
        // and only while they differ from their defaults, so the URLs stay
        // short and a foreign ?type= can never leak into another section's.
        $paginationQuery = ['tab' => $activeTab];
        if ($filters['q'] !== '') {
            $paginationQuery['q'] = $filters['q'];
        }
        if ($field !== 'q' && $fieldVal !== 'all') {
            $paginationQuery[$field] = $fieldVal;
        }
        if ($secondField !== null && $secondField !== $field && $secondField !== 'q' && $secondVal !== 'all') {
            $paginationQuery[$secondField] = $secondVal;
        }

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
            'second' => $second,
            'secondField' => $secondField,
            'secondVal' => $secondVal,
            'rows' => $rows,
            'queryError' => $queryError,
            'pagination' => [
                'current' => $page,
                'total' => max(1, (int) ceil($totalItems / $perPage)),
                'perPage' => $perPage,
                'totalItems' => $totalItems,
                'baseUrl' => route('staff.history'),
                'query' => $paginationQuery,
            ],
        ]);
    }

    /**
     * Collapse a submitted value that is not one of a control's own options
     * (a value belonging to another section, or junk) to "all", so the
     * dropdown and the query can never disagree.
     */
    private function whitelisted(array $control, string $value): string
    {
        return isset($control['options'][$value]) ? $value : 'all';
    }
}
