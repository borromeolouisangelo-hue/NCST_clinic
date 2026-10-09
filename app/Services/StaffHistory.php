<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Port of the shared staff history builders from includes/functions.php
 * (read-only reference):
 *
 *   staff_history_tabs()    -> tabs()
 *   staff_history_colspan() -> colspan()
 *   staff_history_build()   -> build()
 *   staff_history_rows()    -> rows()
 *   staff_history_table()   -> table()  (renders the Blade mirror of
 *                               staff_history_table(), lines 1019-1277)
 *
 * staff/dashboard.php renders the 10 newest rows of every section through
 * these; staff/history.php (Phase 18) will render the full, unbounded
 * history through the same code so columns, badges and actions can never
 * drift apart - exactly like the original's shared function pair.
 *
 * SQL parity: statements are the originals, with DATE_SUB(d, INTERVAL n DAY)
 * resp. INTERVAL 24 HOUR spelled as SUBDATE(d, n) (24 HOUR = 1 day) - the
 * same MySQL arithmetic in plain-function form so the SQLite test
 * connection (see tests/TestCase.php shims) can run them unmodified.
 */
class StaffHistory
{
    /** The seven history sections, keyed by the value used in ?tab=. */
    public static function tabs(): array
    {
        return [
            'patients' => ['label' => 'Patients',        'icon' => 'bi-people'],
            'consultations' => ['label' => 'Consultations',   'icon' => 'bi-clipboard2-pulse'],
            'certificates' => ['label' => 'Certificates',    'icon' => 'bi-file-medical'],
            'sick-leaves' => ['label' => 'Sick Leaves',     'icon' => 'bi-thermometer'],
            'dispense' => ['label' => 'Dispense',        'icon' => 'bi-capsule'],
            'inventory' => ['label' => 'Inventory',       'icon' => 'bi-box-seam'],
            'equipment-loans' => ['label' => 'Equipment Loans', 'icon' => 'bi-arrow-left-right'],
        ];
    }

    /** Column count per section, used for the "no records" row. */
    public static function colspan(string $tab): int
    {
        $map = [
            'patients' => 7,
            'consultations' => 5,
            'certificates' => 6,
            'sick-leaves' => 7,
            'dispense' => 7,
            'inventory' => 10,
            'equipment-loans' => 7,
        ];

        return $map[$tab] ?? 1;
    }

    /**
     * A query that is valid SQL but returns nothing. Used when the caller
     * passes an unknown ?tab= value, so a bad URL can never produce a SQL
     * error or a blank page. (Original: SELECT NULL AS id FROM DUAL ...)
     */
    public static function emptyTab(): array
    {
        return ['sql' => 'SELECT NULL AS id FROM DUAL WHERE 1 = 0', 'params' => []];
    }

    /**
     * Build the SELECT for one history section (original staff_history_build).
     *
     * @param  array  $f  Filter values (already raw, from the query string).
     * @return array{sql: string, params: array} No LIMIT - callers add their own.
     */
    public static function build(string $tab, array $f = []): array
    {
        $q = trim((string) ($f['q'] ?? ''));
        $like = $q === '' ? null : '%'.$q.'%';
        $sql = '';
        $param = [];

        switch ($tab) {

            case 'patients':
                $sql = "SELECT u.id, u.username, u.account_type, u.status,
                               CONCAT(p.first_name,' ',p.last_name) AS full_name,
                               p.contact_no, sd.student_no, ed.employee_no
                        FROM users u
                        LEFT JOIN profiles p          ON p.user_id   = u.id
                        LEFT JOIN student_details sd  ON sd.user_id  = u.id
                        LEFT JOIN employee_details ed ON ed.user_id  = u.id
                        WHERE u.role = 'user'";
                $type = (string) ($f['type'] ?? 'all');
                if (in_array($type, ['student', 'employee'], true)) {
                    $sql .= ' AND u.account_type = ?';
                    $param[] = $type;
                }
                if ($like !== null) {
                    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                                    OR p.first_name LIKE ? OR p.last_name LIKE ?
                                    OR u.username LIKE ? OR sd.student_no LIKE ?
                                    OR ed.employee_no LIKE ?)";
                    for ($i = 0; $i < 6; $i++) {
                        $param[] = $like;
                    }
                }
                $sql .= ' ORDER BY u.created_at DESC, u.id DESC';
                break;

            case 'consultations':
                $sql = "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name
                        FROM consultations c
                        LEFT JOIN profiles p ON p.user_id = c.patient_id
                        WHERE 1 = 1";
                if ($like !== null) {
                    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                                    OR p.first_name LIKE ? OR p.last_name LIKE ?
                                    OR c.complaint LIKE ? OR c.diagnosis LIKE ?
                                    OR c.treatment LIKE ?)";
                    for ($i = 0; $i < 6; $i++) {
                        $param[] = $like;
                    }
                }
                $when = (string) ($f['filter'] ?? 'all');
                if ($when === 'today') {
                    $sql .= ' AND DATE(c.consultation_date) = CURDATE()';
                } elseif ($when === 'week') {
                    $sql .= ' AND c.consultation_date >= SUBDATE(CURDATE(), 7)';
                } elseif ($when === 'month') {
                    $sql .= ' AND c.consultation_date >= SUBDATE(CURDATE(), 30)';
                }
                $sql .= ' ORDER BY c.consultation_date DESC, c.id DESC';
                break;

            case 'certificates':
                $sql = "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                               p.gender, p.birthdate
                        FROM medical_certificates c
                        LEFT JOIN profiles p ON p.user_id = c.user_id
                        WHERE 1 = 1";
                $ctype = (string) ($f['type'] ?? 'all');
                if (in_array($ctype, ['medical', 'excuse', 'fit'], true)) {
                    $sql .= ' AND c.cert_type = ?';
                    $param[] = $ctype;
                }
                if ($like !== null) {
                    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                                    OR p.first_name LIKE ? OR p.last_name LIKE ?
                                    OR c.cert_no LIKE ? OR c.purpose LIKE ?)";
                    for ($i = 0; $i < 5; $i++) {
                        $param[] = $like;
                    }
                }
                $sql .= ' ORDER BY c.issued_at DESC, c.id DESC';
                break;

            case 'sick-leaves':
                $sql = "SELECT sl.*, CONCAT(p.first_name,' ',p.last_name) AS employee_name,
                               ed.department, ed.position, ed.employee_no,
                               CONCAT(rp.first_name,' ',rp.last_name) AS reviewer_name
                        FROM sick_leaves sl
                        LEFT JOIN profiles p          ON p.user_id  = sl.user_id
                        LEFT JOIN employee_details ed ON ed.user_id  = sl.user_id
                        LEFT JOIN profiles rp         ON rp.user_id  = sl.reviewed_by
                        WHERE 1 = 1";
                $st = (string) ($f['status'] ?? 'all');
                if (in_array($st, ['pending', 'approved', 'rejected'], true)) {
                    $sql .= ' AND sl.status = ?';
                    $param[] = $st;
                }
                if ($like !== null) {
                    $sql .= ' AND (CONCAT(p.first_name, \' \', p.last_name) LIKE ? OR ed.employee_no LIKE ?)';
                    $param[] = $like;
                    $param[] = $like;
                }
                $sql .= ' ORDER BY sl.created_at DESC, sl.id DESC';
                break;

            case 'dispense':
                $sql = "SELECT r.*, m.name AS medicine_name,
                               CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                               u.username AS processed_username
                        FROM medicine_requests r
                        JOIN medicines m     ON m.id = r.medicine_id
                        LEFT JOIN profiles p ON p.user_id = r.user_id
                        LEFT JOIN users u    ON u.id  = r.processed_by
                        WHERE 1 = 1";
                $hs = (string) ($f['history_status'] ?? 'all');
                if (in_array($hs, ['dispensed', 'rejected'], true)) {
                    $sql .= ' AND r.status = ?';
                    $param[] = $hs;
                }
                if ($like !== null) {
                    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                                    OR p.first_name LIKE ? OR p.last_name LIKE ?
                                    OR m.name LIKE ? OR m.generic_name LIKE ?
                                    OR r.reason LIKE ?)";
                    for ($i = 0; $i < 6; $i++) {
                        $param[] = $like;
                    }
                }
                $sql .= ' ORDER BY r.requested_at DESC, r.id DESC';
                break;

            case 'inventory':
                $sql = "SELECT m.id, m.name AS medicine_name, m.unit, m.low_stock_threshold,
                               c.name AS category_name,
                               CASE WHEN c.type = 'first_aid' THEN 'first_aid' ELSE 'medicine' END AS item_type,
                               COALESCE(SUM(b.quantity), 0) AS total_qty,
                               MIN(CASE WHEN b.expiry_date IS NOT NULL AND b.expiry_date >= CURDATE() THEN b.expiry_date END) AS next_expiry_date,
                               MAX(CASE WHEN b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS has_expired,
                               MAX(b.date_received) AS last_received
                        FROM medicines m
                        LEFT JOIN medicine_categories c ON c.id = m.category_id
                        LEFT JOIN medicine_batches b    ON b.medicine_id = m.id AND b.quantity > 0
                        WHERE m.is_active = 1";
                $it = (string) ($f['item_type'] ?? 'all');
                if (in_array($it, ['medicine', 'first_aid'], true)) {
                    $sql .= " AND CASE WHEN c.type = 'first_aid' THEN 'first_aid' ELSE 'medicine' END = ?";
                    $param[] = $it;
                }
                if ($like !== null) {
                    $sql .= ' AND (m.name LIKE ? OR m.generic_name LIKE ? OR c.name LIKE ?)';
                    $param[] = $like;
                    $param[] = $like;
                    $param[] = $like;
                }
                $sql .= ' GROUP BY m.id, m.name, m.unit, m.low_stock_threshold, c.name, c.type
                          ORDER BY m.name ASC';
                break;

            case 'equipment-loans':
                // Feature 3: is_overdue uses the same SUBDATE(NOW(), 1) the
                // status filter below does, so the badge and the filter can
                // never disagree (the blade's PHP-side time() derivation ran
                // six hours ahead of the database clock).
                $sql = "SELECT l.*, e.name AS equipment_name,
                               CONCAT(p.first_name,' ',p.last_name) AS borrower_name,
                               CASE WHEN l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)
                                    THEN 1 ELSE 0 END AS is_overdue
                        FROM equipment_loans l
                        JOIN equipment e     ON e.id = l.equipment_id
                        LEFT JOIN profiles p ON p.user_id = l.user_id
                        WHERE 1 = 1";
                $st = (string) ($f['status'] ?? 'all');
                if ($st === 'overdue') {
                    $sql .= " AND l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)";
                } elseif (in_array($st, ['borrowed', 'returned'], true)) {
                    $sql .= ' AND l.status = ?';
                    $param[] = $st;
                }
                if ($like !== null) {
                    $sql .= " AND (CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                                    OR p.first_name LIKE ? OR p.last_name LIKE ?
                                    OR e.name LIKE ?)";
                    for ($i = 0; $i < 4; $i++) {
                        $param[] = $like;
                    }
                }
                $sql .= ' ORDER BY l.borrowed_at DESC, l.id DESC';
                break;

            default:
                return self::emptyTab();
        }

        return ['sql' => $sql, 'params' => $param];
    }

    /**
     * Run a section query. $limit null = full history (staff/history.php);
     * an int = the dashboard preview.
     *
     * Supports pagination via $limit and $offset.
     *
     * Never throws: a failed query yields an empty set plus the message, so
     * the page still renders instead of going blank (original behaviour).
     *
     * @return array{rows: array<int, array>, error: string|null, total: int}
     */
    public static function rows(string $tab, array $filters = [], ?int $limit = null, ?int $offset = null): array
    {
        $built = self::build($tab, $filters);
        $sql = $built['sql'];

        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) AS sub";
        $total = 0;
        try {
            $countResult = DB::select($countSql, $built['params']);
            $total = (int) ($countResult[0]->total ?? 0);
        } catch (Throwable $ex) {
            $total = 0;
        }

        if ($limit !== null) {
            $sql .= ' LIMIT '.max(1, $limit);
        }
        if ($offset !== null) {
            $sql .= ' OFFSET '.max(0, $offset);
        }

        try {
            $rows = DB::select($sql, $built['params']);
        } catch (Throwable $ex) {
            return ['rows' => [], 'error' => $ex->getMessage(), 'total' => $total];
        }

        return [
            'rows' => array_map(fn ($row) => (array) $row, $rows),
            'error' => null,
            'total' => $total,
        ];
    }

    /**
     * Render the <table> (head + body) for one history section through the
     * Blade mirror of the original staff_history_table() - columns, badges,
     * links and actions match the corresponding staff page exactly.
     *
     * $emptyText overrides the shared "No records found." row label for
     * pages whose original echoed its own wording (staff/patients.php said
     * "No patients found."); null keeps the shared label byte-identical.
     */
    public static function table(string $tab, array $rows, string $tableId = '', ?string $emptyText = null): string
    {
        return view('staff.partials.history-table', [
            'tab' => $tab,
            'rows' => $rows,
            'tableId' => $tableId,
            'colspan' => self::colspan($tab),
            'hdr' => self::headerRow($tab),
            'emptyText' => $emptyText,
        ])->render();
    }

    /**
     * The single merged <tr> header row echoed by staff_history_table()
     * (functions.php:1029-1050) - one exact string per history section.
     */
    private static function headerRow(string $tab): string
    {
        return match ($tab) {
            'patients' => '<tr><th>#</th><th>Name</th><th>ID No.</th><th>Type</th><th>Contact</th><th>Status</th><th class="text-end">Actions</th></tr>',
            'consultations' => '<tr><th>Date</th><th>Patient</th><th>Complaint</th><th>Diagnosis</th><th class="text-end">Actions</th></tr>',
            'certificates' => '<tr><th>PATIENT NAME</th><th>SEX / AGE</th><th>CONSULT DATE</th><th>DIAGNOSIS</th><th>REQUESTOR</th><th class="text-end">ACTIONS</th></tr>',
            'sick-leaves' => '<tr><th>Employee</th><th>Department</th><th>Reason</th><th>Leave Period</th><th>Filed</th><th>Status</th><th class="text-end">Actions</th></tr>',
            'dispense' => '<tr><th>Date</th><th>Patient</th><th>Medicine</th><th class="text-end">Qty</th><th>Reason</th><th>Status</th><th>Processed By</th></tr>',
            'inventory' => '<tr><th>#</th><th>Item</th><th>Type</th><th class="text-end">Total Stock</th><th>Unit</th><th class="text-end">Total Pieces</th><th>Threshold</th><th>Status</th><th>Next Expiry</th><th>Last Received</th></tr>',
            'equipment-loans' => '<tr><th>Borrower</th><th>Equipment</th><th class="text-end">Qty</th><th>Borrowed</th><th>24-Hour Timeout</th><th>Status</th><th class="text-end">Actions</th></tr>',
            default => '<tr><th>Record</th></tr>',
        };
    }
}
