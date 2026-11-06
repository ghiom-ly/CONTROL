# المرحلة 7 — التحليلات والتنبيهات — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** صفحة تحليلات بمقارنة فترتين وأربعة أقسام، ستة رسوم في لوحة التحكم، وتنبيهات تلقائية بالساعة مع صفحة وملخص واتساب.

**Architecture:** خدمة `AnalyticsService` تحسب كل شيء بتجميعات SQL على `store_daily_metrics` و`daily_reports`/`report_stores` المعتمدة و`shipment_followups` الرسمية و`issues`. محرك تنبيهات `AlertEngine` بقاعدة لكل صنف في `app/Alerts/Rules/` يُشغَّل بأمر مجدول. الرسوم Chart.js الموجود (`window.Chart`) ببيانات JSON داخل الصفحة.

**Tech Stack:** Laravel 12، PHP 8.4، MariaDB، Blade/Tailwind/Alpine RTL، Chart.js 4، PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-06-phase7-analytics-alerts-design.md`

## Global Constraints
- الأرقام من التقارير **المعتمدة** فقط (`daily_reports.status = approved`، `shipment_followups.is_official = 1`).
- الفترات: `day` (اليوم مقابل أمس)، `week` (آخر 7 أيام مقابل 7 قبلها)، `month` (آخر 30 مقابل 30 قبلها)، `custom` (from–to والسابقة بنفس الطول قبلها). الافتراضي `week`.
- النسبة % = (الحالي − السابق) ÷ السابق × 100، مقرّبة لمنزلة؛ `null` («—») عندما السابق 0 أو null.
- لا N+1 على المتاجر (11 ألف متجر): تجميعات SQL بـ GROUP BY. حلقة على الموظفين (≤100) مسموحة.
- التحليلات والتنبيهات: صلاحية `analytics.view`. إغلاق التنبيه وإشعار الجرس: `analytics.view` و`issues.manage` معاً.
- كل نص عربي؛ اتجاه RTL.
- الاختبار: `php vendor/bin/phpunit --filter=<Name>`؛ الكامل `php vendor/bin/phpunit`.

## Review Focus
1. فترة بلا أي بيانات (منظومة جديدة) — كل الأقسام والرسوم تعرض «لا توجد بيانات» ولا 500 (Task 2: `test_empty_period_renders`; Task 3: `test_dashboard_charts_empty`).
2. تشغيل الأمر مرتين متتاليتين — لا تنبيه مكرر ولا إشعار ثانٍ (Task 4: `test_engine_is_idempotent`).
3. زوال السبب — التنبيه يُغلق تلقائياً؛ بعد إغلاق يدوي لا يعود في نفس اليوم (Task 4: `test_auto_close_and_manual_close_same_day`).
4. موظف مُجاز اليوم — لا تنبيه «لم يقدّم تقريره» (Task 5: `test_missing_report_skips_leave`).
5. فترة مخصصة معكوسة (to قبل from) — خطأ تحقق لا 500 (Task 1: `test_custom_range_validation`).

---

### Task 1: الفترة المقارنة وجدول المؤشرات

**Files:**
- Create: `app/Support/ComparePeriod.php`، `app/Services/Analytics/AnalyticsService.php`
- Test: `tests/Feature/AnalyticsCompareTest.php`

**Interfaces:**
- Produces: `ComparePeriod::fromRequest(Request): ComparePeriod` بخصائص `CarbonImmutable $from, $to, $prevFrom, $prevTo`، `string $preset`، `query(): array`، `label(): string`، `days(): int`. يرمي `ValidationException` للمخصصة المعكوسة.
- Produces: `AnalyticsService::compare(ComparePeriod $p): array` قائمة صفوف `['key','label','previous'=>?float,'current'=>?float,'diff'=>?float,'percent'=>?float,'lower_is_better'=>bool,'unit'=>'count'|'percent'|'points']` بالمفاتيح بالترتيب: `orders`, `start_rate`, `end_rate`, `improvement`, `reports_approved`, `followups`, `delivered_after_followup`, `customer_no_answer`, `courier_no_answer`, `open_codes`, `issues_new`, `issues_resolved`. `lower_is_better` صحيح لـ `customer_no_answer`, `courier_no_answer`, `open_codes`, `issues_new`.
- قواعد: `orders` = مجموع `store_daily_metrics.shipments_count`؛ `start_rate`/`end_rate` = متوسط مرجّح بـ `start_total_orders` من `daily_reports` المعتمدة حيث يتوفر الوزن، وإلا متوسط بسيط؛ `improvement` = `end_rate − start_rate` (نقاط)؛ المتابعات حسب `result`؛ `open_codes` = شحنات `processing_status in (open,in_progress)` و`registered_at <= to` (للسابقة `<= prevTo`)؛ المشكلات حسب `reported_at` و`resolved_at`.

- [ ] **Step 1: الاختبارات**
```php
test_presets(): week → from = today-6, to = today, prevTo = today-7, prevFrom = today-13؛ day → prev = أمس؛ month → 30 يوماً.
test_custom_range_validation(): ?period=custom&from=2026-11-05&to=2026-11-01 → ValidationException على 'to'.
test_compare_rows(): metrics لمتجر: 10 طلبيات قبل 8 أيام و15 اليوم؛ تقرير معتمد اليوم start_total_orders 100 start 60 end 70، وسابق 100 start 50 end 55 → orders previous 10 current 15 diff 5 percent 50.0؛ start_rate 50→60؛ improvement 5→10.
test_percent_null_when_previous_zero(): لا شيء سابق → percent null.
test_unapproved_reports_ignored(): تقرير pending_review اليوم لا يغيّر start_rate ولا reports_approved.
```
- [ ] **Step 2:** FAIL.
- [ ] **Step 3:** التنفيذ كما في Interfaces.
- [ ] **Step 4:** PASS. **Step 5:** commit `feat(phase7): comparison period and KPI comparison`.

### Task 2: صفحة التحليلات (الأقسام الأربعة)

**Files:**
- Create: `app/Http/Controllers/AnalyticsController.php`، `resources/views/analytics/index.blade.php`، `resources/views/analytics/_compare.blade.php`
- Modify: `app/Services/Analytics/AnalyticsService.php`، `app/Services/AttendanceService.php` (إضافة `range(User, CarbonImmutable $from, CarbonImmutable $to): array{late:int, absent:int}` مبنية على `month()`)، `routes/web.php` (`GET /analytics` `analytics.index`)، sidebar (التحليلات → `analytics.index`)، `ModulePlaceholderController` (حذف `analytics`)، `tests/Feature/ReportListTest.php` و`AuthorizationTest.php` إن أشارا لـ `/modules/analytics` (استبداله بـ `/modules/exports`)
- Test: `tests/Feature/AnalyticsPageTest.php`

**Interfaces:**
- Produces في `AnalyticsService`:
  - `decliningStores(ComparePeriod $p, int $limit = 10): Collection` عناصر `['store'=>Store,'previous'=>int,'current'=>int,'percent'=>float]` مرتبة بالنسبة تصاعدياً، فقط previous > 0 و current < previous.
  - `rateDropStores(ComparePeriod $p, int $limit = 10): Collection` عناصر `['store','previous'=>float,'current'=>float,'diff'=>float]` من `report_stores.start_rate` لتقارير معتمدة، diff < 0.
  - `stoppedStores(ComparePeriod $p): Collection<Store>` نشطة، لها shipments_count>0 سابقاً ولا صف بطلبيات >0 حالياً (حد 50).
  - `employees(ComparePeriod $p): Collection` عناصر `['user','reports'=>int,'orders'=>int,'improvement'=>?float,'customer_rate'=>?float,'courier_rate'=>?float,'late'=>int,'absent'=>int]` لمستخدمي دور employee النشطين، مرتبة بـ improvement تصاعدياً (null آخراً).
  - `issueStats(ComparePeriod $p): array` `['by_type'=>array<label,int>, 'top_stores'=>Collection(['store','count']), 'avg_resolution_hours'=>?float, 'overdue_now'=>int]`.

- [ ] **Step 1: الاختبارات**
```php
test_page_sections_and_permissions(): مسؤول → 200 ويرى 'هل الشغل يتحسن؟' 'أي المتاجر تتراجع؟' 'أي الموظفين أداؤهم أقل؟' 'ما المشاكل المتكررة؟'؛ مشاهد → 200؛ موظف → 403.
test_declining_store_listed(): متجر 20 سابقاً و5 حالياً يظهر بنسبة -75.0؛ متجر 5→20 لا يظهر.
test_stopped_store_listed(): متجر 10 سابقاً و0 حالياً → في stoppedStores.
test_employee_row(): موظف بتقرير معتمد (start 50 end 60, orders عبر metrics) → improvement 10.0, reports 1.
test_issue_stats(): مشكلتان delivery_delay ومحلولة بعد 10 ساعات → by_type['تأخر التسليم']=2، avg_resolution_hours=10.0.
test_empty_period_renders(): بلا بيانات → 200 و'لا توجد بيانات'.
test_period_buttons(): ?period=day و?period=month → 200 ويحمل الرابط النشط.
```
- [ ] **Step 2:** FAIL. **Step 3:** تنفيذ. **Step 4:** PASS. **Step 5:** commit `feat(phase7): analytics center page`.

### Task 3: الرسوم الستة

**Files:**
- Create: `resources/views/analytics/_charts.blade.php`، `resources/js/charts.js` (دالة `renderNoursCharts(data)` تُستدعى من الصفحة؛ تُستورد في `app.js`)
- Modify: `AnalyticsService` (إضافة `charts(ComparePeriod $p): array`)، `resources/views/dashboard.blade.php` و`DashboardController` (للـ `analytics.view`: تحويل فترة اللوحة إلى `ComparePeriod` بنفس from/to و include `_charts`)، `resources/views/analytics/index.blade.php` (include `_charts`)
- Test: `tests/Feature/AnalyticsChartsTest.php`

**Interfaces:**
- Produces: `charts(ComparePeriod $p): array` بمفاتيح ثابتة: `rate_trend` (`labels`, `start`, `end`)، `orders` (`labels`, `current`, `previous`)، `results` (`labels`, `values`)، `declining` (`labels`, `values`)، `employees` (`labels`, `orders`, `improvement`)، `issues` (`labels`, `values`). كل سلسلة فارغة = `[]`.
- العرض: `<canvas id="chart-…">` لكل رسم و`<script type="application/json" id="nours-charts">@json($charts)</script>`؛ رسم بلا بيانات يعرض `<p>لا توجد بيانات في هذه الفترة</p>` بدل canvas.

- [ ] **Step 1: الاختبارات**
```php
test_chart_payload_shapes(): بيانات يومين → rate_trend.labels طولها days()، orders.current و previous بنفس الطول، results.values مجموعها = المتابعات الرسمية.
test_dashboard_shows_charts_for_admin_only(): مسؤول على '/' → assertSee('nours-charts', false)؛ موظف → assertDontSee('nours-charts', false).
test_dashboard_charts_empty(): بلا بيانات → 200 و'لا توجد بيانات في هذه الفترة'.
```
- [ ] **Step 2:** FAIL. **Step 3:** تنفيذ (+ `npm run build` ينجح). **Step 4:** PASS. **Step 5:** commit `feat(phase7): six dashboard charts`.

### Task 4: محرك التنبيهات والجدول والصفحة

**Files:**
- Create: migration `2026_11_10_000100_alerts.php` (جدول `alerts`)، `app/Models/Alert.php`، `app/Alerts/AlertRule.php` (interface)، `app/Alerts/AlertEngine.php`، `app/Alerts/Finding.php`، `app/Console/Commands/AlertsCommand.php` (`nours:alerts`)، `app/Notifications/AlertNotification.php`، `app/Http/Controllers/AlertController.php`، `resources/views/alerts/index.blade.php`
- Modify: `routes/console.php` (`->hourly()`)، `routes/web.php` (`GET /alerts` `alerts.index`، `PATCH /alerts/{alert}/close` `alerts.close`، `GET /alerts/whatsapp` `alerts.whatsapp`)، sidebar (عنصر «التنبيهات» بعدّاد المفتوحة، صلاحية `analytics.view`)
- Test: `tests/Feature/AlertEngineTest.php`

**Interfaces:**
- جدول `alerts`: `id`, `rule_key` (string 80, index), `level` (enum info/warning/important/critical), `title` (string 255), `details` (text null), `subject_type` (string 40 null: store|user|report|issue|code), `subject_id` (unsignedBigInteger null), `subject_label` (string 200 null), `fingerprint` (string 191, index), `status` (enum open/closed, index), `first_seen_at`, `last_seen_at`, `closed_at` (null), `closed_by` (FK users null), `close_reason` (enum auto/manual null), timestamps.
- `interface AlertRule { public function key(): string; /** @return list<Finding> */ public function evaluate(array $params, CarbonImmutable $now): array; }`
- `Finding(string $fingerprint, string $title, ?string $details, ?string $subjectType, ?int $subjectId, ?string $subjectLabel)` (readonly).
- `AlertEngine::run(CarbonImmutable $now): array{opened:int, closed:int}` — لكل قاعدة مفعّلة في `alert_rules` ولها صنف مسجّل في `AlertEngine::RULES` (key ⇒ class): finding مفتوح بنفس fingerprint ⇒ `last_seen_at`؛ مغلق يدوياً اليوم بنفس fingerprint ⇒ تجاهل؛ غير ذلك ⇒ إنشاء + إشعار `AlertNotification` لمن له `analytics.view` و`issues.manage`. تنبيه مفتوح لقاعدة لم يعد fingerprint في نتائجها ⇒ `closed`, `close_reason=auto`. قاعدة معطّلة ⇒ تُغلق تنبيهاتها المفتوحة auto.
- `AlertController::whatsapp()` يعيد redirect إلى `https://wa.me/?text=` + rawurlencode(نص): السطر الأول `*تنبيهات NOURS — {التاريخ}*` ثم مجموعات بالمستوى (حرج، مهم، تحذير، معلومة) وكل سطر `• {title}`.
- في هذه المهمة تُسجَّل قاعدة اختبار واحدة حقيقية: `issue_resolution_delay` (مشكلة غير محلولة/مغلقة و`reported_at < now − hours` أو `due_at < now`)؛ fingerprint `issue:{id}`.

- [ ] **Step 1: الاختبارات**
```php
test_engine_opens_alert_and_notifies(): مشكلة متأخرة → run → Alert واحد open، مستواه من alert_rules (critical)، إشعار لمسؤول وليس لموظف ولا مشاهد.
test_engine_is_idempotent(): run مرتين → Alert واحد وإشعار واحد، last_seen_at محدث.
test_auto_close_and_manual_close_same_day(): حل المشكلة → run → closed auto؛ مشكلة أخرى متأخرة، إغلاق يدوي عبر PATCH → run → لا تنبيه جديد اليوم؛ بعد travel يوم → يُفتح من جديد.
test_disabled_rule_closes_its_alerts().
test_alerts_page_and_permissions(): مسؤول 200 ويرى العنوان؛ مشاهد 200 بلا زر إغلاق ويمنع PATCH (403)؛ موظف 403.
test_whatsapp_summary(): GET /alerts/whatsapp → redirect يبدأ بـ 'https://wa.me/?text=' ويحوي rawurlencode عنوان التنبيه.
test_command_scheduled_hourly(): Schedule events تحوي 'nours:alerts' بتكرار '0 * * * *'.
```
- [ ] **Step 2:** FAIL. **Step 3:** تنفيذ. **Step 4:** PASS. **Step 5:** commit `feat(phase7): alert engine, alerts page, whatsapp summary`.

### Task 5: القواعد الست الباقية وتعديلها من الإعدادات

**Files:**
- Create: `app/Alerts/Rules/ShipmentsBelowAverage.php`, `StoreStoppedReporting.php`, `RepeatedStoreIssue.php`, `ReportApprovalDelay.php`, `EmployeeMissingReport.php`, `DuplicateOrConflictingCodes.php` (و`IssueResolutionDelay.php` من Task 4 في نفس المجلد)
- Modify: `AlertEngine::RULES`، `resources/views/settings/index.blade.php` (نموذج تعديل القواعد)، `app/Http/Controllers/Settings/SettingController.php` (`updateAlertRules`)، `routes/web.php` (`PUT /settings/alert-rules` `settings.alert-rules.update`)
- Test: `tests/Feature/AlertRulesTest.php`

**Interfaces / المنطق (المعاملات من AlertRulesSeeder):**
- `shipments_below_average {percent:30, compare_days:14}`: لكل متجر، آخر يوم له metrics (≤ أمس أو اليوم) مقابل متوسط `shipments_count` في `compare_days` يوماً قبله (≥ 3 أيام بيانات)؛ انخفاض ≥ percent ⇒ fingerprint `store:{id}:below`.
- `store_stopped_reporting {days:3}`: متجر active ظهر في metrics خلال آخر 30 يوماً وآخر ظهور أقدم من `days` ⇒ `store:{id}:stopped`.
- `repeated_store_issue {count:3, days:30}`: متجر عليه ≥ count مشكلات reported_at خلال days ⇒ `store:{id}:issues`.
- `report_approval_delay {hours:24}`: تقرير pending_review و`submitted_at < now − hours` ⇒ `report:{id}`.
- `employee_missing_report {deadline:'18:00'}`: بعد deadline اليوم، موظف (دور employee) نشط بلا تقرير مرسل اليوم (`first_submitted_at` اليوم) وليس في إجازة معتمدة اليوم ⇒ `user:{id}:{Y-m-d}`.
- `duplicate_or_conflicting_codes {}`: shipment_code يظهر في متابعات تقارير pending_review لمتجرين مختلفين ⇒ `code:{code}`.
- التعديل من الإعدادات: لكل قاعدة `is_enabled` و`level` ومعاملاتها الرقمية (أعداد صحيحة 1–720، `deadline` بصيغة H:i)؛ مفاتيح المعاملات ثابتة لا تُضاف ولا تُحذف؛ صلاحية `settings.manage`.

- [ ] **Step 1: الاختبارات** (اختبار لكل قاعدة يفتح ويغلق بالحالة المناسبة)
```php
test_shipments_below_average(): 14 يوماً بـ 20 ثم أمس 10 → تنبيه؛ أمس 18 → لا.
test_store_stopped_reporting(): آخر ظهور قبل 5 أيام → تنبيه؛ قبل يومين → لا؛ متجر archived → لا.
test_repeated_store_issue(): 3 مشكلات خلال 10 أيام → تنبيه؛ 2 → لا.
test_report_approval_delay(): pending منذ 30 ساعة → تنبيه؛ 5 ساعات → لا.
test_missing_report_after_deadline(): الساعة 19:00 بلا تقرير → تنبيه؛ 17:00 → لا؛ موظف أرسل → لا.
test_missing_report_skips_leave(): إجازة معتمدة اليوم → لا تنبيه.
test_conflicting_codes(): نفس الكود في تقريرين pending لمتجرين → تنبيه؛ نفس المتجر → لا.
test_edit_rules_in_settings(): مدير عام يعطّل قاعدة ويغيّر percent إلى 40 → محفوظ؛ مفتاح معامل غريب يُتجاهل؛ مسؤول → 403.
```
- [ ] **Step 2:** FAIL. **Step 3:** تنفيذ. **Step 4:** PASS. **Step 5:** commit `feat(phase7): six alert rules and rule settings`.

### Task 6: التوثيق والتحقق
- [ ] `docs/ARCHITECTURE.md` (صف المرحلة 7 ✅ + عدد الاختبارات + رابط المواصفة)، `README.md` (سطر الحالة + cron يشغّل `schedule:run` كل دقيقة كما هو).
- [ ] `php vendor/bin/phpunit` كامل أخضر، `npm run build` ينجح.
- [ ] commit `docs: phase 7 complete`.
