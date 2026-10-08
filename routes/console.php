<?php

use App\Services\NotificationQueries;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Phase 34 - port of api/process_notification_queue.php. The reference
| script refuses web SAPIs (403 - see ProcessNotificationQueueController)
| and, from the CLI, drains up to 100 pending notification_queue rows into
| notifications and prints one timestamped line:
|   {Y-m-d H:i:s} - Processed {n} notification(s)
| Run: php artisan notification:process-queue
*/
Artisan::command('notification:process-queue', function () {
    $processed = NotificationQueries::processQueue(100);

    $this->info(date('Y-m-d H:i:s')." - Processed {$processed} notification(s)");
})->purpose('Drain pending notification_queue rows into notifications (api/process_notification_queue.php)');
