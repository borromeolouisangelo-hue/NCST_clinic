<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cross-module coverage for the module histories feature on the ONE
 * existing Transaction History page (/staff/history?tab=<section>): every
 * Staff module's View All action opens that page with its own tab already
 * selected, rather than a per-module history screen that no longer exists.
 *
 * Per-module behaviour - the 10-row preview, per-page sizes (100/200/20/
 * 100/100), live filters and filter-preserving pagination - lives in each
 * module's own test file (ConsultationTest, DispenseTest,
 * EquipmentLoansTest, CertificateTest, SickLeaveTest).
 */
class ModuleHistoryTest extends TestCase
{
    private const PASSWORD = 'secret123';

    /**
     * View All destination: the ?tab= value the existing Transaction
     * History page takes, mapped to the module page its action lives on.
     */
    private const MODULES = [
        'consultations' => '/staff/consultations',
        'dispense' => '/staff/dispense',
        'equipment-loans' => '/staff/equipment_loans',
        'certificates' => '/staff/certificates',
        'sick-leaves' => '/staff/sick_leaves',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // No per-test-file migrations exist: this file seeds the same
        // table set StaffHistoryTest does (the seven history sections'
        // sources plus the shell pieces), since the five module pages and
        // their history endpoints read them all.
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

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->text('complaint')->nullable();
            $table->string('blood_pressure', 20)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('pulse')->nullable();
            $table->integer('respiration')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('consultation_date')->useCurrent();
        });

        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('cert_no', 40)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('cert_type', 30)->default('medical');
            $table->string('purpose', 255)->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->integer('rest_days')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->useCurrent();
        });

        Schema::create('sick_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('attachment', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('type', 20)->default('medicine');
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

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

        // The loans page reads get_setting_int('default_equipment_loan_limit')
        // through the live system_settings table.
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 80)->unique();
            $table->text('setting_value')->nullable();
            $table->dateTime('updated_at')->useCurrent();
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

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('equipment_id');
            $table->integer('quantity')->default(1);
            $table->date('borrow_date');
            $table->timestamp('borrowed_at')->useCurrent();
            $table->date('due_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 20)->default('borrowed');
            $table->string('condition_on_return', 120)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function test_every_view_all_destination_is_staff_only(): void
    {
        $staff = $this->makeStaff();
        $admin = $this->makeUser('boss', 'admin', null);
        $student = $this->makeUser('stu', 'user', 'student');

        $paths = array_map(fn (string $tab) => '/staff/history?tab='.$tab, array_keys(self::MODULES));

        // One role group per loop: actingAs() persists for every later
        // request in the same test, so a guest call must never follow an
        // authenticated one without flushing the session first.
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }

        foreach ($paths as $path) {
            $this->actingAs($admin)->get($path)->assertRedirect(route('admin.dashboard'));
        }

        foreach ($paths as $path) {
            $this->actingAs($student)->get($path)->assertRedirect(route('user.dashboard'));
        }

        foreach ($paths as $path) {
            $this->actingAs($staff)->get($path)->assertOk();
        }
    }

    public function test_every_module_opens_the_history_page_with_its_own_tab_selected(): void
    {
        $staff = $this->makeStaff();

        foreach (self::MODULES as $tab => $modulePath) {
            // The module page's View All action targets the EXISTING
            // Transaction History route with its own ?tab=, and no module
            // still links to a per-module history screen.
            $module = $this->actingAs($staff)->get($modulePath)->assertOk();
            $module->assertSee(route('staff.history', ['tab' => $tab]), false)
                ->assertSee('View All', false)
                ->assertDontSee(rtrim($modulePath, '/').'/history', false);

            // Arriving there selects that tab: its pill is the active one
            // and carries aria-current="page".
            $history = $this->actingAs($staff)->get('/staff/history?tab='.$tab)->assertOk();
            $this->assertMatchesRegularExpression(
                '/class="nav-link active"\s+data-history-tab="'.preg_quote($tab, '/').'"\s+aria-current="page"/',
                $history->getContent(),
            );
        }
    }

    /** Empty tables must render the shared empty state, not an error. */
    public function test_every_tab_renders_the_shared_empty_state_on_empty_tables(): void
    {
        $staff = $this->makeStaff();

        foreach (array_keys(self::MODULES) as $tab) {
            $this->actingAs($staff)->get('/staff/history?tab='.$tab)
                ->assertOk()
                ->assertSee('No records found.')
                ->assertSee('0 records', false);
        }
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
}
