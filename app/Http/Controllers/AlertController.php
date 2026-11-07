<?php

namespace App\Http\Controllers;

use App\Enums\AlertLevel;
use App\Models\Alert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'level' => ['nullable', Rule::enum(AlertLevel::class)],
            'rule_key' => ['nullable', 'string', 'max:80'],
        ]);
        $status = $f['status'] ?? 'open';

        $alerts = Alert::with('closer:id,name')->where('status', $status)
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($f['rule_key'] ?? null, fn ($q, $v) => $q->where('rule_key', $v))
            ->orderByRaw("FIELD(level, 'critical', 'important', 'warning', 'info')")->latest('last_seen_at')
            ->paginate(50)->withQueryString();

        return view('alerts.index', [
            'alerts' => $alerts, 'filters' => $f + ['status' => $status],
            'rules' => \App\Models\AlertRule::orderBy('id')->pluck('name', 'key'),
            'canClose' => self::canClose($request),
        ]);
    }

    public function close(Request $request, Alert $alert): RedirectResponse
    {
        abort_unless(self::canClose($request), 403);
        if ($alert->status === 'open') {
            $alert->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $request->user()->id, 'close_reason' => 'manual']);
        }

        return back()->with('success', 'تم إغلاق التنبيه.');
    }

    /** ملخص كل التنبيهات المفتوحة كرسالة واتساب واحدة (المالك يختار المحادثة) */
    public function whatsapp(Request $request): View
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        $open = Alert::where('status', 'open')->orderByDesc('last_seen_at')->get()->groupBy(fn (Alert $a) => $a->level->value);

        $lines = ['*تنبيهات NOURS — '.now()->format('Y/m/d H:i').'*'];
        foreach (['critical', 'important', 'warning', 'info'] as $lvl) {
            if (! isset($open[$lvl])) {
                continue;
            }
            $lines[] = '';
            $lines[] = '*'.AlertLevel::from($lvl)->label().' ('.$open[$lvl]->count().')*';
            foreach ($open[$lvl]->take(self::WA_PER_LEVEL) as $a) {
                $lines[] = '• '.$a->title;
            }
            if ($open[$lvl]->count() > self::WA_PER_LEVEL) {
                $lines[] = 'و '.($open[$lvl]->count() - self::WA_PER_LEVEL).' أخرى';
            }
        }
        if ($open->isEmpty()) {
            $lines[] = 'لا توجد تنبيهات مفتوحة ✅';
        }
        $text = implode("\n", $lines);

        return view('alerts.whatsapp', ['text' => $text, 'waUrl' => \App\Support\WhatsAppText::url(null, $text)]);
    }

    /** حد السطور لكل مستوى حتى يبقى الرابط والرسالة بحجم معقول */
    private const WA_PER_LEVEL = 20;

    private static function canClose(Request $request): bool
    {
        return $request->user()->hasPermission('analytics.view') && $request->user()->hasPermission('issues.manage');
    }
}
