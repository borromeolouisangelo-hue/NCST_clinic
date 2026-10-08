<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 34 - api/process_notification_queue.php.
 *
 * The reference script starts with a php_sapi_name() !== 'cli' check and
 * dies 403 "Forbidden" for every web request before touching the queue;
 * from the CLI it drains up to 100 pending notification_queue rows and
 * prints one timestamped line. The port keeps both halves:
 *   - web: this controller answers 403 with the literal body (any method,
 *     no login - the SAPI check runs before require-style bootstrap work);
 *   - CLI: the artisan command notification:process-queue in
 *     routes/console.php, the structural port of running
 *     `php api/process_notification_queue.php`.
 */
class ProcessNotificationQueueController extends Controller
{
    public function handle(): Response
    {
        return response('Forbidden', 403, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
