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
│   ├── Middleware/  EnsureUserIsActive, ForcePasswordChange, RequirePermission, SecurityHeaders
│   └── Requests/    كل التحقق من المدخلات في الـ Backend
├── Models/          19 نموذج + Concerns/Auditable (تسجيل تلقائي في audit_logs)
├── Policies/        UserPolicy (تُضاف سياسات المتاجر/التقارير/المشكلات في مراحلها)
├── Services/        منطق العمل: UserService, DashboardService, EmployeePerformanceService, AuditLogger
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
| الموظف | تقاريره، رفع الملفات، المتابعات والاتصالات، تسجيل المشكلات |
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
| 2 | CRUD المتاجر + صفحة التفاصيل والمقارنات، نموذج التقرير اليومي (متاجر غير محدودة + أكواد لكل متجر + أوقات الاتصال + مرفقات)، المراجعة والاعتماد/الرفض، إعادة التقديم وسجل النسخ، بناء store_daily_metrics عند الاعتماد داخل Transaction | التالية |
| 3 | مركز الاستيراد: رفع XLSX/XLS/PDF، Queue للملفات الكبيرة، استخراج الجداول، OCR للمصور، مطابقة الأعمدة وأسماء المتاجر، كشف الأخطاء والتكرار (داخل الملف ومع قاعدة البيانات)، تصحيح الصفوف، اعتماد ذري، تنزيل Excel بالصفوف المرفوضة | |
| 4 | البحث بالكود وسجل المتابعات المتعددة، سجل الاتصالات وإحصائياته، إدارة المشكلات مع سجل التحديثات والإسناد | |
| 5 | Analytics Center، الرسوم البيانية الستة في لوحة التحكم، مقارنة فترتين (سابق/حالي/فرق/نسبة)، تشغيل قواعد التنبيه المجدولة | |
| 6 | مركز التقارير الإدارية (9 تقارير) PDF عربي عبر mPDF + Excel + طباعة بهوية الشركة، تحسين الأداء والكاش، اختبارات القبول النهائية الـ18 | |

بعد كل مرحلة: اختبارات Feature للوظائف الجديدة + تشغيل كامل المجموعة قبل الانتقال.
