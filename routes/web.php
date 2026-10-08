<?php

use App\Http\Controllers\Admin\AdminAuditLogsController;
use App\Http\Controllers\Admin\AdminBackupController;
use App\Http\Controllers\Admin\AdminCategoriesController;
use App\Http\Controllers\Admin\AdminReportsController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminSuppliersController;
use App\Http\Controllers\Admin\AdminUsersController;
use App\Http\Controllers\Admin\EquipmentController;
use App\Http\Controllers\Admin\MedicineController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Http\Controllers\Api\NotificationSseController;
use App\Http\Controllers\Api\ProcessNotificationQueueController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Staff\DeductionLogsController;
use App\Http\Controllers\Staff\DispenseController;
use App\Http\Controllers\Staff\EquipmentLoansController;
use App\Http\Controllers\Staff\InventoryController;
use App\Http\Controllers\Staff\PatientController;
use App\Http\Controllers\Staff\SickLeaveController;
use App\Http\Controllers\Staff\StaffHistoryController;
use App\Http\Controllers\Staff\StaffReportsController;
use App\Http\Controllers\Staff\StaffSettingsController;
use App\Http\Controllers\User\MedicalFormController;
use App\Http\Controllers\User\MyRequestsController;
use App\Http\Controllers\User\UserSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication (Phase 5)
|--------------------------------------------------------------------------
|
| Ports auth/login.php, auth/logout.php and the three role dashboards.
|
| The login route MUST be named `login`: Laravel's guest handling resolves
| redirect()->guest(... ?? route('login')), so naming it is what sends
| unauthenticated visitors to the login page - the same redirect the original
| require_login() performs.
|
| Logout stays a GET route because the original logout.php is a plain link
| (the frozen account-dropdown partial uses an href and the original form has
| no CSRF token on it either). The session is still fully invalidated on
| every hit.
|
*/

/** GET /login - login form (signed-in visitors bounce to their dashboard). */
Route::get('/login', [LoginController::class, 'create'])->name('login');

/** POST /login - credential check, failed-login protection, audit, redirect. */
Route::post('/login', [LoginController::class, 'store'])->name('login.attempt');

/** GET /logout - audit, session invalidation, redirect to login. */
Route::get('/logout', [LogoutController::class, 'destroy'])->name('logout');

/** GET /auth/logout - the original account-menu link (auth/logout.php). */
Route::get('/auth/logout', [LogoutController::class, 'destroy']);

/*
|--------------------------------------------------------------------------
| Self-registration (Phase 33) - auth/register.php
|--------------------------------------------------------------------------
|
| Guests-only self-registration for students and employees. The original's
| is_logged_in() bounce (lines 7-9) guards GET and POST alike, so the one
| controller method bounces signed-in visitors before anything else - no
| `guest` middleware, mirroring the login routes above which do their own
| bounce too.
|
| The path is the ORIGINAL auth/register.php path (the /auth/logout
| precedent), which also lights up the login page's Create an Account
| button - it 404'd until this phase under the un-migrated link policy.
| The original page runs NO CSRF check and prints no token; the web
| middleware requires one here (approved Phase 6 strengthening, same as
| login) and a token-less POST raises 419.
|
*/

/** GET|POST /auth/register - the self-registration form and its self-post. */
Route::match(['get', 'post'], '/auth/register', [RegisterController::class, 'index'])->name('register');

/*
| Role-guarded dashboards. `auth` sends guests to route('login'); `role:...`
| sends wrong-role users to their OWN dashboard (302) - exactly the two
| redirects require_login() performs in the original application.
*/
Route::middleware(['auth', 'role:admin'])
    ->get('/admin/dashboard', [DashboardController::class, 'admin'])
    ->name('admin.dashboard');

Route::middleware(['auth', 'role:staff'])
    ->get('/staff/dashboard', [DashboardController::class, 'staff'])
    ->name('staff.dashboard');

Route::middleware(['auth', 'role:user'])
    ->get('/user/dashboard', [DashboardController::class, 'user'])
    ->name('user.dashboard');

// The user dashboard's sick-leave modal posts back to the same URL, exactly
// like the original self-posting form (user/dashboard.php:16-45).
Route::middleware(['auth', 'role:user'])
    ->post('/user/dashboard', [DashboardController::class, 'userSickLeave']);

/*
| Phase 10: user/sick_leave.php is a compatibility stub - require_login(['user'])
| then a 302 to user/dashboard.php ("The sick leave form is now a modal on the
| dashboard"). The original checks no request method, so GET and POST both
| redirect; guests go to login and wrong roles to their own dashboard through
| the same auth/role middleware pair every other route uses.
*/
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/sick_leave', fn () => redirect()->route('user.dashboard'))
    ->name('user.sick_leave');

/*
|--------------------------------------------------------------------------
| Patients (Phase 6) - port of staff/patients.php + staff/patient_register.php
|--------------------------------------------------------------------------
|
| Both original pages call require_login(['staff']), so both live behind
| ['auth', 'role:staff']: guests go to login, other roles to their own
| dashboard. The list page keeps the original ?id= / ?q= / ?type= query
| contract; the register form self-posts to its own URL, exactly like the
| PHP original (form has no action attribute).
|
*/

Route::middleware(['auth', 'role:staff'])->group(function () {
    /** staff/patients.php - list, search/filter, and ?id= profile view. */
    Route::get('/staff/patients', [PatientController::class, 'index'])->name('staff.patients');

    /** staff/patient_register.php - register form + self-posted create. */
    Route::get('/staff/patient_register', [PatientController::class, 'create'])->name('staff.patients.register');
    Route::post('/staff/patient_register', [PatientController::class, 'store']);
});

/*
|--------------------------------------------------------------------------
| Consultations (Phase 11) - staff/consultations.php + user/my_consultations.php
|--------------------------------------------------------------------------
|
| The staff page is a single self-posting endpoint: save, ?edit= prefill,
| ?action=delete and the list all live behind GET|POST on one URL, in the
| original's exact order (a failed POST falls through to the list render).
| The user history page is a plain GET behind role:user.
|
*/

/** staff/consultations.php - record + history (save/edit/delete/list). */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/consultations', [ConsultationController::class, 'index'])
    ->name('staff.consultations');

/** user/my_consultations.php - own consultation history. */
Route::middleware(['auth', 'role:user'])
    ->get('/user/my_consultations', [ConsultationController::class, 'myConsultations'])
    ->name('user.my_consultations');

/*
|--------------------------------------------------------------------------
| Certificates (Phase 12) - staff/certificates.php + user/my_certificates.php
|--------------------------------------------------------------------------
|
| The staff page is a single self-posting endpoint: issue, ?action=delete
| and the list all live behind GET|POST on one URL, in the original's exact
| order (a failed POST falls through to the list render). The user page is
| a plain GET behind role:user; its ?print= re-checks ownership in SQL like
| the original, while the staff ?print= does not (no check exists there).
|
*/

/** staff/certificates.php - issue + list + print + delete. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/certificates', [CertificateController::class, 'index'])
    ->name('staff.certificates');

/** user/my_certificates.php - own certificates + print. */
Route::middleware(['auth', 'role:user'])
    ->get('/user/my_certificates', [CertificateController::class, 'myCertificates'])
    ->name('user.my_certificates');

/*
|--------------------------------------------------------------------------
| Sick leaves (Phase 13) - staff/sick_leaves.php
|--------------------------------------------------------------------------
|
| One self-posting endpoint: the do=review POST dispatch runs first (the
| original checks CSRF via middleware-equivalent 419 here), then the list
| renders for GET and for a POST that carried no review action - same
| fall-through as the reference. user/sick_leave.php is Phase 10's
| compatibility stub and the user-side submit lives on the dashboard.
|
*/

/** staff/sick_leaves.php - review queue (approve/reject). */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/sick_leaves', [SickLeaveController::class, 'index'])
    ->name('staff.sick_leaves');

/*
|--------------------------------------------------------------------------
| Medicine (Phase 14) - admin/medicines.php + staff/dispense.php
|--------------------------------------------------------------------------
|
| Both originals call require_login with a single role, so both live behind
| ['auth', 'role:*']: guests go to login, other roles to their own dashboard.
| The medicines list is one self-posting endpoint (store/update falls through
| to the same GET rendering on validation errors); delete and edit ride the
| ?action= query contract exactly like the reference.
|
| The two compatibility stubs check no request method (require_login, flash,
| redirect - in that order), so both accept GET and POST and redirect through
| their target route with the original info message. The message is flashed
| the same way the original set_flash()s it: written but never rendered
| (write-only legacy slot - see partials/flash.blade.php).
|
*/

/** admin/medicines.php - catalog CRUD (self-posting) + ?action=delete/edit. */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/medicines', [MedicineController::class, 'index'])
    ->name('admin.medicines');

/** staff/dispense.php - walk-in dispensing with allergy override (self-posting). */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/dispense', [DispenseController::class, 'index'])
    ->name('staff.dispense');

/** staff/medicine_requests.php - stub: flash + redirect to the dispensing page. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/medicine_requests', fn () => redirect()
        ->route('staff.dispense')
        ->with('info', 'Medicine requests are recorded through Medicine Dispensing.'))
    ->name('staff.medicine_requests');

/** user/request_medicine.php - stub: flash + redirect to the user dashboard. */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/request_medicine', fn () => redirect()
        ->route('user.dashboard')
        ->with('info', 'Please visit the clinic. Staff will enter your medicine request.'))
    ->name('user.request_medicine');

/*
|--------------------------------------------------------------------------
| Inventory (Phase 15) - staff/inventory.php + staff/deduction_logs.php
|--------------------------------------------------------------------------
|
| The inventory page is a single self-posting endpoint: stock receiving and
| batch delete run off POST in the original's exact order (a failed POST
| falls through to the same GET rendering). deduction_logs.php checks no
| request method and performs no writes, so it stays a plain GET like
| staff/patients (Phase 6 convention). The two legacy URLs are method-blind
| compatibility stubs - require_login, flash, redirect, in that order - so
| both accept GET and POST and redirect through their target route with the
| original info message (written but never rendered - write-only legacy
| slot, see partials/flash.blade.php).
|
*/

/** staff/inventory.php - batch receiving + delete (self-posting). */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/inventory', [InventoryController::class, 'index'])
    ->name('staff.inventory');

/** staff/deduction_logs.php - OUT stock movements tied to dispense refs. */
Route::middleware(['auth', 'role:staff'])
    ->get('/staff/deduction_logs', [DeductionLogsController::class, 'index'])
    ->name('staff.deduction_logs');

/** staff/stock_adjust.php - stub: flash + redirect to unified inventory. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/stock_adjust', fn () => redirect()
        ->route('staff.inventory')
        ->with('info', 'The clinic now uses the unified Inventory Operations page for stock receiving and adjustments.'))
    ->name('staff.stock_adjust');

/** staff/history_logs.php - stub: flash + redirect to combined history. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/history_logs', fn () => redirect()
        ->route('staff.dispense', ['history_status' => 'all'])
        ->with('info', 'The clinic now uses the combined Dispensing & History screen for medicine and equipment activity.'))
    ->name('staff.history_logs');

/*
|--------------------------------------------------------------------------
| Equipment (Phase 16) - admin/equipment.php + staff/equipment_loans.php
|--------------------------------------------------------------------------
|
| Both originals are single self-posting endpoints behind require_login
| with one role each. The equipment page runs create/update off POST and
| ?action=delete/edit off GET in the reference's exact order (a failed
| POST falls through to the same GET rendering); it carries no CSRF field
| of its own, so only the global middleware applies there - a token-less
| POST raises 419 (approved Phase 6 strengthening). The loans page runs
| issue and return off POST (its own CSRF check maps to 419) before the
| status-filtered list renders.
|
| The two user URLs are method-blind compatibility stubs - require_login,
| flash, redirect, in that order - so both accept GET and POST and carry
| the original info message (written but never rendered: write-only
| legacy slot, see partials/flash.blade.php). my_loans targets its legacy
| destination URL; only the redirect itself is what the reference does
| today.
|
*/

/** admin/equipment.php - equipment CRUD (self-posting) + ?action=delete/edit. */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/equipment', [EquipmentController::class, 'index'])
    ->name('admin.equipment');

/** staff/equipment_loans.php - issue + return + status-filtered loan list. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/equipment_loans', [EquipmentLoansController::class, 'index'])
    ->name('staff.equipment_loans');

/** user/borrow_equipment.php - stub: flash + redirect to the user dashboard. */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/borrow_equipment', fn () => redirect()
        ->route('user.dashboard')
        ->with('info', 'Equipment services are processed by clinic staff in person.'))
    ->name('user.borrow_equipment');

/** user/my_loans.php - stub: flash + redirect to My Activity (legacy target URL). */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/my_loans', fn () => redirect()
        ->to(url('user/my_requests'))
        ->with('info', 'Equipment history is now available in My Activity.'))
    ->name('user.my_loans');

/*
|--------------------------------------------------------------------------
| My Activity (Phase 17) - user/my_requests.php
|--------------------------------------------------------------------------
|
| Read-only four-card history overview behind require_login(['user']): the
| optional medicine-status whitelist (dispensed/rejected only - anything
| else adds no WHERE clause) runs off GET, POST renders the same page, so
| the route is method-blind like the reference. Nothing here writes.
|
*/

/** user/my_requests.php - My Activity: medicine/equipment/consultation/sick-leave history. */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/my_requests', [MyRequestsController::class, 'index'])
    ->name('user.my_requests');

/*
|--------------------------------------------------------------------------
| History (Phase 18) - staff/history.php
|--------------------------------------------------------------------------
|
| Full, unbounded seven-section history behind require_login(['staff']).
| ?tab= picks the section (unknown keys fall back to the first - patients);
| the shared StaffHistory builders the dashboard already previews with
| render rows and tables, so preview and full history can never drift.
| Read-only GET page (its form is a GET form); a token-less POST raises
| 419 via the global middleware - approved Phase 6 strengthening, the
| reference ignores POST entirely.
|
*/

/** staff/history.php - seven-section searchable history with ?tab= switching. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/history', [StaffHistoryController::class, 'index'])
    ->name('staff.history');

/*
|--------------------------------------------------------------------------
| Reports (Phase 30) - staff/reports.php
|--------------------------------------------------------------------------
|
| Staff-only reports behind require_login(['staff']) - the staff twin of
| Phase 27's admin/reports route: GET only (the period form submits GET;
| any POST is rejected by routing as 405 - ACCEPTED PARITY DIFFERENCE,
| Phase 22/27 precedent - the reference has no POST branch and would just
| render), the ported report_range() plus DATE() BETWEEN aggregate family,
| the staff dashboard's two chart queries and the 7/14/30-day ClinicTrend
| ranges the inline IIFE consumes. The reference's $topPatients query
| never reaches its view (dead query - dropped per the Phase 27 $lowStock
| precedent).
|
*/

/** staff/reports.php - staff reports overview (range form, metrics, reason tables, charts). */
Route::middleware(['auth', 'role:staff'])
    ->get('/staff/reports', [StaffReportsController::class, 'index'])
    ->name('staff.reports');

/*
|--------------------------------------------------------------------------
| Account Settings (Phase 29) - staff/settings.php
|--------------------------------------------------------------------------
|
| The staff-only security half behind require_login(['staff']). Same shape
| as Phase 20's user/settings route with one structural difference: the
| reference inlines the password form in the Security card (no modal), so
| the top-level error block renders unconditionally - there is no reopen
| logic. The Account card reads users.* verbatim; the handler
| (form_action=password) runs the exact three-message validation, updates
| password_hash via Hash::make and PRG-redirects. A token-less POST raises
| 419 (approved Phase 6 strengthening - the reference soft-fails it into
| $errors and skips the verify instead).
|
*/

/** staff/settings.php - Account + Security (password change) for role:staff. */
Route::middleware(['auth', 'role:staff'])
    ->match(['get', 'post'], '/staff/settings', [StaffSettingsController::class, 'index'])
    ->name('staff.settings');

/*
|--------------------------------------------------------------------------
| Profile (Phase 19) - user/profile.php
|--------------------------------------------------------------------------
|
| The real profile screen for EVERY role behind bare require_login() (no
| role allow-list): all four roles keep their details in the same
| profiles / student_details / employee_details tables. GET renders the
| prefilled form; POST runs the original's exact two-field validation,
| updates the profile row plus the account-type's detail row and
| PRG-redirects with the flash the reference sets (no renderer, kept for
| session parity). Security lives on <role>/settings.php - nothing is
| shared between the two pages beyond the layout shell. A token-less POST
| raises 419 (approved Phase 6 strengthening - the reference soft-fails
| it into $errors and blocks the write instead).
|
*/

/** user/profile.php - own personal information, open to all four roles. */
Route::middleware(['auth'])
    ->match(['get', 'post'], '/user/profile', [ProfileController::class, 'index'])
    ->name('user.profile');

/*
|--------------------------------------------------------------------------
| Notifications (Phase 31) - notifications.php
|--------------------------------------------------------------------------
|
| The centre itself, bare require_login() (open to all four roles, no role
| allow-list). GET lists the CURRENT user's rows only (user_id scoped,
| created_at DESC, LIMIT 100) with ?filter=unread narrowing; the filter
| buttons keep the raw filter string so junk leaves both outline. POST
| handles mark_all_read (flash with the affected-row count) and mark_read
| (id + user_id + is_read=0 guard; id=0 still redirects) with PRG;
| unknown/missing action values fall through and render - original lines
| 19-34 semantics kept. A token-less POST raises 419 (approved Phase 6
| strengthening - the reference soft-fails it into a flash + redirect).
|
*/

/** notifications.php - notification centre, open to all four roles. */
Route::middleware(['auth'])
    ->match(['get', 'post'], '/notifications', [NotificationsController::class, 'index'])
    ->name('notifications');

/*
|--------------------------------------------------------------------------
| Notification Preferences (Phase 32) - notifications_preferences.php
|--------------------------------------------------------------------------
|
| The switch board itself, bare require_login() (open to all four roles).
| GET renders the three display groups with $prefs[$type] ?? true - an
| absent notification_settings row reads as ENABLED, so the unchecked
| state only ever comes from an explicit is_enabled = 0 row. POST writes
| ALL eleven switches (missing pref_* field = disabled, isset() === '1'
| semantics of original line 34) through the portable upsert on the
| (user_id, notification_type) unique key - is_enabled only, updated_at
| untouched (original's ON DUPLICATE KEY clause) - then PRG-redirects
| with the reference's flash. A token-less POST raises 419 (approved
| Phase 6 strengthening - the reference soft-fails it into a flash +
| redirect). activePath 'notifications' is inert: the sidebar computes
| active with request()->is(), never ShellContext::isActive.
|
*/

/** notifications_preferences.php - per-user switch board, open to all four roles. */
Route::middleware(['auth'])
    ->match(['get', 'post'], '/notifications_preferences', [NotificationPreferencesController::class, 'index'])
    ->name('notifications.preferences');

/*
|--------------------------------------------------------------------------
| Account Settings (Phase 20) - user/settings.php
|--------------------------------------------------------------------------
|
| The security half of the user area behind require_login(['user']) - the
| role:user bucket holds students AND employees, while admin/staff bounce
| to their own dashboards. The Account card reads users.* verbatim and the
| password handler (form_action=password) runs the reference's exact
| three-message validation, updates password_hash via Hash::make and
| PRG-redirects. Errors reopen the modal exactly like the original ($reopen
| gates the top alert block so messages render once, in the modal). A
| token-less POST raises 419 (approved Phase 6 strengthening - the
| reference soft-fails it into $errors and skips the verify instead).
|
*/

/** user/settings.php - Account + Security (password change) for role:user. */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/settings', [UserSettingsController::class, 'index'])
    ->name('user.settings');

/*
|--------------------------------------------------------------------------
| Medical Form (Phase 21) - user/medical_form.php
|--------------------------------------------------------------------------
|
| Three handlers on one URL behind require_login(['user']), exactly like the
| reference: form_action=record upserts the semester row on the
| (user_id, school_year, semester) unique key, form_action=add_allergy
| inserts into user_allergies, and GET ?action=delete_allergy&id=N deletes an
| OWNED allergy (the reference's GET state change - the JS data-confirm guard
| only). Errors render twice (top block AND allergy-modal block) and reopen
| the allergy modal for any failed submit - legacy quirks kept. Token-less
| POST -> 419 (approved Phase 6 strengthening - the reference hard-redirects
| with header()+exit instead).
|
*/

/** user/medical_form.php - semester medical record + allergy registry. */
Route::middleware(['auth', 'role:user'])
    ->match(['get', 'post'], '/user/medical_form', [MedicalFormController::class, 'index'])
    ->name('user.medical_form');

/*
|--------------------------------------------------------------------------
| Audit Logs (Phase 22) - admin/audit_logs.php
|--------------------------------------------------------------------------
|
| Read-only GET page behind require_login(['admin']): optional trimmed
| free-text search over username/action/details ANDed with an optional exact
| action filter, LEFT JOIN for the username (NULL -> 'system' in the view),
| newest first, LIMIT 500. GET-only like the other read-only pages
| (admin/dashboard) because the reference form is method="get" and the script
| has no POST branch; a POST therefore answers 405 instead of re-rendering.
|
*/

/** admin/audit_logs.php - searchable audit trail table. */
Route::middleware(['auth', 'role:admin'])
    ->get('/admin/audit_logs', [AdminAuditLogsController::class, 'index'])
    ->name('admin.audit_logs');

/*
|--------------------------------------------------------------------------
| User Accounts (Phase 23) - admin/users.php
|--------------------------------------------------------------------------
|
| One URL behind require_login(['admin']) with three GET branches (list /
| edit / legacy ?action=delete state change) and two POST handlers (save /
| toggle). save/toggle PRG; a failed save falls through to the form with
| errors, exactly like the reference. Token-less POST -> 419 (approved
| Phase 6 strengthening - the reference soft-fails invalid CSRF into
| flash + redirect; its toggle form and New-User modal form carry no token
| field at all and dead-end there, while the edit form carries one).
|
*/

/** admin/users.php - user account management (list/create/edit/toggle/delete). */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/users', [AdminUsersController::class, 'index'])
    ->name('admin.users');

/*
|--------------------------------------------------------------------------
| System Settings (Phase 24) - admin/settings.php
|--------------------------------------------------------------------------
|
| GET renders the two settings cards from system_settings; POST blindly
| upserts the 8 whitelisted keys (trimmed, no validation - the reference's
| $errors is never populated), logs update_settings and PRG. The reference
| page performs NO CSRF check at all and its form carries no token field -
| the global middleware answers 419 on token-less POSTs instead (approved
| Phase 6 strengthening; the view carries @csrf so the form saves as in the
| reference). Browser QA never sends a valid token here: it would write
| live system_settings - the save paths are Feature-test only.
|
*/

/** admin/settings.php - system settings (school/clinic info, academic period, limits). */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/settings', [AdminSettingsController::class, 'index'])
    ->name('admin.settings');

/*
|--------------------------------------------------------------------------
| Medicine Categories (Phase 25) - admin/categories.php
|--------------------------------------------------------------------------
|
| One URL behind role:admin: GET list/search + legacy ?action=delete (PRG
| redirect here, unlike admin/users.php's fall-through) + ?action=edit
| (modal renders CLOSED with prefills - the reference has no reopen script
| for it). POST save does the required-name check, the per-type duplicate
| check and the insert/update with PRG. The toolbar's type select submits
| ?type=... but the reference SQL never filters on it - only the raw
| selected-state reads it. The reference form carries no token and the page
| runs no CSRF check; the view carries @csrf and the middleware answers 419
| otherwise (approved Phase 6 strengthening). Browser QA never sends a valid
| token: a successful save would write live medicine_categories.
|
*/

/** admin/categories.php - medicine category management (list/create/edit/delete). */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/categories', [AdminCategoriesController::class, 'index'])
    ->name('admin.categories');

/*
|--------------------------------------------------------------------------
| Suppliers (Phase 26) - admin/suppliers.php
|--------------------------------------------------------------------------
|
| Same single-URL shape as admin/categories.php: GET list/search over five
| columns + legacy ?action=delete (PRG) + ?action=edit (closed-modal
| prefills); POST save validates ONLY that the name is present - the
| reference has no duplicate check here. The reference form carries no token
| and the page runs no CSRF check; the view carries @csrf and the middleware
| answers 419 otherwise (approved Phase 6 strengthening). Browser QA never
| sends a valid token: a successful save would write live suppliers.
|
*/

/** admin/suppliers.php - supplier management (list/create/edit/delete). */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/suppliers', [AdminSuppliersController::class, 'index'])
    ->name('admin.suppliers');

/*
|--------------------------------------------------------------------------
| Reports overview (Phase 27) - admin/reports.php
|--------------------------------------------------------------------------
|
| GET only (the reference has no POST branch - the period form submits GET).
| The range comes from the ported report_range() helper: period whitelist
| (bogus -> monthly), truthy from+to pair goes through strtotime()/date()
| with NO validation (garbage becomes 1970-01-01), otherwise period-derived
| defaults. Seven aggregates are DATE(col)-scoped; the reference's $lowStock
| query never reaches its own view - dead code dropped (Phase 22/25
| precedent). Chart JSON is echoed unescaped in the reference and stays raw
| via {!! !!} here.
|
*/

/** admin/reports.php - admin reports overview (range form, metrics, tables, charts). */
Route::middleware(['auth', 'role:admin'])
    ->get('/admin/reports', [AdminReportsController::class, 'index'])
    ->name('admin.reports');

/*
|--------------------------------------------------------------------------
| Database backup (Phase 28) - admin/backup.php
|--------------------------------------------------------------------------
|
| GET renders the whitelist checkbox form + Recent Backups list; POST runs
| the export into project-root/backups (base_path - the structural mirror of
| the reference's dirname(__DIR__) . '/backups') and re-renders DIRECTLY -
| the reference has no PRG here, so $success lands on the same response.
| Only whitelisted tables are exported, in the reference's order. The
| reference form carries no token and the page runs no CSRF check; the view
| carries @csrf and the middleware answers 419 otherwise (approved Phase 6
| strengthening). Browser QA writes and then removes its own backup file.
|
*/

/** admin/backup.php - database backup generation (whitelisted tables). */
Route::middleware(['auth', 'role:admin'])
    ->match(['get', 'post'], '/admin/backup', [AdminBackupController::class, 'index'])
    ->name('admin.backup');

/*
|--------------------------------------------------------------------------
| Landing route - port of index.php
|--------------------------------------------------------------------------
|
| The original index.php is a role router: signed-in visitors are sent to
| their role dashboard, everyone else to the login page. The Phase 4
| preview routes (GET / rendering the shell for a chosen identity, and
| GET /_preview/*) were deleted together with ShellPreviewController once
| the authenticated shell was browser-verified in Phase 5.
|
*/

/** GET / - index.php: role router (dashboard) or login page. */
Route::get('/', [DashboardController::class, 'landing'])->name('home');

/*
|--------------------------------------------------------------------------
| Notification AJAX API (Phase 34) - api/*
|--------------------------------------------------------------------------
|
| Three session endpoints the shared assets/js/main.js calls directly - the
| URLs must keep their .php spelling because main.js builds
| appBase + '/api/notifications_sse.php' / '/api/notifications.php':
|
|   api/notifications.php   GET fetch / PATCH mark-one / POST action
|                           (mark_all_read | mark_read); every other
|                           action or method falls through to
|                           400 {"error":"Invalid request"} in the original,
|                           so both routes register Route::any and the
|                           controller dispatches on the method instead of
|                           letting Laravel answer 405 for PUT/DELETE.
|   api/notifications_sse.php
|                           long-lived SSE stream (init event, keep-alives,
|                           55s ping) for the topbar bell.
|   api/process_notification_queue.php
|                           CLI-only script: a web request dies 403
|                           "Forbidden" before anything else. The queue
|                           drain itself is the artisan command
|                           notification:process-queue (routes/console.php).
|
| require_login() becomes the auth middleware on the two session endpoints
| (guests get the login redirect); the queue script checks the SAPI first,
| so its route stays public. The original prints no token on this JSON
| surface; the web group keeps the CSRF check (approved Phase 6
| strengthening) and the port's main.js rides the XSRF-TOKEN cookie along
| as the X-XSRF-TOKEN header on its mark-read POST.
|
*/

/** ANY /api/notifications.php - AJAX notification API (GET/PATCH/POST + 400 fall-through). */
Route::middleware('auth')->any('/api/notifications.php', [NotificationApiController::class, 'handle']);

/** ANY /api/notifications_sse.php - Server-Sent Events stream for the bell. */
Route::middleware('auth')->any('/api/notifications_sse.php', [NotificationSseController::class, 'handle']);

/** ANY /api/process_notification_queue.php - web access always 403 (CLI-only script). */
Route::any('/api/process_notification_queue.php', [ProcessNotificationQueueController::class, 'handle']);
