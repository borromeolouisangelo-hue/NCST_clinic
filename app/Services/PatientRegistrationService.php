<?php

namespace App\Services;

use App\Support\PhilippineLocations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Staff patient registration pipeline - extracted verbatim from
 * App\Http\Controllers\Staff\PatientController::store() (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: reading/trimming the POST fields
 * (including format_address() and the province/city + profile/detail field
 * resolution), the success redirect and the failed-POST same-request
 * re-render via its private form() helper. This service owns the pipeline
 * in the original order:
 *
 *  1. the keyed field checks accumulate (no early return): username, valid
 *     email, first name, last name, password min 6, then the
 *     student/employee number check for $type, then the Philippine
 *     province/city dropdown validation - error-array KEYS included
 *     (username, email, ..., province, city_municipality) so the
 *     MessageBag renders exactly like before;
 *  2. uniqueness - one exists() over username OR email, skipped entirely
 *     when the field checks already failed;
 *  3. users + profiles + student_details|employee_details inside one
 *     DB::transaction, then the audit 'create_patient' row (outside the
 *     transaction, exactly like the original);
 *  4. catch (Throwable) -> the transaction already rolled back; the
 *     original's message appends to "Registration failed: " under the
 *     'db' error key.
 *
 * Error strings, error-array keys, validation order, SQL text and
 * transaction boundaries are byte-identical to the controller's originals;
 * only $request->user()->id became the injected $actor (same value,
 * resolved once by the controller).
 */
class PatientRegistrationService
{
    /**
     * Run the registration pipeline: validations -> uniqueness -> create
     * transaction -> audit row.
     *
     * @param  string  $username  trimmed username
     * @param  string  $email  trimmed email
     * @param  string  $password  raw password (TrimStrings-excluded)
     * @param  string  $first  trimmed first name
     * @param  string  $last  trimmed last name
     * @param  string  $type  student|employee (account_type collapse)
     * @param  string  $address  format_address() of the raw address inputs
     * @param  string  $province  trimmed province dropdown value
     * @param  string  $cityMunicipality  trimmed city dropdown value
     * @param  array<string, mixed>  $input  remaining fields, each resolved
     *                                       exactly where/how the original
     *                                       reads them (trimmed), keyed:
     *                                       student_no, employee_no,
     *                                       middle_name, gender, birthdate,
     *                                       contact_no, emergency_name,
     *                                       emergency_contact, course,
     *                                       year_level, section, department,
     *                                       position
     * @param  int  $actor  authenticated staff id ($request->user()->id)
     * @return array{success: bool, errors: array<string, array<int, string>>}
     */
    public function register(
        string $username,
        string $email,
        string $password,
        string $first,
        string $last,
        string $type,
        string $address,
        string $province,
        string $cityMunicipality,
        array $input,
        int $actor,
    ): array {
        $errors = [];

        if ($username === '') {
            $errors['username'][] = 'Username is required.';
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'][] = 'A valid email is required.';
        }
        if ($first === '') {
            $errors['first_name'][] = 'First name is required.';
        }
        if ($last === '') {
            $errors['last_name'][] = 'Last name is required.';
        }
        if (strlen($password) < 6) {
            $errors['password'][] = 'Password must be at least 6 characters.';
        }
        if ($type === 'student' && $input['student_no'] === '') {
            $errors['student_no'][] = 'Student number is required.';
        }
        if ($type === 'employee' && $input['employee_no'] === '') {
            $errors['employee_no'][] = 'Employee number is required.';
        }

        // Feature: Province/City-Municipality dropdown validation.
        if (! PhilippineLocations::isValidProvince($province)) {
            $errors['province'][] = 'Please select a valid province.';
        } elseif (! PhilippineLocations::isValidCity($province, $cityMunicipality)) {
            $errors['city_municipality'][] = 'Please select a valid city/municipality for the chosen province.';
        }

        if (! $errors) {
            $exists = DB::table('users')
                ->where('username', $username)
                ->orWhere('email', $email)
                ->exists();

            if ($exists) {
                $errors['users'][] = 'Username or email already exists.';
            }
        }

        if (! $errors) {
            try {
                $uid = (int) DB::transaction(function () use ($username, $email, $password, $first, $last, $type, $address, $input) {
                    $uid = (int) DB::table('users')->insertGetId([
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => Hash::make($password),
                        'role' => 'user',
                        'account_type' => $type,
                        'status' => 'active',
                    ]);

                    DB::table('profiles')->insert([
                        'user_id' => $uid,
                        'first_name' => $first,
                        'middle_name' => $input['middle_name'],
                        'last_name' => $last,
                        'gender' => in_array($input['gender'], ['male', 'female', 'other'], true)
                            ? $input['gender']
                            : null,
                        'birthdate' => $input['birthdate'] ?: null,
                        'address' => $address,
                        'contact_no' => $input['contact_no'],
                        'emergency_name' => $input['emergency_name'],
                        'emergency_contact' => $input['emergency_contact'],
                    ]);

                    if ($type === 'student') {
                        DB::table('student_details')->insert([
                            'user_id' => $uid,
                            'student_no' => $input['student_no'],
                            'course' => $input['course'],
                            'year_level' => $input['year_level'],
                            'section' => $input['section'],
                        ]);
                    } else {
                        DB::table('employee_details')->insert([
                            'user_id' => $uid,
                            'employee_no' => $input['employee_no'],
                            'department' => $input['department'],
                            'position' => $input['position'],
                        ]);
                    }

                    return $uid;
                });
            } catch (Throwable $ex) {
                $errors['db'][] = 'Registration failed: '.$ex->getMessage();
            }

            if (! $errors) {
                AuditLogger::log(
                    $actor,
                    'create_patient',
                    'users',
                    $uid,
                    "Registered patient $username ($type)"
                );

                return ['success' => true, 'errors' => []];
            }
        }

        return ['success' => false, 'errors' => $errors];
    }
}
