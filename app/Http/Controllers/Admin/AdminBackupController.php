<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Admin - Database backup (admin/backup.php).
 *
 * GET renders the checkbox form + the Recent Backups list; POST runs the
 * whitelisted export and re-renders DIRECTLY (the reference has no PRG here:
 * $success and the flash land on the same response, and the reference's
 * flash slot has no renderer anywhere so only the inline $success alert is
 * visible - same as this port's write-only flash partial).
 *
 * Backup directory: the reference computes dirname(__DIR__) . '/backups'
 * from the admin/ script directory, i.e. the project root one level above
 * the script folder. base_path('backups') is the structural mirror here
 * (project-root/backups, outside the public docroot in both apps); the
 * physical path string differs by project folder name and is masked by the
 * full_diff harness like the BASE URL prefix.
 *
 * CSRF: the reference form carries no token and the page runs no check
 * (validate_csrf_token() exists in the reference but is never called); the
 * view carries @csrf and the global middleware answers 419 on token-less
 * POSTs instead (approved Phase 6 strengthening).
 *
 * Table selection passes through array_intersect() with the reference's
 * whitelist in the reference's order - same as $_POST['tables'] ?? [] in the
 * original, including the TypeError a scalar tables= value raises in PHP 8.
 *
 * Phase 5 (Group B): the whitelist (BackupService::ALLOWED_TABLES) and the
 * whole export pipeline (intersect, three-branch validation, file dump,
 * audit) moved into App\Services\BackupService, injected below - this method
 * keeps the request read, the backup-directory resolution, the flash /
 * $success view state and rendering.
 */
class AdminBackupController extends Controller
{
    public function __construct(protected BackupService $backupService) {}

    public function index(Request $request): View
    {
        $errors = [];
        $success = false;

        // The table whitelist moved into App\Services\BackupService
        // (Phase 5, Group B): the POST validation intersects against it
        // there and the view reads BackupService::ALLOWED_TABLES below.

        // Backup directory (original lines 24-28): project root + /backups,
        // created on first request if missing - @mkdir like the reference.
        $backupDir = base_path('backups');
        if (! is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        if ($request->isMethod('post')) {
            $selected = $request->input('tables') ?? [];

            // Whitelist intersect, the three-branch validation chain, the
            // file dump and the audit row moved into
            // App\Services\BackupService (Phase 5, Group B).
            $result = $this->backupService->backup($selected, $backupDir, (int) $request->user()->id);

            if ($result['success']) {
                session()->flash('success', "Backup created: {$result['filename']}");
                $success = true;
            } else {
                $errors = $result['errors'];
            }
        }

        return view('admin.backup', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/backup'),
            'title' => 'Backup',
            'backupErrors' => $errors,
            'success' => $success,
            'backupDir' => $backupDir,
            'allowedTables' => BackupService::ALLOWED_TABLES,
        ]);
    }
}
