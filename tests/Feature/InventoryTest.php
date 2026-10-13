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
 * Phase 15 inventory tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/inventory.php: the receive POST (original lines 50-102) with
 * its four independent validations, the first-aid expiry bypass (the CASE
 * item-type lookup keeps $expiry null, so past dates are ignored), the
 * batch-merge identity (COALESCE on batch/lot/expiry/supplier) that either
 * UPDATEs quantity or INSERTs a new batch + stock movement + audit inside a
 * transaction; the delete branch (103-112) where a missing id falls through
 * to the render but id=999 still flashes success after a zero-row DELETE;
 * the ?item_type= whitelist (115); the DEAD ?q=/?stock= toolbar inputs; the
 * threshold/expiry badge matrix; and the two compatibility stubs.
 *
 * SQL parity: CURDATE() runs through the SQLite shims in tests/TestCase.php;
 * COALESCE(batch_no, "") uses SQLite's double-quoted string fallback.
 */
class InventoryTest extends TestCase
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
    // Render (original 157-187)
    // ---------------------------------------------------------------------

    public function test_page_renders_toolbar_table_modal_and_empty_state(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/inventory')
            ->assertOk()
            ->assertSee('Search inventory')
            ->assertSee('Name or category...')
            ->assertSee('All Stock')
            ->assertSee('Add Stock')
            ->assertSee('No products found.')
            ->assertSee('Medicine / First Aid Item *')
            ->assertSee('-- select item --')
            ->assertSee('-- none --')
            ->assertSee('Stock In')
            // The modal posts through Laravel's CSRF field, not csrf_token.
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="csrf_token"', false)
            // No errors -> the auto-open script line renders nothing.
            ->assertDontSee('new bootstrap.Modal(modal).show()', false)
            ->assertSee('toggleExpiryVisibility', false);
    }

    public function test_modal_lists_active_medicines_with_item_types_and_suppliers(): void
    {
        $staff = $this->makeStaff();
        $faCat = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $this->makeMedicine('Bandage Roll', ['category_id' => $faCat]);
        $this->makeMedicine('Ibuprofen');
        DB::table('medicines')->insert(['name' => 'Retired Spray', 'is_active' => 0]);
        DB::table('suppliers')->insert(['name' => 'Acme Pharma']);

        $this->actingAs($staff)->get('/staff/inventory')
            ->assertOk()
            ->assertSee('Bandage Roll (Wound Care)', false)
            ->assertSee('Ibuprofen', false)
            ->assertSee('Acme Pharma', false)
            ->assertDontSee('Retired Spray', false)
            ->assertSee('data-item-type="first_aid"', false)
            ->assertSee('data-item-type="medicine"', false);
    }

    // ---------------------------------------------------------------------
    // Receive validation (original 50-54)
    // ---------------------------------------------------------------------

    public function test_receive_requires_item_and_minimum_quantity(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 0,
            'quantity' => 2,
            'batch_no' => 'B-100',
        ]);

        $response->assertOk()
            ->assertSee('Please select a medicine or first aid item.', false)
            ->assertSee('Quantity must be at least 3 for stock receiving.', false)
            // Errors enable the modal auto-open script.
            ->assertSee('new bootstrap.Modal(modal).show()', false);

        $this->assertDatabaseCount('medicine_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_receive_rejects_past_expiry_and_invalid_received_date(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');

        $response = $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 1,
            'quantity' => 5,
            'expiry_date' => '2020-01-01',
            'date_received' => 'garbage',
        ]);

        $response->assertOk()
            ->assertSee('Expiry date cannot be in the past.', false)
            ->assertSee('Date received is invalid.', false);

        $this->assertDatabaseCount('medicine_batches', 0);
    }

    public function test_missing_action_defaults_to_receive(): void
    {
        $staff = $this->makeStaff();

        // No action field: original defaults $action to 'receive'.
        $this->actingAs($staff)->post('/staff/inventory', [
            'medicine_id' => 1,
            'quantity' => 1,
        ])->assertOk()->assertSee('Quantity must be at least 3 for stock receiving.', false);

        $this->assertDatabaseCount('medicine_batches', 0);
    }

    // ---------------------------------------------------------------------
    // Receive success: new batch + merge (original 56-102)
    // ---------------------------------------------------------------------

    public function test_receive_creates_new_batch_movement_and_audit(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 1,
            'quantity' => 5,
            'batch_no' => 'B-100',
            'lot_no' => 'L-1',
            'unit_cost' => '2.50',
            'date_received' => '2026-09-15',
        ])->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('success', 'Stock added successfully.');

        $this->assertDatabaseCount('medicine_batches', 1);
        $this->assertDatabaseHas('medicine_batches', [
            'id' => 1,
            'medicine_id' => 1,
            'batch_no' => 'B-100',
            'lot_no' => 'L-1',
            'quantity' => 5,
            'expiry_date' => null,
            'date_received' => '2026-09-15',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'medicine_id' => 1,
            'batch_id' => 1,
            'type' => 'IN',
            'quantity' => 5,
            'reason' => 'Stock received',
            'ref_type' => 'stock_in',
            'performed_by' => $staff->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_in',
            'table_name' => 'medicine_batches',
            'record_id' => 1,
            'details' => 'Received 5 units (medicine #1)',
        ]);
    }

    public function test_receive_merges_identical_batch_and_updates_quantity(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');
        DB::table('medicine_batches')->insert([
            'medicine_id' => 1,
            'batch_no' => 'B-100',
            'quantity' => 10,
            'date_received' => '2026-09-01',
        ]);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 1,
            'quantity' => 5,
            'batch_no' => 'B-100',
        ])->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('success', 'Stock added successfully.');

        $this->assertDatabaseCount('medicine_batches', 1);
        $this->assertDatabaseHas('medicine_batches', ['id' => 1, 'quantity' => 15]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_in',
            'details' => 'Added 5 units to batch #1 (medicine #1)',
        ]);
    }

    public function test_first_aid_items_ignore_the_expiry_field(): void
    {
        $staff = $this->makeStaff();
        $faCat = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $this->makeMedicine('Bandage Roll', ['category_id' => $faCat]);

        // Past expiry on a first aid item: $expiry stays null, no error.
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 1,
            'quantity' => 5,
            'expiry_date' => '2020-01-01',
        ])->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('success', 'Stock added successfully.');

        $this->assertDatabaseHas('medicine_batches', ['id' => 1, 'expiry_date' => null]);

        // The same past expiry on a normal medicine IS rejected (test above).
        $this->makeMedicine('Ibuprofen');
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'receive',
            'medicine_id' => 2,
            'quantity' => 5,
            'expiry_date' => '2020-01-01',
        ])->assertOk()->assertSee('Expiry date cannot be in the past.', false);

        $this->assertDatabaseCount('medicine_batches', 1);
    }

    // ---------------------------------------------------------------------
    // Delete branch (original 103-112)
    // ---------------------------------------------------------------------

    public function test_delete_removes_batch_and_audits(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');
        $bid = $this->makeBatch(1, 10);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'delete',
            'batch_id' => $bid,
        ])->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('success', 'Batch deleted.');

        $this->assertDatabaseCount('medicine_batches', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_batch',
            'table_name' => 'medicine_batches',
            'record_id' => $bid,
            'details' => "Deleted batch #$bid",
        ]);
    }

    public function test_delete_with_zero_id_renders_error_without_redirect(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');
        $this->makeBatch(1, 10);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'delete',
            'batch_id' => 0,
        ])->assertOk()
            ->assertSee('Batch could not be found.', false)
            ->assertSee('new bootstrap.Modal(modal).show()', false);

        $this->assertDatabaseCount('medicine_batches', 1);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_delete_unknown_batch_id_still_flashes_success(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');
        $this->makeBatch(1, 10);

        // Reference quirk: zero-row DELETE still audits and flashes success.
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'delete',
            'batch_id' => 999,
        ])->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('success', 'Batch deleted.');

        $this->assertDatabaseCount('medicine_batches', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_batch',
            'details' => 'Deleted batch #999',
        ]);
    }

    // ---------------------------------------------------------------------
    // GET filters (original 115-141)
    // ---------------------------------------------------------------------

    public function test_item_type_filter_whitelist(): void
    {
        $staff = $this->makeStaff();
        $faCat = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $this->makeMedicine('Bandage Roll', ['category_id' => $faCat]);
        $this->makeMedicine('Ibuprofen');
        $this->makeBatch(1, 10);
        $this->makeBatch(2, 10);

        // first_aid keeps only the first aid item (the modal select stays
        // unfiltered in the reference, so rows are asserted by their <td>).
        $this->actingAs($staff)->get('/staff/inventory?item_type=first_aid')
            ->assertOk()
            ->assertSee('Bandage Roll <small', false)
            ->assertDontSee('>Ibuprofen</td>', false);

        // medicine keeps only the medicine.
        $this->actingAs($staff)->get('/staff/inventory?item_type=medicine')
            ->assertOk()
            ->assertSee('>Ibuprofen</td>', false)
            ->assertDontSee('Bandage Roll <small', false);

        // Unknown value falls back to 'all' (whitelist).
        $this->actingAs($staff)->get('/staff/inventory?item_type=banana')
            ->assertOk()
            ->assertSee('Bandage Roll <small', false)
            ->assertSee('>Ibuprofen</td>', false);
    }

    public function test_toolbar_q_and_stock_inputs_are_dead_but_render(): void
    {
        $staff = $this->makeStaff();
        $this->makeMedicine('Ibuprofen');
        $this->makeBatch(1, 10);

        $this->actingAs($staff)->get('/staff/inventory?q=zzz&stock=low')
            ->assertOk()
            ->assertSee('value="zzz"', false)
            ->assertSee('value="low" selected', false)
            // Dead inputs: the row list is NOT filtered by q or stock.
            ->assertSee('Ibuprofen', false);
    }

    public function test_threshold_and_expiry_badge_matrix(): void
    {
        $staff = $this->makeStaff();
        // m1 threshold 4, no stock -> Out of stock
        $this->makeMedicine('Empty Med', ['low_stock_threshold' => 4]);
        // m2 threshold 4, qty 1 -> Critical (1 <= floor(4*0.25))
        $this->makeMedicine('Critical Med', ['low_stock_threshold' => 4]);
        // m3 threshold 4, qty 2 -> Low (2 <= floor(4*0.5))
        $this->makeMedicine('Low Med', ['low_stock_threshold' => 4]);
        // m4 threshold 4, qty 3 -> Healthy
        $this->makeMedicine('Healthy Med', ['low_stock_threshold' => 4]);
        // m5 expired batch overrides the stock badge
        $this->makeMedicine('Expired Med');
        // m6 next expiry within 30 days -> Expiring soon
        $this->makeMedicine('Soon Med');

        $this->makeBatch(2, 1);
        $this->makeBatch(3, 2);
        $this->makeBatch(4, 3);
        $this->makeBatch(5, 20, '2020-01-01');
        $this->makeBatch(6, 20, date('Y-m-d', strtotime('+10 days')));

        $response = $this->actingAs($staff)->get('/staff/inventory');
        $response->assertOk()
            ->assertSee('>Out of stock</span>', false)
            ->assertSee('>Critical</span>', false)
            ->assertSee('>Low</span>', false)
            ->assertSee('>Healthy</span>', false)
            ->assertSee('>Expired</span>', false)
            ->assertSee('>Expiring soon</span>', false);
    }

    // ---------------------------------------------------------------------
    // Compatibility stubs
    // ---------------------------------------------------------------------

    public function test_stock_adjust_stub_redirects_to_inventory(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/stock_adjust')
            ->assertRedirect(route('staff.inventory'))
            ->assertSessionHas('info', 'The clinic now uses the unified Inventory Operations page for stock receiving and adjustments.');

        // Method-blind like the reference (require_login, flash, redirect).
        $this->actingAs($staff)->post('/staff/stock_adjust')
            ->assertRedirect(route('staff.inventory'));
    }

    public function test_history_logs_stub_redirects_to_dispense_history(): void
    {
        $staff = $this->makeStaff();

        $this->actingAs($staff)->get('/staff/history_logs')
            ->assertRedirect(route('staff.dispense', ['history_status' => 'all']))
            ->assertSessionHas('info', 'The clinic now uses the combined Dispensing & History screen for medicine and equipment activity.');
    }

    // ---------------------------------------------------------------------
    // Role parity
    // ---------------------------------------------------------------------

    public function test_role_parity(): void
    {
        $staff = $this->makeStaff();

        $this->get('/staff/inventory')->assertRedirect(route('login'));

        $admin = $this->makeUser('boss', 'admin', null);
        $this->actingAs($admin)->get('/staff/inventory')->assertRedirect(route('admin.dashboard'));

        $student = $this->makeUser('stu', 'user', 'student');
        $this->actingAs($student)->get('/staff/inventory')->assertRedirect(route('user.dashboard'));

        $this->actingAs($staff)->get('/staff/inventory')->assertOk();
    }

    // ---------------------------------------------------------------------
    // My Inventory Requests visibility (regression)
    // ---------------------------------------------------------------------

    public function test_staff_requests_appear_in_my_inventory_requests_tab(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Amoxicillin 500mg',
            'request_quantity' => 5,
            'request_expiry_date' => '2027-12-31',
        ])
            ->assertRedirect(route('staff.inventory', ['tab' => 'medicines']))
            ->assertSessionHas('success', 'Request submitted. An admin will review it before the record is created.');

        // The pending row is exactly what the admin approval queue lists.
        $row = DB::table('inventory_requests')
            ->where('requester_id', $staff->id)
            ->where('request_type', 'medicine')
            ->where('status', 'pending')
            ->first();
        $this->assertNotNull($row, 'The submitted request must exist as a pending inventory_requests row.');

        $this->actingAs($admin)->get('/admin/inventory_requests')
            ->assertOk()
            ->assertSee('Amoxicillin 500mg');

        // ...and the SAME request shows in the staff member's own requests tab.
        $this->actingAs($staff)->get('/staff/inventory?tab=requests')
            ->assertOk()
            ->assertSee('My Inventory Requests')
            ->assertSee('Amoxicillin 500mg')
            ->assertSee('Pending')
            ->assertDontSee('No products found.');

        // Approved and rejected requests must stay visible in the staff tab.
        foreach (['approved', 'rejected'] as $status) {
            DB::table('inventory_requests')->where('id', $row->id)->update([
                'status' => $status,
                'reviewed_by' => $admin->id,
                'reviewed_at' => '2026-10-13 09:00:00',
                'review_note' => 'Reviewed as '.$status.'.',
            ]);

            $this->actingAs($staff)->get('/staff/inventory?tab=requests')
                ->assertOk()
                ->assertSee('Amoxicillin 500mg')
                ->assertSee(ucfirst($status))
                ->assertSee($admin->username);
        }
    }

    public function test_another_staff_cannot_see_other_staffs_requests(): void
    {
        $this->makeInventoryRequestsTable();

        $staff1 = $this->makeUser('clinic1', 'staff', null);
        $staff2 = $this->makeUser('clinic2', 'staff', null);

        $this->actingAs($staff1)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Amoxicillin 500mg',
            'request_quantity' => 5,
            'request_expiry_date' => '2027-12-31',
        ])->assertSessionHas('success');

        // Staff 2 sees no requests of their own...
        $this->actingAs($staff2)->get('/staff/inventory?tab=requests')
            ->assertOk()
            ->assertDontSee('Amoxicillin 500mg');

        // ...while the requester still sees theirs.
        $this->actingAs($staff1)->get('/staff/inventory?tab=requests')
            ->assertOk()
            ->assertSee('Amoxicillin 500mg');
    }

    // ---------------------------------------------------------------------
    // Medicine request expiry (required, valid, carried to approval)
    // ---------------------------------------------------------------------

    public function test_medicine_request_requires_a_valid_future_expiry_date(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);

        $base = [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Amoxicillin 500mg',
            'request_quantity' => 5,
        ];

        // Missing expiry - rejected, page re-renders with the error.
        $this->actingAs($staff)->post('/staff/inventory', $base)
            ->assertOk()
            ->assertSee('Expiry date is required for medicine requests.');

        // Not a real Y-m-d date (month/day rollover) - rejected.
        $this->actingAs($staff)->post('/staff/inventory', $base + ['request_expiry_date' => '2027-02-30'])
            ->assertOk()
            ->assertSee('Expiry date is invalid.');

        // Past date - still rejected (rule kept from before).
        $this->actingAs($staff)->post('/staff/inventory', $base + ['request_expiry_date' => '2020-01-01'])
            ->assertOk()
            ->assertSee('Expiry date cannot be in the past.');

        // None of the rejected submissions may create a queue row.
        $this->assertSame(0, DB::table('inventory_requests')->count());
    }

    public function test_medicine_request_carries_expiry_to_admin_details_and_approval(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Amoxicillin 500mg',
            'request_quantity' => 5,
            'request_batch_no' => 'B-2026-01',
            'request_expiry_date' => '2027-12-31',
        ])->assertSessionHas('success');

        $row = DB::table('inventory_requests')
            ->where('requester_id', $staff->id)
            ->where('request_type', 'medicine')
            ->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame('2027-12-31', $payload['expiry_date'] ?? null, 'Expiry must be stored in the request payload.');

        // The admin review details show the requested expiry.
        $this->actingAs($admin)->get('/admin/inventory_requests?view=request&id='.(int) $row->id)
            ->assertOk()
            ->assertSee('Expiry Date')
            ->assertSee('Dec 31, 2027');

        // Approval stores the expiry on the medicine's first batch.
        $this->actingAs($admin)->post('/admin/inventory_requests', [
            'do' => 'review',
            'request_id' => $row->id,
            'status' => 'approved',
            'review_note' => 'Approved for stock.',
        ])
            ->assertRedirect(route('admin.inventory_requests'))
            ->assertSessionHas('success');

        $this->assertSame('approved', (string) DB::table('inventory_requests')->where('id', $row->id)->value('status'));

        $medicineId = (int) DB::table('medicines')->where('name', 'Amoxicillin 500mg')->value('id');
        $this->assertGreaterThan(0, $medicineId, 'Approval must create the medicine record.');

        $batch = DB::table('medicine_batches')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($batch, 'Approval must receive the initial stock as a batch.');
        $this->assertSame('2027-12-31', (string) $batch->expiry_date);
        $this->assertSame(5, (int) $batch->quantity);
    }

    public function test_first_aid_request_does_not_require_an_expiry_date(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);
        $admin = $this->makeUser('boss', 'admin', null);

        // No expiry posted - first aid never tracks one.
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'first_aid',
            'request_name' => 'Sterile Gauze Pad',
            'request_quantity' => 5,
        ])->assertSessionHas('success');

        $row = DB::table('inventory_requests')
            ->where('requester_id', $staff->id)
            ->where('request_type', 'first_aid')
            ->first();
        $this->assertNotNull($row);
        $payload = json_decode((string) $row->payload, true);
        $this->assertArrayHasKey('expiry_date', $payload, 'The payload must carry the expiry_date key.');
        $this->assertNull($payload['expiry_date'], 'First aid payloads never carry an expiry.');

        // The admin review details show no expiry row for first aid...
        $this->actingAs($admin)->get('/admin/inventory_requests?view=request&id='.(int) $row->id)
            ->assertOk()
            ->assertDontSee('Expiry Date');

        // ...and approval creates the record without one.
        $this->actingAs($admin)->post('/admin/inventory_requests', [
            'do' => 'review',
            'request_id' => $row->id,
            'status' => 'approved',
            'review_note' => 'Ok.',
        ])->assertSessionHas('success');

        $medicineId = (int) DB::table('medicines')->where('name', 'Sterile Gauze Pad')->value('id');
        $this->assertGreaterThan(0, $medicineId, 'Approval must create the first aid record.');

        $batch = DB::table('medicine_batches')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($batch);
        $this->assertNull($batch->expiry_date, 'The approved first aid batch must not gain an expiry.');
    }

    // ---------------------------------------------------------------------
    // Full-field trace: every shared form field must reach the payload, the
    // admin review details and (on approval) the right medicine/batch column.
    // ---------------------------------------------------------------------

    public function test_medicine_request_preserves_all_fields_through_review_and_approval(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);
        $admin = $this->makeUser('boss', 'admin', null);

        $catId = DB::table('medicine_categories')->insertGetId(['name' => 'Antibiotics', 'type' => 'medicine']);
        $supId = DB::table('suppliers')->insertGetId(['name' => 'Acme MedSupplies']);

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Amoxicillin 500mg',
            'request_generic_name' => 'Amoxicillin trihydrate',
            'request_unit' => 'vial',
            'request_category_id' => $catId,
            'request_description' => 'Broad-spectrum oral antibiotic',
            'request_quantity' => 37,
            'request_threshold' => 4,
            'request_unit_cost' => '12.50',
            'request_batch_no' => 'B-2026-42',
            'request_lot_no' => 'LOT-77',
            'request_supplier_id' => $supId,
            'request_expiry_date' => '2027-12-31',
            'request_date_received' => '2026-09-15',
        ])->assertSessionHas('success');

        $row = DB::table('inventory_requests')
            ->where('requester_id', $staff->id)
            ->where('request_type', 'medicine')
            ->first();
        $this->assertNotNull($row);

        // Every submitted field survives into the pending payload, unchanged.
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame([
            'generic_name' => 'Amoxicillin trihydrate',
            'category_id' => $catId,
            'unit' => 'vial',
            'description' => 'Broad-spectrum oral antibiotic',
            'low_stock_threshold' => 4,
            'initial_quantity' => 37,
            'batch_no' => 'B-2026-42',
            'lot_no' => 'LOT-77',
            'supplier_id' => $supId,
            'unit_cost' => 12.5,
            'expiry_date' => '2027-12-31',
            'date_received' => '2026-09-15',
        ], $payload);

        // Nothing exists in the real inventory until an admin approves.
        $this->assertSame(0, DB::table('medicines')->where('name', 'Amoxicillin 500mg')->count());

        // The admin review details show the submitted values.
        $this->actingAs($admin)->get('/admin/inventory_requests?view=request&id='.(int) $row->id)
            ->assertOk()
            ->assertSee('Amoxicillin 500mg')
            ->assertSee('Amoxicillin trihydrate')
            ->assertSee('Antibiotics')
            ->assertSee('vial')
            ->assertSee('Broad-spectrum oral antibiotic')
            ->assertSee('B-2026-42')
            ->assertSee('LOT-77')
            ->assertSee('Dec 31, 2027')
            ->assertSee('Sep 15, 2026')
            ->assertSee('12.50')
            ->assertSee('Acme MedSupplies');

        // Approval writes each field to the medicine record...
        $this->actingAs($admin)->post('/admin/inventory_requests', [
            'do' => 'review',
            'request_id' => $row->id,
            'status' => 'approved',
            'review_note' => 'Approved.',
        ])
            ->assertRedirect(route('admin.inventory_requests'))
            ->assertSessionHas('success');

        $medicineId = (int) DB::table('medicines')->where('name', 'Amoxicillin 500mg')->value('id');
        $this->assertGreaterThan(0, $medicineId, 'Approval must create the medicine record.');

        $medicine = DB::table('medicines')->where('id', $medicineId)->first();
        $this->assertSame('Amoxicillin trihydrate', (string) $medicine->generic_name);
        $this->assertSame('vial', (string) $medicine->unit);
        $this->assertSame('Broad-spectrum oral antibiotic', (string) $medicine->description);
        $this->assertSame(4, (int) $medicine->low_stock_threshold);
        $this->assertSame($catId, (int) $medicine->category_id);
        $this->assertTrue((bool) $medicine->is_active);

        // ...and each stock field to the medicine's first batch.
        $batch = DB::table('medicine_batches')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($batch, 'Approval must receive the initial stock as a batch.');
        $this->assertSame('B-2026-42', (string) $batch->batch_no);
        $this->assertSame('LOT-77', (string) $batch->lot_no);
        $this->assertSame($supId, (int) $batch->supplier_id);
        $this->assertSame(37, (int) $batch->quantity);
        $this->assertSame('2027-12-31', (string) $batch->expiry_date);
        $this->assertSame('2026-09-15', (string) $batch->date_received);
        $this->assertSame(12.5, (float) $batch->unit_cost);

        // The queue row links to the record its approval created.
        $this->assertSame($medicineId, (int) DB::table('inventory_requests')->where('id', $row->id)->value('record_id'));
    }

    public function test_first_aid_request_preserves_all_fields_through_review_and_approval(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);
        $admin = $this->makeUser('boss', 'admin', null);

        $catId = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care Supplies', 'type' => 'first_aid']);
        $supId = DB::table('suppliers')->insertGetId(['name' => 'Acme MedSupplies']);

        // Same fields as medicine, minus the expiry - first aid never posts one.
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'first_aid',
            'request_name' => 'Sterile Gauze Pad',
            'request_generic_name' => 'Sterile gauze',
            'request_unit' => 'pack',
            'request_category_id' => $catId,
            'request_description' => 'Individually wrapped gauze pads',
            'request_quantity' => 20,
            'request_threshold' => 6,
            'request_unit_cost' => '3.75',
            'request_batch_no' => 'FA-2026-07',
            'request_lot_no' => 'LOT-91',
            'request_supplier_id' => $supId,
            'request_date_received' => '2026-09-20',
        ])->assertSessionHas('success');

        $row = DB::table('inventory_requests')
            ->where('requester_id', $staff->id)
            ->where('request_type', 'first_aid')
            ->first();
        $this->assertNotNull($row);

        // Every submitted field survives into the pending payload, and the
        // expiry key is present but always null for first aid.
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame([
            'generic_name' => 'Sterile gauze',
            'category_id' => $catId,
            'unit' => 'pack',
            'description' => 'Individually wrapped gauze pads',
            'low_stock_threshold' => 6,
            'initial_quantity' => 20,
            'batch_no' => 'FA-2026-07',
            'lot_no' => 'LOT-91',
            'supplier_id' => $supId,
            'unit_cost' => 3.75,
            'expiry_date' => null,
            'date_received' => '2026-09-20',
        ], $payload);

        // Nothing exists in the real inventory until an admin approves.
        $this->assertSame(0, DB::table('medicines')->where('name', 'Sterile Gauze Pad')->count());

        // The admin review details show the submitted values - and no expiry row.
        $this->actingAs($admin)->get('/admin/inventory_requests?view=request&id='.(int) $row->id)
            ->assertOk()
            ->assertSee('Sterile Gauze Pad')
            ->assertSee('Sterile gauze')
            ->assertSee('Wound Care Supplies')
            ->assertSee('pack')
            ->assertSee('Individually wrapped gauze pads')
            ->assertSee('FA-2026-07')
            ->assertSee('LOT-91')
            ->assertSee('Sep 20, 2026')
            ->assertSee('3.75')
            ->assertSee('Acme MedSupplies')
            ->assertDontSee('Expiry Date');

        // Approval writes each field to the medicine record...
        $this->actingAs($admin)->post('/admin/inventory_requests', [
            'do' => 'review',
            'request_id' => $row->id,
            'status' => 'approved',
            'review_note' => 'Approved.',
        ])
            ->assertRedirect(route('admin.inventory_requests'))
            ->assertSessionHas('success');

        $medicineId = (int) DB::table('medicines')->where('name', 'Sterile Gauze Pad')->value('id');
        $this->assertGreaterThan(0, $medicineId, 'Approval must create the first aid record.');

        $medicine = DB::table('medicines')->where('id', $medicineId)->first();
        $this->assertSame('Sterile gauze', (string) $medicine->generic_name);
        $this->assertSame('pack', (string) $medicine->unit);
        $this->assertSame('Individually wrapped gauze pads', (string) $medicine->description);
        $this->assertSame(6, (int) $medicine->low_stock_threshold);
        $this->assertSame($catId, (int) $medicine->category_id);
        $this->assertTrue((bool) $medicine->is_active);

        // ...and each stock field to the batch, never an expiry.
        $batch = DB::table('medicine_batches')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($batch, 'Approval must receive the initial stock as a batch.');
        $this->assertSame('FA-2026-07', (string) $batch->batch_no);
        $this->assertSame('LOT-91', (string) $batch->lot_no);
        $this->assertSame($supId, (int) $batch->supplier_id);
        $this->assertSame(20, (int) $batch->quantity);
        $this->assertSame('2026-09-20', (string) $batch->date_received);
        $this->assertSame(3.75, (float) $batch->unit_cost);
        $this->assertNull($batch->expiry_date, 'The approved first aid batch must not gain an expiry.');

        // The queue row links to the record its approval created.
        $this->assertSame($medicineId, (int) DB::table('inventory_requests')->where('id', $row->id)->value('record_id'));
    }

    public function test_medicine_and_first_aid_requests_share_the_same_field_validation(): void
    {
        $this->makeInventoryRequestsTable();

        $staff = $this->makeUser('clinic1', 'staff', null);

        $medCat = DB::table('medicine_categories')->insertGetId(['name' => 'Antibiotics', 'type' => 'medicine']);
        $aidCat = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care Supplies', 'type' => 'first_aid']);

        // The quantity floor applies to BOTH item types (medicine carries a
        // valid expiry here so the quantity error is the only one raised).
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Shared Rule Item',
            'request_quantity' => 2,
            'request_expiry_date' => '2027-12-31',
        ])
            ->assertOk()
            ->assertSee('Initial quantity must be at least 3.');

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'first_aid',
            'request_name' => 'Shared Rule Item',
            'request_quantity' => 2,
        ])
            ->assertOk()
            ->assertSee('Initial quantity must be at least 3.');

        // A category of the other item type is rejected for BOTH types.
        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'medicine',
            'request_name' => 'Shared Rule Item',
            'request_quantity' => 5,
            'request_category_id' => $aidCat,
            'request_expiry_date' => '2027-12-31',
        ])
            ->assertOk()
            ->assertSee('Please choose a category that matches the item type.');

        $this->actingAs($staff)->post('/staff/inventory', [
            'action' => 'request',
            'request_type' => 'first_aid',
            'request_name' => 'Shared Rule Item',
            'request_quantity' => 5,
            'request_category_id' => $medCat,
        ])
            ->assertOk()
            ->assertSee('Please choose a category that matches the item type.');

        // None of the rejected submissions may create a queue row.
        $this->assertSame(0, DB::table('inventory_requests')->count());
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

    private function makeBatch(int $medicineId, int $quantity, ?string $expiry = null): int
    {
        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'expiry_date' => $expiry,
            'date_received' => '2026-09-01',
        ]);
    }

    /**
     * The inventory_requests table (the admin approval queue + the staff
     * "My Inventory Requests" source). Not part of this file's baseline
     * schema, so the request-visibility tests create it on demand.
     */
    private function makeInventoryRequestsTable(): void
    {
        Schema::create('inventory_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requester_id');
            $table->string('request_type', 20);
            $table->string('name', 150);
            $table->text('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->string('review_note')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }
}
