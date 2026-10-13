<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Equipment loan mutations for staff/equipment_loans.php - the controller
 * keeps request parsing, pagination and rendering; error strings stay
 * byte-identical to the reference.
 */
class EquipmentLoanService
{
    // #region Issue / return pipeline

    // Issue one equipment loan: validate, re-check stock, decrement + insert atomically
    // <- POST do=issue (borrower, equipment, qty, notes) + issuer id | -> loan row, available_qty -1 | => audit_logs issue_loan
    public function issue(int $borrowerId, int $equipmentId, int $quantity, string $notes = '', ?int $staffId = null): array
    {
        $errors = [];

        if ($borrowerId <= 0) {
            $errors[] = 'Select a borrower.';
        }
        if ($equipmentId <= 0) {
            $errors[] = 'Select equipment.';
        }
        if ($quantity !== 1) {
            $errors[] = 'Equipment requests are limited to 1 item per request.';
        }

        if (! $errors) {
            $eq = DB::table('equipment')->where('id', $equipmentId)->where('is_active', 1)->limit(1)->first();
            if (! $eq) {
                $errors[] = 'Selected equipment is unavailable.';
            } elseif ((int) ($eq->available_qty ?? 0) < $quantity) {
                $errors[] = 'Not enough available units.';
            } elseif ($quantity > (int) ($eq->total_qty ?? 0)) {
                $errors[] = 'Requested quantity cannot exceed total stock.';
            }
        }

        if (! $errors) {
            try {
                $loanId = (int) DB::transaction(function () use ($borrowerId, $equipmentId, $quantity, $notes, $staffId) {
                    $now = now();
                    $due = date('Y-m-d', strtotime($now.' + 24 hours'));

                    $updated = DB::table('equipment')
                        ->where('id', $equipmentId)
                        ->where('available_qty', '>=', $quantity)
                        ->where('is_active', 1)
                        ->update(['available_qty' => DB::raw('available_qty - '.$quantity)]);

                    if ($updated !== 1) {
                        throw new \RuntimeException('Not enough available units.');
                    }

                    return (int) DB::table('equipment_loans')->insertGetId([
                        'user_id' => $borrowerId,
                        'equipment_id' => $equipmentId,
                        'quantity' => $quantity,
                        'borrow_date' => date('Y-m-d'),
                        'borrowed_at' => $now,
                        'due_date' => $due,
                        'status' => 'borrowed',
                        'notes' => $notes,
                        'issued_by' => $staffId,
                    ]);
                });
            } catch (\RuntimeException $ex) {
                if ($ex->getMessage() !== 'Not enough available units.') {
                    throw $ex;
                }

                return ['success' => false, 'errors' => ['Not enough available units.']];
            }

            AuditLogger::log(
                $staffId,
                'issue_loan',
                'equipment_loans',
                $loanId,
                "Issued equipment #{$equipmentId} to user #{$borrowerId}",
            );

            return ['success' => true, 'errors' => []];
        }

        return ['success' => false, 'errors' => $errors];
    }

    // Return a borrowed unit: restore stock and close the loan, only once
    // <- POST do=return + receiver id | -> status=returned, available_qty +qty | => audit_logs return_loan
    public function returnLoan(int $loanId, int $staffId): array
    {
        $loan = DB::table('equipment_loans')->where('id', $loanId)->limit(1)->first();

        if ($loan && $loan->status === 'borrowed') {
            DB::transaction(function () use ($loan, $staffId) {
                DB::table('equipment')
                    ->where('id', $loan->equipment_id)
                    ->update(['available_qty' => DB::raw('available_qty + '.(int) $loan->quantity)]);

                DB::table('equipment_loans')->where('id', $loan->id)->update([
                    'status' => 'returned',
                    'return_date' => date('Y-m-d'),
                    'received_by' => $staffId,
                ]);
            });

            AuditLogger::log(
                $staffId,
                'return_loan',
                'equipment_loans',
                (int) $loan->id,
                "Returned loan #{$loan->id}",
            );

            return ['status' => 'success', 'message' => 'Equipment returned.'];
        }

        return ['status' => 'danger', 'message' => 'Loan not found or already returned.'];
    }

    // #endregion

    // #region Overdue badge

    // Overdue loan count for the status filter (0 unless overdue/all); notifications sweep elsewhere
    // <- raw ?status= value | -> int badge count
    public function overdueAlertCount(string|array $statusFilter): int
    {
        $overdueCount = 0;
        if ($statusFilter === 'overdue' || $statusFilter === 'all') {
            $overdueCount = DB::table('equipment_loans')
                ->where('status', 'borrowed')
                ->where('borrowed_at', '<=', DB::raw('SUBDATE(NOW(), 1)'))
                ->count();
        }

        return $overdueCount;
    }

    // #endregion
}
