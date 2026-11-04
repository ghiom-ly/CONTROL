<?php

namespace App\Support;

use App\Models\Role;

/**
 * سجل الصلاحيات المركزي وتوزيعها الافتراضي على الأدوار.
 * أي صلاحية جديدة في المراحل القادمة تُضاف هنا ثم يُعاد تشغيل RolesAndPermissionsSeeder.
 */
class Permissions
{
    /** @return array<string, array{0:string,1:string}> name => [label, group] */
    public static function all(): array
    {
        return [
            'dashboard.view' => ['عرض لوحة التحكم', 'عام'],

            'users.view' => ['عرض الموظفين وأدائهم', 'الموظفون'],
            'users.manage' => ['إدارة الموظفين (إضافة/تعديل/إيقاف)', 'الموظفون'],

            'stores.view' => ['عرض المتاجر', 'المتاجر'],
            'stores.manage' => ['إدارة المتاجر', 'المتاجر'],

            'reports.create' => ['إنشاء التقارير اليومية', 'التقارير'],
            'reports.view_all' => ['عرض تقارير جميع الموظفين', 'التقارير'],
            'reports.review' => ['مراجعة واعتماد/رفض التقارير', 'التقارير'],
            'reports.admin_edit' => ['التعديل الإداري على التقارير المعتمدة', 'التقارير'],

            'imports.create' => ['رفع ملفات الاستيراد', 'الاستيراد'],
            'imports.view_all' => ['عرض جميع عمليات الاستيراد', 'الاستيراد'],
            'imports.approve' => ['اعتماد عمليات الاستيراد', 'الاستيراد'],

            'shipments.view' => ['عرض الشحنات والبحث بالكود', 'الشحنات'],
            'shipments.followup' => ['تسجيل متابعات الشحنات', 'الشحنات'],

            'contacts.view' => ['عرض سجل الاتصالات', 'الاتصالات'],
            'contacts.create' => ['تسجيل محاولات الاتصال', 'الاتصالات'],

            'issues.view' => ['عرض المشكلات', 'المشكلات'],
            'issues.create' => ['تسجيل مشكلة', 'المشكلات'],
            'issues.manage' => ['إدارة ومعالجة المشكلات', 'المشكلات'],

            'analytics.view' => ['عرض التحليلات الإدارية', 'التحليلات'],
            'exports.generate' => ['استخراج التقارير الإدارية PDF/Excel', 'التحليلات'],

            'audit.view' => ['عرض سجل العمليات', 'النظام'],
            'settings.manage' => ['إدارة الإعدادات والصلاحيات', 'النظام'],
        ];
    }

    /** التوزيع الافتراضي — المدير العام يملك كل شيء تلقائياً عبر Gate::before */
    public static function defaults(): array
    {
        $all = array_keys(self::all());

        return [
            Role::SUPER_ADMIN => $all,
            Role::ADMIN => [
                'dashboard.view', 'users.view', 'stores.view', 'stores.manage',
                'reports.create', 'reports.view_all', 'reports.review',
                'imports.create', 'imports.view_all', 'imports.approve',
                'shipments.view', 'shipments.followup', 'contacts.view', 'contacts.create',
                'issues.view', 'issues.create', 'issues.manage',
                'analytics.view', 'exports.generate', 'audit.view',
            ],
            Role::EMPLOYEE => [
                'dashboard.view', 'stores.view', 'reports.create', 'imports.create',
                'shipments.view', 'shipments.followup', 'contacts.view', 'contacts.create',
                'issues.view', 'issues.create',
            ],
            Role::VIEWER => [
                'dashboard.view', 'stores.view', 'reports.view_all', 'shipments.view',
                'contacts.view', 'issues.view', 'analytics.view',
            ],
        ];
    }
}
