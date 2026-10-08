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
 * Phase 16 equipment tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers admin/equipment.php: the POST create/update block (original lines
 * 11-47) with its two validations, the borrowed-recompute update (available
 * = total - (oldTotal - oldAvail)), the "record not found" fall-through for
 * a missing id, the condition_note presence quirk (an empty field stays
 * empty; only a MISSING key falls back to 'good'), the ?action=delete
 * branch with no existence check (an unknown id still audits + flashes
 * success after a zero-row DELETE), the ?action=edit fetch miss that
 * renders the ADD modal, the trimmed ?q= search, the DEAD ?condition=
 * toolbar echo, and role parity for all four roles.
 *
 * CSRF: the reference page has no token check at all; the port keeps the
 * global middleware (a token-less POST raises 419), which Feature tests
 * bypass, so no 419 assertion is possible here (Phase 6 convention).
 */
class EquipmentTest extends TestCase
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
    // Render (original 78-181)
    // ---------------------------------------------------------------------

    public function test_page_renders_toolbar_modal_table_and_add_mode(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair', ['description' => 'Standard folding wheelchair']);
        $this->makeEquipment('Crutches', ['total_qty' => 3]);

        $this->actingAs($admin)->get('/admin/equipment')
            ->assertOk()
            ->assertSee('Name, description or condition')
            ->assertSee('All Conditions')
            ->assertSee('Add Equipment')
            ->assertSee('Reset')
            ->assertSee('Wheelchair')
            ->assertSee('Crutches')
            // Add mode: header, swal attribute and Save button.
            ->assertSee('Add Equipment')
            ->assertSee('data-swal-add="equipment"', false)
            ->assertSee(' Save</button>', false)
            ->assertDontSee('Edit Equipment')
            // The modal posts through Laravel's CSRF field, not csrf_token.
            ->assertSee('name="_token"', false)
            ->assertDontSee('name="csrf_token"', false);
    }

    public function test_empty_state_when_no_equipment(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($admin)->get('/admin/equipment')
            ->assertOk()
            ->assertSee('No equipment yet.');
    }

    public function test_search_filters_by_name_description_or_condition(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair', ['description' => 'Standard folding wheelchair']);
        $this->makeEquipment('Crutches');

        $this->actingAs($admin)->get('/admin/equipment?q=wheel')
            ->assertOk()
            ->assertSee('Wheelchair')
            ->assertDontSee('Crutches');

        $this->actingAs($admin)->get('/admin/equipment?q=zzz')
            ->assertOk()
            ->assertSee('No equipment yet.');
    }

    public function test_condition_toolbar_input_is_dead_but_echoes_selected(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair');
        $this->makeEquipment('Crutches');

        $response = $this->actingAs($admin)->get('/admin/equipment?condition=good')
            ->assertOk()
            // Echoed into the select...
            ->assertSee('value="good" selected', false)
            // ...but never filters: both rows still render.
            ->assertSee('Wheelchair')
            ->assertSee('Crutches');
    }

    public function test_edit_mode_prefills_form_and_shows_cancel(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair', ['total_qty' => 2, 'condition_note' => 'fair']);

        $this->actingAs($admin)->get('/admin/equipment?action=edit&id=1')
            ->assertOk()
            ->assertSee('Edit Equipment')
            ->assertSee('value="Wheelchair"', false)
            ->assertSee('data-swal-save="equipment"', false)
            ->assertSee(' Update</button>', false)
            ->assertSee('>Cancel</a>', false)
            ->assertDontSee('data-swal-add="equipment"', false);
    }

    public function test_edit_with_missing_id_renders_add_mode(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair');

        // fetch() miss leaves $edit null: the reference renders the ADD modal.
        $this->actingAs($admin)->get('/admin/equipment?action=edit&id=999')
            ->assertOk()
            ->assertSee('Add Equipment')
            ->assertSee('data-swal-add="equipment"', false)
            ->assertDontSee('Edit Equipment');
    }

    // ---------------------------------------------------------------------
    // POST: create (original 39-46)
    // ---------------------------------------------------------------------

    public function test_create_requires_name(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($admin)->post('/admin/equipment', [
            'name' => '',
            'total_qty' => 5,
        ])->assertOk()
            ->assertSee('Equipment name is required.');

        $this->assertDatabaseCount('equipment', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_create_rejects_negative_total(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($admin)->post('/admin/equipment', [
            'name' => 'Walker',
            'total_qty' => -1,
        ])->assertOk()
            ->assertSee('Total quantity cannot be negative.');

        $this->assertDatabaseCount('equipment', 0);
    }

    public function test_create_success_redirects_writes_row_and_audit(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        $this->actingAs($admin)->post('/admin/equipment', [
            'name' => 'Walker',
            'description' => 'Steel walker',
            'total_qty' => 4,
            'condition_note' => 'fair',
            'is_active' => 'on',
        ])->assertRedirect(route('admin.equipment'))
            ->assertSessionHas('success', 'Equipment created.');

        $this->assertDatabaseHas('equipment', [
            'id' => 1,
            'name' => 'Walker',
            'description' => 'Steel walker',
            'total_qty' => 4,
            'available_qty' => 4,
            'condition_note' => 'fair',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create_equipment',
            'table_name' => 'equipment',
            'record_id' => 1,
            'details' => 'Created equipment Walker',
        ]);
    }

    public function test_condition_note_default_only_applies_when_key_is_missing(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        // Present but empty stays empty ($_POST[...] ?? 'good' never fires).
        $this->actingAs($admin)->post('/admin/equipment', [
            'name' => 'Plain',
            'total_qty' => 2,
            'condition_note' => '',
        ])->assertRedirect(route('admin.equipment'));

        $this->assertDatabaseHas('equipment', [
            'name' => 'Plain',
            'condition_note' => '',
            'is_active' => 0,
        ]);

        // Missing key falls back to 'good' (and an omitted checkbox is 0).
        $this->actingAs($admin)->post('/admin/equipment', [
            'name' => 'Defaulted',
            'total_qty' => 1,
        ])->assertRedirect(route('admin.equipment'));

        $this->assertDatabaseHas('equipment', [
            'name' => 'Defaulted',
            'condition_note' => 'good',
        ]);
    }

    // ---------------------------------------------------------------------
    // POST: update (original 23-38)
    // ---------------------------------------------------------------------

    public function test_update_recomputes_available_from_borrowed_units(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        // total 5, available 3 -> 2 units are out on loan.
        $this->makeEquipment('Wheelchair', ['total_qty' => 5, 'available_qty' => 3]);

        $this->actingAs($admin)->post('/admin/equipment', [
            'id' => 1,
            'name' => 'Wheelchair Pro',
            'description' => 'Updated chair',
            'total_qty' => 10,
            'condition_note' => 'good',
            'is_active' => 'on',
        ])->assertRedirect(route('admin.equipment'))
            ->assertSessionHas('success', 'Equipment updated.');

        // available = max(0, 10 - (5 - 3)) = 8: the borrowed units survive.
        $this->assertDatabaseHas('equipment', [
            'id' => 1,
            'name' => 'Wheelchair Pro',
            'total_qty' => 10,
            'available_qty' => 8,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'update_equipment',
            'table_name' => 'equipment',
            'record_id' => 1,
            'details' => 'Updated equipment Wheelchair Pro',
        ]);
    }

    public function test_update_with_missing_id_shows_error_without_writing(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair');

        $this->actingAs($admin)->post('/admin/equipment', [
            'id' => 999,
            'name' => 'Ghost',
            'total_qty' => 4,
        ])->assertOk()
            ->assertSee('Equipment record not found.');

        // No update, no audit: the error falls through to the list render.
        $this->assertDatabaseHas('equipment', ['id' => 1, 'name' => 'Wheelchair']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    // ---------------------------------------------------------------------
    // GET: ?action=delete (original 49-54) and ?action=edit (56-61)
    // ---------------------------------------------------------------------

    public function test_delete_removes_row_and_audits(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair');

        $this->actingAs($admin)->get('/admin/equipment?action=delete&id=1')
            ->assertRedirect(route('admin.equipment'))
            ->assertSessionHas('success', 'Equipment deleted.');

        $this->assertDatabaseCount('equipment', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_equipment',
            'table_name' => 'equipment',
            'record_id' => 1,
            'details' => 'Deleted equipment #1',
        ]);
    }

    public function test_delete_unknown_id_still_redirects_and_audits(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);

        // Reference quirk: no existence check - the zero-row DELETE still
        // writes the audit row and flashes success.
        $this->actingAs($admin)->get('/admin/equipment?action=delete&id=999')
            ->assertRedirect(route('admin.equipment'))
            ->assertSessionHas('success', 'Equipment deleted.');

        $this->assertDatabaseCount('equipment', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_equipment',
            'table_name' => 'equipment',
            'record_id' => 999,
        ]);
    }

    public function test_delete_without_id_renders_list_unchanged(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $this->makeEquipment('Wheelchair');

        $this->actingAs($admin)->get('/admin/equipment?action=delete')
            ->assertOk()
            ->assertSee('Wheelchair');

        $this->assertDatabaseCount('equipment', 1);
    }

    // ---------------------------------------------------------------------
    // Role parity (require_login(['admin']))
    // ---------------------------------------------------------------------

    public function test_role_parity(): void
    {
        $admin = $this->makeUser('boss', 'admin', null);
        $staff = $this->makeUser('clinic', 'staff', null);
        $student = $this->makeUser('stu', 'user', 'student');
        $employee = $this->makeUser('emp', 'user', 'employee');

        $this->get('/admin/equipment')->assertRedirect(route('login'));
        $this->actingAs($staff)->get('/admin/equipment')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/equipment')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/equipment')->assertRedirect(route('user.dashboard'));
        $this->actingAs($staff)->post('/admin/equipment', ['name' => 'X'])
            ->assertRedirect(route('staff.dashboard'));

        $this->actingAs($admin)->get('/admin/equipment')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

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

    private function makeEquipment(string $name, array $extra = []): int
    {
        return DB::table('equipment')->insertGetId($extra + [
            'name' => $name,
            'total_qty' => 1,
            'available_qty' => 1,
        ]);
    }
}
