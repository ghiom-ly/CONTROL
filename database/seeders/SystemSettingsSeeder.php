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
            ['report_submission_deadline', '18:00', 'time', 'reports', 'آخر موعد لتقديم التقرير اليومي', 'بعده يعتبر التقرير متأخراً'],
            ['report_review_sla_hours', '24', 'integer', 'reports', 'مهلة اعتماد التقرير (ساعات)', 'بعدها يظهر تنبيه تأخر الاعتماد'],
            ['employee_can_edit_rejected', '1', 'boolean', 'reports', 'السماح للموظف بتعديل التقرير المرفوض وإعادة تقديمه', null],
            ['store_inactive_days', '3', 'integer', 'stores', 'عدد الأيام المتتالية دون شحنات لاعتبار المتجر متوقفاً', 'لا يُصنف المتجر متوقفاً بناءً على يوم واحد'],
            ['store_decline_percent', '30', 'integer', 'stores', 'نسبة انخفاض الشحنات عن المتوسط لإطلاق تنبيه (%)', null],
            ['issue_resolution_sla_hours', '48', 'integer', 'issues', 'المهلة الافتراضية لحل المشكلة (ساعات)', null],
            ['import_max_rows', '5000', 'integer', 'imports', 'الحد الأقصى لعدد الصفوف في ملف الاستيراد', null],
        ];

        foreach ($settings as [$key, $value, $type, $group, $label, $desc]) {
            SystemSetting::firstOrCreate(['key' => $key], compact('value', 'type', 'group', 'label') + ['description' => $desc]);
        }
    }
}
