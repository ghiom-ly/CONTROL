<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // [key, value, type, group, label, description]
            ['company_name', 'شركة النورس', 'string', 'general', 'اسم الشركة', 'يظهر في التقارير المستخرجة'],
            ['company_phone', null, 'string', 'general', 'هاتف الشركة', null],
            ['company_address', null, 'string', 'general', 'عنوان الشركة', null],
            ['company_logo', null, 'string', 'general', 'شعار الشركة', 'يظهر في رأس التقارير المستخرجة'],
            ['report_submission_deadline', '18:00', 'time', 'reports', 'آخر موعد لتقديم التقرير اليومي', 'بعده يعتبر التقرير متأخراً'],
            ['report_review_sla_hours', '24', 'integer', 'reports', 'مهلة اعتماد التقرير (ساعات)', 'بعدها يظهر تنبيه تأخر الاعتماد'],
            ['employee_can_edit_rejected', '1', 'boolean', 'reports', 'السماح للموظف بتعديل التقرير المرفوض وإعادة تقديمه', null],
            ['reports.max_attempts_per_shift', '2', 'integer', 'reports', 'الحد الأقصى للمحاولات في الشفت', 'تجاوزه يتطلب ذكر السبب ويظهر للمسؤول'],
            ['attendance_late_after', '10:00', 'time', 'attendance', 'بعد هذه الساعة يُعتبر الموظف متأخراً', 'وقت بدء الشفت المرجعي للتأخير'],
            ['attendance_grace_minutes', '0', 'integer', 'attendance', 'دقائق السماح قبل احتساب التأخير', null],
            ['attendance_start_date', now()->toDateString(), 'date', 'attendance', 'بداية احتساب الحضور والغياب', 'لا يُحسب غياب قبل هذا التاريخ'],
            ['store_inactive_days', '3', 'integer', 'stores', 'عدد الأيام المتتالية دون شحنات لاعتبار المتجر متوقفاً', 'لا يُصنف المتجر متوقفاً بناءً على يوم واحد'],
            ['store_decline_percent', '30', 'integer', 'stores', 'نسبة انخفاض الشحنات عن المتوسط لإطلاق تنبيه (%)', null],
            ['issue_resolution_sla_hours', '48', 'integer', 'issues', 'المهلة الافتراضية لحل المشكلة (ساعات)', null],
            ['shipment_url_template', 'https://backoffice.nawris.algoriza.com/orders/{code}', 'string', 'reports', 'رابط صفحة الشحنة في منظومة الشحن', '{code} مكان الكود — اتركه فارغاً لإلغاء الروابط'],
            ['confirmation_cancel_reasons', "ماطلبتش\nسعر غالي\nالطلب مكرر\nالزبون لا يرد", 'text', 'reports', 'أسباب إلغاء الطلبيات (سطر لكل سبب)', 'تظهر لموظفات التأكيد عند إلغاء طلب — «أخرى» تُضاف دائماً'],
            ['import_max_rows', '5000', 'integer', 'imports', 'الحد الأقصى لعدد الصفوف في ملف الاستيراد', null],
        ];

        foreach ($settings as [$key, $value, $type, $group, $label, $desc]) {
            SystemSetting::firstOrCreate(['key' => $key], compact('value', 'type', 'group', 'label') + ['description' => $desc]);
        }
    }
}
