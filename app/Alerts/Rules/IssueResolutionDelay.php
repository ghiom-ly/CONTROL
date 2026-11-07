<?php

namespace App\Alerts\Rules;

use App\Alerts\AlertRule;
use App\Alerts\Finding;
use App\Models\Issue;
use Carbon\CarbonImmutable;

/** مشكلة غير محلولة تجاوزت المهلة (hours من التبليغ، أو due_at) */
class IssueResolutionDelay implements AlertRule
{
    public function key(): string
    {
        return 'issue_resolution_delay';
    }

    public function evaluate(array $params, CarbonImmutable $now): array
    {
        $hours = (int) ($params['hours'] ?? 48);

        return Issue::with('store:id,name')->whereNotIn('status', ['resolved', 'closed'])
            ->where(fn ($q) => $q->where('reported_at', '<', $now->subHours($hours))->orWhere('due_at', '<', $now))
            ->orderBy('id')->limit(\App\Alerts\AlertEngine::RULE_LIMIT)->get()
            ->map(fn (Issue $i) => new Finding("issue:{$i->id}", "تأخر حل المشكلة {$i->issue_number}: {$i->title}",
                'المتجر: '.($i->store?->name ?? '—').' — منذ '.$i->reported_at->diffForHumans($now, true), 'issue', $i->id, $i->issue_number))
            ->all();
    }
}
