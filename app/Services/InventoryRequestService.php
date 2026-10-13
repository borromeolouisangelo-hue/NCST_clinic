<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Inventory approval workflow - the bridge between the staff Inventory page
 * and the admin Inventory Requests queue.
 *
 * Two very different paths run through this class, and keeping them apart is
 * the whole point of the feature:
 *
 *  - SUBMIT   staff ask to CREATE something that does not exist yet: a new
 *             medicine / first aid product, a new piece of equipment, a new
 *             category or a new supplier. Nothing is written to the real
 *             inventory tables here - the submission lands as one pending
 *             `inventory_requests` row carrying a JSON payload, and every
 *             admin is notified.
 *
 *  - DECIDE   an admin approves or rejects that row. Approval is the FIRST
 *             and ONLY moment the active record is created, and it is guarded
 *             twice over: the whole thing runs in one DB transaction, and the
 *             status flip uses a `WHERE status = 'pending'` compare-and-set so
 *             a second (or concurrent) approval of the same request affects
 *             zero rows and creates nothing.
 *
 * Creation reuses the existing catalog/stock services rather than writing new
 * INSERTs, so the record an approval produces is byte-identical to one the
 * now-retired admin pages used to produce - same validation, same LOWER()
 * duplicate check, same audit rows, same stock movement:
 *
 *   medicine / first_aid  AdminMedicineService::save()  then StockService::receive()
 *   equipment             EquipmentStockService::receive()
 *   category              AdminCategoryService::save()
 *   supplier              AdminSupplierService::save()
 *
 * Restocking an EXISTING item deliberately does NOT come through here: it
 * stays a direct Stock In transaction (see InventoryController), only gains a
 * one-shot stock_receipts token so a double submit cannot double-count, and
 * fires an informational notification to the admins.
 */
class InventoryRequestService
{
    /** The request types staff may submit, in menu order. */
    public const TYPES = ['medicine', 'first_aid', 'equipment', 'category', 'supplier'];

    /** Display labels for the request types. */
    public const TYPE_LABELS = [
        'medicine' => 'Medicine',
        'first_aid' => 'First Aid / Wound Care',
        'equipment' => 'Equipment',
        'category' => 'Category',
        'supplier' => 'Supplier',
    ];

    /** Display labels for the request statuses. */
    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    /** Bootstrap badge classes for the request statuses. */
    public const STATUS_BADGES = [
        'pending' => 'bg-warning text-dark',
        'approved' => 'bg-success',
        'rejected' => 'bg-danger',
    ];

    /** Condition values an equipment request offers (same set Stock In uses). */
    public const CONDITIONS = EquipmentStockService::CONDITIONS;

    public function __construct(
        protected AdminMedicineService $medicineService,
        protected AdminCategoryService $categoryService,
        protected AdminSupplierService $supplierService,
        protected StockService $stockService,
        protected EquipmentStockService $equipmentStockService,
    ) {}

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function statusBadge(string $status): string
    {
        return self::STATUS_BADGES[$status] ?? 'bg-secondary';
    }

    /**
     * Validate one staff submission and park it as a pending request.
     *
     * @param  array<string, mixed>  $post  the raw request payload
     * @return array{ok: bool, errors: array<int, string>, request_id: int}
     */
    public function submit(int $staffId, array $post): array
    {
        $type = trim((string) ($post['request_type'] ?? ''));
        $name = trim((string) ($post['request_name'] ?? ''));
        $errors = [];

        if (! in_array($type, self::TYPES, true)) {
            $errors[] = 'Please choose what you are requesting.';
        }
        if ($name === '') {
            $errors[] = 'Name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors[] = 'Name cannot be longer than 150 characters.';
        }

        $data = [];
        $supId = (int) ($post['request_supplier_id'] ?? 0) ?: null;

        if ($type === 'medicine' || $type === 'first_aid') {
            $qty = (int) ($post['request_quantity'] ?? 0);
            $catId = (int) ($post['request_category_id'] ?? 0) ?: null;
            $expiry = $type === 'medicine' ? (trim((string) ($post['request_expiry_date'] ?? '')) ?: null) : null;
            $received = trim((string) ($post['request_date_received'] ?? '')) ?: date('Y-m-d');
            $unitCost = trim((string) ($post['request_unit_cost'] ?? '')) !== ''
                ? (float) $post['request_unit_cost']
                : null;

            // The same floor StockService::receive() enforces, checked up
            // front so a request can never be approved into a state the
            // receiving pipeline would refuse.
            if ($qty < 3) {
                $errors[] = 'Initial quantity must be at least 3.';
            }
            if ($catId !== null) {
                $row = DB::selectOne(
                    'SELECT type FROM medicine_categories WHERE id = ? LIMIT 1',
                    [$catId],
                );
                if (! $row || (string) $row->type !== $type) {
                    $errors[] = 'Please choose a category that matches the item type.';
                }
            }
            // Medicine batches always carry an expiry; first aid never
            // tracks one (the Stock In modal hides the field the same way).
            if ($type === 'medicine') {
                if ($expiry === null) {
                    $errors[] = 'Expiry date is required for medicine requests.';
                } elseif (! self::isDate($expiry)) {
                    $errors[] = 'Expiry date is invalid.';
                } elseif (strtotime($expiry) < strtotime('today')) {
                    $errors[] = 'Expiry date cannot be in the past.';
                }
            }
            if (strtotime($received) === false) {
                $errors[] = 'Date received is invalid.';
            }

            $data = [
                'generic_name' => trim((string) ($post['request_generic_name'] ?? '')),
                'category_id' => $catId,
                'unit' => trim((string) ($post['request_unit'] ?? '')),
                'description' => trim((string) ($post['request_description'] ?? '')),
                'low_stock_threshold' => max(0, (int) ($post['request_threshold'] ?? 10)),
                'initial_quantity' => $qty,
                'batch_no' => trim((string) ($post['request_batch_no'] ?? '')),
                'lot_no' => trim((string) ($post['request_lot_no'] ?? '')),
                'supplier_id' => $supId,
                'unit_cost' => $unitCost,
                'expiry_date' => $expiry,
                'date_received' => $received,
            ];
        } elseif ($type === 'equipment') {
            $qty = (int) ($post['request_quantity_eq'] ?? 0);
            // Every field group posts its own names: one modal holds all four
            // groups at once, so the equipment half cannot reuse the
            // medicine half's request_quantity / request_unit_cost keys.
            $received = trim((string) ($post['request_date_received_eq'] ?? '')) ?: date('Y-m-d');
            $unitCost = trim((string) ($post['request_unit_cost_eq'] ?? '')) !== ''
                ? (float) $post['request_unit_cost_eq']
                : null;
            if ($qty < 1) {
                $errors[] = 'Quantity must be at least 1 for equipment.';
            }
            if (strtotime($received) === false) {
                $errors[] = 'Date received is invalid.';
            }

            $data = [
                'description' => trim((string) ($post['request_description_eq'] ?? '')),
                'quantity' => $qty,
                'supplier_id' => $supId,
                'unit_cost' => $unitCost,
                'date_received' => $received,
                'condition' => in_array((string) ($post['request_condition'] ?? ''), self::CONDITIONS, true)
                    ? (string) $post['request_condition']
                    : 'good',
                'asset_id' => trim((string) ($post['request_asset_id'] ?? '')),
            ];
        } elseif ($type === 'category') {
            $catType = (string) ($post['request_category_type'] ?? '');
            if (! in_array($catType, ['medicine', 'first_aid'], true)) {
                $errors[] = 'Please choose the category type.';
            }

            $data = [
                'category_type' => $catType,
                'description' => trim((string) ($post['request_description_cat'] ?? '')),
            ];
        } elseif ($type === 'supplier') {
            $data = [
                'contact_person' => trim((string) ($post['request_contact_person'] ?? '')),
                'phone' => trim((string) ($post['request_phone'] ?? '')),
                'email' => trim((string) ($post['request_email'] ?? '')),
                'address' => trim((string) ($post['request_address'] ?? '')),
            ];
        }

        // Duplicate submission guard: the same person asking for the same new
        // record twice (double click, impatient retry) stays one request.
        if (! $errors && $type !== '') {
            $pending = DB::table('inventory_requests')
                ->where('requester_id', $staffId)
                ->where('request_type', $type)
                ->where('status', 'pending')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();
            if ($pending) {
                $errors[] = 'You already have a pending request for this '.self::typeLabel($type).' item.';
            }
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors, 'request_id' => 0];
        }

        $requestId = (int) DB::table('inventory_requests')->insertGetId([
            'requester_id' => $staffId,
            'request_type' => $type,
            'name' => $name,
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // Informational for admins only - it never blocks or delays anything.
        Notify::toRoles(['admin'], 'inventory_request_submitted', [
            'reference_id' => $requestId,
            'severity' => 'warning',
            'icon' => 'bi-inbox',
            'title' => 'New '.self::typeLabel($type).' request',
            'message' => $this->requesterName($staffId).' requested a new '.self::typeLabel($type)
                .' item "'.ucfirst($name).'" that needs admin approval.',
            'action_url' => 'admin/inventory_requests?view=request&id='.$requestId,
        ]);

        return ['ok' => true, 'errors' => [], 'request_id' => $requestId];
    }

    /**
     * Approve or reject one pending request.
     *
     * @param  object  $row  the inventory_requests row (already re-read by id)
     * @param  string  $status  'approved' | 'rejected'
     * @return array{ok: bool, errors: array<int, string>, record_id: int}
     */
    public function decide(object $row, string $status, string $note, int $adminId): array
    {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            return ['ok' => false, 'errors' => ['Invalid review action.'], 'record_id' => 0];
        }
        if ((string) $row->status !== 'pending') {
            return ['ok' => false, 'errors' => ['This request has already been '.self::statusLabel((string) $row->status).'.'], 'record_id' => 0];
        }

        $note = trim($note);
        if ($status === 'rejected' && $note === '') {
            return ['ok' => false, 'errors' => ['A rejection reason is required.'], 'record_id' => 0];
        }

        $type = (string) $row->request_type;
        $name = (string) $row->name;
        $data = json_decode((string) $row->payload, true) ?: [];

        try {
            // One transaction covers the compare-and-set AND the record
            // creation, so a failure can never leave a half-approved request
            // or an orphaned inventory record.
            $recordId = (int) DB::transaction(function () use ($row, $status, $note, $adminId, $type, $name, $data) {
                // Compare-and-set on the status: a second approval of the
                // same request affects zero rows and creates nothing.
                $flipped = DB::table('inventory_requests')
                    ->where('id', (int) $row->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => $status,
                        'reviewed_by' => $adminId,
                        'review_note' => $note !== '' ? $note : null,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                    ]);

                if ($flipped === 0) {
                    throw new \RuntimeException('already-decided');
                }

                if ($status === 'rejected') {
                    return 0;
                }

                $created = $this->createRecord($type, $name, $data, $adminId);
                if (! $created['ok']) {
                    throw new \RuntimeException(implode(' ', $created['errors']));
                }

                DB::table('inventory_requests')->where('id', (int) $row->id)->update([
                    'record_id' => $created['record_id'],
                ]);

                return $created['record_id'];
            });
        } catch (\Throwable $ex) {
            $message = $ex instanceof \RuntimeException && $ex->getMessage() === 'already-decided'
                ? 'This request has already been reviewed.'
                : 'The request could not be approved. '.trim($ex->getMessage());

            return ['ok' => false, 'errors' => [$message], 'record_id' => 0];
        }

        $this->notifyDecision((int) $row->requester_id, $type, $name, $status, $note, (int) $row->id, $adminId, $recordId);

        return ['ok' => true, 'errors' => [], 'record_id' => $recordId];
    }

    /**
     * Create the active record an approval implies - the ONLY place an
     * approved request turns into real inventory.
     *
     * @return array{ok: bool, errors: array<int, string>, record_id: int}
     */
    private function createRecord(string $type, string $name, array $data, int $adminId): array
    {
        if ($type === 'medicine' || $type === 'first_aid') {
            $saved = $this->medicineService->save(
                0,
                $name,
                (string) ($data['generic_name'] ?? ''),
                isset($data['category_id']) && $data['category_id'] ? (int) $data['category_id'] : null,
                (string) ($data['unit'] ?? ''),
                (string) ($data['description'] ?? ''),
                (int) ($data['low_stock_threshold'] ?? 10),
                1,
                $adminId,
            );
            if (! $saved['success']) {
                return ['ok' => false, 'errors' => $saved['errors'], 'record_id' => 0];
            }

            $medicineId = (int) DB::table('medicines')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->orderByDesc('id')
                ->value('id');

            // The initial stock goes through the normal receiving pipeline -
            // batch merge, stock_movements IN row and audit row included -
            // so the very first quantity is recorded exactly once, in the
            // same transaction as the approval.
            $received = $this->stockService->receive(
                mid: $medicineId,
                batchNo: (string) ($data['batch_no'] ?? ''),
                lotNo: (string) ($data['lot_no'] ?? ''),
                supId: isset($data['supplier_id']) && $data['supplier_id'] ? (int) $data['supplier_id'] : null,
                qty: (int) ($data['initial_quantity'] ?? 0),
                expiry: $type === 'medicine' ? ($data['expiry_date'] ?? null) : null,
                received: (string) ($data['date_received'] ?? date('Y-m-d')),
                cost: isset($data['unit_cost']) && $data['unit_cost'] !== null ? (float) $data['unit_cost'] : null,
                staffId: $adminId,
            );

            if (! $received['success']) {
                return ['ok' => false, 'errors' => $received['errors'], 'record_id' => 0];
            }

            return ['ok' => true, 'errors' => [], 'record_id' => $medicineId];
        }

        if ($type === 'equipment') {
            $result = $this->equipmentStockService->receive(
                equipmentId: 0,
                newName: $name,
                newDescription: (string) ($data['description'] ?? ''),
                qty: (int) ($data['quantity'] ?? 0),
                supId: isset($data['supplier_id']) && $data['supplier_id'] ? (int) $data['supplier_id'] : null,
                unitCost: isset($data['unit_cost']) && $data['unit_cost'] !== null ? (float) $data['unit_cost'] : null,
                received: (string) ($data['date_received'] ?? date('Y-m-d')),
                condition: (string) ($data['condition'] ?? 'good'),
                assetId: (string) ($data['asset_id'] ?? ''),
                staffId: $adminId,
            );

            return [
                'ok' => $result['success'],
                'errors' => $result['errors'],
                'record_id' => (int) $result['equipment_id'],
            ];
        }

        if ($type === 'category') {
            $saved = $this->categoryService->save(
                $name,
                (string) ($data['description'] ?? ''),
                (string) ($data['category_type'] ?? 'medicine'),
                0,
                $adminId,
            );
            if (! $saved['success']) {
                return ['ok' => false, 'errors' => $saved['errors'], 'record_id' => 0];
            }

            $categoryId = (int) DB::table('medicine_categories')
                ->where('name', $name)
                ->where('type', (string) ($data['category_type'] ?? 'medicine'))
                ->orderByDesc('id')
                ->value('id');

            return ['ok' => true, 'errors' => [], 'record_id' => $categoryId];
        }

        if ($type === 'supplier') {
            $saved = $this->supplierService->save(
                0,
                $name,
                (string) ($data['contact_person'] ?? ''),
                (string) ($data['phone'] ?? ''),
                (string) ($data['email'] ?? ''),
                (string) ($data['address'] ?? ''),
                $adminId,
            );
            if (! $saved['success']) {
                return ['ok' => false, 'errors' => $saved['errors'], 'record_id' => 0];
            }

            $supplierId = (int) DB::table('suppliers')
                ->where('name', $name)
                ->orderByDesc('id')
                ->value('id');

            return ['ok' => true, 'errors' => [], 'record_id' => $supplierId];
        }

        return ['ok' => false, 'errors' => ['Unknown request type.'], 'record_id' => 0];
    }

    /** Tell the requester how their request ended (never throws). */
    private function notifyDecision(int $requesterId, string $type, string $name, string $status, string $note, int $requestId, int $adminId, int $recordId): void
    {
        $label = self::typeLabel($type);

        if ($status === 'approved') {
            $extra = match ($type) {
                'medicine', 'first_aid' => ' It is now stocked and appears on the Inventory page.',
                'equipment' => ' It is now listed on the Equipment tab and available to borrow.',
                'category' => ' It can now be used when stocking items.',
                'supplier' => ' It can now be selected when receiving stock.',
                default => '',
            };

            Notify::to($requesterId, 'inventory_request_decided', [
                'reference_id' => $requestId,
                'severity' => 'success',
                'icon' => 'bi-check2-circle',
                'title' => $label.' request approved',
                'message' => 'Your request for "'.ucfirst($name).'" was approved by '.$this->requesterName($adminId).'.'.$extra,
                'action_url' => 'admin/inventory_requests?view=request&id='.$requestId,
            ]);

            return;
        }

        Notify::to($requesterId, 'inventory_request_decided', [
            'reference_id' => $requestId,
            'severity' => 'danger',
            'icon' => 'bi-x-circle',
            'title' => $label.' request declined',
            'message' => 'Your request for "'.ucfirst($name).'" was declined by '.$this->requesterName($adminId).'. Reason: '.$note,
            'action_url' => 'admin/inventory_requests?view=request&id='.$requestId,
        ]);
    }

    /** Display name for a user id, falling back to the raw id. */
    public function requesterName(int $userId): string
    {
        $row = DB::selectOne(
            'SELECT u.username, p.first_name, p.last_name
               FROM users u
               LEFT JOIN profiles p ON p.user_id = u.id
              WHERE u.id = ? LIMIT 1',
            [$userId],
        );

        if (! $row) {
            return 'User #'.$userId;
        }

        $full = trim(((string) ($row->first_name ?? '')).' '.((string) ($row->last_name ?? '')));

        return $full !== '' ? $full : (string) $row->username;
    }

    /**
     * Strict Y-m-d check - the request field is `<input type="date">`, and
     * strtotime()/createFromFormat() alone both accept rollovers such as
     * 2027-02-30, so the round-trip comparison rejects those too.
     */
    private function isDate(string $value): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /**
     * Informational restock notice - one per receipt, never blocking.
     *
     * @param  array<string, mixed>  $receipt  a stock_receipts row joined to
     *                                         the item and supplier names
     */
    public function notifyRestock(array $receipt): void
    {
        $type = (string) ($receipt['item_type'] ?? '');
        $label = $type === 'equipment' ? 'Equipment' : self::typeLabel($type);
        $qty = (int) ($receipt['quantity'] ?? 0);
        $supplier = trim((string) ($receipt['supplier_name'] ?? ''));
        $when = (string) ($receipt['created_at'] ?? '');

        Notify::toRoles(['admin'], 'inventory_restocked', [
            'reference_id' => (int) ($receipt['id'] ?? 0),
            'severity' => 'info',
            'icon' => 'bi-box-arrow-in-down',
            'title' => $label.' restocked',
            'message' => (string) ($receipt['staff_name'] ?? 'Staff').' added '.$qty.' unit'.($qty === 1 ? '' : 's')
                .' of "'.ucfirst((string) ($receipt['item_name'] ?? '')).'"'
                .($supplier !== '' ? ' from '.$supplier : '')
                .' on '.($when !== '' ? date('M d, Y g:i A', strtotime($when)) : 'the stock-in').'.',
            'action_url' => 'staff/history?tab=inventory',
        ]);
    }
}
