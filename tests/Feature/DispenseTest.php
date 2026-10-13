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
 * Phase 14 walk-in dispensing tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/dispense.php: the validation order (original lines
 * 37-45, plus the per-medicine daily limit that replaced the legacy 24h
 * duplicate guard), the allergy conflict -> decision screen (47-55) with
 * its zero-write invariant, the stock check (61-64), the
 * medicine_requests + deduct_stock_trace + dispense_logs transaction
 * (66-90) with its allergy override remark and audit actions, the
 * ?user_id= GET preselect vs POST-only medicine_id (12-13), the history
 * filter whitelist (120-130) with its invalid->all reset (unlike
 * sick_leaves' dead filter, the reset ALSO re-selects 'all'), and the
 * LIMIT 200 history (140).
 *
 * It also covers the two Phase 14 compatibility stubs
 * (staff/medicine_requests.php, user/request_medicine.php): no method check,
 * info flash written but never rendered.
 *
 * SQL parity: CONCAT/NOW()/SUBDATE()/CURDATE() run through the SQLite shims
 * in tests/TestCase.php. fire_event('medicine.dispensed'|'medicine.declined')
 * is not ported (no listener), so NO notification row may ever appear.
 */
class DispenseTest extends TestCase
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

        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('type', 20)->default('medicine');
            $table->string('description')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('generic_name', 150)->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('unit', 40)->default('tablet');
            $table->string('description')->nullable();
            $table->integer('low_stock_threshold')->default(10);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent();
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('medicine_id');
            $table->string('batch_no', 60)->nullable();
            $table->string('lot_no', 60)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->date('expiry_date')->nullable();
            $table->date('date_received');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        // Live shape: status ENUM('dispensed','rejected'), requested_at
        // DEFAULT current_timestamp, processed_at nullable.
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
            $table->dateTime('requested_at')->useCurrent();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->dateTime('processed_at')->nullable();
        });

        Schema::create('dispense_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->integer('quantity');
            $table->unsignedBigInteger('dispensed_by')->nullable();
            $table->text('remarks')->nullable();
            $table->dateTime('dispensed_at')->useCurrent();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('type', 4);
            $table->integer('quantity');
            $table->string('reason', 255)->nullable();
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('user_allergies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('allergen_name', 150);
            $table->string('severity', 20)->default('mild');
            $table->string('notes')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 80)->unique();
            $table->text('setting_value')->nullable();
            $table->dateTime('updated_at')->useCurrent();
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
    // Render (original 147-347)
    // ---------------------------------------------------------------------

    public function test_page_renders_modal_patients_medicines_and_empty_history(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $this->makePatient('Sam', 'Cruz', employeeNo: 'EMP-9');
        $this->makeUser('ghost', 'admin', null, 'Ghost', 'Admin'); // role=admin: NOT a patient
        $m1 = $this->makeMedicine('Biogesic', ['generic_name' => 'Paracetamol']);
        $this->makeBatch($m1, 7);
        $m2 = $this->makeMedicine('Bandage', ['low_stock_threshold' => 2]);
        $this->makeBatch($m2, 3);

        $this->actingAs($staff)->get('/staff/dispense')
            ->assertOk()
            ->assertSee('Medicine Dispensing')
            ->assertSee('data-patient-combobox="#dispensePatientId"', false)
            ->assertSee('list="dispensePatientSuggestions"', false)
            ->assertSee('Anna Reyes - ST-1', false)
            ->assertSee('Sam Cruz - EMP-9', false)
            ->assertDontSee('Ghost Admin')
            ->assertSee('-- select item --')
            ->assertSee('No medicine history in this view.')
            ->assertSee('Search visible history...')
            ->assertSee('name="_token"', false)
            // Medicine options carry the live stock...
            ->assertSee('Biogesic (Paracetamol) — tablet · stock 7', false)
            ->assertSee('data-stock="7"', false)
            // ...but data-limit is ALWAYS 10: the option loop's
            // $m['low_stock_threshold'] reads a key the 4-column query never
            // selected, so the ?? fallback wins (reference quirk, threshold 2
            // must not leak).
            ->assertSee('data-limit="10"', false)
            ->assertDontSee('data-limit="2"', false);

        $body = $this->actingAs($staff)->get('/staff/dispense')->getContent();
        $this->assertSame(2, substr_count($body, 'data-limit="10"'));
    }

    public function test_user_id_preselects_on_get_but_medicine_id_is_post_only(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);

        $this->actingAs($staff)->get('/staff/dispense?user_id='.$patient->id)
            ->assertOk()
            ->assertSee('id="dispensePatientId" name="user_id" value="'.$patient->id.'"', false);

        // ?medicine_id= on a GET must NOT preselect (POST-only input).
        $this->actingAs($staff)->get('/staff/dispense?medicine_id='.$m)
            ->assertOk()
            ->assertSee('<option value="'.$m.'" data-stock="7" data-limit="10" data-requested-today="0" >', false);
    }

    public function test_history_filter_whitelist_and_invalid_value_resets_to_all(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => 'Fever note',
            'status' => 'dispensed', 'requested_at' => '2026-10-01 09:00:00',
        ]);
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => 'Refused note',
            'status' => 'rejected', 'requested_at' => '2026-10-02 09:00:00',
        ]);

        $filtered = $this->actingAs($staff)->get('/staff/dispense?history_status=dispensed');
        $filtered->assertOk()->assertSee('Fever note')->assertDontSee('Refused note')
            ->assertSee('<option value="dispensed" selected>Dispensed</option>', false);

        $rejected = $this->actingAs($staff)->get('/staff/dispense?history_status=rejected');
        $rejected->assertSee('Refused note')->assertDontSee('Fever note');

        // Unknown status collapses to 'all' - and CONTRARY to sick_leaves'
        // dead filter, the whitelist RESET means 'all' renders as selected.
        $dead = $this->actingAs($staff)->get('/staff/dispense?history_status=banana');
        $dead->assertSee('Fever note')->assertSee('Refused note')
            ->assertSee('<option value="all" selected>All</option>', false);

        $none = $this->actingAs($staff)->get('/staff/dispense');
        $none->assertSee('<option value="all" selected>All</option>', false);
    }

    public function test_history_rows_render_details_badges_and_trailing_dash_fallbacks(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 2,
            'reason' => 'Fever', 'status' => 'dispensed', 'processed_by' => $staff->id,
            'requested_at' => '2026-10-01 09:30:00',
        ]);
        // No reason and no processor: both cells fall back to an em dash.
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => null,
            'status' => 'rejected', 'processed_by' => null,
            'requested_at' => '2026-09-30 08:00:00',
        ]);

        $this->actingAs($staff)->get('/staff/dispense')
            ->assertOk()
            ->assertSee('Oct 01, 2026 9:30 AM')
            ->assertSee('Sep 30, 2026 8:00 AM')
            ->assertSee('Anna Reyes')
            ->assertSee('Fever')
            ->assertSee('<td class="text-end">2</td>', false)
            ->assertSee('<span class="badge bg-success">Dispensed</span>', false)
            ->assertSee('<span class="badge bg-danger">Declined</span>', false)
            ->assertSee('staff')          // processed username
            ->assertSee('—')              // null reason + null processor
            ->assertDontSee('No medicine history in this view.');
    }

    /**
     * Module histories feature: the module page previews the 10 newest
     * dispense rows - the complete history sits behind View All at
     * /staff/history?tab=dispense, paginated at the module's historical page
     * size of 200 records.
     */
    public function test_module_previews_10_newest_and_history_paginates_at_200(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Caplet');
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => "Row $i",
                'status' => 'dispensed', 'requested_at' => sprintf('2026-09-01 %02d:%02d:00', $i % 24, $i % 60),
            ];
        }
        DB::table('medicine_requests')->insert($rows);

        // Recent preview: 10 rows plus the View All link.
        $module = $this->actingAs($staff)->get('/staff/dispense')->assertOk();
        $this->assertSame(10, substr_count($module->getContent(), '<td>Caplet</td>'), 'The module page previews 10 rows');
        $module->assertSee(route('staff.history', ['tab' => 'dispense']));

        // History: 200 rows per page, shared table markup, back link + nav.
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=dispense')->assertOk();
        $this->assertSame(200, substr_count($page1->getContent(), '<td>Caplet</td>'), 'History page 1 must render 200 rows');
        $page1->assertSee('Dispense History')
            ->assertSee('Dispense history pagination', false);

        $page2 = $this->actingAs($staff)->get('/staff/history?tab=dispense&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), '<td>Caplet</td>'));
    }

    /**
     * The history toolbar keeps the module's status semantics: a rejected
     * filter hides dispensed rows (and shows the empty state), a junk
     * value collapses to 'all', and the active filter rides along in every
     * pagination link.
     */
    public function test_history_status_filter_is_live_and_survives_pagination(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Caplet');
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = [
                'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => "Row $i",
                'status' => 'dispensed', 'requested_at' => sprintf('2026-09-01 %02d:%02d:00', $i % 24, $i % 60),
            ];
        }
        DB::table('medicine_requests')->insert($rows);

        // All rows are dispensed: filtering to rejected empties the table.
        $this->actingAs($staff)->get('/staff/history?tab=dispense&history_status=rejected')->assertOk()
            ->assertSee('No records found.')
            ->assertSee('<option value="rejected" selected>', false);

        // Junk status falls back to 'all' for both the dropdown and rows.
        $junk = $this->actingAs($staff)->get('/staff/history?tab=dispense&history_status=banana')->assertOk();
        $this->assertSame(200, substr_count($junk->getContent(), '<td>Caplet</td>'));
        $junk->assertSee('<option value="all" selected>', false);

        // A live value narrows nothing here (all rows match) but must be
        // selected in the dropdown AND present in every page link.
        $all = $this->actingAs($staff)->get('/staff/history?tab=dispense&history_status=dispensed')->assertOk();
        $this->assertSame(200, substr_count($all->getContent(), '<td>Caplet</td>'));
        $all->assertSee('<option value="dispensed" selected>', false)
            ->assertSee('history_status=dispensed', false);

        $page2 = $this->actingAs($staff)
            ->get('/staff/history?tab=dispense&history_status=dispensed&page=2')
            ->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), '<td>Caplet</td>'));
        $page2->assertSee('history_status=dispensed', false);
    }

    // ---------------------------------------------------------------------
    // Validation order (original 37-45)
    // ---------------------------------------------------------------------

    public function test_missing_fields_report_the_first_three_rules_in_order_without_writing(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/dispense', ['notes' => 'x']);
        $response->assertOk()
            ->assertSee('Please select a patient.')
            ->assertSee('Please select a medicine.')
            ->assertSee('Each request is limited to 1 unit.')
            // qty 0 never exceeds the limit -> the 5th rule stays silent.
            ->assertDontSee('Quantity exceeds the reasonable limit')
            // Errors reopen the modal (line 298).
            ->assertSee('new bootstrap.Modal(modal).show()', false);

        $body = $response->getContent();
        $this->assertLessThan(strpos($body, 'Please select a medicine.'), strpos($body, 'Please select a patient.'));
        $this->assertLessThan(strpos($body, 'Each request is limited to 1 unit.'), strpos($body, 'Please select a medicine.'));

        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_quantity_rules_use_the_product_threshold_and_the_setting_fallback(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $strict = $this->makeMedicine('Strict Med', ['low_stock_threshold' => 0]);
        $this->makeBatch($strict, 50);
        $normal = $this->makeMedicine('Normal Med'); // threshold 10
        $this->makeBatch($normal, 50);

        // threshold 0 clamps the reasonable limit to 1: qty 2 fires BOTH the
        // 1-unit rule and the limit rule.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $strict, 'quantity' => 2,
        ])->assertOk()
            ->assertSee('Each request is limited to 1 unit.')
            ->assertSee('Quantity exceeds the reasonable limit for this product (max 1).');

        // Default threshold 10: qty 15 fires both rules with max 10.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $normal, 'quantity' => 15,
        ])->assertOk()
            ->assertSee('Quantity exceeds the reasonable limit for this product (max 10).');

        // An unknown medicine keeps the system_settings default (3 here) as
        // the limit - and still reports the missing selection first.
        DB::table('system_settings')->insert([
            'setting_key' => 'default_dispense_limit', 'setting_value' => '3',
        ]);
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => 0, 'quantity' => 5,
        ])->assertOk()
            ->assertSee('Please select a medicine.')
            ->assertSee('Quantity exceeds the reasonable limit for this product (max 3).');

        $this->assertSame(0, DB::table('medicine_requests')->count());
    }

    public function test_user_id_falls_back_to_the_query_string_on_post(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');

        // The reference reads $_POST['user_id'] ?? $_GET['user_id']: a form
        // posting without the field keeps the preselect from the URL.
        $this->actingAs($staff)->post('/staff/dispense?user_id='.$patient->id, [
            'quantity' => 1,
        ])->assertOk()
            ->assertDontSee('Please select a patient.')
            ->assertSee('Please select a medicine.');

        $this->assertSame(0, DB::table('medicine_requests')->count());
    }

    // ---------------------------------------------------------------------
    // Allergy conflict -> decision screen (original 47-55)
    // ---------------------------------------------------------------------

    public function test_conflict_without_a_decision_renders_the_decision_screen_and_writes_nothing(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeAllergy($patient->id, $m, 'Biogesic', 'severe');

        $response = $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertSee('Allergy Warning')
            ->assertSee('Biogesic (severe)', false)
            ->assertSee('Allergy warning requires a decision.')
            ->assertSee('Accept and Dispense')
            ->assertSee('Reject Request')
            ->assertSee('Override reason *')
            ->assertSee('placeholder="e.g. Assessed by clinic physician; risk outweighed benefit"', false)
            ->assertSee('new bootstrap.Modal(modal).show()', false);

        // The decision screen must NOT touch stock, history or the audit log.
        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(7, $this->batchQuantity($m));
    }

    public function test_accept_without_an_override_reason_is_rejected_without_writing(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeAllergy($patient->id, $m, 'Biogesic', 'severe');

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
            'allergy_decision' => 'accept', 'override_reason' => '   ',
        ])->assertOk()
            ->assertSee('An override reason is required when accepting an allergy conflict.')
            ->assertSee('Allergy Warning')
            // With errors present the decision flag never gets set (original
            // line 47 guards it), so the decision screen does not re-render.
            ->assertDontSee('Allergy warning requires a decision.');

        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(7, $this->batchQuantity($m));
    }

    // ---------------------------------------------------------------------
    // Transaction (original 66-90)
    // ---------------------------------------------------------------------

    public function test_accept_with_override_dispenses_deducts_the_oldest_batch_and_audits(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $batchA = $this->makeBatch($m, 3, '2026-12-31'); // older expiry -> used first
        $batchB = $this->makeBatch($m, 5, '2027-06-30');
        $this->makeAllergy($patient->id, $m, 'Biogesic', 'severe');

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
            'allergy_decision' => 'accept',
            'override_reason' => 'Clinic physician approved',
            'notes' => 'Fever',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $req = DB::table('medicine_requests')->first();
        $this->assertNotNull($req);
        $this->assertSame('dispensed', $req->status);
        $this->assertSame(1, (int) $req->allergy_flag);
        $this->assertSame('Clinic physician approved', $req->override_reason);
        $this->assertSame('Fever', $req->reason);
        $this->assertSame(1, (int) $req->quantity);
        $this->assertSame((int) $patient->id, (int) $req->user_id);
        $this->assertSame((int) $staff->id, (int) $req->processed_by);
        $this->assertNotNull($req->processed_at);

        // Oldest expiry first: batchA 3 -> 2, batchB untouched.
        $this->assertSame(2, $this->batchQuantity($m, $batchA));
        $this->assertSame(5, $this->batchQuantity($m, $batchB));

        $mv = DB::table('stock_movements')->first();
        $this->assertSame('OUT', $mv->type);
        $this->assertSame(1, (int) $mv->quantity);
        $this->assertSame('Walk-in dispense: Fever', $mv->reason);
        $this->assertSame('dispense', $mv->ref_type);
        $this->assertSame((int) $req->id, (int) $mv->ref_id);
        $this->assertSame((int) $staff->id, (int) $mv->performed_by);

        $log = DB::table('dispense_logs')->first();
        $this->assertSame((int) $req->id, (int) $log->request_id);
        $this->assertSame((int) $patient->id, (int) $log->user_id);
        $this->assertSame((int) $batchA, (int) $log->batch_id);
        $this->assertSame(1, (int) $log->quantity);
        $this->assertSame((int) $staff->id, (int) $log->dispensed_by);
        $this->assertSame('[ALLERGY OVERRIDE: Clinic physician approved] Fever', $log->remarks);

        $audit = DB::table('audit_logs')->where('action', 'dispense_walkin')->first();
        $this->assertNotNull($audit);
        $this->assertSame('medicine_requests', $audit->table_name);
        $this->assertSame((int) $req->id, (int) $audit->record_id);
        $this->assertSame(
            "Dispensed walk-in request #{$req->id} for patient #{$patient->id}",
            $audit->details,
        );

        // Feature 2 (scoping): one alert, addressed to the patient the
        // medicine was handed to - the processing staff member gets none.
        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $patient->id, (int) $note->user_id);
        $this->assertSame('medicine_dispensed', $note->type);
        $this->assertSame((int) $req->id, (int) $note->reference_id);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $staff->id)->count());
    }

    public function test_reject_records_a_rejected_request_without_touching_stock(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeAllergy($patient->id, $m, 'Biogesic', 'severe');

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
            'allergy_decision' => 'reject',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Declined and added to the dispensing record.');

        $req = DB::table('medicine_requests')->first();
        // The UI says "Declined" but the stored enum member stays 'rejected'.
        $this->assertSame('rejected', $req->status);
        $this->assertSame(1, (int) $req->allergy_flag);
        $this->assertNull($req->override_reason);
        $this->assertSame('Walk-in dispense', $req->reason);

        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(7, $this->batchQuantity($m));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'reject_request')->count());
        // Decline uses the same channel with the medicine_declined switch.
        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $patient->id, (int) $note->user_id);
        $this->assertSame('medicine_declined', $note->type);
        $this->assertSame((int) $req->id, (int) $note->reference_id);
    }

    public function test_happy_path_without_conflict_defaults_the_reason_and_remarks(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $batch = $this->makeBatch($m, 7);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $req = DB::table('medicine_requests')->first();
        // Empty notes fall back to the reference's default purpose.
        $this->assertSame('Walk-in dispense', $req->reason);
        $this->assertSame(0, (int) $req->allergy_flag);
        $this->assertNull($req->override_reason);

        $log = DB::table('dispense_logs')->first();
        $this->assertSame((int) $batch, (int) $log->batch_id);
        // No conflicts and no notes -> the remarks string is empty, not NULL.
        $this->assertSame('', $log->remarks);

        $this->assertSame(6, $this->batchQuantity($m));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'dispense_walkin')->count());
        // A repeat of the scoping rule above (patient only, request id dedup).
        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $patient->id, (int) $note->user_id);
        $this->assertSame('medicine_dispensed', $note->type);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $staff->id)->count());
    }

    // ---------------------------------------------------------------------
    // Per-medicine daily limit (replaces the legacy 24h duplicate guard):
    // one request per user per specific tablet/capsule medicine per day
    // ---------------------------------------------------------------------

    public function test_the_same_tablet_medicine_cannot_be_requested_twice_on_the_same_day(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => 'Old note',
            'requested_at' => date('Y-m-d H:i:s'),
        ]);

        // Submitted directly to the backend - the UI is not involved.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
            'notes' => 'Fresh note',
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');

        // A rapid duplicate submission is refused too - nothing is written.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');

        $this->assertSame(1, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(7, $this->batchQuantity($m));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_two_different_tablet_medicines_can_be_requested_on_the_same_day(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $biogesic = $this->makeMedicine('Biogesic');
        $this->makeBatch($biogesic, 7);
        $kremil = $this->makeMedicine('Kremil-S');
        $this->makeBatch($kremil, 7);

        // Same purpose (notes) on another tablet: the old purpose-matching
        // guard would have blocked this - the new rule does not.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $biogesic, 'quantity' => 1,
            'notes' => 'Fever',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $kremil, 'quantity' => 1,
            'notes' => 'Fever',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $this->assertSame(2, DB::table('medicine_requests')->count());

        // A repeat of the FIRST medicine afterwards is still refused today.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $biogesic, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');
        $this->assertSame(2, DB::table('medicine_requests')->count());
    }

    public function test_two_different_capsule_medicines_can_be_requested_on_the_same_day(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $vitC = $this->makeMedicine('Vitamin C', ['unit' => 'capsule']);
        $this->makeBatch($vitC, 20);
        $vitB = $this->makeMedicine('Vitamin B Complex', ['unit' => 'Capsule']); // case-insensitive unit
        $this->makeBatch($vitB, 20);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $vitC, 'quantity' => 1,
            'notes' => 'Daily vitamin',
        ])->assertRedirect(route('staff.dispense'));

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $vitB, 'quantity' => 1,
            'notes' => 'Daily vitamin',
        ])->assertRedirect(route('staff.dispense'));

        $this->assertSame(2, DB::table('medicine_requests')->count());

        // The first capsule is still limited for the rest of the day.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $vitC, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');
        $this->assertSame(2, DB::table('medicine_requests')->count());
    }

    public function test_a_tablet_request_does_not_block_a_capsule_request_for_another_medicine(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $biogesic = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($biogesic, 7);
        $vitC = $this->makeMedicine('Vitamin C', ['unit' => 'capsule']);
        $this->makeBatch($vitC, 20);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $biogesic, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'));

        // Tablet first, capsule (another medicine) after: allowed.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $vitC, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'));

        $this->assertSame(2, DB::table('medicine_requests')->count());
    }

    public function test_first_aid_and_other_non_tablet_items_are_not_subject_to_the_daily_limit(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $gauze = $this->makeMedicine('Gauze Pads', ['unit' => 'box']);
        $this->makeBatch($gauze, 5);
        $roll = $this->makeMedicine('Rolled Gauze', ['unit' => 'roll']);
        $this->makeBatch($roll, 4);
        $burnGel = $this->makeMedicine('Burn Gel', ['unit' => 'tube']);
        $this->makeBatch($burnGel, 3);
        $alcohol = $this->makeMedicine('Rubbing Alcohol', ['unit' => 'bottle']);
        $this->makeBatch($alcohol, 2);

        // Gauze (box) twice on the same day - not blocked.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $gauze, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'));
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $gauze, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'));

        // Burn Gel (tube) and Rolled Gauze (roll) twice each from one
        // opened unit - their existing container/roll tracking rules.
        foreach ([$burnGel, $roll] as $partialId) {
            $this->actingAs($staff)->post('/staff/dispense', [
                'user_id' => $patient->id, 'medicine_id' => $partialId, 'quantity' => 1,
                'dispense_from' => 'new',
            ])->assertRedirect(route('staff.dispense'));
            $this->actingAs($staff)->post('/staff/dispense', [
                'user_id' => $patient->id, 'medicine_id' => $partialId, 'quantity' => 1,
                'dispense_from' => 'existing',
            ])->assertRedirect(route('staff.dispense'));
        }

        // Rubbing Alcohol (bottle) twice on the same day - not blocked.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $alcohol, 'quantity' => 1,
            'dispense_from' => 'new',
        ])->assertRedirect(route('staff.dispense'));
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $alcohol, 'quantity' => 1,
            'dispense_from' => 'existing',
        ])->assertRedirect(route('staff.dispense'));

        $this->assertSame(8, DB::table('medicine_requests')->count());

        // None of those first-aid requests counted toward the limit of an
        // eligible tablet medicine for the SAME patient the SAME day.
        $tablet = $this->makeMedicine('Biogesic');
        $this->makeBatch($tablet, 7);
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $tablet, 'requested_at' => date('Y-m-d H:i:s'),
        ]);
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $tablet, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');
    }

    public function test_the_same_medicine_can_be_requested_again_the_next_calendar_day(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        // Last night 23:30 - inside the old rolling 24-hour window, but on
        // the previous CALENDAR day: today's request must go through.
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'reason' => 'Late note',
            'requested_at' => date('Y-m-d H:i:s', strtotime('yesterday 23:30:00')),
        ]);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
            'notes' => 'Morning note',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $this->assertSame(2, DB::table('medicine_requests')->count());
    }

    public function test_daily_limits_are_independent_per_user(): void
    {
        $staff = $this->makeStaff();
        $anna = $this->makePatient('Anna', 'Reyes');
        $ben = $this->makePatient('Ben', 'Cruz', employeeNo: 'EMP-9');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeRequest([
            'user_id' => $anna->id, 'medicine_id' => $m, 'requested_at' => date('Y-m-d H:i:s'),
        ]);

        // Anna already has Biogesic today -> blocked.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $anna->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.');

        // Ben's limit is independent: his first request goes through.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $ben->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        $this->assertSame(2, DB::table('medicine_requests')->count());
        $this->assertDatabaseHas('medicine_requests', ['user_id' => $ben->id, 'medicine_id' => $m]);
    }

    public function test_the_daily_limit_runs_before_the_allergy_decision_screen_and_stock_checks(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 7);
        $this->makeAllergy($patient->id, $m, 'Biogesic', 'severe');
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $m, 'requested_at' => date('Y-m-d H:i:s'),
        ]);

        // The blocked request never reaches the allergy decision screen,
        // the stock check or the transaction - no writes at all.
        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('You have already requested this medicine today. You may request it again tomorrow.')
            ->assertDontSee('Allergy warning requires a decision.');

        $this->assertSame(1, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(7, $this->batchQuantity($m));
    }

    public function test_the_form_marks_only_the_blocked_medicine_for_the_selected_patient(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $biogesic = $this->makeMedicine('Biogesic');
        $this->makeBatch($biogesic, 7);
        $burnGel = $this->makeMedicine('Burn Gel', ['unit' => 'tube']);
        $this->makeBatch($burnGel, 3);
        $vitC = $this->makeMedicine('Vitamin C', ['unit' => 'capsule']);
        $this->makeBatch($vitC, 20);

        // The patient has Biogesic AND Burn Gel recorded today.
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $biogesic, 'requested_at' => date('Y-m-d H:i:s'),
        ]);
        $this->makeRequest([
            'user_id' => $patient->id, 'medicine_id' => $burnGel, 'requested_at' => date('Y-m-d H:i:s'),
        ]);

        $body = $this->actingAs($staff)->get('/staff/dispense?user_id='.$patient->id)
            ->assertOk()
            // Only the eligible Biogesic is marked as blocked; the
            // non-tablet Burn Gel and the untouched Vitamin C capsule stay
            // selectable and requestable.
            ->assertSee('<option value="'.$biogesic.'" data-stock="7" data-limit="10" data-requested-today="1" >', false)
            ->assertSee('<option value="'.$burnGel.'" data-stock="3" data-limit="10" data-requested-today="0" >', false)
            ->assertSee('<option value="'.$vitC.'" data-stock="20" data-limit="10" data-requested-today="0" >', false)
            ->assertSee('already requested today', false)
            // The daily-limit help div is now filled by the page script
            // only, so it is empty in the server-rendered HTML.
            ->getContent();
        $this->assertSame(1, substr_count($body, 'data-requested-today="1"'));
    }

    // ---------------------------------------------------------------------
    // Stock check (original 61-64)
    // ---------------------------------------------------------------------

    public function test_expired_batches_are_not_counted_and_insufficient_stock_writes_nothing(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes');
        $m = $this->makeMedicine('Biogesic');
        $this->makeBatch($m, 10, '2020-01-01'); // expired -> invisible to stock

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id, 'medicine_id' => $m, 'quantity' => 1,
        ])->assertOk()
            ->assertSee('Insufficient stock (0 available).');

        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(10, $this->batchQuantity($m)); // batch row untouched
    }

    // ---------------------------------------------------------------------
    // Authz: require_login(['staff']) + the two stubs
    // ---------------------------------------------------------------------

    public function test_authz_matches_require_login(): void
    {
        $staff = $this->makeStaff();
        $admin = $this->makeUser('adminx', 'admin', null);
        $student = $this->makeUser('studentx', 'user', 'student');

        $this->get('/staff/dispense')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/staff/dispense')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($student)->get('/staff/dispense')->assertRedirect(route('user.dashboard'));

        // The dispense POST sits behind the same guard.
        $this->actingAs($admin)->post('/staff/dispense', [
            'user_id' => 1, 'medicine_id' => 1, 'quantity' => 1,
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertSame(0, DB::table('medicine_requests')->count());

        $this->actingAs($staff)->get('/staff/dispense')->assertOk();
    }

    public function test_staff_medicine_requests_stub_redirects_with_an_info_flash(): void
    {
        $staff = $this->makeStaff();
        $admin = $this->makeUser('adminx', 'admin', null);

        // No method check in the original: GET and POST behave identically.
        $this->actingAs($staff)->get('/staff/medicine_requests')
            ->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('info', 'Medicine requests are recorded through Medicine Dispensing.');

        $this->actingAs($staff)->post('/staff/medicine_requests', ['anything' => 'x'])
            ->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('info', 'Medicine requests are recorded through Medicine Dispensing.');

        $this->actingAs($admin)->get('/staff/medicine_requests')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_user_request_medicine_stub_redirects_with_an_info_flash(): void
    {
        $student = $this->makeUser('studentx', 'user', 'student');
        $staff = $this->makeStaff();
        $flash = 'Please visit the clinic. Staff will enter your medicine request. '
            .'One request per tablet or capsule medicine per day. '
            .'Other eligible medicines may be requested separately. '
            .'First-aid supplies and other non-tablet/capsule items are not subject to this restriction.';

        $this->actingAs($student)->get('/user/request_medicine')
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('info', $flash);

        $this->actingAs($student)->post('/user/request_medicine')
            ->assertRedirect(route('user.dashboard'))
            ->assertSessionHas('info', $flash);

        $this->actingAs($staff)->get('/user/request_medicine')
            ->assertRedirect(route('staff.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeStaff(): User
    {
        return $this->makeUser('staff', 'staff', 'employee', 'Clinic', 'Staff');
    }

    private function makeUser(
        string $username,
        string $role,
        ?string $accountType,
        string $first = 'Some',
        string $last = 'User',
    ): User {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);

        return $user->fresh();
    }

    /** A role=user patient with the student or employee number branch filled. */
    private function makePatient(
        string $first,
        string $last,
        ?string $studentNo = null,
        ?string $employeeNo = null,
    ): User {
        $user = $this->makeUser(strtolower($first.$last), 'user', $studentNo ? 'student' : 'employee', $first, $last);

        if ($studentNo !== null) {
            DB::table('student_details')->insert(['user_id' => $user->id, 'student_no' => $studentNo]);
        }
        if ($employeeNo !== null) {
            DB::table('employee_details')->insert(['user_id' => $user->id, 'employee_no' => $employeeNo]);
        }

        return $user;
    }

    private function makeMedicine(string $name, array $extra = []): int
    {
        return DB::table('medicines')->insertGetId($extra + [
            'name' => $name,
            'unit' => 'tablet',
        ]);
    }

    private function makeBatch(int $medicineId, int $quantity, ?string $expiry = null): int
    {
        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'expiry_date' => $expiry,
            'date_received' => '2026-09-01',
        ]);
    }

    private function makeAllergy(int $userId, int $medicineId, string $allergen, string $severity = 'severe'): int
    {
        return DB::table('user_allergies')->insertGetId([
            'user_id' => $userId,
            'medicine_id' => $medicineId,
            'allergen_name' => $allergen,
            'severity' => $severity,
        ]);
    }

    private function makeRequest(array $attrs): int
    {
        return DB::table('medicine_requests')->insertGetId($attrs + [
            'quantity' => 1,
            'status' => 'dispensed',
        ]);
    }

    /** Available (non-expired) stock for one medicine or one batch row. */
    private function batchQuantity(int $medicineId, ?int $batchId = null): int
    {
        $query = DB::table('medicine_batches')->where('medicine_id', $medicineId);
        if ($batchId !== null) {
            $query->where('id', $batchId);
        }

        return (int) $query->sum('quantity');
    }
}
