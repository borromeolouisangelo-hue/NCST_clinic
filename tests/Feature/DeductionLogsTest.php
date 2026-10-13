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
 * Inventory Deduction Logs feature tests against the isolated in-memory
 * SQLite connection that phpunit.xml selects.
 *
 * The log has no screen of its own: it is the Inventory section of the
 * existing Transaction History page (/staff/history?tab=inventory), which
 * the Inventory page's View Deduction Logs action opens and which this
 * file therefore exercises. Covered: the six required columns in their
 * exact order (Item, Type, Total Stock, Unit, Quantity Deducted negative,
 * Total Pieces Remaining) plus Date and Detail, medicine OUT movements and
 * equipment borrow/return events with their reconstructed before/after
 * figures, IN-movement and never-dispensed-request exclusion, item-name
 * search, the medicine/equipment type whitelist, the today/week/month
 * date window, DB-level pagination that preserves the active filters and
 * the tab, staff-only access, and the retired /staff/deduction_logs URL
 * now 404ing with no sidebar entry anywhere.
 *
 * SQL parity: CONCAT and CURDATE()/SUBDATE() run through the SQLite
 * shims in tests/TestCase.php; DATE() is native SQLite.
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

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->integer('total_qty')->default(0);
            $table->integer('available_qty')->default(0);
            $table->string('condition_note', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->useCurrent();
        });

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('equipment_id');
            $table->integer('quantity')->default(1);
            $table->date('borrow_date')->nullable();
            $table->dateTime('borrowed_at')->nullable();
            $table->date('due_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 20)->default('borrowed');
            $table->string('condition_on_return', 100)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    // ---------------------------------------------------------------------
    // Access + chrome
    // ---------------------------------------------------------------------

    public function test_log_is_staff_only_and_renders_the_history_inventory_section(): void
    {
        // Guest request FIRST: actingAs() below persists for the rest of
        // the test, so an unauthenticated call must be made before it.
        $this->get('/staff/history?tab=inventory')->assertRedirect(route('login'));

        $staff = $this->makeStaff();
        $student = $this->makeUser('stu', 'user', 'student');

        $this->actingAs($student)->get('/staff/history?tab=inventory')->assertRedirect(route('user.dashboard'));

        // The Inventory section carries the log's own two dropdowns (Type
        // and Date) beside the item-name search box, under the Inventory
        // pill's history header. No sidebar entry points at the log.
        $this->actingAs($staff)->get('/staff/history?tab=inventory')
            ->assertOk()
            ->assertSee('<i class="bi bi-box-seam me-1"></i> Inventory History</div>', false)
            ->assertSee('Search item name...', false)
            ->assertSee('All Types')
            ->assertSee('All Dates')
            ->assertDontSee('deduction_logs" class="nav-link', false);

        // The log now lives on an existing page, so the standalone URL it
        // used to own is simply gone rather than redirecting anywhere.
        $this->actingAs($staff)->get('/staff/deduction_logs')->assertNotFound();
    }

    public function test_empty_state_renders_the_empty_message_and_a_zero_count(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/history?tab=inventory')
            ->assertOk()
            ->assertSee('No deduction records found.', false)
            ->assertSee('0 records', false);
    }

    // ---------------------------------------------------------------------
    // Medicine rows: required columns + reconstructed figures
    // ---------------------------------------------------------------------

    public function test_medicine_row_shows_the_required_columns_in_order_with_figures(): void
    {
        $staff = $this->makeStaff();
        $med = $this->makeMedicine('Paracetamol');
        // deduct_stock_trace() decrements the batch as it writes the
        // movement, so the batch holds the POST-deduction stock (99).
        $bid = $this->makeBatch($med, 99);
        $this->makeMovement($med, 'OUT', [
            'batch_id' => $bid,
            'quantity' => 1,
            'reason' => 'Walk-in dispense: Headache',
            'ref_type' => 'dispense',
            'created_at' => '2026-01-05 10:00:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/history?tab=inventory')->assertOk();
        $body = $response->getContent();

        // The six required headers, in the exact required order, then Date/Detail.
        $headers = ['<th>Item</th>', '<th>Type</th>', '>Total Stock</th>', '<th>Unit</th>',
            '>Quantity Deducted</th>', '>Total Pieces Remaining</th>', '<th>Date</th>', '<th>Detail</th>'];
        $positions = [];
        foreach ($headers as $header) {
            $pos = strpos($body, $header);
            $this->assertNotFalse($pos, "header {$header} missing");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'columns must appear in the required order');

        // One row: 100 tablets before, -1 deducted, 99 remaining.
        $this->assertStringContainsString(
            '<td>Paracetamol</td> <td> <span class="badge bg-secondary">Medicine</span> </td>'
            .' <td class="text-end"><strong>100</strong></td> <td>tablet</td>'
            .' <td class="text-end text-danger">-1</td> <td class="text-end"><strong>99</strong></td>'
            .' <td> Jan 05, 2026 10:00 AM </td> <td>Walk-in dispense: Headache</td>',
            $this->flat($body),
        );
    }

    public function test_in_movements_never_list_but_depletion_outs_do(): void
    {
        $staff = $this->makeStaff();

        $gauze = $this->makeMedicine('Gauze Roll', ['unit' => 'roll']);
        $gauzeBatch = $this->makeBatch($gauze, 4);
        $this->makeMovement($gauze, 'IN', ['quantity' => 10, 'ref_type' => 'stock_in']);
        $this->makeMovement($gauze, 'OUT', [
            'batch_id' => $gauzeBatch,
            'quantity' => 1,
            'reason' => 'Opened container depleted',
            'ref_type' => 'depletion',
            'created_at' => '2026-01-06 08:00:00',
        ]);

        $receiveOnly = $this->makeMedicine('Receive Only Med');
        $this->makeMovement($receiveOnly, 'IN', []);

        $response = $this->actingAs($staff)->get('/staff/history?tab=inventory')->assertOk();
        $body = $response->getContent();

        // Stock receiving is not a deduction: the IN-only item is absent.
        $this->assertStringNotContainsString('<td>Receive Only Med</td>', $this->flat($body));

        // The container depletion is a real deduction: 5 rolls before, -1, 4 after.
        $this->assertStringContainsString(
            '<td>Gauze Roll</td> <td> <span class="badge bg-secondary">Medicine</span> </td>'
            .' <td class="text-end"><strong>5</strong></td> <td>roll</td>'
            .' <td class="text-end text-danger">-1</td> <td class="text-end"><strong>4</strong></td>',
            $this->flat($body),
        );
        $this->assertStringContainsString('<td>Opened container depleted</td>', $this->flat($body));
    }

    public function test_requests_that_were_never_dispensed_produce_no_rows(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('stu', 'user', 'student');
        $med = $this->makeMedicine('Never Deducted Med');
        $this->makeBatch($med, 30);

        // A rejected request and a dispensed-but-unprocessed request: neither
        // ever moved stock, so neither may appear as a successful deduction.
        DB::table('medicine_requests')->insert([
            ['user_id' => $user->id, 'medicine_id' => $med, 'status' => 'rejected'],
            ['user_id' => $user->id, 'medicine_id' => $med, 'status' => 'dispensed'],
        ]);

        $this->actingAs($staff)->get('/staff/history?tab=inventory')
            ->assertOk()
            ->assertSee('No deduction records found.', false)
            ->assertDontSee('<td>Never Deducted Med</td>', false);
    }

    // ---------------------------------------------------------------------
    // Equipment rows: borrow -qty, completed return +qty
    // ---------------------------------------------------------------------

    public function test_equipment_borrow_and_return_events_reconstruct_availability(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('borrower', 'user', 'student');
        $eq = $this->makeEquipment('X-Ray Unit', 5, 2);

        // Loan A: borrowed Jan 5, returned Jan 6. Loan B: still out.
        $this->makeLoan($user->id, $eq, '2026-01-05 08:00:00', 'returned', '2026-01-06');
        $this->makeLoan($user->id, $eq, '2026-01-10 09:00:00', 'borrowed', null);

        $response = $this->actingAs($staff)->get('/staff/history?tab=inventory')->assertOk();
        $flat = $this->flat($response->getContent());

        // Current availability is the STORED available_qty (2), and the
        // replay anchors on it: the outstanding borrow left 3 -> 2 units,
        // the completed loan's return restored 2 -> 3, and the older borrow
        // of that same loan had taken 3 -> 2.
        $this->assertStringContainsString(
            '<td>X-Ray Unit</td> <td> <span class="badge bg-primary">Equipment</span> </td>'
            .' <td class="text-end"><strong>3</strong></td> <td>pieces</td>'
            .' <td class="text-end text-danger">-1</td> <td class="text-end"><strong>2</strong></td>'
            .' <td> Jan 10, 2026 9:00 AM </td> <td>Loan issued</td>',
            $flat,
        );

        // The completed loan's return: a positive restoration, dated (date-only).
        $this->assertStringContainsString(
            '<td>X-Ray Unit</td> <td> <span class="badge bg-primary">Equipment</span> </td>'
            .' <td class="text-end"><strong>2</strong></td> <td>pieces</td>'
            .' <td class="text-end text-success">+1</td> <td class="text-end"><strong>3</strong></td>'
            .' <td> Jan 06, 2026 </td> <td>Equipment returned</td>',
            $flat,
        );

        // The older borrow of the same loan: 3 -> 2 before the return restored it.
        $this->assertStringContainsString(
            '<td> Jan 05, 2026 8:00 AM </td> <td>Loan issued</td>',
            $flat,
        );
    }

    // ---------------------------------------------------------------------
    // Search / type / date filters
    // ---------------------------------------------------------------------

    public function test_search_matches_the_item_name_of_both_types(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('borrower', 'user', 'student');

        $med = $this->makeMedicine('Ibuprofen');
        $this->makeMovement($med, 'OUT', []);
        $this->makeMovement($this->makeMedicine('Paracetamol'), 'OUT', []);
        $eq = $this->makeEquipment('Wheelchair', 2, 1);
        $this->makeLoan($user->id, $eq, '2026-01-05 08:00:00', 'borrowed', null);

        $this->actingAs($staff)->get('/staff/history?tab=inventory&q=Ibu')
            ->assertOk()
            ->assertSee('<td>Ibuprofen</td>', false)
            ->assertDontSee('<td>Paracetamol</td>', false)
            ->assertDontSee('<td>Wheelchair</td>', false);

        $this->actingAs($staff)->get('/staff/history?tab=inventory&q=wheel')
            ->assertOk()
            ->assertSee('<td>Wheelchair</td>', false)
            ->assertDontSee('<td>Ibuprofen</td>', false);

        $this->actingAs($staff)->get('/staff/history?tab=inventory&q=zzzz')
            ->assertOk()
            ->assertSee('No deduction records found.', false);
    }

    public function test_type_whitelist_filters_and_junk_falls_through(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('borrower', 'user', 'student');

        $med = $this->makeMedicine('Alpha Med');
        $this->makeMovement($med, 'OUT', []);
        $eq = $this->makeEquipment('Crutches', 3, 2);
        $this->makeLoan($user->id, $eq, '2026-01-05 08:00:00', 'borrowed', null);

        // Medicine: rows only, option rendered selected.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&type=medicine')
            ->assertOk()
            ->assertSee('<td>Alpha Med</td>', false)
            ->assertDontSee('<td>Crutches</td>', false)
            ->assertSee('value="medicine" selected', false)
            ->assertDontSee('value="equipment" selected', false);

        // Equipment: rows only, option rendered selected.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&type=equipment')
            ->assertOk()
            ->assertSee('<td>Crutches</td>', false)
            ->assertDontSee('<td>Alpha Med</td>', false)
            ->assertSee('value="equipment" selected', false)
            ->assertDontSee('value="medicine" selected', false);

        // Junk: no filter at all, no selected option.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&type=banana')
            ->assertOk()
            ->assertSee('<td>Alpha Med</td>', false)
            ->assertSee('<td>Crutches</td>', false)
            ->assertDontSee('value="medicine" selected', false)
            ->assertDontSee('value="equipment" selected', false);
    }

    public function test_date_filter_matches_the_consultations_windows(): void
    {
        $staff = $this->makeStaff();

        foreach ([
            'Today Med' => 0,
            'Recent Med' => 3,
            'Mid Med' => 10,
            'Ancient Med' => 40,
        ] as $name => $daysAgo) {
            $med = $this->makeMedicine($name);
            $this->makeMovement($med, 'OUT', [
                'created_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            ]);
        }

        // Today: only the movement from today.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&filter=today')
            ->assertSee('<td>Today Med</td>', false)
            ->assertDontSee('<td>Recent Med</td>', false)
            ->assertDontSee('<td>Ancient Med</td>', false);

        // Week (SUBDATE(CURDATE(), 7)): today + 3 days ago.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&filter=week')
            ->assertSee('<td>Today Med</td>', false)
            ->assertSee('<td>Recent Med</td>', false)
            ->assertDontSee('<td>Mid Med</td>', false)
            ->assertDontSee('<td>Ancient Med</td>', false);

        // Month (SUBDATE(CURDATE(), 30)): + 10 days ago, not 40.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&filter=month')
            ->assertSee('<td>Mid Med</td>', false)
            ->assertDontSee('<td>Ancient Med</td>', false);

        // Junk: everything, no selected option.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&filter=banana')
            ->assertSee('<td>Today Med</td>', false)
            ->assertSee('<td>Ancient Med</td>', false)
            ->assertDontSee('value="today" selected', false)
            ->assertDontSee('value="week" selected', false);
    }

    // ---------------------------------------------------------------------
    // Pagination (server-side, filters preserved)
    // ---------------------------------------------------------------------

    public function test_pagination_is_server_side_and_preserves_the_active_filters(): void
    {
        $staff = $this->makeStaff();
        $med = $this->makeMedicine('Bulk Med');

        $base = strtotime('2026-02-01 00:00:00');
        for ($i = 1; $i <= 25; $i++) {
            $this->makeMovement($med, 'OUT', [
                'reason' => sprintf('PAGE-%03d', $i),
                'created_at' => date('Y-m-d H:i:s', $base + $i * 60),
            ]);
        }

        // Page 1: 20 newest rows (PAGE-025 .. PAGE-006), oldest on page 2.
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=inventory&q=Bulk+Med&type=medicine')->assertOk();
        $body1 = $page1->getContent();
        $this->assertSame(20, substr_count($body1, '<td>Bulk Med</td>'), 'page 1 must render 20 rows');
        $page1->assertSee('PAGE-025', false)
            ->assertSee('PAGE-006', false)
            ->assertDontSee('PAGE-005', false);
        // The counter reports the filtered TOTAL, not the rows on screen.
        $page1->assertSee('25 records', false)
            ->assertSee('Inventory history pagination', false);

        // Page 2: the remaining 5 rows; the tab and both filters ride along
        // in every link.
        $page2 = $this->actingAs($staff)->get('/staff/history?tab=inventory&q=Bulk+Med&type=medicine&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), '<td>Bulk Med</td>'), 'page 2 must render 5 rows');
        $page2->assertSee('PAGE-005', false)
            ->assertDontSee('PAGE-006', false)
            ->assertSee('history?tab=inventory&amp;q=Bulk+Med&amp;type=medicine&amp;page=1', false)
            ->assertSee('history?tab=inventory&amp;q=Bulk+Med&amp;type=medicine&amp;page=2', false);
    }

    // ---------------------------------------------------------------------
    // Record counter (reflects the active filters, like every other tab)
    // ---------------------------------------------------------------------

    public function test_the_record_count_reflects_the_active_filters(): void
    {
        $staff = $this->makeStaff();
        $user = $this->makeUser('borrower', 'user', 'student');

        $ibuprofen = $this->makeMedicine('Ibuprofen');
        $this->makeMovement($ibuprofen, 'OUT', []);
        $this->makeMovement($this->makeMedicine('Paracetamol'), 'OUT', []);
        $eq = $this->makeEquipment('Crutches', 3, 2);
        $this->makeLoan($user->id, $eq, '2020-05-01 09:00:00', 'returned', '2020-05-02');

        // Four events in all: two medicine OUT movements, plus the borrow
        // and the completed return the loan wrote - a return restores
        // stock, but it stays a visible event so the restoration is auditable.
        $this->actingAs($staff)->get('/staff/history?tab=inventory')
            ->assertOk()
            ->assertSee('4 records', false);

        // A search narrows the rows and the count together.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&q=Ibu')
            ->assertOk()
            ->assertSee('1 record', false)
            ->assertSee('<td>Ibuprofen</td>', false)
            ->assertDontSee('<td>Paracetamol</td>', false)
            ->assertDontSee('<td>Crutches</td>', false);

        // So does the Equipment type filter: the loan's borrow and return.
        $this->actingAs($staff)->get('/staff/history?tab=inventory&type=equipment')
            ->assertOk()
            ->assertSee('2 records', false)
            ->assertSee('<td>Crutches</td>', false)
            ->assertDontSee('<td>Ibuprofen</td>', false);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Collapse whitespace so multi-line blade rows can be asserted exactly. */
    private function flat(string $body): string
    {
        return preg_replace('/\s+/', ' ', $body);
    }

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

    private function makeBatch(int $medicineId, int $quantity): int
    {
        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'date_received' => '2026-01-01',
        ]);
    }

    private function makeMovement(int $medicineId, string $type, array $extra = []): int
    {
        // Seed created_at through the PHP clock (same shim family as
        // CURDATE()): SQLite's column default is UTC, while CURDATE() comes
        // from the PHP-TZ shim - the two disagree during the first hours
        // after local midnight. Explicit dates in $extra still win.
        $extra += ['created_at' => date('Y-m-d H:i:s')];

        return DB::table('stock_movements')->insertGetId($extra + [
            'medicine_id' => $medicineId,
            'type' => $type,
            'quantity' => 2,
            'reason' => 'Dispense',
        ]);
    }

    private function makeEquipment(string $name, int $total, int $available): int
    {
        return DB::table('equipment')->insertGetId([
            'name' => $name,
            'total_qty' => $total,
            'available_qty' => $available,
            'condition_note' => 'good',
        ]);
    }

    private function makeLoan(int $userId, int $equipmentId, string $borrowedAt, string $status, ?string $returnDate): int
    {
        return DB::table('equipment_loans')->insertGetId([
            'user_id' => $userId,
            'equipment_id' => $equipmentId,
            'quantity' => 1,
            'borrow_date' => substr($borrowedAt, 0, 10),
            'borrowed_at' => $borrowedAt,
            'due_date' => substr($borrowedAt, 0, 10),
            'return_date' => $returnDate,
            'status' => $status,
        ]);
    }
}
