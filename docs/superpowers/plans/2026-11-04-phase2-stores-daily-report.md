# Phase 2 — Stores, Shift Daily Report, Review, Open Codes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the company's WhatsApp daily-report template into the system: stores management, a per-shift report form, manager review/approval that makes data official, and a combined open-codes screen.

**Architecture:** Same layering as phase 1 — thin Controllers → FormRequests → Policies → Services that run inside `DB::transaction`. Draft codes live only in `shipment_followups` (as text + store_id); `shipments` rows, official flags and `store_daily_metrics` are produced only by `ReportApprovalService`, which is idempotent so re-approval after an admin edit is safe.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL/MariaDB, Blade + Tailwind v4 + Alpine.js (RTL), PHPUnit 11 against MySQL `nours_testing`.

**Spec:** `docs/superpowers/specs/2026-11-04-phase2-stores-daily-report-design.md` (Arabic). Read it before each task; section numbers below (§6.3 etc.) refer to it.

## Global Constraints

- Schema changes only in a **new** migration; never edit phase-1 migration files.
- `NULL` means "not entered" — never coerce to 0. UI shows «غير متوفر».
- Nothing from a non-`approved` report may reach `shipments`, `is_official`, `store_daily_metrics`, dashboard or employee stats.
- Every state change goes through a Service inside `DB::transaction`; models use the existing `Auditable` trait.
- Access via existing permission names only (`App\Support\Permissions`); super admin passes via `Gate::before`. Accessing another user's report without permission → 403.
- All UI text Arabic, RTL, using existing components (`x-layouts.app`, `x-page-header`, `x-badge`, `x-flash`, `x-modal`, `x-empty-state`, `x-pagination`).
- Uploaded screenshots: images only (`jpg,jpeg,png,webp`), max `config('nours.uploads.max_size_kb')`, stored on the private disk under `nours/attachments/`, served only through an authorized route.
- Max attempts per shift = setting `reports.max_attempts_per_shift` (integer, default `2`).
- Respect existing setting `employee_can_edit_rejected` (if false, rejected reports are editable only by reviewers).
- Phase-1 suite (39 tests) must stay green.

## Review Focus

1. Pasted codes with Arabic digits, Arabic comma «،», mixed case, blank lines, or the same code twice → normalized, de-duplicated, one followup per code (Task 4 `test_paste_parser_normalizes_and_dedupes`).
2. Same store in two approved reports on one day (two shifts / covering colleague) → `store_daily_metrics.shipments_count` is the **sum**; all-NULL stays NULL (Task 7 `test_metrics_sum_across_reports_and_keep_null`).
3. Admin edits an approved report and re-approves → no duplicate issues, removed code's orphan shipment soft-deleted, metrics for old and new keys correct (Task 7 `test_reapproval_is_idempotent`).
4. Employee double-clicks «بدء الشفت» or opens two tabs → never two `draft` reports for the same user (Task 4 `test_cannot_start_second_open_shift`).
5. Code already officially belonging to store A typed under store B → rejected with a message; same code only in another person's *pending* report → warning, and the second approval is blocked until fixed (Task 4 `test_code_of_other_store_rejected`, Task 7 `test_approval_blocked_on_pending_code_conflict`).

---

## Prerequisite — Task 0: Development environment

The machine has PHP 8.5 and Node 22 but **no Composer, no MySQL, no `vendor/`**. Nothing below can be verified until this is done.

- [ ] Install Composer 2 and MySQL 8 / MariaDB 10.6+ (XAMPP is acceptable). Create DBs `nours_monitoring` and `nours_testing` (utf8mb4).
- [ ] `composer install` → creates `composer.lock`; commit it.
- [ ] `npm install && npm run build`; `cp .env.example .env && php artisan key:generate`; set DB credentials.
- [ ] Run `vendor/bin/phpunit` → Expected: `OK (39 tests ...)`. If PHP 8.5 breaks a dependency, record it and fix before Task 1.
- [ ] Commit: `chore: add composer.lock`.

---

### Task 1: Schema, models, factories, setting

**Files:**
- Create: `database/migrations/2026_11_05_000100_phase2_report_shift_fields.php`
- Create: `database/factories/StoreFactory.php`, `DailyReportFactory.php`, `ReportStoreFactory.php`
- Modify: `app/Models/DailyReport.php`, `ReportStore.php`, `ShipmentFollowup.php`, `Issue.php`, `Store.php` (add `HasFactory` where missing)
- Modify: `database/seeders/SystemSettingsSeeder.php`
- Modify: `app/Enums/FollowupResult.php`
- Test: `tests/Feature/Phase2SchemaTest.php`, `tests/Unit/FollowupResultStatusesTest.php`

**Interfaces — Produces:**
- `daily_reports` + columns: `shift_started_at` datetime nullable, `start_total_orders` uint nullable, `start_delivery_rate` decimal(5,2) nullable, `end_delivery_rate` decimal(5,2) nullable, `attempt_times` json nullable, `attempts_over_limit_reason` text nullable, `issue_requests` json nullable (`[{key, store_id, type, title, description}]`).
- `report_stores` + `is_outside_assignment` bool default false.
- `shipment_followups`: `shipment_id` → nullable; + `shipment_code` string(80), `store_id` FK stores restrictOnDelete; drop unique(`daily_report_id`,`shipment_id`), add unique(`daily_report_id`,`shipment_code`) and index(`shipment_code`). Backfill `shipment_code`/`store_id` from `shipments` for existing rows before making them non-null.
- `issues` + `source_key` string(40) nullable; unique(`daily_report_id`,`source_key`).
- Casts: `attempt_times` array, rates `decimal:2`, `shift_started_at` datetime, `issue_requests` array, `is_outside_assignment` boolean.
- `DailyReport::scopeOpenDraft(Builder)` = status draft.
- `FollowupResult::impliedStatuses(): array{courier: ?CourierStatus, customer: ?CustomerStatus, processing: ?ProcessingStatus}` — table exactly as spec §6.5 step 3.
- `FollowupResult::isUnanswered(): ?string` → `'customer'` for `CustomerNoAnswer`, `'courier'` for `CourierNoAnswer`, else null.
- Setting row: `['reports.max_attempts_per_shift', '2', 'integer', 'reports', 'الحد الأقصى للمحاولات في الشفت', 'تجاوزه يتطلب ذكر السبب ويظهر للمسؤول']`.
- Factories: `Store::factory()` (active, Arabic name), `DailyReport::factory()->draft()|pendingReview()|approved()->for($user)`.

- [ ] **Step 1: Write failing tests**
  - `test_followup_code_unique_per_report`: inserting two followups with same `daily_report_id`+`shipment_code` throws `QueryException`; `shipment_id` null is accepted.
  - `test_issue_source_key_unique_per_report`.
  - `test_max_attempts_setting_seeded`: `SystemSetting::get('reports.max_attempts_per_shift') === 2`.
  - Unit: each of the 7 `FollowupResult` cases returns the exact triple from spec §6.5 (e.g. `CustomerNoAnswer` → courier `Answered`, customer `NoAnswer`, processing `Open`; `DeliveredAfterFollowup` → null, null, `Resolved`; `OtherIssue` → null, null, null).
- [ ] **Step 2:** `vendor/bin/phpunit --filter "Phase2SchemaTest|FollowupResultStatusesTest"` → FAIL.
- [ ] **Step 3:** Implement migration (with working `down()`), model casts/fillables, enum methods, factories, seeder row.
- [ ] **Step 4:** Same command → PASS; then full suite → 39 + new pass.
- [ ] **Step 5:** Commit `feat(phase2): schema for shift reports, followup codes, issue source keys`.

---

### Task 2: Stores management

**Files:**
- Create: `app/Http/Controllers/StoreController.php`, `app/Http/Requests/Stores/StoreRequest.php`, `app/Policies/StorePolicy.php`, `app/Services/StoreService.php`
- Create: `resources/views/stores/index.blade.php`, `stores/_form.blade.php`
- Modify: `routes/web.php`, `resources/views/partials/sidebar.blade.php` (row «إدارة المتاجر» → route `stores.index`, pattern `stores.*`, phase null), `app/Providers/AppServiceProvider.php` if policies are registered there
- Test: `tests/Feature/StoreManagementTest.php`

**Interfaces — Produces:**
- Routes: `GET /stores` `stores.index`, `POST /stores` `stores.store`, `GET /stores/{store}` `stores.show` (view in Task 3), `PUT /stores/{store}` `stores.update`, `PATCH /stores/{store}/archive` `stores.archive`.
- `StorePolicy`: `viewAny/view` ← `stores.view`; `create/update/archive` ← `stores.manage`.
- `StoreService::create(array $data, User $actor): Store`, `update(Store, array, User): Store`, `archive(Store, User): Store` (status `archived`, never delete).
- Index filters: `q` (name/code/phone, also matched against `ArabicNormalizer::normalize(q)` on `normalized_name`), `status`, `assigned_user_id`.

- [ ] **Step 1: Write failing tests**
  - `test_admin_creates_store_with_generated_code`: code matches `/^STR-\d{5}$/`, `normalized_name` set, audit row `created`.
  - `test_duplicate_normalized_name_rejected`: «متجر الأمل» then «متجر الامل» → `assertSessionHasErrors('name')`.
  - `test_employee_cannot_create_or_update_store` → 403.
  - `test_archive_keeps_store_with_reports`: store with a report → `archive` sets `archived`, row still exists.
  - `test_assigned_user_must_be_active_employee`: inactive or viewer user id → error on `assigned_user_id`.
  - `test_index_filters_by_assigned_user_and_status`.
- [ ] **Step 2:** Run `--filter StoreManagementTest` → FAIL.
- [ ] **Step 3:** Implement following `UserController`/`UserService` patterns (create/edit in an `x-modal` on index like users).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(stores): CRUD, assignment, archive`.

---

### Task 3: Store details page

**Files:**
- Create: `app/Services/StoreInsightsService.php`, `resources/views/stores/show.blade.php`
- Modify: `StoreController::show`
- Test: `tests/Feature/StoreDetailsTest.php`

**Interfaces:**
- Produces (also used by Task 8): `app/Queries/OpenCodesQuery.php` with `public static function base(): Builder` (official shipments whose `last_result` ∈ {customer_no_answer, courier_no_answer} and `processing_status` ∉ {resolved, closed}) and `forStore(int $storeId): Builder`.
- Produces: `StoreInsightsService::dailyOrders(Store, Carbon $from, Carbon $to): Collection<date => ?int>` (from `store_daily_metrics`); `weekComparison(Store, Carbon $today): array{current: ?int, previous: ?int, diff: ?int, percent: ?float}` (last 7 days incl. today vs the 7 before; percent null when previous is 0/null); `recentNotes(Store, int $limit = 10)` (report_stores.notes of approved reports).

- [ ] **Step 1: Write failing tests**
  - `test_week_comparison_numbers`: metrics 10/day for days −13..−7 and 15/day for −6..0 → `current 105, previous 70, diff 35, percent 50.0`.
  - `test_week_comparison_null_when_no_data` → all four null.
  - `test_unapproved_reports_not_shown`: a pending report's numbers don't appear on the page.
  - `test_open_codes_listed_for_store` (create shipment rows directly with `last_result=customer_no_answer`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(stores): details page with 7-day comparison and open codes`.

---

### Task 4: Start shift and save report draft (sections ⓪–④)

**Files:**
- Create: `app/Http/Controllers/DailyReportController.php` (`create`, `start`, `edit`, `update`), `app/Http/Requests/Reports/StartShiftRequest.php`, `SaveReportRequest.php`, `app/Policies/DailyReportPolicy.php`
- Create: `app/Services/Reports/ShiftService.php`, `ReportDraftService.php`, `app/Support/CodeParser.php`, `app/Services/Reports/ShipmentCodeGuard.php`
- Create: `resources/views/reports/start.blade.php`, `reports/edit.blade.php`, `reports/_store-card.blade.php`, `resources/js/report-form.js` (Alpine component, imported in `app.js`)
- Modify: `routes/web.php`, sidebar («إدخال تقرير جديد» → `reports.create`)
- Test: `tests/Feature/ReportDraftTest.php`, `tests/Unit/CodeParserTest.php`

**Interfaces — Produces:**
- Routes: `GET /reports/new` `reports.create` (redirects to the open draft if one exists, else shows ⓪ form), `POST /reports/start` `reports.start`, `GET /reports/{report}/edit` `reports.edit`, `PUT /reports/{report}` `reports.update`.
- `ShiftService::start(User $user, int $totalOrders, float $deliveryRate): DailyReport` — locks the user row (`lockForUpdate`) and throws `ValidationException` (`'shift' => 'لديك شفت مفتوح بالفعل.'`) if a draft exists; sets `shift_started_at=now()`, `work_date=today`, `source=manual`, `created_by`.
- `CodeParser::split(string $raw): list<string>` — split on whitespace, `,`, `،`, `;`, newlines; convert Arabic-Indic digits; trim; uppercase Latin; drop empties; unique preserving order; each ≤ 80 chars else `InvalidArgumentException`.
- `ShipmentCodeGuard::officialOwner(string $code): ?int` (store_id from `shipments`), `pendingConflicts(string $code, int $storeId, int $exceptReportId): Collection<DailyReport>` (non-approved, non-draft-of-same-report followups with the same code and a different store).
- `ReportDraftService::save(DailyReport $report, array $data, User $actor): array{warnings: list<string>}` — replaces the report's `report_stores` and `shipment_followups` from payload inside a transaction. Payload shape (validated by `SaveReportRequest`):
  ```
  stores[]: {store_id, shipments_count?:int|null, notes?:string, codes[]: {code, result}}
  (the card's «لصق عدة أكواد» box is client-side: Alpine runs the same split rules and appends rows to codes[] with one result chosen for the whole paste; server re-normalizes every code with CodeParser)
  unanswered.customer?: string, unanswered.customer_store_id?: int
  unanswered.courier?:  string, unanswered.courier_store_id?:  int
  attempt_times[]: "HH:MM"; attempts_over_limit_reason?: string
  notes?, inquiries?, issue_requests[]: {key, store_id, type(IssueType), title, description}
  ```
  Rules: `is_outside_assignment = store.assigned_user_id !== report.user_id`; codes in `unanswered.*` become followups with result `customer_no_answer` / `courier_no_answer`; their store comes from `officialOwner()` or the chosen `*_store_id`, which must be one of the report's stores; a code already given a result in a store card keeps the card result (no duplicate). `followup_at=now()`, `user_id=report.user_id`, `is_official=false`, `shipment_id=null` (or the existing shipment id if official — still not official itself).
  Errors (Arabic, keyed so the form can show them): duplicate code in report; code officially owned by another store (`'الكود :code مسجّل لمتجر :store'`); more attempt times than the setting without reason; stores not allowed (archived).
  Warnings (returned, flashed, not blocking): code in another pending report under a different store.
- `DailyReportPolicy`: `create` ← `reports.create`; `view` ← owner or `reports.view_all`; `update` ← owner & status draft, or owner & rejected & setting `employee_can_edit_rejected`, or (`reports.review` & status pending_review & not owner), or (`reports.admin_edit` & approved); `review` ← `reports.review` & not owner.
- Form UI per spec §6.3 and the approved mockup `.superpowers/brainstorm/*/content/report-merged.html`: one page, sections ⓪①②③④ in order, store cards collapsible, owner's assigned stores first in the store search (`GET /stores?for_report=1` JSON or inline list), live totals (store count, orders sum), attempts counter with over-limit reason field, ③ shows codes auto-collected from cards plus paste boxes. Field label for orders: «عدد الأوردرات التي اشتغلت عليها في هذا الشفت».

- [ ] **Step 1: Write failing tests**
  - Unit `test_paste_parser_normalizes_and_dedupes`: `"nr-55821، NR-55821\n\n ٥٥٩٠٧ ,ab1"` → `['NR-55821', '55907', 'AB1']`.
  - `test_employee_starts_shift`: POST start `{start_total_orders:140, start_delivery_rate:72}` → draft with those values, `shift_started_at` set, redirect to edit.
  - `test_cannot_start_second_open_shift` (and a rejected report does **not** block).
  - `test_save_draft_with_store_codes_and_unanswered`: two stores, one card code with `courier_promised_delivery`, `unanswered.customer="A1 A2"` for store 1 → 3 followups, none official, `shipments` table empty.
  - `test_duplicate_code_in_report_rejected`.
  - `test_code_of_other_store_rejected` (existing official shipment for store A, typed under B).
  - `test_pending_conflict_is_warning_only`.
  - `test_over_limit_attempts_requires_reason` (3 times, no reason → error; with reason → saved).
  - `test_outside_assignment_flag_set`.
  - `test_employee_cannot_edit_other_users_report` → 403; `test_cannot_edit_pending_report_as_owner` → 403.
  - `test_null_shipments_count_stays_null`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement services, requests, policy, controller, views, Alpine component. **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat(reports): start shift and draft report form`.

---

### Task 5: Screenshot attachments

**Files:**
- Create: `app/Http/Controllers/AttachmentController.php`, `app/Services/AttachmentService.php`, `app/Http/Requests/Reports/UploadAttachmentRequest.php`
- Modify: `routes/web.php`, `reports/edit.blade.php` (upload zones in ③: «سكرينات المندوب» and «سكرينات مكالمات الزبائن»)
- Create: `database/migrations/2026_11_05_000200_add_category_to_attachments.php` — `attachments.category` string(30) nullable (`courier_screens` | `customer_calls`).
- Test: `tests/Feature/ReportAttachmentTest.php`

**Interfaces — Produces:**
- `POST /reports/{report}/attachments` `reports.attachments.store` (multiple `files[]`, `category`), `GET /attachments/{attachment}` `attachments.show` (streams with correct mime; authorize `view` on the parent report), `DELETE /attachments/{attachment}` `attachments.destroy` (only while report editable by actor).
- `AttachmentService::storeForReport(DailyReport, UploadedFile, string $category, User): Attachment` (sha256, path `nours/attachments/{Y}/{m}/{uuid}.{ext}`).
- `DailyReport::screens(string $category): MorphMany`.

- [ ] **Step 1: Tests** — `test_upload_image_to_own_draft` (Storage::fake private), `test_non_image_rejected` (pdf → error), `test_other_employee_cannot_download` → 403, `test_cannot_upload_to_pending_report_as_owner` → 403.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(reports): screenshot attachments`.

---

### Task 6: Submit (end of shift), revisions, WhatsApp text

**Files:**
- Create: `app/Services/Reports/ReportSubmissionService.php`, `app/Services/Reports/ReportRevisionService.php`, `app/Support/ReportWhatsappFormatter.php`, `app/Http/Requests/Reports/SubmitReportRequest.php`
- Create: `resources/views/reports/show.blade.php` (read-only view of all sections + warnings badges + «نسخ كنص واتساب» button using `navigator.clipboard`)
- Modify: `DailyReportController` (`show`, `submit`), routes
- Test: `tests/Feature/ReportSubmissionTest.php`, `tests/Unit/ReportWhatsappFormatterTest.php`

**Interfaces — Produces:**
- `POST /reports/{report}/submit` `reports.submit` with `end_delivery_rate` (0–100, required); `GET /reports/{report}` `reports.show`.
- `ReportSubmissionService::submit(DailyReport, float $endRate, User): array{warnings: list<string>}` — requires ≥1 store; sets `end_delivery_rate`, `submitted_at=now()`, status `pending_review`, `total_shipments` = sum of non-null counts (null if all null), `contact_attempts_count = count(attempt_times)`. If the report was `rejected`: `ReportRevisionService::snapshot($report, 'إعادة تقديم بعد الإرجاع', $actor)` first, then `version+1`, clear `rejection_reason`. Warnings: courier list non-empty with zero `courier_screens` → «قائمة المندوب بدون سكرينات».
- `ReportRevisionService::snapshot(DailyReport, string $reason, User): ReportRevision` — JSON of report attributes + stores + followups (code, store_id, result) + attachment ids; stored with current `version`.
- `DailyReport::warningFlags(): list<string>` — `over_attempts`, `outside_assignment`, `courier_without_screens` (used by review queue badges).
- `ReportWhatsappFormatter::format(DailyReport): string` — exactly the template in spec Appendix A (same headings, «ريبورت تاريخ: Y-m-d», «اسم الموظف: …», stores listed by name with counts, «غير متوفر» for null, attempts «المحاولة الأولى/الثانية/الثالثة…: الساعة HH:MM», codes space-separated), plus a first block «بداية الشفت: إجمالي الطلبيات X — نسبة التسليم Y% → Z%».

- [ ] **Step 1: Tests**
  - Unit `test_whatsapp_text_matches_template`: fixed report → `assertSame` against a heredoc expected string.
  - `test_submit_moves_to_pending_and_sets_totals`.
  - `test_submit_requires_end_rate_and_a_store`.
  - `test_resubmit_after_reject_creates_revision_and_bumps_version`.
  - `test_courier_without_screens_warns_but_submits`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(reports): submit, revisions, WhatsApp export`.

---

### Task 7: Review and approval engine

**Files:**
- Create: `app/Services/Reports/ReportApprovalService.php`, `app/Services/Reports/StoreMetricsBuilder.php`, `app/Services/Reports/ShipmentSummaryRefresher.php`, `app/Notifications/ReportReviewedNotification.php`
- Create: `app/Http/Controllers/ReportReviewController.php` (`queue`, `approve`, `reject`), `app/Http/Requests/Reports/RejectReportRequest.php`
- Create: `resources/views/reports/review-queue.blade.php`; modify `reports/show.blade.php` (approve / «تصحيح ثم اعتماد» → `reports.edit` with `approve_after=1` / reject modal)
- Modify: `DailyReportController::update` — when `approve_after=1` and actor can review: `ReportRevisionService::snapshot(..., 'تصحيح المسؤول')`, save, then approve. When report is approved and actor has `reports.admin_edit`: snapshot `'تعديل إداري بعد الاعتماد'`, save, then `approve()` again.
- Test: `tests/Feature/ReportApprovalTest.php`

**Interfaces — Produces:**
- Routes: `GET /review` `reports.review.queue` (pending, oldest `submitted_at` first, badges from `warningFlags()`), `POST /reports/{report}/approve` `reports.approve`, `POST /reports/{report}/reject` `reports.reject` (`rejection_reason` required, max 1000).
- `ReportApprovalService::approve(DailyReport, User $actor): void` — one transaction, `lockForUpdate` on the report:
  1. Block (ValidationException) if any code has a different store in `shipments` or in another **approved** report.
  2. Record previously affected keys: shipment ids + (store_id, work_date) pairs from the report's current official state.
  3. For each followup: `Shipment::firstOrCreate(['code'=>…], ['store_id', 'registered_at'=>work_date, 'first_report_id'=>report.id, 'created_by'])`, set `shipment_id`, `customer_status`/`courier_status` from `impliedStatuses()`, `is_official=true`.
  4. Previously linked shipments no longer referenced by any followup and with `first_report_id == report.id` → soft delete.
  5. `ShipmentSummaryRefresher::refresh(Shipment)` for old ∪ new shipments: last official followup → `last_result`, statuses, `last_followup_at`, `last_followup_user_id`, `followups_count` (official count); `processing_status` from `impliedStatuses()['processing']` when non-null.
  6. Issues from `issue_requests`: `Issue::firstOrCreate(['daily_report_id', 'source_key'=>key], [...status new, reported_by=report.user_id, reported_at=now])`. Never delete existing ones.
  7. `StoreMetricsBuilder::rebuild(int $storeId, string $date)` for old ∪ new keys.
  8. status `approved`, `approved_by/at`, `reviewed_by/at`; notify owner.
- `reject(DailyReport, string $reason, User): void` — status `rejected`, `reviewed_by/at`, `rejection_reason`; notify owner.
- `StoreMetricsBuilder::rebuild(int $storeId, string $date): void` — delete-and-recompute from approved reports where `work_date=$date`: `shipments_count` = SUM of non-null `report_stores.shipments_count` or NULL if none non-null; `reports_count`; `followups_count`, `delivered_after_followup`, `customer_no_answer`, `courier_no_answer` from official followups of those reports for this store; `contact_attempts` = sum of `contact_attempts_count` of those reports (0 if none); `issues_opened` = issues with that store and `daily_report_id` in those reports. Deletes the row if no approved report touches the key.
- `ReportReviewedNotification` (database channel) data: `{report_id, report_number, status, reason?}`.

- [ ] **Step 1: Tests**
  - `test_approve_makes_followups_official_and_creates_shipments`.
  - `test_status_mapping_applied` (one code per result; assert courier/customer/processing per spec table).
  - `test_metrics_sum_across_reports_and_keep_null`: two approved reports same store/day 30 + 12 → 42; two with NULL → NULL.
  - `test_pending_report_not_in_metrics_or_dashboard`.
  - `test_reject_requires_reason_and_notifies`.
  - `test_reviewer_cannot_approve_own_report` → 403.
  - `test_correct_then_approve_keeps_revision`.
  - `test_reapproval_is_idempotent`: approve → admin edit removing one code and changing a count → approve again: no duplicate issues, orphan shipment soft-deleted, metrics correct for both old and new store keys.
  - `test_approval_blocked_on_pending_code_conflict`: report A (store 1) approved first with code X; report B (store 2, code X) approve → validation error.
  - `test_issue_created_once_from_note`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(reports): review queue, approval engine, metrics rebuild`.

---

### Task 8: Open codes screen

**Files:**
- Create: `app/Http/Controllers/OpenCodesController.php`, `app/Services/OpenCodesService.php`, `resources/views/open-codes/index.blade.php`
- Modify: routes; sidebar — add row «الأكواد المفتوحة» (icon `alert`, route `open-codes.index`, permission `shipments.view`) after «التقارير اليومية».
- Test: `tests/Feature/OpenCodesTest.php`

**Interfaces:**
- Consumes: `OpenCodesQuery` (Task 3).
- Produces: `GET /open-codes` `open-codes.index` (`tab=customer|courier`, filters `store_id`, `user_id`, `from`, `to`; grouped by store; per-store «نسخ الأكواد» button), `POST /open-codes/mark` `open-codes.mark` (`shipment_ids[]`, `action=forwarded|resolved`, `note?`).
- `OpenCodesService::mark(array $shipmentIds, string $action, ?string $note, User): int` — `forwarded` → `in_progress`, `resolved` → `resolved`; appends note to `shipments.notes` with date and actor; audit-logged. Marking requires `reports.review` (managers), viewing requires `shipments.view`. *(Plan's call: the spec's «للمسؤول» is enforced with `reports.review` because employees also hold `shipments.followup`.)*
- Visibility: users without `reports.view_all` see only codes whose last official followup is theirs.

- [ ] **Step 1: Tests** — `test_lists_only_official_unanswered_codes_by_tab`, `test_mark_resolved_removes_from_list`, `test_forwarded_stays_with_badge`, `test_new_unanswered_followup_reopens_resolved_code` (approve a later report with `customer_no_answer` → back in list), `test_later_other_result_removes_code`, `test_employee_cannot_mark` → 403, `test_employee_sees_only_own_codes`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(shipments): open codes screen with forward/resolve marking`.

---

### Task 9: Report lists, navigation, docs, full verification

**Files:**
- Modify: `DailyReportController::index` + `resources/views/reports/index.blade.php` (`GET /reports` `reports.index`: employee sees own; `reports.view_all` sees all with filters user/status/date; status badges; link to open draft «متابعة الشفت المفتوح»)
- Modify: sidebar «التقارير اليومية» → `reports.index` (pattern `reports.*`), add «مراجعة التقارير» (`reports.review.queue`, permission `reports.review`) with pending count badge
- Modify: `app/Http/Controllers/ModulePlaceholderController.php` — remove `stores`, `reports`, `reports-create` placeholders
- Modify: `docs/ARCHITECTURE.md` §1 (new controllers/services), §3 (schema additions), §4 (phase 2 ✅ with test count); `README.md` status line
- Test: `tests/Feature/ReportListTest.php`

- [ ] **Step 1: Tests** — `test_employee_list_shows_only_own_reports`, `test_admin_filters_by_status_and_user`, `test_sidebar_links_resolve_for_each_role` (GET each sidebar route as employee/admin/viewer → 200 or hidden, never 500).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Full suite `vendor/bin/phpunit` → all green; `npm run build` succeeds; manual smoke with `php artisan db:seed --class=DemoSeeder` and `composer dev`: employee starts shift → fills → submits → admin approves → open codes shows the codes.
- [ ] **Step 6:** Commit `feat(phase2): report lists, navigation, docs` and push.
