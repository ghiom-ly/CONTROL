# المرحلة 6 — البحث بالكود، الاتصالات، المشكلات — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** رابط خارجي لكل كود، صفحة كود بملخص/تاريخ كامل، تسجيل اتصالات وإحصائياتها، وإدارة المشكلات بالتحديثات والإسناد والمهلة.

**Architecture:** نفس طبقات المشروع: Controller رفيع → FormRequest → Policy (Gate::before للمدير العام) → Service داخل `DB::transaction` → Auditable. لا جداول جديدة؛ migration واحدة تضيف إعداداً وصلاحيات وتملأ `due_at`. الإحصائيات استعلامات تجميعية بلا كاش.

**Tech Stack:** Laravel 12، PHP 8.4، MariaDB، Blade + Tailwind + Alpine (RTL)، PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-06-phase6-codes-contacts-issues-design.md`

## Global Constraints
- كل نص واجهة بالعربية؛ الأكواد `ltr`.
- الرابط الافتراضي حرفياً: `https://backoffice.nawris.algoriza.com/orders/{code}`؛ يُفتح `target="_blank" rel="noopener noreferrer"`؛ الكود يمر بـ `rawurlencode`.
- الموظف: لا `shipments.view`، لا `contacts.view`، لا `issues.view`. يحصل على `shipments.lookup` و`contacts.create`.
- الاتصال بالمتجر يتطلب `stores.manage`. الموظف يسجّل `customer|courier` فقط.
- لا إنشاء تلقائي لسجلات في `contact_attempts` من التقارير.
- لا حذف للمشكلات من الواجهة.
- الحل/الإغلاق يتطلب `action_taken`.
- أوامر الاختبار: `php vendor/bin/phpunit --filter=<Name>`؛ المجموعة كاملة: `php vendor/bin/phpunit`.

## Review Focus
1. كود بأرقام عربية أو مسافات أو حروف صغيرة في البحث/الرابط — يُوحَّد بـ `Shipment::normalizeCode` قبل البحث والعرض (Task 2: `test_lookup_normalizes_code`).
2. قالب رابط فارغ أو بلا `{code}` — الفارغ يعرض الكود نصاً بلا رابط؛ الحفظ بلا `{code}` يُرفض (Task 1: `test_empty_template_renders_plain_code`, `test_template_without_placeholder_rejected`).
3. موظف يرسل `party_type=store` يدوياً — 403/خطأ تحقق ولا سجل (Task 3: `test_employee_cannot_log_store_contact`).
4. إعادة فتح مشكلة مغلقة تمسح `resolved_at` و`closed_at` وتُسجَّل في التحديثات (Task 5: `test_reopen_clears_dates`).
5. نسبة الرد مع صفر اتصالات تُعرض «—» لا قسمة على صفر (Task 4: `test_rate_is_null_when_no_attempts`).

---

### Task 1: الإعداد والصلاحيات ومكوّن رابط الكود

**Files:**
- Create: `database/migrations/2026_11_09_000100_phase6.php`
- Create: `app/Support/ShipmentUrl.php`, `resources/views/components/code.blade.php`
- Modify: `app/Support/Permissions.php` (أو الملف الذي يعرّف `Permissions::all()` وتعيين الأدوار)، `database/seeders/SystemSettingsSeeder.php`، `app/Http/Requests/Settings/UpdateSettingsRequest.php`
- Modify (استبدال عرض الكود بـ `<x-code>`): `resources/views/reports/show.blade.php:76,95-96`، `resources/views/open-codes/index.blade.php:61`، `resources/views/stores/_insights.blade.php:40`، `resources/views/search.blade.php:19`
- Test: `tests/Feature/Phase6SetupTest.php`

**Interfaces:**
- Produces: `ShipmentUrl::for(?string $code): ?string` (null إن كان القالب فارغاً أو الكود فارغاً)؛ `<x-code :code="$c" />` يعرض `<a href=… target=_blank>` أو `<span>`؛ صلاحية `shipments.lookup` («عرض ملخص الكود»، مجموعة الشحنات).

- [ ] **Step 1: الاختبارات**
```php
test_migration_adds_setting_and_permissions(): SystemSetting::get('shipment_url_template') === 'https://backoffice.nawris.algoriza.com/orders/{code}'; موظف hasPermission('shipments.lookup') و('contacts.create') صحيحان، و('shipments.view') خطأ.
test_url_for_code(): ShipmentUrl::for('4749558') === 'https://backoffice.nawris.algoriza.com/orders/4749558'; ShipmentUrl::for('A B/1') يحوي rawurlencode('A B/1').
test_empty_template_renders_plain_code(): بعد تفريغ القالب، صفحة التقرير تعرض الكود ولا تحوي 'backoffice.nawris'.
test_report_page_links_codes(): تقرير بمتابعة كود '4749558' → assertSee('https://backoffice.nawris.algoriza.com/orders/4749558', false) و assertSee('target="_blank"', false).
test_template_without_placeholder_rejected(): حفظ الإعدادات بقالب 'https://x.com/orders' → assertSessionHasErrors('settings.shipment_url_template').
```
- [ ] **Step 2:** `php vendor/bin/phpunit --filter=Phase6SetupTest` → FAIL.
- [ ] **Step 3:** migration: `insertOrIgnore` للإعداد (type string، group reports، label «رابط صفحة الشحنة في منظومة الشحن»، description «{code} مكان الكود — فارغ = بلا روابط»)؛ صلاحية `shipments.lookup` وربطها بالموظف؛ ربط `contacts.create` بالموظف؛ ملء `issues.due_at = reported_at + INTERVAL sla HOUR` حيث null؛ `Cache::forget('system_settings.all')`. تحديث `Permissions` والبذر بنفس القيم. القاعدة: `['nullable','string','max:300','url','regex:/\{code\}/']`.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat(phase6): shipment url setting, permissions, code link component`.

### Task 2: صفحة الكود والبحث

**Files:**
- Create: `app/Http/Controllers/ShipmentController.php`، `app/Policies/ShipmentPolicy.php`، `resources/views/shipments/index.blade.php`، `resources/views/shipments/show.blade.php`
- Modify: `routes/web.php`، `app/Http/Controllers/SearchController.php`، `resources/views/partials/sidebar.blade.php` (عنصر «متابعة الشحنات» → `shipments.index` بصلاحية `shipments.view|shipments.lookup`)، `app/Http/Controllers/ModulePlaceholderController.php` (حذف `shipments`)
- Test: `tests/Feature/ShipmentLookupTest.php`

**Interfaces:**
- Produces: مسارات `GET /shipments` (`shipments.index`, نموذج بحث `?code=`)، `GET /shipments/{code}` (`shipments.show`, `where('code','.+')`). Policy: `lookup(User)` = lookup||view؛ `viewHistory(User)` = view.

- [ ] **Step 1: الاختبارات**
```php
test_employee_sees_summary_only(): موظف يفتح /shipments/{code} → assertSee(اسم المتجر, آخر نتيجة label, اسم آخر موظف تابع) و assertDontSee('سجل المتابعات الكامل') و assertDontSee(اسم موظف آخر تابع قبله).
test_admin_sees_full_history(): مسؤول → assertSee('سجل المتابعات الكامل') وكل الموظفين المتابعين وأرقام التقارير.
test_lookup_normalizes_code(): GET /shipments?code=' ٤٧٤٩٥٥٨ ' → redirect /shipments/4749558.
test_unknown_code_page(): /shipments/NOPE → 200 + 'لا يوجد كود بهذا الرقم' + رابط الموقع الخارجي.
test_search_exact_code_redirects(): /search?q=4749558 (موجود) → redirect shipments.show.
test_viewer_without_permissions_forbidden(): مستخدم بلا الصلاحيتين → 403.
```
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** `index` يطبّع الكود ويعيد التوجيه؛ `show(string $code)` يجلب `Shipment::withTrashed()->where('code', normalizeCode($code))` مع `store`, `lastFollowupUser`؛ إن `can('viewHistory')` يحمّل `followups.user`, `followups.report`, `contactAttempts.user`، المشكلات بـ `shipment_id`، والرسائل `StoreMessage::whereJsonContains('meta->codes', $code)`. الموظف: يُمرَّر فقط `contactAttempts` حيث `user_id = auth id`. البحث: مطابقة تامة واحدة لكود (بعد التطبيع) ولمن له lookup → redirect.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat(phase6): shipment lookup page with summary/full history`.

### Task 3: تسجيل الاتصالات اليدوية

**Files:**
- Create: `app/Services/ContactService.php`، `app/Http/Requests/Contacts/LogContactRequest.php`، `app/Http/Controllers/ContactController.php`
- Modify: `routes/web.php`، `resources/views/shipments/show.blade.php` (نموذج «تسجيل اتصال»)، `resources/views/stores/show.blade.php` (نموذج «تسجيل اتصال بالمتجر» لمن له `stores.manage` + آخر 10 اتصالات)
- Test: `tests/Feature/ContactLogTest.php`

**Interfaces:**
- Produces: `ContactService::log(User $actor, ContactParty $party, ContactResult $result, ?Shipment $shipment, ?Store $store, ?string $notes): ContactAttempt` — يملأ `store_id` من الشحنة إن وُجدت، `shipment_code`، `contacted_at=now()`، `attempt_number` = عدد سجلات نفس المستخدم/الكود/الطرف + 1 (للمتجر: نفس المستخدم/المتجر/اليوم + 1). مسارات: `POST /shipments/{code}/contacts` (`shipments.contacts.store`)، `POST /stores/{store}/contacts` (`stores.contacts.store`).

- [ ] **Step 1: الاختبارات**
```php
test_employee_logs_customer_contact(): POST {party_type: customer, result: no_answer, notes: 'x'} → سجل واحد attempt_number 1؛ تكرار → 2.
test_employee_cannot_log_store_contact(): party_type=store على مسار الكود → assertSessionHasErrors('party_type'); POST /stores/{id}/contacts من موظف → 403؛ لا سجلات.
test_admin_logs_store_contact(): مسؤول → سجل party store، store_id صحيح.
test_cannot_log_for_unknown_code(): /shipments/NOPE/contacts → 404.
test_employee_sees_only_own_contacts_on_code_page(): سجل لموظف آخر لا يظهر لموظف؛ يظهر للمسؤول.
```
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** مسار الكود: `party_type` ∈ `customer,courier`، صلاحية `contacts.create`؛ مسار المتجر: `authorize('update', $store)` (stores.manage)، الطرف ثابت store. `result` بـ `Rule::enum(ContactResult::class)`، `notes` حتى 1000.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat(phase6): manual contact logging (customer/courier by employee, store by admin)`.

### Task 4: شاشة سجل الاتصالات والإحصائيات

**Files:**
- Create: `app/Services/ContactStatsService.php`، `resources/views/contacts/index.blade.php`
- Modify: `app/Http/Controllers/ContactController.php` (`index`)، `routes/web.php` (`GET /contacts`, `contacts.index`, `permission:contacts.view`)، sidebar (عنصر الاتصالات → `contacts.index`)، `ModulePlaceholderController` (حذف `contacts`)
- Test: `tests/Feature/ContactStatsTest.php`

**Interfaces:**
- Produces: `ContactStatsService::perEmployee(Carbon $from, Carbon $to): Collection` عناصر `['user' => User, 'total' => int, 'customer' => int, 'customer_answered' => int, 'customer_rate' => ?float, 'courier' => int, 'courier_answered' => int, 'courier_rate' => ?float]`؛ `perStore(Carbon $from, Carbon $to): Collection` عناصر `['store' => Store, 'count' => int, 'last_at' => ?Carbon]` (party store فقط).
- قواعد العد (من المواصفة): متابعة رسمية `customer_no_answer` ⇒ زبون لم يرد؛ `courier_no_answer` ⇒ مندوب لم يرد؛ `courier_answered_still_delayed`, `courier_promised_delivery` ⇒ مندوب تم الرد؛ الباقي ⇒ يُضاف لـ`total` فقط. اليدوي: `answered` ⇒ تم الرد؛ `no_answer,busy,unreachable` ⇒ لم يرد (مقام النسبة)؛ `wrong_number,callback_requested,other` ⇒ `total` فقط. التاريخ: `followup_at` / `contacted_at` ضمن [from 00:00, to 23:59:59]. النسبة مقرّبة لمنزلتين، null عند مقام صفر.

- [ ] **Step 1: الاختبارات**
```php
test_counts_from_official_followups_and_manual(): متابعتان رسميتان (customer_no_answer, courier_promised_delivery) + متابعة غير رسمية (لا تُحسب) + يدوي (customer answered) → customer=2, customer_answered=1, customer_rate=50.0, courier=1, courier_rate=100.0, total=3.
test_rate_is_null_when_no_attempts(): موظف بلا شيء → لا يظهر أو rate null، والصفحة تعرض '—'.
test_store_stats(): اتصالان بالمتجر من المسؤول → count 2 و last_at الأحدث.
test_filters_and_permissions(): /contacts?from&to&user_id&party_type&result يرشّح الجدول؛ موظف → 403؛ مشاهد → 200.
```
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** الفترة الافتراضية آخر 7 أيام شاملة اليوم؛ الجدول يدوي فقط، 50 في الصفحة.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat(phase6): contacts log screen with per-employee and per-store stats`.

### Task 5: إدارة المشكلات

**Files:**
- Create: `app/Services/IssueService.php`، `app/Policies/IssuePolicy.php`، `app/Http/Controllers/IssueController.php`، `app/Http/Requests/Issues/StoreIssueRequest.php`، `app/Http/Requests/Issues/UpdateIssueRequest.php`، `app/Notifications/IssueNotification.php`، `resources/views/issues/index.blade.php`، `resources/views/issues/show.blade.php`
- Modify: `app/Services/Reports/ReportApprovalService.php:104-113` (الإنشاء عبر `IssueService::create` مع الحفاظ على `firstOrCreate` بالمفتاح)، `routes/web.php`، `resources/views/stores/show.blade.php` (زر «فتح مشكلة» لمن له `issues.manage`)، `resources/views/reports/show.blade.php` (قسم «المشكلات المفتوحة من هذا التقرير» بالحالة، قراءة فقط)، sidebar (→ `issues.index`)، `ModulePlaceholderController` (حذف `issues`)
- Test: `tests/Feature/IssueManagementTest.php`

**Interfaces:**
- Produces: `IssueService::create(array $data, User $reporter, ?DailyReport $report = null, ?string $sourceKey = null): Issue` (يضبط `reported_at=now()`, `due_at=+sla`, `status=new`، ويُشعر كل من له `issues.manage` عدا المُنشئ)؛ `IssueService::update(Issue $issue, array $data, User $actor): IssueUpdate` (data: `comment?, status?, priority?, assigned_to?, action_taken?`؛ يسجّل old/new status و assignee؛ يضبط/يمسح `resolved_at`,`closed_at`؛ يُشعر المُسند الجديد). `Issue::isOverdue(): bool`. مسارات: `GET /issues` (`issues.index`)، `GET /issues/{issue}` (`issues.show`)، `POST /stores/{store}/issues` (`stores.issues.store`)، `POST /issues/{issue}/updates` (`issues.updates.store`).
- `IssueNotification(Issue $issue, string $kind)` حيث kind ∈ `created|assigned`؛ `toArray` يعطي `title` («مشكلة جديدة: …» / «أُسندت إليك مشكلة: …»)، `message`، `url` = `issues.show`.

- [ ] **Step 1: الاختبارات**
```php
test_approved_report_issue_gets_due_and_notifies(): اعتماد تقرير فيه issue_requests → due_at = reported_at + 48h؛ Notification::assertSentTo(مسؤول, IssueNotification)؛ إعادة الاعتماد لا تكرر.
test_admin_creates_issue_from_store_page(): POST /stores/{id}/issues {title,type,description,priority} → issue_number ISS-…، status new.
test_update_logs_history_and_assigns(): POST updates {status: in_progress, assigned_to: مسؤول2, comment: 'اتصلت'} → IssueUpdate old_status new / new_status in_progress / new_assignee_id؛ المُسند يستلم إشعار assigned.
test_resolve_requires_action_taken(): status resolved بلا action_taken → assertSessionHasErrors('action_taken')؛ معه → resolved_at مضبوط.
test_reopen_clears_dates(): من closed إلى in_progress → resolved_at و closed_at null.
test_assignee_must_manage_issues(): assigned_to = موظف → assertSessionHasErrors('assigned_to').
test_overdue_filter(): due_at في الماضي و status new → يظهر مع ?overdue=1؛ المحلولة لا تظهر.
test_employee_permissions(): موظف → /issues 403، /issues/{id} 403؛ صفحة تقريره تعرض عنوان المشكلة وحالتها.
```
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** القائمة: تصفية status/priority/type/store_id/assigned_to/overdue، عدّادات حسب الحالة، الأحدث أولاً، 30 في الصفحة، المتأخرة بشارة حمراء «متأخرة». Policy: `viewAny/view` = issues.view؛ `create/update` = issues.manage.
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** commit `feat(phase6): issue management with updates, assignment, SLA and notifications`.

### Task 6: التوثيق والتحقق النهائي

**Files:** Modify `docs/ARCHITECTURE.md` (صف المرحلة 6 ✅ مع عدد الاختبارات ورابط المواصفة)، `README.md` (سطر الحالة).
- [ ] **Step 1:** `php vendor/bin/phpunit` → كل الاختبارات خضراء.
- [ ] **Step 2:** `npm run build` ينجح.
- [ ] **Step 3:** commit `docs: phase 6 complete`.
