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
 * Phase 34 api/* tests against the isolated in-memory SQLite database.
 *
 * The reference baselines below were captured live from
 * school-clinic/api/* (student session, empty notification tables), so the
 * byte-exact assertions mirror what PHP actually emitted:
 *
 *   GET              200 application/json         {"notifications":[],"unread_count":0}
 *   PATCH {id}       200 application/json         {"success":true,"unread_count":0}
 *   POST mark_all    200 application/json         {"success":true,"marked":0,"unread_count":0}
 *   POST mark_read   200 application/json         {"success":true,"unread_count":0}
 *   bad action/body  400 text/html; charset=UTF-8 {"error":"Invalid request"}
 *   PUT / DELETE      400 (the original falls through; Laravel's default
 *                        would be 405 - Route::any + method dispatch keeps
 *                        the 400)
 *   queue (web)      403 text/html; charset=UTF-8 Forbidden
 *   SSE              200 text/event-stream;charset=UTF-8,
 *                        Cache-Control: no-cache, no-transform
 *                          (port: ..., private appended by Symfony's header
 *                           bag - accepted, see the test body),
 *                        X-Accel-Buffering: no
 *   guests           302 to the login page (require_login -> auth)
 *
 * The SSE stream BODY is never materialised here: the reference loop runs
 * 55 real-time seconds (verified live instead). Feature tests assert the
 * headers, which Symfony has already computed without sending.
 */
class ApiNotificationsTest extends TestCase
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
            // Live reference_id is INT(11) (SHOW COLUMNS on school_clinic).
            $table->integer('reference_id')->nullable();
            $table->string('type', 60);
            $table->string('severity', 20)->default('info');
            $table->string('icon', 50)->nullable();
            $table->string('title', 150);
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'is_read']);
        });

        Schema::create('notification_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 60);
            $table->string('title', 150);
            $table->string('message');
            $table->string('action_url')->nullable();
            // Live column is an ENUM; SQLite has none. The plain string
            // also lets the invalid-severity mapping (NotificationModel:74)
            // be asserted instead of failing on a CHECK constraint.
            $table->string('severity', 20)->default('info');
            $table->string('icon', 50)->nullable();
            $table->integer('reference_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('processed_at')->nullable();
            $table->index('status');
        });
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_guests_redirect_on_session_endpoints_and_queue_web_access_is_403(): void
    {
        // require_login() -> auth middleware: the original answers a 302 to
        // auth/login.php for every method (JSON fetches included - the
        // client never sends an Accept that would make Laravel answer 401).
        $this->get('/api/notifications.php')->assertRedirect(route('login'));
        $this->post('/api/notifications.php')->assertRedirect(route('login'));
        $this->patch('/api/notifications.php')->assertRedirect(route('login'));
        $this->get('/api/notifications_sse.php')->assertRedirect(route('login'));

        // process_notification_queue.php checks php_sapi_name() FIRST: any
        // web method dies 403 with the literal body, no login involved.
        // (Feature tests bypass CSRF by design, so the POST lands on the
        // controller here; the browser smoke records the real-env token-less
        // POST answering 419 - the approved Phase 6 strengthening class.)
        foreach (['get', 'post', 'put'] as $verb) {
            $this->{$verb}('/api/process_notification_queue.php')
                ->assertStatus(403)
                ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
                ->assertContent('Forbidden');
        }
    }

    // ---------------------------------------------------------------------
    // GET - fetch
    // ---------------------------------------------------------------------

    public function test_empty_state_bytes_match_the_live_reference(): void
    {
        $user = $this->makeUser('apempty');

        // Captured live: {"notifications":[],"unread_count":0} etc. - the
        // seeded table is empty here, so the bytes must be identical.
        $this->actingAs($user)
            ->get('/api/notifications.php')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent('{"notifications":[],"unread_count":0}');

        $this->actingAs($user)
            ->json('PATCH', '/api/notifications.php', ['id' => 999])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent('{"success":true,"unread_count":0}');

        $this->actingAs($user)
            ->json('POST', '/api/notifications.php', ['action' => 'mark_all_read'])
            ->assertStatus(200)
            ->assertContent('{"success":true,"marked":0,"unread_count":0}');

        $this->actingAs($user)
            ->json('POST', '/api/notifications.php', ['action' => 'mark_read', 'id' => 999])
            ->assertStatus(200)
            ->assertContent('{"success":true,"unread_count":0}');
    }

    public function test_get_returns_rows_and_count_with_the_reference_json_shape(): void
    {
        $me = $this->makeUser('apstudent');
        $other = $this->makeUser('apother');

        // Three rows for me (2 unread, oldest first), one for someone else.
        $this->seedNotification($me->id, ['title' => 'Oldest one', 'created_at' => '2026-01-01 10:00:00']);
        $this->seedNotification($me->id, ['title' => 'Middle two', 'created_at' => '2026-01-02 10:00:00', 'is_read' => 1]);
        $this->seedNotification($me->id, ['title' => 'Newest three', 'created_at' => '2026-01-03 10:00:00']);
        $this->seedNotification($other->id, ['title' => 'Foreign row', 'created_at' => '2026-01-04 10:00:00']);

        $response = $this->actingAs($me)->get('/api/notifications.php');
        $response->assertStatus(200);
        $this->assertSame('application/json', $response->headers->get('content-type'));

        $payload = json_decode($response->getContent(), true);
        $this->assertSame(['notifications', 'unread_count'], array_keys($payload));
        $this->assertSame(2, $payload['unread_count'], 'only my unread rows count');
        $this->assertCount(3, $payload['notifications'], 'my rows only');

        // SELECT * keeps the live column order (SHOW COLUMNS on school_clinic).
        $this->assertSame(
            ['id', 'user_id', 'loan_id', 'reference_id', 'type', 'severity', 'icon', 'title', 'message', 'action_url', 'is_read', 'created_at'],
            array_keys($payload['notifications'][0]),
        );

        // ORDER BY created_at DESC: newest first.
        $this->assertSame(
            ['Newest three', 'Middle two', 'Oldest one'],
            array_column($payload['notifications'], 'title'),
        );
    }

    public function test_get_limit_defaults_to_10_caps_at_20_and_garbage_becomes_zero(): void
    {
        $user = $this->makeUser('aplimit');

        for ($i = 1; $i <= 25; $i++) {
            $this->seedNotification($user->id, [
                'title' => 'Row '.$i,
                'created_at' => sprintf('2026-02-%02d 10:00:00', $i),
            ]);
        }

        $titles = function (string $query = '') use ($user): array {
            $payload = json_decode(
                $this->actingAs($user)->get('/api/notifications.php'.$query)->getContent(),
                true,
            );

            return array_column($payload['notifications'], 'title');
        };

        // min(20, default 10) -> 10 newest.
        $this->assertCount(10, $titles(), 'default limit 10');
        // min(20, 999) -> capped at 20.
        $this->assertCount(20, $titles('?limit=999'), 'capped at 20');
        $this->assertSame('Row 25', $titles('?limit=999')[0], 'still newest-first');
        // Exact limit passes through.
        $this->assertSame(['Row 25', 'Row 24'], $titles('?limit=2'));
        // (int)'abc' = 0 -> LIMIT 0 -> no rows, count still real.
        $garbage = json_decode(
            $this->actingAs($user)->get('/api/notifications.php?limit=abc')->getContent(),
            true,
        );
        $this->assertSame([], $garbage['notifications']);
        $this->assertSame(25, $garbage['unread_count']);
    }

    public function test_get_unread_filter_returns_only_unread_rows(): void
    {
        $user = $this->makeUser('apunread');

        $this->seedNotification($user->id, ['title' => 'u1', 'created_at' => '2026-03-01 10:00:00']);
        $this->seedNotification($user->id, ['title' => 'r1', 'created_at' => '2026-03-02 10:00:00', 'is_read' => 1]);
        $this->seedNotification($user->id, ['title' => 'u2', 'created_at' => '2026-03-03 10:00:00']);
        $this->seedNotification($user->id, ['title' => 'r2', 'created_at' => '2026-03-04 10:00:00', 'is_read' => 1]);

        $payload = json_decode(
            $this->actingAs($user)->get('/api/notifications.php?unread=1')->getContent(),
            true,
        );

        $this->assertSame(['u2', 'u1'], array_column($payload['notifications'], 'title'));
        $this->assertSame(2, $payload['unread_count']);

        // Any value other than the literal '1' disables the filter
        // ($_GET['unread'] ?? '') === '1').
        $all = json_decode(
            $this->actingAs($user)->get('/api/notifications.php?unread=yes')->getContent(),
            true,
        );
        $this->assertCount(4, $all['notifications']);
    }

    // ---------------------------------------------------------------------
    // PATCH - mark one read
    // ---------------------------------------------------------------------

    public function test_patch_marks_only_the_owners_row_and_always_succeeds(): void
    {
        $me = $this->makeUser('appatch');
        $other = $this->makeUser('appatch2');

        $mine = $this->seedNotification($me->id, ['title' => 'mine']);
        $mine2 = $this->seedNotification($me->id, ['title' => 'mine two']);
        $foreign = $this->seedNotification($other->id, ['title' => 'theirs']);

        // Owner scope: another user's id must not change anything, yet the
        // original still answers success (the guard only skips id <= 0).
        $this->actingAs($me)
            ->json('PATCH', '/api/notifications.php', ['id' => $foreign])
            ->assertContent('{"success":true,"unread_count":2}');
        $this->assertSame(0, (int) DB::table('notifications')->where('id', $foreign)->value('is_read'));

        // Real mark: owner-scoped UPDATE, fresh count.
        $this->actingAs($me)
            ->json('PATCH', '/api/notifications.php', ['id' => $mine])
            ->assertContent('{"success":true,"unread_count":1}');
        $this->assertSame(1, (int) DB::table('notifications')->where('id', $mine)->value('is_read'));

        // id <= 0 / missing body: no write, still success + real count.
        $this->actingAs($me)
            ->json('PATCH', '/api/notifications.php', ['id' => 0])
            ->assertContent('{"success":true,"unread_count":1}');
        $this->actingAs($me)
            ->json('PATCH', '/api/notifications.php')
            ->assertContent('{"success":true,"unread_count":1}');

        $this->assertSame(0, (int) DB::table('notifications')->where('id', $mine2)->value('is_read'), 'my other unread row untouched');
        $this->assertSame(0, (int) DB::table('notifications')->where('id', $foreign)->value('is_read'), 'foreign row untouched');
    }

    // ---------------------------------------------------------------------
    // POST - actions
    // ---------------------------------------------------------------------

    public function test_post_mark_all_read_reports_the_marked_count(): void
    {
        $me = $this->makeUser('apall');
        $other = $this->makeUser('apall2');

        $this->seedNotification($me->id, ['title' => 'a']);
        $this->seedNotification($me->id, ['title' => 'b']);
        $foreign = $this->seedNotification($other->id, ['title' => 'c']);

        $this->actingAs($me)
            ->json('POST', '/api/notifications.php', ['action' => 'mark_all_read'])
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json')
            ->assertContent('{"success":true,"marked":2,"unread_count":0}');

        $this->assertSame(0, DB::table('notifications')->where('user_id', $me->id)->where('is_read', 0)->count());
        $this->assertSame(0, (int) DB::table('notifications')->where('id', $foreign)->value('is_read'), 'other users untouched');
    }

    public function test_post_mark_read_and_fallthrough_400s(): void
    {
        $user = $this->makeUser('apmark');
        $id = $this->seedNotification($user->id, ['title' => 'read me']);

        $this->actingAs($user)
            ->json('POST', '/api/notifications.php', ['action' => 'mark_read', 'id' => $id])
            ->assertStatus(200)
            ->assertContent('{"success":true,"unread_count":0}');
        $this->assertSame(1, (int) DB::table('notifications')->where('id', $id)->value('is_read'));

        // Unknown action -> 400 under PHP's implicit text/html content type
        // with the raw JSON body (observed live byte for byte).
        $this->actingAs($user)
            ->json('POST', '/api/notifications.php', ['action' => 'banana'])
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertContent('{"error":"Invalid request"}');

        // No body at all: json_decode('') is null, $input['action'] ?? ''
        // stays quiet (null-coalescing) -> same 400.
        $this->actingAs($user)
            ->post('/api/notifications.php')
            ->assertStatus(400)
            ->assertContent('{"error":"Invalid request"}');
    }

    // ---------------------------------------------------------------------
    // Method fall-through
    // ---------------------------------------------------------------------

    public function test_unsupported_methods_answer_400_not_405(): void
    {
        $user = $this->makeUser('apmethod');

        // The original has no method router: anything outside GET/PATCH/POST
        // walks into http_response_code(400). Route::any + method dispatch
        // keeps Laravel from answering 405 instead.
        $this->actingAs($user)
            ->put('/api/notifications.php')
            ->assertStatus(400)
            ->assertContent('{"error":"Invalid request"}');

        $this->actingAs($user)
            ->delete('/api/notifications.php')
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertContent('{"error":"Invalid request"}');
    }

    // ---------------------------------------------------------------------
    // SSE
    // ---------------------------------------------------------------------

    public function test_sse_serves_the_reference_stream_headers(): void
    {
        $user = $this->makeUser('apsse');

        // Building the response does NOT execute the 55-second stream
        // callback - it only runs when the response is sent (the browser
        // smoke reads the real init/keep-alive bytes instead).
        $response = $this->actingAs($user)->get('/api/notifications_sse.php');

        $response->assertStatus(200);
        $this->assertSame('text/event-stream;charset=UTF-8', $response->headers->get('content-type'));
        // ACCEPTED PARITY DIFFERENCE (header-byte tier): the reference's raw
        // header() sends "no-cache, no-transform" verbatim, while Symfony's
        // ResponseHeaderBag appends ", private" to any explicit Cache-Control
        // without a public/private marker (ResponseHeaderBag.php:261).
        // Functionally identical for EventSource - both forbid caching, and
        // `private` only further restricts shared caches.
        $this->assertSame('no-cache, no-transform, private', $response->headers->get('cache-control'));
        $this->assertSame('no', $response->headers->get('x-accel-buffering'));
    }

    // ---------------------------------------------------------------------
    // CLI queue processor
    // ---------------------------------------------------------------------

    public function test_cli_command_drains_pending_rows_atomically(): void
    {
        $user = $this->makeUser('apqueue');

        $first = DB::table('notification_queue')->insertGetId([
            'user_id' => $user->id,
            'type' => 'equipment',
            'title' => 'First queued',
            'message' => 'msg one',
            'action_url' => '/equipment',
            'severity' => 'info',
            'icon' => 'bi-bell',
            'reference_id' => 7,
            'status' => 'pending',
        ]);
        $second = DB::table('notification_queue')->insertGetId([
            'user_id' => $user->id,
            'type' => 'medicine',
            'title' => 'Bad severity',
            'message' => 'msg two',
            // Not one of info|success|warning|danger -> 'info' at insert.
            'severity' => 'banana',
            'status' => 'pending',
        ]);
        $done = DB::table('notification_queue')->insertGetId([
            'user_id' => $user->id,
            'type' => 'stale',
            'title' => 'Already done',
            'message' => 'msg three',
            'severity' => 'warning',
            'status' => 'processed',
            'processed_at' => '2026-01-01 10:02:00',
        ]);

        // Port of `php api/process_notification_queue.php`: one timestamped
        // line, two pending rows moved.
        $this->artisan('notification:process-queue')
            ->expectsOutputToContain(' - Processed 2 notification(s)')
            ->assertExitCode(0);

        $this->assertSame('processed', DB::table('notification_queue')->where('id', $first)->value('status'));
        $this->assertNotNull(DB::table('notification_queue')->where('id', $first)->value('processed_at'));
        $this->assertSame('processed', DB::table('notification_queue')->where('id', $second)->value('status'));
        $this->assertNotNull(DB::table('notification_queue')->where('id', $second)->value('processed_at'));

        // Already-processed row: status AND processed_at untouched.
        $this->assertSame('processed', DB::table('notification_queue')->where('id', $done)->value('status'));
        $this->assertSame(
            '2026-01-01 10:02:00',
            DB::table('notification_queue')->where('id', $done)->value('processed_at'),
        );

        // The notifications rows carry the queue columns, mapped severity,
        // is_read 0 and a DB-clock created_at (NOW(), like the reference).
        $rows = DB::table('notifications')->orderBy('id')->get();
        $this->assertCount(2, $rows);

        $this->assertSame('First queued', $rows[0]->title);
        $this->assertSame('equipment', $rows[0]->type);
        $this->assertSame('/equipment', $rows[0]->action_url);
        $this->assertSame('bi-bell', $rows[0]->icon);
        $this->assertSame(7, (int) $rows[0]->reference_id);
        $this->assertSame('info', $rows[0]->severity);
        $this->assertSame(0, (int) $rows[0]->is_read);
        $this->assertNotNull($rows[0]->created_at);

        $this->assertSame('Bad severity', $rows[1]->title);
        $this->assertSame('info', $rows[1]->severity, 'invalid severity collapses to info');
        $this->assertSame($user->id, (int) $rows[1]->user_id);

        // Nothing pending anymore: the second run reports zero.
        $this->artisan('notification:process-queue')
            ->expectsOutputToContain(' - Processed 0 notification(s)')
            ->assertExitCode(0);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Seed one notifications row the way the app writes them (owner, unread unless told). */
    private function seedNotification(int $userId, array $overrides = []): int
    {
        return DB::table('notifications')->insertGetId(array_merge([
            'user_id' => $userId,
            'type' => 'equipment',
            'severity' => 'info',
            'icon' => 'bi-bell-fill',
            'title' => 'Notification',
            'message' => 'Body of the notification.',
            'action_url' => null,
            'is_read' => 0,
        ], $overrides));
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

        Profile::create(['user_id' => $user->id, 'first_name' => 'Api', 'last_name' => 'Tester']);

        return $user->fresh();
    }
}
