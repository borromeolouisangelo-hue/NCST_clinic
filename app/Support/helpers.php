<?php

use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;

/**
 * View helpers ported verbatim from includes/functions.php (read-only
 * reference) so Blade views can call them by their original names.
 *
 * Registered through composer.json's `autoload.files`.
 */
if (! function_exists('fmt_dt')) {
    /**
     * Format a datetime - used throughout views for consistent date display.
     * Returns '' for falsy input and the original string when strtotime()
     * cannot parse it (same fallbacks as the original helper).
     */
    function fmt_dt($datetime, $format = 'Y-m-d H:i:s')
    {
        if (! $datetime) {
            return '';
        }

        $ts = strtotime($datetime);

        if ($ts === false) {
            return $datetime;
        }

        // The port's format map was an identity ($defaultFormats[$format] ??
        // $format always yielded $format), so it is folded into a direct
        // call - byte-identical output for every input (Phase 6, Step 2).
        return date($format, $ts);
    }
}

if (! function_exists('format_address')) {
    /** Join the address form parts with single spaces (original helper). */
    function format_address($parts)
    {
        return trim(implode(' ', $parts));
    }
}

if (! function_exists('age_from_birthdate')) {
    /**
     * Age (in years) from a birthdate; returns null when unknown or invalid.
     * Port of includes/functions.php:701 - used by the certificates history
     * table (staff dashboard preview, later the full staff history page).
     */
    function age_from_birthdate($birthdate)
    {
        if ($birthdate === null || $birthdate === '') {
            return null;
        }

        try {
            $born = new DateTime((string) $birthdate);
        } catch (Exception $ex) {
            return null;
        }

        $today = new DateTime('today');
        if ($born > $today) {
            return null;
        }

        return (int) $born->diff($today)->y;
    }
}

if (! function_exists('get_setting')) {
    /**
     * Look up one system_settings row by key, falling back to $default when
     * the row does not exist. Port of includes/functions.php:32 - the
     * original's first argument was the PDO handle (dropped here; the query
     * is identical against the same live schema), and a row whose value is
     * NULL still returns that NULL rather than the default, exactly like
     * `return $row ? $row['setting_value'] : $default`.
     */
    function get_setting($key, $default = '')
    {
        $row = SystemSetting::query()
            ->where('setting_key', $key)
            ->first();

        return $row ? $row->setting_value : $default;
    }
}

if (! function_exists('generate_cert_no')) {
    /**
     * Next certificate number: prefix + zero-padded MAX(existing)+1.
     * Port of includes/functions.php:720 - the original's first parameter
     * was the PDO handle (dropped here), and its only caller never passes
     * $type, so every certificate - including excuse slips and fit notes -
     * receives a MED number. Legacy behaviour kept on purpose.
     */
    function generate_cert_no($type = 'medical')
    {
        $prefix = $type === 'medical' ? 'MED' : 'FIT';
        $start = strlen($prefix) + 1;

        $max = (int) DB::select(
            "SELECT MAX(CAST(SUBSTRING(cert_no, {$start}) AS UNSIGNED)) AS next_no
             FROM medical_certificates
             WHERE cert_no LIKE ?",
            [$prefix.'%'],
        )[0]->next_no;

        return $prefix.str_pad($max + 1, 6, '0', STR_PAD_LEFT);
    }
}

if (! function_exists('get_setting_int')) {
    /** Setting as integer with default (original includes/functions.php:486). */
    function get_setting_int($key, $default = 0)
    {
        $val = get_setting($key, '');

        return $val !== '' ? (int) $val : $default;
    }
}

if (! function_exists('medicine_stock')) {
    /**
     * Total available stock across all batches (original functions.php:494):
     * positive, non-expired quantities only.
     */
    function medicine_stock($medicine_id)
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(quantity), 0) AS stock
               FROM medicine_batches
              WHERE medicine_id = ? AND quantity > 0
                AND (expiry_date IS NULL OR expiry_date >= CURDATE())',
            [$medicine_id],
        );

        return (int) $row->stock;
    }
}

if (! function_exists('check_allergy_conflict')) {
    /**
     * Allergy conflicts between a patient and a medicine - verbatim port of
     * includes/functions.php:513 (PDO $handle dropped per the port convention).
     * Returns the matching user_allergies rows as associative arrays (staff/
     * dispense.php prints allergen_name + severity from each); [] = no conflict.
     * Matching order: explicit medicine link, explicit category link, then
     * case-insensitive text matching against the medicine's name, generic name,
     * description and category name (both directions, plus whole-word tokens).
     */
    function check_allergy_conflict($user_id, $medicine_id)
    {
        $user_id = (int) $user_id;
        $medicine_id = (int) $medicine_id;
        if ($user_id <= 0 || $medicine_id <= 0) {
            return [];
        }

        $medicine = DB::selectOne(
            'SELECT m.id, m.name, m.generic_name, m.description, m.category_id, c.name AS category_name
               FROM medicines m
               LEFT JOIN medicine_categories c ON c.id = m.category_id
              WHERE m.id = ? LIMIT 1',
            [$medicine_id],
        );
        if (! $medicine) {
            return [];
        }
        $medicine = (array) $medicine;

        $rows = DB::select(
            'SELECT id, medicine_id, category_id, allergen_name, severity, notes
               FROM user_allergies
              WHERE user_id = ?',
            [$user_id],
        );
        if (! $rows) {
            return [];
        }
        $allergies = array_map(fn ($row) => (array) $row, $rows);

        // Everything the medicine is described by, lowercased once.
        $parts = [];
        foreach (['name', 'generic_name', 'description', 'category_name'] as $field) {
            $value = trim((string) ($medicine[$field] ?? ''));
            if ($value !== '') {
                $parts[] = mb_strtolower($value);
            }
        }
        $haystack = implode(' ', $parts);

        // Medicine-side keys tested against the allergy text (reverse direction).
        $medicineKeys = [];
        foreach (['name', 'generic_name'] as $field) {
            $value = mb_strtolower(trim((string) ($medicine[$field] ?? '')));
            if (mb_strlen($value) >= 4) {
                $medicineKeys[] = $value;
            }
        }

        $conflicts = [];
        foreach ($allergies as $allergy) {
            if ($allergy['medicine_id'] !== null && (int) $allergy['medicine_id'] === $medicine_id) {
                $conflicts[] = $allergy;

                continue;
            }
            if ($allergy['category_id'] !== null
                && $medicine['category_id'] !== null
                && (int) $allergy['category_id'] === (int) $medicine['category_id']) {
                $conflicts[] = $allergy;

                continue;
            }

            $allergen = mb_strtolower(trim((string) ($allergy['allergen_name'] ?? '')));
            if ($allergen === '') {
                continue;
            }

            // The allergen text appears in the medicine's details ...
            if (mb_strpos($haystack, $allergen) !== false) {
                $conflicts[] = $allergy;

                continue;
            }

            // ... or a significant allergen word appears in them ("peanut" in "Peanut Oil") ...
            $matched = false;
            $tokens = preg_split('/[^\p{L}\p{N}]+/u', $allergen, -1, PREG_SPLIT_NO_EMPTY);
            foreach ((array) $tokens as $token) {
                if (mb_strlen($token) >= 4 && mb_strpos($haystack, $token) !== false) {
                    $matched = true;
                    break;
                }
            }

            // ... or the medicine's own name/generic name appears in the allergy
            // text (allergy "Amoxicillin 500mg" vs medicine "Amoxicillin").
            if (! $matched) {
                foreach ($medicineKeys as $key) {
                    if (mb_strpos($allergen, $key) !== false) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched) {
                $conflicts[] = $allergy;
            }
        }

        return $conflicts;
    }
}

if (! function_exists('deduct_stock_trace')) {
    /**
     * Deduct a quantity from a medicine's batches, oldest expiry first (the
     * same batches medicine_stock() counts), writing one stock_movements OUT
     * row per batch with ref_type 'dispense' / ref_id = request id (original
     * functions.php:648; PDO $handle dropped).
     *
     * Returns the batches used - [['batch_id' => int, 'quantity' => int], ...]
     * with the primary batch first - or null when stock is insufficient (the
     * caller rolls back). Runs inside the caller's transaction; FOR UPDATE
     * locks the rows it reads. SQLite (tests only) does not understand
     * FOR UPDATE, so the lock clause is appended for MySQL alone - the only
     * driver the application runs on in production.
     */
    function deduct_stock_trace($medicine_id, $qty, $performed_by, $request_id, $reason)
    {
        $medicine_id = (int) $medicine_id;
        $qty = (int) $qty;
        if ($medicine_id <= 0 || $qty <= 0) {
            return null;
        }

        $sql = 'SELECT id, quantity FROM medicine_batches
                  WHERE medicine_id = ? AND quantity > 0
                    AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                  ORDER BY (expiry_date IS NULL), expiry_date, id';
        if (DB::connection()->getDriverName() === 'mysql') {
            $sql .= ' FOR UPDATE';
        }

        $batches = DB::select($sql, [$medicine_id]);

        $available = 0;
        foreach ($batches as $batch) {
            $available += (int) $batch->quantity;
        }
        if ($available < $qty) {
            return null;
        }

        $used = [];
        $remaining = $qty;
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((int) $batch->quantity, $remaining);
            if ($take <= 0) {
                continue;
            }
            DB::table('medicine_batches')->where('id', $batch->id)
                ->update(['quantity' => DB::raw('quantity - '.$take)]);
            DB::table('stock_movements')->insert([
                'medicine_id' => $medicine_id,
                'batch_id' => (int) $batch->id,
                'type' => 'OUT',
                'quantity' => $take,
                'reason' => $reason,
                'ref_type' => 'dispense',
                'ref_id' => $request_id,
                'performed_by' => $performed_by,
            ]);
            $used[] = ['batch_id' => (int) $batch->id, 'quantity' => $take];
            $remaining -= $take;
        }

        return $remaining === 0 ? $used : null;
    }
}

if (! function_exists('role_label')) {
    /**
     * Human-readable label for a role - ported verbatim from
     * includes/functions.php (admin/users.php renders it in the Role chip).
     */
    function role_label($role)
    {
        $labels = [
            'admin' => 'Administrator',
            'staff' => 'Clinic Staff',
            'user' => 'User',
        ];

        return $labels[$role] ?? ucfirst($role);
    }
}

if (! function_exists('sync_account_type')) {
    /**
     * Sync account type with student/employee details tables - ported from
     * includes/functions.php (admin/users.php calls it after every save).
     *
     * The reference takes its PDO handle; the port runs through Laravel's
     * default connection. The COUNT results are cast to int explicitly: the
     * reference stack runs native prepares (probe: COUNT(*) comes back as
     * integer), so its strict `=== 0` compares fire - the cast reproduces
     * that on MySQL whether or not emulation stringifies, and on the SQLite
     * test connection. The INSERTs keep the original `""` empty-number
     * literals verbatim.
     */
    function sync_account_type($user_id, $account_type)
    {
        if ($account_type === 'student') {
            if ((int) DB::scalar('SELECT COUNT(*) FROM student_details WHERE user_id = ?', [$user_id]) === 0) {
                DB::insert('INSERT INTO student_details (user_id, student_no) VALUES (?, "")', [$user_id]);
            }

            DB::delete('DELETE FROM employee_details WHERE user_id = ?', [$user_id]);
        } elseif ($account_type === 'employee') {
            if ((int) DB::scalar('SELECT COUNT(*) FROM employee_details WHERE user_id = ?', [$user_id]) === 0) {
                DB::insert('INSERT INTO employee_details (user_id, employee_no) VALUES (?, "")', [$user_id]);
            }

            DB::delete('DELETE FROM student_details WHERE user_id = ?', [$user_id]);
        }
    }
}

if (! function_exists('report_range')) {
    /**
     * Report date range helper - computes from/to dates based on period
     * (admin/reports.php Phase 27, ported verbatim from the reference's
     * includes/functions.php so staff/reports.php can share it later).
     *
     * Quirks kept as-is: the whitelist falls back to 'monthly'; when BOTH
     * from and to are truthy they go through strtotime()+date() with NO
     * validation (a garbage pair becomes 1970-01-01 - epoch); a lone from
     * or to is ignored; '0' is falsy and therefore ignored too. DateTime
     * uses the app timezone (Europe/Berlin), which matches the shared
     * php.ini date.timezone on this machine.
     */
    function report_range($period = 'monthly', $from = null, $to = null)
    {
        $allowed = ['daily', 'weekly', 'monthly', 'yearly'];
        if (! in_array($period, $allowed, true)) {
            $period = 'monthly';
        }

        if ($from && $to) {
            // Explicit dates provided - validate them
            $from = date('Y-m-d', strtotime($from));
            $to = date('Y-m-d', strtotime($to));

            return [$period, $from, $to];
        }

        $now = new DateTime;
        $today = $now->format('Y-m-d');

        switch ($period) {
            case 'daily':
                $from = $today;
                $to = $today;
                break;
            case 'weekly':
                // Start of week (Monday)
                $from = $now->modify('monday this week')->format('Y-m-d');
                $to = (clone $now)->modify('sunday this week')->format('Y-m-d');
                break;
            case 'monthly':
                $from = $now->modify('first day of this month')->format('Y-m-d');
                $to = $now->modify('last day of this month')->format('Y-m-d');
                break;
            case 'yearly':
                $from = $now->modify('first day of January')->format('Y-m-d');
                $to = $now->modify('last day of December')->format('Y-m-d');
                break;
        }

        return [$period, $from, $to];
    }
}

// Text colour class for the notification icon, by notifications.severity
// (enum: info, success, warning, danger) - port of severity_text_class()
// (includes/functions.php:302); consumed by the notifications centre view.
if (! function_exists('severity_text_class')) {
    function severity_text_class($severity)
    {
        switch ((string) $severity) {
            case 'success':
                return 'text-success';
            case 'warning':
                return 'text-warning';
            case 'danger':
                return 'text-danger';
            default:
                return 'text-primary';
        }
    }
}

// Badge class for the notification severity label (Bootstrap 5 utilities) -
// port of severity_badge_class() (includes/functions.php:318).
if (! function_exists('severity_badge_class')) {
    function severity_badge_class($severity)
    {
        switch ((string) $severity) {
            case 'success':
                return 'bg-success';
            case 'warning':
                return 'bg-warning text-dark';
            case 'danger':
                return 'bg-danger';
            default:
                return 'bg-primary';
        }
    }
}
