<?php

namespace App\Alerts;

use App\Models\Alert;
use App\Models\AlertRule as AlertRuleModel;
use App\Models\User;
use App\Notifications\AlertNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * يشغّل القواعد المفعّلة: يفتح تنبيهاً لكل حالة جديدة، يحدّث «آخر ظهور» للمفتوحة،
 * ويغلق تلقائياً ما زال سببه. المُغلق يدوياً لا يعود لنفس السبب في نفس اليوم.
 */
class AlertEngine
{
    /** key ⇒ صنف القاعدة */
    /** حد نتائج القاعدة الواحدة (القواعد تطلب بالترتيب حتى هذا الحد) */
    public const RULE_LIMIT = 500;

    public const RULES = [
        'shipments_below_average' => Rules\ShipmentsBelowAverage::class,
        'store_stopped_reporting' => Rules\StoreStoppedReporting::class,
        'repeated_store_issue' => Rules\RepeatedStoreIssue::class,
        'report_approval_delay' => Rules\ReportApprovalDelay::class,
        'employee_missing_report' => Rules\EmployeeMissingReport::class,
        'issue_resolution_delay' => Rules\IssueResolutionDelay::class,
        'duplicate_or_conflicting_codes' => Rules\DuplicateOrConflictingCodes::class,
    ];

    /** @return array{opened: int, closed: int} */
    public function run(CarbonImmutable $now): array
    {
        $opened = $closed = 0;
        $new = [];

        foreach (AlertRuleModel::all() as $rule) {
            $class = self::RULES[$rule->key] ?? null;
            if (! $class) {
                continue;
            }
            $open = Alert::where('rule_key', $rule->key)->where('status', 'open')->get()->keyBy('fingerprint');

            if (! $rule->is_enabled) {
                $closed += $this->close($open->pluck('id')->all(), $now);

                continue;
            }

            // قاعدة تتعطل لا توقف بقية القواعد ولا تُغلق تنبيهاتها
            try {
                $findings = collect(app($class)->evaluate($rule->parameters ?? [], $now))->keyBy('fingerprint');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Alert rule {$rule->key} failed: ".$e->getMessage());

                continue;
            }
            // نتيجة وصلت للحد الأقصى ⇒ قد تكون ناقصة، فلا إغلاق تلقائي في هذا التشغيل
            $capped = $findings->count() >= self::RULE_LIMIT;
            $mutedToday = Alert::where('rule_key', $rule->key)->where('close_reason', 'manual')
                ->where('closed_at', '>=', $now->startOfDay())->pluck('fingerprint')->flip();

            DB::transaction(function () use ($findings, $open, $mutedToday, $rule, $now, $capped, &$opened, &$closed, &$new) {
                // تحديث «آخر ظهور» دفعة واحدة؛ والعنوان/التفاصيل فقط إن تغيّرت
                $seen = $open->toBase()->only($findings->keys()->all());
                if ($seen->isNotEmpty()) {
                    Alert::whereIn('id', $seen->pluck('id'))->update(['last_seen_at' => $now]);
                }
                foreach ($findings as $fp => $f) {
                    if ($open->has($fp)) {
                        if ($open[$fp]->title !== $f->title || $open[$fp]->details !== $f->details) {
                            $open[$fp]->update(['title' => $f->title, 'details' => $f->details]);
                        }
                    } elseif (! $mutedToday->has($fp)) {
                        $new[] = Alert::create([
                            'rule_key' => $rule->key, 'level' => $rule->level, 'title' => $f->title, 'details' => $f->details,
                            'subject_type' => $f->subjectType, 'subject_id' => $f->subjectId, 'subject_label' => $f->subjectLabel,
                            'fingerprint' => $fp, 'status' => 'open', 'first_seen_at' => $now, 'last_seen_at' => $now,
                        ]);
                        $opened++;
                    }
                }
                // toBase(): except في مجموعة Eloquent يحذف بالمعرّف لا بمفتاح المصفوفة
                if (! $capped) {
                    $closed += $this->close($open->toBase()->except($findings->keys()->all())->pluck('id')->all(), $now);
                }
            });
        }

        // إشعار واحد ملخص لكل تشغيل — لا سيل إشعارات
        if ($new) {
            Notification::send(self::recipients(), new AlertNotification($new));
        }

        return ['opened' => $opened, 'closed' => $closed];
    }

    private function close(array $ids, CarbonImmutable $now): int
    {
        return $ids ? Alert::whereIn('id', $ids)->update(['status' => 'closed', 'closed_at' => $now, 'close_reason' => 'auto']) : 0;
    }

    /** المدير والمسؤول: يرون التحليلات ويديرون المشكلات */
    public static function recipients()
    {
        return User::with('role')->where('status', 'active')->get()
            ->filter(fn (User $u) => $u->hasPermission('analytics.view') && $u->hasPermission('issues.manage'))->values();
    }
}
