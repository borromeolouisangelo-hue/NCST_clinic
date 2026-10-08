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
 * Phase 31 notifications.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers bare require_login() (all four roles render, guest -> login), the
 * empty state with the conditional Mark All Read form (only while unread >
 * 0), the filter buttons (raw filter string -> junk leaves BOTH outline),
 * ?filter=unread scoping, card rendering (severity icon defaults via the
 * match island, custom icon precedence, badge classes, unread border +
 * Mark-as-read form only on unread rows, muted read rows, action_url View
 * link only when set, created_at DESC order, LIMIT 100 cap), the mark_all_read
 * PRG + affected-count flash (caller-scoped - foreign rows untouched), the
 * mark_read user scope (foreign id never flips; id=0/999 no-op but still
 * redirects) and the unknown-action fall-through (renders, no PRG - original
 * lines 19-34). GET-only concerns aside, POSTs are exercised with the
 * feature-suite CSRF bypass; the 419 path is browser-asserted.
 */
class NotificationsTest extends TestCase
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
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_all_four_roles_render_and_guest_redirects(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $staff = $this->makeUser('staffx', 'staff');
        $student = $this->makeUser('mstudent', 'user');
        $employee = $this->makeUser('memployee', 'user');

        $this->get('/notifications')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/notifications')->assertOk();
        $this->actingAs($staff)->get('/notifications')->assertOk();
        $this->actingAs($student)->get('/notifications')->assertOk();
        $this->actingAs($employee)->get('/notifications')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Empty state + conditional form + filter buttons
    // ---------------------------------------------------------------------

    public function test_empty_state_hides_mark_all_form_and_all_button_stays_active(): void
    {
        // Student: the user-branch sidebar carries the Notifications item
        // (admin/staff reach the centre through the topbar bell - the
        // reference keeps the same split, byte-verified since Phase 5).
        $student = $this->makeUser('mstudent', 'user');
        // Nothing seeded - no rows, unread = 0.

        $response = $this->actingAs($student)->get('/notifications');
        $response->assertOk();
        $response->assertSee('href="'.url('notifications').'" class="nav-link active">', false);
        $response->assertSee('No notifications to display.', false);
        $response->assertSee('Unread (0)', false);
        // Mark All Read only renders while unread > 0 (original line 63).
        $response->assertDontSee('value="mark_all_read"', false);
        // Active/outline pair for the default filter=all.
        $response->assertSee(
            '<a href="'.url('notifications').'?filter=all" class="btn btn-sm btn-primary">All</a>',
            false
        );
        $response->assertSee(
            '<a href="'.url('notifications').'?filter=unread" class="btn btn-sm btn-outline-primary">Unread (0)</a>',
            false
        );
        // No form at all -> no token input in the markup.
        $response->assertDontSee('name="_token"', false);
    }

    public function test_filter_unread_scopes_rows_and_junk_leaves_both_buttons_outline(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $this->seedRow($admin->id, 'U1', unread: true, at: '2026-01-01 10:00:00');
        $this->seedRow($admin->id, 'U2', unread: true, at: '2026-01-02 10:00:00');
        $this->seedRow($admin->id, 'R1', unread: false, at: '2026-01-03 10:00:00');

        // Default: all three rows, Mark All Read present, unread badge = 2.
        $all = $this->actingAs($admin)->get('/notifications');
        $all->assertOk();
        $this->assertSame(3, substr_count($all->getContent(), 'notification-card" data-id='));
        $all->assertSee('Unread (2)', false);
        $all->assertSee('value="mark_all_read"', false);
        $all->assertSee('name="_token"', false);

        // ?filter=unread: only the two unread rows; unread button active.
        $unread = $this->actingAs($admin)->get('/notifications?filter=unread');
        $unread->assertOk();
        $this->assertSame(2, substr_count($unread->getContent(), 'notification-card" data-id='));
        $unread->assertSee(
            '<a href="'.url('notifications').'?filter=unread" class="btn btn-sm btn-primary">Unread (2)</a>',
            false
        );

        // Junk filter: rows unscoped (not 'unread'), BOTH buttons outline.
        $junk = $this->actingAs($admin)->get('/notifications?filter=whatever');
        $junk->assertOk();
        $this->assertSame(3, substr_count($junk->getContent(), 'notification-card" data-id='));
        $junk->assertSee(
            '<a href="'.url('notifications').'?filter=all" class="btn btn-sm btn-outline-primary">All</a>',
            false
        );
        $junk->assertSee(
            '<a href="'.url('notifications').'?filter=unread" class="btn btn-sm btn-outline-primary">Unread (2)</a>',
            false
        );
    }

    // ---------------------------------------------------------------------
    // Card rendering: severities, icons, badges, links, order, cap
    // ---------------------------------------------------------------------

    public function test_cards_render_severities_icons_badges_links_and_newest_first(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        DB::table('notifications')->insert([
            // success without a stored icon -> match default.
            ['user_id' => $admin->id, 'severity' => 'success', 'icon' => null, 'title' => 'T-old',
                'message' => 'older', 'action_url' => null, 'is_read' => false, 'created_at' => '2026-01-01 10:00:00'],
            // danger WITH a stored icon -> stored icon wins over the default.
            ['user_id' => $admin->id, 'severity' => 'danger', 'icon' => 'bi-star-fill', 'title' => 'T-mid',
                'message' => 'middle', 'action_url' => 'staff/patients', 'is_read' => false, 'created_at' => '2026-01-02 10:00:00'],
            // info -> default bell + primary classes.
            ['user_id' => $admin->id, 'severity' => 'info', 'icon' => null, 'title' => 'T-new',
                'message' => 'newest', 'action_url' => null, 'is_read' => true, 'created_at' => '2026-01-03 10:00:00'],
        ]);

        $html = $this->actingAs($admin)->get('/notifications')->assertOk()->getContent();

        // Severity defaults (match island) vs stored icon precedence.
        $this->assertStringContainsString('bi bi-bell-fill fs-4', $html, 'info -> default bell');
        $this->assertStringContainsString('bi bi-check-circle-fill fs-4', $html, 'success -> default check');
        $this->assertStringContainsString('bi bi-star-fill fs-4', $html, 'stored icon wins');

        // Text/badge classes per severity.
        $this->assertStringContainsString('notification-icon text-success flex-shrink-0', $html);
        $this->assertStringContainsString('notification-icon text-danger flex-shrink-0', $html);
        $this->assertStringContainsString('notification-icon text-primary flex-shrink-0', $html);
        $this->assertStringContainsString('badge bg-success">Success</span>', $html);
        $this->assertStringContainsString('badge bg-danger">Danger</span>', $html);
        $this->assertStringContainsString('badge bg-primary">Info</span>', $html);

        // Unread card keeps the border + Mark-as-read form; read card does not.
        $this->assertStringContainsString('border-start border-4 border-primary', $html);
        $this->assertStringContainsString('<div class="card  notification-card"', $html, 'read card class collapse');
        $this->assertSame(2, substr_count($html, 'value="mark_read"'), 'only unread rows get the form');
        $this->assertSame(1, substr_count($html, 'h6 class="mb-1 text-muted"'), 'read title muted');
        $this->assertSame(2, substr_count($html, 'h6 class="mb-1 fw-bold"'), 'unread titles bold');

        // action_url -> View link only when set.
        $this->assertStringContainsString('href="'.url('staff/patients').'"', $html);
        $this->assertSame(1, substr_count($html, '>View</a>'));

        // created_at DESC order (T-new before T-mid before T-old).
        $this->assertTrue(
            strpos($html, '>T-new</h6>') < strpos($html, '>T-mid</h6>')
            && strpos($html, '>T-mid</h6>') < strpos($html, '>T-old</h6>'),
            'newest first'
        );
    }

    public function test_list_is_capped_at_100_rows(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'user_id' => $admin->id,
                'severity' => 'info',
                'title' => 'N'.$i,
                'message' => 'msg',
                'is_read' => false,
                // Oldest first so N105 is the newest kept row.
                'created_at' => sprintf('2026-01-%02d 10:00:00', (($i - 1) % 27) + 1),
            ];
        }
        DB::table('notifications')->insert($rows);

        $html = $this->actingAs($admin)->get('/notifications')->assertOk()->getContent();
        $this->assertSame(100, substr_count($html, 'notification-card" data-id='));
    }

    // ---------------------------------------------------------------------
    // POST actions
    // ---------------------------------------------------------------------

    public function test_mark_all_read_prgs_with_count_and_stays_user_scoped(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $other = $this->makeUser('otherx', 'user');
        $this->seedRow($admin->id, 'M1', unread: true, at: '2026-01-01 10:00:00');
        $this->seedRow($admin->id, 'M2', unread: true, at: '2026-01-02 10:00:00');
        $this->seedRow($admin->id, 'M3', unread: false, at: '2026-01-03 10:00:00');
        $foreign = $this->seedRow($other->id, 'Foreign', unread: true, at: '2026-01-04 10:00:00');

        $response = $this->actingAs($admin)
            ->post('/notifications', ['action' => 'mark_all_read']);

        $response->assertRedirect(route('notifications'));
        $response->assertSessionHas('success', 'Marked 2 notification(s) as read.');

        $this->assertSame(0, DB::table('notifications')->where('user_id', $admin->id)->where('is_read', 0)->count());
        $this->assertSame(0, DB::table('notifications')->where('id', $foreign)->value('is_read'), 'foreign row untouched');
    }

    public function test_mark_read_flips_only_callers_row_and_odd_ids_still_redirect(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $other = $this->makeUser('otherx', 'user');
        $mine = $this->seedRow($admin->id, 'Mine', unread: true, at: '2026-01-01 10:00:00');
        $foreign = $this->seedRow($other->id, 'Foreign', unread: true, at: '2026-01-02 10:00:00');
        $this->seedRow($admin->id, 'Done', unread: false, at: '2026-01-03 10:00:00');

        // Own unread row flips (PRG).
        $this->actingAs($admin)
            ->post('/notifications', ['action' => 'mark_read', 'id' => $mine])
            ->assertRedirect(route('notifications'));
        $this->assertSame(1, DB::table('notifications')->where('id', $mine)->value('is_read'));

        // Foreign id: UPDATE is user-scoped -> no flip, still redirects.
        $this->actingAs($admin)
            ->post('/notifications', ['action' => 'mark_read', 'id' => $foreign])
            ->assertRedirect(route('notifications'));
        $this->assertSame(0, DB::table('notifications')->where('id', $foreign)->value('is_read'));

        // id=0 and unknown id: no write, still redirects (original L27-33).
        $this->actingAs($admin)
            ->post('/notifications', ['action' => 'mark_read', 'id' => 0])
            ->assertRedirect(route('notifications'));
        $this->actingAs($admin)
            ->post('/notifications', ['action' => 'mark_read', 'id' => 999])
            ->assertRedirect(route('notifications'));
        // Mine got flipped above; Done was already read; only Foreign (the
        // other user's row) is still unread.
        $this->assertSame(1, DB::table('notifications')->where('is_read', 0)->count(), 'only the foreign row stays unread');
    }

    public function test_unknown_or_missing_action_renders_instead_of_redirecting(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $this->seedRow($admin->id, 'Keep', unread: true, at: '2026-01-01 10:00:00');

        // Unknown action and missing action both fall through to the render
        // (original lines 19-34 have no branch for them - no PRG).
        $this->actingAs($admin)->post('/notifications', ['action' => 'bogus'])->assertOk();
        $this->actingAs($admin)->post('/notifications', [])->assertOk();
        $this->assertSame(
            1,
            DB::table('notifications')->where('user_id', $admin->id)->where('is_read', 0)->count(),
            'row still unread'
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function seedRow(int $uid, string $title, bool $unread, string $at): int
    {
        return (int) DB::table('notifications')->insertGetId([
            'user_id' => $uid,
            'severity' => 'info',
            'icon' => null,
            'title' => $title,
            'message' => 'message for '.$title,
            'action_url' => null,
            'is_read' => $unread ? 0 : 1,
            'created_at' => $at,
        ]);
    }

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
