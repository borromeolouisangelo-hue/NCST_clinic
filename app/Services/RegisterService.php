<?php

namespace App\Services;

use App\Support\PhilippineLocations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Self-registration pipeline - extracted verbatim from
 * App\Http\Controllers\Auth\RegisterController::index() (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the signed-in bounce, the $old
 * repopulation map (OLD_DEFAULTS + the per-field trim loop), reading the
 * raw password/confirm, the PRG redirect/flash and view rendering. This
 * service owns the POST pipeline in the original order:
 *
 *  1. the field checks ALL run and accumulate - no early return, so one
 *     POST can show six messages at once: username, valid email, first
 *     name, last name, password min 6, password match, then the
 *     student/employee number check for $type, then the Philippine
 *     province/city dropdown validation;
 *  2. uniqueness - a single COUNT over username OR email, skipped entirely
 *     when the field checks already failed;
 *  3. users -> profiles -> student_details|employee_details inside one
 *     DB::transaction, then the audit 'register' row (outside the
 *     transaction - exactly like the original, so an audit failure still
 *     surfaces after a successful commit);
 *  4. QueryException -> DB::transaction already rolled back (the original's
 *     $pdo->rollBack()); the driver's own message (the previous exception,
 *     for byte-shape parity with the original's PDOException) appends to
 *     "Registration failed: ".
 *
 * $type collapses anything but the literal 'employee' to 'student' - the
 * original's account_type handling - computed here and also used in the
 * audit message ("New {$type} registered").
 */
class RegisterService
{
    /**
     * Run the registration pipeline: validations -> uniqueness -> create
     * transaction -> audit row.
     *
     * @param  array<string, string>  $old  the already-trimmed $old map
     *                                      (controller's OLD_DEFAULTS shape)
     * @param  string  $password  raw password (TrimStrings-excluded)
     * @param  string  $confirm  raw password_confirm
     * @return array{success: bool, errors: array<int, string>}
     */
    public function register(array $old, string $password, string $confirm): array
    {
        $type = $old['account_type'] === 'employee' ? 'employee' : 'student';
        $errors = [];

        // Field checks - original lines 31-39: every check runs, order kept.
        if ($old['username'] === '') {
            $errors[] = 'Username is required.';
        }
        if ($old['email'] === '' || ! filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if ($old['first_name'] === '') {
            $errors[] = 'First name is required.';
        }
        if ($old['last_name'] === '') {
            $errors[] = 'Last name is required.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if ($type === 'student' && $old['student_no'] === '') {
            $errors[] = 'Student number is required.';
        }
        if ($type === 'employee' && $old['employee_no'] === '') {
            $errors[] = 'Employee number is required.';
        }

        // Feature: Province/City-Municipality dropdown validation.
        if (! PhilippineLocations::isValidProvince($old['province'])) {
            $errors[] = 'Please select a valid province.';
        } elseif (! PhilippineLocations::isValidCity($old['province'], $old['city_municipality'])) {
            $errors[] = 'Please select a valid city/municipality for the chosen province.';
        }

        // Uniqueness - original lines 42-48: one COUNT, username OR email,
        // skipped entirely when the field checks already failed.
        if (! $errors) {
            $taken = DB::table('users')
                ->where('username', $old['username'])
                ->orWhere('email', $old['email'])
                ->count();

            if ($taken > 0) {
                $errors[] = 'Username or email is already taken.';
            }
        }

        // Create - original lines 50-89.
        if (! $errors) {
            try {
                $uid = DB::transaction(function () use ($old, $password, $type) {
                    $uid = (int) DB::table('users')->insertGetId([
                        'username' => $old['username'],
                        'email' => $old['email'],
                        'password_hash' => Hash::make($password),
                        'role' => 'user',
                        'account_type' => $type,
                        'status' => 'active',
                    ]);

                    DB::table('profiles')->insert([
                        'user_id' => $uid,
                        'first_name' => $old['first_name'],
                        'middle_name' => $old['middle_name'],
                        'last_name' => $old['last_name'],
                        'gender' => in_array($old['gender'], ['male', 'female', 'other'], true) ? $old['gender'] : null,
                        'birthdate' => $old['birthdate'] ?: null,
                        'address' => format_address([
                            $old['lot_block'], $old['street'], $old['barangay'], $old['postal_code'],
                            $old['city_municipality'], $old['province'],
                        ]),
                        'contact_no' => $old['contact_no'],
                        'emergency_name' => $old['emergency_name'],
                        'emergency_contact' => $old['emergency_contact'],
                    ]);

                    if ($type === 'student') {
                        DB::table('student_details')->insert([
                            'user_id' => $uid,
                            'student_no' => $old['student_no'],
                            'course' => $old['course'],
                            'year_level' => $old['year_level'],
                            'section' => $old['section'],
                        ]);
                    } else {
                        DB::table('employee_details')->insert([
                            'user_id' => $uid,
                            'employee_no' => $old['employee_no'],
                            'department' => $old['department'],
                            'position' => $old['position'],
                        ]);
                    }

                    return $uid;
                });
            } catch (QueryException $ex) {
                // DB::transaction already rolled back (the original's
                // $pdo->rollBack()); message from the wrapped driver
                // exception = the original's $ex->getMessage() on the
                // PDOException itself.
                $driver = $ex->getPrevious();
                $errors[] = 'Registration failed: '
                    .($driver instanceof \Throwable ? $driver->getMessage() : $ex->getMessage());
                $uid = null;
            }

            if ($uid !== null) {
                AuditLogger::log($uid, 'register', 'users', $uid, "New {$type} registered");

                return ['success' => true, 'errors' => []];
            }
        }

        return ['success' => false, 'errors' => $errors];
    }
}
