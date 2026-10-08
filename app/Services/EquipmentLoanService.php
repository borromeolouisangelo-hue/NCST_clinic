<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Staff equipment loan mutations - extracted verbatim from
 * App\Http\Controllers\Staff\EquipmentLoansController::index()
 * (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the issue-form data reads (patients,
 * equipment, loan-limit setting), the ?status/?q/?page parsing and the
 * (dead) $where builder, the StaffHistory pagination, the redirect/flash
 * and view rendering. This service owns the mutation side:
 *
 *  - issue(): the borrow pipeline - three request validations (borrower
 *    selected, equipment selected, the single-item rule), the availability
 *    re-check ("Equipment not found." / "Not enough available units."),
 *    then the original DB::transaction holding the decrement, the loan
 *    insert (24h due) and the overdue-notification sweep call, in the
 *    original order with byte-identical error strings. Success returns a
 *    flag; the controller builds the redirect.
 *
 *  - overdueAlertCount(): the status-filter block - when the filter is
 *    'overdue' or 'all', the overdue COUNT and, when positive, the
 *    notification sweep (count-only=false); returns the count the view
 *    renders. Both queries keep their original shapes and positions.
 *
 *  - createOverdueLoanNotifications(): the legacy sweep, moved verbatim
 *    from the controller (idempotent 'OverdueLoanNotification' rows, dedup
 *    on data->>value LIKE, 'read' column, optional equipment scope,
 *    count-only mode). It is deliberately NOT merged with
 *    OverdueLoanNotifications::create() (Feature 2's 'overdue_loan' rows
 *    keyed on loan_id with is_read/title/message): the two write different
 *    row shapes for different consumers, so combining them would change
 *    persisted data. The consolidation here is structural - every
 *    equipment-loan mutation path now lives in this one service.
 */
class EquipmentLoanService
{
    /**
     * Run the borrow (issue) pipeline: validations -> availability check ->
     * transaction (decrement + loan insert + overdue sweep).
     *
     * @return array{success: bool, errors: array<int, string>}
     */
    public function issue(int $borrowerId, int $equipmentId, int $quantity): array
    {
        $errors = [];

        if ($borrowerId <= 0) {
            $errors[] = 'Please select a borrower.';
        }
        if ($equipmentId <= 0) {
            $errors[] = 'Please select equipment.';
        }
        if ($quantity !== 1) {
            $errors[] = 'Equipment requests are limited to 1 item per request.';
        }

        if (! $errors) {
            $eq = DB::table('equipment')->where('id', $equipmentId)->limit(1)->first();
            if (! $eq) {
                $errors[] = 'Equipment not found.';
            } elseif ((int) ($eq->available_qty ?? 0) < $quantity) {
                $errors[] = 'Not enough available units.';
            }
        }

        if (! $errors) {
            DB::transaction(function () use ($borrowerId, $equipmentId, $quantity, &$errors) {
                $now = now();
                $due = date('Y-m-d H:i:s', strtotime($now.' + 24 hours'));

                DB::table('equipment')
                    ->where('id', $equipmentId)
                    ->update(['available_qty' => DB::raw('available_qty - '.$quantity)]);

                DB::table('equipment_loans')->insert([
                    'user_id' => $borrowerId,
                    'equipment_id' => $equipmentId,
                    'quantity' => $quantity,
                    'borrow_date' => $now,
                    'due_date' => $due,
                    'status' => 'borrowed',
                ]);

                // Feature 18: Post-migration - create overdue notifications
                // after 24h timeout
                $this->createOverdueLoanNotifications($equipmentId);
            });

            return ['success' => true, 'errors' => []];
        }

        return ['success' => false, 'errors' => $errors];
    }

    /**
     * The status-filter overdue block: count overdue loans and sweep their
     * notifications when the filter shows them (and the count is positive).
     *
     * @param  string|array  $statusFilter  raw ?status= value (a non-string
     *                                      never matches the two literals,
     *                                      exactly like the original === checks)
     * @return int the count rendered as overdueCount
     */
    public function overdueAlertCount(string|array $statusFilter): int
    {
        $overdueCount = 0;
        if ($statusFilter === 'overdue' || $statusFilter === 'all') {
            $overdueCount = DB::table('equipment_loans')
                ->where('status', 'borrowed')
                ->where('borrowed_at', '<=', DB::raw('SUBDATE(NOW(), 1)'))
                ->count();
            if ($overdueCount > 0) {
                $this->createOverdueLoanNotifications(
                    null, // no specific equipment id needed
                    false // don't double-count
                );
            }
        }

        return $overdueCount;
    }

    /**
     * Create overdue loan notifications (idempotent).
     */
    private function createOverdueLoanNotifications($equipmentId = null, $countOnly = true)
    {
        $query = DB::table('equipment_loans')
            ->where('status', 'borrowed')
            ->where('borrowed_at', '<=', DB::raw('SUBDATE(NOW(), 1)'));

        if ($equipmentId) {
            $query->where('equipment_id', $equipmentId);
        }

        $overdueLoans = $query->get();

        if ($countOnly) {
            return count($overdueLoans);
        }

        foreach ($overdueLoans as $loan) {
            // Check if notification already exists (idempotent)
            $existing = DB::table('notifications')
                ->where('user_id', $loan->user_id)
                ->where('type', 'OverdueLoanNotification')
                ->where('data', '->>value', 'like', "%{$loan->id}%")
                ->exists();

            if (! $existing) {
                DB::table('notifications')->insert([
                    'user_id' => $loan->user_id,
                    'type' => 'OverdueLoanNotification',
                    'data' => json_encode(['loan_id' => $loan->id]),
                    'read' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return count($overdueLoans);
    }
}
