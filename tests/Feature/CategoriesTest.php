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
 * Phase 25 admin/categories.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the closed-modal list render
 * (empty state, toolbar with RAW type selected-state, med_count subquery
 * rows ordered by name), search across name/description/type (and the
 * preserved legacy quirk that ?type= never filters the SQL), the edit-form
 * prefills (row-based, no reopen script - reference behavior), the create
 * validation rules (required name + per-type duplicate - same name under a
 * different type is allowed), the blind insert/update with PRG + audit rows,
 * the type whitelist on POST, and the legacy ?action=delete PRG redirect
 * (including the no-existence-check id=999 path). Save paths run only here -
 * browser QA never sends a valid token because it would write live
 * medicine_categories.
 */
class CategoriesTest extends TestCase
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

        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('type', 20)->default('medicine');
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name', 100);
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

        $this->get('/admin/categories')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/categories')->assertOk();
        $this->actingAs($staff)->get('/admin/categories')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/categories')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/categories')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    public function test_empty_list_renders_toolbar_modal_and_no_categories_state(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->get('/admin/categories');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/categories').'" class="nav-link active">', false);
        $response->assertSee('No categories yet.', false);

        // Toolbar: search input, All Types, buttons.
        $response->assertSee('placeholder="Name, type or description"', false);
        $response->assertSee('<option value="">All Types</option>', false);
        $response->assertSee('data-bs-target="#categoryModal"', false);
        $response->assertSee('Add Category', false);
        $response->assertSee('Reset', false);

        // Modal exists (closed - no reopen script anywhere) with Add content.
        $response->assertSee('id="categoryModal"', false);
        $response->assertSee('<div class="card-header" id="categoryModalLabel">Add Category</div>', false);
        $response->assertSee('name="id" value="0"', false);
        $response->assertSee('data-swal-add="category"', false);
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);

        // Modal default type selected once; toolbar option unselected.
        $this->assertSame(1, substr_count($response->getContent(), 'value="medicine" selected'));
        $response->assertSee('<option value="medicine" >Medicine</option>', false);

        // @csrf present; error loop renders nothing.
        $response->assertSee('name="_token"', false);
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
    }

    public function test_rows_render_ordered_with_badges_counts_and_links(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        // Insert out of order; ORDER BY c.name must sort them.
        $bandages = DB::table('medicine_categories')->insertGetId([
            'name' => 'Bandages', 'description' => 'Adhesive strips', 'type' => 'first_aid',
        ]);
        $antibiotics = DB::table('medicine_categories')->insertGetId([
            'name' => 'Antibiotics', 'description' => null, 'type' => 'medicine',
        ]);
        $analgesics = DB::table('medicine_categories')->insertGetId([
            'name' => 'Analgesics', 'description' => 'Pain relievers', 'type' => 'medicine',
        ]);

        DB::table('medicines')->insert([
            ['category_id' => $analgesics, 'name' => 'Paracetamol'],
            ['category_id' => $analgesics, 'name' => 'Ibuprofen'],
            ['category_id' => $antibiotics, 'name' => 'Amoxicillin'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/categories');
        $response->assertOk();
        $response->assertDontSee('No categories yet.', false);

        // Sorted by name regardless of insert order.
        $html = $response->getContent();
        $this->assertTrue(strpos($html, '<td>Analgesics</td>') < strpos($html, '<td>Antibiotics</td>'));
        $this->assertTrue(strpos($html, '<td>Antibiotics</td>') < strpos($html, '<td>Bandages</td>'));

        // Type badges + med_count + null description renders empty.
        $response->assertSee('<span class="badge bg-secondary">Medicine</span>', false);
        $response->assertSee('<span class="badge bg-secondary">First Aid / Wound Care</span>', false);
        $response->assertSee('<td class="text-end">2</td>', false);
        $response->assertSee('<td class="text-end">1</td>', false);
        $response->assertSee('<td class="text-end">0</td>', false);
        $response->assertSee('<td></td>', false);
        $response->assertSee('<td>Pain relievers</td>', false);

        // Row actions (raw hrefs, no .php).
        $response->assertSee(str_replace('&', '&amp;', url('admin/categories?action=edit&id='.$analgesics)), false);
        $response->assertSee(str_replace('&', '&amp;', url('admin/categories?action=delete&id='.$analgesics)), false);
        $response->assertSee('data-confirm="Delete this category?"', false);
        $response->assertSee('data-swal-delete="category"', false);
    }

    public function test_search_matches_name_description_and_type_but_type_select_never_filters(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('medicine_categories')->insert([
            ['name' => 'Analgesics', 'description' => 'Pain relievers', 'type' => 'medicine'],
            ['name' => 'Bandages', 'description' => 'Adhesive strips', 'type' => 'first_aid'],
        ]);

        // q matches description...
        $this->actingAs($admin)->get('/admin/categories?q=adhesive')
            ->assertSee('<td>Bandages</td>', false)
            ->assertDontSee('<td>Analgesics</td>', false);

        // ...and the type column (reference LIKE includes type).
        $this->actingAs($admin)->get('/admin/categories?q=first_aid')
            ->assertSee('<td>Bandages</td>', false)
            ->assertDontSee('<td>Analgesics</td>', false);

        // Empty state + q echoed in the input.
        $this->actingAs($admin)->get('/admin/categories?q=zzz')
            ->assertSee('No categories yet.', false)
            ->assertSee('value="zzz"', false);

        // LEGACY QUIRK: ?type= never filters the SQL - all rows still render
        // (only the toolbar selected-state consumes the parameter).
        $this->actingAs($admin)->get('/admin/categories?type=first_aid')
            ->assertSee('<td>Analgesics</td>', false)
            ->assertSee('<td>Bandages</td>', false);
    }

    public function test_edit_form_prefills_row_and_modal_stays_closed(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $cid = DB::table('medicine_categories')->insertGetId([
            'name' => 'Bandages', 'description' => 'Adhesive strips', 'type' => 'first_aid',
        ]);

        $response = $this->actingAs($admin)->get('/admin/categories?action=edit&id='.$cid);
        $response->assertOk();
        $response->assertSee('Edit Category', false);
        $response->assertSee('name="id" value="'.$cid.'"', false);
        $response->assertSee('value="Bandages"', false);
        $response->assertSee('>Adhesive strips</textarea>', false);
        $response->assertSee('<option value="first_aid" selected>First Aid / Wound Care</option>', false);
        $response->assertSee('data-swal-save="category"', false);
        $response->assertSee(' Update</button>', false);
        $response->assertSee('Cancel', false);
        // Modal renders CLOSED (reference has no reopen script - quirk kept).
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
        // List still renders below the (hidden) modal.
        $response->assertSee('<table class="table table-hover mb-0 align-middle">', false);

        // Unknown id: falls back to the Add Category content.
        $this->actingAs($admin)->get('/admin/categories?action=edit&id=999')
            ->assertSee('>Add Category</div>', false);
    }

    // ---------------------------------------------------------------------
    // Save (create / edit)
    // ---------------------------------------------------------------------

    public function test_create_valid_inserts_logs_and_prg(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->post('/admin/categories', [
            'name' => '  Analgesics  ',
            'description' => 'Pain relievers',
            'type' => 'first_aid',
        ]);

        $response->assertRedirect(route('admin.categories'));
        $response->assertSessionHas('success', 'Category created.');

        $cat = DB::table('medicine_categories')->where('name', 'Analgesics')->first();
        $this->assertNotNull($cat);
        $this->assertSame('first_aid', $cat->type);
        $this->assertSame('Pain relievers', $cat->description);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'create_category',
            'table_name' => 'medicine_categories',
            'record_id' => $cat->id,
            'details' => 'Created category Analgesics',
        ]);
    }

    public function test_validation_requires_name_and_duplicates_are_scoped_per_type(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('medicine_categories')->insert([
            ['name' => 'Analgesics', 'description' => 'Pain relievers', 'type' => 'medicine'],
        ]);

        // Empty name -> error, fall-through render, nothing written.
        $this->actingAs($admin)->post('/admin/categories', ['name' => '   ', 'type' => 'medicine'])
            ->assertOk()
            ->assertSee('Category name is required.', false);
        $this->assertSame(1, DB::table('medicine_categories')->count());

        // Duplicate within the same type -> error, nothing written.
        $this->actingAs($admin)->post('/admin/categories', [
            'name' => 'Analgesics', 'type' => 'medicine',
        ])
            ->assertOk()
            ->assertSee('Category name already exists in this type.', false);
        $this->assertSame(1, DB::table('medicine_categories')->count());

        // Same name under a DIFFERENT type is allowed (dup check includes type).
        $this->actingAs($admin)->post('/admin/categories', [
            'name' => 'Analgesics', 'type' => 'first_aid',
        ])->assertRedirect(route('admin.categories'));
        $this->assertSame(2, DB::table('medicine_categories')->count());
        $this->assertDatabaseHas('medicine_categories', ['name' => 'Analgesics', 'type' => 'first_aid']);
    }

    public function test_edit_valid_updates_and_logs(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $cid = DB::table('medicine_categories')->insertGetId([
            'name' => 'Analgesics', 'description' => 'Old text', 'type' => 'medicine',
        ]);

        $response = $this->actingAs($admin)->post('/admin/categories', [
            'id' => $cid,
            'name' => 'Analgesics Updated',
            'description' => 'New text',
            'type' => 'medicine',
        ]);

        $response->assertRedirect(route('admin.categories'));
        $response->assertSessionHas('success', 'Category updated.');
        $this->assertDatabaseHas('medicine_categories', [
            'id' => $cid, 'name' => 'Analgesics Updated', 'description' => 'New text',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'update_category',
            'table_name' => 'medicine_categories',
            'record_id' => $cid,
            'details' => 'Updated category Analgesics Updated',
        ]);
    }

    public function test_type_whitelist_coerces_unknown_values_to_medicine(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $this->actingAs($admin)->post('/admin/categories', [
            'name' => 'Whatever', 'type' => 'bogus',
        ])->assertRedirect(route('admin.categories'));

        $this->assertDatabaseHas('medicine_categories', ['name' => 'Whatever', 'type' => 'medicine']);
    }

    // ---------------------------------------------------------------------
    // Legacy GET delete (PRG, no existence check)
    // ---------------------------------------------------------------------

    public function test_delete_redirects_logs_and_removes_the_row(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $cid = DB::table('medicine_categories')->insertGetId([
            'name' => 'Analgesics', 'description' => 'Pain relievers', 'type' => 'medicine',
        ]);

        $response = $this->actingAs($admin)->get('/admin/categories?action=delete&id='.$cid);

        // PRG redirect (unlike admin/users.php's fall-through).
        $response->assertRedirect(route('admin.categories'));
        $response->assertSessionHas('success', 'Category deleted.');
        $this->assertDatabaseMissing('medicine_categories', ['id' => $cid]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'delete_category',
            'table_name' => 'medicine_categories',
            'record_id' => $cid,
        ]);

        // id=999: no existence check - redirect + audit anyway, nothing removed.
        $this->actingAs($admin)->get('/admin/categories?action=delete&id=999')
            ->assertRedirect(route('admin.categories'));
        $this->assertDatabaseHas('audit_logs', ['record_id' => 999, 'action' => 'delete_category']);
    }

    public function test_raw_type_value_renders_no_toolbar_selection(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('medicine_categories')->insert([
            ['name' => 'Analgesics', 'description' => 'Pain relievers', 'type' => 'medicine'],
        ]);

        // Valid type: toolbar option selected (modal option is selected too,
        // so the needle appears twice - modal first in source order).
        $clean = $this->actingAs($admin)->get('/admin/categories?type=medicine');
        $this->assertSame(2, substr_count($clean->getContent(), 'value="medicine" selected'));

        // RAW ' medicine ' (like PHP's $_GET under no TrimStrings): toolbar
        // matches nothing, modal still carries its default selection.
        $raw = $this->actingAs($admin)->get('/admin/categories?type=%20medicine%20');
        $this->assertSame(1, substr_count($raw->getContent(), 'value="medicine" selected'));
        $raw->assertSee('<option value="medicine" >Medicine</option>', false);
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
