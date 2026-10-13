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
 * Phase 16 equipment-loan tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/equipment_loans.php: the issue POST (original lines 19-59)
 * with its three early validations (borrower, equipment, quantity !== 1),
 * the active/stock re-checks, the conditional UPDATE + insert inside a
 * transaction (borrow_date CURDATE(), borrowed_at NOW(), status borrowed),
 * the return POST (62-80) where only a 'borrowed' row restores stock,
 * commits and audits while anything else flashes the already-returned
 * danger message; the borrower datalist (student/employee IDs via CONCAT),
 * the status filter (default borrowed, 'overdue' = borrowed_at <= 24h ago,
 * whitelist, junk = no WHERE at all), the default loan limit from
 * system_settings (default 5), the overdue-notification idempotency hook,
 * and role parity.
 *
 * SQL parity: CURDATE()/NOW()/CONCAT run through the SQLite shims in
 * tests/TestCase.php; SUBDATE(d, 1) is the port's DATE_SUB(d, INTERVAL
 * 24 HOUR) spelling.
 *
 * CSRF: Feature tests bypass Laravel's middleware (runningUnitTests), so
 * the reference's token check - which maps to 419 - cannot be asserted
 * here (Phase 6 convention).
 */
class EquipmentLoansTest extends TestCase
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

        Schema::create('student_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('student_no', 40);
            $table->string('course', 120)->nullable();
            $table->string('year_level', 40)->nullable();
            $table->string('section', 60)->nullable();
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('employee_no', 40);
            $table->string('department', 120)->nullable();
            $table->string('position', 120)->nullable();
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

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 80)->unique();
            $table->text('setting_value')->nullable();
            $table->dateTime('updated_at')->useCurrent();
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

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('table_name', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
            $table->index('action');
        });
    }

    // ---------------------------------------------------------------------
    // Render (original 112-236)
    // ---------------------------------------------------------------------

    public function test_page_renders_issue_modal_datalist_and_loans(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makePatient('stu', 'student', 'Patient', 'One', 'ST-001');
        $employee = $this->makePatient('emp', 'employee', 'Worker', 'Two', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 8, 8);
        $this->makeLoan($student, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'));

        $this->actingAs($staff)->get('/staff/equipment_loans')
            ->assertOk()
            ->assertSee('Issue Equipment')
            ->assertSee('Borrower *')
            ->assertSee('Search borrower name or ID...')
            // Datalist: student ID and employee ID (student_no ?: employee_no).
            ->assertSee('value="Patient One - ST-001"', false)
            ->assertSee('value="Worker Two - EMP-001"', false)
            ->assertSee('data-user-id="'.$student->id.'"', false)
            // Equipment option: default limit 5 caps the total of 8.
            ->assertSee('data-available="8"', false)
            ->assertSee('data-limit="5"', false)
            ->assertSee('Wheelchair (8 available)', false)
            ->assertSee('24-Hour Timeout')
            ->assertSee('Equipment must be returned within 24 hours.', false)
            ->assertSee('Equipment requests are limited to 1 item per request.', false)
            // Loan row + return form.
            ->assertSee('Patient One')
            ->assertSee(' Return</button>', false)
            // CSRF: Laravel's field, not csrf_token.
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="csrf_token"', false)
            // No errors -> the auto-open script line renders nothing.
            ->assertDontSee('new bootstrap.Modal(modal).show()', false)
            ->assertSee('applyEquipmentLimit', false);
    }

    public function test_default_filter_shows_every_status(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $other = $this->makePatient('emp', 'employee', 'Returned', 'Kid', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 1);
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'), 'borrowed');
        $this->makeLoan($other, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'returned');

        $borrowedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00')));
        $returnedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00', strtotime('-3 days'))));

        // The redesign defaults the page to All: no status parameter means
        // no status restriction, so both rows render with All selected.
        $this->actingAs($staff)->get('/staff/equipment_loans')
            ->assertOk()
            ->assertSee($borrowedOn, false)
            ->assertSee($returnedOn, false)
            ->assertSee('<option value="all" selected>All</option>', false);

        // The borrowed-only view is still one filter away. (Dates are
        // unique to table rows - patient names also appear in the borrower
        // datalist, so they cannot assert absence.)
        $this->actingAs($staff)->get('/staff/equipment_loans?status=borrowed')
            ->assertOk()
            ->assertSee($borrowedOn, false)
            ->assertDontSee($returnedOn, false);
    }

    public function test_status_returned_filter_shows_only_returned(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $other = $this->makePatient('emp', 'employee', 'Returned', 'Kid', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 1);
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'), 'borrowed');
        $this->makeLoan($other, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'returned');

        $borrowedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00')));
        $returnedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00', strtotime('-3 days'))));

        $this->actingAs($staff)->get('/staff/equipment_loans?status=returned')
            ->assertOk()
            ->assertSee($returnedOn, false)
            ->assertDontSee($borrowedOn, false);
    }

    public function test_status_overdue_filter_uses_24_hour_window(): void
    {
        $staff = $this->makeStaff();
        $old = $this->makePatient('stu', 'student', 'Old', 'Loan', 'ST-001');
        $fresh = $this->makePatient('emp', 'employee', 'Fresh', 'Loan', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 0);
        $this->makeLoan($old, $eq, 1, date('Y-m-d', strtotime('-2 days')), date('Y-m-d 09:00:00', strtotime('-2 days')), 'borrowed');
        $this->makeLoan($fresh, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'), 'borrowed');

        $oldOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00', strtotime('-2 days'))));
        $freshOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00')));

        $this->actingAs($staff)->get('/staff/equipment_loans?status=overdue')
            ->assertOk()
            ->assertSee($oldOn, false)
            ->assertDontSee($freshOn, false);
    }

    public function test_junk_status_filter_renders_all_rows(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $other = $this->makePatient('emp', 'employee', 'Returned', 'Kid', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 1);
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'), 'borrowed');
        $this->makeLoan($other, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'returned');

        $borrowedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00')));
        $returnedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00', strtotime('-3 days'))));

        // Not in the whitelist -> NO WHERE clause at all (reference quirk).
        $this->actingAs($staff)->get('/staff/equipment_loans?status=banana')
            ->assertOk()
            ->assertSee($borrowedOn, false)
            ->assertSee($returnedOn, false);
    }

    public function test_all_filter_shows_every_status_and_keeps_other_filters(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $other = $this->makePatient('emp', 'employee', 'Returned', 'Kid', 'EMP-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 1);
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'), 'borrowed');
        $this->makeLoan($other, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'returned');

        $borrowedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00')));
        $returnedOn = date('M d, Y g:i A', strtotime(date('Y-m-d 09:00:00', strtotime('-3 days'))));

        // The explicit All option: status=all drops the status restriction
        // in the backend query, so rows of every supported status render
        // together (same behavior the legacy junk values relied on).
        $this->actingAs($staff)->get('/staff/equipment_loans?status=all')
            ->assertOk()
            ->assertSee($borrowedOn, false)
            ->assertSee($returnedOn, false)
            ->assertSee('<option value="all" selected>All</option>', false);

        // All removes ONLY the status restriction: an active ?q= still
        // narrows the rows, and the hidden field carries it through the
        // status form submission.
        $this->actingAs($staff)->get('/staff/equipment_loans?status=all&q=Borrow')
            ->assertOk()
            ->assertSee($borrowedOn, false)
            ->assertDontSee($returnedOn, false)
            ->assertSee('<input type="hidden" name="q" value="Borrow"', false);

        // The default now equals the explicit All option: no status
        // parameter lists every status, with All selected.
        $this->actingAs($staff)->get('/staff/equipment_loans')
            ->assertOk()
            ->assertSee('<option value="all" selected>All</option>', false)
            ->assertSee($returnedOn, false);
    }

    public function test_empty_state_when_no_loans_in_view(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/equipment_loans')
            ->assertOk()
            ->assertSee('No loans in this view.');
    }

    public function test_loan_limit_setting_caps_data_limit(): void
    {
        $staff = $this->makeStaff();
        $this->makeEquipment('Wheelchair', 8, 8);
        DB::table('system_settings')->insert([
            'setting_key' => 'default_equipment_loan_limit',
            'setting_value' => '2',
        ]);

        $this->actingAs($staff)->get('/staff/equipment_loans')
            ->assertOk()
            ->assertSee('data-limit="2"', false);
    }

    // ---------------------------------------------------------------------
    // POST: issue (original 19-59)
    // ---------------------------------------------------------------------

    public function test_issue_validates_borrower_and_equipment_presence(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => 0,
            'equipment_id' => 0,
            'quantity' => 1,
        ])->assertOk()
            ->assertSee('Select a borrower.')
            ->assertSee('Select equipment.')
            ->assertSee('new bootstrap.Modal(modal).show()', false);

        $this->assertDatabaseCount('equipment_loans', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_issue_rejects_quantity_other_than_one(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 5, 5);

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => $borrower->id,
            'equipment_id' => $eq,
            'quantity' => 2,
            'notes' => 'need two',
        ])->assertOk()
            ->assertSee('Equipment requests are limited to 1 item per request.');

        $this->assertDatabaseCount('equipment_loans', 0);
        $this->assertDatabaseHas('equipment', ['id' => $eq, 'available_qty' => 5]);
    }

    public function test_issue_rejects_unknown_or_inactive_equipment(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => $borrower->id,
            'equipment_id' => 999,
            'quantity' => 1,
        ])->assertOk()
            ->assertSee('Selected equipment is unavailable.');

        $this->assertDatabaseCount('equipment_loans', 0);
    }

    public function test_issue_rejects_when_no_units_available(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 0);

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => $borrower->id,
            'equipment_id' => $eq,
            'quantity' => 1,
        ])->assertOk()
            ->assertSee('Not enough available units.');

        $this->assertDatabaseCount('equipment_loans', 0);
    }

    public function test_issue_rejects_quantity_above_total_stock(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        // Inconsistent legacy row: available >= 1 but total is 0.
        $eq = $this->makeEquipment('Wheelchair', 0, 1);

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => $borrower->id,
            'equipment_id' => $eq,
            'quantity' => 1,
        ])->assertOk()
            ->assertSee('Requested quantity cannot exceed total stock.');

        $this->assertDatabaseCount('equipment_loans', 0);
    }

    public function test_issue_success_decrements_stock_writes_loan_and_audit(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 5, 5);

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'issue',
            'user_id' => $borrower->id,
            'equipment_id' => $eq,
            'quantity' => 1,
            'notes' => 'for physio',
        ])->assertRedirect(route('staff.equipment_loans'))
            ->assertSessionHas('success', 'Equipment issued.');

        $this->assertDatabaseHas('equipment_loans', [
            'id' => 1,
            'user_id' => $borrower->id,
            'equipment_id' => $eq,
            'quantity' => 1,
            'borrow_date' => date('Y-m-d'),
            'status' => 'borrowed',
            'notes' => 'for physio',
            'issued_by' => $staff->id,
            'return_date' => null,
        ]);
        $this->assertDatabaseHas('equipment', ['id' => $eq, 'available_qty' => 4]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'issue_loan',
            'table_name' => 'equipment_loans',
            'record_id' => 1,
            'details' => "Issued equipment #{$eq} to user #{$borrower->id}",
        ]);
    }

    // ---------------------------------------------------------------------
    // POST: return (original 62-80)
    // ---------------------------------------------------------------------

    public function test_return_restores_stock_marks_returned_and_audits(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 5, 4);
        $loan = $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 09:00:00'));

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'return',
            'loan_id' => $loan,
        ])->assertRedirect(route('staff.equipment_loans'))
            ->assertSessionHas('success', 'Equipment returned.');

        $this->assertDatabaseHas('equipment_loans', [
            'id' => $loan,
            'status' => 'returned',
            'return_date' => date('Y-m-d'),
            'received_by' => $staff->id,
        ]);
        $this->assertDatabaseHas('equipment', ['id' => $eq, 'available_qty' => 5]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'return_loan',
            'table_name' => 'equipment_loans',
            'record_id' => $loan,
            'details' => "Returned loan #{$loan}",
        ]);
    }

    public function test_return_unknown_loan_flashes_danger_without_writing(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'return',
            'loan_id' => 999,
        ])->assertRedirect(route('staff.equipment_loans'))
            ->assertSessionHas('danger', 'Loan not found or already returned.');

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_return_already_returned_loan_flashes_danger(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 2);
        $loan = $this->makeLoan($borrower, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'returned');

        $this->actingAs($staff)->post('/staff/equipment_loans', [
            'do' => 'return',
            'loan_id' => $loan,
        ])->assertRedirect(route('staff.equipment_loans'))
            ->assertSessionHas('danger', 'Loan not found or already returned.');

        // Stock untouched: the restore only runs for 'borrowed' rows.
        $this->assertDatabaseHas('equipment', ['id' => $eq, 'available_qty' => 2]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ---------------------------------------------------------------------
    // Feature 3 - status colours
    // ---------------------------------------------------------------------

    /**
     * Borrowed = orange, Overdue = red, Returned = green - and the colour has
     * to answer the same question the filter asks. Both used to disagree: the
     * filters query SUBDATE(NOW(), 1) (the database clock) while the badge
     * derived "overdue" from PHP's time(), which on this box sits six hours
     * behind MySQL, so a row could read Overdue while the Overdue filter
     * omitted it. is_overdue now comes from the query.
     */
    public function test_status_badges_are_coloured_and_agree_with_the_status_filter(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 3, 3);

        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d H:i:s'), 'borrowed');
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d H:i:s', strtotime('-3 days')), 'borrowed');
        $back = $this->makeLoan($borrower, $eq, 1, date('Y-m-d', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'returned');

        $borrowed = $this->actingAs($staff)->get('/staff/equipment_loans?status=borrowed');
        $borrowed->assertOk();
        $borrowed->assertSee('<span class="badge bg-orange">Borrowed</span>', false);
        $borrowed->assertSee('<span class="badge bg-danger">Overdue</span>', false);
        $borrowed->assertDontSee('<span class="badge bg-success">Returned</span>', false);

        // The default view is All, so the returned row's green badge is on
        // the page as well - agreeing with the selected filter.
        $all = $this->actingAs($staff)->get('/staff/equipment_loans');
        $all->assertOk();
        $all->assertSee('<span class="badge bg-success">Returned</span>', false);

        // A returned loan keeps its record and turns green; it is no longer
        // in the borrowed view but is still on the page's own Returned filter.
        $returned = $this->actingAs($staff)->get('/staff/equipment_loans?status=returned');
        $returned->assertOk();
        $returned->assertSee('<span class="badge bg-success">Returned</span>', false);
        $returned->assertDontSee('<span class="badge bg-orange">Borrowed</span>', false);
        $returned->assertDontSee('<span class="badge bg-danger">Overdue</span>', false);

        // The Overdue filter returns exactly the row the red badge names:
        // the lapsed loan is listed and still red, the fresh one is absent,
        // and the returned one never qualifies (status is not 'borrowed').
        $overdue = $this->actingAs($staff)->get('/staff/equipment_loans?status=overdue');
        $overdue->assertOk();
        $overdue->assertSee('<span class="badge bg-danger">Overdue</span>', false);
        $overdue->assertDontSee('<span class="badge bg-orange">Borrowed</span>', false);
        $overdue->assertDontSee('<span class="badge bg-success">Returned</span>', false);

        // No grey loan badges remain.
        $borrowed->assertDontSee('<span class="badge bg-secondary">Borrowed</span>', false);
        $borrowed->assertDontSee('<span class="badge bg-secondary">Overdue</span>', false);
        $returned->assertDontSee('<span class="badge bg-secondary">Returned</span>', false);

        // "Equipment returns keep records": the row survives with its return
        // date - it is recoloured, never deleted.
        $this->assertDatabaseHas('equipment_loans', [
            'id' => $back,
            'status' => 'returned',
            'return_date' => date('Y-m-d'),
        ]);
    }

    // ---------------------------------------------------------------------
    // Overdue notifications hook (original line 8)
    // ---------------------------------------------------------------------

    public function test_overdue_notification_is_created_once_per_loan_and_covers_null_due_date(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 2, 1);

        // Past due_date -> one notification. The loan form never writes a
        // due_date, so on live data every row is NULL there: feature 2 makes
        // that case alert once the loan has been out over a day - the same
        // expression the page's own "Overdue" filter uses - while one
        // borrowed today is not overdue yet and stays silent.
        $overdue = $this->makeLoan($borrower, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 09:00:00', strtotime('-3 days')), 'borrowed', date('Y-m-d', strtotime('-1 day')));
        $nullDueLate = $this->makeLoan($borrower, $eq, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d 10:00:00', strtotime('-3 days')), 'borrowed', null);
        $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 10:00:00'), 'borrowed', null);

        $this->actingAs($staff)->get('/staff/equipment_loans')->assertOk();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $borrower->id,
            'loan_id' => $overdue,
            'type' => 'overdue_loan',
        ]);

        // No due_date to quote, so the alert quotes the borrow date instead.
        $nullDueNote = DB::table('notifications')->where('loan_id', $nullDueLate)->first();
        $this->assertNotNull($nullDueNote);
        $this->assertSame('overdue_loan', $nullDueNote->type);
        $this->assertStringContainsString(
            'borrowed on '.date('M d, Y', strtotime('-3 days')),
            $nullDueNote->message,
        );
        $this->assertStringNotContainsString('was due on', $nullDueNote->message);

        // Idempotent: a second page load must not duplicate the alerts.
        $this->actingAs($staff)->get('/staff/equipment_loans')->assertOk();
        $this->assertDatabaseCount('notifications', 2);
    }

    // ---------------------------------------------------------------------
    // Role parity (require_login(['staff']))
    // ---------------------------------------------------------------------

    public function test_role_parity(): void
    {
        $staff = $this->makeStaff();
        $admin = $this->makeUser('boss', 'admin', null);
        $student = $this->makeUser('stu', 'user', 'student');

        $this->get('/staff/equipment_loans')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/staff/equipment_loans')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($student)->get('/staff/equipment_loans')->assertRedirect(route('user.dashboard'));
        $this->actingAs($admin)->post('/staff/equipment_loans', ['do' => 'return', 'loan_id' => 1])
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($staff)->get('/staff/equipment_loans')->assertOk();
        $this->actingAs($staff)->get('/staff/equipment_loans?status=returned')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Module histories (module histories feature)
    // ---------------------------------------------------------------------

    /**
     * The loans page previews the 10 newest matching loans; the complete
     * history sits behind View All at /staff/history?tab=equipment-loans,
     * paginated at the module's 20 records per page and rendered through
     * the same history-table partial (identical Return forms per borrowed
     * row). The toolbar status semantics carry over: a returned filter on
     * an all-borrowed set shows the empty state, junk collapses to 'all',
     * and the active status rides along in every page link.
     */
    public function test_module_previews_10_newest_and_history_paginates_at_20(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient('stu', 'student', 'Borrow', 'Ed', 'ST-001');
        $eq = $this->makeEquipment('Wheelchair', 30, 5);
        for ($i = 0; $i < 25; $i++) {
            $this->makeLoan($borrower, $eq, 1, date('Y-m-d'), date('Y-m-d 08:00:00'), 'borrowed');
        }

        // Recent preview: 10 rows (each borrowed row owns one Return
        // button) plus the View All link.
        $module = $this->actingAs($staff)->get('/staff/equipment_loans')->assertOk();
        $this->assertSame(10, substr_count($module->getContent(), 'Return</button>'), 'The loans page previews 10 rows');
        $module->assertSee(route('staff.history', ['tab' => 'equipment-loans']));

        // History: shared table, 20 rows per page, back link + nav.
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans')->assertOk();
        $this->assertSame(20, substr_count($page1->getContent(), 'Return</button>'), 'History page 1 must render 20 rows');
        $page1->assertSee('Equipment Loans History')
            ->assertSee('Equipment Loans history pagination', false);

        $page2 = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), 'Return</button>'));

        // Status filters stay live and survive pagination.
        $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=returned')->assertOk()
            ->assertSee('No records found.')
            ->assertSee('<option value="returned" selected>', false);

        $borrowed = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=borrowed')->assertOk();
        $this->assertSame(20, substr_count($borrowed->getContent(), 'Return</button>'));
        $borrowed->assertSee('<option value="borrowed" selected>', false)
            ->assertSee('status=borrowed', false);

        $junk = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=banana')->assertOk();
        $junk->assertSee('<option value="all" selected>', false);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeStaff(): User
    {
        return $this->makeUser('clinic', 'staff', null);
    }

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

    /** role=user patient with student/employee details for the datalist. */
    private function makePatient(string $username, string $type, string $first, string $last, string $number): User
    {
        $user = $this->makeUser($username, 'user', $type);
        $user->profile()->update(['first_name' => $first, 'last_name' => $last]);

        if ($type === 'student') {
            DB::table('student_details')->insert(['user_id' => $user->id, 'student_no' => $number]);
        } else {
            DB::table('employee_details')->insert(['user_id' => $user->id, 'employee_no' => $number]);
        }

        return $user;
    }

    private function makeEquipment(string $name, int $total, int $available): int
    {
        return DB::table('equipment')->insertGetId([
            'name' => $name,
            'total_qty' => $total,
            'available_qty' => $available,
        ]);
    }

    private function makeLoan(
        User $user,
        int $equipmentId,
        int $quantity,
        string $borrowDate,
        string $borrowedAt,
        string $status = 'borrowed',
        ?string $dueDate = null,
    ): int {
        return DB::table('equipment_loans')->insertGetId([
            'user_id' => $user->id,
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
