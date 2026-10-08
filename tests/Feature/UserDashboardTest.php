<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 9 user (student/employee) dashboard tests against the isolated
 * in-memory SQLite connection that phpunit.xml selects.
 *
 * Covers user/dashboard.php: the four personal metric cards (lines 52-66),
 * the medical-form warning and unread-notification alerts (lines 80-91),
 * My Tasks with its allergy box (lines 100-119), the employee-only Recent
 * Sick Leave list (lines 121-152), the sick-leave modal POST handler with
 * its exact validation messages (lines 16-45) and the overdue-loan
 * notification sweep that runs before every render (line 12).
 *
 * Legacy behaviours preserved: the backend does not check account_type, so
 * a crafted student POST still inserts (no employee gate in the original);
 * a POST without form_action=sick_leave falls through to a plain render.
 */
class UserDashboardTest extends TestCase
{
    /** Password used by every seeded account. */
    private const PASSWORD = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('email', 120)->unique();
            $table->string('password_hash');
            $table->string('role', 20)->default('user');
            $table->string('account_type', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_no', 30)->nullable();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_contact', 30)->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->string('reference_id', 50)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'is_read']);
        });

        // The tables user/dashboard.php reads - shapes mirror the live
        // schema (Phase 8/9 information_schema probes, PatientTest).
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('blood_type', 5)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->text('allergies_text')->nullable();
            $table->text('existing_conditions')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('immunizations')->nullable();
            $table->string('school_year', 20);
            $table->string('semester', 10);
            $table->timestamp('submitted_at')->useCurrent();
        });

        Schema::create('user_allergies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('allergen_name', 150);
            $table->string('severity', 10)->default('mild');
            $table->string('notes', 255)->nullable();
        });

        Schema::create('sick_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('attachment', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
            $table->boolean('allergy_flag')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->text('complaint')->nullable();
            $table->string('blood_pressure', 20)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('pulse')->nullable();
            $table->integer('respiration')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('consultation_date')->useCurrent();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->integer('total_qty')->default(1);
            $table->integer('available_qty')->default(1);
            $table->string('condition_note', 120)->default('good');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('equipment_id');
            $table->integer('quantity')->default(1);
            $table->date('borrow_date');
            $table->timestamp('borrowed_at')->useCurrent();
            $table->date('due_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 20)->default('borrowed');
            $table->string('condition_on_return', 120)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    // ---------------------------------------------------------------------
    // Metric cards, alerts and role-dependent widgets (original 80-152)
    // ---------------------------------------------------------------------

    public function test_student_dashboard_renders_counts_alerts_and_no_employee_widgets(): void
    {
        $student = $this->makeUser('student01', 'student');
        DB::table('medical_records')->insert([
            'user_id' => $student->id, 'school_year' => '2026-2027', 'semester' => '1st',
        ]);

        // 1 request, 2 loans (1 borrowed + 1 returned), 1 consultation.
        $this->makeRequest($student->id);
        $this->makeLoan($student->id, $this->makeEquipment('Treadmill'), 1, date('Y-m-d'), date('Y-m-d H:i:s'));
        $this->makeLoan($student->id, $this->makeEquipment('Chair'), 1, date('Y-m-d', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'returned');
        DB::table('consultations')->insert([
            'patient_id' => $student->id, 'complaint' => 'Cough', 'diagnosis' => 'Colds',
        ]);

        $response = $this->actingAs($student)->get('/user/dashboard');

        $response->assertOk();
        $response->assertSee('My Dashboard');
        // Metric cards in original order, values bound to their labels
        // (the markup is one line per card, so adjacency is asserted).
        $response->assertSee('<div class="metric-value">1</div><div class="metric-label">Total Requests</div>', false);
        $response->assertSee('<div class="metric-value">2</div><div class="metric-label">Total Loans</div>', false);
        $response->assertSee('<div class="metric-value">1</div><div class="metric-label">Consultations</div>', false);
        $response->assertSee('<div class="metric-value">1</div><div class="metric-label">Active Loans</div>', false);

        // Medical record exists => no warning; allergies 0 => allergy box;
        // unread 0 => no notification alert.
        $response->assertDontSee('You have not submitted your medical form yet.');
        $response->assertSee('You have no recorded allergies.');
        $response->assertDontSee('unread notification');

        // Tasks card takes the full width for students.
        $response->assertSee('<div class="col-12">', false);
        $response->assertSee('Update Medical Form');
        $response->assertSee('Open My Activity');
        $response->assertSee('View My Consultations');

        // No employee widgets at all.
        $response->assertDontSee('Notify Staff (Sick Leave)');
        $response->assertDontSee('Recent Sick Leave');
        $response->assertDontSee('id="sickLeaveModal"');
        $this->assertSame(0, substr_count($response->getContent(), 'class="modal fade"'));
    }

    public function test_employee_dashboard_shows_medical_warning_sick_list_and_modal(): void
    {
        $employee = $this->makeUser('employee01', 'employee');

        DB::table('sick_leaves')->insert([
            [
                'user_id' => $employee->id, 'reason' => 'Flu and fever',
                'start_date' => date('Y-m-d', strtotime('-3 days')), 'end_date' => date('Y-m-d', strtotime('-2 days')),
                'status' => 'pending', 'reviewed_by' => null, 'review_note' => null,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            ],
            [
                'user_id' => $employee->id, 'reason' => 'Migraine',
                'start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => null,
                'status' => 'approved', 'reviewed_by' => 99, 'review_note' => 'Looks fine',
                'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            ],
        ]);
        DB::table('user_allergies')->insert([
            'user_id' => $employee->id, 'allergen_name' => 'Peanuts', 'severity' => 'severe',
        ]);

        $response = $this->actingAs($employee)->get('/user/dashboard');

        $response->assertOk();
        // No medical record on file => warning alert; allergy recorded => no box.
        $response->assertSee('You have not submitted your medical form yet.');
        $response->assertDontSee('You have no recorded allergies.');
        $response->assertDontSee('unread notification');

        // Tasks card is halved because a recent sick leave exists.
        $response->assertSee('<div class="col-lg-6">', false);

        // Recent Sick Leave: newest first, date line, reason, both badges.
        $response->assertSee('Recent Sick Leave');
        $response->assertSee('Flu and fever');
        $response->assertSee('Migraine');
        $response->assertSee('<span class="badge bg-warning text-dark">Pending</span>', false);
        $response->assertSee('<span class="badge bg-success">Approved</span>', false);
        $response->assertSee('View Full History');

        // Modal with its form, no validation errors and no auto-open script.
        $response->assertSee('id="sickLeaveModal"', false);
        $response->assertSee('name="form_action" value="sick_leave"', false);
        $response->assertSee('<textarea name="reason"', false);
        $response->assertSee('name="start_date"', false);
        $response->assertSee('name="end_date"', false);
        $response->assertSee('_token'); // @csrf (approved convention)
        $response->assertDontSee('alert alert-danger py-2');
        $response->assertDontSee("document.getElementById('sickLeaveModal')");
        $this->assertSame(1, substr_count($response->getContent(), 'class="modal fade"'));
    }

    // ---------------------------------------------------------------------
    // Unread notification alert (original lines 86-91)
    // ---------------------------------------------------------------------

    public function test_unread_notification_alert_counts_and_pluralizes(): void
    {
        $student = $this->makeUser('student01', 'student');
        $this->makeNotification($student->id, true);
        $this->makeNotification($student->id, true);
        $this->makeNotification($student->id, false); // already read

        $response = $this->actingAs($student)->get('/user/dashboard');
        $response->assertOk();
        $response->assertSee('You have 2 unread notifications.', false);

        // Read the oldest unread => exactly one remains.
        $firstUnread = DB::table('notifications')
            ->where('user_id', $student->id)->where('is_read', 0)->min('id');
        DB::table('notifications')->where('id', $firstUnread)->update(['is_read' => 1]);

        $this->actingAs($student)->get('/user/dashboard')
            ->assertOk()
            ->assertSee('You have 1 unread notification.', false);
    }

    // ---------------------------------------------------------------------
    // Sick-leave POST: success path (original lines 34-44)
    // ---------------------------------------------------------------------

    public function test_valid_sick_leave_post_inserts_redirects_and_flashes(): void
    {
        $employee = $this->makeUser('employee01', 'employee');

        // Feature 2 (scoping): clinic staff are the audience for a new sick
        // leave request - the employee who filed it is the subject, not a
        // recipient. Created before the POST so the fan-out has a target.
        $clinicStaff = User::create([
            'username' => 'staff01',
            'email' => 'staff01@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'active',
        ]);

        $response = $this->actingAs($employee)->post('/user/dashboard', [
            'form_action' => 'sick_leave',
            'reason' => '  Fever and cough  ',
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-12',
        ]);

        $response->assertRedirect(route('user.dashboard'));

        $row = DB::table('sick_leaves')->where('user_id', $employee->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Fever and cough', $row->reason); // trimmed like the original
        $this->assertSame('2026-10-10', $row->start_date);
        $this->assertSame('2026-10-12', $row->end_date);
        $this->assertSame('pending', $row->status);

        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $clinicStaff->id, (int) $note->user_id);
        $this->assertSame('sick_leave', $note->type);
        $this->assertSame((int) $row->id, (int) $note->reference_id);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $employee->id)->count());

        // PRG: the follow-up GET shows the flash (shared flash partial) and
        // the new pending item in the Recent Sick Leave list.
        // The follow-up GET shows the new Pending row. The original's flash
        // slot is write-only (no renderer exists - see partials/flash.blade.php),
        // so the success message stays in the session but renders nothing.
        $this->actingAs($employee)->get('/user/dashboard')
            ->assertOk()
            ->assertDontSee('Sick leave reported. The clinic staff has been notified.')
            ->assertSee('<span class="badge bg-warning text-dark">Pending</span>', false);

        // Second submission without an end date stores NULL end_date.
        $this->actingAs($employee)->post('/user/dashboard', [
            'form_action' => 'sick_leave',
            'reason' => 'Migraine',
            'start_date' => '2026-11-01',
            'end_date' => '',
        ])->assertRedirect(route('user.dashboard'));

        $this->assertSame(2, DB::table('sick_leaves')->count());
        $this->assertNull(
            DB::table('sick_leaves')->where('reason', 'Migraine')->value('end_date'),
        );
    }

    // ---------------------------------------------------------------------
    // Sick-leave POST: validation (original lines 28-32, exact messages)
    // ---------------------------------------------------------------------

    public function test_sick_leave_validation_collects_every_error_in_original_order(): void
    {
        $employee = $this->makeUser('employee01', 'employee');

        $response = $this->actingAs($employee)->post('/user/dashboard', [
            'form_action' => 'sick_leave',
            'reason' => '',
            'start_date' => '',
            'end_date' => 'not-a-date',
        ]);

        $response->assertOk();
        // All errors are collected, in the original's order (end-format is
        // reached because end_date is non-empty; the range check is skipped
        // because start_date is empty).
        $response->assertSee('<div class="alert alert-danger py-2">Please describe your reason for leave.</div>', false);
        $response->assertSee('<div class="alert alert-danger py-2">Start date is required.</div>', false);
        $response->assertSee('<div class="alert alert-danger py-2">Invalid end date format.</div>', false);
        $response->assertDontSee('Invalid start date format.');
        $response->assertDontSee('End date cannot be before start date.');
        $this->assertSame(3, substr_count($response->getContent(), 'alert alert-danger py-2'));
        $this->assertSame(0, DB::table('sick_leaves')->count());
        // Modal auto-opens when the re-render carries errors.
        $response->assertSee("<script>document.addEventListener('DOMContentLoaded', function() { var modal = document.getElementById('sickLeaveModal'); if (modal && window.bootstrap) new bootstrap.Modal(modal).show(); });</script>", false);
    }

    public function test_sick_leave_format_and_range_messages_match_the_original(): void
    {
        $employee = $this->makeUser('employee01', 'employee');
        $post = fn (array $extra) => $this->actingAs($employee)->post('/user/dashboard', $extra + [
            'form_action' => 'sick_leave',
            'reason' => 'Valid reason',
        ]);

        // Invalid start format.
        $post(['start_date' => 'abc', 'end_date' => ''])
            ->assertOk()
            ->assertSee('<div class="alert alert-danger py-2">Invalid start date format.</div>', false)
            ->assertDontSee('Start date is required.');

        // Invalid end format.
        $post(['start_date' => '2026-10-10', 'end_date' => 'abc'])
            ->assertOk()
            ->assertSee('<div class="alert alert-danger py-2">Invalid end date format.</div>', false)
            ->assertDontSee('End date cannot be before start date.');

        // Range check is the original's string comparison (end < start).
        $post(['start_date' => '2026-10-10', 'end_date' => '2026-10-05'])
            ->assertOk()
            ->assertSee('<div class="alert alert-danger py-2">End date cannot be before start date.</div>', false)
            ->assertDontSee('Invalid start date format.');

        $this->assertSame(0, DB::table('sick_leaves')->count());
    }

    // ---------------------------------------------------------------------
    // Overdue loan sweep (original line 12 -> functions.php:201)
    // ---------------------------------------------------------------------

    public function test_overdue_loan_sweep_creates_one_notification_and_counts_it(): void
    {
        $employee = $this->makeUser('employee01', 'employee');
        $treadmill = $this->makeEquipment('Treadmill');

        // Overdue: due yesterday, still borrowed.
        $loanId = $this->makeLoan($employee->id, $treadmill, 1, date('Y-m-d', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'borrowed', date('Y-m-d', strtotime('-1 day')));
        // Due today is NOT overdue (strict due_date < CURDATE()).
        $this->makeLoan($employee->id, $treadmill, 1, date('Y-m-d'), date('Y-m-d H:i:s'), 'borrowed', date('Y-m-d'));

        $response = $this->actingAs($employee)->get('/user/dashboard');

        $response->assertOk();
        $response->assertSee('You have 1 unread notification.', false);

        $this->assertSame(1, DB::table('notifications')->where('type', 'overdue_loan')->count());
        $note = DB::table('notifications')->where('type', 'overdue_loan')->first();
        $this->assertSame($employee->id, (int) $note->user_id);
        $this->assertSame($loanId, (int) $note->loan_id);
        $this->assertSame('Overdue Equipment Loan', $note->title);
        $this->assertSame(
            "Your loan of 'Treadmill' was due on ".date('M d, Y', strtotime('-1 day')).' and is now overdue. Please return it to the clinic.',
            $note->message,
        );
        $this->assertSame(0, (int) $note->is_read);

        // Second load must not duplicate the alert (existence check first).
        $this->actingAs($employee)->get('/user/dashboard')->assertOk();
        $this->assertSame(1, DB::table('notifications')->where('type', 'overdue_loan')->count());
    }

    // ---------------------------------------------------------------------
    // Legacy quirks preserved: no account_type gate, unknown form_action
    // ---------------------------------------------------------------------

    public function test_crafted_student_post_is_still_accepted_like_the_original(): void
    {
        $student = $this->makeUser('student01', 'student');

        // The original backend never checks account_type - only the UI hides
        // the button - so a student's crafted POST inserts a sick leave.
        $this->actingAs($student)->post('/user/dashboard', [
            'form_action' => 'sick_leave',
            'reason' => 'Crafted',
            'start_date' => '2026-12-01',
            'end_date' => '',
        ])->assertRedirect(route('user.dashboard'));

        $this->assertSame(1, DB::table('sick_leaves')->count());
        $this->assertSame($student->id, (int) DB::table('sick_leaves')->value('user_id'));
    }

    public function test_post_without_form_action_falls_through_to_a_plain_render(): void
    {
        $employee = $this->makeUser('employee01', 'employee');

        // Original: the handler only runs when form_action === 'sick_leave';
        // anything else renders the page (the textarea still echoes $_POST).
        $response = $this->actingAs($employee)->post('/user/dashboard', [
            'reason' => 'Not a sick leave',
        ]);

        $response->assertOk();
        $response->assertSee('My Dashboard');
        $response->assertSee('>Not a sick leave</textarea>', false);
        $response->assertDontSee('alert alert-danger py-2');
        $this->assertSame(0, DB::table('sick_leaves')->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'first_name' => 'Test',
            'last_name' => 'Person',
        ]);

        return $user->fresh();
    }

    private function makeNotification(int $userId, bool $unread): int
    {
        return DB::table('notifications')->insertGetId([
            'user_id' => $userId,
            'type' => 'info',
            'title' => 'Test notice',
            'message' => 'Body of the test notice.',
            'is_read' => $unread ? 0 : 1,
        ]);
    }

    private function makeRequest(int $userId): int
    {
        return DB::table('medicine_requests')->insertGetId([
            'user_id' => $userId,
            'medicine_id' => 1,
            'quantity' => 1,
            'status' => 'dispensed',
        ]);
    }

    private function makeEquipment(string $name): int
    {
        return DB::table('equipment')->insertGetId([
            'name' => $name,
            'total_qty' => 5,
            'available_qty' => 4,
        ]);
    }

    private function makeLoan(
        int $userId,
        int $equipmentId,
        int $quantity,
        string $borrowDate,
        string $borrowedAt,
        string $status = 'borrowed',
        ?string $dueDate = null,
    ): int {
        return DB::table('equipment_loans')->insertGetId([
            'user_id' => $userId,
            'equipment_id' => $equipmentId,
            'quantity' => $quantity,
            'borrow_date' => $borrowDate,
            'borrowed_at' => $borrowedAt,
            'due_date' => $dueDate,
            'status' => $status,
            'return_date' => $status === 'returned' ? date('Y-m-d') : null,
        ]);
    }
}
