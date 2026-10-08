<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Database backup export - extracted verbatim from
 * App\Http\Controllers\Admin\AdminBackupController::index()
 * (Phase 5, Group B).
 *
 * The controller keeps HTTP concerns: reading tables from the request, the
 * backup-directory resolution + @mkdir (the view displays the path), the
 * write-only session flash, the $success/$errors view state and rendering.
 * This service owns the export:
 *
 *  - ALLOWED_TABLES: the reference's whitelist (original lines 14-22,
 *    original order) - the POST validation intersects against it here and
 *    the view reads it from here;
 *
 *  - backup(): array_intersect() against the whitelist FIRST (a scalar
 *    tables= value still raises PHP 8's TypeError exactly like the
 *    reference - the parameter is deliberately mixed), then the original's
 *    three-branch chain: empty selection / unwritable directory / fopen
 *    failure each append their original error string, otherwise the
 *    timestamp + random-suffix filename, the header + per-table INSERT
 *    dump (PDO::FETCH_ASSOC, NULL handling, empty-table skip) + footer,
 *    and the 'backup' audit row. The session flash and $success flag stay
 *    with the controller (response concerns).
 *
 * Error strings, SQL/file text, audit payload and branch order are
 * byte-identical to the controller's original; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class BackupService
{
    /**
     * Tables that are safe to back up (original lines 14-22, original order).
     */
    public const ALLOWED_TABLES = [
        'medicine_categories', 'suppliers', 'medicines', 'medicine_batches',
        'stock_movements', 'stock_adjustments', 'medicine_requests',
        'dispense_logs', 'consultations', 'equipment', 'equipment_loans', 'notifications',
        'medical_certificates', 'audit_logs', 'system_settings',
        'users', 'profiles', 'student_details', 'employee_details',
        'medical_records', 'user_allergies',
    ];

    /**
     * Run the whitelisted export.
     *
     * @param  mixed  $selected  raw ?tables value (array expected; a scalar
     *                           hits array_intersect()'s TypeError like the
     *                           reference)
     * @param  string  $backupDir  resolved backup directory (controller)
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, filename: string|null, errors: array<int, string>}
     */
    public function backup(mixed $selected, string $backupDir, int $actor): array
    {
        $errors = [];
        $success = false;
        $filename = null;

        $selected = array_intersect($selected, self::ALLOWED_TABLES);

        if (empty($selected)) {
            $errors[] = 'Please select at least one table to back up.';
        } elseif (! is_dir($backupDir) || ! is_writable($backupDir)) {
            $errors[] = 'Backup directory is not writable. Create /school-clinic/backups manually.';
        } else {
            // Original lines 39-40: timestamp + random suffix filename.
            $filename = 'backup_'.date('Y-m-d_H-i-s').'_'.substr(bin2hex(random_bytes(4)), 0, 8).'.sql';
            $filepath = $backupDir.'/'.$filename;

            $handle = fopen($filepath, 'w');
            if (! $handle) {
                $errors[] = 'Could not open backup file for writing.';
            } else {
                // Original lines 46-67: header, per-table dump, footer.
                fwrite($handle, "-- School Clinic backup\n");
                fwrite($handle, '-- Generated: '.date('Y-m-d H:i:s')."\n");
                fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");

                $pdo = DB::getPdo();
                foreach ($selected as $table) {
                    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                    fwrite($handle, "-- Table: {$table} (".count($rows)." rows)\n");
                    if (empty($rows)) {
                        continue;
                    }
                    $cols = array_keys($rows[0]);
                    $colList = '`'.implode('`,`', $cols).'`';
                    foreach ($rows as $row) {
                        $values = [];
                        foreach (array_values($row) as $v) {
                            $values[] = $v === null ? 'NULL' : $pdo->quote($v);
                        }
                        fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES (".implode(',', $values).");\n");
                    }
                }

                fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
                fclose($handle);

                // Original lines 70-73: audit (the write-only session flash
                // and the $success flag stay with the controller).
                AuditLogger::log(
                    $actor,
                    'backup',
                    null,
                    null,
                    'Backed up tables: '.implode(', ', $selected)." -> {$filename}"
                );
                $success = true;
            }
        }

        return ['success' => $success, 'filename' => $filename, 'errors' => $errors];
    }
}
