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
    public function whatsapp(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('analytics.view'), 403);
        $open = Alert::where('status', 'open')->get()->groupBy(fn (Alert $a) => $a->level->value);

        $lines = ['*تنبيهات NOURS — '.now()->format('Y/m/d H:i').'*'];
        foreach (['critical', 'important', 'warning', 'info'] as $lvl) {
            if (! isset($open[$lvl])) {
                continue;
            }
            $lines[] = '';
            $lines[] = '*'.AlertLevel::from($lvl)->label().' ('.$open[$lvl]->count().')*';
            foreach ($open[$lvl] as $a) {
                $lines[] = '• '.$a->title;
            }
        }
        if ($open->isEmpty()) {
            $lines[] = 'لا توجد تنبيهات مفتوحة ✅';
        }

        return redirect()->away('https://wa.me/?text='.rawurlencode(implode("\n", $lines)));
    }

    private static function canClose(Request $request): bool
    {
        return $request->user()->hasPermission('analytics.view') && $request->user()->hasPermission('issues.manage');
    }
}
