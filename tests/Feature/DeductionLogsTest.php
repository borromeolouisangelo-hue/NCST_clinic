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
 * Phase 15 deduction log tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/deduction_logs.php: the OUT-movement list (original 14-40)
 * with its four-param search (medicine/first/last/batch), the
 * ['dispensed','rejected'] status whitelist (any other value adds NO
 * filter), the LIMIT 200 cap, the dispense-ref LEFT JOINs (patient via
 * profile, status via request - both NULL-safe with the '—'/'N/A'
 * fallbacks), the two GLOBAL counters (200/44-45 - they ignore search and
 * filter), and the IN-movement exclusion. The page never writes audit rows.
 *
 * SQL parity: CONCAT and CURDATE() run through the SQLite shims in
 * tests/TestCase.php; DATE() is native SQLite.
 */
class DeductionLogsTest extends TestCase
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
    // Render + counters (original 42-63)
    // ---------------------------------------------------------------------

    public function test_empty_state_and_zero_counters(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/deduction_logs')
            ->assertOk()
            ->assertSee('Total Deductions')
            ->assertSee("Today's Deductions", false)
            ->assertSee('<div class="metric-value">0</div>', false)
            ->assertSee('No deduction records found.', false)
            ->assertSee('Medicine, patient, or batch...', false)
            ->assertSee('All Status');
    }

    public function test_counters_are_global_and_independent_of_filters(): void
    {
        $staff = $this->makeStaff();
        $med = $this->makeMedicine('Ibuprofen');
        $this->makeBatch($med, 10);

        // One OUT movement on an old day, one OUT today, one IN (ignored).
        $this->makeMovement($med, 'OUT', ['created_at' => '2020-05-05 10:00:00']);
        $this->makeMovement($med, 'OUT', []);
        $this->makeMovement($med, 'IN', []);

        $this->actingAs($staff)->get('/staff/deduction_logs?q=nomatch')
            ->assertOk()
            ->assertSee('No deduction records found.', false)
            // Counters ignore the (empty) search result: 2 OUT, 1 today.
            ->assertSee('<div class="metric-value">2</div>', false)
            ->assertSee('<div class="metric-value">1</div>', false);
    }

    // ---------------------------------------------------------------------
    // Rows (original 104-127)
    // ---------------------------------------------------------------------

    public function test_row_renders_patient_batch_and_dispensed_badge(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('stu', 'user', 'student');
        Profile::where('user_id', $user->id)->update(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $med = $this->makeMedicine('Ibuprofen');
        $bid = $this->makeBatch($med, 10, null, 'BATCH01');
        $rid = DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id,
            'medicine_id' => $med,
            'status' => 'dispensed',
            'reason' => 'Headache',
        ]);
        $this->makeMovement($med, 'OUT', [
            'batch_id' => $bid,
            'quantity' => 2,
            'reason' => 'Walk-in dispense',
            'ref_type' => 'dispense',
            'ref_id' => $rid,
        ]);

        $this->actingAs($staff)->get('/staff/deduction_logs')
            ->assertOk()
            ->assertSee('Ibuprofen', false)
            ->assertSee('BATCH01', false)
            ->assertSee('Jane Doe', false)
            ->assertSee('Walk-in dispense', false)
            ->assertSee('>2 tablet</td>', false)
            ->assertSee('>2 pcs</td>', false)
            ->assertSee('>Dispensed</span>', false)
            ->assertDontSee('>Declined</span>', false)
            ->assertDontSee('>N/A</span>', false);
    }

    public function test_rejected_badge_and_na_fallback(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('stu', 'user', 'student');

        $medA = $this->makeMedicine('Ibuprofen');
        $medB = $this->makeMedicine('Paracetamol');
        $rid = DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id,
            'medicine_id' => $medA,
            'status' => 'rejected',
        ]);
        $this->makeMovement($medA, 'OUT', ['ref_type' => 'dispense', 'ref_id' => $rid]);
        // No dispense ref at all -> status NULL -> 'N/A' badge, '—' patient.
        $this->makeMovement($medB, 'OUT', ['ref_type' => 'adjust']);

        $this->actingAs($staff)->get('/staff/deduction_logs')
            ->assertOk()
            ->assertSee('>Declined</span>', false)
            ->assertSee('>N/A</span>', false)
            ->assertDontSee('>Dispensed</span>', false);
    }

    public function test_in_movements_never_list(): void
    {
        $staff = $this->makeStaff();
        $med = $this->makeMedicine('IN Only Med');
        $this->makeMovement($med, 'IN', []);

        $this->actingAs($staff)->get('/staff/deduction_logs')
            ->assertOk()
            ->assertSee('No deduction records found.', false)
            ->assertDontSee('IN Only Med', false);
    }

    // ---------------------------------------------------------------------
    // Filters + search (original 11-37)
    // ---------------------------------------------------------------------

    public function test_status_whitelist_filters_and_junk_falls_through(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('stu', 'user', 'student');

        $medA = $this->makeMedicine('Alpha Med');
        $medB = $this->makeMedicine('Beta Med');
        $ridA = DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id, 'medicine_id' => $medA, 'status' => 'dispensed',
        ]);
        $ridB = DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id, 'medicine_id' => $medB, 'status' => 'rejected',
        ]);
        $this->makeMovement($medA, 'OUT', ['ref_type' => 'dispense', 'ref_id' => $ridA]);
        $this->makeMovement($medB, 'OUT', ['ref_type' => 'dispense', 'ref_id' => $ridB]);

        // Whitelisted value filters AND echoes its selected state.
        $this->actingAs($staff)->get('/staff/deduction_logs?status=dispensed')
            ->assertOk()
            ->assertSee('>Alpha Med</td>', false)
            ->assertDontSee('>Beta Med</td>', false)
            ->assertSee('value="dispensed" selected', false)
            ->assertDontSee('value="rejected" selected', false);

        // Junk value: no filter at all, no selected state on either option.
        $this->actingAs($staff)->get('/staff/deduction_logs?status=banana')
            ->assertOk()
            ->assertSee('>Alpha Med</td>', false)
            ->assertSee('>Beta Med</td>', false)
            ->assertDontSee('value="dispensed" selected', false)
            ->assertDontSee('value="rejected" selected', false);
    }

    public function test_search_matches_medicine_patient_and_batch(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('stu', 'user', 'student');
        Profile::where('user_id', $user->id)->update(['first_name' => 'John', 'last_name' => 'Smith']);

        $med = $this->makeMedicine('Ibuprofen');
        $bid = $this->makeBatch($med, 10, null, 'BATCH01');
        $rid = DB::table('medicine_requests')->insertGetId([
            'user_id' => $user->id, 'medicine_id' => $med, 'status' => 'dispensed',
        ]);
        $this->makeMovement($med, 'OUT', ['batch_id' => $bid, 'ref_type' => 'dispense', 'ref_id' => $rid]);

        $med2 = $this->makeMedicine('Paracetamol');
        $this->makeMovement($med2, 'OUT', []);

        // Medicine name.
        $this->actingAs($staff)->get('/staff/deduction_logs?q=Ibu')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('>Paracetamol</td>', false);

        // Patient last name (through the request -> profile join).
        $this->actingAs($staff)->get('/staff/deduction_logs?q=Smith')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('>Paracetamol</td>', false);

        // Batch number.
        $this->actingAs($staff)->get('/staff/deduction_logs?q=batch01')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('>Paracetamol</td>', false);

        // No match -> empty state (but counters stay global).
        $this->actingAs($staff)->get('/staff/deduction_logs?q=zzzz')
            ->assertOk()
            ->assertSee('No deduction records found.', false);
    }

    public function test_list_caps_at_200_rows(): void
    {
        $staff = $this->makeStaff();
        $med = $this->makeMedicine('Bulk Med');

        $base = strtotime('2026-01-01 00:00:00');
        for ($i = 1; $i <= 205; $i++) {
            $this->makeMovement($med, 'OUT', [
                'reason' => sprintf('ROW-%03d', $i),
                'created_at' => date('Y-m-d H:i:s', $base + $i * 60),
            ]);
        }

        // ORDER BY created_at DESC LIMIT 200 -> ROW-205 .. ROW-006.
        $this->actingAs($staff)->get('/staff/deduction_logs')
            ->assertOk()
            ->assertSee('ROW-205', false)
            ->assertSee('ROW-006', false)
            ->assertDontSee('ROW-005', false)
            ->assertDontSee('ROW-001', false);
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

    private function makeMedicine(string $name, array $extra = []): int
    {
        return DB::table('medicines')->insertGetId($extra + [
            'name' => $name,
            'unit' => 'tablet',
        ]);
    }

    private function makeBatch(int $medicineId, int $quantity, ?string $expiry = null, ?string $batchNo = null): int
    {
        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicineId,
            'batch_no' => $batchNo,
            'quantity' => $quantity,
            'expiry_date' => $expiry,
            'date_received' => '2026-09-01',
        ]);
    }

    private function makeMovement(int $medicineId, string $type, array $extra = []): int
    {
        // Seed created_at through the PHP clock (app TZ Europe/Berlin): the
        // column's SQLite CURRENT_TIMESTAMP default is UTC, while CURDATE()
        // comes from the PHP-TZ shim - the two disagree during the first two
        // hours after local midnight (CEST = UTC+2) and the "Today" counter
        // test would flake there. Explicit dates in $extra still win.
        $extra += ['created_at' => date('Y-m-d H:i:s')];

        return DB::table('stock_movements')->insertGetId($extra + [
            'medicine_id' => $medicineId,
            'type' => $type,
            'quantity' => 2,
            'reason' => 'Dispense',
        ]);
    }
}
