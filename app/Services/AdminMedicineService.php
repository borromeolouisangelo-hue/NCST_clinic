<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin medicine-catalog mutations - extracted verbatim from
 * App\Http\Controllers\Admin\MedicineController::index()
 * (Phase 5, Group A).
 *
 * The controller keeps HTTP concerns: the POST field reads (trim, the
 * category_id int-or-null collapse, the is_active presence flag), the GET
 * delete/edit action reads, the edit/category/list queries, the redirects
 * and view rendering. This service owns the mutations:
 *
 *  - save(): the two-rule validation order in the original order (name
 *    required, then the unit-empty -> 'tablet' normalization which is a
 *    VALUE DEFAULT, not an error, then the negative-threshold check), the
 *    LOWER() case-insensitive duplicate check (id <> ? excludes the row
 *    being edited), then the update-or-create branch with its audit row.
 *    No transaction exists on this page in the reference - none is added.
 *
 *  - delete(): the GET ?action=delete delete + its audit row (a plain
 *    GET, no existence check); the controller keeps the redirect.
 *
 * Error strings, SQL text, audit payloads and call order are
 * byte-identical to the controller's originals; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class AdminMedicineService
{
    /**
     * Run the save pipeline: validations (incl. the unit default) ->
     * LOWER() duplicate check -> update or create + audit.
     *
     * @param  int  $mid  0 = create, > 0 = update target
     * @param  string  $name  trimmed name
     * @param  string  $generic  trimmed generic name
     * @param  int|null  $catId  category id or null
     * @param  string  $unit  trimmed unit ('' becomes 'tablet' inside)
     * @param  string  $desc  trimmed description
     * @param  int  $thresh  low stock threshold
     * @param  int  $active  0|1 (is_active presence flag)
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, created: bool, errors: array<int, string>}
     */
    public function save(int $mid, string $name, string $generic, ?int $catId, string $unit, string $desc, int $thresh, int $active, int $actor): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Medicine name is required.';
        }
        if ($unit === '') {
            $unit = 'tablet';
        }
        if ($thresh < 0) {
            $errors[] = 'Low stock threshold cannot be negative.';
        }

        if (! $errors) {
            $dup = (int) DB::selectOne(
                'SELECT COUNT(*) AS c FROM medicines WHERE LOWER(name) = LOWER(?) AND id <> ?',
                [$name, $mid],
            )->c;

            if ($dup > 0) {
                $errors[] = 'A medicine with this name already exists.';
            }
        }

        if (! $errors) {
            if ($mid > 0) {
                DB::table('medicines')->where('id', $mid)->update([
                    'name' => $name,
                    'generic_name' => $generic,
                    'category_id' => $catId,
                    'unit' => $unit,
                    'description' => $desc,
                    'low_stock_threshold' => $thresh,
                    'is_active' => $active,
                ]);

                AuditLogger::log(
                    $actor,
                    'update_medicine',
                    'medicines',
                    $mid,
                    "Updated medicine $name",
                );

                return ['success' => true, 'created' => false, 'errors' => []];
            }

            $cid = DB::table('medicines')->insertGetId([
                'name' => $name,
                'generic_name' => $generic,
                'category_id' => $catId,
                'unit' => $unit,
                'description' => $desc,
                'low_stock_threshold' => $thresh,
                'is_active' => $active,
            ]);

            AuditLogger::log(
                $actor,
                'create_medicine',
                'medicines',
                $cid,
                "Created medicine $name",
            );

            return ['success' => true, 'created' => true, 'errors' => []];
        }

        return ['success' => false, 'created' => false, 'errors' => $errors];
    }

    /**
     * GET ?action=delete: no existence check, audit + PRG in the
     * controller.
     */
    public function delete(int $mid, int $actor): void
    {
        DB::table('medicines')->where('id', $mid)->delete();

        AuditLogger::log(
            $actor,
            'delete_medicine',
            'medicines',
            $mid,
            "Deleted medicine #$mid",
        );
    }
}
