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
 * Phase 13 sick leave tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/sick_leaves.php: the do=review dispatch (original lines
 * 16-36) with its exact success/invalid flashes, the "UPDATE matches zero
 * rows but still flashes success" quirk, the NO-audit invariant (this page
 * never calls log_action), the search/filter builder (38-51) with the
 * dead-status-filter silence, the LIMIT 100 list (53-66) and the global
 * counts (69-71), plus the unconditional per-row modals.
 *
 * SQL parity: CONCAT runs through the SQLite shim in tests/TestCase.php;
 * reviewed_at's NOW() uses the same shim. The live status column is an
 * ENUM - SQLite stores the same strings through a plain text column.
 */
class SickLeaveTest extends TestCase
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

        // Live shape (Phase 13 information_schema probe): status is an ENUM
        // with a 'pending' default, reviewed_at is nullable.
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
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('reviewed_at')->nullable();
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('employee_no', 40);
            $table->string('department', 120)->nullable();
            $table->string('position', 120)->nullable();
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
    // List: render, metrics, empty state (original 75-137)
    // ---------------------------------------------------------------------

    public function test_staff_page_renders_metrics_toolbar_and_empty_state(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->get('/staff/sick_leaves');

        $response->assertOk()
            ->assertSee('Pending Requests')
            ->assertSee('Approved')
            ->assertSee('Declined')
            ->assertSee('Name or employee ID...')
            ->assertSee('No sick leave requests found.');
    }

    public function test_rows_render_chips_actions_modals_and_reviewed_state(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Maria', 'Santos');
        DB::table('employee_details')->insert([
            'user_id' => $emp->id, 'employee_no' => 'EMP-001', 'department' => 'Registrar',
        ]);

        $pending = $this->makeLeave($emp->id, 'Fever and cough', '2026-10-01', '2026-10-03', 'pending', [
            'created_at' => '2026-10-01 09:30:00',
        ]);
        $approved = $this->makeLeave($emp->id, 'Migraine', '2026-09-20', null, 'approved', [
            'reviewed_by' => $staff->id,
            'review_note' => 'Rest well',
            'reviewed_at' => '2026-09-21 10:00:00',
            'created_at' => '2026-09-20 08:00:00',
        ]);
        $rejected = $this->makeLeave($emp->id, 'Duplicate complaint', '2026-09-15', null, 'rejected', [
            'reviewed_by' => $staff->id,
            'review_note' => 'Duplicate request',
            'reviewed_at' => '2026-09-16 10:00:00',
            'created_at' => '2026-09-15 08:00:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/sick_leaves');

        $response->assertOk()
            // Employee cell: CONCAT name, employee_no, department.
            ->assertSee('Maria Santos')
            ->assertSee('EMP-001')
            ->assertSee('Registrar')
            // Leave period: both dates present, en dash between them (the
            // original swallows each closing-tag newline, leaving ~77
            // spaces in between); filed stamp.
            ->assertSee('Oct 01, 2026')
            ->assertSee('– Oct 03, 2026')
            ->assertSee('Oct 01, 2026 9:30 AM')
            // Status chips (raw needles: tags and quotes must not be escaped).
            ->assertSee('bg-warning text-dark">Pending</span>', false)
            ->assertSee('bg-success">Approved</span>', false)
            ->assertSee('bg-danger">Declined</span>', false)
            // Feature 4: the Actions column is View only, so the pending row
            // points at ONE details modal instead of an approve/reject pair;
            // the decision buttons and the reviewer/note live inside it.
            ->assertSee('data-bs-target="#leaveModal'.$pending.'"', false)
            ->assertSee('id="leaveModal'.$pending.'"', false)
            ->assertSee('Accept</button>', false)
            ->assertSee('Decline</button>', false)
            ->assertSee('>Cancel</button>', false)
            ->assertSee('formnovalidate', false)
            ->assertSee('Reviewed by Clinic Staff')
            ->assertSee('Rest well')
            ->assertSee('Duplicate request')
            ->assertDontSee('No sick leave requests found.');

        // Every row still gets its details modal, reviewed ones included -
        // View has to work for a decided request too, since the reviewer and
        // the note moved out of the table cell and into this modal.
        $response->assertSee('id="leaveModal'.$approved.'"', false)
            ->assertSee('id="leaveModal'.$rejected.'"', false);

        // Reason truncation at width 60 (mb_strimwidth counts the ellipsis
        // toward the width: 59 source chars + marker).
        $long = $this->makeLeave($emp->id, str_repeat('abcdefghij', 8), '2026-10-02', null, 'pending');
        $full = str_repeat('abcdefghij', 8);
        $body = $this->actingAs($staff)->get('/staff/sick_leaves')->getContent();
        $this->assertStringContainsString(str_repeat('abcdefghij', 5).'abcdefghi…', $body);
        // Feature 4: the cell keeps its 60-char summary, while the details
        // modal the View button opens carries the whole reason - so the
        // untruncated text now exists, exactly once, outside that cell.
        $this->assertSame(1, substr_count($body, $full), 'only the details modal may hold the full reason');
    }

    public function test_null_end_date_renders_the_start_date_alone(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Solo', 'Range');
        $this->makeLeave($emp->id, 'Just a start', '2026-09-20', null, 'pending');

        $this->actingAs($staff)->get('/staff/sick_leaves')
            ->assertOk()
            ->assertSee('Sep 20, 2026')
            ->assertDontSee(' – ');
    }

    // ---------------------------------------------------------------------
    // Review dispatch (original 16-36)
    // ---------------------------------------------------------------------

    public function test_valid_review_updates_flashes_and_notifies_the_requester_only(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Leo', 'Cruz');
        $pending = $this->makeLeave($emp->id, 'Flu', '2026-10-01', null, 'pending');

        $response = $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review',
            'leave_id' => $pending,
            'status' => 'approved',
            'review_note' => 'Take a rest',
        ]);

        $response->assertRedirect(route('staff.sick_leaves'))
            ->assertSessionHas('success', 'Sick leave request approved.');

        $row = DB::table('sick_leaves')->where('id', $pending)->first();
        $this->assertSame('approved', $row->status);
        $this->assertSame((int) $staff->id, (int) $row->reviewed_by);
        $this->assertSame('Take a rest', $row->review_note);
        $this->assertNotNull($row->reviewed_at);

        // The original page never calls log_action() - no audit row.
        $this->assertSame(0, DB::table('audit_logs')->count());

        // Feature 2: sick_leave_status goes to the employee who filed the
        // request (deduped on the leave id, so a re-review never stacks),
        // and not to the staff member who approved it.
        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $emp->id, (int) $note->user_id);
        $this->assertSame('sick_leave_status', $note->type);
        $this->assertSame('Sick Leave Approved', $note->title);
        $this->assertSame($pending, (int) $note->reference_id);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $staff->id)->count());

        // Dedup half of feature 2: reviewing the very same request again
        // must not stack a second alert on the employee's bell.
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review',
            'leave_id' => $pending,
            'status' => 'approved',
            'review_note' => 'Still resting',
        ])->assertRedirect(route('staff.sick_leaves'));

        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_reject_and_blank_notes_keep_original_null_semantics(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Ana', 'Reyes');
        $a = $this->makeLeave($emp->id, 'One', '2026-10-01', null, 'pending');
        $b = $this->makeLeave($emp->id, 'Two', '2026-10-01', null, 'pending');
        $c = $this->makeLeave($emp->id, 'Three', '2026-10-01', null, 'pending');

        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => $a, 'status' => 'rejected',
            'review_note' => 'Not covered',
        ])->assertSessionHas('success', 'Sick leave request rejected.');
        $this->assertSame('rejected', DB::table('sick_leaves')->find($a)->status);

        // Whitespace-only note trims to '' and stores NULL.
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => $b, 'status' => 'approved',
            'review_note' => '   ',
        ])->assertSessionHas('success', 'Sick leave request approved.');
        $this->assertNull(DB::table('sick_leaves')->find($b)->review_note);

        // Missing note key stores NULL too ($row ?? '' semantics).
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => $c, 'status' => 'approved',
        ])->assertSessionHas('success', 'Sick leave request approved.');
        $this->assertNull(DB::table('sick_leaves')->find($c)->review_note);
    }

    public function test_invalid_review_actions_flash_danger_without_writing(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Zed', 'Lim');
        $pending = $this->makeLeave($emp->id, 'Pending row', '2026-10-01', null, 'pending');

        $before = DB::table('sick_leaves')->find($pending);

        // Crafted status outside the in_array whitelist.
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => $pending, 'status' => 'banana',
        ])->assertSessionHas('danger', 'Invalid review action.');

        // Non-positive / missing leave_id.
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => 0, 'status' => 'approved',
        ])->assertSessionHas('danger', 'Invalid review action.');
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'status' => 'approved',
        ])->assertSessionHas('danger', 'Invalid review action.');

        $after = DB::table('sick_leaves')->find($pending);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->reviewed_by, $after->reviewed_by);
        $this->assertSame($before->reviewed_at, $after->reviewed_at);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_review_of_a_missing_id_still_flashes_success_like_the_reference(): void
    {
        $staff = $this->makeStaff();

        // The original runs the UPDATE unconditionally and reads no rowCount:
        // a well-formed review of a non-existent row flashes success.
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => 999, 'status' => 'approved', 'review_note' => 'x',
        ])->assertRedirect(route('staff.sick_leaves'))
            ->assertSessionHas('success', 'Sick leave request approved.');

        $this->assertSame(0, DB::table('sick_leaves')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_get_query_review_never_reaches_the_handler_and_post_without_do_renders(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Kit', 'Tan');
        $pending = $this->makeLeave($emp->id, 'Stay', '2026-10-01', null, 'pending');

        // $_POST is empty on GET, so ?do=review must be inert.
        $this->actingAs($staff)->get('/staff/sick_leaves?do=review&leave_id='.$pending.'&status=approved')
            ->assertOk();
        $this->assertSame('pending', DB::table('sick_leaves')->find($pending)->status);

        // A POST without do=review falls through to the list render.
        $this->actingAs($staff)->post('/staff/sick_leaves', ['something' => 'else'])
            ->assertOk()
            ->assertSee('Stay');
        $this->assertSame('pending', DB::table('sick_leaves')->find($pending)->status);
    }

    // ---------------------------------------------------------------------
    // Search + filter (original 38-51, 111-114)
    // ---------------------------------------------------------------------

    public function test_search_matches_concatenated_name_and_employee_no(): void
    {
        $staff = $this->makeStaff();
        $maria = $this->makeEmployee('Maria', 'Santos');
        $juan = $this->makeEmployee('Juan', 'Dela Cruz');
        DB::table('employee_details')->insert([
            ['user_id' => $maria->id, 'employee_no' => 'EMP-001', 'department' => 'Registrar'],
            ['user_id' => $juan->id, 'employee_no' => 'EMP-002', 'department' => 'Clinic'],
        ]);
        $this->makeLeave($maria->id, 'A', '2026-10-01', null, 'pending');
        $this->makeLeave($juan->id, 'B', '2026-10-01', null, 'pending');

        $byName = $this->actingAs($staff)->get('/staff/sick_leaves?q=Santos');
        $byName->assertSee('Maria Santos')->assertDontSee('Juan Dela Cruz');

        $byNo = $this->actingAs($staff)->get('/staff/sick_leaves?q=EMP-002');
        $byNo->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');

        $none = $this->actingAs($staff)->get('/staff/sick_leaves?q=zzz');
        $none->assertSee('No sick leave requests found.');
    }

    public function test_status_filter_limits_rows_but_counts_stay_global_and_dead_values_show_all(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Pia', 'Gomez');
        $this->makeLeave($emp->id, 'P1', '2026-10-01', null, 'pending');
        $this->makeLeave($emp->id, 'P2', '2026-10-01', null, 'pending');
        $this->makeLeave($emp->id, 'A1', '2026-09-01', null, 'approved');
        $this->makeLeave($emp->id, 'R1', '2026-09-01', null, 'rejected');

        // Valid filter narrows the rows...
        $filtered = $this->actingAs($staff)->get('/staff/sick_leaves?status=approved');
        $filtered->assertOk()->assertSee('A1')->assertDontSee('P1')->assertDontSee('R1');
        // ...select shows the raw value as selected...
        $filtered->assertSee('<option value="approved" selected>', false);
        // ...but the metric cards keep the GLOBAL counts (pending=2).
        $filtered->assertSee('<div class="metric-value">2</div>', false);

        // An invalid status collapses to no SQL filter (all rows) while the
        // <select> just renders without any selected option.
        $dead = $this->actingAs($staff)->get('/staff/sick_leaves?status=banana');
        $dead->assertSee('P1')->assertSee('A1')->assertSee('R1')
            ->assertSee('<option value="all" >All</option>', false)
            ->assertDontSee('selected>');

        // Missing parameter behaves as 'all'.
        $none = $this->actingAs($staff)->get('/staff/sick_leaves');
        $none->assertSee('<option value="all" selected>', false);

        // Combined q + status ANDs like the original builder.
        $combo = $this->actingAs($staff)->get('/staff/sick_leaves?q=Gomez&status=rejected');
        $combo->assertSee('R1')->assertDontSee('P1')->assertDontSee('A1');
    }

    // ---------------------------------------------------------------------
    // Recent preview + module history (module histories feature)
    // ---------------------------------------------------------------------

    /**
     * The queue previews the 10 newest requests; the complete history sits
     * behind View All at /staff/history?tab=sick-leaves, paginated at the
     * original's LIMIT 100 per page and rendered through the shared
     * history-table partial (histLeaveModal details modals per row).
     */
    public function test_module_previews_10_newest_and_history_paginates_at_100(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Bulk', 'Rows');
        for ($i = 0; $i < 105; $i++) {
            $this->makeLeave($emp->id, "Row $i", '2026-10-01', null, 'pending');
        }

        // Recent preview: 10 rows plus the View All link.
        $module = $this->actingAs($staff)->get('/staff/sick_leaves')->assertOk();
        $this->assertSame(10, substr_count($module->getContent(), 'id="leaveModal'), 'The queue previews 10 rows');
        $module->assertSee(route('staff.history', ['tab' => 'sick-leaves']));

        // History: shared table, 100 rows per page, back link + nav.
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves')->assertOk();
        $this->assertSame(100, substr_count($page1->getContent(), 'id="histLeaveModal'), 'History page 1 must render 100 rows');
        $page1->assertSee('Sick Leaves History')
            ->assertSee('Sick Leaves history pagination', false);

        $page2 = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), 'id="histLeaveModal'));
    }

    /**
     * The history toolbar keeps the queue's status semantics: pending /
     * approved / declined actually filter, junk collapses to 'all' for
     * both the dropdown and the query, and the active status rides along
     * in every pagination link.
     */
    public function test_history_status_filter_is_live_and_survives_pagination(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Bulk', 'Rows');
        for ($i = 0; $i < 3; $i++) {
            $this->makeLeave($emp->id, "Row $i", '2026-10-01', null, 'pending');
        }
        $this->makeLeave($emp->id, 'Approved one', '2026-10-01', null, 'approved');

        $approved = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=approved')->assertOk();
        $this->assertSame(1, substr_count($approved->getContent(), 'id="histLeaveModal'));
        $approved->assertSee('Approved one')
            ->assertSee('<option value="approved" selected>', false);

        $junk = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=banana')->assertOk();
        $this->assertSame(4, substr_count($junk->getContent(), 'id="histLeaveModal'));
        $junk->assertSee('<option value="all" selected>', false);

        // 105 pending: the filtered set paginates with the status kept in
        // every page link.
        for ($i = 3; $i < 105; $i++) {
            $this->makeLeave($emp->id, "Row $i", '2026-10-01', null, 'pending');
        }
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=pending')->assertOk();
        $this->assertSame(100, substr_count($page1->getContent(), 'id="histLeaveModal'));
        $page1->assertSee('status=pending', false);

        $page2 = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=pending&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), 'id="histLeaveModal'));
        $page2->assertSee('status=pending', false);
    }

    // ---------------------------------------------------------------------
    // Feature 4: confirmation after a successful review
    // ---------------------------------------------------------------------

    /**
     * The review flashes "Sick leave request approved." through a partial
     * that renders nothing, so the redirect used to land silently. The
     * flashed message is now handed to main.js as a toast - and only on the
     * request that FOLLOWED a completed POST, so a success cannot be shown
     * before it happened, nor announced on a page that never performed one.
     */
    public function test_a_successful_review_surfaces_a_confirmation_after_the_redirect(): void
    {
        $staff = $this->makeStaff();
        $emp = $this->makeEmployee('Toast', 'Check');
        $id = $this->makeLeave($emp->id, 'Fever', '2026-10-01', null, 'pending');

        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review',
            'leave_id' => $id,
            'status' => 'approved',
            'review_note' => '',
        ])->assertRedirect(route('staff.sick_leaves'));

        // The redirect target carries the carrier with the exact message.
        $this->actingAs($staff)->get('/staff/sick_leaves')
            ->assertOk()
            ->assertSee('id="flashNotice"', false)
            ->assertSee('data-flash-text="Sick leave request approved."', false)
            ->assertSee('data-flash-icon="success"', false);

        // ...and the next plain load has none, so the toast shows once.
        $this->actingAs($staff)->get('/staff/sick_leaves')
            ->assertOk()
            ->assertDontSee('id="flashNotice"', false);

        // A declined review uses the error-flavoured carrier instead.
        $declined = $this->makeLeave($emp->id, 'Cough', '2026-10-05', null, 'pending');
        $this->actingAs($staff)->post('/staff/sick_leaves', [
            'do' => 'review',
            'leave_id' => $declined,
            'status' => 'rejected',
            'review_note' => 'Duplicate request',
        ])->assertRedirect(route('staff.sick_leaves'));

        $this->actingAs($staff)->get('/staff/sick_leaves')
            ->assertOk()
            ->assertSee('data-flash-text="Sick leave request rejected."', false);
    }

    // ---------------------------------------------------------------------
    // Authz: require_login(['staff'])
    // ---------------------------------------------------------------------

    public function test_authz_matches_require_login(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makeUser('student', 'user', 'student');
        $admin = $this->makeUser('admin', 'admin', null);

        $this->get('/staff/sick_leaves')->assertRedirect(route('login'));
        $this->actingAs($student)->get('/staff/sick_leaves')->assertRedirect(route('user.dashboard'));
        $this->actingAs($admin)->get('/staff/sick_leaves')->assertRedirect(route('admin.dashboard'));

        // The review POST sits behind the same guard.
        $this->actingAs($student)->post('/staff/sick_leaves', [
            'do' => 'review', 'leave_id' => 1, 'status' => 'approved',
        ])->assertRedirect(route('user.dashboard'));

        $this->actingAs($staff)->get('/staff/sick_leaves')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeStaff(): User
    {
        $user = User::create([
            'username' => 'staff',
            'email' => 'staff@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Clinic', 'last_name' => 'Staff']);

        return $user->fresh();
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

    private function makeEmployee(string $first, string $last): User
    {
        $user = User::create([
            'username' => strtolower($first.$last),
            'email' => strtolower($first.$last).'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => 'employee',
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);

        return $user->fresh();
    }

    private function makeLeave(
        int $userId,
        string $reason,
        string $start,
        ?string $end,
        string $status = 'pending',
        array $extra = [],
    ): int {
        return DB::table('sick_leaves')->insertGetId($extra + [
            'user_id' => $userId,
            'reason' => $reason,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
        ]);
    }
}
