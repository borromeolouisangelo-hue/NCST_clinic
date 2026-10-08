<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin supplier mutations - extracted verbatim from
 * App\Http\Controllers\Admin\AdminSuppliersController::index()
 * (Phase 5, Group A).
 *
 * The controller keeps HTTP concerns: the ?action/?id/?q reads, the POST
 * field reads, the edit-row load, the list query, the redirects and view
 * rendering. This service owns the mutations:
 *
 *  - save(): the name-required check ONLY - the reference has NO
 *    duplicate-supplier check (unlike categories) and no other
 *    validation, so two suppliers may share a name - then the
 *    update-or-create branch with its audit row. No transaction exists
 *    on this page in the reference - none is added.
 *
 *  - delete(): the legacy ?action=delete delete + its audit row (PRG, no
 *    existence check); the controller keeps the redirect.
 *
 * Error strings, SQL text, audit payloads and call order are
 * byte-identical to the controller's originals; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class AdminSupplierService
{
    /**
     * Run the save pipeline: single name validation -> update or create
     * + audit.
     *
     * @param  int  $sid  0 = create, > 0 = update target
     * @param  string  $name  trimmed name
     * @param  string  $person  trimmed contact person
     * @param  string  $phone  trimmed phone
     * @param  string  $email  trimmed email
     * @param  string  $address  trimmed address
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, created: bool, errors: array<int, string>}
     */
    public function save(int $sid, string $name, string $person, string $phone, string $email, string $address, int $actor): array
    {
        $errors = [];

        if ($name === '') {
            $errors[] = 'Supplier name is required.';
        }

        if (! $errors) {
            if ($sid > 0) {
                DB::table('suppliers')->where('id', $sid)->update([
                    'name' => $name,
                    'contact_person' => $person,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                ]);
                AuditLogger::log(
                    $actor,
                    'update_supplier',
                    'suppliers',
                    $sid,
                    "Updated supplier {$name}"
                );

                return ['success' => true, 'created' => false, 'errors' => []];
            }

            $sid = (int) DB::table('suppliers')->insertGetId([
                'name' => $name,
                'contact_person' => $person,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
            ]);
            AuditLogger::log(
                $actor,
                'create_supplier',
                'suppliers',
                $sid,
                "Created supplier {$name}"
            );

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
        DB::table('suppliers')->where('id', $id)->delete();
        AuditLogger::log(
            $actor,
            'delete_supplier',
            'suppliers',
            $id,
            "Deleted supplier #{$id}"
        );
    }
}
