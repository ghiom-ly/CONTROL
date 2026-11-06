# المرحلة 8 — التقارير الإدارية — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** مركز تقارير بتسعة تقارير تُستخرج PDF/Excel/طباعة بهوية الشركة، رابط آمن لتقرير المتجر عبر واتساب، كاش للتحليلات، و18 اختبار قبول.

**Architecture:** كل تقرير صنف يطبّق `App\Reports\ManagementReport` ويعيد `ReportData` (عنوان + أقسام جداول + ملخص). ثلاثة عارضات مشتركة: `PdfRenderer` (mPDF من قالب Blade واحد)، `ExcelRenderer` (PhpSpreadsheet)، والطباعة = نفس قالب Blade في المتصفح. `ReportRegistry` يربط المفتاح بالصنف.

**Tech Stack:** Laravel 12، PHP 8.4، mPDF 8 (مثبّت)، PhpSpreadsheet (مثبّت)، Blade RTL، PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-11-06-phase8-management-reports-design.md`

## Global Constraints
- الأرقام من التقارير المعتمدة فقط؛ الفترات بـ `ComparePeriod` (حد 366 يوماً).
- حد صفوف PDF/الطباعة 2000 لكل قسم مع سطر «تم عرض أول 2000 — استخدم Excel للكامل»؛ Excel كامل.
- تقرير بلا بيانات ⇒ «لا توجد بيانات في هذه الفترة».
- تقرير المتجر (store) بلا أسماء موظفين ولا ملاحظات داخلية.
- الرابط الآمن: `URL::temporarySignedRoute` لمدة 7 أيام، يعرض PDF بلا دخول.
- الصلاحيات: `exports.generate` لمركز التقارير (تُمنح للمشاهد بـ migration)؛ الإرسال للمتجر `messages.send`.
- كل استخراج ⇒ `AuditLogger::log('report_exported', …)` بالتقرير والصيغة والفترة.
- الاختبار: `php vendor/bin/phpunit --filter=<Name>`.

## Review Focus
1. اسم متجر/موظف عربي طويل أو برموز في PDF وعنوان ورقة Excel — لا خطأ (Task 2: `test_excel_sheet_title_sanitized`).
2. شعار مرفوع ثم محذوف من القرص — التقرير يُستخرج بلا شعار لا 500 (Task 1: `test_missing_logo_file_ignored`).
3. رابط آمن معدّل (تغيير store أو التاريخ) — 403 «انتهت صلاحية الرابط» (Task 5: `test_tampered_link_rejected`).
4. موظف بلا أي تقارير في الشهر — تقرير الموظف يُستخرج بأصفار (Task 4: `test_employee_report_empty_month`).
5. فترة طويلة في Excel لكل المتاجر (آلاف الصفوف) — يُستخرج كاملاً (Task 3: `test_excel_has_all_rows_beyond_pdf_cap`).

---

### Task 1: الهوية، الإطار، والعارضات

**Files:**
- Create: `app/Reports/ManagementReport.php` (interface)، `app/Reports/ReportData.php`، `app/Reports/ReportParams.php`، `app/Reports/ReportRegistry.php`، `app/Reports/Renderers/PdfRenderer.php`، `app/Reports/Renderers/ExcelRenderer.php`، `app/Support/Branding.php`، `resources/views/reports-center/document.blade.php`، `database/migrations/2026_11_11_000100_phase8.php`
- Modify: `resources/views/settings/index.blade.php` (نموذج رفع/حذف الشعار)، `SettingController` (`uploadLogo`, `deleteLogo`)، `routes/web.php` (`POST /settings/logo`, `DELETE /settings/logo`)، `Permissions::defaults()` (المشاهد + `exports.generate`)، `SystemSettingsSeeder` (`company_logo` نوع string group general)
- Test: `tests/Feature/ReportsFrameworkTest.php`

**Interfaces:**
- `ReportParams` (readonly): `ComparePeriod $period`، `?CarbonImmutable $month`، `?Store $store`، `?User $employee`، `User $actor`.
- `ReportData` (readonly): `string $title`، `string $subtitle`، `array $summary` (`label => value`)، `array $sections` كل عنصر `['title'=>string,'headers'=>list<string>,'rows'=>list<list<scalar|null>>,'landscape'=>bool]`، `bool $internal` (false لتقرير المتجر ⇒ لا اسم مستخرج).
- `interface ManagementReport { key(): string; title(): string; audience(): string /* management|employees|stores */; needs(): list<string> /* من: period, month, store, employee */; build(ReportParams $p): ReportData; }`
- `ReportRegistry::all(): array<string, class-string>`؛ `ReportRegistry::make(string $key): ManagementReport` (404 لمفتاح غير معروف).
- `Branding::header(): array{name, phone, address, logo_path: ?string}` (logo_path مسار مطلق فقط إن كان الملف موجوداً).
- `PdfRenderer::render(ReportData $d): string` (bytes) — mPDF بإعدادات: `mode=utf-8`, `format=A4` (أو `A4-L` إن كان أي قسم landscape)، `default_font=dejavusans`، `autoScriptToLang=true`، `autoLangToFont=true`، `directionality=rtl`، تذييل برقم الصفحة `{PAGENO}/{nbpg}`.
- `ExcelRenderer::download(ReportData $d, string $filename): StreamedResponse` — ورقة لكل قسم (عنوان مُنقّى ≤31 حرفاً)، RTL، الصف 1 عنوان التقرير والفترة، الصف 3 العناوين.
- `document.blade.php` يستقبل `$data`, `$brand`, `$mode` (`pdf|print`) ويقص كل قسم إلى 2000 صف مع السطر المحدد.

- [ ] **Step 1: الاختبارات**
```php
test_logo_upload_and_delete(): مدير عام يرفع PNG → SystemSetting company_logo غير فارغ والملف موجود؛ DELETE → فارغ والملف محذوف؛ مسؤول → 403.
test_missing_logo_file_ignored(): company_logo يشير لملف غير موجود → Branding::header()['logo_path'] null.
test_pdf_renderer_outputs_pdf(): ReportData بقسم عربي → bytes تبدأ بـ '%PDF'.
test_pdf_caps_rows(): قسم 2005 صفوف، وضع print → assertSee('تم عرض أول 2000') وعدد <tr> في tbody = 2000.
test_excel_sheet_title_sanitized(): قسم عنوانه 'المتجر/الأول: [تجربة]*؟ اسم طويل جداً جداً جداً' → download 200 وعنوان الورقة ≤31 بلا محارف ممنوعة.
test_viewer_gets_exports_permission(): مشاهد hasPermission('exports.generate') true.
```
- [ ] **Step 2:** FAIL. **Step 3:** تنفيذ. **Step 4:** PASS. **Step 5:** commit `feat(phase8): report framework, branding and renderers`.

### Task 2: مركز التقارير وتقارير الإدارة 1–3

**Files:**
- Create: `app/Http/Controllers/ReportCenterController.php`، `resources/views/reports-center/index.blade.php`، `app/Reports/Definitions/ExecutiveSummaryReport.php` (`executive`)، `StorePerformanceReport.php` (`stores`)، `DecliningStoresReport.php` (`declining`)
- Modify: `routes/web.php` (`GET /reports-center` `reports-center.index`؛ `GET /reports-center/{key}/{format}` `reports-center.export` بـ format ∈ pdf|excel|print)، sidebar («التقارير الإدارية» → `reports-center.index`)، `ModulePlaceholderController` (حذف `exports`) والاختبارات القديمة التي تشير لـ `/modules/exports`
- Test: `tests/Feature/ReportCenterTest.php`

**Interfaces:**
- `ReportCenterController::export(Request, string $key, string $format)`: يبني `ReportParams` من `period/from/to`, `month` (Y-m), `store_id`, `user_id` حسب `needs()` (مفقود ⇒ خطأ تحقق)، ثم pdf ⇒ `response($bytes, 200, ['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="…pdf"'])`؛ excel ⇒ `ExcelRenderer::download`؛ print ⇒ view `document` بـ `mode=print` (يستدعي `window.print()`). يسجّل `report_exported`.
- `executive`: summary من `compare()` (label ⇒ current)، أقسام: «المقارنة» (المؤشر|السابق|الحالي|الفرق|النسبة)، «أكثر المتاجر تراجعاً» (decliningStores 10)، «المشكلات حسب النوع».
- `stores`: قسم واحد landscape: المتجر|الكود|الطلبيات السابقة|الحالية|التغير %|متوسط نسبة التسليم|الأكواد المفتوحة الآن|المشكلات — لكل متجر له metrics أو report_stores أو مشكلات في الفترتين؛ تجميعات SQL فقط.
- `declining`: ثلاثة أقسام (decliningStores 100، rateDropStores 100، stoppedStores).

- [ ] **Step 1: الاختبارات**
```php
test_index_lists_nine_reports_and_permissions(): مسؤول 200 ويرى العناوين التسعة؛ مشاهد 200؛ موظف 403.
test_executive_pdf_excel_print(): لكل صيغة 200؛ pdf content-type application/pdf؛ print assertSee('window.print')؛ audit_logs فيه report_exported.
test_store_performance_rows(): متجران بنشاط → قسم stores فيه صفان بالأرقام الصحيحة (10→15 = 50%).
test_empty_report_message(): بلا بيانات → print assertSee('لا توجد بيانات في هذه الفترة').
test_unknown_report_404(): /reports-center/nope/pdf → 404؛ format غريب → 404.
```
- [ ] **Step 2–5:** FAIL → تنفيذ → PASS → commit `feat(phase8): reports center and management reports 1-3`.

### Task 3: التقارير 4–6 (الأكواد، المشكلات، الاتصالات)

**Files:** Create `app/Reports/Definitions/OpenCodesReport.php` (`open_codes`, needs [] + store اختياري)، `IssuesReport.php` (`issues`)، `ContactsReport.php` (`contacts`)؛ تسجيلها في `ReportRegistry`. Test: `tests/Feature/ReportsOperationsTest.php`

**Interfaces:**
- `open_codes`: قسم landscape: الكود|المتجر|آخر نتيجة|عدد المتابعات|تاريخ التسجيل|العمر (أيام = today − registered_at)، مرتب بالمتجر ثم العمر تنازلياً؛ `store_id` اختياري يقيّد.
- `issues`: قسم: الرقم|المتجر|النوع|الأولوية|الحالة|المسؤول|تاريخ التبليغ|مدة الحل (ساعات)|متأخرة (نعم/لا) — المبلّغ عنها في الفترة ∪ المفتوحة حالياً؛ summary: الإجمالي، المحلولة، المتأخرة، متوسط الحل.
- `contacts`: قسمان من `ContactStatsService`.

- [ ] **Step 1: الاختبارات**
```php
test_open_codes_age_and_filter(): شحنة سُجّلت قبل 5 أيام → العمر 5؛ store_id لمتجر آخر → لا صفوف.
test_issues_report_includes_open_old_issue(): مشكلة مفتوحة من قبل الفترة تظهر؛ محلولة قديمة لا تظهر.
test_contacts_report_sections(): قسمان بالعناوين 'إحصائيات الموظفين' و'اتصالات الإدارة بالمتاجر'.
test_excel_has_all_rows_beyond_pdf_cap(): 2100 شحنة مفتوحة → Excel (PhpSpreadsheet IOFactory::load على المحتوى المحفوظ) فيه 2100 صف بيانات.
```
- [ ] **Step 2–5:** FAIL → تنفيذ → PASS → commit `feat(phase8): open codes, issues and contacts reports`.

### Task 4: تقارير الموظفين 7–8

**Files:** Create `app/Reports/Definitions/EmployeeMonthlyReport.php` (`employee_monthly`, needs [employee, month])، `AttendanceMonthlyReport.php` (`attendance_monthly`, needs [month]). Test: `tests/Feature/ReportsEmployeesTest.php`

**Interfaces:**
- `employee_monthly`: summary: التقارير المعتمدة، الطلبيات، متوسط التحسن، نسبة رد الزبون، نسبة رد المندوب، الحضور، الغياب، التأخير، الإجازات؛ قسم «أيام الشهر» من `AttendanceService::month()['days']`. `user_id` يجب أن يكون موظفاً (دور employee) وإلا خطأ تحقق.
- `attendance_monthly`: قسم من `summaryForAll` بنفس أعمدة `AttendanceExport` الملخصة.

- [ ] **Step 1: الاختبارات**
```php
test_employee_monthly_summary(): موظف بتقرير معتمد start 50 end 60 → summary 'متوسط التحسن' = 10.0 وصفوف الأيام = أيام الشهر.
test_employee_report_empty_month(): بلا تقارير → 200 بأصفار.
test_employee_report_requires_employee(): بلا user_id → خطأ تحقق؛ user_id لمسؤول → خطأ تحقق.
test_attendance_monthly_rows(): موظفان → صفان.
```
- [ ] **Step 2–5:** FAIL → تنفيذ → PASS → commit `feat(phase8): employee monthly and attendance reports`.

### Task 5: تقرير المتجر والرابط الآمن عبر واتساب

**Files:**
- Create: `app/Reports/Definitions/StoreReport.php` (`store`, needs [store, period], `internal=false`)، `app/Http/Controllers/StoreReportShareController.php`، `resources/views/reports-center/link-expired.blade.php`
- Modify: `routes/web.php` — خارج مجموعة auth: `GET /r/store/{store}` `store-report.public` بـ middleware `signed` (معالجة `InvalidSignatureException` ⇒ صفحة link-expired 403)؛ داخل auth: `POST /stores/{store}/report-share` `stores.report.share`؛ `resources/views/stores/show.blade.php` (نموذج الفترة + «إرسال تقرير المتجر عبر واتساب» + «تحميل PDF»)
- Test: `tests/Feature/StoreReportShareTest.php`

**Interfaces:**
- `store`: summary: الطلبيات، متوسط نسبة التسليم، المتابعات، الأكواد المفتوحة، المشكلات؛ أقسام: «الطلبيات اليومية» (التاريخ|الطلبيات)، «نتائج المتابعات» (النتيجة|العدد)، «الأكواد المفتوحة» (الكود|آخر نتيجة|العمر)، «المشكلات» (العنوان|الحالة|التاريخ). لا أعمدة أسماء موظفين، لا notes.
- `share`: يتحقق `messages.send` و`WhatsAppNumber::normalize($store->phone)` (فشل ⇒ خطأ `phone`)، ينشئ رابط `URL::temporarySignedRoute('store-report.public', now()->addDays(7), ['store'=>id,'from'=>…,'to'=>…])`، يسجّل `StoreMessage` (type `report`, meta: from, to, url)، ويعيد redirect إلى `https://wa.me/{phone}?text=` + نص: «السلام عليكم {owner}،\nتقرير أداء متجركم {name} للفترة {from} — {to}:\n{url}\nمع التحية — {company}».
- public: يبني تقرير `store` بالفترة من الاستعلام (`ComparePeriod::between`) ويعيد PDF inline.

- [ ] **Step 1: الاختبارات**
```php
test_share_redirects_to_whatsapp_with_signed_link(): مسؤول → redirect يبدأ 'https://wa.me/218912345678?text=' ويحوي '%2Fr%2Fstore%2F'؛ StoreMessage type report.
test_public_link_serves_pdf_without_login(): رابط موقّع → 200 application/pdf بلا actingAs.
test_tampered_link_rejected(): تغيير store في الرابط → 403 و'انتهت صلاحية الرابط'.
test_link_expires_after_seven_days(): travel 8 أيام → 403.
test_store_report_has_no_employee_names(): موظف 'موظف سري' تابع كوداً للمتجر → print للتقرير store لا يحوي 'موظف سري'.
test_viewer_cannot_share_and_invalid_phone(): مشاهد → 403؛ هاتف '123' → خطأ phone.
```
- [ ] **Step 2–5:** FAIL → تنفيذ → PASS → commit `feat(phase8): store report with signed whatsapp link`.

### Task 6: الكاش واختبارات القبول الـ18 والتوثيق

**Files:**
- Modify: `AnalyticsService` (`compare`, `charts`: `Cache::remember("analytics:{method}:{from}:{to}:".self::version(), 600, …)` حيث `version()` = `Cache::get('analytics:version', 1)`؛ `AnalyticsService::flush()` يزيد الإصدار)، `ReportApprovalService` (استدعاء `AnalyticsService::flush()` بعد الاعتماد والتعديل الإداري)، `docs/ARCHITECTURE.md` (صف 8 ✅)، `README.md`
- Create: `tests/Feature/AcceptanceTest.php` (الـ18 سيناريو بأسماء `test_01_…` إلى `test_18_…` كما في المواصفة §ز)
- Test: `tests/Feature/AnalyticsCacheTest.php`

- [ ] **Step 1: الاختبارات**
```php
test_compare_is_cached(): استدعاءان متتاليان → الثاني بلا استعلامات (DB::getQueryLog فارغ).
test_cache_flushed_on_approval(): compare مخزّن، اعتماد تقرير جديد → compare يعكس الطلبيات الجديدة.
AcceptanceTest: 18 اختباراً مستقلاً، كل منها يمر بالمسار الكامل الموصوف عبر HTTP كما يفعل المستخدم.
```
- [ ] **Step 2–5:** FAIL → تنفيذ → PASS، المجموعة كاملة خضراء، `npm run build` → commit `feat(phase8): analytics cache, acceptance tests, docs`.
