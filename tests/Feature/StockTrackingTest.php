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
 * Tracking-method coverage for the Full/Half/Empty replacement - a NEW,
 * additive file: no pinned test file (InventoryTest, DispenseTest,
 * MedicineTest) is touched by this suite.
 *
 * The three methods and their guarantees:
 *
 *  - Individual (tablet, box, piece): exact deduction through
 *    deduct_stock_trace - usage/dispense_from posts are ignored. The
 *    Total Pieces cell shows the package position for counted units
 *    ("11/12 deducted" for tablets, "20/50 deducted" for boxes) and the
 *    plain stock count for units without a package size (piece).
 *  - Container + Partial (bottle, tube, jar): the cell reads the opened
 *    ratio "N/M opened" ("0/16 opened" when nothing is opened),
 *    dispensing never removes whole units, opening a new container is a
 *    separate event (opened +1, no batch change, open_container audit).
 *  - Roll + Partial (roll): the same model with roll wording ("1/4
 *    opened").
 *
 * Mark Depleted (action=deplete) is the third separate event: one unit
 * leaves the oldest usable batch through an OUT movement (ref_type
 * 'depletion'), the opened count drops, and deplete_container is audited.
 * Rejections and validation failures write nothing, exactly like the
 * existing dispensing workflow.
 *
 * Runs on the same isolated in-memory SQLite connection (schema copied from
 * DispenseTest's shape; CURDATE() goes through the shims in tests/TestCase).
 */
class StockTrackingTest extends TestCase
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

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
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
    // Rendering: inventory table + dispensing modal
    // ---------------------------------------------------------------------

    public function test_inventory_shows_package_deduction_and_no_fill_level_controls(): void
    {
        $staff = $this->makeStaff();
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 103);
        // 11 pieces dispensed so far - the cell walks 11/12 of the package.
        DB::table('stock_movements')->insert([
            'medicine_id' => $tablet, 'type' => 'OUT', 'quantity' => 11, 'ref_type' => 'dispense',
        ]);
        $fresh = $this->makeMedicine('Paracetamol', ['unit' => 'tablet']);
        $this->makeBatch($fresh, 50);
        $tool = $this->makeMedicine('Trauma Shears', ['unit' => 'piece']);
        $this->makeBatch($tool, 6);

        $response = $this->actingAs($staff)->get('/staff/inventory');
        $response->assertOk()
            // The tracking state now renders under Quantity / Stock (the old
            // Total Pieces column is gone), so each is a small sub-line.
            ->assertSee('>11/12 deducted</small>', false)   // quantitative: package position
            ->assertSee('>0/12 deducted</small>', false)    // fresh package, nothing deducted
            ->assertSee('>6</small>', false)                // no package size: plain count
            ->assertDontSee('Fill level', false)
            ->assertDontSee('value="full"', false)          // old level buttons gone
            ->assertDontSee('value="half"', false)
            ->assertDontSee('value="empty"', false);
    }

    public function test_inventory_shows_opened_ratio_for_partial_items(): void
    {
        $staff = $this->makeStaff();
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $gauze = $this->makeMedicine('Rolled Gauze', ['unit' => 'roll']);
        $burn = $this->makeMedicine('Burn Gel', ['unit' => 'tube']);
        $this->makeBatch($oil, 9);
        $this->makeBatch($gauze, 4);
        $this->makeBatch($burn, 16);
        DB::table('system_settings')->insert([
            ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1'],
            ['setting_key' => 'stock_opened_'.$gauze, 'setting_value' => '1'],
        ]);

        $response = $this->actingAs($staff)->get('/staff/inventory');
        $response->assertOk()
            ->assertSee('>1/9 opened</small>', false)    // opened / total
            ->assertSee('>1/4 opened</small>', false)
            ->assertSee('>0/16 opened</small>', false);  // fresh, nothing opened
    }

    public function test_dispense_form_renders_tracking_json_and_controls_without_fill_level(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 103);

        $response = $this->actingAs($staff)->get('/staff/dispense');
        $response->assertOk()
            ->assertDontSee('Fill level', false)
            ->assertDontSee('Pieces in the open package', false)
            ->assertSee('id="dispenseTrackingBlock"', false)
            ->assertSee('name="dispense_from"', false)
            ->assertSee('name="usage"', false)
            ->assertSee('id="dispenseDepleteBlock"', false)
            ->assertSee('value="deplete"', false)
            ->assertSee('"method":"container"', false)
            ->assertSee('"method":"individual"', false)
            ->assertSee('"text":"1\\/9 opened"', false)                                   // compact cell copy
            ->assertSee('"label":"8 unopened bottles \\u00b7 1 opened bottle"', false)   // modal Stock line
            ->assertSee('"text":"0\\/12 deducted"', false)
            ->assertSee('"label":"103 tablets"', false);
    }

    // ---------------------------------------------------------------------
    // Opening: a separate inventory event, no batch change
    // ---------------------------------------------------------------------

    public function test_opening_a_new_container_records_open_event_without_deducting_stock(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $oil,
            'quantity' => 1,
            'dispense_from' => 'new',
            'usage' => 'moderate',
            'notes' => 'Rubbed on student forearm',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        // Opening moves the unit from unopened to opened - stock is untouched.
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);
        $this->assertSame(9, $this->batchQuantity($oil));
        $this->assertSame(0, DB::table('stock_movements')->count());

        // The dispensing event and the opening event are recorded separately.
        $this->assertDatabaseHas('dispense_logs', [
            'medicine_id' => $oil,
            'quantity' => 1,
            'batch_id' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispense_walkin', 'table_name' => 'medicine_requests']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'open_container',
            'table_name' => 'medicines',
            'record_id' => $oil,
        ]);
        $this->assertSame(2, DB::table('audit_logs')->count());

        // Usage is a record only - stored as a label, never a quantity.
        $log = DB::table('dispense_logs')->first();
        $this->assertStringContainsString('[USAGE: Moderate]', (string) $log->remarks);
        $this->assertStringContainsString('Rubbed on student forearm', (string) $log->remarks);
    }

    public function test_dispense_from_existing_opened_container_records_usage_only(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $oil,
            'quantity' => 1,
            'dispense_from' => 'existing',
            'usage' => 'small',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        // Usage only: no batch change, no movement, opened count unchanged.
        $this->assertSame(9, $this->batchQuantity($oil));
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);

        // One dispense event, no open event this time.
        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispense_walkin']);
        $log = DB::table('dispense_logs')->first();
        $this->assertStringContainsString('[USAGE: Small]', (string) $log->remarks);
    }

    // ---------------------------------------------------------------------
    // Mark Depleted: the third separate event, the real deduction
    // ---------------------------------------------------------------------

    public function test_mark_depleted_deducts_one_unit_and_audits_the_event(): void
    {
        $staff = $this->makeStaff();
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);

        $this->actingAs($staff)->post('/staff/dispense', [
            'action' => 'deplete',
            'medicine_id' => $oil,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Opened container marked as depleted. Stock updated.');

        $this->assertSame(8, $this->batchQuantity($oil));
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '0']);
        $this->assertDatabaseHas('stock_movements', [
            'medicine_id' => $oil,
            'type' => 'OUT',
            'quantity' => 1,
            'ref_type' => 'depletion',
            'performed_by' => $staff->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deplete_container',
            'table_name' => 'medicines',
            'record_id' => $oil,
        ]);

        // Nothing left to deplete: second POST is refused without writes.
        $this->actingAs($staff)->post('/staff/dispense', [
            'action' => 'deplete',
            'medicine_id' => $oil,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('danger', 'There is no opened container to deplete.');
        $this->assertSame(8, $this->batchQuantity($oil));
        $this->assertSame(1, DB::table('stock_movements')->count());
    }

    public function test_roll_depletion_uses_roll_wording(): void
    {
        $staff = $this->makeStaff();
        $roll = $this->makeMedicine('Rolled Gauze', ['unit' => 'roll']);
        $this->makeBatch($roll, 4);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$roll, 'setting_value' => '1']);

        $this->actingAs($staff)->post('/staff/dispense', [
            'action' => 'deplete',
            'medicine_id' => $roll,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Opened roll marked as depleted. Stock updated.');
        $this->assertSame(3, $this->batchQuantity($roll));
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$roll, 'setting_value' => '0']);
    }

    public function test_individual_items_do_not_use_container_tracking(): void
    {
        $staff = $this->makeStaff();
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 10);

        $this->actingAs($staff)->post('/staff/dispense', [
            'action' => 'deplete',
            'medicine_id' => $tablet,
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('danger', 'This item does not use container tracking.');
        $this->assertSame(10, $this->batchQuantity($tablet));
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    // ---------------------------------------------------------------------
    // Validation: source choices are checked before anything is written
    // ---------------------------------------------------------------------

    public function test_dispense_from_existing_requires_an_opened_container(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $oil,
            'quantity' => 1,
            'dispense_from' => 'existing',
        ])->assertOk()
            ->assertSee('There is no opened container yet.', false);

        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(9, $this->batchQuantity($oil));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_open_new_requires_an_unopened_unit_left(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 2);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '2']);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $oil,
            'quantity' => 1,
            'dispense_from' => 'new',
        ])->assertOk()
            ->assertSee('No unopened container is left.', false);

        $this->assertSame(0, DB::table('medicine_requests')->count());
        $this->assertSame(2, $this->batchQuantity($oil));
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '2']);
    }

    // ---------------------------------------------------------------------
    // Rejection and Individual parity: the existing workflow stays intact
    // ---------------------------------------------------------------------

    public function test_rejected_partial_dispense_changes_no_inventory(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $oil = $this->makeMedicine('Efficascent Oil', ['unit' => 'bottle']);
        $this->makeBatch($oil, 9);
        DB::table('system_settings')->insert(['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);
        $this->makeAllergy($patient->id, $oil, 'menthol');

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $oil,
            'quantity' => 1,
            'dispense_from' => 'new',
            'allergy_decision' => 'reject',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Declined and added to the dispensing record.');

        // Only the declined request record - no opening, no dispensing, no movement.
        $this->assertSame(1, DB::table('medicine_requests')->count());
        $this->assertDatabaseHas('medicine_requests', ['medicine_id' => $oil, 'status' => 'rejected']);
        $this->assertSame(0, DB::table('dispense_logs')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('system_settings', ['setting_key' => 'stock_opened_'.$oil, 'setting_value' => '1']);
        $this->assertSame(9, $this->batchQuantity($oil));
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'open_container')->count());
    }

    public function test_individual_dispense_still_deducts_exact_pieces_and_ignores_tracking_posts(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 10);

        $this->actingAs($staff)->post('/staff/dispense', [
            'user_id' => $patient->id,
            'medicine_id' => $tablet,
            'quantity' => 1,
            'dispense_from' => 'new',
            'usage' => 'large',
        ])->assertRedirect(route('staff.dispense'))
            ->assertSessionHas('success', 'Dispensed and added to the dispensing record.');

        // Exact piece deduction - one tablet leaves stock through the usual
        // OUT movement; the partial-only posts are ignored server-side.
        $this->assertSame(9, $this->batchQuantity($tablet));
        $this->assertDatabaseHas('stock_movements', [
            'medicine_id' => $tablet,
            'type' => 'OUT',
            'quantity' => 1,
            'ref_type' => 'dispense',
        ]);
        $log = DB::table('dispense_logs')->first();
        $this->assertStringNotContainsString('[USAGE:', (string) $log->remarks);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dispense_walkin']);
        $this->assertSame(1, DB::table('audit_logs')->count());
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

    /** A role=user patient with the student number branch filled. */
    private function makePatient(string $first, string $last, ?string $studentNo = null): User
    {
        $user = $this->makeUser(strtolower($first.$last), 'user', $studentNo ? 'student' : 'employee', $first, $last);

        if ($studentNo !== null) {
            DB::table('student_details')->insert(['user_id' => $user->id, 'student_no' => $studentNo]);
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

    /** Total quantity across one medicine's batches. */
    private function batchQuantity(int $medicineId): int
    {
        return (int) DB::table('medicine_batches')->where('medicine_id', $medicineId)->sum('quantity');
    }
}
