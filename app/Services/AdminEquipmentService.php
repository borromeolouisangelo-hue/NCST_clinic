<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin equipment mutations - extracted verbatim from
 * App\Http\Controllers\Admin\EquipmentController::index()
 * (Phase 5, Group A).
 *
 * The controller keeps HTTP concerns: the POST field reads (trim, the
 * condition_note presence-keyed default, the is_active flag), the GET
 * delete/edit action reads, the edit/list queries, the redirects and view
 * rendering. This service owns the mutations:
 *
 *  - save(): the two validations (name required, total qty >= 0), then
 *    the update-or-create branch. The update first reads total_qty and
 *    available_qty: a missing row appends "Equipment record not found."
 *    WITHOUT writing (and without reaching the create branch - original
 *    structure kept verbatim, including the borrowed/newAvail
 *    recomputation that still runs before the guarded UPDATE); a present
 *    row updates with available_qty = max(0, total - borrowed) where
 *    borrowed = old total - old available. The create branch sets
 *    available_qty = total. Each branch audits. No transaction exists on
 *    this page in the reference - none is added.
 *
 *  - delete(): the GET ?action=delete delete + its audit row - NO
 *    existence check on purpose: a zero-row DELETE still audits and the
 *    controller flashes success; the controller keeps the redirect.
 *
 * Error strings, SQL text, audit payloads and call order are
 * byte-identical to the controller's originals; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class AdminEquipmentService
{
    /**
     * Run the save pipeline: validations -> update (row re-read +
     * availability recompute) or create + audit.
     *
     * @param  int  $eid  0 = create, > 0 = update target
     * @param  string  $name  trimmed name
     * @param  string  $desc  trimmed description
     * @param  int  $total  total quantity
     * @param  string  $cond  condition note (presence-defaulted by the controller)
     * @param  int  $active  0|1 (is_active presence flag)
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, created: bool, errors: array<int, string>}
     */
    public function save(int $eid, string $name, string $desc, int $total, string $cond, int $active, int $actor): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Equipment name is required.';
        }
        if ($total < 0) {
            $errors[] = 'Total quantity cannot be negative.';
        }

        if (! $errors) {
            if ($eid > 0) {
                $row = DB::selectOne(
                    'SELECT total_qty, available_qty FROM equipment WHERE id=?',
                    [$eid],
                );
                if (! $row) {
                    $errors[] = 'Equipment record not found.';
                }
                $borrowed = $row ? max(0, (int) $row->total_qty - (int) $row->available_qty) : 0;
                $newAvail = max(0, $total - $borrowed);

                if (! $errors) {
                    DB::update(
                        'UPDATE equipment SET name=?, description=?, total_qty=?, available_qty=?, condition_note=?, is_active=? WHERE id=?',
                        [$name, $desc, $total, $newAvail, $cond, $active, $eid],
                    );
                    AuditLogger::log(
                        $actor,
                        'update_equipment',
                        'equipment',
                        $eid,
                        "Updated equipment $name",
                    );

                    return ['success' => true, 'created' => false, 'errors' => []];
                }
            } else {
                $eid = (int) DB::table('equipment')->insertGetId([
                    'name' => $name,
                    'description' => $desc,
                    'total_qty' => $total,
                    'available_qty' => $total,
                    'condition_note' => $cond,
                    'is_active' => $active,
                ]);
                AuditLogger::log(
                    $actor,
                    'create_equipment',
                    'equipment',
                    $eid,
                    "Created equipment $name",
                );

                return ['success' => true, 'created' => true, 'errors' => []];
            }
        }

        return ['success' => false, 'created' => false, 'errors' => $errors];
    }

    /**
     * GET ?action=delete: no existence check (zero-row DELETE audits
     * anyway), audit + PRG in the controller.
     */
    public function delete(int $eid, int $actor): void
    {
        DB::delete('DELETE FROM equipment WHERE id=?', [$eid]);
        AuditLogger::log(
            $actor,
            'delete_equipment',
            'equipment',
            $eid,
            "Deleted equipment #$eid",
        );
    }
}
