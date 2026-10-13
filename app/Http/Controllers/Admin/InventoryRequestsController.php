<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InventoryRequestService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin Inventory Requests - the approval queue for everything staff are no
 * longer allowed to create for themselves.
 *
 * The staff Inventory page creates nothing directly any more: a new medicine,
 * first aid product, equipment item, category or supplier is submitted as a
 * pending `inventory_requests` row, and THIS page is where an admin turns it
 * into a real record (or declines it with a reason). Restocking an existing
 * item never comes here - it stays a direct Stock In on the staff page.
 *
 * One self-posting route, exactly like the Sick Leaves review queue:
 * GET renders the list (status / type filters + search + pagination) and,
 * with ?view=request&id=, the details modal; POST with do=review runs the
 * approve / reject decision and redirects back (PRG). Approve carries
 * formnovalidate so it may submit with an empty note; Decline keeps the
 * textarea's required check, so only a decline is blocked - the same two
 * submit-button shape leave-details-modal.blade.php uses.
 *
 * Authorisation is route middleware (['auth', 'role:admin']) plus, inside
 * InventoryRequestService::decide(), a `WHERE status = 'pending'`
 * compare-and-set inside one DB transaction - so a second approval of the
 * same request creates nothing and the initial stock is written exactly once.
 */
class InventoryRequestsController extends Controller
{
    /** Rows per page of the request queue. */
    public const PER_PAGE = 25;

    public function __construct(protected InventoryRequestService $requests) {}

    public function index(Request $request): View|RedirectResponse
    {
        $adminId = (int) $request->user()->id;
        $errors = [];

        if ($request->isMethod('post') && $request->post('do') === 'review') {
            $id = (int) $request->post('request_id', 0);
            $status = (string) $request->post('status', '');
            $note = trim((string) ($request->post('review_note') ?? ''));

            $row = $id > 0 ? DB::table('inventory_requests')->where('id', $id)->first() : null;

            if (! $row) {
                $errors[] = 'Request could not be found.';
            } else {
                $result = $this->requests->decide($row, $status, $note, $adminId);

                if ($result['ok']) {
                    $message = $status === 'approved'
                        ? 'Request approved. The record was created.'
                        : 'Request declined. The requester has been notified.';

                    return redirect()
                        ->route('admin.inventory_requests')
                        ->with('success', $message);
                }

                $errors = $result['errors'];
            }
        }

        $status = (string) $request->query('status', 'pending');
        if (! array_key_exists($status, InventoryRequestService::STATUS_LABELS)) {
            $status = 'pending';
        }
        $type = (string) $request->query('type', '');
        if (! in_array($type, InventoryRequestService::TYPES, true)) {
            $type = '';
        }
        $q = trim((string) $request->query('q', ''));

        $sql = 'SELECT r.*, u.username,
                       p.first_name, p.last_name,
                       rv.username AS reviewer_name
                  FROM inventory_requests r
                  JOIN users u ON u.id = r.requester_id
             LEFT JOIN profiles p ON p.user_id = r.requester_id
             LEFT JOIN users rv ON rv.id = r.reviewed_by';
        $where = [];
        $params = [];

        if ($status !== '') {
            $where[] = 'r.status = ?';
            $params[] = $status;
        }
        if ($type !== '') {
            $where[] = 'r.request_type = ?';
            $params[] = $type;
        }
        if ($q !== '') {
            $where[] = '(r.name LIKE ? OR u.username LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
            $params[] = '%'.$q.'%';
            $params[] = '%'.$q.'%';
            $params[] = '%'.$q.'%';
        }
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY CASE r.status WHEN "pending" THEN 0 WHEN "approved" THEN 1 ELSE 2 END, r.created_at DESC, r.id DESC';

        try {
            $rows = DB::select($sql, $params);
        } catch (\Throwable $ex) {
            $rows = [];
        }

        $decoded = [];
        foreach ($rows as $row) {
            $row->data = json_decode((string) $row->payload, true) ?: [];
            $row->full_name = trim(((string) ($row->first_name ?? '')).' '.((string) ($row->last_name ?? '')));
            if ($row->full_name === '') {
                $row->full_name = (string) $row->username;
            }
            $decoded[] = $row;
        }

        $totalItems = count($decoded);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page = (int) $request->query('page', 1);
        if ($page < 1) {
            $page = 1;
        } elseif ($page > $totalPages) {
            $page = $totalPages;
        }
        $pageRows = array_slice($decoded, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Counts for the status filter, so the dropdown can show how much is
        // waiting without a second query per option.
        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        try {
            foreach (DB::select('SELECT status, COUNT(*) AS c FROM inventory_requests GROUP BY status') as $c) {
                if (array_key_exists((string) $c->status, $counts)) {
                    $counts[(string) $c->status] = (int) $c->c;
                }
            }
        } catch (\Throwable $ex) {
            $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        }

        return view('admin.inventory_requests', [
            'title' => 'Inventory Requests',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/inventory_requests'),
            'errors' => $errors,
            'rows' => $pageRows,
            'counts' => $counts,
            'status' => $status,
            'type' => $type,
            'q' => $q,
            'details' => $this->details($request),
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'totalItems' => $totalItems,
                'perPage' => self::PER_PAGE,
                'baseUrl' => url('admin/inventory_requests'),
                'query' => array_filter([
                    'status' => $status,
                    'type' => $type !== '' ? $type : null,
                    'q' => $q !== '' ? $q : null,
                ], fn ($v) => $v !== null && $v !== ''),
            ],
        ]);
    }

    /**
     * Resolve ?view=request&id= into the record the details modal shows.
     * Re-read by id, so the modal can never show a different record than the
     * button that opened it.
     */
    private function details(Request $request): ?object
    {
        if ((string) $request->query('view', '') !== 'request') {
            return null;
        }
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return null;
        }

        try {
            $row = DB::selectOne(
                'SELECT r.*, u.username,
                        p.first_name, p.last_name,
                        rv.username AS reviewer_name
                   FROM inventory_requests r
                   JOIN users u ON u.id = r.requester_id
              LEFT JOIN profiles p ON p.user_id = r.requester_id
              LEFT JOIN users rv ON rv.id = r.reviewed_by
                  WHERE r.id = ? LIMIT 1',
                [$id],
            );
        } catch (\Throwable $ex) {
            return null;
        }

        if (! $row) {
            return null;
        }

        $row->data = json_decode((string) $row->payload, true) ?: [];
        $row->full_name = trim(((string) ($row->first_name ?? '')).' '.((string) ($row->last_name ?? '')));
        if ($row->full_name === '') {
            $row->full_name = (string) $row->username;
        }

        return $row;
    }
}
