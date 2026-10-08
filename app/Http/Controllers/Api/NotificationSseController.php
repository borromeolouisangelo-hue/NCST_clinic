<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationQueries;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 34 - api/notifications_sse.php (Server-Sent Events stream).
 *
 * Replaces the 30-second polling with a persistent connection. The client
 * (EventSource) reconnects automatically with Last-Event-ID, so a
 * reconnecting stream replays only notifications created after that id;
 * every connection then gets the init event with the current unread count,
 * a keep-alive comment each 3-second tick (the source comment says 15
 * seconds - legacy, the loop really answers every 3s; preserved), and an
 * unnamed `ping` event when the 55-second time limit is reached before the
 * connection closes for the client to reconnect.
 *
 * Header parity as observed live: PHP itself appends the charset to a
 * text/* Content-Type, producing exactly "text/event-stream;charset=UTF-8"
 * (no space) - the port spells the charset out so the bytes land the same.
 * ob_flush()/flush() mirror the original's output calls; ob_flush() is
 * guarded by an active-buffer check because Laravel converts the raw
 * "no buffer to flush" notice into an exception mid-stream where the
 * reference PHP would only log it (the guard is byte-invisible: PHP's
 * output_buffering=4096 keeps a buffer active in this environment).
 *
 * The stream body is only executed when the response is sent (EventSource
 * / fetch), never while a test merely builds the response - so Feature
 * tests assert headers without waiting out the 55-second loop.
 *
 * Feature 2 (dedup): the Last-Event-ID replay and the polling loop used to
 * query independently, so the loop's first tick re-sent the whole replayed
 * window and the bell stacked a duplicate per reconnect. Both now share one
 * cursor and one $emitted id set: a row leaves this connection exactly once,
 * while the cursor deliberately sits a second behind the newest delivered
 * row so a notification sharing its timestamp is not stranded (the id set
 * filters the overlap back out). The stream itself stays scoped to the
 * authenticated user via NotificationQueries' user_id predicates.
 */
class NotificationSseController extends Controller
{
    public function handle(Request $request): Response
    {
        $uid = (int) $request->user()->id;

        return response()->stream(function () use ($request, $uid) {
            // Flush helper: reference does ob_flush(); flush(); unguarded.
            $flush = function (): void {
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            // Time limit for this request (PHP max_execution_time safety).
            $maxRuntime = 55; // seconds
            $startTime = time();

            // Get the last event ID from the client.
            $lastEventId = $request->header('Last-Event-ID');

            // Feature 2 (dedup): one cursor for the whole connection. The
            // reconnect replay, the first-connect seed and the polling loop
            // all share it, so rows already delivered are never delivered a
            // second time - previously the loop restarted from the same
            // lastEventId as the replay and re-emitted the whole window,
            // which is what stacked duplicate entries in the bell on every
            // reconnect. $emitted is the belt to those suspenders: it also
            // swallows rows sharing a timestamp with rows already sent (the
            // cursor has to sit a second back so a same-second sibling is
            // never dropped). It starts at the epoch only as a placeholder;
            // both branches below overwrite it before the loop reads it.
            $since = '1970-01-01 00:00:00';
            $emitted = [];
            $latestEmitted = 0;

            $emit = function (array $n) use (&$emitted, &$latestEmitted): bool {
                $nid = (int) $n['id'];
                if (isset($emitted[$nid])) {
                    return false;
                }
                $emitted[$nid] = true;

                $at = strtotime($n['created_at']);
                if ($at > $latestEmitted) {
                    $latestEmitted = $at;
                }

                echo 'id: '.$at."\n";
                echo 'data: '.json_encode([
                    'id' => $nid,
                    'type' => $n['type'],
                    'title' => $n['title'],
                    'message' => $n['message'],
                    'severity' => $n['severity'],
                    'icon' => $n['icon'],
                    'is_read' => (bool) $n['is_read'],
                    'created_at' => $n['created_at'],
                    'action_url' => $n['action_url'],
                ])."\n\n";

                return true;
            };

            if ($lastEventId) {
                $since = date('Y-m-d H:i:s', (int) $lastEventId);

                // Client reconnecting - send only notifications since last check.
                $replayed = NotificationQueries::since($uid, $since);
                $anyReplayed = false;

                foreach ($replayed as $n) {
                    $anyReplayed = $emit($n) || $anyReplayed;
                }

                if ($anyReplayed) {
                    // The catch-up window is spent; sit one second behind the
                    // newest row so nothing sharing its second is lost.
                    $since = date('Y-m-d H:i:s', $latestEmitted - 1);
                }

                $flush();
            } else {
                // First connect for this document: hand the client this user's
                // newest rows (reversed, so the list's prepend leaves the
                // newest on top) and park the cursor behind the newest of them.
                //
                // The reference seeded the window with PHP's `time() - 5`, but
                // notifications.created_at is written by MySQL's NOW() and
                // this deployment runs PHP six hours behind the database
                // (php.ini date.timezone=Europe/Berlin against MySQL's SYSTEM
                // clock) - every stored row looked "less than five seconds
                // old", so opening the bell replayed the user's whole history
                // each time. Seeding from the row column keeps the cursor on
                // the same clock as the data it compares against.
                $seed = NotificationQueries::recent($uid, 10, false);
                foreach (array_reverse($seed) as $n) {
                    $emit($n);
                }

                // recent() is newest-first, so row 0 is the cursor - no extra
                // query, and no PHP clock anywhere near created_at.
                $newest = $seed[0]['created_at'] ?? null;
                $since = $newest
                    ? date('Y-m-d H:i:s', strtotime($newest) - 1)
                    : '1970-01-01 00:00:00';

                $flush();
            }

            // Send initial unread count. This control event carries no `id:`
            // on purpose - the SSE resume cursor must stay on the row clock
            // (a Last-Event-ID minted from PHP's clock would compare six
            // hours behind created_at and replay everything on reconnect).
            // Without an id the browser resumes from the last real
            // notification, or falls through to the seed branch above.
            $unreadCount = NotificationQueries::unreadCount($uid);
            echo 'data: '.json_encode(['type' => 'init', 'unread_count' => $unreadCount])."\n\n";
            $flush();

            // Keep the connection alive, checking for new notifications.
            while (true) {
                // Check if we've exceeded max runtime.
                if (time() - $startTime >= $maxRuntime) {
                    echo "event: ping\n";
                    echo 'data: '.json_encode(['type' => 'ping', 'time' => time()])."\n\n";
                    $flush();
                    break; // Client will reconnect automatically
                }

                // Check for new notifications against the shared cursor.
                $newNotifs = NotificationQueries::since($uid, $since);

                if ($newNotifs) {
                    $batchMax = 0;
                    $fresh = 0;

                    foreach ($newNotifs as $n) {
                        $at = strtotime($n['created_at']);
                        if ($at > $batchMax) {
                            $batchMax = $at;
                        }
                        if ($emit($n)) {
                            $fresh++;
                        }
                    }

                    if ($batchMax > 0) {
                        // Anything genuinely new keeps the cursor one second
                        // back so a sibling sharing its timestamp is picked up
                        // on the next tick; a window of rows already delivered
                        // is stepped over outright so a replayed batch can
                        // never park the cursor and strand later rows.
                        $since = date('Y-m-d H:i:s', $fresh ? $batchMax - 1 : $batchMax);
                    }

                    $flush();
                }

                // Send a keep-alive comment every 15 seconds (reference
                // comment; the loop actually runs every 3 seconds).
                echo ": keep-alive\n\n";
                $flush();

                sleep(3); // Check every 3 seconds
            }
        }, 200, [
            'Content-Type' => 'text/event-stream;charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }
}
