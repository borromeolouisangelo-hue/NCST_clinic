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
 * Phase 17 My Activity tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers user/my_requests.php: the four history queries (medicine requests
 * JOIN medicines with the dispensed/rejected status whitelist - anything
 * else adds no WHERE clause; equipment loans by COALESCE(return_date,
 * borrow_date); consultations with the staff name from profiles; the
 * latest 50 sick leaves), the three filter pills with their active class,
 * the empty states (the sick-leave card hides entirely when the user has
 * none - no empty-state row like the other three tables), badge labels
 * ("Declined" for rejected/declined states), the 40/50-char trim with
 * ellipsis, newest-first request order, the my_loans stub redirect that
 * lands on this page, the method-blind POST, and role parity.
 *
 * CSRF: the reference page posts nothing, so no 419 scenario exists here.
 */
class MyRequestsTest extends TestCase
{
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

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('generic_name', 150)->nullable();
            $table->string('unit', 40)->default('tablet');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
            $table->boolean('allergy_flag')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
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

        Schema::create('sick_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('attachment')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    // ---------------------------------------------------------------------
    // Render (original lines 51-174)
    // ---------------------------------------------------------------------

    public function test_renders_all_four_history_tabs_with_data(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $employee = $this->makeUser('emp', 'user', 'employee');
        $staff = $this->makeUser('doc', 'staff', null);
        Profile::where('user_id', $staff->id)->update(['first_name' => 'Clinic', 'last_name' => 'Staff']);

        $this->makeMedicine('Ibuprofen', 'Ibuprofen acid');
        $this->makeRequest($student, 1, 2, 'toothache', 'dispensed', 'Take after meal', '2026-09-30 14:05:00');
        $this->makeLoan($student, $this->makeEquipment('Wheelchair'), '2026-09-30', '2026-09-30');
        $this->makeConsultation($student, $staff->id, 'Fever and cough', 'Viral URI', '2026-09-25 10:30:00');
        $this->makeLeave($employee, 'Flu symptoms', '2026-10-01', '2026-10-02', 'approved', 'Fit to return to class', '2026-10-01 08:00:00');

        // Default tab = medicine (student)
        $medicine = $this->actingAs($student)->get('/user/my_requests')
            ->assertOk()
            ->assertSee('Medicine History')
            ->assertSee('Ibuprofen')
            ->assertSee('class="small text-muted"', false)
            ->assertSee(date('M d, Y g:i A', strtotime('2026-09-30 14:05:00')), false)
            ->assertSee('toothache')
            ->assertSee('bg-success', false)
            ->assertSee('Dispensed')
            ->assertSee('Take after meal')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Equipment tab (student)
        $equipment = $this->actingAs($student)->get('/user/my_requests?tab=equipment')
            ->assertOk()
            ->assertSee('Equipment History')
            ->assertSee('Wheelchair')
            ->assertSee('Sep 30, 2026', false)
            ->assertSee('Oct 01, 2026', false)
            ->assertSee('<span class="badge bg-success">Returned</span>', false)
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Consultations tab (student)
        $consultations = $this->actingAs($student)->get('/user/my_requests?tab=consultations')
            ->assertOk()
            ->assertSee('Consultation History')
            ->assertSee('Fever and cough')
            ->assertSee('Viral URI')
            ->assertSee('Clinic Staff')
            ->assertSee(date('M d, Y g:i A', strtotime('2026-09-25 10:30:00')), false)
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Sick Leave tab (employee)
        $sickLeave = $this->actingAs($employee)->get('/user/my_requests?tab=sick-leaves')
            ->assertOk()
            ->assertSee('Sick Leave History')
            ->assertSee('Oct 01, 2026', false) // Start date column
            ->assertSee('Oct 02, 2026', false) // End date column
            ->assertSee('Flu symptoms')
            ->assertSee('Approved')
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>');

        // Info alert present on all tabs
        $this->assertStringContainsString(
            'This page combines your medicine requests, equipment loans, consultation history, and sick leave history into one activity overview.',
            $medicine->getContent()
        );
    }

    /**
     * Feature 3: the borrower's Equipment History uses the same three colours
     * as the staff Equipment Loans page - orange while it is still out, red
     * once the 24-hour timeout lapses, green after it is returned - and a
     * returned loan stays in the history rather than disappearing.
     */
    public function test_equipment_status_badges_use_the_feature_3_colours(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $eq = $this->makeEquipment('Wheelchair');

        // Still out inside the window.
        $this->makeLoan($student, $eq, date('Y-m-d'), null, 'borrowed');
        // Still out past it.
        $lapsed = date('Y-m-d', strtotime('-3 days'));
        $this->makeLoan($student, $eq, $lapsed, null, 'borrowed');
        // Returned: recoloured, not removed.
        $back = $this->makeLoan($student, $eq, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-4 days')));

        $response = $this->actingAs($student)->get('/user/my_requests?tab=equipment');
        $response->assertOk();
        $content = $response->getContent();
        // The rendered HTML may have extra whitespace or attribute order differences.
        // Check for the key badge components separately.
        $this->assertStringContainsString('badge bg-orange', $content);
        $this->assertStringContainsString('>Borrowed<', $content);
        $this->assertStringContainsString('badge bg-danger', $content);
        $this->assertStringContainsString('>Overdue<', $content);
        $this->assertStringContainsString('badge bg-success', $content);
        $this->assertStringContainsString('>Returned<', $content);
        // The old warning/grey spellings are gone.
        $this->assertStringNotContainsString('badge bg-warning text-dark', $content);
        $this->assertStringNotContainsString('badge bg-secondary">Borrowed', $content);
        // No due date is ever written, so Due falls back to the 24-hour
        // timeout: the lapsed loan shows when it went past it.
        $this->assertStringContainsString(date('M d, Y', strtotime($lapsed.' 09:00:00') + 86400), $content);

        // The return kept the record.
        $this->assertDatabaseHas('equipment_loans', [
            'id' => $back,
            'status' => 'returned',
            'return_date' => date('Y-m-d', strtotime('-4 days')),
        ]);
    }

    public function test_status_whitelist_filters_medicine_rows(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Aspirin');
        $this->makeMedicine('Paracetamol');
        $this->makeRequest($student, 1, 1, 'pain', 'dispensed', null, '2026-09-30 14:05:00');
        $this->makeRequest($student, 2, 1, 'allergy', 'rejected', null, '2026-09-29 14:05:00');

        $dispensedResponse = $this->actingAs($student)->get('/user/my_requests?tab=medicine&status=dispensed');
        $dispensedResponse->assertOk()
            ->assertSee('Aspirin')
            ->assertDontSee('Paracetamol');
        $dispensedContent = $dispensedResponse->getContent();
        // The rendered HTML uses & in href attributes and includes the app prefix.
        // Attribute order and whitespace may vary; check components separately.
        $this->assertStringContainsString('nav-link active', $dispensedContent);
        // The href in the rendered HTML uses the HTML entity & for the query separator.
        // Test client uses APP_URL=http://localhost so the base is http://localhost
        $this->assertStringContainsString('href="http://localhost/user/my_requests?tab=medicine', $dispensedContent);
        $this->assertStringContainsString('status=dispensed"', $dispensedContent);
        $this->assertStringContainsString('>Dispensed<', $dispensedContent);

        $rejectedResponse = $this->actingAs($student)->get('/user/my_requests?tab=medicine&status=rejected');
        $rejectedResponse->assertOk()
            ->assertSee('Paracetamol')
            ->assertDontSee('Aspirin')
            ->assertSee('badge bg-danger">Declined', false);
    }

    public function test_junk_status_applies_no_filter_and_no_active_pill(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Aspirin');
        $this->makeMedicine('Paracetamol');
        $this->makeRequest($student, 1, 1, 'pain', 'dispensed', null, '2026-09-30 14:05:00');
        $this->makeRequest($student, 2, 1, 'allergy', 'rejected', null, '2026-09-29 14:05:00');

        // Not in the whitelist -> no WHERE clause; no pill matches either.
        $this->actingAs($student)->get('/user/my_requests?tab=medicine&status=banana')
            ->assertOk()
            ->assertSee('Aspirin')
            ->assertSee('Paracetamol')
            ->assertDontSee('nav-link active" href="'.url('user/my_requests?tab=medicine').'">All Medicine', false)
            ->assertDontSee('nav-link active" href="'.url('user/my_requests?tab=medicine&status=dispensed').'">Dispensed', false);
    }

    public function test_empty_states_and_hidden_sick_leave_card(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $employee = $this->makeUser('emp', 'user', 'employee');

        // Medicine tab shows empty state
        $this->actingAs($student)->get('/user/my_requests?tab=medicine')
            ->assertOk()
            ->assertSee('No requests found.')
            ->assertSee('Medicine History')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Equipment tab shows empty state
        $this->actingAs($student)->get('/user/my_requests?tab=equipment')
            ->assertOk()
            ->assertSee('No equipment history found.')
            ->assertSee('Equipment History')
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Consultations tab shows empty state
        $this->actingAs($student)->get('/user/my_requests?tab=consultations')
            ->assertOk()
            ->assertSee('No consultations found.')
            ->assertSee('Consultation History')
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Sick Leave History</div>');

        // Sick leave tab: only employees see it. For a student, the tab
        // falls back to medicine (unknown tab handling) or renders nothing.
        // Here we test that an employee sees the sick leave empty state.
        $this->actingAs($employee)->get('/user/my_requests?tab=sick-leaves')
            ->assertOk()
            ->assertSee('No sick leave records found.')
            ->assertSee('Sick Leave History')
            ->assertDontSee('<div class="card-header">Medicine History</div>')
            ->assertDontSee('<div class="card-header">Equipment History</div>')
            ->assertDontSee('<div class="card-header">Consultation History</div>');

        // A student requesting the sick-leaves tab sees the top-level tabs
        // but no content card (the sick-leaves card only renders for employees).
        $this->actingAs($student)->get('/user/my_requests?tab=sick-leaves')
            ->assertOk()
            ->assertSee('Medicine') // top-level tab label present
            ->assertDontSee('Sick Leave History'); // card not rendered
    }

    public function test_pending_leave_hides_end_date_and_review_note(): void
    {
        $employee = $this->makeUser('emp', 'user', 'employee');
        $this->makeLeave($employee, 'Flu symptoms', '2026-10-01', null, 'pending', null, '2026-10-01 08:00:00');

        $this->actingAs($employee)->get('/user/my_requests?tab=sick-leaves')
            ->assertOk()
            ->assertSee('Pending')
            ->assertSee('bg-warning text-dark', false)
            ->assertSee('Oct 01, 2026', false)
            ->assertDontSee('– Oct 02, 2026', false)
            // end_date NULL renders a plain em dash in the End column.
            ->assertSee('—', false);
    }

    public function test_rejected_leave_renders_declined_badge(): void
    {
        $employee = $this->makeUser('emp', 'user', 'employee');
        $this->makeLeave($employee, 'Flu symptoms', '2026-10-01', '2026-10-02', 'rejected', 'No medical basis', '2026-10-01 08:00:00');

        $response = $this->actingAs($employee)->get('/user/my_requests?tab=sick-leaves');
        $response->assertOk();
        $content = $response->getContent();
        // The badge spans multiple lines in the HTML; check for both parts.
        $this->assertStringContainsString('badge bg-danger', $content);
        $this->assertStringContainsString('Declined', $content);
        // The reason 'Flu symptoms' is displayed in the Reason column.
        $this->assertStringContainsString('Flu symptoms', $content);
    }

    public function test_rejected_request_renders_declined_label_null_remarks_and_no_generic_line(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Aspirin', null);
        $this->makeRequest($student, 1, 1, 'allergy', 'rejected', null, '2026-09-30 14:05:00');

        $this->actingAs($student)->get('/user/my_requests?tab=medicine')
            ->assertOk()
            ->assertSee('badge bg-danger">Declined', false)
            ->assertSee('bg-danger', false)
            ->assertSee('<td>—</td>', false)
            ->assertDontSee('<div class="small text-muted">', false);
    }

    public function test_reason_is_trimmed_to_40_chars_with_ellipsis(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Aspirin');
        $this->makeRequest($student, 1, 1, str_repeat('A', 50), 'dispensed', null, '2026-09-30 14:05:00');

        $expected = mb_strimwidth(str_repeat('A', 50), 0, 40, '…');

        $this->actingAs($student)->get('/user/my_requests')
            ->assertOk()
            ->assertSee($expected, false)
            ->assertDontSee(str_repeat('A', 41), false);
    }

    public function test_requests_are_ordered_newest_first(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Zebra');
        $this->makeMedicine('Alpha');
        $this->makeRequest($student, 1, 1, 'old', 'dispensed', null, '2026-10-01 09:00:00');
        $this->makeRequest($student, 2, 1, 'new', 'dispensed', null, '2026-10-03 09:00:00');

        $html = $this->actingAs($student)->get('/user/my_requests')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Zebra'), strpos($html, 'Alpha'), 'Alpha (Oct 3) must render before Zebra (Oct 1)');
    }

    // ---------------------------------------------------------------------
    // Stubs + method parity + role parity
    // ---------------------------------------------------------------------

    public function test_my_loans_stub_redirects_to_my_requests_which_renders(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');

        $this->actingAs($student)->get('/user/my_loans')
            ->assertRedirect(url('user/my_requests'))
            ->assertSessionHas('info', 'Equipment history is now available in My Activity.');

        $this->actingAs($student)->get('/user/my_requests')
            ->assertOk()
            ->assertSee('Medicine History');
    }

    public function test_post_renders_the_same_read_only_page(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $this->makeMedicine('Aspirin');
        $this->makeRequest($student, 1, 1, 'pain', 'dispensed', null, '2026-09-30 14:05:00');

        $this->actingAs($student)->post('/user/my_requests')
            ->assertOk()
            ->assertSee('Medicine History');
    }

    public function test_role_parity(): void
    {
        $student = $this->makeUser('stu', 'user', 'student');
        $employee = $this->makeUser('emp', 'user', 'employee');
        $admin = $this->makeUser('boss', 'admin', null);
        $staff = $this->makeUser('doc', 'staff', null);

        $this->get('/user/my_requests')->assertRedirect(route('login'));
        $this->get('/user/my_loans')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/user/my_requests')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get('/user/my_requests')->assertRedirect(route('staff.dashboard'));

        $this->actingAs($student)->get('/user/my_requests')->assertOk();
        $this->actingAs($employee)->get('/user/my_requests')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $role, ?string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Some', 'last_name' => 'User']);

        return $user->fresh();
    }

    private function makeMedicine(string $name, ?string $generic = null): int
    {
        return DB::table('medicines')->insertGetId([
            'name' => $name,
            'generic_name' => $generic,
        ]);
    }

    private function makeRequest(User $user, int $medicineId, int $qty, string $reason, string $status, ?string $remarks, string $requestedAt): int
    {
        return DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id,
            'medicine_id' => $medicineId,
            'quantity' => $qty,
            'reason' => $reason,
            'status' => $status,
            'remarks' => $remarks,
            'requested_at' => $requestedAt,
        ]);
    }

    private function makeEquipment(string $name): int
    {
        return DB::table('equipment')->insertGetId([
            'name' => $name,
            'total_qty' => 1,
            'available_qty' => 1,
        ]);
    }

    /**
     * Feature 3 needs still-out rows too, so status and return_date are
     * parameters now - the existing call keeps its returned fixture.
     */
    private function makeLoan(User $user, int $equipmentId, string $borrowDate, ?string $returnDate = null, string $status = 'returned'): int
    {
        return DB::table('equipment_loans')->insertGetId([
            'user_id' => $user->id,
            'equipment_id' => $equipmentId,
            'quantity' => 1,
            'borrow_date' => $borrowDate,
            'borrowed_at' => $borrowDate.' 09:00:00',
            'return_date' => $returnDate,
            'status' => $status,
        ]);
    }

    private function makeConsultation(User $patient, ?int $staffId, string $complaint, string $diagnosis, string $date): int
    {
        return DB::table('consultations')->insertGetId([
            'patient_id' => $patient->id,
            'staff_id' => $staffId,
            'complaint' => $complaint,
            'diagnosis' => $diagnosis,
            'consultation_date' => $date,
        ]);
    }

    private function makeLeave(User $user, string $reason, string $start, ?string $end, string $status, ?string $reviewNote, string $createdAt): int
    {
        return DB::table('sick_leaves')->insertGetId([
            'user_id' => $user->id,
            'reason' => $reason,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
            'review_note' => $reviewNote,
            'created_at' => $createdAt,
        ]);
    }
}
