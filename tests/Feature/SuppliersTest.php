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
 * Phase 26 admin/suppliers.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the closed-modal list render
 * (empty state, toolbar, row cells ordered by name, no address column),
 * search across all five columns, the edit-form prefills (row-based, no
 * reopen script), the create validation (name required - and NO duplicate
 * check, unlike categories: same name twice is allowed), the blind
 * insert/update with PRG + audit rows, and the legacy ?action=delete PRG
 * redirect (id=999 path included). Save paths run only here - browser QA
 * never sends a valid token because it would write live suppliers.
 */
class SuppliersTest extends TestCase
{
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
            $table->string('action', 50)->nullable();
            $table->string('table_name', 60)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_admin_renders_guest_redirects_and_wrong_roles_bounce(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $staff = $this->makeUser('staffx', 'staff');
        $student = $this->makeUser('mstudent', 'user');
        $employee = $this->makeUser('memployee', 'user');

        $this->get('/admin/suppliers')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/suppliers')->assertOk();
        $this->actingAs($staff)->get('/admin/suppliers')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/suppliers')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/suppliers')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    public function test_empty_list_renders_toolbar_modal_and_no_suppliers_state(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->get('/admin/suppliers');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/suppliers').'" class="nav-link active">', false);
        $response->assertSee('No suppliers yet.', false);

        // Toolbar: search input + Search + Add Supplier (no filter/Reset here).
        $response->assertSee('placeholder="Name, contact, phone or email"', false);
        $response->assertSee('data-bs-target="#supplierModal"', false);
        $response->assertSee('Add Supplier', false);
        $response->assertDontSee('All Types', false);

        // Modal exists (closed - no reopen script) with Add content.
        $response->assertSee('id="supplierModal"', false);
        $response->assertSee('<div class="card-header" id="supplierModalLabel">Add Supplier</div>', false);
        $response->assertSee('name="id" value="0"', false);
        $response->assertSee('data-swal-add="supplier"', false);
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);

        // @csrf present; error loop renders nothing.
        $response->assertSee('name="_token"', false);
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
    }

    public function test_rows_render_ordered_without_address_column_and_null_cells(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        // Insert out of order; ORDER BY name must sort them.
        DB::table('suppliers')->insert([
            ['name' => 'Zenith Meds', 'contact_person' => 'Zed Zorro', 'phone' => '09990001111', 'email' => 'zed@zenith.test', 'address' => 'Quezon City'],
            ['name' => 'Alpha Meds', 'contact_person' => null, 'phone' => '09171112222', 'email' => null, 'address' => 'Manila'],
            ['name' => 'Beta Meds', 'contact_person' => 'Bea B.', 'phone' => '09183334444', 'email' => 'bea@beta.test', 'address' => 'Cebu'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/suppliers');
        $response->assertOk();
        $response->assertDontSee('No suppliers yet.', false);

        // Sorted by name regardless of insert order.
        $html = $response->getContent();
        $this->assertTrue(strpos($html, '<td>Alpha Meds</td>') < strpos($html, '<td>Beta Meds</td>'));
        $this->assertTrue(strpos($html, '<td>Beta Meds</td>') < strpos($html, '<td>Zenith Meds</td>'));

        // Cells: contact/phone/email shown, NULL renders empty, address is
        // NOT a table column (only the modal carries it).
        $response->assertSee('<td>09171112222</td>', false);
        $response->assertSee('<td></td>', false);
        $response->assertDontSee('<td>Quezon City</td>', false);
        $response->assertDontSee('Quezon City', false);

        // Row actions.
        $response->assertSee('data-confirm="Delete this supplier?"', false);
        $response->assertSee('data-swal-delete="supplier"', false);
        $response->assertSee('admin/suppliers?action=edit&amp;id=', false);
        $response->assertSee('admin/suppliers?action=delete&amp;id=', false);
    }

    public function test_search_matches_all_five_columns(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('suppliers')->insert([
            ['name' => 'Alpha Meds', 'contact_person' => 'Maria Santos', 'phone' => '09171112222', 'email' => 'sales@alpha.test', 'address' => '123 Rizal Ave, Manila'],
            ['name' => 'Beta Meds', 'contact_person' => 'Juan Dela Cruz', 'phone' => '09183334444', 'email' => 'info@beta.test', 'address' => '456 Bonifacio St, Cebu'],
        ]);

        // name
        $this->actingAs($admin)->get('/admin/suppliers?q=alpha')
            ->assertSee('<td>Alpha Meds</td>', false)
            ->assertDontSee('<td>Beta Meds</td>', false);

        // contact_person
        $this->actingAs($admin)->get('/admin/suppliers?q=dela')
            ->assertSee('<td>Beta Meds</td>', false)
            ->assertDontSee('<td>Alpha Meds</td>', false);

        // phone (fragment)
        $this->actingAs($admin)->get('/admin/suppliers?q=3334444')
            ->assertSee('<td>Beta Meds</td>', false)
            ->assertDontSee('<td>Alpha Meds</td>', false);

        // email
        $this->actingAs($admin)->get('/admin/suppliers?q=sales@alpha')
            ->assertSee('<td>Alpha Meds</td>', false)
            ->assertDontSee('<td>Beta Meds</td>', false);

        // address (fifth column - not shown in the table but searched)
        $this->actingAs($admin)->get('/admin/suppliers?q=Bonifacio')
            ->assertSee('<td>Beta Meds</td>', false)
            ->assertDontSee('<td>Alpha Meds</td>', false);

        // empty state + q echoed
        $this->actingAs($admin)->get('/admin/suppliers?q=zzz')
            ->assertSee('No suppliers yet.', false)
            ->assertSee('value="zzz"', false);
    }

    public function test_edit_form_prefills_row_and_modal_stays_closed(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $sid = DB::table('suppliers')->insertGetId([
            'name' => 'Alpha Meds', 'contact_person' => 'Maria Santos',
            'phone' => '09171112222', 'email' => 'sales@alpha.test',
            'address' => '123 Rizal Ave, Manila',
        ]);

        $response = $this->actingAs($admin)->get('/admin/suppliers?action=edit&id='.$sid);
        $response->assertOk();
        $response->assertSee('Edit Supplier', false);
        $response->assertSee('name="id" value="'.$sid.'"', false);
        $response->assertSee('value="Alpha Meds"', false);
        $response->assertSee('value="Maria Santos"', false);
        $response->assertSee('value="09171112222"', false);
        $response->assertSee('value="sales@alpha.test"', false);
        $response->assertSee('>123 Rizal Ave, Manila</textarea>', false);
        $response->assertSee('data-swal-save="supplier"', false);
        $response->assertSee(' Update</button>', false);
        $response->assertSee('Cancel', false);
        // Modal renders CLOSED (reference has no reopen script - quirk kept).
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
        // List still renders below the (hidden) modal.
        $response->assertSee('<table class="table table-hover mb-0 align-middle">', false);

        // Unknown id: falls back to the Add Supplier content.
        $this->actingAs($admin)->get('/admin/suppliers?action=edit&id=999')
            ->assertSee('>Add Supplier</div>', false);
    }

    // ---------------------------------------------------------------------
    // Save (create / edit)
    // ---------------------------------------------------------------------

    public function test_create_valid_inserts_logs_and_prg(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->post('/admin/suppliers', [
            'name' => '  Alpha Meds  ',
            'contact_person' => ' Maria Santos ',
            'phone' => '09171112222',
            'email' => 'sales@alpha.test',
            'address' => '123 Rizal Ave, Manila',
        ]);

        $response->assertRedirect(route('admin.suppliers'));
        $response->assertSessionHas('success', 'Supplier created.');

        $sup = DB::table('suppliers')->where('name', 'Alpha Meds')->first();
        $this->assertNotNull($sup);
        $this->assertSame('Maria Santos', $sup->contact_person);
        $this->assertSame('123 Rizal Ave, Manila', $sup->address);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'create_supplier',
            'table_name' => 'suppliers',
            'record_id' => $sup->id,
            'details' => 'Created supplier Alpha Meds',
        ]);
    }

    public function test_only_name_is_required_and_duplicates_are_allowed(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        // Empty name -> error, fall-through render, nothing written.
        $this->actingAs($admin)->post('/admin/suppliers', ['name' => '   '])
            ->assertOk()
            ->assertSee('Supplier name is required.', false);
        $this->assertSame(0, DB::table('suppliers')->count());

        // NO duplicate check on this page (unlike categories): the same name
        // twice is stored twice - reference behavior kept.
        $this->actingAs($admin)->post('/admin/suppliers', ['name' => 'Alpha Meds'])
            ->assertRedirect(route('admin.suppliers'));
        $this->actingAs($admin)->post('/admin/suppliers', ['name' => 'Alpha Meds'])
            ->assertRedirect(route('admin.suppliers'));
        $this->assertSame(2, DB::table('suppliers')->count());
    }

    public function test_edit_valid_updates_and_logs(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $sid = DB::table('suppliers')->insertGetId([
            'name' => 'Alpha Meds', 'contact_person' => 'Old Person',
            'phone' => null, 'email' => null, 'address' => null,
        ]);

        $response = $this->actingAs($admin)->post('/admin/suppliers', [
            'id' => $sid,
            'name' => 'Alpha Meds Renamed',
            'contact_person' => 'New Person',
            'phone' => '09170000000',
            'email' => 'new@alpha.test',
            'address' => 'New address',
        ]);

        $response->assertRedirect(route('admin.suppliers'));
        $response->assertSessionHas('success', 'Supplier updated.');
        $this->assertDatabaseHas('suppliers', [
            'id' => $sid, 'name' => 'Alpha Meds Renamed', 'contact_person' => 'New Person',
            'email' => 'new@alpha.test', 'address' => 'New address',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'update_supplier',
            'table_name' => 'suppliers',
            'record_id' => $sid,
            'details' => 'Updated supplier Alpha Meds Renamed',
        ]);
    }

    // ---------------------------------------------------------------------
    // Legacy GET delete (PRG, no existence check)
    // ---------------------------------------------------------------------

    public function test_delete_redirects_logs_and_removes_the_row(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $sid = DB::table('suppliers')->insertGetId([
            'name' => 'Alpha Meds', 'contact_person' => 'Maria Santos',
            'phone' => null, 'email' => null, 'address' => null,
        ]);

        $response = $this->actingAs($admin)->get('/admin/suppliers?action=delete&id='.$sid);

        // PRG redirect (unlike admin/users.php's fall-through).
        $response->assertRedirect(route('admin.suppliers'));
        $response->assertSessionHas('success', 'Supplier deleted.');
        $this->assertDatabaseMissing('suppliers', ['id' => $sid]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_supplier',
            'table_name' => 'suppliers',
            'record_id' => $sid,
        ]);

        // id=999: no existence check - redirect + audit anyway, nothing removed.
        $this->actingAs($admin)->get('/admin/suppliers?action=delete&id=999')
            ->assertRedirect(route('admin.suppliers'));
        $this->assertDatabaseHas('audit_logs', ['record_id' => 999, 'action' => 'delete_supplier']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $role = 'user'): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'account_type' => null,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Old', 'last_name' => 'Name']);

        return $user->fresh();
    }
}
