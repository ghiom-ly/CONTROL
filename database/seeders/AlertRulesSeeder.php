<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use Illuminate\Database\Seeder;

class AlertRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['shipments_below_average', 'انخفاض الشحنات عن المعدل', 'warning', ['percent' => 30, 'compare_days' => 14]],
            ['store_stopped_reporting', 'توقف المتجر عن الظهور في التقارير', 'important', ['days' => 3]],
            ['repeated_store_issue', 'تكرار مشكلة في متجر', 'important', ['count' => 3, 'days' => 30]],
            ['report_approval_delay', 'تأخر اعتماد التقارير', 'warning', ['hours' => 24]],
            ['employee_missing_report', 'عدم تقديم الموظف تقريره', 'warning', ['deadline' => '18:00']],
            ['issue_resolution_delay', 'تأخر حل مشكلة', 'critical', ['hours' => 48]],
            ['duplicate_or_conflicting_codes', 'وجود أكواد مكررة أو متعارضة', 'important', []],
        ];
        foreach ($rules as [$key, $name, $level, $params]) {
            AlertRule::firstOrCreate(['key' => $key], ['name' => $name, 'level' => $level, 'parameters' => $params, 'is_enabled' => true]);
        }
    }
}
