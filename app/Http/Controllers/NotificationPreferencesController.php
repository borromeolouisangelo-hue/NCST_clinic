<?php

namespace App\Http\Controllers;

use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification preferences - notifications_preferences.php (all roles, bare
 * require_login()).
 *
 * GET renders the three display groups with every switch defaulted to ON
 * (original line 75: $prefs[$type] ?? true - an ABSENT row means enabled,
 * so the unchecked state only ever comes from an explicit is_enabled = 0
 * row). The $labels map of the reference's POST block is values-dead (the
 * view shows the raw type keys from $groups, original lines 65-80); the
 * const TYPES below mirrors its 11 keys - that array is the authoritative
 * save list: each POST writes ALL eleven switches, a missing pref_* field
 * counts as disabled (isset() + === '1' semantics, original line 34), and
 * the row is upserted per (user_id, notification_type) - the live table's
 * uq_notif_setting unique key, mirrored in the test schema. updated_at is
 * NOT written (the reference's ON DUPLICATE KEY clause only touches
 * is_enabled; the column default fills on insert).
 *
 * PRG after save with the reference's flash 'Notification preferences
 * saved.' (write-only slot - no renderer). The reference's CSRF soft-fail
 * (flash + redirect) runs as 419 here - approved Phase 6 strengthening.
 *
 * route: notifications.preferences, auth only (open to all four), match
 * GET+POST. activePath 'notifications' is inert (the sidebar computes its
 * active state with request()->is(), never with ShellContext::isActive).
 */
class NotificationPreferencesController extends Controller
{
    /** The 11 switch keys - mirror of $types (original lines 19-31). */
    private const TYPES = [
        'medicine_dispensed',
        'medicine_declined',
        'consultation_new',
        'certificate_issued',
        'equipment_issued',
        'equipment_returned',
        'equipment_overdue',
        'sick_leave',
        'sick_leave_status',
        'user_created',
        'category_created',
    ];

    /** Display groups - verbatim mirror of $groups (original lines 46-50). */
    private const GROUPS = [
        'Medical Services' => [
            'medicine_dispensed', 'medicine_declined', 'consultation_new',
            'certificate_issued', 'equipment_issued', 'equipment_returned',
            'equipment_overdue',
        ],
        'Sick Leave' => ['sick_leave', 'sick_leave_status'],
        'System (Staff)' => ['user_created', 'category_created'],
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $uid = (int) $request->user()->id;

        if ($request->isMethod('post')) {
            foreach (self::TYPES as $type) {
                $enabled = $request->input("pref_{$type}") === '1';
                $this->setPreference($uid, $type, $enabled);
            }

            return redirect()
                ->route('notifications.preferences')
                ->with('success', 'Notification preferences saved.');
        }

        return view('notifications_preferences', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'notifications'),
            'title' => 'Notification Preferences',
            'prefs' => $this->getPreferences($uid),
            'groups' => self::GROUPS,
        ]);
    }

    /**
     * NotificationModel::getPreferences() port (NotificationModel.php:146):
     * type => bool map scoped to the current user; no row = key absent.
     *
     * @return array<string, bool>
     */
    private function getPreferences(int $uid): array
    {
        $prefs = [];
        foreach (DB::table('notification_settings')->where('user_id', $uid)->get() as $row) {
            $prefs[$row->notification_type] = (bool) $row->is_enabled;
        }

        return $prefs;
    }

    /**
     * NotificationModel::setPreference() port (NotificationModel.php:162):
     * INSERT .. ON DUPLICATE KEY UPDATE becomes the portable query-builder
     * upsert on (user_id, notification_type) - is_enabled only, no
     * updated_at write.
     */
    private function setPreference(int $uid, string $type, bool $enabled): void
    {
        DB::table('notification_settings')->upsert(
            [[
                'user_id' => $uid,
                'notification_type' => $type,
                'is_enabled' => $enabled ? 1 : 0,
            ]],
            ['user_id', 'notification_type'],
            ['is_enabled']
        );
    }
}
