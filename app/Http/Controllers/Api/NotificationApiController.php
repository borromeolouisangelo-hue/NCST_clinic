<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NotificationQueries;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 34 - api/notifications.php (session-auth AJAX API for the bell).
 *
 * The original dispatches on REQUEST_METHOD in one flat file:
 *   GET    -> {notifications, unread_count}   (limit=min(20, default 10),
 *                                               unread=1 filters unread)
 *   PATCH  -> {success, unread_count}         (JSON body {id}; id <= 0 is a
 *                                               no-op that still answers
 *                                               success - the original's
 *                                               `if ($id > 0)` guard)
 *   POST   -> action=mark_all_read -> {success, marked, unread_count: 0}
 *             action=mark_read     -> {success, unread_count}
 *   other  -> 400 {"error":"Invalid request"} (unknown action, no body,
 *             or any method outside GET/PATCH/POST - Route::any keeps
 *             PUT/DELETE from turning into a Laravel 405)
 *
 * require_login() becomes the auth middleware (guests get the login
 * redirect). The original prints no CSRF token on this JSON surface; the
 * port keeps the web group's CSRF check (approved Phase 6 strengthening)
 * and the shared main.js sends the X-XSRF-TOKEN header with its mark-read
 * POST. Success bodies are emitted with json_encode() defaults + the
 * literal application/json content type so the bytes match the reference
 * (Laravel's response()->json would hex-escape via its default encoding
 * options); the 400 keeps PHP's implicit text/html content type.
 */
class NotificationApiController extends Controller
{
    public function handle(Request $request): Response
    {
        $uid = (int) $request->user()->id;
        $method = $request->method();

        if ($method === 'GET') {
            // Fetch recent notifications.
            $limit = min(20, (int) $request->query('limit', 10));
            $unreadOnly = $request->query('unread') === '1';

            return $this->json([
                'notifications' => NotificationQueries::recent($uid, $limit, $unreadOnly),
                'unread_count' => NotificationQueries::unreadCount($uid),
            ]);
        }

        if ($method === 'PATCH') {
            // Mark single notification as read.
            $input = json_decode($request->getContent(), true);
            $id = (int) ($input['id'] ?? 0);

            if ($id > 0) {
                NotificationQueries::markOne($id, $uid);
            }

            return $this->json([
                'success' => true,
                'unread_count' => NotificationQueries::unreadCount($uid),
            ]);
        }

        if ($method === 'POST') {
            // Mark all notifications as read / mark one read.
            $input = json_decode($request->getContent(), true);
            $action = $input['action'] ?? '';

            if ($action === 'mark_all_read') {
                return $this->json([
                    'success' => true,
                    'marked' => NotificationQueries::markAll($uid),
                    'unread_count' => 0,
                ]);
            }

            if ($action === 'mark_read') {
                $id = (int) ($input['id'] ?? 0);

                if ($id > 0) {
                    NotificationQueries::markOne($id, $uid);
                }

                return $this->json([
                    'success' => true,
                    'unread_count' => NotificationQueries::unreadCount($uid),
                ]);
            }
        }

        // http_response_code(400) + a plain body: PHP answers with its
        // default Content-Type: text/html; charset=UTF-8 (no space after
        // the semicolon is PHP's own append for text/event-stream; the
        // default type carries the spaced form - both as observed live).
        return response(json_encode(['error' => 'Invalid request']), 400, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /** header('Content-Type: application/json') + echo json_encode($data). */
    private function json(array $data): Response
    {
        return response(json_encode($data), 200, ['Content-Type' => 'application/json']);
    }
}
