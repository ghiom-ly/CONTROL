<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * صفحة توضيحية للوحدات المجدولة في مراحل لاحقة — لا تعرض بيانات أو أزرار وهمية.
 */
class ModulePlaceholderController extends Controller
{
    public const MODULES = [
        'stores' => ['إدارة المتاجر', 2],
        'reports' => ['التقارير اليومية', 2],
        'reports-create' => ['إدخال تقرير جديد', 2],
        'imports' => ['استيراد الملفات', 3],
        'shipments' => ['متابعة الشحنات', 4],
        'contacts' => ['سجل الاتصالات', 4],
        'issues' => ['إدارة المشكلات', 4],
        'analytics' => ['التحليلات', 5],
        'exports' => ['التقارير الإدارية', 6],
    ];

    public function __invoke(string $module): View
    {
        abort_unless(isset(self::MODULES[$module]), 404);
        [$title, $phase] = self::MODULES[$module];

        return view('placeholder', compact('title', 'phase', 'module'));
    }
}
