<?php

namespace App\Services;

/**
 * Role-aware navigation for the shared dashboard shell.
 *
 * Transcribed from the original application's `includes/sidebar.php`: same
 * sections, same order, same Bootstrap Icons classes, same labels. One array
 * feeds one Blade partial, so ADMIN / STAFF / STUDENT / EMPLOYEE all render
 * through a single layout rather than four near-identical copies.
 *
 * Paths mirror the original `url()` targets with `.php` stripped
 * (`admin/users.php` -> `admin/users`) so every entry stays traceable to the
 * page it came from. A link only resolves once the feature phase that creates
 * its route has landed; until then it renders but 404s, which is expected for a
 * shell-only phase.
 */
class Navigation
{
    /**
     * Sections for a role.
     *
     * @param  string  $role  One of ShellContext::ROLES (admin|staff|user).
     * @return array<int, array{section: string, items: array<int, array{path: string, icon: string, label: string}>}>
     */
    public static function for(string $role, bool $isEmployee = false): array
    {
        if ($role === 'admin') {
            return self::admin();
        }

        if ($role === 'staff') {
            return self::staff();
        }

        return self::user($isEmployee);
    }

    /**
     * Admin navigation: Dashboard / Patients / Clinic Operations / Inventory / Records / Users & Accounts / Reports / Notifications / Settings.
     *
     * @return array<int, array{section: string, items: array<int, array{path: string, icon: string, label: string}>}>
     */
    private static function admin(): array
    {
        return [
            ['section' => 'Dashboard', 'items' => [
                ['path' => 'admin/dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ]],
            ['section' => 'Patients', 'items' => [
                ['path' => 'admin/users', 'icon' => 'bi-people', 'label' => 'Patients'],
            ]],
            ['section' => 'Clinic Operations', 'items' => [
                ['path' => 'staff/consultations', 'icon' => 'bi-clipboard2-pulse', 'label' => 'Visits'],
                ['path' => 'staff/dispense', 'icon' => 'bi-capsule', 'label' => 'Dispensing'],
                ['path' => 'staff/equipment_loans', 'icon' => 'bi-tools', 'label' => 'Equipment'],
                ['path' => 'staff/sick_leaves', 'icon' => 'bi-thermometer', 'label' => 'Sick Leaves'],
            ]],
            ['section' => 'Inventory', 'items' => [
                ['path' => 'admin/medicines', 'icon' => 'bi-capsule', 'label' => 'Medicines'],
                ['path' => 'admin/categories', 'icon' => 'bi-tags', 'label' => 'Categories'],
                ['path' => 'admin/suppliers', 'icon' => 'bi-truck', 'label' => 'Suppliers'],
                ['path' => 'admin/equipment', 'icon' => 'bi-tools', 'label' => 'Equipment Catalog'],
            ]],
            ['section' => 'Records', 'items' => [
                ['path' => 'staff/history', 'icon' => 'bi-card-list', 'label' => 'History'],
                ['path' => 'admin/audit_logs', 'icon' => 'bi-journal-text', 'label' => 'Audit Logs'],
            ]],
            ['section' => 'Users & Accounts', 'items' => [
                ['path' => 'admin/users', 'icon' => 'bi-people', 'label' => 'User Accounts'],
            ]],
            ['section' => 'Reports', 'items' => [
                ['path' => 'admin/reports', 'icon' => 'bi-graph-up', 'label' => 'Reports'],
            ]],
            ['section' => 'Notifications', 'items' => [
                ['path' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications'],
            ]],
            ['section' => 'Settings', 'items' => [
                // Feature 5: Profile + Account Settings merged into one page
                // at user/profile.php - still open to every role, exactly as
                // the original Profile entry was. The old second entry pointed
                // at admin/settings.php, which saves the school and clinic
                // configuration rather than an account, so it is relabelled
                // System Settings instead of competing for the same name.
                ['path' => 'user/profile', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
                ['path' => 'admin/settings', 'icon' => 'bi-sliders', 'label' => 'System Settings'],
            ]],
        ];
    }

    /**
     * Staff navigation: Dashboard / Patients / Clinic Operations / Inventory / Records / Notifications / Settings.
     *
     * @return array<int, array{section: string, items: array<int, array{path: string, icon: string, label: string}>}>
     */
    private static function staff(): array
    {
        return [
            ['section' => 'Dashboard', 'items' => [
                ['path' => 'staff/dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ]],
            ['section' => 'Patients', 'items' => [
                ['path' => 'staff/patients', 'icon' => 'bi-people', 'label' => 'Patients'],
                ['path' => 'staff/patient_register', 'icon' => 'bi-person-plus', 'label' => 'Register Patient'],
            ]],
            ['section' => 'Clinic Operations', 'items' => [
                ['path' => 'staff/consultations', 'icon' => 'bi-clipboard2-pulse', 'label' => 'Visits'],
                ['path' => 'staff/dispense', 'icon' => 'bi-capsule', 'label' => 'Dispensing'],
                ['path' => 'staff/equipment_loans', 'icon' => 'bi-arrow-left-right', 'label' => 'Equipment'],
                ['path' => 'staff/sick_leaves', 'icon' => 'bi-thermometer', 'label' => 'Sick Leaves'],
            ]],
            ['section' => 'Inventory', 'items' => [
                ['path' => 'staff/dispense', 'icon' => 'bi-capsule', 'label' => 'Dispense'],
                ['path' => 'staff/inventory', 'icon' => 'bi-box-seam', 'label' => 'Inventory'],
                ['path' => 'staff/medicine_requests', 'icon' => 'bi-activity', 'label' => 'Medicine Requests'],
                ['path' => 'staff/equipment_loans', 'icon' => 'bi-tools', 'label' => 'Equipment Loans'],
            ]],
            ['section' => 'Records', 'items' => [
                ['path' => 'staff/history', 'icon' => 'bi-card-list', 'label' => 'History'],
                ['path' => 'staff/deduction_logs', 'icon' => 'bi-receipt', 'label' => 'Deduction Logs'],
                ['path' => 'staff/reports', 'icon' => 'bi-graph-up', 'label' => 'Reports'],
            ]],
            ['section' => 'Notifications', 'items' => [
                ['path' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications'],
            ]],
            ['section' => 'Settings', 'items' => [
                // Feature 5: Profile + Account Settings merged into one page.
                ['path' => 'user/profile', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
            ]],
        ];
    }

    /**
     * Student / employee navigation: Dashboard / My Activity / Health / Settings.
     *
     * "Sick Leave" is employee-only, exactly like the `$isEmployee` branch in
     * the original sidebar.
     *
     * @return array<int, array{section: string, items: array<int, array{path: string, icon: string, label: string}>}>
     */
    private static function user(bool $isEmployee): array
    {
        $activity = [
            ['path' => 'user/my_requests', 'icon' => 'bi-activity', 'label' => 'My Activity'],
            ['path' => 'user/my_requests?tab=medicine', 'icon' => 'bi-capsule', 'label' => 'Medicine History'],
            ['path' => 'user/my_requests?tab=equipment', 'icon' => 'bi-tools', 'label' => 'Borrowed Items'],
        ];

        if ($isEmployee) {
            $activity[] = ['path' => 'user/my_requests?tab=sick-leaves', 'icon' => 'bi-thermometer', 'label' => 'Sick Leave'];
        }

        $activity[] = ['path' => 'user/my_certificates', 'icon' => 'bi-file-medical', 'label' => 'Certificates'];

        return [
            ['section' => 'Dashboard', 'items' => [
                ['path' => 'user/dashboard', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ]],
            ['section' => 'My Activity', 'items' => $activity],
            ['section' => 'Health', 'items' => [
                ['path' => 'user/medical_form', 'icon' => 'bi-file-medical', 'label' => 'Medical Form'],
            ]],
            ['section' => 'Settings', 'items' => [
                // Feature 5: Profile + Account Settings merged into one page.
                ['path' => 'user/profile', 'icon' => 'bi-gear', 'label' => 'Account Settings'],
                ['path' => 'notifications', 'icon' => 'bi-bell', 'label' => 'Notifications'],
            ]],
        ];
    }
}
