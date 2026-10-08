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
 * Phase 14 medicine catalog tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers admin/medicines.php: the store/update POST (original lines 15-55)
 * with its two-rule validation order, the LOWER() duplicate check and the
 * id>0 update-vs-insert branch (a POST with id=0 inserts - original quirk);
 * the existence-check-free GET delete/edit actions (57-69, a missing id
 * still flashes success); the COALESCE stock subquery + CURDATE() search SQL
 * (75-91); and the modal closed-on-error quirk (the reference has NO
 * auto-open script - the errors render inside a closed modal).
 *
 * SQL parity: CONCAT and CURDATE() run through the SQLite shims in
 * tests/TestCase.php; LOWER() is native SQLite.
 */
class MedicineTest extends TestCase
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

        // Live shape (Phase 14 information_schema probe).
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
    // Render: toolbar, modal, empty state (original 96-167)
    // ---------------------------------------------------------------------

    public function test_page_renders_toolbar_modal_fields_and_empty_state(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/admin/medicines')
            ->assertOk()
            ->assertSee('Add Medicine')
            ->assertSee('Brand / Name *')
            ->assertSee('Used for allergy matching.')
            ->assertSee('Low Stock Threshold')
            ->assertSee('Brand or generic name')
            ->assertSee('Search medicine catalog')
            ->assertSee('No medicines found.')
            // The modal form posts through Laravel's CSRF field, not csrf_token.
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="csrf_token"', false)
            // The reference has NO auto-open script: errors render in a
            // closed modal (Phase 14 quirk - contrast staff/dispense.php).
            ->assertDontSee('new bootstrap.Modal(modal).show()', false);
    }

    public function test_rows_render_stock_badges_categories_and_action_links(): void
    {
        $admin = $this->makeAdmin();
        $catId = $this->makeCategory('Painkiller');
        $this->makeMedicine('Ibuprofen', ['generic_name' => 'Advil', 'category_id' => $catId, 'low_stock_threshold' => 5]);
        $this->makeBatch(1, 3); // stock 3 <= threshold 5 -> red badge
        $this->makeMedicine('Paracetamol', ['generic_name' => 'Biogesic']);
        $this->makeBatch(2, 50); // stock 50 > default threshold 10 -> green
        $this->makeMedicine('Old Spray', ['is_active' => 0]);

        $this->actingAs($admin)->get('/admin/medicines')
            ->assertOk()
            ->assertSee('Ibuprofen')
            ->assertSee('Advil')
            ->assertSee('Painkiller')
            ->assertSee('Paracetamol')
            ->assertSee('Biogesic')
            // No category renders the reference's em dash.
            ->assertSee('—')
            ->assertSee('<span class="badge bg-danger">3</span>', false)
            ->assertSee('<span class="badge bg-success">50</span>', false)
            ->assertSee('<span class="badge bg-success">Active</span>', false)
            ->assertSee('<span class="badge bg-secondary">Inactive</span>', false)
            ->assertSee('action=edit&amp;id=1', false)
            ->assertSee('action=delete&amp;id=1', false)
            ->assertSee('data-confirm="Delete this medicine and all its batches?"', false)
            ->assertDontSee('No medicines found.');
    }

    // ---------------------------------------------------------------------
    // Search (original 75-91)
    // ---------------------------------------------------------------------

    public function test_search_matches_name_or_generic_case_insensitively(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMedicine('Ibuprofen', ['generic_name' => 'Advil']);
        $this->makeMedicine('Paracetamol', ['generic_name' => 'Biogesic']);

        $this->actingAs($admin)->get('/admin/medicines?q=IBUP')
            ->assertOk()->assertSee('Ibuprofen')->assertDontSee('Paracetamol');

        $this->actingAs($admin)->get('/admin/medicines?q=biog')
            ->assertOk()->assertSee('Paracetamol')->assertDontSee('Ibuprofen');

        $this->actingAs($admin)->get('/admin/medicines?q=zzz')
            ->assertOk()->assertSee('No medicines found.');
    }

    // ---------------------------------------------------------------------
    // Store / update (original 15-55)
    // ---------------------------------------------------------------------

    public function test_valid_create_redirects_flashes_and_writes_audit(): void
    {
        $admin = $this->makeAdmin();
        $catId = $this->makeCategory('Antibiotics');

        $this->actingAs($admin)->post('/admin/medicines', [
            'name' => 'Amoxicillin',
            'generic_name' => 'AMOX',
            'category_id' => $catId,
            'unit' => 'capsule',
            'description' => '500mg caps',
            'low_stock_threshold' => 7,
            'is_active' => 'on',
        ])->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine created.');

        $row = DB::table('medicines')->where('name', 'Amoxicillin')->first();
        $this->assertNotNull($row);
        $this->assertSame('AMOX', $row->generic_name);
        $this->assertSame($catId, (int) $row->category_id);
        $this->assertSame('capsule', $row->unit);
        $this->assertSame('500mg caps', $row->description);
        $this->assertSame(7, (int) $row->low_stock_threshold);
        $this->assertSame(1, (int) $row->is_active);

        $audit = DB::table('audit_logs')->where('action', 'create_medicine')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $row->id, (int) $audit->record_id);
        $this->assertSame('medicines', $audit->table_name);
        $this->assertSame('Created medicine Amoxicillin', $audit->details);

        // The new row renders on the next visit.
        $this->actingAs($admin)->get('/admin/medicines')->assertSee('Amoxicillin');
    }

    public function test_missing_fields_keep_reference_defaults(): void
    {
        $admin = $this->makeAdmin();

        // name '  ' trims to empty -> required error (no insert).
        $this->actingAs($admin)->post('/admin/medicines', ['name' => '   '])
            ->assertOk()
            ->assertSee('Medicine name is required.');
        $this->assertSame(0, DB::table('medicines')->count());

        // Empty unit defaults to 'tablet', missing threshold to 10, and a
        // POST without is_active stores 0 (checkbox must be sent).
        $this->actingAs($admin)->post('/admin/medicines', ['name' => 'Aspirin', 'unit' => '  '])
            ->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine created.');

        $row = DB::table('medicines')->where('name', 'Aspirin')->first();
        $this->assertSame('tablet', $row->unit);
        $this->assertSame(10, (int) $row->low_stock_threshold);
        $this->assertSame(0, (int) $row->is_active);
    }

    public function test_negative_threshold_reports_before_the_duplicate_check(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMedicine('Ibuprofen');

        // Both validation rules run in order; the duplicate check is skipped
        // while any error exists (original: if (!$errors) guards each stage).
        $this->actingAs($admin)->post('/admin/medicines', [
            'name' => 'Ibuprofen', 'low_stock_threshold' => -5,
        ])->assertOk()
            ->assertSee('Low stock threshold cannot be negative.')
            ->assertDontSee('A medicine with this name already exists.');

        $this->assertSame(1, DB::table('medicines')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_duplicate_name_is_case_insensitive_on_create_and_update(): void
    {
        $admin = $this->makeAdmin();
        $a = $this->makeMedicine('ibuprofen');
        $b = $this->makeMedicine('Paracetamol');

        $this->actingAs($admin)->post('/admin/medicines', ['name' => 'IBUPROFEN'])
            ->assertOk()
            ->assertSee('A medicine with this name already exists.');
        $this->assertSame(2, DB::table('medicines')->count());

        // Updating row B to row A's name is blocked too...
        $this->actingAs($admin)->post('/admin/medicines', [
            'id' => $b, 'name' => 'Ibuprofen',
        ])->assertOk()->assertSee('A medicine with this name already exists.');
        $this->assertSame('Paracetamol', DB::table('medicines')->find($b)->name);

        // ...but updating A with its OWN name passes (id <> $mid).
        $this->actingAs($admin)->post('/admin/medicines', [
            'id' => $a, 'name' => 'Ibuprofen', 'unit' => 'tablet',
        ])->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine updated.');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'update_medicine')->count());
    }

    public function test_update_rewrites_every_field_including_the_checkbox(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->makeMedicine('Ibuprofen', ['generic_name' => 'Advil', 'is_active' => 1]);

        $this->actingAs($admin)->post('/admin/medicines', [
            'id' => $id,
            'name' => 'Ibuprofen Forte',
            'generic_name' => '',
            'unit' => '',
            'description' => 'updated',
            'low_stock_threshold' => 3,
        ])->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine updated.');

        $row = DB::table('medicines')->find($id);
        $this->assertSame('Ibuprofen Forte', $row->name);
        // A blank generic_name stores '' (not NULL): the reference passes
        // trim() straight through to the UPDATE.
        $this->assertSame('', $row->generic_name);
        $this->assertSame('tablet', $row->unit);
        $this->assertSame('updated', $row->description);
        $this->assertSame(3, (int) $row->low_stock_threshold);
        // The checkbox was not sent -> is_active flips to 0 (reference does a
        // full-field UPDATE, never a partial one).
        $this->assertSame(0, (int) $row->is_active);

        // A POST with id=0 falls into the INSERT branch (original quirk).
        $this->actingAs($admin)->post('/admin/medicines', [
            'id' => 0, 'name' => 'Zero Id',
        ])->assertSessionHas('success', 'Medicine created.');
        $this->assertSame(2, DB::table('medicines')->count());
    }

    public function test_failed_post_to_edit_action_keeps_the_edit_context(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->makeMedicine('Ibuprofen', ['generic_name' => 'Advil']);

        // Validation errors fall through to the ?action=edit context read
        // from the QUERY string - the modal stays on the edit row's values.
        $this->actingAs($admin)->post('/admin/medicines?action=edit&id='.$id, [
            'name' => '',
        ])->assertOk()
            ->assertSee('Medicine name is required.')
            ->assertSee('Edit Medicine')
            ->assertSee('value="Ibuprofen"', false)
            ->assertDontSee('new bootstrap.Modal(modal).show()', false);

        $this->assertSame('Ibuprofen', DB::table('medicines')->find($id)->name);
    }

    // ---------------------------------------------------------------------
    // Delete / edit actions (original 57-69): no existence checks
    // ---------------------------------------------------------------------

    public function test_delete_removes_the_row_flashes_and_audits(): void
    {
        $admin = $this->makeAdmin();
        $id = $this->makeMedicine('Ibuprofen');

        $this->actingAs($admin)->get('/admin/medicines?action=delete&id='.$id)
            ->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine deleted.');

        $this->assertSame(0, DB::table('medicines')->count());
        $audit = DB::table('audit_logs')->where('action', 'delete_medicine')->first();
        $this->assertSame($id, (int) $audit->record_id);
        $this->assertSame('Deleted medicine #'.$id, $audit->details);
    }

    public function test_delete_of_a_missing_id_still_flashes_success(): void
    {
        $admin = $this->makeAdmin();

        // The reference DELETEs unconditionally and reads no rowCount.
        $this->actingAs($admin)->get('/admin/medicines?action=delete&id=999')
            ->assertRedirect(route('admin.medicines'))
            ->assertSessionHas('success', 'Medicine deleted.');

        $this->assertSame(1, DB::table('audit_logs')->where('action', 'delete_medicine')->count());
        $this->assertSame(999, (int) DB::table('audit_logs')->first()->record_id);
    }

    public function test_edit_action_prefills_the_modal(): void
    {
        $admin = $this->makeAdmin();
        $catId = $this->makeCategory('Painkiller');
        $id = $this->makeMedicine('Ibuprofen', [
            'generic_name' => 'Advil', 'category_id' => $catId, 'unit' => 'tablet',
            'description' => '200mg', 'low_stock_threshold' => 4, 'is_active' => 1,
        ]);

        $this->actingAs($admin)->get('/admin/medicines?action=edit&id='.$id)
            ->assertOk()
            ->assertSee('Edit Medicine')
            ->assertSee('value="Ibuprofen"', false)
            ->assertSee('value="Advil"', false)
            ->assertSee('name="low_stock_threshold" class="form-control" min="0" value="4"', false)
            ->assertSee('>200mg</textarea>', false)
            ->assertSee('id="is_active" checked', false)
            ->assertSee('<option value="'.$catId.'" selected>Painkiller</option>', false)
            ->assertSee('name="id" value="'.$id.'"', false);
    }

    public function test_edit_action_with_a_missing_id_renders_the_add_context(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMedicine('Ibuprofen');

        $this->actingAs($admin)->get('/admin/medicines?action=edit&id=999')
            ->assertOk()
            ->assertSee('Add Medicine')
            ->assertDontSee('Edit Medicine');
    }

    // ---------------------------------------------------------------------
    // Authz: require_login(['admin'])
    // ---------------------------------------------------------------------

    public function test_authz_matches_require_login(): void
    {
        $admin = $this->makeAdmin();
        $staff = $this->makeUser('staff', 'staff', 'employee');
        $student = $this->makeUser('student', 'user', 'student');

        $this->get('/admin/medicines')->assertRedirect(route('login'));
        $this->actingAs($staff)->get('/admin/medicines')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/medicines')->assertRedirect(route('user.dashboard'));

        // The store POST and the GET delete ride the same guard.
        $this->actingAs($staff)->post('/admin/medicines', ['name' => 'X'])
            ->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/medicines?action=delete&id=1')
            ->assertRedirect(route('user.dashboard'));
        $this->assertSame(0, DB::table('medicines')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        $this->actingAs($admin)->get('/admin/medicines')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeAdmin(): User
    {
        return $this->makeUser('admin', 'admin', null);
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

    private function makeCategory(string $name): int
    {
        return DB::table('medicine_categories')->insertGetId(['name' => $name]);
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
