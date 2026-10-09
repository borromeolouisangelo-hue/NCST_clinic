# Migration Inventory — Final (after Phase 34)

**Source (read-only):** `C:\xampp\htdocs\school-clinic`
**Target:** `C:\xampp\htdocs\school-clinic-laravel` (Laravel 12.69.3)
**Scope:** one authoritative recursive inventory of every remaining `.php` file.
**Total:** **62 files** — MIGRATE 56 (all ported) · INFRA/NON-TARGET 3 · DUPLICATE/LEGACY 3 · EXTERNAL/UNUSED 0 · **remaining genuine targets: 0**

---

## 1. MIGRATE — genuine application targets (56, ALL PORTED)

### Pages — admin/ (10)
| Reference file | Port | Evidence |
|---|---|---|
| admin/dashboard.php | AdminDashboardController | full_diff IDENTICAL / accepted audit-rotation only |
| admin/medicines.php | AdminMedicinesController | full_diff IDENTICAL (list, search, edit, low-stock) |
| admin/equipment.php | AdminEquipmentController | full_diff IDENTICAL (list, search, edit, filter) |
| admin/audit_logs.php | AdminAuditLogsController | full_diff; 4 accepted live-churn captures |
| admin/reports.php | AdminReportsController | full_diff; accepted tie-order flips only |
| admin/users.php | AdminUsersController (Phase 23) | full_diff + feature tests |
| admin/settings.php | AdminSettingsController (Phase 24) | full_diff + feature tests |
| admin/categories.php | AdminCategoriesController (Phase 25) | full_diff + feature tests |
| admin/suppliers.php | AdminSuppliersController (Phase 26) | full_diff + feature tests |
| admin/backup.php | AdminBackupController (Phase 28) | route `admin.backup`, browser QA (write+remove) |

### Pages — staff/ (16)
dashboard, reports, patients, patient_register, consultations, certificates, dispense,
medicine_requests (stub), inventory, stock_adjust (stub), equipment_loans, sick_leaves,
deduction_logs, history, history_logs (stub), settings — all routed in `routes/web.php`
(Phases 11–22), full_diff IDENTICAL, feature-tested.
  Post-migration: `deduction_logs` was removed end to end (controller, view, route,
  nav entries, tests) — Transaction History covers those records.

### Pages — user/ (11)
dashboard, medical_form, my_consultations, my_certificates, my_requests, profile,
request_medicine (stub), borrow_equipment (stub), my_loans (stub), sick_leave (stub,
Phase 10 route + UserSickLeaveRedirectTest), settings — all routed, full_diff IDENTICAL.

### Standalone pages (2)
| Reference | Port | Evidence |
|---|---|---|
| index.php (root landing: logged-in → role dashboard, guest → login) | landing route in `routes/web.php` | full_diff GUEST landing |
| notifications.php | NotificationController (Phase 31) | full_diff + feature tests |
| notifications_preferences.php | NotificationPreferencesController (Phase 32) | full_diff + feature tests |

### API endpoints (3) — Phase 34
| Reference | Port | Evidence |
|---|---|---|
| api/notifications.php | Api\NotificationApiController + `App\Support\NotificationQueries` | byte-exact JSON (Feature + browser 24/24) |
| api/notifications_sse.php | Api\NotificationSseController | init/keep-alive bytes match live reference capture; EventSource verified |
| api/process_notification_queue.php | Api\ProcessNotificationQueueController (web 403) + artisan `notification:process-queue` (CLI) | Feature: queue drain, severity mapping; browser: 403/419 |

### Auth (3)
auth/login.php → Auth\LoginController · auth/logout.php → GET logout + `/auth/logout` dual route ·
auth/register.php → Auth\RegisterController (Phase 33, isolated-SQLite CREATE path).

### Shared includes — view layer (7) → `resources/views/layouts/dashboard.blade.php` + partials
header.php · topbar.php (incl. notifications partial) · sidebar.php · footer.php · head.php ·
layout_dashboard_start.php · layout_dashboard_end.php — Phase 4/5 shell, byte-verified since.

### Shared includes — libraries (3)
| Reference | Port |
|---|---|
| includes/NotificationModel.php | ported piecemeal: processQueue/getSince/getUnreadCount/getLatestTimestamp → `App\Support\NotificationQueries` (Phase 34); get/setPreference → Phase 32; overdue sweep → Phase 13 tests |
| includes/auth.php | ported: log_action → AuditLogger, record/clear_failed_logins → Phase 20, password_verify → Hash::check, session regeneration → Laravel session |
| includes/functions.php | helper library: page-affecting helpers (fmt, url, audit, sync_account_type, redirect semantics, validation messages) ported as consumed — every consumer page is full_diff/feature green |

---

## 2. INFRA/NON-TARGET (3) — not application features

| File | Why |
|---|---|
| config/db.php | PDO bootstrap/connection include — infrastructure (explicitly excluded by final boundary) |
| setup.php | one-time installer script — not a running-system feature |
| test_dash.php | diagnostic harness — not a user-facing feature |

---

## 3. DUPLICATE/LEGACY (3) — dead or vestigial code

| File | Why |
|---|---|
| public/index.php | vestigial copy of the root landing router with the guest redirect **commented out** (renders blank); unreachable from any link; root `index.php` (the real entry) is ported |
| includes/NotificationRouter.php | never instantiated anywhere (grep: no `new NotificationRouter` outside its own file); even if it were, its listener events (`student_registered`, `employee_registered`, `user_login`) are never dispatched — no caller uses those names |
| includes/EventDispatcher.php | `dispatch()` only iterates registered listeners; `addListener()` is called solely inside the never-instantiated NotificationRouter, and `register_notification_listeners()` (called from config/db.php:63) has an **empty body** → `fire_event()` is a provably observable no-op → prior decision "fire_event NOT ported" is behavior-preserving |

---

## 4. EXTERNAL/UNUSED (0)

None.

---

## 5. END-CONDITION CHECKLIST

| Condition | Status |
|---|---|
| Every genuine application endpoint/page has a Laravel equivalent | ✅ 56/56 MIGRATE files ported; 0 remaining targets |
| All roles/routes covered (admin/staff/student/employee) | ✅ `route:list` complete; full_diff captures per role green |
| Legacy DB schema unchanged | ✅ 25 tables / 231 columns / 37 FKs, no `migrations` table; row counts unchanged (DB safety check ALL OK; only audit_logs grew from QA logins) |
| Security gates pass | ✅ CSRF (419 on token-less state-changing requests incl. api/*), auth + `role` middleware, validation, prepared/Eloquent queries, hashed passwords, audit logs; no `.env`/dump exposure |
| Final browser smoke passes | ✅ Phase 34 browser: 24/24 (byte-exact API responses, SSE bytes, EventSource, bell dropdown, guest redirects, 419/400/403 classes) |
| Final parity/integrity checks pass | ✅ full_diff 120 pages: 113 IDENTICAL + 7 accepted (admin dashboard rotation, audit_logs ×4 live churn, reports ×2 equal-count tie-order flip — proven 2-line-only diffs, no tiebreaker on either side); offline byte-verify 111 checked, 2 mismatches = those accepted flips; full suite **358 passed / 3039 assertions** |
| Remaining legacy files documented as non-target infrastructure | ✅ this document |
| Reference tree untouched | ✅ read-only throughout (HEAD `c73a36b`, dirty paths pre-existing and unchanged) |

**Migration status: COMPLETE.** No further phases will be created.

### Accepted parity differences (final ledger)
1. Token-less non-GET → 419 where the reference (no CSRF) answered 200/400/403 — approved Phase 6 strengthening.
2. SSE `Cache-Control` = `no-cache, no-transform, private` — Symfony's header bag appends `, private` (ResponseHeaderBag:261); functionally identical for EventSource.
3. Data-churn captures: admin dashboard recent-activity rotation + audit_logs pages (live table grows during QA).
4. Reports weekly/yearly: swap of two count-1 rows (MySQL nondeterministic tie order; neither SQL has a tiebreaker; byte-identical in Phases 30–33).
