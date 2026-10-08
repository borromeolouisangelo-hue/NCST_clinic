<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Feature 5 - one password-change rule set for every Account Settings surface.
 *
 * The three messages, their exact order and the RAW (untrimmed) reads of the
 * three fields are the reference's own sequence from user/settings.php lines
 * 34-36 and staff/settings.php lines 35-37. The two existing settings
 * controllers used to keep a private copy of it each; now they and the merged
 * Profile + Security page all call this, so the rule set cannot drift apart
 * between the three places a user can change a password.
 *
 * Writing is deliberately split from validating: the caller owns the redirect
 * and flash (each surface PRGs to its own URL), and only ever calls
 * {@see apply()} once {@see validate()} returned no errors.
 */
class PasswordChange
{
    /**
     * Run the reference's three checks, in the reference's order.
     *
     * @return array<int, string> empty when the change may be applied
     */
    public static function validate(Request $request, int $userId): array
    {
        // Original lines read the password fields RAW (no trim).
        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $confirmPassword = (string) $request->input('confirm_password', '');

        $passwordHash = (string) DB::table('users')
            ->where('id', $userId)
            ->value('password_hash');

        $errors = [];

        if (! Hash::check($currentPassword, $passwordHash)) {
            $errors[] = 'Current password is incorrect.';
        }
        if ($newPassword === '' || strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New passwords do not match.';
        }

        return $errors;
    }

    /** Store the new hash and audit it. Only call with no validation errors. */
    public static function apply(Request $request, int $userId): void
    {
        DB::table('users')->where('id', $userId)->update([
            'password_hash' => Hash::make((string) $request->input('new_password', '')),
        ]);

        AuditLogger::log($userId, 'change_password', 'users', $userId, 'Changed own password');
    }
}
