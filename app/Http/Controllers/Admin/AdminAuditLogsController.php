<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin - Audit logs (admin/audit_logs.php).
 *
 * Read-only GET page: optional free-text search over username/action/details,
 * optional exact action filter (ANDed together), newest first, capped at
 * LIMIT 500 - a straight translation of the reference's WHERE-builder and
 * LEFT JOIN (u.username may be NULL for orphaned logs, the view prints
 * 'system').
 *
 * The reference also ran `SELECT DISTINCT action FROM audit_logs` into
 * $actions (original line 24) but never rendered it - the <select> is a
 * hardcoded option list - so like the listener-less fire_event() helper
 * (Phase 2), the dead query is not ported.
 *
 * Quirk kept: the options' `selected` state reads the RAW query string
 * (original view: $_GET['action_filter'] === '...') while the SQL filter uses
 * the TRIMMED value - a value like " login " filters rows but selects no
 * option. Laravel's TrimStrings middleware rewrites the Request query bag
 * (GET parameters arrive already trimmed) and $_GET is not populated for
 * synthetic test requests, so the untouched query string is recovered from
 * getRequestUri() - raw in real HTTP and in feature tests alike - and handed
 * to the view as $rawActionFilter.
 */
class AdminAuditLogsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $actionFilter = trim((string) $request->query('action_filter', ''));

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ?)';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
        }
        if ($actionFilter !== '') {
            $where[] = 'a.action = ?';
            $params[] = $actionFilter;
        }
        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        $sql = "SELECT a.*, u.username
                FROM audit_logs a
                LEFT JOIN users u ON u.id = a.user_id
                {$whereSql}
                ORDER BY a.created_at DESC
                LIMIT 500";

        $logs = array_map(
            static fn ($row): array => (array) $row,
            DB::select($sql, $params)
        );

        // The reference's <select> re-read the RAW query value ($_GET), so
        // recover it from the untouched request URI: parse_str() decodes it
        // exactly like PHP builds $_GET (whitespace and duplicates intact).
        $rawQuery = [];
        parse_str((string) (explode('?', $request->getRequestUri(), 2)[1] ?? ''), $rawQuery);

        return view('admin.audit_logs', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/audit_logs'),
            'title' => 'Audit Logs',
            'search' => $search,
            'rawActionFilter' => $rawQuery['action_filter'] ?? '',
            'logs' => $logs,
        ]);
    }
}
