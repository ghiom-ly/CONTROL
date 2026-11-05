<?php

namespace App\Services\Messages;

use App\Enums\FollowupResult;
use App\Enums\ReportStatus;
use App\Models\Issue;
use App\Models\ReportStore;
use App\Models\Store;
use App\Models\StoreDailyMetric;
use App\Models\SystemSetting;
use App\Models\User;
use App\Queries\OpenCodesQuery;
use Carbon\CarbonImmutable;

/** قيم متغيرات الرسالة لمتجر (من البيانات المعتمدة فقط) */
class MessageContext
{
    public const PERIODS = ['today' => 'اليوم', '7d' => 'آخر 7 أيام', 'month' => 'هذا الشهر'];

    private const NA = 'غير متوفر';

    /**
     * @param  array{codes?: list<string>, open?: list<string>, issue?: Issue, period?: string, performance?: bool}  $opts
     *                                                                                                                  open = أكواد المتجر المفتوحة محسوبة مسبقاً (للإرسال الجماعي)، performance=false لتخطي حساب الأداء
     */
    public function forStore(Store $store, User $sender, array $opts = []): array
    {
        $open = $opts['open'] ?? self::openCodes($store);
        $codes = $opts['codes'] ?? $open;
        $values = [
            'اسم_المتجر' => $store->name,
            'صاحب_المتجر' => $store->owner_name ?? $store->name,
            'كود_المتجر' => $store->code,
            'التاريخ' => now()->format('Y/m/d'),
            'اسم_المرسل' => $sender->name,
            'اسم_الشركة' => (string) SystemSetting::get('company_name', 'شركة النورس'),
            'الأكواد' => $codes ? implode("\n", $codes) : self::NA,
            'عدد_الأكواد' => (string) count($codes),
            'الأكواد_المفتوحة' => (string) count($open),
        ];

        if ($issue = $opts['issue'] ?? null) {
            $values += [
                'عنوان_المشكلة' => $issue->title,
                'وصف_المشكلة' => $issue->description,
                'رقم_المشكلة' => $issue->issue_number,
                'تاريخ_المشكلة' => $issue->reported_at?->format('Y/m/d') ?? self::NA,
            ];
        }

        return ($opts['performance'] ?? true) ? $values + $this->performance($store, $opts['period'] ?? '7d') : $values;
    }

    /** @return list<string> أكواد «الزبون لم يرد» المفتوحة للمتجر */
    public static function openCodes(Store $store): array
    {
        return OpenCodesQuery::forStore($store->id)->where('last_result', FollowupResult::CustomerNoAnswer)
            ->orderByDesc('last_followup_at')->orderBy('code')->pluck('code')->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(string $period): array
    {
        $today = CarbonImmutable::today();

        return match ($period) {
            'today' => [$today, $today],
            'month' => [$today->startOfMonth(), $today],
            default => [$today->subDays(6), $today],
        };
    }

    /** ملخص الأداء: الطلبيات، ونسبة التسليم «تحسّن خلال الفترة» (أول ← آخر شفت معتمد) */
    private function performance(Store $store, string $period): array
    {
        [$from, $to] = self::range($period);
        $orders = StoreDailyMetric::where('store_id', $store->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(shipments_count) t, COUNT(shipments_count) k')->first();

        $rates = ReportStore::query()->where('report_stores.store_id', $store->id)->whereNotNull('report_stores.start_rate')
            ->join('daily_reports', 'daily_reports.id', '=', 'report_stores.daily_report_id')
            ->where('daily_reports.status', ReportStatus::Approved->value)->whereNull('daily_reports.deleted_at')
            ->whereBetween('daily_reports.work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('daily_reports.work_date')->orderBy('daily_reports.shift_started_at')->orderBy('report_stores.id')
            ->pluck('report_stores.start_rate');

        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').'%';
        $first = $rates->first();
        $last = $rates->last();
        $change = $first === null ? null : round((float) $last - (float) $first, 2);

        return [
            'الفترة' => self::PERIODS[$period] ?? self::PERIODS['7d'],
            'عدد_الطلبيات' => ($orders && $orders->k > 0) ? (string) (int) $orders->t : self::NA,
            'نسبة_البداية' => $first === null ? self::NA : $fmt($first),
            'نسبة_النهاية' => $last === null ? self::NA : $fmt($last),
            'التحسن' => $change === null ? self::NA : ($change > 0 ? '+' : '').$fmt($change),
        ];
    }
}
