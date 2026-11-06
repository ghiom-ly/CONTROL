# تقرير تأكيد الطلبيات — خطة التنفيذ

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** نوع تقرير ثانٍ «تأكيد طلبيات» لموظفات التأكيد بنفس دورة الشفت والمراجعة.
**Architecture:** نفس `daily_reports` بحقل `kind`؛ طلبات التأكيد في جدول خاص؛ خدمة حفظ خاصة؛ تفرّع في البدء/التعديل/الإرسال/الاعتماد/العرض حسب `kind`.
**Spec:** `docs/superpowers/specs/2026-11-07-confirmation-reports-design.md`

## Global Constraints
- تقارير التأكيد لا تنشئ `report_stores` ولا `shipment_followups` ولا تبني `store_daily_metrics`.
- الأرقام من المعتمد فقط في التحليلات والتقارير.
- الأكواد تُوحَّد بـ `Shipment::normalizeCode`؛ تكرار الكود داخل التقرير ⇒ خطأ تحقق.
- الاختبار: `php vendor/bin/phpunit --filter=<Name>`.

## Review Focus
1. موظف متابعة يحاول إرسال بيانات تأكيد أو العكس — يُرفض/يُتجاهل (Task 2: `test_followup_report_ignores_confirmation_payload`).
2. كود ملغى بلا سبب — خطأ تحقق (Task 2: `test_cancelled_requires_reason`).
3. «أخرى» بلا نص — خطأ تحقق (Task 2).
4. اعتماد تقرير تأكيد لا يلمس `store_daily_metrics` (Task 3: `test_approval_does_not_touch_metrics`).
5. موظفة تأكيد تظهر في الحضور كالعادة (Task 3: `test_attendance_counts_confirmation_shift`).

---

### Task 1: المخطط والإعداد ونوع العمل
- Migration: `users.work_type` enum(followup,confirmation) default followup؛ `daily_reports.kind` enum(followup,confirmation) default followup + index؛ جدول `confirmation_orders` (id, daily_report_id FK cascade, store_id FK restrict, user_id FK, code string 80, result enum, cancel_reason string 150 null, timestamps, unique(daily_report_id, code), index(code), index(store_id, result))؛ إعداد `confirmation_cancel_reasons` (type text, group reports) قيمته الأسطر الأربعة.
- Model `ConfirmationOrder`، `ConfirmationResult` enum (labels: مؤكد، ملغى، لا يرد)، `DailyReport::isConfirmation()`, علاقة `confirmationOrders()`، `User::isConfirmation()`.
- نموذج الموظف + `UserRequest`: `work_type` (Rule::in)؛ الإعدادات: textarea للأسباب.
- Tests (`ConfirmationSetupTest`): الأعمدة والإعداد موجودة؛ إنشاء موظف بـ work_type confirmation يُحفظ؛ قيمة غريبة ⇒ خطأ.

### Task 2: البدء والتعديل والحفظ
- `ShiftService::start`: لموظفة التأكيد يقبل قائمة متاجر فارغة ويضبط `kind=confirmation`؛ `reports.start` view: زر بدء فقط لها.
- `ConfirmationDraftService::save(DailyReport, array $data, User): void` — `stores.*.store_id`, `stores.*.confirmed` (نص أكواد), `stores.*.no_answer` (نص), `stores.*.cancelled.*.code`, `.reason`, `.other`؛ يستبدل طلبات التقرير؛ + attempt_times, notes.
- `SaveConfirmationRequest` والتفرّع في `DailyReportController::edit/update` حسب `kind` (view `reports.edit-confirmation`).
- Tests (`ConfirmationDraftTest`): البدء ينشئ kind confirmation بلا متاجر؛ الحفظ ينشئ الطلبات بالنتائج والأسباب والأكواد موحّدة؛ تكرار كود ⇒ خطأ؛ ملغى بلا سبب ⇒ خطأ؛ «أخرى» بلا نص ⇒ خطأ؛ موظف متابعة يرسل payload تأكيد ⇒ لا تُنشأ طلبات تأكيد.

### Task 3: الإرسال والاعتماد والعرض وواتساب
- `ReportSubmissionService::submit`: للتأكيد يتطلب طلباً واحداً ولا نسبة نهائية؛ `SubmitReportRequest`: end_delivery_rate غير مطلوب للتأكيد.
- `ReportApprovalService::approve`: للتأكيد يحدّث الحالة ويُشعر فقط (لا شحنات/مقاييس/مشكلات)، ويمسح كاش التحليلات.
- `reports.show`: تفرّع لعرض الجداول؛ `ConfirmationWhatsappFormatter::format(DailyReport): string` بالقالب.
- Tests (`ConfirmationFlowTest`): إرسال بلا طلبات ⇒ خطأ؛ إرسال ناجح بلا نسبة؛ اعتماد ⇒ approved ولا metrics؛ الرفض والإشعار؛ صفحة العرض والنص يحويان «الأوردرات المؤكدة : [2]» والأكواد مجمّعة بالمتجر؛ الحضور يحسب الشفت.

### Task 4: البحث بالكود، التحليلات، مركز التقارير
- `ShipmentController::show`: قسم «سجل التأكيد» من `confirmation_orders` بالكود (المدير يرى الموظفة والتقرير؛ الموظف بلا أسماء)؛ كود موجود فقط في التأكيد لا يعطي «لا يوجد كود».
- `ConfirmationStatsService::perEmployee/perStore/reasons(ComparePeriod)` من المعتمد؛ قسم في صفحة التحليلات.
- `Definitions\ConfirmationReport` (`confirmation`) في `ReportRegistry`.
- Tests (`ConfirmationAnalyticsTest`): النسب من المعتمد فقط؛ الأسباب مرتبة؛ صفحة الكود؛ التقرير يُستخرج بالصيغ الثلاث.

### Task 5: التوثيق والتحقق الكامل
- README/ARCHITECTURE؛ المجموعة كاملة خضراء؛ build.
