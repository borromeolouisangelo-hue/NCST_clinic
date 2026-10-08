<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin - System settings (admin/settings.php).
 *
 * GET renders the two cards from system_settings; POST blindly upserts the
 * 8 whitelisted keys (trimmed, NO validation - the reference's $errors array
 * is declared but never populated), logs update_settings and PRG with
 * ->with('success', ...). set_flash() is the never-rendered write, as always.
 *
 * The reference page performs NO CSRF check anywhere (the form carries no
 * token field and no validate_csrf_token call exists in the file) - the
 * global middleware answers 419 on token-less POSTs instead (approved Phase
 * 6 strengthening; the view carries @csrf so the form itself saves exactly
 * as in the reference). Browser QA never posts a valid token here: that would
 * write live system_settings, so the save paths are Feature-test only
 * (isolated SQLite).
 *
 * The reference's $val closure applies e() before the raw echo; the view
 * emits {{ $settings[...] ?? ... }} instead, which runs the same e() once -
 * byte-identical output without double-escaping.
 *
 * Phase 5 (Group B): the 8-key whitelist (AdminSettingsService::ALLOWED),
 * the blind upsert loop and the audit row moved into
 * App\Services\AdminSettingsService, injected below - this method keeps the
 * body-bag reads, the settings load and rendering.
 */
class AdminSettingsController extends Controller
{
    public function __construct(protected AdminSettingsService $adminSettingsService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        // ---- Handle POST (original lines 17-32): body-bag reads stay here
        // (mirroring the reference's $_POST via $request->request); the
        // whitelist, blind upsert loop and audit moved into
        // App\Services\AdminSettingsService (Phase 5, Group B). ----
        if ($request->isMethod('post')) {
            $values = [];
            foreach (AdminSettingsService::ALLOWED as $key) {
                // $request->request mirrors the reference's $_POST exactly:
                // body-bag only (a query-string key must NOT qualify, and
                // array_key_exists semantics keep empty/null values in play).
                if (! $request->request->has($key)) {
                    continue;
                }
                $values[$key] = trim((string) $request->request->get($key));
            }

            $this->adminSettingsService->save($values, (int) $request->user()->id);

            return redirect()->route('admin.settings')->with('success', 'Settings saved.');
        }

        // ---- Load settings (original lines 34-37) ----
        $settings = [];
        foreach (DB::select('SELECT setting_key, setting_value FROM system_settings') as $row) {
            $settings[$row->setting_key] = $row->setting_value;
        }

        return view('admin.settings', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/settings'),
            'title' => 'System Settings',
            'settingsErrors' => $errors,
            'settings' => $settings,
        ]);
    }
}
