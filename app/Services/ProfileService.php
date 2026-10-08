<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Account Settings mutations - extracted verbatim from
 * App\Http\Controllers\ProfileController::index() (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the $uid/$data row fetch, the
 * form_action dispatch, the identity-field reads, the photo_action read,
 * the redirect for each success path, the active-tab logic and view
 * rendering. This service owns the mutations:
 *
 *  - updateProfile(): the two validations (first/last name), then the
 *    profiles UPDATE, the account-type detail UPDATE (student -> course/
 *    year_level/section, every non-student -> department/position, an
 *    UPDATE matching no row is a silent no-op like the reference) and the
 *    audit row. The controller passes the already-resolved $gender
 *    whitelist, $birthdate and the five trimmed detail fields.
 *
 *  - removePhoto(): ProfilePhoto::delete + photo IS NULL + audit.
 *
 *  - uploadPhoto(): the Validator (image sniffs the server-guessed
 *    extension, so a renamed non-image never reaches ProfilePhoto::store),
 *    then delete-old / store-new / photo update + audit; returns the
 *    validator's messages so the controller re-renders in-place on failure.
 *
 *  The password branch is deliberately NOT here: it stays a three-line
 *  dispatch in the controller to PasswordChange (already the shared
 *  rule-set service) - wrapping it would only add an indirection.
 *
 * Error strings, SQL text, validation arrays and call order are
 * byte-identical to the controller's originals; $uid is
 * $request->user()->id resolved once by the controller.
 */
class ProfileService
{
    /**
     * Run the identity-update pipeline: validations -> profile UPDATE ->
     * detail UPDATE -> audit.
     *
     * @param  bool  $isStudent  ($data['account_type'] ?? '') === 'student'
     * @param  array<string, string>  $details  course, year_level, section,
     *                                          department, position (trimmed)
     * @return array{success: bool, errors: array<int, string>}
     */
    public function updateProfile(
        int $uid,
        string $first,
        string $mid,
        string $last,
        ?string $gender,
        mixed $birthdate,
        string $address,
        string $contact,
        string $emergencyName,
        string $emergencyContact,
        bool $isStudent,
        array $details,
    ): array {
        $errors = [];

        if ($first === '') {
            $errors[] = 'First name is required.';
        }
        if ($last === '') {
            $errors[] = 'Last name is required.';
        }

        if ($errors === []) {
            DB::update(
                'UPDATE profiles SET first_name=?, middle_name=?, last_name=?, gender=?,'
                .' birthdate=?, address=?, contact_no=?, emergency_name=?, emergency_contact=?'
                .' WHERE user_id=?',
                [$first, $mid, $last, $gender, $birthdate, $address, $contact, $emergencyName, $emergencyContact, $uid]
            );

            if ($isStudent) {
                DB::update(
                    'UPDATE student_details SET course=?, year_level=?, section=? WHERE user_id=?',
                    [
                        $details['course'],
                        $details['year_level'],
                        $details['section'],
                        $uid,
                    ]
                );
            } else {
                // The reference runs this branch for every non-student
                // (admin/staff included) - an UPDATE that matches no
                // employee_details row is a silent no-op there too.
                DB::update(
                    'UPDATE employee_details SET department=?, position=? WHERE user_id=?',
                    [
                        $details['department'],
                        $details['position'],
                        $uid,
                    ]
                );
            }

            // Original line 63 wrote $_SESSION['full_name'] so the
            // topbar name refreshed; the shell re-reads profiles on
            // every request instead (ShellContext writes no parallel
            // session state), so the new name shows from the row just
            // written - no session copy to keep in sync.
            AuditLogger::log($uid, 'update_profile', 'profiles', $uid, 'Updated own profile');

            return ['success' => true, 'errors' => []];
        }

        return ['success' => false, 'errors' => $errors];
    }

    /**
     * Remove the profile picture: delete the file, null the column, audit.
     */
    public function removePhoto(int $uid, ?string $photo): void
    {
        ProfilePhoto::delete($photo);
        DB::table('profiles')->where('user_id', $uid)->update(['photo' => null]);

        AuditLogger::log($uid, 'remove_profile_photo', 'profiles', $uid, 'Removed own profile picture');
    }

    /**
     * Validate + store a new profile picture.
     *
     * @param  array<string, mixed>  $input  $request->all() (the original
     *                                       Validator input, file included)
     * @return array{success: bool, photoErrors: array<int, string>}
     */
    public function uploadPhoto(int $uid, ?string $currentPhoto, array $input): array
    {
        // `image` sniffs the server-guessed extension, so a renamed
        // non-image never reaches ProfilePhoto::store().
        $validator = Validator::make($input, [
            'photo' => ['required', 'image', 'max:'.ProfilePhoto::MAX_KILOBYTES],
        ], [
            'photo.required' => 'Choose a picture to upload.',
            'photo.image' => 'The picture must be an image file.',
            'photo.max' => 'The picture may not be greater than 2 MB.',
        ]);

        if ($validator->fails()) {
            return ['success' => false, 'photoErrors' => $validator->errors()->all()];
        }

        ProfilePhoto::delete($currentPhoto);
        $filename = ProfilePhoto::store($input['photo'], $uid);

        DB::table('profiles')->where('user_id', $uid)->update(['photo' => $filename]);

        AuditLogger::log($uid, 'update_profile_photo', 'profiles', $uid, 'Updated own profile picture');

        return ['success' => true, 'photoErrors' => []];
    }
}
