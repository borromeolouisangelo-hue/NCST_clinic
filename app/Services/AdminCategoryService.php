<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin medicine-category mutations - extracted verbatim from
 * App\Http\Controllers\Admin\AdminCategoriesController::index()
 * (Phase 5, Group A).
 *
 * The controller keeps HTTP concerns: the ?action/?id/?q reads, the POST
 * field reads (trim + type whitelist), the edit-row load, the list query,
 * the redirects and view rendering. This service owns the mutations:
 *
 *  - save(): the name-required check, the per-type duplicate COUNT
 *    (id <> ? excludes the row being edited), then the update-or-create
 *    branch with its audit row; the create branch also fires the
 *    feature-2 Notify to every admin (fire_event('category.created') had
 *    no listener in the reference). No transaction exists on this page in
 *    the reference - none is added.
 *
 *  - delete(): the legacy ?action=delete delete + its audit row (PRG, no
 *    existence check - a zero-row DELETE still audits and flashes
 *    success); the controller keeps the redirect.
 *
 * Error strings, SQL text, audit/Notify payloads and call order are
 * byte-identical to the controller's originals; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class AdminCategoryService
{
    /**
     * Run the save pipeline: validation -> per-type duplicate check ->
     * update or create + audit (+ Notify on create).
     *
     * @param  string  $name  trimmed name
     * @param  string  $desc  trimmed description
     * @param  string  $type  whitelisted type (medicine|first_aid)
     * @param  int  $cid  0 = create, > 0 = update target
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, created: bool, errors: array<int, string>}
     */
    public function save(string $name, string $desc, string $type, int $cid, int $actor): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }

        if (! $errors) {
            $dup = (int) DB::scalar(
                'SELECT COUNT(*) FROM medicine_categories WHERE name = ? AND type = ? AND id <> ?',
                [$name, $type, $cid]
            );
            if ($dup > 0) {
                $errors[] = 'Category name already exists in this type.';
            }
        }

        if (! $errors) {
            if ($cid > 0) {
                DB::table('medicine_categories')->where('id', $cid)->update([
                    'name' => $name,
                    'description' => $desc,
                    'type' => $type,
                ]);
                AuditLogger::log(
                    $actor,
                    'update_category',
                    'medicine_categories',
                    $cid,
                    "Updated category {$name}"
                );

                return ['success' => true, 'created' => false, 'errors' => []];
            }

            $cid = (int) DB::table('medicine_categories')->insertGetId([
                'name' => $name,
                'description' => $desc,
                'type' => $type,
            ]);
            AuditLogger::log(
                $actor,
                'create_category',
                'medicine_categories',
                $cid,
                "Created category {$name}"
            );
            // fire_event('category.created', ...) had no listener in the
            // reference; feature 2 announces it to every admin account.
            Notify::toRoles(['admin'], 'category_created', [
                'reference_id' => $cid,
                'severity' => 'info',
                'title' => 'New Medicine Category',
                'message' => "Category '{$name}' was created.",
                'action_url' => 'admin/categories',
            ]);

            return ['success' => true, 'created' => true, 'errors' => []];
        }

        return ['success' => false, 'created' => false, 'errors' => $errors];
    }

    /**
     * Legacy ?action=delete: no existence check, audit + PRG in the
     * controller.
     */
    public function delete(int $id, int $actor): void
    {
        DB::table('medicine_categories')->where('id', $id)->delete();
        AuditLogger::log(
            $actor,
            'delete_category',
            'medicine_categories',
            $id,
            "Deleted category #{$id}"
        );
    }
}
