<?php

namespace App\Support;

use App\Models\Notification;
use App\Models\User;
use App\Services\Navigation;
use App\Services\ProfilePhoto;

/**
 * Everything the shared dashboard layout needs to know about who is looking.
 *
 * The Blade shell reads the sidebar, the orange role badge, the profile
 * dropdown and the active navigation state from this object, so there is a
 * single seam between "the layout" and "the signed-in user".
 *
 * One factory feeds that seam:
 *
 *  - {@see ShellContext::fromUser()} - builds the context from the
 *    authenticated user row (used by the dashboard routes).
 *
 * Ported from the original includes/functions.php helpers: current_role(),
 * role_badge_label(), settings_for() and includes/topbar.php.
 */
class ShellContext
{
    /** The only three role buckets the original application uses. */
    public const ROLES = ['admin', 'staff', 'user'];

    /**
     * Known login identities: role plus account_type.
     *
     * student/employee are account types under the `user` role, not roles of
     * their own - exactly as in the original session.
     */
    public const IDENTITIES = ['admin', 'staff', 'student', 'employee', 'user'];

    public function __construct(
        public readonly string $role,
        public readonly string $accountType,
        public readonly string $fullName,
        public readonly string $roleBadge,
        public readonly bool $isEmployee,
        public readonly int $unreadNotifications,
        public readonly string $activePath,
        public readonly array $nav,
        public readonly string $profileUrl,
        public readonly string $settingsUrl,
        public readonly string $logoutUrl,
        public readonly string $photoUrl = '',
    ) {}

    /**
     * Build the context for the authenticated user.
     *
     * Everything mirrors what the original session stored at login time and
     * what includes/topbar.php read back out of it:
     *
     *  - identity      = the `role` bucket (admin|staff|user)
     *  - account type  = `users.account_type` (student|employee)
     *  - full name     = trim(profiles.first_name . ' ' . profiles.last_name),
     *                    falling back to the username when the profile row is
     *                    missing (the original's flash already used
     *                    `full_name ?: username`; every live account has a
     *                    profile, so the fallback never fires on real data)
     *  - badge         = role_badge_label() (ADMIN/STAFF/STUDENT/EMPLOYEE)
     *  - unread count  = COUNT(notifications WHERE user_id = ? AND is_read = 0),
     *                    the original topbar's exact query
     *
     * No parallel session state is written - the identity lives in Laravel's
     * Auth session and is re-read from these tables on every request.
     */
    public static function fromUser(User $user, string $activePath = ''): self
    {
        $role = in_array($user->role, self::ROLES, true) ? $user->role : 'user';
        $accountType = (string) $user->account_type;

        $fullName = self::fullNameFor($user);

        if ($fullName === '') {
            $fullName = (string) $user->username;
        }

        $unread = (int) Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', 0)
            ->count();

        return new self(
            role: $role,
            accountType: $accountType,
            fullName: $fullName,
            roleBadge: self::badgeFor($role, $accountType),
            isEmployee: $accountType === 'employee',
            unreadNotifications: max(0, min(999, $unread)),
            activePath: $activePath,
            nav: Navigation::for($role, $accountType === 'employee'),
            profileUrl: url('user/profile'),
            // Feature 5: Profile + Account Settings are ONE page now, so the
            // dropdown's "Account Settings" lands on the same merged route
            // the sidebar does for every role - the per-role settings pages
            // are no longer the account half of the shell.
            settingsUrl: url('user/profile'),
            logoutUrl: url('auth/logout'),
            // Feature 5: '' when the user has no (usable) picture, so the
            // dropdown and any other avatar slot render their placeholder.
            photoUrl: ProfilePhoto::url($user->profile?->photo ?? null),
        );
    }

    /**
     * Display name from users -> profiles: trim(first_name . ' ' . last_name).
     *
     * This is the exact expression login.php wrote into $_SESSION['full_name']
     * (LEFT JOIN profiles, no middle name). Returns '' when there is no profile
     * row, exactly like the original expression does; callers decide whether to
     * fall back to the username (`?: username`).
     */
    public static function fullNameFor(User $user): string
    {
        $profile = $user->profile;

        return trim(
            ($profile->first_name ?? '').' '.($profile->last_name ?? '')
        );
    }

    /**
     * Route name of a role's dashboard - port of dashboard_for(): allow-list
     * the three roles and fall back to 'user' for anything else.
     */
    public static function dashboardRouteName(string $role): string
    {
        if (! in_array($role, self::ROLES, true)) {
            $role = 'user';
        }

        return $role.'.dashboard';
    }

    /**
     * Role bucket for an identity - port of the allow-list used by
     * dashboard_for() / settings_for() in the original helpers.
     */
    public static function roleFor(string $identity): string
    {
        if ($identity === 'admin' || $identity === 'staff') {
            return $identity;
        }

        return 'user';
    }

    /**
     * Short uppercase badge label - byte-for-byte port of role_badge_label().
     */
    public static function badgeFor(string $role, string $accountType): string
    {
        if ($role === 'admin') {
            return 'ADMIN';
        }

        if ($role === 'staff') {
            return 'STAFF';
        }

        if (in_array($accountType, ['student', 'employee'], true)) {
            return strtoupper($accountType);
        }

        return strtoupper($role ?: 'user');
    }

    /** True when this sidebar entry is the page currently on screen. */
    public function isActive(string $path): bool
    {
        return $this->activePath !== '' && $this->activePath === $path;
    }
}
