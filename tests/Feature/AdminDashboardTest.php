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
 * Phase 7 admin dashboard tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers admin/dashboard.php's four stat counters (lines 15-18) and its
 * three data cards (lines 22-54): low stock, expiring within 90 days and
 * recent activity - including the original's edge behaviour (expired stock
 * excluded from sums, inactive medicines excluded from low stock but NOT
 * from the expiring list, unused $activeLoans query not rendered).
 *
 * SQL parity note: the port keeps the original predicates but spells the
 * day arithmetic as ADDDATE(CURDATE(), 90) (same MySQL result as the
 * original DATE_ADD(CURDATE(), INTERVAL 90 DAY)); tests/TestCase.php
 * registers curdate()/adddate() with their MySQL semantics so the exact
 * production SQL runs unmodified on SQLite - the same technique as
 * PatientTest's concat() shim.
 */
class AdminDashboardTest extends TestCase
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

        // Shell pieces the shared layout reads (same as LoginTest/PatientTest).
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

        // The three tables /admin/dashboard queries - shapes mirror the live
        // schema (see information_schema dump, Phase 7 probe).
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('generic_name', 150)->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->string('unit', 40)->default('tablet');
            $table->string('description', 255)->nullable();
            $table->integer('low_stock_threshold')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('medicine_id');
            $table->string('batch_no', 60)->nullable();
            $table->string('lot_no', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->date('expiry_date')->nullable();
            $table->date('date_received');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
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
    // Stat counters (original lines 15-18) + card structure
    // ---------------------------------------------------------------------

    public function test_four_metrics_count_the_original_sets_and_cards_render(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);
        $this->makeUser('student1', 'Juan', 'Dela Cruz', ['role' => 'user', 'account_type' => 'student']);
        $this->makeUser('employee1', 'Maria', 'Santos', ['role' => 'user', 'account_type' => 'employee']);
        $this->makeUser('staff', 'Clinic', 'Staff', ['role' => 'staff', 'account_type' => 'employee']);

        $activeA = $this->makeMedicine('Paracetamol', 10, true);
        $this->makeMedicine('Ibuprofen', 10, true);
        $this->makeMedicine('Discontinued Med', 10, false);   // is_active = 1 filter
        $this->makeBatch($activeA, 5);                        // part of the stock total below
        $this->makeBatch($activeA, 50);                       // stock 55 > 10 => NOT low stock

        $this->makeRequest(date('Y-m-d 08:00:00'));           // today
        $this->makeRequest(date('Y-m-d 08:00:00', strtotime('-1 day')));  // yesterday - excluded

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('Admin Dashboard');
        // Exact metric cells: role=user => 2, staff => 1, active meds => 2,
        // requests today => 1 (the inactive medicine and yesterday's request
        // must not count).
        $response->assertSee('<div class="metric-value">2</div>', false);
        $response->assertSee('<div class="metric-value">1</div>', false);
        $response->assertSee('<div class="metric-label">Students / Employees</div>', false);
        $response->assertSee('<div class="metric-label">Clinic Staff</div>', false);
        $response->assertSee('<div class="metric-label">Active Medicines</div>', false);
        $response->assertSee('<div class="metric-label">Requests Today</div>', false);
        // All three cards with their original headers.
        $response->assertSee('Low Stock');
        $response->assertSee('Expiring Within 90 Days');
        $response->assertSee('Recent Activity');
        $response->assertSee('View All');
        $response->assertSee(
            '<a href="http://localhost/admin/audit_logs" class="btn btn-sm btn-outline-primary">View All</a>',
            false,
        );
        // Scaffold from Phase 5 must be gone for admin.
        $response->assertDontSee('migrated in later phases', false);
    }

    // ---------------------------------------------------------------------
    // Low Stock card (original lines 22-33)
    // ---------------------------------------------------------------------

    public function test_low_stock_lists_active_medicines_at_or_under_threshold(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);

        // Below threshold (no expiry) => listed with summed stock.
        $low = $this->makeMedicine('Saline Wash', 20, true);
        $this->makeBatch($low, 6);
        $this->makeBatch($low, 6);   // stock 12

        // Above threshold => not listed.
        $fine = $this->makeMedicine('Rubbing Alcohol', 20, true);
        $this->makeBatch($fine, 50);

        // Only an EXPIRED batch => sum excludes it => stock 0 <= threshold
        // => listed with 0 (original behaviour).
        $expiredOnly = $this->makeMedicine('Expired Med', 10, true);
        $this->makeBatch($expiredOnly, 8, date('Y-m-d', strtotime('-1 day')));

        // Inactive medicine => never listed, even when low.
        $inactive = $this->makeMedicine('Off Market', 10, false);
        $this->makeBatch($inactive, 1);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('<td>Saline Wash</td>', false);
        $response->assertSee('<td class="text-end"><span class="badge bg-danger">12</span></td>', false);
        $response->assertSee('<td class="text-end">20</td>', false);
        $response->assertSee('<td>Expired Med</td>', false);
        $response->assertSee('<td class="text-end"><span class="badge bg-danger">0</span></td>', false);
        $response->assertDontSee('<td>Rubbing Alcohol</td>', false);
        $response->assertDontSee('<td>Off Market</td>', false);
        // With rows present, the original empty state row must not appear.
        $response->assertDontSee('No low-stock items.', false);
    }

    public function test_low_stock_is_ordered_ascending_and_capped_at_eight(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);

        for ($i = 1; $i <= 10; $i++) {
            $medicine = $this->makeMedicine('Med '.$i, 100, true);
            $this->makeBatch($medicine, $i);
        }

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        // ORDER BY stock ASC, LIMIT 8: stocks 1..8 in order...
        $response->assertSeeInOrder([
            '<span class="badge bg-danger">1</span>',
            '<span class="badge bg-danger">2</span>',
            '<span class="badge bg-danger">7</span>',
            '<span class="badge bg-danger">8</span>',
        ]);
        // ...9 and 10 cut off by the LIMIT.
        $response->assertDontSee('<span class="badge bg-danger">9</span>', false);
        $response->assertDontSee('<span class="badge bg-danger">10</span>', false);
    }

    public function test_low_stock_empty_state(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);
        // A medicine comfortably above threshold => no rows.
        $medicine = $this->makeMedicine('Well Stocked', 5, true);
        $this->makeBatch($medicine, 99);

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<td colspan="3" class="text-center text-muted py-3">No low-stock items.</td>', false);
    }

    // ---------------------------------------------------------------------
    // Expiring Within 90 Days card (original lines 36-45)
    // ---------------------------------------------------------------------

    public function test_expiring_shows_only_positive_stock_within_the_window(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);
        $medicine = $this->makeMedicine('Vitamin C', 5, true);

        // Inside the window with stock => listed (date formatted "M d, Y").
        $this->makeBatch($medicine, 12, date('Y-m-d', strtotime('+30 days')), 'LOT-30');
        // NULL batch number renders the em-dash.
        $this->makeBatch($medicine, 6, date('Y-m-d', strtotime('+40 days')));
        // Beyond 90 days => hidden.
        $this->makeBatch($medicine, 6, date('Y-m-d', strtotime('+200 days')), 'LOT-200');
        // Already expired => hidden.
        $this->makeBatch($medicine, 6, date('Y-m-d', strtotime('-1 day')), 'LOT-PAST');
        // Zero quantity => hidden.
        $this->makeBatch($medicine, 0, date('Y-m-d', strtotime('+10 days')), 'LOT-ZERO');

        // Inactive medicine batches ARE still listed (original query does not
        // filter is_active here - documented parity behaviour).
        $inactive = $this->makeMedicine('Off Med', 5, false);
        $this->makeBatch($inactive, 4, date('Y-m-d', strtotime('+5 days')), 'LOT-INACT');

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('<td>LOT-30</td>', false);
        $response->assertSee('<td>'.date('M d, Y', strtotime('+30 days')).'</td>', false);
        $response->assertSee('<td>—</td>', false);
        $response->assertSee('<td>LOT-INACT</td>', false);
        $response->assertDontSee('<td>LOT-200</td>', false);
        $response->assertDontSee('<td>LOT-PAST</td>', false);
        $response->assertDontSee('<td>LOT-ZERO</td>', false);
    }

    public function test_expiring_empty_state(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);
        $medicine = $this->makeMedicine('No Expiry Med', 5, true);
        $this->makeBatch($medicine, 10, null);   // NULL expiry never listed

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<td colspan="4" class="text-center text-muted py-3">No batches expiring soon.</td>', false);
    }

    // ---------------------------------------------------------------------
    // Recent Activity card (original lines 48-54)
    // ---------------------------------------------------------------------

    public function test_recent_activity_shows_last_eight_rows_with_username_and_system(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);

        // 10 rows; only the newest 8 may render (ORDER BY created_at DESC).
        for ($i = 1; $i <= 10; $i++) {
            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => 'action_'.$i,
                'details' => 'details '.$i,
                'created_at' => sprintf('2026-01-%02d 10:00:00', $i),
            ]);
        }
        // A row without a user renders the original "system" fallback
        // (user_id is nullable in the live schema; created_at is NOT NULL
        // with DEFAULT CURRENT_TIMESTAMP, so this row lands as the newest).
        DB::table('audit_logs')->insert([
            'user_id' => null,
            'action' => 'system_seed',
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');

        $response->assertOk();
        $response->assertSee('<td>admin</td>', false);                 // username of joined user
        $response->assertSee('<td>system</td>', false);                // NULL user fallback
        $response->assertSee('<span class="chip">action_10</span>', false);
        $response->assertSee('details 8');
        $response->assertSee('2026-01-10 10:00:00');                   // fmt_dt default format
        $response->assertDontSee('action_2</span>', false);            // cut off by LIMIT 8
        $response->assertDontSee('action_1</span>', false);
    }

    public function test_recent_activity_empty_state(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);

        $this->actingAs($admin)->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<td colspan="4" class="text-center text-muted py-3">No activity yet.</td>', false);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(
        string $username,
        string $firstName,
        string $lastName,
        array $overrides = [],
    ): User {
        $user = User::create(array_merge([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'active',
        ], $overrides));

        Profile::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        return $user->fresh();
    }

    /** Insert one medicines row and return its id. */
    private function makeMedicine(string $name, int $threshold, bool $active): int
    {
        return DB::table('medicines')->insertGetId([
            'name' => $name,
            'low_stock_threshold' => $threshold,
            'is_active' => $active ? 1 : 0,
        ]);
    }

    /** Insert one medicine_batches row (expiry/batch number optional). */
    private function makeBatch(
        int $medicineId,
        int $quantity,
        ?string $expiryDate = null,
        ?string $batchNo = null,
    ): int {
        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicineId,
            'batch_no' => $batchNo,
            'quantity' => $quantity,
            'expiry_date' => $expiryDate,
            'date_received' => date('Y-m-d'),
        ]);
    }

    /** Insert one medicine_requests row with an explicit requested_at. */
    private function makeRequest(string $requestedAt): int
    {
        return DB::table('medicine_requests')->insertGetId([
            'user_id' => 1,
            'medicine_id' => 1,
            'quantity' => 1,
            'requested_at' => $requestedAt,
        ]);
    }
}
