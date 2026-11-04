# Phase 3 — Shift File Import & Legacy WhatsApp Reports — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let employees fill shift reports from the shipping-system Excel (start and end of shift), show file-vs-report number differences to reviewers, and turn pasted old WhatsApp reports into historical reports.

**Architecture:** A pure calculator turns mapped spreadsheet rows into per-store totals; an importer service stores the file as an `ImportBatch` with `ImportRow`s, remembers column mappings by header signature and store names as aliases, and applies results to a `DailyReport` while tracking each value's source (file/manual). Legacy text is parsed into the phase-2 save payload and saved through `ReportDraftService`; approval of a legacy report writes *historical* followups that never touch shipment summaries or open codes.

**Tech Stack:** Laravel 12, PHP 8.2+ (dev: 8.5), MariaDB/MySQL, PhpSpreadsheet (already installed via maatwebsite/excel), Blade + Alpine, PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-04-phase3-imports-design.md` (Arabic); phase-2 spec for the report model.

## Global Constraints

- New migrations only; never edit earlier migration files.
- `NULL` = not entered/unreadable; never coerce to 0. Total 0 → rate NULL.
- Nothing official before report approval; batches follow the report (`uploaded` → `needs_review` on apply → `approved` on report approval; `cancelled` from preview).
- File types `xlsx, xls, csv`; size ≤ `config('nours.uploads.max_size_kb')`; rows ≤ setting `import_max_rows` (5000); private disk under `imports/Y/m/`.
- A newer file overwrites only values whose source is `file`; never `manual`.
- Legacy followups: `followup_at = work_date 00:00`, `is_historical = true`; they never change `shipments.last_*`, statuses or `processing_status`, never appear in open codes; new shipments from legacy only are created `closed`; store conflicts are warnings, not blocks.
- Arabic UI, existing components and the phase-2 hidden-`action` pattern (buttons are disabled on submit).

## Review Focus

1. Excel cells holding numbers as text with Arabic digits («٣٤»), percent strings («73%», «0.73»), or blank trailing rows → parsed correctly, blanks skipped not rejected (Task 2 `test_reads_text_numbers_and_skips_blank_rows`, Task 3 `test_rate_column_accepts_percent_and_fraction`).
2. Status values with stray spaces/case («تم التسليم » vs «تم التسليم») → treated as the same delivered value (Task 3 `test_status_values_trimmed_and_normalized`).
3. Employee edits a count the start file filled, then uploads a second start file → manual value kept, other file values replaced (Task 5 `test_second_file_keeps_manual_values`).
4. WhatsApp text with `*bold*` markers, bullets «•», extra blank lines, Arabic digits and a missing section → still parsed; missing date → error for that report only (Task 6 `test_tolerates_whatsapp_formatting`, `test_missing_date_is_error_only_for_that_report`).
5. Approving a legacy report whose code is currently open for another store → approval succeeds, warning shown, open code unchanged (Task 7 `test_legacy_conflict_warns_and_keeps_current_state`).

---

### Task 1: Schema, aliases, store matcher

**Files:**
- Create: `database/migrations/2026_11_06_000100_phase3_imports.php`, `app/Models/StoreAlias.php`, `app/Models/ImportMapping.php`, `app/Services/Imports/StoreMatcher.php`
- Modify: `app/Models/ImportBatch.php`, `ImportRow.php`, `DailyReport.php`, `ReportStore.php`, `ShipmentFollowup.php`, `Store.php`
- Test: `tests/Feature/Phase3SchemaTest.php`

**Interfaces — Produces:**
- `store_aliases`: id, store_id FK cascade, alias string(200), normalized_alias string(200) **unique**, created_by FK users nullOnDelete, timestamps. `Store::aliases()` hasMany.
- `import_mappings`: id, signature char(64) **unique**, layout enum(`orders`,`stores`), columns json (`{store, status?, code?, total?, delivered?, rate?}` → header names), delivered_values json (list of normalized status strings), created_by, timestamps.
- `import_batches`: + `daily_report_id` FK nullable nullOnDelete; `file_type` enum extended with `csv`, `text`. `report_type` values used: `shift_start`, `shift_end`, `legacy_whatsapp`. `ImportBatch::report()` belongsTo.
- `daily_reports`: + `is_legacy` bool default false, `file_values` json nullable (`{start_total, start_rate, end_rate}`), `value_sources` json nullable (`{start_total_orders|start_delivery_rate|end_delivery_rate: 'file'|'manual'}`).
- `report_stores`: + `file_orders_count` uint nullable, `file_delivered_count` uint nullable, `end_file_orders_count` uint nullable, `end_file_delivered_count` uint nullable, `shipments_count_source` string(10) nullable.
- `shipment_followups`: + `is_historical` bool default false, index.
- `StoreMatcher::match(string $name): ?int` (by `normalized_name`, then `normalized_alias`; archived stores excluded), `remember(string $name, int $storeId, User $actor): void` (upsert alias by normalized value; no-op if it equals the store's own normalized name).

- [ ] **Step 1: Tests** — `test_alias_unique_by_normalized_value`; `test_matcher_finds_by_name_then_alias` («متجر الأمل» matches «متجر الامل»; alias «الامل فرع 2» → store); `test_matcher_ignores_archived_store`; `test_remember_is_idempotent`; `test_migration_columns_exist` (create a report with `file_values`/`value_sources`, followup with `is_historical`, batch with `file_type='csv'` and `daily_report_id`).
- [ ] **Step 2:** `vendor/bin/phpunit --filter Phase3SchemaTest` → FAIL.
- [ ] **Step 3:** Implement (migration with working `down()`; casts: arrays, booleans).
- [ ] **Step 4:** PASS + full suite green (140+).
- [ ] **Step 5:** Commit `feat(imports): schema, store aliases, matcher`.

---

### Task 2: Spreadsheet reader

**Files:**
- Create: `app/Services/Imports/SpreadsheetReader.php`, `app/Services/Imports/HeaderSignature.php`, `tests/Concerns/MakesSpreadsheets.php`
- Test: `tests/Feature/SpreadsheetReaderTest.php`

**Interfaces — Produces:**
- `SpreadsheetReader::read(string $absolutePath): array{headers: list<string>, rows: list<array{row: int, cells: array<string, ?string>}>}` — PhpSpreadsheet `IOFactory::load` (reader by extension; csv as UTF-8, BOM stripped); first sheet; header row = first row with any non-empty cell; header names trimmed, empty headers become `عمود N`, duplicates suffixed ` (2)`; each data row keyed by header, values as trimmed strings (`getFormattedValue` for numbers? no — `getCalculatedValue` cast to string, floats without trailing `.0`), empty string → null; fully-empty rows skipped; `row` = 1-based sheet row number. Throws `ImportException` (new, `app/Services/Imports/ImportException.php`) with Arabic message when no header row or rows > `SystemSetting::get('import_max_rows', 5000)`.
- `HeaderSignature::of(list<string> $headers): string` — sha256 of `ArabicNormalizer::normalize` of each header, sorted, joined by `|`.
- Test helper trait `MakesSpreadsheets::xlsx(array $rows, string $name = 'shift.xlsx'): UploadedFile` and `csv(array $rows): UploadedFile` (write with PhpSpreadsheet to a temp file).

- [ ] **Step 1: Tests** — `test_reads_xlsx_headers_and_rows` (header on row 2 after an empty row); `test_reads_csv_utf8_with_bom`; `test_reads_text_numbers_and_skips_blank_rows` (cell «٣٤» returned as «٣٤» — normalization is the calculator's job; blank row absent; `row` numbers correct); `test_duplicate_and_empty_headers_renamed`; `test_too_many_rows_throws` (setting 3, 4 rows); `test_signature_ignores_order_and_alef_variants`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(imports): spreadsheet reader and header signature`.

---

### Task 3: Shift file calculator

**Files:**
- Create: `app/Services/Imports/ShiftFileCalculator.php`
- Test: `tests/Unit/ShiftFileCalculatorTest.php`

**Interfaces — Produces:**
- `ShiftFileCalculator::compute(array $rows, array $mapping, callable $resolveStore): array` where `$rows` is `SpreadsheetReader` rows, `$mapping = ['layout' => 'orders'|'stores', 'columns' => [...], 'delivered_values' => [...]]`, `$resolveStore(string $name): ?int|'ignore'|null` (null = unmatched). Returns:
  ```
  ['stores' => list<['name'=>string,'store_id'=>?int,'total'=>int,'delivered'=>?int,'rate'=>?float]>,
   'total' => ?int, 'delivered' => ?int, 'rate' => ?float,
   'rejected' => list<['row'=>int,'reason'=>string]>, 'unmatched' => list<string>,
   'ignored' => int, 'status_values' => list<string>]
  ```
  Rules: numbers via `ArabicNormalizer::digits`, `,`/`٫` decimal, strip `%`; rate cell `0–1` → ×100, `1–100` as is, else reject; `orders` layout: one row = one order, missing store/status → reject («المتجر فارغ»/«الحالة فارغة»), duplicate code (when code column mapped, normalized with `Shipment::normalizeCode`) → reject «كود مكرر»; status compare with `ArabicNormalizer::normalize(trim)`; `stores` layout: repeated store name → rows summed; delivered from `delivered` column else `round(rate × total / 100)`; per-store rate = delivered/total×100 rounded 2, total 0 → null; overall from sums of matched (not ignored, not unmatched) stores; `status_values` = distinct normalized statuses (orders layout) for the delivered-values picker; unmatched names reported once each and excluded from totals.

- [ ] **Step 1: Tests** (no DB) — `test_orders_layout_counts_per_store` (3 rows store A: 2 delivered → total 3, delivered 2, rate 66.67); `test_stores_layout_with_delivered_column`; `test_rate_column_accepts_percent_and_fraction` («73%», «0.73», «٧٣» → 73.0; «150» rejected); `test_status_values_trimmed_and_normalized`; `test_rejects_rows_with_reasons` (empty store, empty status, duplicate code, bad number); `test_ignored_and_unmatched_excluded_from_totals`; `test_zero_total_gives_null_rate`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(imports): shift file calculator`.

---

### Task 4: Upload, mapping and preview screens

**Files:**
- Create: `app/Services/Imports/ShiftFileImporter.php`, `app/Http/Controllers/ShiftImportController.php`, `app/Http/Requests/Imports/UploadShiftFileRequest.php`, `SaveMappingRequest.php`, `app/Policies/ImportBatchPolicy.php`
- Create: `resources/views/imports/shift.blade.php` (mapping form + preview), modify `reports/start.blade.php` (upload alternative) and `reports/edit.blade.php` (section ⓪: «رفع ملف البداية», near end-rate field: «رفع ملف النهاية»)
- Modify: `routes/web.php`
- Test: `tests/Feature/ShiftImportUploadTest.php`

**Interfaces:**
- Consumes: `SpreadsheetReader`, `HeaderSignature`, `ShiftFileCalculator`, `StoreMatcher`.
- Produces:
  - Routes: `POST /imports/shift` `imports.shift.upload` (`file`, `kind=shift_start|shift_end`, `report_id?`), `GET /imports/{batch}` `imports.show`, `POST /imports/{batch}/mapping` `imports.mapping`, `POST /imports/{batch}/apply` `imports.apply` (Task 5), `POST /imports/{batch}/cancel` `imports.cancel`.
  - `ShiftFileImporter::upload(UploadedFile $file, string $kind, ?DailyReport $report, User $actor): array{batch: ImportBatch, warnings: list<string>}` — stores file, hash, `ImportRow` per data row (`raw_data` = cells, `row_number`), `total_rows`, status `uploaded`; if an `ImportMapping` exists for the signature, copy it to `batch.column_mapping` (`meta.mapping_source='saved'`). Warning «هذا الملف رُفع سابقاً لهذا التقرير» when same hash + same report + not cancelled. `shift_end` requires a report; `shift_start` without report allowed only if actor has no open draft (else attach to it).
  - `ShiftFileImporter::saveMapping(ImportBatch, array $mapping, array $storeChoices): void` — validates columns exist in headers; `storeChoices` = `[fileName => store_id|'ignore']` kept in `meta.store_choices`.
  - `ShiftFileImporter::preview(ImportBatch): ?array` — null when mapping incomplete; else calculator result using matcher + `meta.store_choices`.
  - `ImportBatchPolicy`: `view` ← uploader, or `imports.view_all`, or can `view` the linked report; `update` ← uploader and status `uploaded`.
- Mapping screen: layout radio; selects for columns from headers; for orders layout a checkbox list of `status_values` for «تعني تم التسليم»; unmatched store names each with a store select + «تجاهل»; preview table and rejected rows; buttons «تطبيق على التقرير» / «إلغاء».

- [ ] **Step 1: Tests** — `test_upload_creates_batch_and_rows`; `test_rejects_non_spreadsheet` (pdf → error); `test_saved_mapping_auto_applied_by_signature` (second file with same headers in different order shows preview without mapping); `test_same_file_twice_warns`; `test_mapping_with_unknown_column_rejected`; `test_unmatched_store_shown_and_choice_kept`; `test_other_employee_cannot_view_batch` (403); `test_end_file_requires_report`; `test_viewer_cannot_upload` (403).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(imports): shift file upload, mapping and preview`.

---

### Task 5: Apply to report, provenance, differences

**Files:**
- Modify: `ShiftFileImporter` (`apply`), `ShiftImportController::apply`, `app/Services/Reports/ReportDraftService.php` (carry file columns + sources across re-save), `ReportSubmissionService.php` (end-rate source), `app/Models/DailyReport.php` (`fileDiscrepancies()`, flag), views `reports/show.blade.php`, `reports/review-queue.blade.php`, `reports/edit.blade.php` (badge «من الملف» next to file-sourced values)
- Test: `tests/Feature/ShiftImportApplyTest.php`

**Interfaces:**
- Produces:
  - `ShiftFileImporter::apply(ImportBatch $batch, User $actor): DailyReport` — transaction; requires `preview()` non-null and, for start, non-null total; if no report and kind `shift_start` → `ShiftService::start($actor, total, rate ?? 0)` then set `start_delivery_rate` to the file rate (may be NULL); upsert `ImportMapping` by signature (`created_by`), `StoreMatcher::remember` for each chosen store; `ImportRow` status `accepted`/`rejected` with `mapped_data`/`errors`; batch `accepted_rows`, `rejected_rows`, `daily_report_id`, status `needs_review`; applies:
    - start: `file_values.start_total/start_rate`; `start_total_orders`/`start_delivery_rate` set when null or source `file` (source → `file`); for each matched store: add `ReportStore` if missing (`is_outside_assignment` as phase 2), set `file_orders_count`/`file_delivered_count`, set `shipments_count` when null or source `file` (source → `file`).
    - end: `file_values.end_rate`; `end_delivery_rate` when null or source `file`, or when the apply request has `replace_end_rate=1` (the preview shows «القيمة الحالية X — قيمة الملف Y» with a checkbox «استبدال بقيمة الملف» when a manual value differs; spec 6.3); per-store `end_file_*` columns only for stores already on the report.
  - `ReportDraftService::save` keeps `file_*`/`end_file_*` by store_id; `shipments_count_source` = `file` if the posted count equals `file_orders_count` and previous source was `file`, `manual` if a non-null count otherwise, null if empty.
  - Submission: `value_sources.end_delivery_rate` = `manual` when the posted end rate ≠ `file_values.end_rate` (or no file), else `file`.
  - `DailyReport::fileDiscrepancies(): list<array{label: string, report: int|float|null, file: int|float|null}>` — start total, start rate, end rate (when the file value exists and differs; rates compared at 2 decimals), and per store `shipments_count` vs `file_orders_count` (label = store name). `warningFlags()` adds `file_mismatch`; `WARNING_LABELS['file_mismatch'] = 'فرق مع ملف المنظومة'`.
  - Show page: table «الفروق مع ملف المنظومة» (only when non-empty) and link to the batches.

- [ ] **Step 1: Tests** — `test_start_file_creates_shift_and_fills_report` (no draft → draft with total/rate/stores/counts, sources `file`, batch `needs_review`, mapping + alias saved); `test_start_file_on_open_draft_fills_only_empty_values`; `test_second_file_keeps_manual_values`; `test_end_file_fills_end_rate_only`; `test_end_file_replaces_manual_rate_only_when_confirmed`; `test_manual_end_rate_differs_is_discrepancy`; `test_no_files_no_discrepancy_flag`; `test_discrepancy_shown_on_review_page`; `test_apply_twice_not_allowed` (second apply → error, status not `uploaded`); `test_cancel_marks_cancelled`.
- [ ] **Step 2–4:** FAIL → implement → PASS; full suite green.
- [ ] **Step 5:** Commit `feat(imports): apply shift files to reports with provenance and differences`.

---

### Task 6: Legacy WhatsApp parser

**Files:**
- Create: `app/Services/Imports/LegacyReportParser.php`
- Test: `tests/Unit/LegacyReportParserTest.php`

**Interfaces — Produces:**
- `LegacyReportParser::parse(string $text): list<array>` — splits on lines containing «ريبورت تاريخ» (each starts a report); each item:
  ```
  ['date' => ?string Y-m-d, 'employee_name' => ?string,
   'stores' => list<['name'=>string,'count'=>?int]>, 'attempt_times' => list<string H:i>,
   'customer_codes' => list<string>, 'courier_codes' => list<string>,
   'notes' => ?string, 'errors' => list<string>, 'hash' => string (sha256 of the trimmed block)]
  ```
  Tolerance: strip `*`, `_`, `~`, leading `•`, `-`, `–` and whitespace per line; `ArabicNormalizer::digits`; dates `Y-m-d`, `d/m/Y`, `d-m-Y`, `d.m.Y` (2- or 4-digit year → 20YY); store lines = lines in section 1 matching `^(.+?)\s*[:：]\s*(\d+|غير متوفر)$` excluding the «كام متجر» / «إجمالي» lines; times `H:MM` or `HH:MM` with optional «ص/م» (م adds 12 when < 12); codes via `CodeParser::split` of the text after the colon, «لا يوجد» → empty; notes = section-4 lines joined by newline, «لا يوجد» → null. Missing/invalid date → `errors[] = 'تاريخ التقرير غير موجود أو غير صالح'`. Sections recognised by their leading «1)»..«4)» or their titles, in any order.
- Round-trip guarantee: `parse(ReportWhatsappFormatter::format($report))` reproduces stores, counts, times, codes and notes.

- [ ] **Step 1: Tests** — `test_parses_company_template`; `test_round_trip_with_formatter`; `test_tolerates_whatsapp_formatting` (`*ريبورت تاريخ:* ٤/١١/٢٠٢٦`, bullets, blank lines, «غير متوفر»); `test_multiple_reports_in_one_paste`; `test_missing_date_is_error_only_for_that_report`; `test_pm_times_converted` («2:15 م» → `14:15`); `test_missing_sections_give_empty_lists`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(imports): legacy WhatsApp report parser`.

---

### Task 7: Legacy import flow and historical approval

**Files:**
- Create: `app/Services/Imports/LegacyReportImporter.php`, `app/Http/Controllers/LegacyImportController.php`, `resources/views/imports/legacy-paste.blade.php`, `imports/legacy-preview.blade.php`
- Modify: `ReportDraftService` (legacy: ownership conflicts → warnings; `followup_at` = `work_date` start of day), `ReportApprovalService` (historical branch), `ShipmentSummaryRefresher` (ignore `is_historical` for last_* and statuses; `followups_count` counts all official), routes
- Test: `tests/Feature/LegacyImportTest.php`

**Interfaces:**
- Consumes: `LegacyReportParser`, `StoreMatcher`, `ReportDraftService::save`.
- Produces:
  - Routes: `GET /imports/legacy` `imports.legacy.create`, `POST /imports/legacy/preview` `imports.legacy.preview`, `POST /imports/legacy` `imports.legacy.store`.
  - Preview: one card per parsed report with editable date, employee (own user only unless `reports.view_all`; suggested by `employee_name` exact match on `users.name`), each store line with matched store or a select (+ «تجاهل»), store selects for customer/courier code lists (preselected when one store), notes, errors, and duplicate warnings (`daily_reports` for same user + work_date, or `import_batches.file_hash` = block hash).
  - `LegacyReportImporter::create(array $item, User $owner, User $actor): array{report: DailyReport, warnings: list<string>}` — transaction: `ImportBatch` (`file_type=text`, `report_type=legacy_whatsapp`, `file_hash` = item hash, original text in `meta.text`, `stored_path` = '' (column is required → store the text to the private disk as `imports/legacy/{uuid}.txt`)); `DailyReport` (`source=import`, `import_batch_id`, `is_legacy=true`, `work_date`, `status=pending_review`, `submitted_at=now()`, rates NULL); `ReportDraftService::save` with the phase-2 payload (`stores`, `unanswered.*`, `attempt_times`, `notes`); `remember` aliases; batch `needs_review`, `daily_report_id`.
  - Approval (`ReportApprovalService::approve`) when `$report->is_legacy`: followups `is_historical=true`, official; ownership conflict → no exception, shipment linked as is, warning returned via session flash (`approve()` returns `list<string>` warnings; existing callers ignore/flash it); new shipments created with `processing_status=closed`; summary refresh leaves `last_*`/statuses/processing untouched for historical followups; metrics rebuilt normally (historical counted).
- `OpenCodesQuery` unchanged (it reads shipment summaries, which historical followups never touch).

- [ ] **Step 1: Tests** — `test_paste_preview_and_create_pending_report` (report `is_legacy`, `pending_review`, work_date from text, followups dated work_date, batch linked); `test_employee_cannot_import_for_others` (403 / forced to self); `test_admin_imports_for_employee`; `test_duplicate_date_warns`; `test_unmatched_store_choice_saved_as_alias`; `test_legacy_approval_is_historical` (new code → shipment `closed`, not in `/open-codes`, metrics count it); `test_legacy_conflict_warns_and_keeps_current_state` (code open for store B; legacy for store A approves; shipment store/last_result/processing unchanged; warning flashed); `test_legacy_followup_does_not_become_latest` (newer normal followup stays `last_result`).
- [ ] **Step 2–4:** FAIL → implement → PASS; full suite green.
- [ ] **Step 5:** Commit `feat(imports): legacy WhatsApp import with historical approval`.

---

### Task 8: Import center, batch lifecycle, aliases UI, navigation, docs

**Files:**
- Create: `app/Http/Controllers/ImportController.php` (`index`, `download`), `resources/views/imports/index.blade.php`, `app/Http/Controllers/StoreAliasController.php` (`destroy`)
- Modify: `ReportApprovalService::approve` (linked batches → `approved`, `approved_by/at`), `stores/show.blade.php` (aliases list with creator/date + delete for `stores.manage`), sidebar («استيراد الملفات» → `imports.index`, phase null), `ModulePlaceholderController` (drop `imports`), `tests/Feature/AuthorizationTest.php` / `ReportListTest.php` (placeholder URL now `/modules/shipments`), `docs/ARCHITECTURE.md` (§1 new services, §3 tables, §4 phase 3 ✅ with test count), `README.md` status line
- Test: `tests/Feature/ImportCenterTest.php`

**Interfaces:**
- Routes: `GET /imports` `imports.index` (filters `report_type`, `status`, `user_id` for `imports.view_all`; employee sees own uploads), `GET /imports/{batch}/download` `imports.download` (policy `view`; text batches download `.txt`), `DELETE /store-aliases/{alias}` `stores.aliases.destroy` (`stores.manage`).

- [ ] **Step 1: Tests** — `test_employee_sees_only_own_batches`; `test_admin_filters_by_type`; `test_download_original_protected`; `test_batch_approved_when_report_approved`; `test_alias_delete_requires_manage`; `test_sidebar_imports_link_works_for_roles`.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Full suite green; `npm run build`; manual smoke: start shift from an xlsx made with the test helper, map, apply, end file, submit, approve; paste a formatter-generated text as legacy and approve.
- [ ] **Step 6:** Commit `feat(imports): import center, aliases UI, navigation, docs`.
