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
 * Search / filter coverage for the two pages whose toolbar inputs were
 * wired up in this pass - a NEW, additive file: no pinned test file
 * (InventoryTest, DispenseTest, MedicineTest) is touched by this suite.
 *
 * Inventory (/staff/inventory):
 *  - ?q= partial / exact / case-insensitive item-name matching (plain
 *    LIKE, the same convention every other list search in the app uses),
 *    plus the category name the placeholder promises ("Name or
 *    category..."), composed with the existing ?item_type= and
 *    ?category= filters (AND semantics).
 *  - ?stock= picks one of three disjoint buckets over the aggregated
 *    quantity (out = 0, low = 1..threshold, in = above threshold) and
 *    composes with ?q=; unknown values fall back to "all rows".
 *  - The Total Pieces cell formats stay untouched by filtering:
 *    "{n}/12 deducted" (quantitative), "{opened}/{total} opened"
 *    (container/roll), plain count (no package size).
 *
 * Dispense (/staff/dispense):
 *  - The history search input's data-live-filter selector must target
 *    the table id the page actually renders (#dispenseTable, not the
 *    never-emitted #dispenseHistoryTable - the typo that made the search
 *    a no-op), and the dispensing modal markup must stay in place.
 *
 * Runs on the same isolated in-memory SQLite connection (schema copied
 * from StockTrackingTest's shape; CURDATE() goes through the shims in
 * tests/TestCase).
 */
class SearchFilterTest extends TestCase
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
            $table->unsignedBigInteger('user_id');
            $table->string('student_no', 40);
            $table->string('course', 120)->nullable();
            $table->string('year_level', 40)->nullable();
            $table->string('section', 60)->nullable();
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
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
    // Inventory: ?q= (empty / exact / partial / none / category)
    // ---------------------------------------------------------------------

    public function test_inventory_search_filters_by_partial_and_exact_item_names_case_insensitively(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeMedicine('Burn Gel', ['unit' => 'tube']);

        // Empty search: no q param, no filtering - every row renders.
        $this->actingAs($staff)->get('/staff/inventory')
            ->assertOk()
            ->assertSee('>Biogesic</td>', false)
            ->assertSee('>Burn Gel</td>', false);

        // Exact name.
        $this->actingAs($staff)->get('/staff/inventory?q=Biogesic')
            ->assertOk()
            ->assertSee('>Biogesic</td>', false)
            ->assertDontSee('>Burn Gel</td>', false)
            ->assertSee('value="Biogesic"', false);   // the term stays in the input

        // Partial name.
        $this->actingAs($staff)->get('/staff/inventory?q=gesic')
            ->assertOk()
            ->assertSee('>Biogesic</td>', false)
            ->assertDontSee('>Burn Gel</td>', false);

        // Case-insensitive (ASCII) like every other list search in the app.
        $this->actingAs($staff)->get('/staff/inventory?q=BIOGESIC')
            ->assertOk()
            ->assertSee('>Biogesic</td>', false)
            ->assertDontSee('>Burn Gel</td>', false);
    }

    public function test_inventory_search_with_no_results_renders_the_empty_state(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Biogesic', ['unit' => 'tablet']);

        $this->actingAs($staff)->get('/staff/inventory?q=zzz')
            ->assertOk()
            ->assertSee('No products found.')
            ->assertDontSee('>Biogesic</td>', false);

        // Clearing the search (empty q) restores the normal list.
        $this->actingAs($staff)->get('/staff/inventory?q=')
            ->assertOk()
            ->assertSee('>Biogesic</td>', false);
    }

    public function test_inventory_search_also_matches_the_category_name(): void
    {
        $staff = $this->makeStaff();
        $painCat = DB::table('medicine_categories')->insertGetId(['name' => 'Pain Relievers', 'type' => 'medicine']);
        $this->makeMedicine('Ibuprofen', ['unit' => 'tablet', 'category_id' => $painCat]);
        $this->makeMedicine('Bandage Roll', ['unit' => 'roll']);

        // The placeholder promises "Name or category..."; the CATEGORY name
        // matches even though the item name does not. Both live on the
        // medicines tab, which is the default.
        $this->actingAs($staff)->get('/staff/inventory?q=relievers')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('>Bandage Roll</td>', false);

        $this->actingAs($staff)->get('/staff/inventory?q=ibuprofen')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('>Bandage Roll</td>', false);
    }

    public function test_inventory_search_composes_with_the_tab_and_category_filters(): void
    {
        $staff = $this->makeStaff();
        $woundCat = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $this->makeMedicine('Bandage Roll', ['unit' => 'roll', 'category_id' => $woundCat]);
        $this->makeMedicine('Ibuprofen', ['unit' => 'tablet']);

        // q matches but the tab rejects it -> no rows (AND semantics).
        $this->actingAs($staff)->get('/staff/inventory?tab=medicines&q=bandage')
            ->assertOk()
            ->assertSee('No products found.')
            ->assertDontSee('>Bandage Roll</td>', false);

        // q matches and the tab keeps it.
        $this->actingAs($staff)->get('/staff/inventory?tab=first_aid&q=bandage')
            ->assertOk()
            ->assertSee('>Bandage Roll</td>', false)
            ->assertDontSee('>Ibuprofen</td>', false);

        // q matches but the category filter rejects it.
        $this->actingAs($staff)->get('/staff/inventory?tab=first_aid&q=ibup&category='.$woundCat)
            ->assertOk()
            ->assertSee('No products found.')
            ->assertDontSee('>Ibuprofen</td>', false);

        // q + category together keep the row.
        $this->actingAs($staff)->get('/staff/inventory?tab=first_aid&q=bandage&category='.$woundCat)
            ->assertOk()
            ->assertSee('>Bandage Roll</td>', false);
    }

    // ---------------------------------------------------------------------
    // Inventory: ?stock= (buckets + unknown-value fallback + composition)
    // ---------------------------------------------------------------------

    public function test_inventory_stock_filter_keeps_disjoint_buckets_and_ignores_unknown_values(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Empty Med');                                  // 0 <= threshold -> out
        $low = $this->makeMedicine('Low Med');
        $this->makeBatch($low, 5);                                         // 5 <= 10 -> low
        $plenty = $this->makeMedicine('Plenty Med');
        $this->makeBatch($plenty, 50);                                     // 50 > 10 -> in

        $this->actingAs($staff)->get('/staff/inventory?stock=out')
            ->assertOk()
            ->assertSee('>Empty Med</td>', false)
            ->assertDontSee('>Low Med</td>', false)
            ->assertDontSee('>Plenty Med</td>', false);

        $this->actingAs($staff)->get('/staff/inventory?stock=low')
            ->assertOk()
            ->assertSee('>Low Med</td>', false)
            ->assertDontSee('>Empty Med</td>', false)
            ->assertDontSee('>Plenty Med</td>', false);

        $this->actingAs($staff)->get('/staff/inventory?stock=in')
            ->assertOk()
            ->assertSee('>Plenty Med</td>', false)
            ->assertDontSee('>Empty Med</td>', false)
            ->assertDontSee('>Low Med</td>', false);

        // Unknown values fall back to "all rows" (whitelist, ?item_type= style).
        $this->actingAs($staff)->get('/staff/inventory?stock=banana')
            ->assertOk()
            ->assertSee('>Empty Med</td>', false)
            ->assertSee('>Low Med</td>', false)
            ->assertSee('>Plenty Med</td>', false);
    }

    public function test_inventory_search_composes_with_the_stock_filter(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Empty Med');
        $low = $this->makeMedicine('Low Med');
        $this->makeBatch($low, 5);
        $plenty = $this->makeMedicine('Plenty Med');
        $this->makeBatch($plenty, 50);

        // q=med matches all three names; stock narrows to the one bucket.
        $this->actingAs($staff)->get('/staff/inventory?q=med&stock=low')
            ->assertOk()
            ->assertSee('>Low Med</td>', false)
            ->assertDontSee('>Empty Med</td>', false)
            ->assertDontSee('>Plenty Med</td>', false);

        // q misses while stock would match -> still no rows (AND semantics).
        $this->actingAs($staff)->get('/staff/inventory?q=absent&stock=low')
            ->assertOk()
            ->assertSee('No products found.');
    }

    // ---------------------------------------------------------------------
    // Filtering never changes the Total Pieces formats
    // ---------------------------------------------------------------------

    public function test_inventory_search_preserves_the_total_pieces_formats(): void
    {
        $staff = $this->makeStaff();
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 103);
        DB::table('stock_movements')->insert([
            'medicine_id' => $tablet, 'type' => 'OUT', 'quantity' => 11, 'ref_type' => 'dispense',
        ]);
        $burn = $this->makeMedicine('Burn Gel', ['unit' => 'tube']);
        $this->makeBatch($burn, 16);
        $shears = $this->makeMedicine('Trauma Shears', ['unit' => 'piece']);
        $this->makeBatch($shears, 6);

        // Quantitative: package position ("deducted"), unchanged by ?q=. It
        // now renders under Quantity / Stock rather than in a Total Pieces
        // column, so it is a small sub-line instead of a badge.
        $this->actingAs($staff)->get('/staff/inventory?q=biogesic')
            ->assertOk()
            ->assertSee('>11/12 deducted</small>', false);

        // Non-quantitative: opened ratio, unchanged by ?q=.
        $this->actingAs($staff)->get('/staff/inventory?q=burn')
            ->assertOk()
            ->assertSee('>0/16 opened</small>', false);

        // No known package: plain count, unchanged by ?q=.
        $this->actingAs($staff)->get('/staff/inventory?q=shears')
            ->assertOk()
            ->assertSee('>6</small>', false);
    }

    // ---------------------------------------------------------------------
    // Dispense: the history search must target the rendered table
    // ---------------------------------------------------------------------

    public function test_dispense_history_search_targets_the_rendered_table_and_modal(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('Anna', 'Reyes', studentNo: 'ST-1');
        $tablet = $this->makeMedicine('Biogesic', ['unit' => 'tablet']);
        $this->makeBatch($tablet, 10);

        $response = $this->actingAs($staff)->get('/staff/dispense');
        $response->assertOk()
            ->assertSee('id="dispenseHistorySearch"', false)
            // The selector the JS actually resolves rows from...
            ->assertSee('data-live-filter="#dispenseTable tbody tr"', false)
            ->assertDontSee('#dispenseHistoryTable', false)   // ...never the dead id
            ->assertSee('id="dispenseTable"', false)
            // The dispensing modal keeps its population hooks.
            ->assertSee('id="dispenseMedicineSelect"', false)
            ->assertSee('"method":"individual"', false)
            ->assertSee('"label":"10 tablets"', false);
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
}
