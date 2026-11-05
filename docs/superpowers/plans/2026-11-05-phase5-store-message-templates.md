# Phase 5 — Store Message Templates via WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin-written, super-admin-approved message templates with variables, filled per store and opened in WhatsApp (`wa.me`), with a send log and bulk sending.

**Architecture:** `MessageTemplate` with a status workflow; `MessageContext` builds variable values for a store (+ optional codes/issue/period); `MessageRenderer` substitutes variables and reports unknown/unfilled ones; `WhatsAppNumber` normalizes Libyan numbers; `StoreMessage` logs every opened message. Controllers are thin.

**Tech Stack:** Laravel 12, MariaDB, Blade + Alpine, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-05-phase5-store-message-templates-design.md` (Arabic).

## Global Constraints

- Only `approved` templates can be sent. Editing an approved template → `draft`. Version increments on each approval.
- Permissions: new `messages.send` (admin default), `messages.approve` (super admin only — granted to no role by default).
- Phone: digits only; `09xxxxxxxx`→`2189xxxxxxxx`; `00218…`/`+218…`→`218…`; valid = `^218\d{9}$`.
- Unavailable value → «غير متوفر». Message > 1500 chars → warning.
- Bulk: types `general`, `performance`, `open_codes` (no `issue`); max 300 stores; `open_codes` stores without codes skipped and listed.
- New migrations only; Arabic UI; existing components.

## Review Focus

1. Template body with an unknown variable or one not allowed for its type (`{الأكواد}` in `general`) → save rejected naming it (Task 1 `test_unknown_variable_rejected`).
2. Phone written as «٠٩١-٢٣٤ ٥٦٧٨», «+218 91 2345678», «00218912345678», «0912345678», «12345» → first four same number, last invalid (Task 1 `test_whatsapp_number_normalization`).
3. Approved template edited by admin then sent before re-approval → refused (Task 3 `test_cannot_send_unapproved_or_disabled`).
4. Performance period with one approved shift / none → change «0%» / «غير متوفر» (Task 1 `test_performance_values`).
5. Store with no open codes and an `open_codes` template → warning, still sendable; in bulk → skipped (Task 3, Task 4).

---

### Task 1: Schema, permissions, variables, renderer, phone

**Files:** Create `database/migrations/2026_11_08_000100_message_templates.php`, `app/Models/MessageTemplate.php`, `app/Models/StoreMessage.php`, `app/Support/MessageVariables.php`, `app/Services/Messages/MessageContext.php`, `app/Services/Messages/MessageRenderer.php`, `app/Support/WhatsAppNumber.php`; modify `Permissions.php`; Tests `tests/Unit/WhatsAppNumberTest.php`, `tests/Feature/MessageRenderingTest.php`.

**Produces:**
- `message_templates`: id, name string(150), type enum(open_codes,issue,performance,general), body text, status enum(draft,pending,approved,rejected,disabled) default draft, version uint default 0, review_note text null, created_by, updated_by, approved_by, approved_at, timestamps, softDeletes. `MessageTemplate::TYPES` labels: أكواد لم يرد عليها الزبون، مشكلة مسجّلة على المتجر، ملخص أداء المتجر، رسالة عامة. `STATUSES` labels: مسودة، بانتظار الاعتماد، معتمد، مُرجَع، موقوف.
- `store_messages`: id, store_id FK, message_template_id FK nullOnDelete, template_version uint null, type string(20), body text, phone string(20), sent_by FK users, meta json null (`codes`, `issue_id`, `period`), timestamps; index(store_id, created_at).
- Migration inserts the 4 suggested templates from spec Appendix A with status `pending` and the 2 permissions (+ attach `messages.send` to admin).
- `MessageVariables::COMMON` = [اسم_المتجر, صاحب_المتجر, كود_المتجر, التاريخ, اسم_المرسل, اسم_الشركة]; `BY_TYPE` = open_codes:[الأكواد, عدد_الأكواد], issue:[عنوان_المشكلة, وصف_المشكلة, رقم_المشكلة, تاريخ_المشكلة], performance:[الفترة, عدد_الطلبيات, نسبة_البداية, نسبة_النهاية, التحسن, الأكواد_المفتوحة], general:[]. `MessageVariables::allowed(string $type): list<string>`; `MessageVariables::unknownIn(string $body, string $type): list<string>` (regex `\{([^{}]+)\}`).
- `WhatsAppNumber::normalize(?string): ?string` (null when invalid).
- `MessageContext::forStore(Store $s, User $sender, array $opts = []): array<string,string>` — opts: `codes` (list) for open_codes (default: `OpenCodesQuery::base()->where store, last_result customer_no_answer` codes, newest first), `issue` (Issue), `period` (`today`|`7d`|`month`, default `7d`). performance: orders = SUM metrics.shipments_count in period (null→غير متوفر); start/end rate = start_rate of report_stores for this store in approved reports ordered by work_date, shift_started_at (first/last non-null); change = end−start formatted «+6.5%»/«-2%»/«0%»; open count = same as default codes count. Period label: «اليوم» / «آخر 7 أيام» / «هذا الشهر». Dates `Y/m/d`.
- `MessageRenderer::render(string $body, array $values): array{text: string, missing: list<string>}` — replaces `{name}`; names without value stay and are listed in `missing`.

- [ ] Tests: Unit `test_whatsapp_number_normalization`; Feature `test_unknown_variable_rejected_helper` (`unknownIn('مرحبا {الأكواد} {غلط}', 'general')` → `['الأكواد','غلط']`), `test_render_replaces_and_reports_missing`, `test_open_codes_values` (codes listed one per line, count), `test_performance_values` (two approved shifts 72→81 → «72%», «81%», «+9%»; one shift → «0%»; none → «غير متوفر»; orders sum), `test_issue_values`, `test_seeded_templates_pending`, `test_permissions_defaults` (admin has send not approve).
- [ ] FAIL → implement → PASS → commit `feat(messages): templates schema, variables, renderer`.

### Task 2: Template management and approval

**Files:** Create `app/Http/Controllers/MessageTemplateController.php`, `app/Http/Requests/MessageTemplateRequest.php`, views `messages/templates/index.blade.php`, `messages/templates/form.blade.php`; routes; Test `tests/Feature/MessageTemplateTest.php`.

**Produces:** Routes (`messages.send` unless noted): `GET /messages/templates` `messages.templates.index`, `GET /messages/templates/create`, `POST /messages/templates`, `GET /messages/templates/{template}/edit`, `PUT /messages/templates/{template}`, `POST /messages/templates/{template}/submit`, `DELETE /messages/templates/{template}` (draft/rejected only), `POST /messages/templates/{template}/approve|reject|disable|enable` (`messages.approve`; reject requires `review_note`), `GET /messages/templates/{template}/preview?store_id=` (JSON `{text, missing}`). Form: variable buttons for the selected type, live preview (Alpine fetch). Saving approved → draft.

- [ ] Tests: `test_admin_creates_draft_and_submits`; `test_unknown_variable_rejected`; `test_super_admin_approves_and_version_increments`; `test_admin_cannot_approve`; `test_reject_requires_note_and_returns_to_admin`; `test_editing_approved_returns_to_draft`; `test_disable_and_enable`; `test_delete_only_draft_or_rejected`; `test_preview_json`; `test_employee_forbidden`.
- [ ] FAIL → implement → PASS → commit `feat(messages): template management and approval`.

### Task 3: Send to one store + log

**Files:** Create `app/Http/Controllers/StoreMessageController.php` (`create`, `store`), view `messages/send.blade.php`; modify `stores/show.blade.php` (button + last 20 messages), `open-codes/index.blade.php` (per-store «إرسال رسالة» link with selected codes); routes; Test `tests/Feature/StoreMessageSendTest.php`.

**Produces:** `GET /stores/{store}/message?template_id=&codes[]=&issue_id=&period=` `stores.message` (form: template select of approved, issue select for issue type, period select for performance, editable textarea prefilled, warnings: missing variables, invalid phone, no open codes, >1500 chars); `POST /stores/{store}/message` `stores.message.send` (`template_id`, `body`, `codes[]?`, `issue_id?`, `period?`, `mark_forwarded?`) → validates template approved and type, phone valid, body non-empty ≤ 4000; creates `StoreMessage`; if `mark_forwarded` and type open_codes → `OpenCodesService::mark(shipment ids of codes, 'forwarded', null, actor)`; responds redirect to `wa.me` URL (`https://wa.me/{phone}?text=` + rawurlencode(body)). The form posts with `target="_blank"` so the page stays.

- [ ] Tests: `test_send_redirects_to_whatsapp_and_logs`; `test_body_edit_is_what_is_logged`; `test_cannot_send_unapproved_or_disabled`; `test_invalid_phone_blocks_send`; `test_open_codes_prefilled_and_mark_forwarded`; `test_issue_template_requires_store_issue`; `test_no_codes_shows_warning`; `test_employee_forbidden`.
- [ ] FAIL → implement → PASS → commit `feat(messages): send to a store via WhatsApp with log`.

### Task 4: Bulk send

**Files:** Create `app/Http/Controllers/BulkMessageController.php`, view `messages/bulk.blade.php`; routes; Test `tests/Feature/BulkMessageTest.php`.

**Produces:** `GET /messages/bulk` (choose template [allowed types], stores: multi-select / «كل المتاجر النشطة» / «متاجر موظف»), `POST /messages/bulk/preview` → list rows `{store, phone|null, text, skipped_reason?}` (≤300), each row has a button posting to `stores.message.send` (target `_blank`) with the rendered body; after click Alpine marks «فُتح للإرسال».

- [ ] Tests: `test_bulk_preview_rows_for_general`; `test_open_codes_skips_stores_without_codes`; `test_issue_type_not_allowed`; `test_limit_300`; `test_invalid_phone_flagged`.
- [ ] FAIL → implement → PASS → commit `feat(messages): bulk send list`.

### Task 5: Log screen, navigation, docs

- [ ] `GET /messages/log` (filters store/template/sender/date; `messages.send`); sidebar «رسائل المتاجر» (templates/log/bulk tabs); ARCHITECTURE §4 phase 5 ✅ (renumber later rows), README status.
- [ ] Tests `tests/Feature/MessageLogTest.php`: `test_log_lists_and_filters`, `test_nav_for_admin_not_employee`.
- [ ] Full suite; `npm run build`; commit `feat(messages): log, navigation, docs`.
