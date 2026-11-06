# بنية مشروع NOURS STORE MONITORING

## 1. بنية الكود

```
app/
├── Console/Commands/BackupCommand.php      نسخ احتياطي (قاعدة البيانات + الملفات الخاصة)
├── Enums/                                   كل الحالات والنتائج بقيم ثابتة + تسميات عربية + ألوان
│   ├── StoreStatus, ReportStatus, FollowupResult, CustomerStatus, CourierStatus,
│   ├── ProcessingStatus, ContactParty, ContactResult, IssueType, IssuePriority,
│   └── IssueStatus, ImportStatus, AlertLevel, UserStatus
├── Http/
│   ├── Controllers/                         رفيعة: تحقق → استدعاء Service → عرض
│   │   ├── Auth/ (Login, Password)
│   │   ├── Settings/ (Setting, Role)
│   │   ├── Dashboard, User, AuditLog, Search, Notification, ModulePlaceholder
│   │   ├── Store, DailyReport, ReportReview, Attachment, OpenCodes   (المرحلة 2)
│   ├── Middleware/  EnsureUserIsActive, ForcePasswordChange, RequirePermission, SecurityHeaders
│   └── Requests/    كل التحقق من المدخلات في الـ Backend
├── Models/          19 نموذج + Concerns/Auditable (تسجيل تلقائي في audit_logs)
├── Policies/        UserPolicy, StorePolicy, DailyReportPolicy
├── Services/        منطق العمل: UserService, DashboardService, EmployeePerformanceService, AuditLogger,
│                    StoreService, StoreInsightsService, AttachmentService, OpenCodesService
│   └── Reports/     ShiftService, ReportDraftService, ReportSubmissionService, ReportRevisionService,
│                    ReportApprovalService, StoreMetricsBuilder, ShipmentSummaryRefresher, ShipmentCodeGuard
├── Queries/         OpenCodesQuery
├── Services/        (المرحلة 4) AttendanceService, Exports/AttendanceExport, Imports/StoreListImporter, Support/StartTotals
│   جداول: leaves, attendance_alerts؛ أعمدة: report_stores.start_orders/start_rate/start_source، daily_reports.first_submitted_at
│   أمر مجدول: nours:attendance-alerts كل 15 دقيقة
├── Services/Imports/ SpreadsheetReader, HeaderSignature, ShiftFileCalculator, ShiftFileImporter,
│                    StoreMatcher, LegacyReportParser, LegacyReportImporter   (المرحلة 3)
│   جداول جديدة: store_aliases, import_mappings؛ أعمدة: daily_reports.is_legacy/file_values/value_sources،
│   report_stores.file_*، shipment_followups.is_historical، import_batches.daily_report_id
└── Support/         Permissions (سجل الصلاحيات المركزي), Period (الفترات الزمنية), ArabicNormalizer
config/nours.php     إعدادات خاصة بالنظام (الرفع، OCR، الترقيم)
database/
├── migrations/      الجداول الكاملة لكل المراحل (لا إعادة بناء للأساس لاحقاً)
├── seeders/         إنتاج: Roles/Permissions, SystemSettings, AlertRules, InitialAdmin — تجريبي: DemoSeeder
resources/views/     Blade + Tailwind v4 + Alpine.js — RTL كامل، خط Cairo محلي (بدون اعتماد على الإنترنت)
tests/               Feature (المصادقة، الصلاحيات، الموظفون، الإعدادات، سلامة البيانات) + Unit
```

**قاعدة الفصل:** Controller لا يحتوي منطق عمل؛ Request يتحقق؛ Policy/Permission يقرر الوصول؛ Service ينفذ داخل Transaction؛ Model يعرّف العلاقات والـ casts.

## 2. الصلاحيات

- 23 صلاحية مسجلة في `App\Support\Permissions` ومخزنة في جدول `permissions`، مرتبطة بالأدوار عبر `permission_role`.
- كل صلاحية تصبح Gate تلقائياً (`@can('users.manage')`, `$this->authorize('audit.view')`).
- المدير العام يملك كل شيء عبر `Gate::before`. صلاحية `settings.manage` محصورة به ولا يمكن منحها لدور آخر.
- مصفوفة الصلاحيات قابلة للتعديل من الواجهة (الإعدادات ← الأدوار والصلاحيات) وكل تعديل مسجل.

| الدور | الافتراضي |
|---|---|
| المدير العام | كل الصلاحيات |
| المسؤول | عرض كل البيانات، اعتماد التقارير والاستيراد، إدارة المتاجر والمشكلات، التحليلات والتصدير، سجل العمليات، عرض الموظفين |
| الموظف | إدخال تقريره وإرساله (مع رفع ملف الشفت)، ورؤية إحصائيات تقاريره فقط — لا يرى المتاجر ولا الأكواد المفتوحة ولا مركز الاستيراد |
| المشاهد | عرض فقط |

## 3. قاعدة البيانات

```
roles ─< permission_role >─ permissions
roles ─< users
users ─< stores (assigned_user_id)
users ─< daily_reports ─< report_stores >─ stores
                      ─< report_revisions        (نسخة كاملة قبل كل تعديل جوهري)
                      ─< shipment_followups >─ shipments >─ stores
                      ─< contact_attempts
import_batches ─< import_rows ;  import_batches ─< daily_reports (source=import)
stores ─< issues ─< issue_updates
stores ─< store_daily_metrics                    (ملخص يومي يُبنى من المعتمد فقط)
attachments (morph) → daily_reports / issues / ...
audit_logs, notifications, alert_rules, system_settings
```

| الجدول | أهم الحقول والقيود |
|---|---|
| `users` | username فريد، password مشفر، role_id، status، hired_at، must_change_password، SoftDeletes |
| `stores` | code فريد تلقائي `STR-00001`، normalized_name للمطابقة عند الاستيراد، status (نشط/متوقف/يحتاج متابعة/مؤرشف)، expected_avg_shipments |
| `daily_reports` | report_number تلقائي `RPT-2026-000001`، **work_date منفصل عن created_at**، status، source، version، created_by/updated_by/reviewed_by/approved_by |
| `report_stores` | unique(report, store) — عدد غير محدود من المتاجر لكل تقرير، shipments_count **nullable** |
| `shipments` | **code فريد** — المتابعة الجديدة لا تنشئ شحنة جديدة، حالات منفصلة: last_result / customer_status / courier_status / processing_status |
| `shipment_followups` | **unique(daily_report_id, shipment_id)** يمنع تكرار الكود داخل التقرير، is_official = true فقط بعد الاعتماد |
| `contact_attempts` | party_type (متجر/عميل/مندوب)، attempt_number، مستقل عن عدد الشحنات |
| `issues` / `issue_updates` | issue_number تلقائي، 9 أنواع، أولوية، مسؤول، سجل تحديثات لكل تغيير |
| `import_batches` / `import_rows` | file_hash لكشف إعادة رفع الملف نفسه، raw_data كما قُرئ + mapped_data بعد التصحيح، حالة لكل صف |
| `audit_logs` | المستخدم، الحدث، السجل، القيم قبل/بعد (JSON)، IP، المتصفح |

**قواعد السلامة المطبقة في المخطط:**
1. الأعداد كلها nullable — `NULL` = غير مُدخل/غير واضح، ولا يُحوّل إلى صفر (الواجهة تعرض «غير متوفر»).
2. الإحصائيات الرسمية من `status = approved` و `is_official = true` فقط.
3. Foreign Keys مع `restrictOnDelete` للبيانات المرجعية (لا يمكن حذف متجر له تقارير)، وSoftDeletes للمستخدمين والمتاجر والتقارير والمشكلات.
4. Indexes على كل أعمدة الفلترة الزمنية والحالة.

## 4. خطة التنفيذ

| المرحلة | المحتوى | الحالة |
|---|---|---|
| 1 | التأسيس، قاعدة البيانات الكاملة، الدخول، الصلاحيات، التصميم العام، الموظفون، الإعدادات، سجل العمليات، النسخ الاحتياطي | ✅ مكتملة — 39 اختبار ناجح |
| 2 | CRUD المتاجر + صفحة التفاصيل والمقارنات، نموذج التقرير اليومي (متاجر غير محدودة + أكواد لكل متجر + أوقات الاتصال + مرفقات)، المراجعة والاعتماد/الرفض، إعادة التقديم وسجل النسخ، بناء store_daily_metrics عند الاعتماد داخل Transaction، شاشة الأكواد المفتوحة، نسخ التقرير كنص واتساب | ✅ مكتملة — 131 اختبار ناجح (التصميم: `docs/superpowers/specs/2026-11-04-phase2-stores-daily-report-design.md`) |
| 3 | مركز الاستيراد: ملف منظومة الشحن (XLSX/XLS/CSV) عند بداية الشفت ونهايته يملأ التقرير، مطابقة أعمدة محفوظة بتوقيع العناوين، مطابقة المتاجر وأسماؤها البديلة، تتبّع مصدر كل قيمة (ملف/يدوي)، الفروق مع الملف للمسؤول، استيراد تقارير واتساب القديمة كسجل تاريخي. (PDF/OCR مؤجَّل — لم يُطلب) | ✅ مكتملة — 206 اختبار ناجح (التصميم: `docs/superpowers/specs/2026-11-04-phase3-imports-design.md`) |
| 4 | استيراد المتاجر من Excel، شاشة تعيين المتاجر للموظفين، طلبيات ونسبة تسليم كل متجر عند بداية الشفت (النسبة العامة مرجّحة)، الحضور والانصراف من الشفتات (تأخير، غياب، إجازات، تنبيهات مجدولة، تقرير شهري مع طباعة وExcel) | ✅ مكتملة — 280 اختبار ناجح (التصميم: `docs/superpowers/specs/2026-11-05-phase4-stores-assignment-attendance-design.md`) |
| 5 | قوالب رسائل المتاجر (أكواد لم يرد عليها الزبون، مشكلة، ملخص أداء، عامة) بمتغيرات تُملأ تلقائياً، اعتماد المدير العام، إرسال عبر واتساب لمتجر أو لعدة متاجر، سجل الرسائل | ✅ مكتملة — 328 اختبار ناجح (التصميم: `docs/superpowers/specs/2026-11-05-phase5-store-message-templates-design.md`) |
| 6 | البحث بالكود وسجل المتابعات المتعددة، سجل الاتصالات وإحصائياته، إدارة المشكلات مع سجل التحديثات والإسناد | ✅ مكتملة — 379 اختبار ناجح، مع رابط كل كود إلى منظومة الشحن (التصميم: `docs/superpowers/specs/2026-11-06-phase6-codes-contacts-issues-design.md`) |
| 7 | Analytics Center، الرسوم البيانية الستة في لوحة التحكم، مقارنة فترتين (سابق/حالي/فرق/نسبة)، تشغيل قواعد التنبيه المجدولة | ✅ مكتملة — 414 اختبار ناجح، مع صفحة «التنبيهات» وملخص واتساب (التصميم: `docs/superpowers/specs/2026-11-06-phase7-analytics-alerts-design.md`) |
| 8 | مركز التقارير الإدارية (9 تقارير) PDF عربي عبر mPDF + Excel + طباعة بهوية الشركة، تحسين الأداء والكاش، اختبارات القبول النهائية الـ18 | ✅ مكتملة — 469 اختبار ناجح (منها 18 اختبار قبول)، تقرير المتجر برابط آمن عبر واتساب، كاش التحليلات (التصميم: `docs/superpowers/specs/2026-11-06-phase8-management-reports-design.md`) |

بعد كل مرحلة: اختبارات Feature للوظائف الجديدة + تشغيل كامل المجموعة قبل الانتقال.
