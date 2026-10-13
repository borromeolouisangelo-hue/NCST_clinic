<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Patient Activity History - the combined, per-patient activity feed behind
 * the Patient Overview's "View All Activities" button.
 *
 * One UNION ALL of per-type subqueries normalizes the five existing activity
 * tables into a single shape (id, activity_type, description, raw_status,
 * occurred_at, is_overdue), each subquery already filtered by the selected
 * patient, the optional search LIKE and its type. Nothing is denormalized:
 * every row still points at its original record, and the outer query pages
 * with COUNT(*) + LIMIT/OFFSET so a patient's full history is never loaded
 * into memory just to paginate it.
 *
 * Per-type ownership and date fields (the same relationships the staff
 * history sections use):
 *
 *   consultation  consultations.patient_id  -> consultation_date
 *   dispense      medicine_requests.user_id -> requested_at
 *   equipment_loan equipment_loans.user_id  -> borrowed_at
 *   certificate   medical_certificates.user_id -> issued_at
 *   sick_leave    sick_leaves.user_id       -> created_at (EMPLOYEES ONLY -
 *                 sick leave is an employee-only feature, so a student's
 *                 feed never queries or offers it)
 *
 * Searchable fields per type mirror what each row shows in its Details
 * column, so the search can never match text the staff cannot see.
 *
 * Beyond the feed, detail() is the read-only single-record fetch behind
 * the activity detail page (?type= + ?id=): same ownership joins, every
 * field the record carries (certificates reuse the certificates page).
 */
class PatientActivityService
{
    /**
     * The activity types, keyed by the ?type= value: label + icon follow the
     * StaffHistory section conventions (same icons per record family).
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function types(): array
    {
        return [
            'consultation' => ['label' => 'Consultation', 'icon' => 'bi-clipboard2-pulse'],
            'dispense' => ['label' => 'Medicine Dispensed', 'icon' => 'bi-capsule'],
            'equipment_loan' => ['label' => 'Equipment Loan', 'icon' => 'bi-arrow-left-right'],
            'certificate' => ['label' => 'Medical Certificate', 'icon' => 'bi-file-medical'],
            'sick_leave' => ['label' => 'Sick Leave', 'icon' => 'bi-thermometer'],
        ];
    }

    /**
     * The activity-type filter options for one patient: "all" plus every
     * type, with Sick Leave added for EMPLOYEES ONLY (students never have
     * sick-leave records, so the option is not offered to them).
     *
     * @return array<string, string> value => label
     */
    public function filterOptions(string $accountType): array
    {
        $options = ['all' => 'All Activities'];

        foreach (self::types() as $key => $meta) {
            if ($key === 'sick_leave' && $accountType !== 'employee') {
                continue;
            }

            $options[$key] = $meta['label'];
        }

        return $options;
    }

    /**
     * The newest activities for the Patient Overview preview (the page keeps
     * the 10 most recent; the full history lives behind the activities page).
     *
     * @return array<int, array>
     */
    public function recent(int $patientId, string $accountType, int $limit = 10): array
    {
        if ($patientId <= 0) {
            return [];
        }

        $page = $this->page($patientId, $accountType, '', 'all', max(1, $limit), 0, withTotal: false);

        return $page['rows'];
    }

    /**
     * One server-side page of the combined history, newest first.
     *
     * @param  string  $q  raw search text (already trimmed by the caller)
     * @param  string  $type  'all' or a key of filterOptions() for this patient
     * @return array{rows: array<int, array>, total: int}
     */
    public function search(int $patientId, string $accountType, string $q, string $type, int $limit, int $offset): array
    {
        if ($patientId <= 0) {
            return ['rows' => [], 'total' => 0];
        }

        $page = $this->page($patientId, $accountType, $q, $type, max(1, $limit), max(0, $offset), withTotal: true);

        return ['rows' => $page['rows'], 'total' => (int) $page['total']];
    }

    /**
     * The shared page runner: build the per-type sources, keep only the
     * requested one (or all), then COUNT(*) + one page query over the union.
     *
     * @return array{rows: array<int, array>, total: int}
     */
    private function page(int $patientId, string $accountType, string $q, string $type, int $limit, int $offset, bool $withTotal): array
    {
        $sources = $this->sources($patientId, $accountType, $q);

        // Whitelist the type against THIS patient's options: an unknown key -
        // or Sick Leave requested for a student - yields an empty result
        // rather than a query the account can never match.
        if ($type !== 'all') {
            $sources = isset($sources[$type]) ? [$type => $sources[$type]] : [];
        }

        if ($sources === []) {
            return ['rows' => [], 'total' => 0];
        }

        $union = implode(' UNION ALL ', array_column($sources, 'sql'));
        $params = array_merge(...array_column($sources, 'params'));

        $total = 0;
        if ($withTotal) {
            $total = (int) (DB::selectOne("SELECT COUNT(*) AS total FROM ({$union}) AS activity_sub", $params)->total ?? 0);
        }

        $rows = DB::select(
            "{$union} ORDER BY occurred_at DESC, activity_type ASC, id ASC LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        return [
            'rows' => array_map(fn ($row) => $this->present((array) $row), $rows),
            'total' => $total,
        ];
    }

    /**
     * One normalized subquery per activity type, filtered by the patient id
     * (each type's own ownership column) and the optional search LIKE. Keys
     * are the ?type= values.
     *
     * @return array<string, array{sql: string, params: array<int, mixed>}>
     */
    private function sources(int $patientId, string $accountType, string $q): array
    {
        $like = $q === '' ? null : '%'.$q.'%';
        $sources = [];

        // Consultations - complaint with diagnosis fallback for the detail.
        $sql = "SELECT c.id AS id, 'consultation' AS activity_type,
                       COALESCE(NULLIF(c.complaint, ''), c.diagnosis, 'Consultation recorded') AS description,
                       NULL AS raw_status, c.consultation_date AS occurred_at, 0 AS is_overdue
                  FROM consultations c
                 WHERE c.patient_id = ?";
        $params = [$patientId];
        if ($like !== null) {
            $sql .= ' AND (c.complaint LIKE ? OR c.diagnosis LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sources['consultation'] = ['sql' => $sql, 'params' => $params];

        // Medicine dispensing - the medicine_requests rows the dispense
        // page and the staff dispense history section read.
        $sql = "SELECT r.id AS id, 'dispense' AS activity_type,
                       CONCAT(m.name, ' x ', r.quantity) AS description,
                       r.status AS raw_status, r.requested_at AS occurred_at, 0 AS is_overdue
                  FROM medicine_requests r
                  JOIN medicines m ON m.id = r.medicine_id
                 WHERE r.user_id = ?";
        $params = [$patientId];
        if ($like !== null) {
            $sql .= ' AND (m.name LIKE ? OR r.reason LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sources['dispense'] = ['sql' => $sql, 'params' => $params];

        // Equipment loans - is_overdue uses the same SUBDATE(NOW(), 1) the
        // staff loan history does, so the badge can never disagree with it.
        $sql = "SELECT l.id AS id, 'equipment_loan' AS activity_type,
                       CONCAT(e.name, ' x ', l.quantity) AS description,
                       l.status AS raw_status, l.borrowed_at AS occurred_at,
                       CASE WHEN l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)
                            THEN 1 ELSE 0 END AS is_overdue
                  FROM equipment_loans l
                  JOIN equipment e ON e.id = l.equipment_id
                 WHERE l.user_id = ?";
        $params = [$patientId];
        if ($like !== null) {
            $sql .= ' AND e.name LIKE ?';
            $params[] = $like;
        }
        $sources['equipment_loan'] = ['sql' => $sql, 'params' => $params];

        // Medical certificates - purpose is what the staff list shows. A
        // certificate is "Issued" only when the record actually carries an
        // issued_at timestamp (every row the issuance workflow writes does).
        $sql = "SELECT c.id AS id, 'certificate' AS activity_type,
                       COALESCE(NULLIF(c.purpose, ''), 'Certificate issued') AS description,
                       CASE WHEN c.issued_at IS NOT NULL THEN 'issued' ELSE NULL END AS raw_status,
                       c.issued_at AS occurred_at, 0 AS is_overdue
                  FROM medical_certificates c
                 WHERE c.user_id = ?";
        $params = [$patientId];
        if ($like !== null) {
            $sql .= ' AND (c.purpose LIKE ? OR c.cert_no LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $sources['certificate'] = ['sql' => $sql, 'params' => $params];

        // Sick leaves - EMPLOYEES ONLY: the source is not built at all for
        // students, so their feed never queries (or offers) the table.
        if ($accountType === 'employee') {
            $sql = "SELECT sl.id AS id, 'sick_leave' AS activity_type,
                           COALESCE(NULLIF(sl.reason, ''), 'Sick leave filed') AS description,
                           sl.status AS raw_status, sl.created_at AS occurred_at, 0 AS is_overdue
                      FROM sick_leaves sl
                     WHERE sl.user_id = ?";
            $params = [$patientId];
            if ($like !== null) {
                $sql .= ' AND sl.reason LIKE ?';
                $params[] = $like;
            }
            $sources['sick_leave'] = ['sql' => $sql, 'params' => $params];
        }

        return $sources;
    }

    /**
     * Present one union row for the Blade tables: label/icon, the formatted
     * details, the normalized status badge (same label/class pairs the
     * history-table partial prints per section) and the record link.
     */
    private function present(array $row): array
    {
        $type = (string) $row['activity_type'];
        $meta = self::types()[$type] ?? ['label' => ucfirst(str_replace('_', ' ', $type)), 'icon' => 'bi-clock-history'];

        return [
            'type' => $type,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'record_id' => (int) $row['id'],
            'description' => (string) $row['description'],
            'occurred_at' => (string) $row['occurred_at'],
            'status' => $this->status($type, (string) ($row['raw_status'] ?? ''), (int) ($row['is_overdue'] ?? 0)),
            'url' => $this->url($type, (int) $row['id']),
        ];
    }

    /**
     * Status badge per type, copied from the shared history-table partial so
     * the same record can never wear two different colours. Consultations
     * carry no status -> null (the views print '—'); certificates wear the
     * success "Issued" badge only when the record carries an issued_at, so
     * an unissued certificate is never presented as issued.
     *
     * @return array{label: string, badge: string}|null
     */
    private function status(string $type, string $raw, int $isOverdue): ?array
    {
        return match ($type) {
            'certificate' => $raw === 'issued'
                ? ['label' => 'Issued', 'badge' => 'bg-success']
                : null,
            'dispense' => match (true) {
                $raw === 'dispensed' => ['label' => 'Dispensed', 'badge' => 'bg-success'],
                $raw === 'rejected' => ['label' => 'Declined', 'badge' => 'bg-danger'],
                default => ['label' => $raw !== '' ? ucfirst($raw) : 'Unknown', 'badge' => 'bg-secondary'],
            },
            'equipment_loan' => match (true) {
                $raw === 'returned' => ['label' => 'Returned', 'badge' => 'bg-success'],
                $isOverdue === 1 => ['label' => 'Overdue', 'badge' => 'bg-danger'],
                default => ['label' => 'Borrowed', 'badge' => 'bg-orange'],
            },
            'sick_leave' => match (true) {
                $raw === 'pending' => ['label' => 'Pending', 'badge' => 'bg-warning text-dark'],
                $raw === 'approved' => ['label' => 'Approved', 'badge' => 'bg-success'],
                default => ['label' => 'Declined', 'badge' => 'bg-danger'],
            },
            default => null,
        };
    }

    /**
     * The link to the record's read-only detail page. Certificates reuse
     * the existing certificates page (?view= renders the certificate
     * document itself, never the print dialog); every other family opens
     * the shared activity detail page (?type= + ?id=), which fetches the
     * record with the same patient-ownership rule the feed uses, so no row
     * ever links to a bare list or an edit form pretending to be a view.
     */
    private function url(string $type, int $id): ?string
    {
        return match ($type) {
            'certificate' => url('staff/certificates?view='.$id),
            'consultation', 'dispense', 'equipment_loan', 'sick_leave' => url(
                'staff/patient_activity_detail?type='.$type.'&id='.$id,
            ),
            default => null,
        };
    }

    /**
     * One read-only record fetch for the activity detail page (?type= +
     * ?id=): every field the record carries, normalized for the Blade view
     * (label + value rows, optional badge), plus the patient owning it.
     *
     * Each source joins the owning account and requires role='user' (sick
     * leaves additionally require an employee, mirroring the feed), so an
     * unknown id - or a record belonging to a non-patient account - yields
     * null and the controller redirects to the patient list. The
     * certificate family is not fetched here: its View reuses the existing
     * certificates page, which renders the certificate document itself.
     *
     * @return array{type: string, label: string, icon: string, patient_id: int, fields: array<int, array<string, mixed>>}|null
     */
    public function detail(string $type, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return match ($type) {
            'consultation' => $this->consultationDetail($id),
            'dispense' => $this->dispenseDetail($id),
            'equipment_loan' => $this->loanDetail($id),
            'sick_leave' => $this->sickLeaveDetail($id),
            default => null,
        };
    }

    /**
     * One consultation row with the staff member who recorded it. Every
     * column the consultations form saves is listed, vitals included.
     */
    private function consultationDetail(int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT c.*, CONCAT(s.first_name, ' ', s.last_name) AS staff_name
               FROM consultations c
               LEFT JOIN profiles s ON s.user_id = c.staff_id
               JOIN users u ON u.id = c.patient_id
              WHERE c.id = ? AND u.role = 'user'
              LIMIT 1",
            [$id],
        );

        if (! $row) {
            return null;
        }

        return $this->wrap('consultation', (int) $row->patient_id, [
            ['label' => 'Consultation Date', 'value' => fmt_dt($row->consultation_date, 'M d, Y g:i A')],
            ['label' => 'Recorded By', 'value' => $this->display($row->staff_name ?? null)],
            ['label' => 'Chief Complaint', 'value' => $this->display($row->complaint ?? null), 'full' => true],
            ['label' => 'Diagnosis', 'value' => $this->display($row->diagnosis ?? null), 'full' => true],
            ['label' => 'Treatment', 'value' => $this->display($row->treatment ?? null), 'full' => true],
            ['label' => 'Notes', 'value' => $this->display($row->notes ?? null), 'full' => true],
            ['label' => 'Blood Pressure', 'value' => $this->display($row->blood_pressure ?? null)],
            ['label' => 'Temperature (°C)', 'value' => $this->display($row->temperature ?? null)],
            ['label' => 'Pulse (bpm)', 'value' => $this->display($row->pulse ?? null)],
            ['label' => 'Respiration', 'value' => $this->display($row->respiration ?? null)],
            ['label' => 'Weight (kg)', 'value' => $this->display($row->weight_kg ?? null)],
            ['label' => 'Height (cm)', 'value' => $this->display($row->height_cm ?? null)],
        ]);
    }

    /**
     * One dispensed-medicine request with its medicine and the staff member
     * who processed it, including the allergy-override bookkeeping.
     */
    private function dispenseDetail(int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT r.*, m.name AS medicine_name, CONCAT(s.first_name, ' ', s.last_name) AS processed_name
               FROM medicine_requests r
               JOIN medicines m ON m.id = r.medicine_id
               LEFT JOIN profiles s ON s.user_id = r.processed_by
               JOIN users u ON u.id = r.user_id
              WHERE r.id = ? AND u.role = 'user'
              LIMIT 1",
            [$id],
        );

        if (! $row) {
            return null;
        }

        $fields = [
            ['label' => 'Medicine', 'value' => (string) $row->medicine_name],
            ['label' => 'Quantity', 'value' => (string) (int) $row->quantity],
            ['label' => 'Reason', 'value' => $this->display($row->reason ?? null), 'full' => true],
            ['label' => 'Requested', 'value' => fmt_dt($row->requested_at, 'M d, Y g:i A')],
            ['label' => 'Status', 'badge' => $this->status('dispense', (string) $row->status, 0)],
            ['label' => 'Processed By', 'value' => $this->display($row->processed_name ?? null)],
            ['label' => 'Processed At', 'value' => $row->processed_at ? fmt_dt($row->processed_at, 'M d, Y g:i A') : '—'],
            ['label' => 'Allergy Override', 'value' => ! empty($row->allergy_flag) ? 'Yes' : 'No'],
        ];

        if (! empty($row->allergy_flag)) {
            $fields[] = ['label' => 'Override Reason', 'value' => $this->display($row->override_reason ?? null), 'full' => true];
        }

        $fields[] = ['label' => 'Remarks', 'value' => $this->display($row->remarks ?? null), 'full' => true];

        return $this->wrap('dispense', (int) $row->user_id, $fields);
    }

    /**
     * One equipment loan with its equipment and the staff members on both
     * ends of the exchange; is_overdue uses the same SUBDATE(NOW(), 1) the
     * feed (and the staff loan history) uses, so the badge can never
     * disagree with them.
     */
    private function loanDetail(int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT l.*, e.name AS equipment_name,
                    CONCAT(i.first_name, ' ', i.last_name) AS issued_name,
                    CONCAT(rc.first_name, ' ', rc.last_name) AS received_name,
                    CASE WHEN l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)
                         THEN 1 ELSE 0 END AS is_overdue
               FROM equipment_loans l
               JOIN equipment e ON e.id = l.equipment_id
               LEFT JOIN profiles i ON i.user_id = l.issued_by
               LEFT JOIN profiles rc ON rc.user_id = l.received_by
               JOIN users u ON u.id = l.user_id
              WHERE l.id = ? AND u.role = 'user'
              LIMIT 1",
            [$id],
        );

        if (! $row) {
            return null;
        }

        return $this->wrap('equipment_loan', (int) $row->user_id, [
            ['label' => 'Equipment', 'value' => (string) $row->equipment_name],
            ['label' => 'Quantity', 'value' => (string) (int) $row->quantity],
            ['label' => 'Borrow Date', 'value' => $row->borrow_date ? date('M d, Y', strtotime((string) $row->borrow_date)) : '—'],
            ['label' => 'Borrowed At', 'value' => fmt_dt($row->borrowed_at, 'M d, Y g:i A')],
            ['label' => 'Due Date', 'value' => $row->due_date ? date('M d, Y', strtotime((string) $row->due_date)) : '—'],
            ['label' => 'Return Date', 'value' => $row->return_date ? date('M d, Y', strtotime((string) $row->return_date)) : '—'],
            ['label' => 'Status', 'badge' => $this->status('equipment_loan', (string) $row->status, (int) $row->is_overdue)],
            ['label' => 'Issued By', 'value' => $this->display($row->issued_name ?? null)],
            ['label' => 'Received By', 'value' => $this->display($row->received_name ?? null)],
            ['label' => 'Condition on Return', 'value' => $this->display($row->condition_on_return ?? null)],
            ['label' => 'Notes', 'value' => $this->display($row->notes ?? null), 'full' => true],
        ]);
    }

    /**
     * One employee sick leave with its reviewer, mirroring the shared
     * details modal's field set (period, filed, status, review line, note).
     */
    private function sickLeaveDetail(int $id): ?array
    {
        $row = DB::selectOne(
            "SELECT sl.*, CONCAT(s.first_name, ' ', s.last_name) AS reviewed_name
               FROM sick_leaves sl
               JOIN users u ON u.id = sl.user_id
               LEFT JOIN profiles s ON s.user_id = sl.reviewed_by
              WHERE sl.id = ? AND u.role = 'user' AND u.account_type = 'employee'
              LIMIT 1",
            [$id],
        );

        if (! $row) {
            return null;
        }

        $period = date('M d, Y', strtotime((string) $row->start_date))
            .($row->end_date ? ' – '.date('M d, Y', strtotime((string) $row->end_date)) : '');

        $review = $row->reviewed_at
            ? 'Reviewed by '.$this->display($row->reviewed_name ?? null)
                .' on '.fmt_dt($row->reviewed_at, 'M d, Y g:i A')
            : '—';

        return $this->wrap('sick_leave', (int) $row->user_id, [
            ['label' => 'Reason', 'value' => (string) $row->reason, 'full' => true],
            ['label' => 'Leave Period', 'value' => $period],
            ['label' => 'Filed', 'value' => fmt_dt($row->created_at, 'M d, Y g:i A')],
            ['label' => 'Status', 'badge' => $this->status('sick_leave', (string) $row->status, 0)],
            ['label' => 'Review', 'value' => $review],
            ['label' => 'Review Note', 'value' => $this->display($row->review_note ?? null), 'full' => true],
        ]);
    }

    /**
     * Shared detail-page shape: the feed's label/icon for the card header
     * plus the owning patient (the detail view's Back link target).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array{type: string, label: string, icon: string, patient_id: int, fields: array<int, array<string, mixed>>}
     */
    private function wrap(string $type, int $patientId, array $fields): array
    {
        $meta = self::types()[$type];

        return [
            'type' => $type,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'patient_id' => $patientId,
            'fields' => $fields,
        ];
    }

    /**
     * One field value for the detail view: empty/null becomes the em dash
     * every staff page uses for "nothing recorded".
     */
    private function display(mixed $value): string
    {
        return ($value === null || $value === '') ? '—' : (string) $value;
    }
}
