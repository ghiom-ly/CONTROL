# Phase 4 — Stores Import, Assignment, Per-Store Start, Attendance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bulk-add stores from Excel, assign stores to employees from one screen, record start-of-shift orders and delivery rate per store, and derive attendance (lateness, absence, leaves, alerts, monthly report) from shifts.

**Architecture:** Store import reuses the phase-3 reader/mapping/batch pipeline with a new batch type. Per-store start values live on `report_stores` with the same file/manual provenance rule; report-level start totals become derived. Attendance is computed on demand from `daily_reports` + a new `leaves` table by one service used by pages, export, dashboard and the alerts command.

**Tech Stack:** Laravel 12, MariaDB/MySQL, PhpSpreadsheet, Blade + Alpine, PHPUnit 11, Laravel scheduler + database notifications.

**Spec:** `docs/superpowers/specs/2026-11-05-phase4-stores-assignment-attendance-design.md` (Arabic).

## Global Constraints

- New migrations only. `NULL` never becomes 0. Arabic UI, existing components, hidden-`action` pattern for multi-button forms.
- Attendance population: active users whose role is `employee` only.
- Settings use underscore keys (the settings form nests dotted keys): `attendance_late_after` (time, `10:00`), `attendance_grace_minutes` (integer, `0`), `attendance_start_date` (date, migration day); report deadline = existing `report_submission_deadline`.
- New permission `attendance.manage` (leaves) — default for admin; super admin has all.
- `config('app.timezone')` = `Africa/Tripoli` (set in `config/app.php` default and `.env.example`).
- Store import never deletes or archives; empty cells never clear existing values.
- Phase-3 change: start file writes `start_orders`/`start_rate` per store, **not** `shipments_count`.

## Review Focus

1. Store Excel with the same store twice in different alef/ta-marbuta spelling, or matching an alias → one row updated, the duplicate rejected (Task 2 `test_duplicate_spellings_in_file_rejected`).
2. Employee leaves a store row empty at shift start → store not added; all rows empty → validation error (Task 4 `test_empty_rows_skipped_and_one_required`).
3. Report rejected then resubmitted days later → clock-out stays the first submission (Task 1 `test_first_submitted_at_kept_on_resubmit`, Task 5 `test_clock_out_uses_first_submission`).
4. A leave covering today and the alerts command → no "لم يبدأ شفته" alert; command run twice → one notification (Task 7 `test_no_alert_on_leave_and_no_duplicates`).
5. Month containing future days and days before `attendance_start_date` → neither counted as absence (Task 5 `test_future_and_pre_start_days_not_absent`).

---

### Task 1: Schema, settings, permission, first submission

**Files:** Create `database/migrations/2026_11_07_000100_phase4.php`, `app/Models/Leave.php`, `app/Models/AttendanceAlert.php`; modify `ReportStore`, `DailyReport`, `User` (`leaves()`), `Permissions.php` (+`attendance.manage` in group «الموظفون», admin default), `SystemSettingsSeeder`, `UpdateSettingsRequest` (+3 rules + labels), `resources/views/settings/index.blade.php` if fields are listed explicitly, `ReportSubmissionService` (`first_submitted_at` set only when null), `config/app.php` + `.env.example` timezone; Test `tests/Feature/Phase4SchemaTest.php`.

**Produces:**
- `report_stores`: `start_orders` uint null, `start_rate` decimal(5,2) null, `start_source` string(10) null.
- `daily_reports`: `first_submitted_at` datetime null (backfill = `submitted_at`).
- `leaves`: id, user_id FK cascade, from_date date, to_date date, type enum(`leave`,`sick`,`mission`,`other`), notes text null, created_by FK nullOnDelete, timestamps; index(user_id, from_date). `Leave::TYPES` labels: إجازة، مرضية، مهمة عمل، أخرى. `Leave::covers(Carbon $day): bool`.
- `attendance_alerts`: id, user_id FK cascade, alert_date date, kind string(30), timestamps; unique(user_id, alert_date, kind).
- `import_mappings.layout` enum + `store_list`.
- Migration also attaches `attendance.manage` to the existing `admin` role.

- [ ] Tests: `test_columns_and_tables_exist`; `test_first_submitted_at_kept_on_resubmit` (submit → reject → resubmit next day: `first_submitted_at` unchanged, `submitted_at` changed); `test_attendance_settings_seeded_and_editable` (PUT /settings with the three keys as super admin saves them); `test_admin_has_attendance_manage`.
- [ ] FAIL → implement → PASS + full suite → commit `feat(phase4): schema, attendance settings, first submission time`.

---

### Task 2: Import stores from Excel

**Files:** Create `app/Services/Imports/StoreListImporter.php`, `app/Http/Controllers/StoreImportController.php`, `resources/views/stores/import.blade.php`; modify `ImportBatch::TYPES` (+`stores` => 'قائمة متاجر'), routes, `stores/index.blade.php` (button «استيراد من Excel»); Test `tests/Feature/StoreImportTest.php`.

**Consumes:** `SpreadsheetReader`, `HeaderSignature`, `StoreMatcher`, `ImportMapping`.
**Produces:**
- Routes (`stores.manage`): `GET /stores/import` `stores.import`, `POST /stores/import` `stores.import.upload` (`file`), `GET /stores/import/{batch}` `stores.import.show`, `POST /stores/import/{batch}/mapping` `stores.import.mapping` (`columns[name|phone|owner]`), `POST /stores/import/{batch}/apply` `stores.import.apply`.
- `StoreListImporter::upload(UploadedFile, User): ImportBatch` (`report_type=stores`, rows stored, saved mapping by signature with `layout=store_list`).
- `StoreListImporter::preview(ImportBatch): ?array` → `['rows' => list<['row','name','phone','owner','action'=>'new'|'update'|'same'|'rejected','store_id'=>?int,'reason'=>?string,'archived'=>bool]>, 'counts' => ['new','update','same','rejected']]`. Rules from spec §6.1: match via `StoreMatcher::match` **including archived stores** (add `StoreMatcher::matchAny(string): ?int` that does not exclude archived); phone normalized with `ArabicNormalizer::digits` and validated `^[0-9+\-\s]{6,30}$`; duplicate normalized name in file → `rejected` «مكرر في الملف»; empty name → «اسم المتجر فارغ»; `same` when no non-empty cell differs.
- `StoreListImporter::apply(ImportBatch, User): array{created:int, updated:int}` — one transaction; new stores `status=active`, unassigned, `created_by`; updates only non-empty cells; batch `approved`, rows accepted/rejected; mapping saved.

- [ ] Tests: `test_preview_classifies_rows`; `test_apply_creates_and_updates_without_clearing`; `test_duplicate_spellings_in_file_rejected` (`متجر الأمل` + `متجر الامل`); `test_alias_match_updates_existing_store`; `test_archived_store_updated_stays_archived`; `test_bad_phone_rejected`; `test_employee_forbidden`; `test_saved_mapping_reused`.
- [ ] FAIL → implement → PASS → commit `feat(stores): import stores from Excel`.

---

### Task 3: Assignment screen

**Files:** Create `app/Http/Controllers/StoreAssignmentController.php`, `resources/views/stores/assignment.blade.php`; routes; sidebar/menu link from stores index («تعيين المتاجر»); Test `tests/Feature/StoreAssignmentTest.php`.

**Produces:** `GET /stores/assignment` `stores.assignment` (filters `q`), `POST /stores/assignment` `stores.assignment.update` (`store_ids[]`, `user_id` int|`none`). Employees = active users with role `employee`; archived stores excluded; target user must be such an employee. Updates via `StoreService::update` (audit logged).

- [ ] Tests: `test_lists_employees_with_store_counts_and_unassigned`; `test_bulk_assign_and_unassign`; `test_cannot_assign_to_admin_or_inactive`; `test_archived_not_listed`; `test_employee_forbidden`.
- [ ] FAIL → implement → PASS → commit `feat(stores): assignment screen`.

---

### Task 4: Per-store start of shift

**Files:** modify `ShiftService::start`, `StartShiftRequest`, `reports/start.blade.php`, `DailyReportController::create/start/edit/formState`, `ReportDraftService` (carry + edit start values, recompute totals), `SaveReportRequest` (`stores.*.start_orders`, `stores.*.start_rate`), `reports/edit.blade.php` section ⓪ (table bound to Alpine `stores`), `resources/js/report-form.js`, `ShiftFileImporter::applyStart` (write `start_*` not `shipments_count`; stale stores clear file-sourced `start_*`), `DailyReport::fileDiscrepancies` (per store: start orders vs `file_orders_count`, start rate vs file rate), `ReportWhatsappFormatter` (start line per store: «- اسم: X طلبية — Y%» under «بداية الشفت»), `reports/show.blade.php`; update phase-3 tests that asserted `shipments_count` from file; Test `tests/Feature/PerStoreStartTest.php`.

**Produces:**
- `ShiftService::start(User $user, array $stores): DailyReport` — `$stores` = list of `['store_id'=>int,'start_orders'=>?int,'start_rate'=>?float]` (rows with both null dropped; ≥1 required else ValidationException `stores`); creates `report_stores` with `start_source='manual'`; sets report totals via `StartTotals::compute`.
- `App\Support\StartTotals::compute(iterable $rows): array{total: ?int, rate: ?float}` — total = sum of non-null orders; rate = Σ(orders×rate)/Σorders over rows having both, 2 decimals; null when none.
- Start page: table of the employee's assigned non-archived stores (orders, rate) + «إضافة متجر آخر»; file-upload alternative unchanged.
- Draft save: posted `start_orders/start_rate` per store; `start_source` stays `file` only if both equal stored file-sourced values, else `manual` (null when both empty); when any store has a start value → `start_total_orders/start_delivery_rate` recomputed (sources mirror: `file` only if all contributing stores are file-sourced).
- `ShiftFileImporter::start` path (no report): calls `ShiftService::start` with the file's per-store values and marks them `file`.

- [ ] Tests: `test_start_page_lists_assigned_stores`; `test_start_with_per_store_values_computes_weighted_rate` (A 100@80, B 50@50 → total 150, rate 70.00); `test_empty_rows_skipped_and_one_required`; `test_edit_start_values_recomputes`; `test_file_fills_start_values_not_worked_count`; `test_manual_start_value_not_overwritten_by_file`; `test_old_report_without_store_values_keeps_totals`; `test_discrepancy_per_store_rate`.
- [ ] FAIL → implement → PASS + full suite (fix phase-3 expectations) → commit `feat(reports): per-store start orders and delivery rate`.

---

### Task 5: Attendance service and leaves

**Files:** Create `app/Services/AttendanceService.php`, `app/Http/Controllers/LeaveController.php`, `app/Http/Requests/LeaveRequest.php`; routes; Test `tests/Feature/AttendanceServiceTest.php`, `tests/Feature/LeaveTest.php`.

**Produces:**
- `AttendanceService::employees(): Collection<User>` (active, role employee).
- `AttendanceService::month(User $u, CarbonImmutable $month): array{days: list<Day>, summary: Summary}` where Day = `['date'=>Y-m-d, 'status'=>'present'|'late'|'absent'|'leave'|'incomplete'|'future'|'not_counted', 'in'=>?H:i, 'out'=>?H:i, 'minutes'=>?int, 'late_minutes'=>?int, 'leave_type'=>?string, 'legacy'=>bool]`; Summary = `['present','absent','leave','late','late_minutes','incomplete','avg_minutes'=>?int,'reports_total','reports_approved','reports_rejected','reports_pending']`.
  Rules (spec §6.4): manual non-legacy reports by `work_date`; in = min `shift_started_at`; out = max `first_submitted_at`; minutes = Σ(first_submitted_at − shift_started_at) of submitted shifts; late when in > `attendance_late_after` + grace; `incomplete` when any shift of the day has no `first_submitted_at` and the day is past (today → `present`/`late`); legacy report day → `present` with `legacy=true`, no times; leave day without shift → `leave`; days > today → `future`; days < max(`attendance_start_date`, hired_at or created_at) → `not_counted`; otherwise `absent`. `present` counts include `late` and `incomplete`.
- `AttendanceService::summaryForAll(CarbonImmutable $month): Collection<array{user: User, summary: Summary}>`.
- `AttendanceService::today(User): array{started: bool, submitted_all: bool, on_leave: bool}` (used by alerts).
- Leaves routes (`attendance.manage`): `POST /attendance/leaves` `attendance.leaves.store` (user_id employee, from ≤ to, type), `DELETE /attendance/leaves/{leave}` `attendance.leaves.destroy`.

- [ ] Tests: `test_present_late_and_minutes` (start 10:20 with late_after 10:00 → late 20 min; submit 16:20 → 360 min); `test_grace_minutes`; `test_absent_and_leave`; `test_future_and_pre_start_days_not_absent`; `test_incomplete_shift`; `test_clock_out_uses_first_submission`; `test_legacy_day_counts_present_without_times`; `test_two_shifts_same_day`; `test_admins_not_in_population`; `test_report_counts_in_summary`; Leave: `test_admin_records_and_deletes_leave`, `test_leave_validation`, `test_employee_forbidden`.
- [ ] FAIL → implement → PASS → commit `feat(attendance): attendance calculation and leaves`.

---

### Task 6: Attendance pages, monthly report, print, Excel, employee dashboard

**Files:** Create `app/Http/Controllers/AttendanceController.php` (`index`, `show`, `print`, `export`), `resources/views/attendance/index.blade.php`, `attendance/show.blade.php`, `attendance/print.blade.php` (standalone printable layout, company name from settings), `app/Exports/AttendanceExport.php` (PhpSpreadsheet writer, RTL sheet); modify `DashboardService`/`dashboard.blade.php` (employee: «حضوري هذا الشهر» present/absent/late), sidebar («الحضور والانصراف», `users.view`); Test `tests/Feature/AttendancePagesTest.php`.

**Produces:** `GET /attendance?month=YYYY-MM` `attendance.index` (summary table all employees + leave form), `GET /attendance/{user}?month=` `attendance.show` (days table + leaves list), `GET /attendance/{user}/print?month=` `attendance.print`, `GET /attendance/export?month=&user_id?=` `attendance.export` (xlsx: sheet «ملخص» + one sheet per employee when `user_id` absent; filename `حضور-YYYY-MM.xlsx`). All `users.view`; `{user}` must be in population (404 otherwise).

- [ ] Tests: `test_index_lists_employees_with_summary`; `test_show_days_table`; `test_print_page_renders`; `test_export_downloads_xlsx_with_summary_sheet` (read back with PhpSpreadsheet: sheet names, a known cell); `test_employee_dashboard_shows_own_attendance`; `test_employee_cannot_open_attendance` (403); `test_invalid_month_defaults_to_current`.
- [ ] FAIL → implement → PASS → commit `feat(attendance): pages, monthly report, print and Excel export`.

---

### Task 7: Attendance alerts

**Files:** Create `app/Console/Commands/AttendanceAlertsCommand.php` (`nours:attendance-alerts`), `app/Notifications/AttendanceAlertNotification.php`; modify `routes/console.php` (every 15 minutes); Test `tests/Feature/AttendanceAlertsTest.php`.

**Produces:** command at time T (today): if T ≥ late_after+grace → for each employee with no shift today and not on leave → kind `not_started`; if T ≥ `report_submission_deadline` → for each employee with a started shift today lacking `first_submitted_at` → kind `not_submitted`. Each (user, date, kind) recorded in `attendance_alerts`; skip if exists. Notify every active user with `reports.review` (database channel) with data `{title, message, url=attendance.show}`. Before `attendance_start_date` → nothing.

- [ ] Tests (use `$this->travelTo`): `test_not_started_alert_after_late_time`; `test_no_alert_before_late_time`; `test_not_submitted_alert_after_deadline`; `test_no_alert_on_leave_and_no_duplicates`; `test_admins_not_alerted_about_themselves`.
- [ ] FAIL → implement → PASS → commit `feat(attendance): scheduled alerts for managers`.

---

### Task 8: Navigation, docs, verification

- [ ] Sidebar: «تعيين المتاجر» (stores.manage) and «الحضور والانصراف» (users.view); `docs/ARCHITECTURE.md` (§1 services, §3 tables, §4 phase 4 ✅ with test count, roles table: attendance.manage), `README.md` status + note on `php artisan schedule:work`/cron for alerts.
- [ ] Test `tests/Feature/Phase4NavTest.php`: `test_admin_sees_new_links`, `test_employee_sees_none_of_them`.
- [ ] Full suite green; `npm run build`; manual smoke on the dev server: import a stores xlsx, assign, start shift per store as demo.ahmed, submit, open attendance month, export.
- [ ] Commit `feat(phase4): navigation and docs`.
